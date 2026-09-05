<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Converter\Converter;
use Taxmod\Core\Converter\ConverterRegistry;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Identity;
use Taxmod\Core\Renderer\Renderable;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RecordKind;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\FieldRowRenderer;
use Taxmod\Core\Renderer\Choice;
use Taxmod\Core\Renderer\ChoiceRenderer;
use Taxmod\Core\Renderer\HeadRenderer;
use Taxmod\Core\Renderer\ChooserCellRenderer;
use Taxmod\Core\Renderer\DialogChooserRenderer;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;
use Taxmod\Core\Renderer\DrawnRow;
use Taxmod\Core\Renderer\FormRenderer;
use Taxmod\Core\Renderer\LabelSlot;
use Taxmod\Core\Renderer\LabelsRenderer;
use Taxmod\Core\Renderer\NodeRenderer;
use Taxmod\Core\Renderer\RecordRenderer;
use Taxmod\Core\Renderer\Section;
use Taxmod\Core\Renderer\SettingsRenderer;
use Taxmod\Core\Renderer\TreeRenderer;
use Taxmod\Core\Renderer\Submission;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RenderedField;
use Taxmod\Core\Renderer\RenderedSetting;
use Taxmod\Core\Renderer\RenderResult;
use Taxmod\Core\Renderer\Surroundings;
use Taxmod\Core\Renderer\TableRenderer;
use Taxmod\Core\Renderer\TreeNodeRenderer;
use Taxmod\Core\Renderer\Renderer;
use Taxmod\Core\Renderer\RendererRegistry;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Model\NodeKind;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RelationRepository;
use Taxmod\Core\Repository\TypeNodes;

/**
 * The descent, for the attributes of one node.
 *
 * ```mermaid
 * flowchart LR
 *   E["attribute edge"] --> T["its target"] --> Y["the simple type<br/>own name, else an ancestor's"]
 *   Y --> R["the renderer<br/>the chain, else the type default"]
 *   R --> M["markup"]
 * ```
 *
 * ⚠️ **Everything is loaded before the first renderer is called** (D-159). Targets in one query,
 * their ancestors in a second, every chain in a third — because the alternative is a query per
 * field, which is the loop `CD-7` forbids outright.
 *
 * ⚠️ **The policy living here is what to do with a purpose nobody can answer for, and it is not
 * the same answer twice.** A **value** must never silently disappear, so display and edit fall
 * back and the fallback marks itself (R14b). A **filter** that cannot be executed must never
 * appear, so search yields nothing — which is D-217's *not searchable*, reached through a missing
 * capability rather than a special case.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class Rendering
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
        private readonly RendererRegistry $renderers,
        /**
         * ⚠️ **Required, and that is the decision rather than an oversight** ([D-510](../../../docs/NewConcept/90-decision-log.md)).
         * *An optional binding with a name fallback behind it would mean a wiring nobody did looks
         * exactly like a wiring that worked — which is how the whole fault being fixed here survived
         * three days. The Notnagel lives in **one** place, in the implementation, where it can write
         * the id down.*
         */
        private readonly TypeNodes $typeNodes,
        private readonly ?Labels $labels = null,
        /**
         * ⚠️ **Optional, so every existing caller keeps working with no converter in effect** — which
         * is a complete state and not a gap ([R33b](../../../docs/NewConcept/30-renderer.md#r33b--several-are-eligible-exactly-one-is-in-effect)):
         * *no converter means the value is shown as it is stored.* Same shape as `$labels`, for the
         * same reason.
         */
        private readonly ?ConverterRegistry $converters = null,
        /**
         * Die Angaben, die schon an ihrer neuen Stelle liegen — im Modell, als Datensätze.
         *
         * ⚠️ **Die Brücke für [D-529](../../../docs/NewConcept/90-decision-log.md), und sie ist
         * absichtlich optional:** *solange eine Angabe noch in der Settings-Tabelle steht, antwortet
         * diese Quelle «nichts» und der alte Weg trägt weiter. **Erst wenn eine Angabe umgezogen ist,
         * gewinnt die neue Stelle** — sonst hätte der Umzug keine Wirkung.*
         */
        private readonly ?ModelValues $model = null,

        /**
         * ⚠️ **Damit der Abstieg durch die Knoten gehen kann.** *Der Eigentümer hat es diagnostiziert:
         * «heisst wohl Renderkette ist unterbrochen» — und: «Form-Render sollte ja die Knoten
         * durchgehen». **Durchgehen ja, nachladen nein:** ein Renderer darf nichts holen
         * ([D-159](../../../docs/NewConcept/90-decision-log.md)), und {@see FormRenderer} legt nur aus,
         * was ihm gegeben wurde ([D-366](../../../docs/NewConcept/90-decision-log.md)). Also gehört der
         * Gang hierher — und hier fehlten die Kanten.*
         *
         * ⚠️ *Gemessen an `Kontakt`: das Feld `Address` bekam Typ «keiner» und `plain`, während `Adresse`
         * fünf eigene Felder trägt — strasse, hausnummer, plz, ort, land. **Vier von fünf waren nie zu
         * sehen.***
         *
         * ⚠️ *Nachträglich und mit `null` als Vorgabe, damit die bestehenden Aufrufstellen unverändert
         * bleiben — dieselbe Form, in der `$model` dazukam.*
         */
        private readonly ?RelationRepository $relations = null,
    ) {
    }

    /**
     * What was typed into a form, as values to store — the converter's other direction.
     *
     * ⚠️ **The mirror of {@see self::fieldsFor()}, and it has to be, or the round trip is broken.**
     * *A field that draws `XII` and saves `XII` as text is a field that lost its value. [R36](../../../docs/NewConcept/30-renderer.md)
     * puts the reason plainly: the converter runs **on the way in as well**, and that is what makes
     * `> XII` in a search box possible at all.*
     *
     * ⚠️ **One resolution for the whole form, not one per field** (`CD-7`). *Same construction as the
     * drawing side: settings and types for every edge at once, then a loop with no query in it.*
     *
     * ⚠️ **Only an invertible converter is asked** ([D-076](../../../docs/NewConcept/90-decision-log.md)).
     * *A lossy one is display only, so what a person typed into it is read by the type — which is the
     * honest reading: the characters on screen were never the whole value.*
     *
     * @param  list<Relation>        $edges      The attributes the form drew.
     * @param  array<int, string>    $characters What was typed, by edge id. Empty strings belong to
     *                                           the caller: an empty field means *unanswered* and the
     *                                           row goes, which is not this method's decision.
     * @return array<int, TypedValue>            By edge id, for every edge that had a type.
     */
    public function valuesFrom(array $edges, array $characters): array
    {
        if ($edges === []) {
            return [];
        }

        $types    = $this->typesOf($edges);
        $resolved = $this->settingsForUseSites($edges);
        $values   = [];

        foreach ($edges as $edge) {
            $typed = $characters[$edge->id] ?? null;
            $type  = $types[$edge->id] ?? null;

            if ($typed === null || $type === null) {
                continue;
            }

            $converter = $this->readingConverter($resolved[$edge->id] ?? [], $type);

            // ⚠️ *`NotAValueOfThatType` travels on either way — from the converter or from the type.
            // Both refuse rather than coerce, and the boundary turns it into a `WP_Error` (`CD-10`).*
            $values[$edge->id] = $converter === null
                ? $type->valueFrom($typed)
                : $converter->written($typed, $type);
        }

        return $values;
    }

    /**
     * The converter that may read this field back, or `null` where none may.
     *
     * @param array<string, ResolvedSetting> $settings
     */
    private function readingConverter(array $settings, ?SimpleType $type): ?Converter
    {
        if ($this->converters === null || $type === null) {
            return null;
        }

        $name = ($settings[SettingKey::Converter->value] ?? null)?->value->text;

        if ($name === null || $name === '' || ! $this->converters->knows($name)) {
            return null;
        }

        $converter = $this->converters->byName($name);

        // ⚠️ *Three conditions and all three are load-bearing: registered, eligible for this type, and
        // **invertible**. Dropping the last one would let a rounding converter parse `8.50` back and
        // store the rounded number over the one that was there.*
        if (! $converter->isInvertible() || ! in_array($type, $converter->handles(), true)) {
            return null;
        }

        return $converter;
    }

    /**
     * The characters the converter in effect produces, or `null` where none is.
     *
     * ⚠️ **Resolved here and never in a renderer** ([D-159](../../../docs/NewConcept/90-decision-log.md),
     * [D-445](../../../docs/NewConcept/90-decision-log.md)): the descent knows the chain, the registry
     * and the type, so it runs the mapping and hands the result over.
     *
     * ⚠️ **A converter nobody registered is left as it is rather than refused.** *`byName()` throws, and
     * `NotAPossibleTarget` here would take down a whole form because one attribute names a converter a
     * data pack removed. **The value is still true** — it just is not mapped — so the honest failure is
     * to show it stored, the same way [R14b](../../../docs/NewConcept/30-renderer.md)'s fallback shows
     * rather than hides. Refusing belongs at the **write**, where the name is chosen
     * ([D-360](../../../docs/NewConcept/90-decision-log.md)), and that is `SettingDoesNotApply`'s job.*
     *
     * ⚠️ *A mapping that cannot read the value it was handed is the same case: `range_min` on a text,
     * `roman` on `4000`. It says so in its own output ({@see \Taxmod\Core\Converter\RomanNumeralConverter::shown()}),
     * which is where a reader can see it.*
     *
     * @param array<string, ResolvedSetting> $settings
     */
    private function convertedCharacters(
        TypedValue $value,
        array $settings,
        ?SimpleType $type,
    ): ?string {
        if ($this->converters === null || $value->isNothing() || $type === null) {
            return null;
        }

        $chosen = $settings[SettingKey::Converter->value] ?? null;
        $name   = $chosen?->value->text;

        if ($name === null || $name === '' || ! $this->converters->knows($name)) {
            return null;
        }

        $converter = $this->converters->byName($name);

        // ⚠️ *Eligibility is checked here too, not only when the name is chosen: a type can change
        // under a stored setting — an attribute repointed from `Integer` to `Text` — and running an
        // integer mapping over characters would invent a reading rather than refuse one.*
        if (! in_array($type, $converter->handles(), true)) {
            return null;
        }

        return $converter->shown($value);
    }

    /**
     * The free key an attribute uses to say **which label** its reference should show.
     *
     * ⚠️ **Free rather than reserved, and that is [D-364](90-decision-log.md)'s own ruling.** It
     * says the free-key mechanism *has a real job* — *`cols` and `rows` are free keys today and
     * correctly so … it is how one renderer draws* — and settles who writes them: **whoever writes
     * renderers, not whoever models.** A label role is the same kind of thing as `rows`: no record
     * answers *which role*, so by D-364's own test it is a setting and not an attribute, and by its
     * precedent it needs no place on the reserved list.
     *
     * ⚠️ **This is the setting [D-049](90-decision-log.md) promised and nobody had built.** It said
     * the choice of text *is a setting on the renderer naming the label role* — and the role was
     * nailed to `form` here, so no author could ask for `symbol` and a prefix could only ever read
     * `kilo` where `k` was wanted. *The owner found it by asking the one question that mattered:
     * **which field do we hang the renderer on?** The answer is the referring edge, and this is the
     * key it carries.*
     *
     * ⚠️ *[D-264](90-decision-log.md) wants a **pattern** here eventually — roles and fixed
     * characters, `symbol – form` — and a single role is its first step rather than a rival to it.*
     */
    public const LABEL_ROLE = 'label_role';

    /**
     * What the referenced nodes are called, for every reference in this batch, in one query
     * **per role** that anybody asked for.
     *
     * ⚠️ **Resolved before the descent begins** (D-159). A reference is drawn as its target's
     * label (D-105) and a renderer fetches nothing, so this is the only place the labels can come
     * from — and it is one query for the whole form rather than one per row, which is what `CD-7`
     * forbids and what made the legacy parts list slow.
     *
     * ⚠️ **Keyed by *edge*, not by target, because the role belongs to the edge.** The same node
     * reached from two attributes may want `symbol` in one and `form` in the other — *a parts list
     * showing `k` and a heading showing `kilo`* — so a map keyed by target could only hold one of
     * them and would silently give the second row the first one's answer.
     *
     * ⚠️ **Grouped by role rather than asked per edge.** `CD-7` bounds this at *one query per
     * distinct role*, which is at most a handful whatever the size of the form; asking per edge
     * would be the loop again, one level down and harder to see.
     *
     * @param  list<Relation>                                          $edges
     * @param  array<int, TypedValue>                                  $values
     * @param  array<int, array<string, \Taxmod\Core\Model\ResolvedSetting>> $resolved
     * @return array<int, string>                                      Keyed by the **edge's** id.
     */
    private function namesOfReferences(array $edges, array $values, array $resolved, string $locale): array
    {
        if ($this->labels === null) {
            return [];
        }

        // Which targets each role has to answer for — the role is read off the edge that points.
        $wanted = [];

        foreach ($edges as $edge) {
            $reference = ($values[$edge->id] ?? null)?->reference;

            if ($reference === null) {
                continue;
            }

            $role = $this->roleOf($resolved[$edge->id] ?? []);

            $wanted[$role->value][$reference][] = $edge->id;
        }

        $names = [];

        foreach ($wanted as $role => $targets) {
            $resolvedNames = $this->labels->forNodes(
                array_values($this->nodes->byIds(array_keys($targets))),
                SeededRole::from($role),
                $locale
            );

            foreach ($targets as $target => $edgeIds) {
                foreach ($edgeIds as $edgeId) {
                    // ⚠️ Absent stays absent: a dangling reference is drawn as a marked fault
                    // rather than as its id (D-363), and that decision is the renderer's to make.
                    if (isset($resolvedNames[$target])) {
                        $names[$edgeId] = $resolvedNames[$target];
                    }
                }
            }
        }

        return $names;
    }

    /**
     * Which label role this edge asked for, or the ordinary one.
     *
     * ⚠️ **An unknown role falls back rather than throwing.** The set is seeded
     * ([D-196](90-decision-log.md)) and a typo, an import or a pack could name something outside it;
     * refusing to draw the whole form over one misspelt setting would be the wrong trade. *It is
     * still visible as wrong, because the text that appears is the `form` label and not the one the
     * author meant.*
     *
     * @param array<string, \Taxmod\Core\Model\ResolvedSetting> $settings
     */
    /**
     * Welche Namensrolle diese Verwendungsstelle zeichnet.
     *
     * ⚠️ **Von aussen fragbar, damit eine Randpruefung das Verhalten festnageln kann** — *und es ist
     * eine echte Frage, die auch ein Schirm stellen darf: «welchen der Namen zeigt dieses Feld».*
     *
     * ⚠️ *Sie geht denselben Weg wie das Zeichnen, einschliesslich der neuen Quelle
     * ([D-529](../../../docs/NewConcept/90-decision-log.md)) — eine Pruefung, die einen kuerzeren Weg
     * nimmt, prueft etwas anderes als das, was der Benutzer sieht.*
     */
    public function labelRoleFor(Relation $edge): SeededRole
    {
        return $this->roleOf($this->settingsForUseSites([$edge])[$edge->id] ?? []);
    }

    /**
     * Was die **Kante selbst** über sich sagt — heute genau die Multiplizität.
     *
     * ⚠️ **Das ist der Rest der alten Auflösungskette** ([D-579](../../../docs/NewConcept/90-decision-log.md)).
     * *Vor dem Streichen der `settings`-Tabelle las diese Stelle deren Zeilen und legte die
     * Spalte darüber. **Gemessen trug die Tabelle zuletzt 13 Zeilen**, und was daraus noch gelesen
     * wurde, war `label_role` an drei Kanten — der Verlust, den [D-579](../../../docs/NewConcept/90-decision-log.md)
     * benannt und in Kauf genommen hat. Alles andere kommt aus dem Datensatz
     * ({@see self::withModelValues()}) und gewann schon vorher.*
     *
     * ⚠️ *Die Spalte `multiplicity` ist `NOT NULL` seit [D-528](../../../docs/NewConcept/90-decision-log.md) —
     * es gibt kein «sagt nichts» mehr, darum steht hier keine Bedingung.*
     *
     * @param  list<Relation>                            $edges
     * @return array<int, array<string, ResolvedSetting>> Nach Kanten-Id.
     */
    /**
     * Die Angaben eines **Knotens**, über dieselbe Naht wie die einer Verwendungsstelle.
     *
     * ⚠️ *Das Gegenstück zu {@see self::settingsForUseSites()} und aus demselben Grund öffentlich:
     * **eine Stelle im Kern beantwortet die Frage.** Vor [D-579](../../../docs/NewConcept/90-decision-log.md)
     * fragten Randprüfungen die `settings`-Tabelle direkt und bekamen eine zweite, plausible
     * Antwort.*
     *
     * @return array<string, ResolvedSetting>
     */
    public function settingsForNode(Node $node): array
    {
        return $this->withModelValues([], $node);
    }

    private function vonDenKnoten(array $nodes): array
    {
        $aus = [];

        foreach ($nodes as $node) {
            $aus[$node->id] = $this->withModelValues([], $node);
        }

        return $aus;
    }

    private function vonDenKanten(array $edges): array
    {
        $aus = [];

        foreach ($edges as $edge) {
            $aus[$edge->id] = [
                SettingKey::Multiplicity->value => new ResolvedSetting(
                    SettingKey::Multiplicity->value,
                    TypedValue::ofText($edge->multiplicity->value),
                    $edge->id,
                    true
                ),
            ];
        }

        return $aus;
    }

    private function roleOf(array $settings): SeededRole
    {
        $asked = ($settings[self::LABEL_ROLE] ?? null)?->value->text;

        return $asked === null ? SeededRole::Form : (SeededRole::tryFrom($asked) ?? SeededRole::Form);
    }

    /**
     * Draw every attribute of a record.
     *
     * @param  list<Relation>         $edges       The attributes, in the order they are shown.
     * @param  array<int, TypedValue> $values      What the record holds, keyed by edge id. A
     *                                             missing key is *not answered* (D-232).
     * @param  string                 $fieldPrefix Form fields become `prefix[edge id]`. Keyed by
     *                                             the edge and never by position: a checkbox that
     *                                             does not submit when unticked would shift every
     *                                             later field onto the wrong attribute.
     * @return list<RenderedField>
     */
    public function fieldsFor(
        array $edges,
        array $values,
        Purpose $purpose,
        string $fieldPrefix = '',
        string $locale = '',
        Level $level = Level::Admin,
        bool $editable = true,
        /**
         * ⚠️ *Das Formular, in das die Bedienung gehört, wenn sie **ausserhalb** von ihm steht. Eine
         * Tabellenzeile ist ein `<tr>`, und ein Formular darf keine Zellen umschliessen — dieselbe
         * Regel, die {@see FieldRowRenderer::formFor()} beschreibt und die zweimal Bedienelemente
         * stumm gemacht hat.*
         */
        string $formId = '',
        /**
         * ⚠️ **Die Bremse des Abstiegs.** *Ein Feld kann auf einen Knoten zeigen, dessen Feld wieder
         * hierher zeigt — dann liefe der Gang endlos. **Drei Stufen**, weil `Kontakt → Adresse → Text`
         * zwei braucht und die dritte Luft ist; tiefer wird nicht gezeichnet, sondern der gewöhnliche
         * Renderer genommen, damit sichtbar bleibt, dass dort etwas ist.*
         */
        int $tiefe = 0,
        /**
         * ⚠️ **Der Unterbau, **vor** dem Abstieg geladen** ([D-159](../../../docs/NewConcept/90-decision-log.md):
         * *«the descent has two inputs, both loaded before it starts»*).
         *
         * ⚠️ **Und das ist keine Formalie: mein erster Entwurf hat es falsch gemacht.** *Er hätte je
         * zusammengesetztem Feld die Kanten des Ziels nachgeladen — «a descent that fetches per edge is
         * N+1 by construction», sagt dieselbe Entscheidung. **Jetzt eine Abfrage je Stufe**, nicht eine
         * je Feld: drei Stufen sind drei Abfragen, egal wie breit das Modell ist.*
         *
         * @var array<int, list<Relation>> Knoten-Id => seine Feldkanten.
         */
        array $unterbau = [],
        /**
         * ⚠️ **Wo der Abstieg schon war — gegen die Selbstbezüglichkeit.**
         *
         * ⚠️ *Der Eigentümer hat es auf der Seite von `DisplayOption` gesehen: `render` und `converter`
         * standen **doppelt** in jedem Datensatz. Der Grund steht schon im Konzept — `DisplayOption`
         * erbt `Display Option` **mit sich selbst als Ziel**, die Lage, die
         * [D-503](../../../docs/NewConcept/90-decision-log.md) verbietet und
         * [OQ-133](../../../docs/NewConcept/91-open-questions.md) verfolgt. **Der Abstieg hat sie nicht
         * verursacht, er hat sie sichtbar gemacht.***
         *
         * ⚠️ *Die Tiefenbremse allein reicht nicht: sie hätte dreimal dasselbe gezeigt statt endlos.
         * **Ein Ziel, in dem der Lauf schon war, wird nicht wieder aufgeklappt.***
         *
         * @var array<int, true> Knoten-Ids, die auf diesem Weg schon besucht wurden.
         */
        array $gesehen = [],
        /**
         * ⚠️ **Die Teile je Trägerkante — der Grund, warum in der Wertspalte nichts stand.**
         *
         * ⚠️ *Der Eigentümer: «auch bezweifle ich, dass dies Datensätze sind, die wir hier sehen». **Er
         * hatte recht:** gezeichnet wurden die **Kanten** des Teils, und kein einziger seiner
         * Kanten-Datensätze wurde gelesen. Gemessen an `Passiv`: im Teil steht `render = form`, der
         * Auswahlkasten zeigte nichts.*
         *
         * ⚠️ *Eine **Liste** je Kante, weil eine Einstellung mehrere Teile haben kann
         * ([D-548](../../../docs/NewConcept/90-decision-log.md), für das Farbschema) — und **ein Teil
         * ist eine Zeile** ([D-546](../../../docs/NewConcept/90-decision-log.md)).*
         *
         * @var array<int, list<array{id: int, werte: array<int, TypedValue>}>>
         */
        array $parts = [],
        /**
         * ⚠️ *Der Knoten, dessen Angaben hier gezeichnet werden — gebraucht für **eine** Frage: welche
         * Renderer er verträgt ([Zeile 92](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
         * `0` heisst «unbekannt», und dann wird nichts eingeschränkt.*
         */
        int $forNode = 0,
        /**
         * Nur eine Einstellungskante steigt in ihr Ziel ab -- der Rest bleibt beim Verweis stehen.
         *
         * WICHTIG: Fuer die Wertspalte der Feldtabelle, nicht fuer die Vorschau. Auf sein Wort:
         * "Felder sind nur fuer Settings-Relation sichtbar" -- ein gewoehnliches Feld auf eine
         * Komposition (z.B. eine Adresse) zeigte dort deren eigene Glieder als Eingabezeilen, wo
         * nur der **Typ** definiert wird und niemand einen Datensatz ausfuellt. Die Vorschau
         * ([D-159](../../../docs/NewConcept/90-decision-log.md), "Renderkette geht durch ein
         * zusammengesetztes Feld") braucht den vollen Abstieg weiterhin und laesst dies auf
         * Vorgabe stehen.
         */
        bool $onlySettingParts = false,
    ): array {
        if ($edges === []) {
            return [];
        }

        // ⚠️ **The abort, and it is the descent's job rather than a renderer's**
        // ([D-450](90-decision-log.md), [D-452](90-decision-log.md), [D-457](90-decision-log.md)): a
        // hidden placement is not drawn and **not enumerated**. *Before, a renderer returned an empty
        // string — which means it had already been asked, and for a composed value its members had
        // already been drawn and thrown away.*
        //
        // ⚠️ **The edge's `hide`, and deliberately not the target node's.** *That distinction is what
        // keeps [D-426](90-decision-log.md)'s fix: as a setting, `hide` on a **type** reached every
        // field of that type and blanked them all — measured twice. A field is one **placement** of a
        // type, so hiding the type must not hide the fields that point at it. **A node's own `hide`
        // stops the walk where the walk enters the node** — the tree, and a composed value's members —
        // not where something merely points at it.* Recorded as [OQ-118](91-open-questions.md), because
        // the concept says «render no further» and does not say which walk.
        $edges = array_values(array_filter($edges, static fn (Relation $edge): bool => ! $edge->hide));

        if ($edges === []) {
            return [];
        }

        // ⚠️ *Hier stand für einen Augenblick eine Sortierung «Einstellungen hinten» — und sie war an
        // der falschen Stelle: der **Behälter** legt aus, nicht der Zeichenlauf
        // ([D-366](../../../docs/NewConcept/90-decision-log.md)), und {@see FormRenderer::groupOf()} hat
        // die Gruppen dafür. **Gemessen: der Form-Renderer sortierte danach wieder nach `position` und
        // machte sie zunichte** — zwei Stellen für eine Reihenfolge, und die zweite gewann.*
        $types    = $this->typesOf($edges);
        $resolved = $this->settingsForUseSites($edges);
        $names    = $this->namesOfReferences($edges, $values, $resolved, $locale);
        $wahl     = $this->optionsFor($edges);
        $fields   = [];

        // ⚠️ *Einmal, ganz oben, in einer festen Zahl von Abfragen — und danach rührt der Abstieg die
        // Datenbank nicht mehr an.*
        if ($tiefe === 0 && $unterbau === []) {
            $unterbau = $this->subgraph($edges, self::TIEFSTENS);
        }

        foreach ($edges as $edge) {
            $type     = $types[$edge->id] ?? null;
            $settings = $this->withModelValues($resolved[$edge->id] ?? [], $edge);
            $renderer = $this->renderers->chosenFor($edge, $settings, $purpose, $type);

            // ⚠️ **[D-540](../../../docs/NewConcept/90-decision-log.md), und die Regel ist seine:**
            // *«ein Feld ist eine **Auswahl**, wenn sein Ziel sichtbare, unmarkierte Kinder hat».*
            //
            // ⚠️ **Nur wenn niemand einen Renderer genannt hat.** *Eine gesetzte Einstellung ist eine
            // Aussage einer Person und schlägt eine Regel; sonst wäre die Einstellung eine Anzeige
            // ohne Wirkung.*
            //
            // ⚠️ **Und nur beim Bearbeiten.** *Beim Anzeigen ist eine Auswahl ein Wort, und das kann
            // der Verweis-Renderer besser — ein ausgegrautes `<select>` wäre eine Bedienung, die
            // keine ist.*
            //
            // ⚠️ *Gemessen, wie es vorher aussah: `label_role` und `orientation` standen als nacktes
            // Textfeld mit `taxmod-no-renderer` da, obwohl ihre fünf beziehungsweise zwei
            // Möglichkeiten längst als Kinder im Modell stehen.*
            $istWahl = $purpose === Purpose::Edit && $type === SimpleType::NodeRef;

            // ⚠️ **Die Wahl wird gebaut und nicht stückweise ausgerechnet** ({@see Choice}). *Der
            // Eigentümer hat den Grund benannt: «von der Multiplizität zum Choice ist ein Weg … und es
            // kann sein, dass du den mehrfach erfindest». **Gemessen stand der Weg viermal**, und ein
            // Kerntest zählt jetzt nach, dass er einmal steht.*
            $dieWahl = Choice::atUseSite(
                $edge->multiplicity,
                $istWahl ? ($wahl[$edge->id] ?? []) : [],
                $values[$edge->id] ?? null,
                $editable
            );

            // ⚠️ **Ein gespeicherter Verweis ist immer ein Eintrag, auch wenn er heute nicht angeboten
            // würde** ([D-360](../../../docs/NewConcept/90-decision-log.md)).
            //
            // ⚠️ **Ohne das versteckt eine leere Auswahl den Wert, und ein Kerntest hat es gefangen.**
            // *Ein Verweis auf `Gramm` mit null Möglichkeiten wäre als leeres gesperrtes `<select>`
            // gezeichnet worden — **der Wert stand nirgends mehr auf dem Schirm**, und das nächste
            // Speichern hätte «nichts» geschrieben. Die Zusage hiess «damit die Lücke sichtbar bleibt»;
            // sie war für genau diesen Fall geschrieben.*
            //
            // ⚠️ *Der Name kommt aus {@see self::namesOfReferences()} — schon aufgelöst, in einem Zug für
            // alle Zeilen (`CD-7`). Löst er **nicht** auf, hängt der Verweis ins Leere, und dann wird kein
            // Eintrag erfunden: [D-363](../../../docs/NewConcept/90-decision-log.md) will einen
            // markierten Fehler sehen und nicht eine Id, die wie ein Name aussieht.*
            $verweis = ($values[$edge->id] ?? null)?->reference;

            if ($istWahl && $verweis !== null && isset($names[$edge->id])) {
                $dieWahl = $dieWahl->including($verweis, $names[$edge->id]);
            }

            $angebot = $dieWahl->options;

            // ⚠️ **Ein Renderer wird nur angeboten, wenn er das hier auch zeichnen kann**
            // ([Zeile 92](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
            //
            // ⚠️ *Der Eigentümer an `Integer`: «Integer sieht jetzt alle Renderer, wobei nur
            // int-Renderer ok wären» — und die Präzisierung: «allgemeiner `field` wäre auch noch ok».
            // **Genau das sagt die Registratur schon**: `eligibleFor()` antwortet für `Integer` mit
            // `field, spinner, slider`, für `Boolean` mit `toggle, checkbox`. [D-540](../../../docs/NewConcept/90-decision-log.md)
            // liefert die Möglichkeiten aus dem Modell, `R14a` verengt sie auf die brauchbaren.*
            //
            // ⚠️ **Ein Angebot, kein Zaun** ([D-360](../../../docs/NewConcept/90-decision-log.md)): *was
            // schon gespeichert ist, bleibt stehen, auch wenn es heute nicht mehr angeboten würde —
            // sonst verschwände eine Wahl, die jemand bewusst getroffen hat.*
            if ($angebot !== [] && $forNode !== 0 && $edge->id === $this->framework->settingValueEdgeId(SettingKey::Renderer)) {
                $angebot = $this->onlyUsableRenderers($angebot, $forNode, $values[$edge->id] ?? null);
            }

            // ⚠️ **Eine Auswahl bleibt eine Auswahl, auch wenn nichts zu wählen ist** —
            // *[R28](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete): «mit **keinem**
            // verfügbaren Eintrag gibt es nichts zu wählen und das Bedienelement ist **gesperrt**».*
            //
            // ⚠️ **Hier stand `$angebot !== []`, und das war die Regel andersherum.** *Der Eigentümer an
            // `validator`: «müsste eigentlich Select-Feld sein, `choice_renderer`, ausgegraut, weil
            // aktuell kein Validator existiert.» Gemessen stand dort ein nacktes `<input type="text"
            // class="taxmod-no-renderer">` — **die Einladung, einen Namen hinzuschreiben, den niemand
            // kennt.** Ohne Einträge fiel die Zeile aus der Auswahl heraus und landete beim Rückfall.*
            //
            // ⚠️ *`ChoiceRenderer` kann den Fall längst: null Ausgänge heisst gesperrt **und markiert**,
            // und bei `0..*` mit leerer Wahl. Es hat nur niemand hingeschickt.*
            //
            // ⚠️ **Drei Fälle und nicht zwei — der dritte hat mich beim ersten Versuch erwischt, und ein
            // Kerntest hat ihn gefangen:**
            //
            // | Möglichkeiten | gespeicherter Wert | was gezeichnet wird |
            // |---|---|---|
            // | ja | egal | Auswahl; der gespeicherte Wert ist ein Eintrag ([D-360](../../../docs/NewConcept/90-decision-log.md)) |
            // | nein | keiner | Auswahl, leer, **gesperrt und markiert** ([R28](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete)) — der Fall `validator` |
            // | nein | einer, dessen Name nicht auflöst | **Rückfall**, denn eine leere Auswahl würde den Wert **verschwinden lassen** |
            //
            // *Der dritte ist keine Feinheit: das nächste Speichern hätte «nichts» geschrieben. Die Zusage
            // heisst «damit die Lücke sichtbar bleibt» — und ein Rückfall, der den Wert zeigt und den
            // Grund nennt, hält sie besser als ein leerer Kasten.*
            if ($istWahl && $dieWahl->canShowItsState() && ($settings[SettingKey::Renderer->value]->value->text ?? '') === '') {
                $renderer = $this->renderers->byName(ChoiceRenderer::NAME);
            }

            if ($renderer === null) {
                if ($purpose === Purpose::Search) {
                    continue;
                }

                $renderer = $this->renderers->fallback();
            }

            $value = $values[$edge->id] ?? TypedValue::nothing();

            $context = new RenderContext(
                purpose: $purpose,
                value: $value,
                settings: $settings,
                locale: $locale,
                level: $level,
                editable: $editable,
                fieldName: $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $edge->id . ']',
                type: $type,
                surroundings: new Surroundings(
                    // ⚠️ By **edge**, not by target: the role that decided this text belongs to the
                    // edge, so two attributes pointing at one node can show `k` and `kilo`.
                    refersTo: $value->reference === null ? null : ($names[$edge->id] ?? null),
                    // ⚠️ **Already known, so it is handed over rather than looked up** (D-445). A
                    // reference with no simple type behind it is a reference to a record: `typeOf()`
                    // answers `node_ref` for a constant and a real type for a data type, so `null`
                    // here is the composed case — *and it is the summary renderer (D-106) that is
                    // missing, not a renderer that is mis-set.*
                    refersToARecord: $value->reference !== null && $type === null,
                    options: $angebot,
                    // ⚠️ **«Nichts» ist eine Möglichkeit nur dort, wo die Multiplizität es zulässt.**
                    //
                    // ⚠️ *Der Eigentümer: «`render` ist `1..1` in `DisplayOption`, sollte somit nicht die
                    // Möglichkeit haben, keinen Wert einzugeben — also ist es ja nicht `0..1`. Ist eine
                    // Regel, die wir für Auswahllisten festgelegt hatten.» **Die Regel steht wörtlich**
                    // ([D-380](../../../docs/NewConcept/90-decision-log.md), [R28–R32](../../../docs/NewConcept/30-renderer.md)):
                    // die Zahl der Ausgänge ist `Möglichkeiten + (nichts ist eine Antwort ? 1 : 0)`, und
                    // «`mayBeNothing` ist eine eigene Angabe und **nicht aus der Liste ableitbar**».*
                    //
                    // ⚠️ **Sie war nirgends abgeleitet, also stand überall ein Leereintrag.** *Der
                    // Listen-Renderer setzt die Regel längst um — gesperrt bei null Ausgängen,
                    // ausgegraut bei genau einem, echte Bedienung darüber. **Ihm fehlte nur die
                    // Angabe.***
                    mayBeNothing: $dieWahl->mayBeNothing,
                    formId: $formId,
                ),
                shown: $this->convertedCharacters($value, $settings, $type),
            );

            // ⚠️ **Hier war die Kette unterbrochen**, und die Diagnose ist seine: *«heisst wohl
            // Renderkette ist unterbrochen»*, *«Form-Render sollte ja die Knoten durchgehen»*. *Zeigt ein
            // Feld auf einen Knoten mit **eigenen Feldern**, ist sein Wert ein eigener Teil
            // ([D-541](../../../docs/NewConcept/90-decision-log.md)) — und dessen Felder gehören
            // gezeichnet. Vorher endete der Abstieg hier und lieferte `plain`.*
            $tiefer = $onlySettingParts && ! $edge->kind->isSetting()
                ? null
                : $this->partBelow($edge, $type, $purpose, $fieldPrefix, $locale, $level, $editable, $formId, $tiefe, $unterbau, $values, $gesehen, $parts[$edge->id] ?? [], $forNode);

            $fields[] = new RenderedField(
                $edge,
                $type,
                $tiefer === null ? $renderer->name() : $tiefer['renderer'],
                // WICHTIG: Bei einer Auswahl bleiben *beide* stehen -- der Kasten, in dem gewaehlt
                // wird, und daneben die Felder des Gewaehlten (D-583: "einfach rechts davon
                // anhaengen finde ich am schoensten"). Vorher ersetzte der Abstieg den Kasten,
                // und der Renderer liess sich nicht mehr wechseln.
                $tiefer === null
                    ? $renderer->render($edge, $context)
                    : $this->chosenAndItsFields($edge, $type, $renderer, $context, $tiefer['result']),
                // Carried for the **layout**: R75 puts read-only values first, as context rather
                // than as something to fill in. A container must not resolve the chain again.
                $context->setting(SettingKey::ReadOnly->value)?->asBool() ?? SettingKey::ReadOnly->defaultSwitch()
            );
        }

        return $fields;
    }

    /**
     * A node drawn **as a value** — what a field of this type looks like, with this node's settings.
     *
     * ⚠️ **[D-430](../../../docs/NewConcept/90-decision-log.md), and it exists because the descent
     * takes edges while a type node has none.** The owner: *why no preview on the simple data types?*
     * The panel refused them for a reason that answers a different question — *only a node that can
     * hold records has something to preview* — which is right about **records** and wrong about
     * **fields**: a data type does not hold one, it **is** one.
     *
     * ⚠️ **No synthetic edge.** {@see RendererRegistry::chosenFor()} and {@see Renderer::render()}
     * already accept a `Node`, so nothing has to be invented to fit a signature — *a fake `Relation`
     * in the core to satisfy a parameter list is the kind of thing that later gets stored.*
     *
     * ⚠️ **It resolves the chain once and finds its own example.** The value is the node's resolved
     * `default`, which is the same third rung {@see previewValuesFor()} uses — *real data → rows
     * marked as test data → the type's sample.* A type has no records, so the first two cannot apply
     * and the third is the whole of it.
     *
     * ⚠️ *Returns `null` where the node is no simple type at all, so the surface can say so rather
     * than draw an empty box: a composed node or a model wants [row 36](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)'s
     * composite renderer, which does not exist.*
     */
    public function valueOfType(
        Node $node,
        Purpose $purpose,
        ?TypedValue $value = null,
        string $locale = '',
        Level $level = Level::Admin,
        bool $editable = true,
    ): ?RenderResult {
        $type = $this->typeOf($node, $this->nodes->byIds($node->ancestorIds()));

        if ($type === null) {
            return null;
        }

        // ⚠️ **Auch aus den Datensätzen** ([D-529](../../../docs/NewConcept/90-decision-log.md)) — *hier
        // stand nur die Auflösung über die `settings`-Tabelle, und der Eigentümer hat die Folge gesehen:
        // «Renderer-Änderung ändert die Preview nicht, selbst nach Speichern». **Gemessen: `Integer`
        // sagt im Modell `slider`, und diese Methode zeichnete ein Textfeld** — den Typvorgabewert.*
        //
        // ⚠️ **Sechster Fall derselben Sache an einem Tag:** *Daten umgezogen, ein Leser
        // stehengeblieben. Dieselbe Zeile wie in {@see self::containerFor()} und aus demselben Grund.*
        $settings = $this->withModelValues([], $node);

        if ($value === null || $value->isNothing()) {
            $value = ($settings[SettingKey::DefaultValue->value] ?? null)?->value ?? TypedValue::nothing();
        }

        // ⚠️ *The fallback rather than nothing, for the same reason a field falls back: a value must
        // never silently disappear, and the fallback marks itself (R14b).*
        $renderer = $this->renderers->chosenFor($node, $settings, $purpose, $type)
            ?? $this->renderers->fallback();

        return $renderer->render($node, new RenderContext(
            purpose: $purpose,
            value: $value,
            settings: $settings,
            locale: $locale,
            level: $level,
            editable: $editable,
            // ⚠️ **Nameless on purpose**: this is a preview, and a named field inside the settings
            // form would be submitted as if somebody had filled it in.
            fieldName: '',
            type: $type,
            surroundings: new Surroundings(),
        ));
    }

    

    /**
     * What a subject is called, in one locale — the labels panel.
     *
     * ⚠️ **The rows arrive resolved**, because a renderer fetches nothing ([D-159](90-decision-log.md))
     * and because *what is stored here* and *what the chain answers* are two different facts the
     * panel needs side by side ([D-020](90-decision-log.md)).
     *
     * @param  list<LabelSlot>       $slots
     * @param  list<Control>         $acts
     * @param  array<string, Section> $sections The locale picker, keyed `locale`.
     * @param  string                $formId   The page's form, when the panel is to be saved **with
     *                                         the page** rather than by a button of its own — then it
     *                                         draws no form and its fields name this one.
     */
    public function labelsPanelFor(
        Renderable $subject,
        array $slots,
        array $acts = [],
        ?Submission $submits = null,
        array $sections = [],
        Purpose $purpose = Purpose::Edit,
        string $locale = '',
        Level $level = Level::Admin,
        string $formId = '',
    ): RenderResult {
        return $this->renderers->byName(LabelsRenderer::NAME)->render(
            $subject,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                surroundings: new Surroundings(
                    actions: $acts,
                    submits: $submits,
                    rows: $slots,
                    sections: $sections,
                    formId: $formId
                )
            )
        );
    }

    /**
     * Walked rows plus their drawn cells, as the walker wants them.
     *
     * ⚠️ *One place, because the modelling tree and the chooser both need it and the shape is
     * [D-367](90-decision-log.md)'s seam: **depth, cell, and whether it folds**. A second copy would
     * be the drift that decision exists to prevent — collapsing worked in the tree and not in the
     * trash, one function, two call sites, one forgotten ([D-346](90-decision-log.md)).*
     *
     * @param  list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $walked
     * @param  array<int, RenderResult> $cells
     * @param  array<int, string>       $toggles
     * @return list<DrawnRow>
     */
    private function drawnRows(array $walked, array $cells, array $toggles = [], ?int $highlight = null): array
    {
        $rows = [];

        foreach ($walked as $row) {
            $node = $row['node'];

            $rows[] = new DrawnRow(
                $row['depth'],
                $cells[$node->id],
                $row['hasChildren'],
                $row['collapsed'],
                $toggles[$node->id] ?? null,
                $node->id === $highlight
            );
        }

        return $rows;
    }

    /**
     * A **tree chooser** — the candidates walked, each row drawn as a pickable one.
     *
     * The owner, looking at the flat eighty-entry `<select>` the toolbar had: *the select would have
     * to be the tree chooser.* He is right, and it is the same walker the modelling tree uses —
     * [D-367](90-decision-log.md)'s *one walker, several cells* finally has its second cell.
     *
     * ```mermaid
     * flowchart LR
     *   W["walked rows"] --> C["chooser cell · a radio per node"]
     *   C --> T["the walker · nests them"]
     *   T --> D["dialog · or inline"]
     * ```
     *
     * ⚠️ **A row that cannot be picked is drawn and not left out.** Its child may be perfectly
     * pickable, so removing it would tear a hole in the hierarchy — the cell draws it as text without
     * a radio ({@see ChooserCellRenderer}).
     *
     * ⚠️ **Which chooser draws it is the ordinary choice**: the dialog by default
     * ([D-244](90-decision-log.md)) and the inline one where somebody asked for it, resolved through
     * the chain like any renderer setting.
     *
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $walked
     * @param list<int>  $unpickable Node ids that may not be chosen — its own subtree, the protected.
     * @param string     $chooser    Which of the two to draw it with.
     */
    /**
     * Die PHP-Klasse hinter einem Renderer-Namen, oder `null` (TASK-009).
     *
     * ⚠️ *Die Oberfläche kennt den **Namen**, den der Benutzer gewählt hat, und die Spalte am Knoten
     * kennt die **Klasse**. Dies ist das Stück dazwischen — durchgereicht statt der Registratur
     * selbst, damit der Rand nicht anfängt, sich Renderer zu bauen.*
     */
    public function rendererClassFor(string $name): ?string
    {
        return $this->renderers->classFor($name);
    }

    public function chooserFor(
        array $walked,
        string $fieldName,
        ?int $chosen = null,
        array $unpickable = [],
        ?string $chosenName = null,
        string $nothingToChoose = '',
        string $chooser = DialogChooserRenderer::NAME,
        string $locale = '',
        Level $level = Level::Admin,
        // ⚠️ **What opens the dialog, and what confirms inside it** — both boundary markup, because
        // both are buttons with capabilities, titles and translated labels behind them. *The owner
        // wants the **move button** to be the opener: «button move with dialog tree chooser», then
        // «nicht inline». So the surface hands in its own trigger and the renderer stops guessing.*
        string $trigger = '',
        string $confirm = '',
    ): RenderResult {
        $barred = [];

        foreach ($unpickable as $id) {
            $barred[$id] = [new Control(ChooserCellRenderer::UNPICKABLE, '', '')];
        }

        $nodes = array_map(static fn (array $row): Node => $row['node'], $walked);

        // ⚠️ **One value for every cell, because the radio has to know which row is checked** — and
        // the checked row is a property of the *chooser*, not of the node. *`cellsFor()` hands every
        // cell the same context apart from its own settings, which is exactly what is wanted here.*
        $cells = $this->cellsForChoosing($nodes, $fieldName, $chosen, $barred, $locale, $level);
        $tree  = $this->renderers->byName(TreeRenderer::NAME)->render(
            $nodes[0] ?? Node::create(0, '', null),
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                // WICHTIG: Klapper fuer jede Zeile mit Kindern, damit der Dialog aussieht und sich
                // verhaelt wie die Baumansicht. '#' statt einer Adresse: hier klappt das Skript,
                // weil ein Seitenneuaufbau den Dialog schliessen wuerde.
                surroundings: new Surroundings(rows: $this->drawnRows($walked, $cells, $this->foldsInPlace($walked))),
            )
        );

        return $this->renderers->byName($chooser)->render(
            $nodes[0] ?? Node::create(0, '', null),
            new RenderContext(
                purpose: Purpose::Edit,
                value: $chosen === null ? TypedValue::nothing() : TypedValue::ofReference($chosen),
                locale: $locale,
                level: $level,
                // ⚠️ **The field name reaches the chooser, and it has to.** {@see DialogChooserRenderer}
                // builds its switch id from the subject **and** this — and both choosers on the node
                // page are built from the *first walked node*, so without it the ids matched and **each
                // trigger opened both dialogs**. *Measured: `taxmod-dialog-402` twice.*
                fieldName: $fieldName,
                surroundings: new Surroundings(
                    refersTo: $chosenName,
                    sections: [
                        DialogChooserRenderer::CANDIDATES => new Section($nothingToChoose, $tree->markup),
                        DialogChooserRenderer::TRIGGER    => new Section('', $trigger),
                        DialogChooserRenderer::CONFIRM    => new Section('', $confirm),
                    ]
                ),
            )
        );
    }

    /**
     * Every candidate as a pickable cell.
     *
     * ⚠️ *Its own method rather than a flag on {@see cellsFor()}: that one draws rows of a tree a
     * person **works** in and hands each cell its own acts, and this one hands every cell the same
     * field name and the same chosen value. Two different jobs that happen to share a walker.*
     *
     * @param  list<Node>                $nodes
     * @param  array<int, list<Control>> $barred
     * @return array<int, RenderResult>
     */
    private function cellsForChoosing(
        array $nodes,
        string $fieldName,
        ?int $chosen,
        array $barred,
        string $locale,
        Level $level,
    ): array {
        if ($nodes === []) {
            return [];
        }

        $settings = $this->vonDenKnoten($nodes);
        $renderer = $this->renderers->byName(ChooserCellRenderer::NAME);
        $cells    = [];

        foreach ($nodes as $node) {
            $cells[$node->id] = $renderer->render(
                $node,
                new RenderContext(
                    purpose: Purpose::Edit,
                    value: $chosen === null ? TypedValue::nothing() : TypedValue::ofReference($chosen),
                    settings: $settings[$node->id] ?? [],
                    locale: $locale,
                    level: $level,
                    fieldName: $fieldName,
                    surroundings: new Surroundings(actions: $barred[$node->id] ?? []),
                )
            );
        }

        return $cells;
    }

    /**
     * One record as a block — heading, its drawn form, its acts.
     *
     * ⚠️ **The form is drawn here and placed there**: the descent draws the fields and
     * {@see RecordRenderer} frames them.
     *
     * ⚠️ **This is how it was built and not what any decision requires** — the owner asked *«who told
     * you a renderer has no access to the registry?»* and the answer was **nobody.** *[D-159](90-decision-log.md)
     * says the narrower thing: «the descent has two inputs, **both loaded before it starts**», «a
     * descent that fetches per edge is N+1 by construction» and «the renderer never writes». **A
     * registry lookup is neither a fetch nor a write**, so nothing decided forbids a renderer from
     * descending. Three docblocks claimed it did, citing D-159, and then got quoted back as though
     * D-159 had said it — `PR-10`'s dangling rule, with a citation to make it look agreed.*
     *
     * @param list<Relation>         $edges  The model's attributes, in the order they are shown.
     * @param array<int, TypedValue> $values What this record holds, keyed by edge id.
     * @param list<Control>          $acts
     */
    /**
     * **Alle** Datensätze eines Knotens als **eine** Tabelle — eine Zeile je Satz.
     *
     * ⚠️ **Auf sein Wort:** *«Action sollte rechts sein, Record, Version davor, sodass wir eine schmale
     * Zeile bekommen. Würde alle Datensätze in eine Tabelle packen, ist kompakter und sieht besser aus.
     * Und zu welchem Knoten/Kante es gehört, würde ich auch noch vorne dran schreiben.»*
     *
     * ⚠️ **Die letzte Spalte ist nicht Deko, und das ist gemessen.** *An `Einheitenwert` stehen 23
     * Datensätze; **einer davon ist ein Teil** des Datensatzes von `__uv Resistor`, angehängt über die
     * Kante `resistance`. Er stand zwischen den anderen, ohne dass irgendetwas ihn unterschied — und er
     * ist kein Datensatz von `Einheitenwert` im gewöhnlichen Sinn, sondern ein Stück eines fremden.*
     *
     * ⚠️ **Ein Formular je Zeile, nicht eines für die Tabelle.** *Zwei Datensätze auf einem Schirm sind
     * zwei verschiedene Dinge, und ein Speichern darf nicht beide schreiben. Ein `<tr>` kann kein
     * `<form>` umschliessen, also steht es in der Aktionszelle und die Wertfelder nennen es über
     * `form="…"` — dieselbe Naht wie in der Feldzeile.*
     *
     * ```mermaid
     * flowchart LR
     *   S["je Satz: Werte, Vorspalten, Akte"] --> D["der Abstieg zeichnet die Felder"]
     *   D --> T["table: Vorspalten · Felder · Akte"]
     *   T --> R["record: Rahmen und Diagnose"]
     * ```
     *
     * @param list<Relation>                                                                                        $edges  Die Felder des Modells, in ihrer Reihenfolge.
     * @param list<array{id: int, values: array<int, TypedValue>, lead: array<string,string>, acts: list<Control>, submits: Submission}> $rows
     */
    public function recordsAsTable(
        Node $model,
        array $edges,
        array $rows,
        string $fieldPrefix = '',
        string $diagnostic = '',
        bool $developerMode = false,
        Purpose $purpose = Purpose::Edit,
        string $locale = '',
        Level $level = Level::Admin,
    ): RenderResult {
        $gezeichnet = [];
        $vorne      = [];
        $akte       = [];

        foreach ($rows as $row) {
            $formId = 'taxmod-record-' . $row['id'];

            // ⚠️ *Der Knoten selbst gilt als «schon besucht»: eine Einstellung, die auf ihn zeigt, würde
            // ihn sonst ein zweites Mal aufklappen ([OQ-133](../../../docs/NewConcept/91-open-questions.md)).*
            $gezeichnet[] = $this->fieldsFor(
                $edges,
                $row['values'],
                $purpose,
                $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $row['id'] . ']',
                $locale,
                $level,
                true,
                $formId,
                0,
                [],
                [$model->id => true]
            );

            $vorne[] = $row['lead'];
            $akte[]  = ControlMarkup::actsForm($formId, $row['submits'], $row['acts']);
        }

        $tabelle = $this->renderers->byName(TableRenderer::NAME)->render(
            $model,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                surroundings: new Surroundings(
                    records: $gezeichnet,
                    rowLead: $vorne,
                    rowActs: $akte
                ),
            )
        );

        $sections = [RecordRenderer::FORM => new Section('', $tabelle->markup)];

        if ($diagnostic !== '') {
            $sections[RecordRenderer::DIAGNOSTIC] = new Section('', $diagnostic);
        }

        // ⚠️ *`submits: null` mit Absicht: der Rahmen zieht **kein** Formular um die Tabelle — jede Zeile
        // hat ihr eigenes. Ohne das lägen Formulare ineinander, was HTML verbietet.*
        return $this->renderers->byName(RecordRenderer::NAME)->render(
            $model,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                surroundings: new Surroundings(sections: $sections),
                developerMode: $developerMode,
            )
        );
    }

    public function recordAsBlock(
        Node $model,
        array $edges,
        array $values,
        string $title,
        array $acts = [],
        ?Submission $submits = null,
        string $fieldPrefix = '',
        string $diagnostic = '',
        bool $developerMode = false,
        Purpose $purpose = Purpose::Edit,
        string $locale = '',
        Level $level = Level::Admin,
    ): RenderResult {
        // ⚠️ **Ein Datensatz wird immer als **Tabelle** gezeichnet, nicht mit dem Renderer des
        // Knotens.** *Auf sein Wort: «ich würde die Records immer als Tabelle zeigen, also Table-Renderer,
        // nicht den Renderer des Knotens».*
        //
        // ⚠️ **Und es ist dieselbe Regel, die für Einstellungen schon gilt** ([D-546](90-decision-log.md)):
        // *wie eine Sache **im Modelleditor** angeordnet wird, ist nicht die Anzeigewahl des Knotens. Der
        // Renderer des Knotens sagt, wie er einem **Leser** erscheint; hier arbeitet ein Autor an einer
        // Liste von Sätzen, und die liest sich als Tabelle. **Vorher konnte ein Knoten mit `compact` seine
        // Datensätze zu einer Zeile zusammenschieben** — dieselbe Ansicht, unbrauchbar geworden durch eine
        // Wahl, die für die Anzeige gedacht war.*
        $sections = [
            RecordRenderer::FORM => new Section(
                $title,
                $this->nodeAsForm(
                    $model,
                    $edges,
                    $values,
                    $purpose,
                    $fieldPrefix,
                    $locale,
                    $level,
                    true,
                    TableRenderer::NAME
                )->markup
            ),
        ];

        if ($diagnostic !== '') {
            $sections[RecordRenderer::DIAGNOSTIC] = new Section('', $diagnostic);
        }

        return $this->renderers->byName(RecordRenderer::NAME)->render(
            $model,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                surroundings: new Surroundings(actions: $acts, submits: $submits, sections: $sections),
                developerMode: $developerMode,
            )
        );
    }

    

    /**
     * Draw a node's attributes as rows — one renderer per attribute, the subject being the **edge**.
     *
     * ⚠️ **The attribute table was the last hand-built markup on the detail page**, which `R1`
     * forbids: *everything that is displayed must be a renderer.* The owner found it by asking for
     * the one thing it could not do — *the name of the attributes should be changeable*
     * ([D-376](90-decision-log.md)).
     *
     * ```mermaid
     * flowchart LR
     *   E["each attribute edge"] --> C["its multiplicity, drawn"]
     *   E --> T["its target's name"]
     *   E --> A["what may be done, from the boundary"]
     *   C & T & A --> R["the attribute renderer · one row"]
     * ```
     *
     * ⚠️ **`editable` carries *declared here*, and it is not a second fact.** An attribute may be
     * renamed exactly where it is declared, so the flag the renderer reads to decide *field or text*
     * is the same one that decides *own or inherited*. Two fields for one truth would drift.
     *
     * ⚠️ **The multiplicity is drawn by the settings side rather than built here** — it is an
     * ordinary setting on the edge ([D-351](90-decision-log.md)), and a second select composed in
     * this method would be the same control twice. *That is precisely the defect D-376 records: the
     * hand-built one had been posting to a field name nobody read since the settings panel moved.*
     *
     * @param  list<Relation>                  $edges     The attributes, in the order shown.
     * @param  array<int, list<Control>>       $actions   What may be done, keyed by **edge** id.
     * @param  array<int, Submission>          $submits   Where those go, keyed by edge id.
     * @param  int                             $declaredBy The node whose page this is — an
     *                                                    attribute is editable only on the node that
     *                                                    declares it.
     * @param  array<int, string>              $targetHrefs Where a target node is reached, keyed by
     *                                                    **node** id — not by edge id, because two
     *                                                    attributes pointing at one node share the
     *                                                    address.
     * @return list<RenderedField>
     */
    public function fieldRowsFor(
        array $edges,
        int $declaredBy,
        array $actions = [],
        array $submits = [],
        string $namePrefix = '',
        string $settingPrefix = '',
        string $locale = '',
        Level $level = Level::Admin,
        // ⚠️ *Hier standen `$settingActs`, `$settingSubmits` und `$settingsTitle` — die Zutaten des
        // Einstellungsblocks je Zeile, der mit [D-520](../../../docs/NewConcept/90-decision-log.md)
        // entfallen ist. **Sie waren danach reine Mitläufer**: der Aufrufer baute sie, die Methode nahm
        // sie an, und niemand las sie mehr.*
        array $targetHrefs = [],
        /**
         * ⚠️ **Der Wert je Angabe, damit die Zeile ihn zeigen kann** — *auf sein Wort: «ich verstehe
         * nicht, warum es nicht im Setting `Display Option` angezeigt wird, das ist genau dafür da».
         * Und das Konzept sagt dasselbe: «der Eingabemechanismus existiert bereits: die
         * Einstellungsseite».*
         *
         * @var array<int, TypedValue> Kanten-Id => Wert
         */
        array $values = [],
        /** ⚠️ *Der Namensvorsatz der Wertbedienungen — leer heisst «nur zeigen, nicht abschicken».* */
        string $valuePrefix = '',
        /**
         * ⚠️ *Das Formular, in das die Wertbedienungen gehören. **Nicht das der Zeile**: sein Wunsch war
         * ein Speichern oben und keiner je Wert — «Save in Fields sollte eigentlich auch über die Seite
         * gehen».*
         */
        string $pageForm = '',
        /**
         * ⚠️ *Die Teile je Trägerkante, geladen **vor** dem Zeichnen
         * ([D-159](../../../docs/NewConcept/90-decision-log.md)) — sonst zeigte die Wertspalte leere
         * Bedienelemente, obwohl im Teil ein Wert stand.*
         *
         * @var array<int, list<array{id: int, werte: array<int, TypedValue>}>>
         */
        array $parts = [],
        /**
         * Der Auswahldialog je Kante, fertig gezeichnet vom Rand -- TASK-029.
         *
         * @var array<int, string> Kanten-Id => Markup
         */
        array $targetChoosers = [],
        /**
         * Ob diese Zeilen ueberhaupt eine Wertspalte haben.
         *
         * WICHTIG: Fuer Felder aus, fuer Einstellungen an -- auf sein Wort: "die ganze Spalte
         * Value muss weg". Ein Feld ist Benutzerdaten, die in einem Datensatz stehen, nicht im
         * Modell; eine Einstellung ist Modelldaten, und ihr Wert gehoert hier gezeichnet
         * (D-546, D-548).
         */
        bool $showValue = true,
    ): array {
        if ($edges === []) {
            return [];
        }

        $renderer = $this->renderers->byName(FieldRowRenderer::NAME);
        $resolved = $this->vonDenKanten($edges);
        $targets  = $this->nodes->byIds(array_map(static fn (Relation $e): int => $e->toId, $edges));

        // ⚠️ One query for the whole table, not one per row (`CD-7`) — and through the ordinary
        // label walk, so an attribute's target reads the same here as it does anywhere else.
        $names = $this->labels === null
            ? []
            : $this->labels->forNodes(array_values($targets), SeededRole::Form, $locale);

        $rows = [];

        foreach ($edges as $edge) {
            $settings = $this->withModelValues($resolved[$edge->id] ?? [], $edge);

            // The multiplicity, drawn once by the settings side and handed to the row.
            $configured = [];

            // ⚠️ **Die Angabe gehört ins Seitenformular, nicht ins Formular der Zeile** — *sein Befund:
            // «in Display Option hatte ich für Converter die `1..1`-Beziehung angegeben, das ist falsch,
            // ich wollte es in `0..1` ändern, kann es aber nicht mit dem Speichern-Knopf in der Seite
            // speichern.» **Gemessen war es genau das:** der Auswahlkasten nannte `taxmod-field-<Kante>`,
            // und den schickt nur die Diskette der Zeile ab. Ein `<tr>` kann kein `<form>` umschliessen,
            // aber `form="…"` darf jedes Formular nennen — also das der Seite, wie die Wertspalte längst.*
            //
            // ⚠️ **Und je Zeile ihr eigener Name, sonst gewinnt die letzte.** *Alle Zeilen hiessen
            // `taxmod_setting[multiplicity]`; in **einem** Formular wäre das eine Angabe für die ganze
            // Tabelle. Der Vorsatz trägt jetzt die Kanten-Id, wie es die Wertspalte schon tut.*
            //
            // ⚠️ **Dieselbe Angabe wie die Zeile selbst** ([D-376](../../../docs/NewConcept/90-decision-log.md)):
            // *eine geerbte Kante gehört dem Vorfahren, und «wie oft» hier zu ändern hiesse, es für alle
            // zu ändern — still. Die Zeile wusste es und gab es nicht weiter.*
            $rowSettings = $settingPrefix === '' ? '' : $settingPrefix . '[' . $edge->id . ']';
            $rowForm     = $pageForm === '' ? FieldRowRenderer::formFor($edge) : $pageForm;

            foreach ($this->settingsFor($edge, $settings, Purpose::Edit, $rowSettings, $locale, $level, [], $rowForm, $edge->fromId === $declaredBy) as $drawn) {
                $configured[$drawn->key] = $drawn;
            }

            // ⚠️ **Der Wert der Angabe, gezeichnet vom gewöhnlichen Abstieg.** *Bei `Display Option`
            // steigt der in den Teil hinein und liefert **beide** Felder — `render` und `converter`;
            // sein Satz dazu: «es sollte ja auch das zweite Feld für Converter zu sehen sein». Bei
            // `read_only` kommt ein Schalter, bei `label_role` eine Auswahl.*
            //
            // ⚠️ *Ein Aufruf je Zeile, und er kostet keine Abfrage: die Kanten des Unterbaus holt
            // {@see self::subgraph()} in einer festen Zahl von Abfragen
            // ([D-159](../../../docs/NewConcept/90-decision-log.md)).*
            $gezeichneterWert = ! $showValue ? [] : $this->fieldsFor(
                [$edge],
                $values,
                Purpose::Edit,
                $valuePrefix,
                $locale,
                $level,
                true,
                $pageForm,
                0,
                [],
                // ⚠️ *Der Knoten dieser Seite gilt als «schon besucht» — sonst klappt eine Einstellung,
                // die auf ihn selbst zeigt, ihn ein zweites Mal auf ([OQ-133](../../../docs/NewConcept/91-open-questions.md)).*
                [$declaredBy => true],
                $parts,
                // ⚠️ *Wessen Angaben hier stehen — damit die Renderer-Auswahl auf das eingeschränkt
                // werden kann, was **dieser** Knoten verträgt ([Zeile 92](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).*
                $declaredBy,
                // WICHTIG: Diese Wertspalte definiert den Typ, sie fuellt keinen Datensatz --
                // ein Feld auf eine Komposition (z.B. Adresse) bekommt hier keine eigene
                // Eingabezeile fuer ihre Glieder, nur eine Einstellungskante steigt ab.
                true
            );

            $context = new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::nothing(),
                settings: $settings,
                locale: $locale,
                level: $level,
                editable: $edge->fromId === $declaredBy,
                fieldName: $namePrefix === '' ? '' : $namePrefix . '[' . $edge->id . ']',
                surroundings: new Surroundings(
                    refersTo: $names[$edge->toId] ?? null,
                    actions: $actions[$edge->id] ?? [],
                    // ⚠️ **The target's address, so the row can be a way *to* it.** The owner,
                    // 2026-08-26: *should have a jump link to the node.* Reading a model meant
                    // finding `BOM Position` in the tree by eye.
                    //
                    // ⚠️ *Keyed by the **target's** id and handed in, because a URL is a boundary
                    // fact (`CD-1`) and one lookup per row would be `CD-7`'s loop. The screen
                    // builds it from the same method the tree rows use.*
                    href: $targetHrefs[$edge->toId] ?? null,
                    // WICHTIG: Der Auswahldialog dieser Zeile -- TASK-029. Er kommt fertig vom
                    // Rand, weil er URL und Nonce braucht, und wird nur durchgereicht.

                    submits: $submits[$edge->id] ?? null,
                    // ⚠️ *Das Formular der **Seite**, damit das Namensfeld mit ihr gespeichert wird —
                    // sein Wunsch: «Save in Fields sollte eigentlich auch über die Seite gehen». Leer
                    // heisst «keins», und dann nimmt die Zeile wieder ihr eigenes.*
                    formId: $pageForm,
                    // ⚠️ **Die Sperre aus [D-607](../../../docs/NewConcept/90-decision-log.md), hier
                    // nur weitergereicht.** *Die Regel wohnt an einer Stelle
                    // ({@see ModelValues::inheritanceBlocked()}); die Zeile kann sie nicht selbst
                    // stellen, weil sie den Knoten der Seite nicht kennt — genau dafür ist
                    // `$declaredBy` da. **Weggelassen wird die Zeile nicht**
                    // ([D-608](../../../docs/NewConcept/90-decision-log.md)).*
                    locked: ModelValues::inheritanceBlocked($edge, $declaredBy),
                    configured: $configured,
                    // ⚠️ **The same panel as a node's, drawn here and placed there** — so the attribute
                    // row cannot grow a settings list of its own.
                    //
                    // ⚠️ **The reason given here used to be «a renderer cannot call another renderer
                    // (D-159)», and that rule does not exist.** *[D-452](../../../docs/NewConcept/90-decision-log.md)
                    // withdrew it — [R5](../../../docs/NewConcept/30-renderer.md) says the opposite in
                    // the owner's own words, *«a renderer may work with trees and **call other
                    // renderers**»*, and D-159 says only that the descent's inputs are loaded before it
                    // starts. **The withdrawal reached two docblocks out of three and this was the
                    // third** — the same shape [D-469](../../../docs/NewConcept/90-decision-log.md)
                    // measured for documents, in code.*
                    //
                    // ⚠️ *The arrangement stands on its own merit and needs no rule: the panel is drawn
                    // **once** at this level and placed in every row, so there is one place that knows
                    // what a settings panel looks like. That is `R1`, not a prohibition.*
                    // ⚠️ **Hier stand der Einstellungsblock je Feldzeile, und er ist weg.** *Der
                    // Eigentümer: «wenn ich Settings unter einem Field aufklappe, habe ich immer noch
                    // den Settings-Renderer — kannst du den mal auskommentieren?» **Nach
                    // [D-518](../../../docs/NewConcept/90-decision-log.md) ist er eine Doppelung**:
                    // dieselben Angaben stehen jetzt als Feldzeilen im Settings-Block, gezeichnet vom
                    // Feldzeilen-Renderer — und `R1` erlaubt **eine** Art, eine Sache zu zeichnen.*
                    //
                    // ⚠️ *Die Mehrfachheit bleibt: sie hängt an `surroundings->configured` und hat ihre
                    // eigene Spalte in der Zeile, nicht diesen Block.*
                    // ⚠️ **Der gezeichnete Wert der Angabe** — *die Spalte, ohne die eine Einstellung
                    // nicht einzustellen war. Bei `Display Option` stehen hier beide Felder des Teils.*
                    //
                    // ⚠️ *Der **Teildatensatz** wird nicht hier angelegt, obwohl die Multiplizität
                    // `1..*` ihn verlangt: eine Seite anzusehen darf nichts schreiben. Er entsteht beim
                    // ersten Speichern, in {@see \Taxmod\Core\Service\DataEntry::putSettingAt()}.*
                    sections: array_merge(
                        // ⚠️ **Nur, wo es die Spalte gibt** ({@see self::fieldRowsFor()}'s
                        // `$showValue`) — sonst schriebe eine leer gezeichnete Feldzeile denselben
                        // Gedankenstrich, den eine Einstellung ohne Wert zeigt, in eine Tabelle,
                        // die den Kopf dafür gar nicht hat.
                        ! $showValue
                            ? []
                            : [FieldRowRenderer::VALUE => new Section(
                                '',
                                $gezeichneterWert === [] ? '' : $gezeichneterWert[0]->result->markup
                            )],
                        // WICHTIG: Der Auswahldialog dieser Zeile -- TASK-029. Er kommt fertig vom
                        // Rand, weil er URL und Nonce braucht (CD-1), und wird durchgereicht.
                        isset($targetChoosers[$edge->id])
                            ? ['target-chooser' => new Section('', $targetChoosers[$edge->id])]
                            : []
                    )
                ),
            );

            $rows[] = new RenderedField(
                $edge,
                null,
                $renderer->name(),
                $renderer->render($edge, $context),
                false
            );
        }

        return $rows;
    }

    /**
     * Draw the settings resolved for a node — the settings side, through the renderers.
     *
     * ⚠️ **This is [R20a](30-renderer.md#r20a--the-detail-view-is-not-a-special-screen) applied
     * where it had not been.** *The settings side is a series of attributes rendered under the edit
     * purpose*, and it was printing key and value as text because nothing said what type a
     * setting's own value has. {@see SettingKey::typeFor()} says it, and the same renderers that
     * draw a record draw this — so there is no second way to draw a field.
     *
     * ⚠️ **Three rows come back undrawn, each for its own honest reason:** a **choice** wants a
     * chooser and none is built; a **free key** has no type the engine can know; and a *borrowing*
     * key on a subject with no type of its own has no shape to be drawn in.
     *
     * @param  array<string, \Taxmod\Core\Model\ResolvedSetting> $resolved
     * @return list<RenderedSetting>
     */
    public function settingsFor(
        Identity $node,
        array $resolved,
        Purpose $purpose = Purpose::Display,
        string $fieldPrefix = '',
        string $locale = '',
        Level $level = Level::Admin,
        array $choices = [],
        string $formId = '',
        /**
         * ⚠️ *Ob eine Angabe **hier** geändert werden darf — an einer geerbten Kante nicht
         * ([D-376](../../../docs/NewConcept/90-decision-log.md)). Vorgabe `true`, damit die
         * Aufrufstellen unverändert bleiben, die eine eigene Sache zeichnen.*
         */
        bool $editable = true,
    ): array {
        // ⚠️ **A use site is configured too, and its type is its target's.** [C8](../../../docs/NewConcept/10-domain-core.md)
        // gives an edge settings of its own and [D-091](90-decision-log.md) resolves them the same
        // way; what differs is only where the type comes from — a node *is* the type, an edge
        // *points* at it. *Until the attribute renderer wanted the multiplicity drawn, nothing had
        // ever asked this method about an edge, so the narrower signature had never been wrong.*
        $subject = $node instanceof Relation ? $this->typeAt($node) : $this->typeOfNode($node);

        // ⚠️ **Every key that applies, not only the ones somebody wrote.** The owner, on an `int`
        // node whose chain was empty: *the settings that belong firmly to the data type — min,
        // max, step — should be shown as such.* An unset key becomes a row with an empty control
        // and `setHere = false`, which is the truth about it: nothing along the chain has said.
        //
        // ⚠️ **`multiplicity` applies only to an edge** and is the one key that does (D-351) — a
        // node describes a thing, and a thing has no multiplicity.
        foreach (SettingKey::applyingTo($subject, $node instanceof Relation) as $key) {
            $resolved[$key->value] ??= new ResolvedSetting(
                $key->value,
                TypedValue::nothing(),
                0,
                false
            );
        }

        ksort($resolved);

        $drawn = [];

        // ⚠️ **`hide` puts the renderer out of force** ([D-399](90-decision-log.md)). The owner:
        // *`hide` would have to override the renderer — so **no renderer is valid**, because it is
        // not used here; the field should then be greyed out.* **This is the honest answer to an
        // empty renderer**, and it narrows [D-352](90-decision-log.md) rather than breaking it: a
        // renderer is always resolved *where something is drawn*, and a hidden field draws nothing.
        //
        // ⚠️ *Read with `($a['x'] ?? null)?->y` and never `$a['x']?->y` — the second warns on a
        // missing key, which is a bug that was written two files from this line on 2026-08-26.*
        // ⚠️ **Read off the subject now** ([D-457](90-decision-log.md)): `hide` is a column on
        // {@see \Taxmod\Core\Model\Identity}, so there is no resolved setting to ask.
        //
        // ⚠️ **And [D-399](90-decision-log.md)'s second half is narrowed away by [D-448](90-decision-log.md)**:
        // *a hidden **node** has a renderer choice like any other, so the greying lost its ground.*
        // What survives is the case this line was written for — **a hidden placement draws nothing,
        // so «which renderer draws it» has no answer to force.*
        // ⚠️ *`$hidden` stood here, read for [D-399](90-decision-log.md)'s greying. With
        // [D-448](90-decision-log.md) the greying is gone, and so is its reader — a variable that
        // decides nothing is the dead code `CLAUDE.md` forbids outright.*

        foreach ($resolved as $key => $setting) {
            $engineKey = SettingKey::tryFrom($key);
            $shape     = $engineKey?->shape() ?? SettingShape::Words;
            $type      = $engineKey?->typeFor($subject);

            // ⚠️ **A choice is drawn now, by the choice renderer** — it was one of three rows that
            // came back undrawn, and the honest reason was *a chooser wants a set and none is
            // built*. It is built, so the reason is gone. *The other two remain honest: a free key
            // has no type the engine can know, and a borrowing key on a subject with no type of its
            // own has no shape to be drawn in.*
            if ($engineKey !== null && $shape->isAChoice()) {
                $drawn[] = $this->drawChoice($node, $engineKey, $shape, $setting, $purpose, $fieldPrefix, $locale, $level, $subject, $choices, $formId, $editable);

                continue;
            }

            // A free key, or a borrowed type the subject does not have. Nothing is drawn, and the
            // caller is told which of the two it is by the shape.
            if ($engineKey === null || $type === null) {
                $drawn[] = new RenderedSetting($key, $shape, $type, $setting, null, null, $subject);

                continue;
            }

            $renderer = $this->renderers->defaultFor($type);

            $context = new RenderContext(
                $purpose,
                $setting->value,
                // ⚠️ **No settings inside a setting.** The chain resolved this value; a renderer
                // drawing it must not then resolve `hide` or `read_only` against the same node, or
                // hiding an attribute would hide the control that un-hides it.
                [],
                $locale,
                $level,
                true,
                $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $key . ']',
                $type,
            );

            $drawn[] = new RenderedSetting(
                $key,
                $shape,
                $type,
                $setting,
                $renderer->render($node, $context),
                $renderer->name(),
                $subject
            );
        }

        return $drawn;
    }

    /**
     * A node drawn as a whole — its members through the descent, then laid out by a container.
     *
     * ⚠️ **This is the shape [R46](30-renderer.md#r46r47--a-container-renderer-is-the-same-recursion)
     * asks for, arranged so that D-159 still holds.** *Every cell goes back to the registry* — and
     * the descent is what asks, because a renderer reaches out to nothing. The container receives
     * the finished members in the context and regroups them (R75).
     *
     * ⚠️ **The container is chosen the same way a field's renderer is** — the chain, then the
     * structural default. A node with no simple type has no *typed* renderer and this is what fits
     * it, which is why `eligibleFor()` on a supplier stopped being empty the moment the form
     * renderer existed.
     *
     * @param list<Relation>        $edges
     * @param array<int, TypedValue> $values
     */
    /**
     * What a preview should be filled with, when nothing has been entered.
     *
     * ⚠️ **[D-160](90-decision-log.md) is explicit that this exists**: *the preview loads the test
     * data and renders that … **defaults remain the fallback** where no pack covers the model.* The
     * owner's reason is the whole point of a preview — *a form of empty fields shows that the
     * structure exists, a filled one shows whether it **reads***.
     *
     * ⚠️ **This is not «no record is the default record».** That was the tempting version and
     * [D-160](90-decision-log.md) turned it down: a missing value is **not answered**
     * ([D-232](90-decision-log.md)), and a real form must keep showing it that way. *So the
     * substitution happens here, for the preview only, and never on the way into storage.*
     *
     * ⚠️ **A default is a `choosing` setting, so it may be anything the type permits**
     * ([D-312](90-decision-log.md)) — which is why this reads the resolved chain rather than the
     * edge: a default written at the type is exactly the one a preview should show.
     *
     * @param  list<Relation>                                    $edges
     * @param  array<int, array<string, ResolvedSetting>>         $resolved Settings per edge id.
     * @param  array<int, TypedValue>                             $held     What a record holds, if any.
     * @return array<int, TypedValue>                                       Keyed by edge id.
     */
    /**
     * What a **non-persistent** attribute is worth for one particular node.
     *
     * ```mermaid
     * flowchart LR
     *   K["kilo"] -->|"default at path «exponent»"| V["3"]
     *   P["Prefixes declares exponent · persistent = false"] --> K
     * ```
     *
     * ⚠️ **This is the first consumer of `settings.path`** ([D-413](90-decision-log.md)) and it is
     * what makes [D-378](90-decision-log.md) work at last. That decision made a prefix's exponent an
     * **attribute** rather than a reserved key, so that *whoever hangs under `Prefixes` has one and
     * nobody else does* — and its value lives as a `default`, because
     * [D-026](90-decision-log.md) says *at model level there are no values, only defaults*.
     *
     * ⚠️ **Measured broken on 2026-08-26 and this is the repair.** The value had been written at the
     * **empty** path, meaning *kilo's own default*, and the attribute could never see it: a use site
     * resolves from its **target's** chain, and `kilo` is not in that chain. *So the question has to
     * be asked of the node, at the attribute's path — which is exactly what the column was added
     * for.*
     *
     * ⚠️ *Nothing is invented when nothing is there. A missing row means this node says nothing about
     * that attribute, which is a different fact from «zero» and is returned as such.*
     */
    public function nonPersistentValue(Node $node, Relation $edge): ?TypedValue
    {
        // ⚠️ **Nur noch die neue Stelle** ([D-579](../../../docs/NewConcept/90-decision-log.md)):
        // *hier stand darunter der Rückfall auf die `settings`-Tabelle. Sie ist gestrichen, und
        // gemessen am 2026-09-04 trug sie **keine einzige `default`-Zeile** mehr, sondern nur noch
        // 13 Zeilen mit `read_only` und `label_role`. **Ein Rückfall auf eine Tabelle, die für
        // diesen Schlüssel nichts hält, ist kein Rückfall, sondern toter Code.***
        return $this->model?->defaultFor($node, $edge);
    }

    /**
     * Which of a node's records a preview draws from — **real data before a row marked as test data**.
     *
     * ⚠️ **The middle rung of the decided order, and it was missing rather than deferred.**
     * [D-028](90-decision-log.md): *«Testdaten sind gewöhnliche Daten, gekennzeichnet. Zeilen können
     * als Testdaten markiert werden; die Vorschau zeichnet den Knoten in der Datenansicht über diesen
     * Zeilen und fällt auf die Vorgaben zurück, wo keine da sind.»* The order is
     * **real data → rows marked as test data → the type's sample**, and until schema 13 the column
     * did not exist, so the surface took whichever record came first by id.
     *
     * ```mermaid
     * flowchart LR
     *   A["records of the node"] --> R{"any not marked?"}
     *   R -- yes --> N["the first unmarked one"]
     *   R -- no --> T["the first marked one"]
     *   R -- none at all --> D["null · the defaults draw"]
     * ```
     *
     * ⚠️ **It chooses a record, it does not blend two.** *Taking the real values and topping them up
     * from a test row would answer «which record does a preview show» twice in one preview, and that
     * question is still nobody's — {@see previewValuesFor()} tops up from the **defaults**, which are
     * a fact about the type rather than about a second row.*
     *
     * ⚠️ **Order within a rung is left as it arrives**, so *which* real record is still the caller's
     * first — the unanswered half of the same question, and this method does not pretend to close it.
     *
     * @param  list<NodeRecord> $records
     */
    public function previewRecordAmong(array $records): ?NodeRecord
    {
        $marked = null;

        foreach ($records as $record) {
            // ⚠️ *Die Reihenfolge ist **unverändert**: was nicht als Testdaten markiert ist, geht vor.
            // **Wo ein Autoren-Datensatz einzuordnen ist, ist nicht entschieden**
            // ([D-521](../../../docs/NewConcept/90-decision-log.md)) — er zählt darum vorerst wie eine
            // gewöhnliche Eingabe, was genau das ist, was `is_test = 0` bisher tat.*
            if ($record->kind !== RecordKind::Example) {
                return $record;
            }

            $marked ??= $record;
        }

        return $marked;
    }

    public function previewValuesFor(array $edges, array $resolved, array $held = []): array
    {
        $values = [];

        foreach ($edges as $edge) {
            // ⚠️ **Real data wins, and the rung between it and the defaults is
            // {@see previewRecordAmong()}** — the caller has already chosen *which* record the
            // values came from, so what is left here is the decided *«fällt auf die Vorgaben
            // zurück, wo keine da sind»* of [D-028](90-decision-log.md).
            if (isset($held[$edge->id]) && ! $held[$edge->id]->isNothing()) {
                $values[$edge->id] = $held[$edge->id];

                continue;
            }

            $default = $resolved[$edge->id][SettingKey::DefaultValue->value] ?? null;

            if ($default !== null && ! $default->value->isNothing()) {
                $values[$edge->id] = $default->value;
            }
        }

        return $values;
    }

    /**
     * Which edges a preview may leave out, and which it must draw dead rather than absent.
     *
     * ⚠️ **This is what the owner is after** — *we need the preview to fix the flag and renderer
     * concept errors.* `hide` and `read_only` are stored, resolved and had **no surface that showed
     * them doing anything**: a settings panel draws the switch, not its effect. **Here they have
     * one.**
     *
     * ```mermaid
     * flowchart LR
     *   H["hide = true"] --> G["gone from the preview"]
     *   R["read_only = true"] --> D["drawn, not editable"]
     * ```
     *
     * ⚠️ **The two are not variations of one thing.** `hide` removes the row; `read_only` keeps it
     * and refuses the edit — *a computed value a reader should see and nobody may type*. Collapsing
     * them would make a read-only field invisible, which is the opposite of what it is for.
     *
     * @param  list<Relation>                            $edges
     * @param  array<int, array<string, ResolvedSetting>> $resolved
     * @return array{shown: list<Relation>, hidden: list<Relation>, settings: list<Relation>, fixed: list<int>}
     */
    public function previewVisibilityFor(array $edges, array $resolved): array
    {
        $shown    = [];
        $hidden   = [];
        $settings = [];
        $fixed    = [];

        foreach ($edges as $edge) {
            $keys = $resolved[$edge->id] ?? [];

            // ⚠️ `($a['x'] ?? null)?->y` and **not** `$a['x']?->y` — the second is a warning on a
            // missing key, which is a bug this file's own docblock warns about and which was written
            // two files away on 2026-08-26.
            // ⚠️ *The edge's own column ([D-457](90-decision-log.md)) — no chain, no resolution.*
            if ($edge->hide) {
                $hidden[] = $edge;

                continue;
            }

            // ⚠️ **Eine Einstellung ist keine Daten, also gehört sie nicht in die Vorschau.**
            // *Der Eigentümer hat es am Knoten `Kontakt` gesehen: dort stand «Fields: None yet» und
            // die Vorschau zeigte trotzdem drei Zeilen — `renderer`, `validator`, `read_only`, die
            // **geerbten Einstellungskanten der Wurzel**. Zwei davon als nacktes Textfeld mit
            // `taxmod-no-renderer`, über der Zeile «nothing has been entered against this node yet».*
            //
            // ⚠️ **Und er hat die Diagnose gestellt, die ich nicht hatte:** *«du renderst die Settings,
            // und dort solltest du eigentlich die Settings nicht rendern — also haben wir das im
            // Grunde schon, es ist nur fehlgeleitet.» **Die Fähigkeit fehlte nie, sie stand am
            // falschen Ort.***
            //
            // ⚠️ **Eigene Liste, denn «versteckt» und «keine Daten» sind zwei Gründe.** *Hier stand
            // `$hidden[]`, und der Schirm schreibt über diese Liste «Left out by hide» — also stand an
            // jedem Knoten «Left out by hide: Display Option, validator, read_only», obwohl niemand etwas
            // versteckt hatte. **Ein Etikett, das den falschen Grund nennt, ist schlimmer als keines**,
            // und `preview-check.php` hat genau daran drei Zusagen verloren.*
            if ($edge->kind->isSetting()) {
                $settings[] = $edge;

                continue;
            }

            $shown[] = $edge;

            if ((($keys[SettingKey::ReadOnly->value] ?? null)?->value->asBool() ?? SettingKey::ReadOnly->defaultSwitch()) === true) {
                $fixed[] = $edge->id;
            }
        }

        return ['shown' => $shown, 'hidden' => $hidden, 'settings' => $settings, 'fixed' => $fixed];
    }
    /**
     * The detail head — three labelled rows, drawn by {@see HeadRenderer}.
     *
     * ⚠️ **The screen states the facts and the renderer decides the shape**, which is the same seam
     * every other panel uses ([D-393](90-decision-log.md)): the buttons, the constants and the name
     * field are boundary matter — they carry nonces, capabilities and translated labels — and how
     * they are arranged is not.
     *
     * @param array<string, Section> $rows Keyed by `HeadRenderer::ACTION` / `SYSTEM` / `NAME_ROW`.
     */
    public function headFor(
        Node $node,
        array $rows,
        Purpose $purpose = Purpose::Edit,
        string $locale = '',
        Level $level = Level::Admin,
    ): RenderResult {
        return $this->renderers->byName(HeadRenderer::NAME)->render(
            $node,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                settings: [],
                locale: $locale,
                level: $level,
                surroundings: new Surroundings(sections: $rows)
            )
        );
    }
    public function nodeAsForm(
        Node $node,
        array $edges,
        array $values,
        Purpose $purpose,
        string $fieldPrefix = '',
        string $locale = '',
        Level $level = Level::Admin,
        bool $editable = true,
        /**
         * ⚠️ *Der Behälter, wenn der Aufrufer ihn **festlegt** statt ihn aus dem Modell zu holen —
         * siehe {@see self::recordAsBlock()}. Leer heisst: das Modell entscheidet.*
         */
        string $containerName = '',
    ): RenderResult {
        // ⚠️ *Der gezeichnete Knoten gilt als «schon besucht» — sonst klappt ein Feld, das auf ihn
        // selbst zeigt, ihn ein zweites Mal auf. Genau das war auf `DisplayOption` zu sehen.*
        $parts = $this->fieldsFor($edges, $values, $purpose, $fieldPrefix, $locale, $level, $editable, '', 0, [], [$node->id => true]);

        $container = $containerName === ''
            ? $this->containerFor($node, $purpose)
            : $this->renderers->byName($containerName);

        return $container->render(
            $node,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                editable: $editable,
                surroundings: new Surroundings(parts: $parts),
            )
        );
    }

    /**
     * Which container lays out a node's members — the chain, then the structural default.
     *
     * ⚠️ **This existed as a sentence in {@see nodeAsForm()}'s docblock and not as code.** *That
     * docblock said «the container is chosen the same way a field's renderer is — the chain, then the
     * structural default», while the line beneath it read `byName(FormRenderer::NAME)` and asked
     * nothing. **`form` worked only because it was the only one**, and the moment
     * {@see CompactRenderer} arrived the owner could choose it and nothing changed on screen.*
     *
     * ⚠️ *Same shape as `hide` stored-and-never-read ([D-396](90-decision-log.md)) and `label_role`
     * storable-and-unreachable — **written, decided, and not built**. It keeps happening at the seam
     * where a setting is offered before anything consumes it, and the owner keeps being the one who
     * finds it: «ich kann irgendwie hier noch nichts richtig aufsetzen».*
     *
     * ⚠️ **Eligibility is asked, not re-invented** ([D-481](90-decision-log.md)): a name only counts
     * if `eligibleFor()` would have offered it for this node. *So the read side and the write side
     * ask one question, and a name that is no longer offerable falls back instead of drawing nothing.*
     *
     * ⚠️ *The fallback is {@see FormRenderer} and deliberately **not** the registry's fallback: a
     * container that cannot lay out its members would drop them, and losing a person's fields is
     * worse than laying them out plainly. [R14b](30-renderer.md)'s «the fallback marks itself» is
     * about a **value**, not about a frame.*
     */
    /**
     * Die aufgelösten Angaben, ergänzt um das, was schon im Modell steht.
     *
     * ⚠️ **Die neue Stelle gewinnt, und das ist der ganze Sinn** ([D-529](../../../docs/NewConcept/90-decision-log.md)):
     * *ein Umzug, nach dem der alte Wert weiter gilt, hat nichts bewegt. Umgekehrt darf die neue Quelle
     * nichts überschreiben, wozu sie nichts sagt — **darum wird ergänzt und nicht ersetzt**.*
     *
     * @param  array<string,\Taxmod\Core\Model\ResolvedSetting> $resolved
     * @return array<string,\Taxmod\Core\Model\ResolvedSetting>
     */
    /**
     * Was das Modell über diese Verwendungsstellen sagt — **die eine Naht**, die jeder Aufrufer nimmt.
     *
     * ⚠️ **Der Eigentümer hat den Grund benannt, bevor ich den achten Fall gefunden hatte:** *«umso
     * wichtiger, dass wir unseren Code richtig kapseln, denn dann können wir ja ganze Teile herauslösen
     * und durch neue ersetzen … hätte man diesen Settings-Mechanismus komplett gekapselt und dann
     * rausgenommen, hätte man sehen können, überall da, wo er knallt.»*
     *
     * ⚠️ **Genau daran hat es gefehlt, und es ist messbar.** *Die Vorschau fragte `resolveForUseSites()`
     * allein — die alte Tabelle mit ihren fünf Zeilen — und bekam für `read_only` nichts. Der Wert lag im
     * Datensatz. **Weil es zwei Wege gab, konnte ein Aufrufer den falschen nehmen, und der falsche
     * antwortete plausibel statt zu knallen.** Das ist derselbe Fehler, den `PR-12` sechsmal an einem
     * Abend gezählt hat, und der Grund ist jedes Mal: zwei Leser für eine Sache.*
     *
     * ⚠️ *Deshalb ist diese Methode öffentlich und {@see self::withModelValues()} bleibt privat: **eine
     * Stelle im Kern beantwortet die Frage**, und wenn die alte Tabelle herausgenommen wird, knallt es
     * hier — an einer Stelle — und nicht still an acht.*
     *
     * @param  list<Relation> $edges
     * @return array<int, array<string, \Taxmod\Core\Model\ResolvedSetting>> Kanten-Id => Angaben
     */
    public function settingsForUseSites(array $edges): array
    {
        $resolved = $this->vonDenKanten($edges);

        // ⚠️ *Alle Ketten auf einmal, bevor die erste gelesen wird (`CD-7`,
        // [D-602](../../../docs/NewConcept/90-decision-log.md)). Sieben Felder eines Formulars zeigen
        // auf sieben Typen, deren Vorfahren sich fast vollständig überschneiden — je Feld nachzusehen
        // wäre linear in der Zahl der Felder, und genau das misst `package7-check.php`.*
        $this->model?->preload($edges);

        $aus = [];

        foreach ($edges as $edge) {
            $aus[$edge->id] = $this->withModelValues($resolved[$edge->id] ?? [], $edge);
        }

        return $aus;
    }

    private function withModelValues(array $resolved, Node|Relation $subject): array
    {
        if ($this->model === null) {
            return $resolved;
        }

        $ausDemModell = $subject instanceof Node
            ? $this->model->forNode($subject)
            : $this->model->forUseSite($subject);

        return [...$resolved, ...$ausDemModell];
    }

    private function containerFor(Node $node, Purpose $purpose): Renderer
    {
        // ⚠️ **Ein Einstellungsknoten wird als Tabelle gezeichnet** ([D-546](../../../docs/NewConcept/90-decision-log.md)),
        // *dieselbe Regel wie für eine Einstellungskante, eine Ebene höher: er ist dasselbe Ding, nur
        // von aussen betrachtet. **Und er hat keine Renderer-Einstellung** — seit
        // [D-545](../../../docs/NewConcept/90-decision-log.md) erbt nichts im Settings-Ast von der
        // Wurzel, also gäbe es unten nichts zu lesen und `form` käme als stille Vorgabe heraus.*
        if ($node->kind === NodeKind::Setting) {
            return $this->renderers->byName(TableRenderer::NAME);
        }

        // ⚠️ **Auch aus den Datensätzen, und ohne dies war die Wahl wirkungslos** ([D-529](../../../docs/NewConcept/90-decision-log.md)).
        // *Hier stand nur die Auflösung über die `settings`-Tabelle. Der Renderer liegt seit dem Umzug
        // im Datensatz — also hätte der Eigentümer `table` wählen können und weiter ein Formular
        // gesehen. **Fünfter Fall derselben Sache an einem Tag:** Daten umgezogen, ein Leser
        // stehengeblieben.*
        $chosen = ($this->withModelValues([], $node)[SettingKey::Renderer->value] ?? null)
            ?->value
            ->text;

        if ($chosen === null || $chosen === '') {
            return $this->renderers->byName(FormRenderer::NAME);
        }

        foreach ($this->renderers->eligibleFor($node, $this->typeOfNode($node), $purpose) as $one) {
            if ($one->name() === $chosen) {
                return $one;
            }
        }

        return $this->renderers->byName(FormRenderer::NAME);
    }

    /**
     * A tree's worth of nodes, each drawn by the cell — **three queries whatever the depth**.
     *
     * ⚠️ **The batching is the point, not an optimisation.** A cell draws the node's icon (D-251),
     * which is a setting on the chain — so asking per row would be a walk per row, and `CD-7`
     * forbids the loop. One query for every node's settings, then every cell drawn in memory.
     *
     * ⚠️ **No labels are fetched, because the tree shows the node's own name**
     * ([D-369](90-decision-log.md)). That closed [OQ-091](91-open-questions.md)'s second half and
     * saved a query with it.
     *
     * ⚠️ **Which cell is a parameter, and that is [D-367](90-decision-log.md)'s whole point:** the
     * modelling tree, the chooser and the trash walk one hierarchy and draw the node differently.
     *
     * @param  list<Node>                    $nodes
     * @param  array<int, list<Control>>     $actions What can be done to each node, **described**
     *                                               — the renderer builds the buttons.
     * @param  array<int, string>            $hrefs   Where each node is reached, per node id.
     * @param  array<int, Submission>        $submits Where its controls submit to, with the nonce.
     * @return array<int, RenderResult>      Keyed by node id.
     */
    public function cellsFor(
        array $nodes,
        array $actions = [],
        array $hrefs = [],
        array $submits = [],
        string $cell = TreeNodeRenderer::NAME,
        string $locale = '',
        Level $level = Level::Admin,
        bool $developerMode = false,
        array $hidden = [],
    ): array {
        if ($nodes === []) {
            return [];
        }

        $settings = $this->vonDenKnoten($nodes);
        $renderer = $this->renderers->byName($cell);

        $cells = [];

        foreach ($nodes as $node) {
            $cells[$node->id] = $renderer->render(
                $node,
                new RenderContext(
                    purpose: Purpose::Display,
                    value: TypedValue::nothing(),
                    settings: $settings[$node->id] ?? [],
                    locale: $locale,
                    level: $level,
                    editable: false,
                    surroundings: new Surroundings(
                        actions: $actions[$node->id] ?? [],
                        href: $hrefs[$node->id] ?? null,
                        submits: $submits[$node->id] ?? null,
                        // ⚠️ *Prepared, not asked: a cell draws a **node** and `hide` sits on its
                        // **edge** ([D-467](90-decision-log.md), [D-445](90-decision-log.md)).*
                        hidden: $hidden[$node->id] ?? false
                    ),
                    // ⚠️ **A circumstance and not a setting** (D-389): developer mode is a fact about
                    // the installation, so the boundary reads it from a WordPress option and hands it
                    // in — it never travelled the settings chain, where it could differ per branch.
                    developerMode: $developerMode,
                )
            );
        }

        return $cells;
    }

    /**
     * A whole tree — the walker over the cells.
     *
     * ⚠️ **Two renderers, one call** ([D-367](90-decision-log.md)): every node goes through the
     * **cell**, and the **walker** nests what came back. Which cell is a parameter, because the
     * modelling tree, the chooser and the trash draw a node differently and must not each grow
     * their own walker — that is the fault [D-346](90-decision-log.md) demonstrated.
     *
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $walked
     * @param array<int, list<Control>>  $actions
     * @param array<int, string>         $hrefs
     * @param array<int, Submission>     $submits
     * @param array<int, string>         $toggles Where folding a row leads, per node id.
     */
    public function treeFor(
        array $walked,
        array $actions = [],
        array $hrefs = [],
        array $submits = [],
        array $toggles = [],
        ?int $highlight = null,
        string $cell = TreeNodeRenderer::NAME,
        string $locale = '',
        Level $level = Level::Admin,
        bool $developerMode = false,
        /**
         * Feldname des Suchfeldes, leer = keines.
         *
         * WICHTIG: Die Seitenansicht ist zugeklappt -- gemessen 11 von 145 Zeilen. Was nicht im
         * Dokument steht, findet kein Skript, und genau daran ist seine Suche gescheitert.
         * Traegt das Feld einen Namen, sucht der Server.
         */
        string $filterName = '',
        string $filterValue = '',
    ): RenderResult {
        if ($walked === []) {
            return RenderResult::of('');
        }

        $nodes = array_map(static fn (array $row): Node => $row['node'], $walked);
        // ⚠️ *The rows already carry it — {@see \Taxmod\Core\Service\Tree::rowsUnder()} reads it off
        // the inheritance edges it loads anyway ([D-467](90-decision-log.md)). No parameter at the
        // boundary, and no query here.*
        $hidden = [];

        foreach ($walked as $row) {
            $hidden[$row['node']->id] = $row['hidden'] ?? false;
        }

        $cells = $this->cellsFor($nodes, $actions, $hrefs, $submits, $cell, $locale, $level, $developerMode, $hidden);

        $rows = $this->drawnRows($walked, $cells, $toggles, $highlight);

        return $this->renderers->byName(TreeRenderer::NAME)->render(
            $nodes[0],
            new RenderContext(
                purpose: Purpose::Display,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                editable: false,
                surroundings: new Surroundings(rows: $rows, filterName: $filterName, filterValue: $filterValue),
            )
        );
    }

    /**
     * A node as a page — the frame of [R20a](30-renderer.md#r20a--the-detail-view-is-not-a-special-screen).
     *
     * ⚠️ **The order is not a parameter.** It lives in {@see PageSlot} because
     * [R20a](30-renderer.md) decided it and wrote down why: *the owner walked that order out loud as
     * the sequence in which a person actually works on a node, and it is written down so a rebuild
     * does not reshuffle it for looks.* The caller says **what** goes in a slot, never **where**.
     *
     * @param array<string, Section> $sections Keyed by {@see PageSlot}'s values.
     */
    public function nodeAsPage(
        Node $node,
        array $sections,
        Purpose $purpose = Purpose::Edit,
        string $locale = '',
        Level $level = Level::Admin,
    ): RenderResult {
        return $this->renderers->byName(NodeRenderer::NAME)->render(
            $node,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                surroundings: new Surroundings(sections: $sections),
            )
        );
    }

    /** What this attribute's value has to be read back as. */
    public function typeAt(Relation $edge): ?SimpleType
    {
        return $this->typesFor([$edge])[$edge->id] ?? null;
    }

    /**
     * The same question for a whole form's worth of attributes, in two queries.
     *
     * ⚠️ **Public because writing needs it too.** A form comes back as characters and each one has
     * to be read as its own type; asking per field would be a query per field on **save** as well
     * as on draw, which is the loop `CD-7` forbids either way round.
     *
     * @param  list<Relation> $edges
     * @return array<int, SimpleType|null>
     */
    public function typesFor(array $edges): array
    {
        return $this->typesOf($edges);
    }

    /**
     * Draw one choosing setting through the choice renderer.
     *
     * ⚠️ **The set comes from here and never from the renderer** ([D-159](90-decision-log.md)). Two
     * shapes, two sources, and they are genuinely different kinds of set: `OneOfFour` is the closed
     * list of [D-351](90-decision-log.md), fixed forever; `ARegisteredName` is whatever the registry
     * answers to, which grows with every renderer added ([R14](30-renderer.md)).
     *
     * ⚠️ **A setting may always be left unset, so *nothing* is always an outcome here.** That is not
     * [R29](30-renderer.md)'s multiplicity rule being ignored — it is that rule applied to a
     * setting: settings are **sparse** ([D-015](90-decision-log.md)), an absent one means *nothing
     * along the chain has said*, and there is no such thing as a mandatory setting. *R29's `1` and
     * `1..*` cases belong to a **value**, where the concept placed them.*
     *
     * ⚠️ *`converter` therefore arrives as an empty set and draws as a disabled control — the honest
     * state, because [D-219](90-decision-log.md) decided converters and none is built. R28–R32 asked
     * for exactly that rather than an empty box that looks fillable.*
     */
    private function drawChoice(
        Renderable $subject,
        SettingKey $key,
        SettingShape $shape,
        ResolvedSetting $setting,
        Purpose $purpose,
        string $fieldPrefix,
        string $locale,
        Level $level,
        ?SimpleType $subjectType = null,
        array $choices = [],
        string $formId = '',
        bool $editable = true,
    ): RenderedSetting {
        $renderer = $this->renderers->byName(ChoiceRenderer::NAME);
        $options  = [];

        // ⚠️ **The multiplicity is never nothing** (D-379): the owner — *multiplicity may not be
        // empty, the default is `0..1`* — so the chooser offers no blank option and an unset setting
        // arrives already reading the standard. *Every other setting may be left unsaid, which is
        // what makes settings sparse (D-015); this one has a meaning when unsaid instead.*
        $mayBeNothing = $shape !== SettingShape::OneOfFour;

        if ($shape === SettingShape::OneOfFour) {
            $setting = new ResolvedSetting(
                $setting->key,
                TypedValue::ofText(Multiplicity::fromSetting($setting->value->text)->value),
                $setting->fromOwnerId,
                $setting->setHere
            );

            foreach (Multiplicity::cases() as $one) {
                // ⚠️ **Ein `bool` bekommt keine Untergrenze null angeboten** ([D-412](../../../docs/NewConcept/90-decision-log.md)),
                // auf sein Wort: *«ein `bool` hat genau zwei Zustände … und ein `bool` darf keine
                // Multiplizität von null haben.»* `0..1` hiesse «vielleicht wahr, vielleicht falsch,
                // vielleicht keins» — und ein Drittes gibt es nicht.
                //
                // ⚠️ *Gefragt wird {@see Multiplicity::requiresOne()} und **nicht** eine Liste der
                // beiden Werte: die Frage «verlangt das eine Antwort» ist genau die, die hier
                // gestellt wird, und sie hat schon eine Stelle. Eine zweite Aufzählung daneben wäre
                // dieselbe Tatsache doppelt.*
                //
                // ⚠️ *Das ist `R28` — **ein Steuerelement bietet nur echte Wahlen an**. Die
                // Speicherseite kannte die Regel längst: der Schalter schreibt eine verborgene `0`
                // neben die Ankreuzbox ([D-370](../../../docs/NewConcept/90-decision-log.md)), damit
                // ein leeres Kästchen `false` sendet statt nichts. **Nur der Wähler log noch.***
                if ($subjectType === SimpleType::Bool && ! $one->requiresOne()) {
                    continue;
                }

                // ⚠️ The **notation**, deliberately not translated — `0..1` is not English and
                // survives a locale change without a label.
                $options[$one->value] = $one->notation();
            }
        }

        // ⚠️ **A set the boundary knows takes precedence over anything worked out here**
        // ([D-390](90-decision-log.md)). Which **icons** an installation offers is a boundary fact
        // (`CD-1`) — the core cannot list Dashicons — so the options are handed in and this places
        // them. *That is the same seam as `Control`: the boundary states, the core composes.*
        if ($choices[$key->value] ?? null) {
            $options      = $choices[$key->value];
            $mayBeNothing = true;
        }

        // ⚠️ **The converter's set comes from the converter registry, and it may be empty.** *An empty
        // set draws as a disabled control, which R28–R32 asked for over an empty box that looks
        // fillable — so this branch does not need to special-case «none registered»: no options is
        // already the honest state.*
        //
        // ⚠️ **And unlike the renderer below, *nothing* stays an outcome.** No converter means the value
        // is shown as it is stored ([R33b](30-renderer.md#r33b--several-are-eligible-exactly-one-is-in-effect)),
        // so there is no default to force and `mayBeNothing` is left alone. *Forcing one here would map
        // every number in the installation the moment a converter was registered.*
        if ($shape === SettingShape::ARegisteredName && $key === SettingKey::Converter && $this->converters !== null) {
            $forType = $subject instanceof Relation ? $this->typeAt($subject) : $this->typeOfNode($subject);

            foreach ($this->converters->eligibleFor($forType) as $one) {
                $options[$one->name()] = $one->name();
            }
        }

        if ($shape === SettingShape::ARegisteredName && $key === SettingKey::Renderer) {
            $eligible = $subject instanceof Relation
                ? $this->choicesFor($subject, $purpose)
                : $this->choicesForNode($subject, $purpose);

            foreach ($eligible as $one) {
                $options[$one->name()] = $one->name();
            }

            // ⚠️ **A renderer is never nothing, and the control must say which one is in force**
            // ([R33c](30-renderer.md#r33c--automatic-is-a-default-never-a-fact), [D-352](90-decision-log.md)).
            // The owner: *the renderer must always be set, we agreed that.* What was agreed is the
            // sharper thing — **it is always *resolved***, because *which one is the default is a fact
            // the registry holds* — and R33c adds that **an automatic choice must be visible**. The
            // panel was showing an empty option as selected on `decimal`, `text` and `bool` while
            // `field`, `field` and `toggle` were in fact drawing them. *An empty control over a
            // working default is the worst of the three states: it reads as «nothing draws this».*
            //
            // ⚠️ *Shown, not written.* Storing the default on every node would put one fact in a
            // thousand places and break what the type default is **for** — change it centrally and
            // nothing would follow (D-015: settings are sparse).
            $mayBeNothing = false;

            // ⚠️ *The `hide` exception that stood here is gone with [D-448](90-decision-log.md). It set
            // `mayBeNothing` for a hidden subject so the choice could stay empty — and it rested on
            // [D-399](90-decision-log.md)'s second half, which lived on `hide` being able to hide a
            // **field**. **A hidden node has a renderer choice like any other**: it is not shown in the
            // tree, and that says nothing about how it would be drawn.*
            if ($setting->value->isNothing()) {
                $inForce = $this->renderers->defaultFor(
                    $subject instanceof Relation ? $this->typeAt($subject) : $this->typeOfNode($subject)
                );

                // ⚠️ **Only where it is genuinely one of the choices.** For a subject with no simple
                // type the registry answers with the **fallback** — the marker that says *nothing
                // draws this yet* (R14b) — and that is not an option anybody may pick, so selecting
                // it would be the control claiming a choice the model does not offer.
                if (isset($options[$inForce->name()])) {
                    $setting = new ResolvedSetting(
                        $setting->key,
                        TypedValue::ofText($inForce->name()),
                        0,
                        false
                    );
                } else {
                    $mayBeNothing = true;
                }
            }
        }

        return new RenderedSetting(
            $key->value,
            $shape,
            null,
            $setting,
            $renderer->render(
                $subject,
                new RenderContext(
                    purpose: $purpose,
                    value: $setting->value,
                    settings: [],
                    locale: $locale,
                    level: $level,
                    fieldName: $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $key->value . ']',
                    // ⚠️ **Greyed and not removed** ([D-399](90-decision-log.md), [R30](30-renderer.md)):
                    // a control that vanishes when a switch is thrown makes a person hunt for the row
                    // they were about to use. *A disabled control submits nothing, so keeping it costs
                    // nothing — which is the same argument the choice renderer already makes for a
                    // model that cannot be satisfied.*
                    // ⚠️ **The greying is gone** ([D-448](90-decision-log.md), confirmed by
                    // [D-457](90-decision-log.md)). *It was `! ($hidden && $key === Renderer)` and it
                    // implemented [D-399](90-decision-log.md)'s second half — which lived on `hide`
                    // being able to hide a **field**. Now it hides a node in the tree, and a hidden
                    // node's renderer choice is as real as any other's.*
                    // ⚠️ **Und seit heute auch: nur, wo die Kante erklärt ist** ([D-376](90-decision-log.md)).
                    // *Hier stand `true`, fest. Der Eigentümer hat gefragt, ob die Umsetzung fehlt —
                    // **sie fehlte**: gemessen an `render with label` war **keine einzige** Auswahl
                    // gesperrt, auch nicht bei den drei geerbten Zeilen. Die Zeile wusste es (ihre
                    // Spalte «From» sagte `inherited`) und gab es nicht weiter.*
                    editable: $editable,
                    surroundings: new Surroundings(options: $options, mayBeNothing: $mayBeNothing, formId: $formId)
                )
            ),
            $renderer->name(),
            $subjectType
        );
    }

    /**
     * Which renderers a person may choose at this use site — the registry's second job (R14a).
     *
     * @return list<Renderer>
     */
    public function choicesFor(Relation $edge, ?Purpose $purpose = null): array
    {
        return $this->renderers->eligibleFor($edge, $this->typeAt($edge), $purpose);
    }

    /**
     * The same question asked of a node — *what may this type be drawn by?*
     *
     * ⚠️ **This is what a person actually needs, and the owner said so plainly:** a renderer is
     * **chosen**, never typed. *There are only certain ones for the current purpose — and how
     * would the user know the name?* So the eligible set is the control, and the name never has to
     * be known. It holds for converters and validators in the same way (D-358).
     *
     * @return list<Renderer>
     */
    public function choicesForNode(Node $node, ?Purpose $purpose = null): array
    {
        return $this->renderers->eligibleFor($node, $this->typeOfNode($node), $purpose);
    }

    /**
     * Whether a renderer of this name exists — **not** whether it is the obvious choice.
     *
     * ⚠️ **The eligible set is guidance, not a fence** (D-360). The owner drew the line: *you
     * cannot turn a text into a binary number — well, you can, it just makes no sense, unless you
     * have a special use case.* [R14](30-renderer.md#r12r17) puts the type declaration behind the
     * **offer**, and reading it as a prohibition forecloses the special case for everybody in
     * order to prevent a mistake nobody has made yet.
     */
    public function knowsRenderer(string $name): bool
    {
        return $this->renderers->knows($name);
    }


    /**
     * Welcher Renderer an diesem Knoten **jetzt** gilt.
     *
     * ⚠️ **Damit eine Auswahl zeigen kann, was gesetzt ist.** *Eine Auswahlliste ohne Vorauswahl ist
     * keine Auskunft, sondern eine Falle: sie zeigt immer den ersten Eintrag, und der nächste Klick
     * schreibt ihn — **auch wenn niemand ihn wollte**.*
     *
     * ⚠️ *Über {@see self::withModelValues()}, also einschliesslich dessen, was im Datensatz steht.
     * `valueOfType()` tut das an dieser Stelle noch nicht und liest nur die alte Tabelle — notiert, nicht
     * hier mitgeändert.*
     */
    public function rendererNameFor(Node $node, Purpose $purpose = Purpose::Edit): ?string
    {
        $settings = $this->withModelValues([], $node);

        return $this->renderers->chosenFor($node, $settings, $purpose, $this->typeOfNode($node))?->name();
    }

    /**
     * The simple type a node **is**, rather than the one an attribute points at.
     *
     * ⚠️ *It used to load every ancestor to read their names. Since the binding is by id
     * ([D-510](../../../docs/NewConcept/90-decision-log.md)) the ids off the node's own path are
     * enough, and the query is gone with the names.*
     */
    public function typeOfNode(Node $node): ?SimpleType
    {
        return $this->typeOf($node);
    }

    /**
     * The simple type behind each attribute's target, keyed by edge id.
     *
     * ⚠️ **A subtype of a type is still that type.** A node `Description` under `text` has no
     * `SimpleType` of its own name and stores exactly what a text stores — so the nearest ancestor
     * that **is** one answers, walked from the node upwards, because the closest statement wins
     * everywhere else in this model too.
     *
     * ⚠️ **Only inside `Data Types`.** A node called `text` sitting under `Model` is somebody's
     * own thing that happens to share a word, and reading a type off its name would be exactly
     * the special-casing-by-name the code standard forbids.
     *
     * ⚠️ *The ancestors are no longer read. They were loaded only to have names to compare, and
     * since the binding is by id ([D-510](../../../docs/NewConcept/90-decision-log.md)) the ids a
     * node's path already carries answer the same question with one query fewer.*
     *
     * @param  list<Relation> $edges
     * @return array<int, SimpleType|null>
     */
    private function typesOf(array $edges): array
    {
        $targets = $this->nodes->byIds(array_map(static fn (Relation $e): int => $e->toId, $edges));

        $types = [];
        $offen = [];

        foreach ($edges as $edge) {
            $target           = $targets[$edge->toId] ?? null;
            $types[$edge->id] = $target === null ? null : $this->typeOf($target);

            if ($target !== null && $types[$edge->id] === null) {
                $offen[$edge->id] = $target->id;
            }
        }

        // ⚠️ **[D-540](../../../docs/NewConcept/90-decision-log.md), und die Regel ist seine:** *ein
        // Feld ist eine **Auswahl**, wenn sein Ziel sichtbare, unmarkierte Kinder hat — sonst eine
        // Eingabe. **Der Zweig entscheidet das nicht.***
        //
        // ⚠️ **Erst der Typ, dann die Kinder, und die Reihenfolge trägt Gewicht.** *Gemessen: `Boolean`
        // hat ein Kind. Fragte man zuerst nach Kindern, würde jede `Boolean`-Einstellung zur Auswahl
        // zwischen einem einzigen Eintrag statt zum Schalter, den sie ist. **Ein Ziel mit eigenem Typ
        // ist fertig beantwortet; die Kinderfrage gilt nur für die, die keinen haben.***
        //
        // ⚠️ *In einer Abfrage für alle offenen Ziele zusammen (`CD-7`), nicht einer je Zeile.*
        if ($offen !== []) {
            $kinder = $this->nodes->visibleChildrenOf(array_values(array_unique($offen)));

            foreach ($offen as $edgeId => $targetId) {
                if (($kinder[$targetId] ?? []) !== []) {
                    $types[$edgeId] = SimpleType::NodeRef;
                    unset($offen[$edgeId]);
                }
            }
        }

        // ⚠️ **Ein Ziel im Ast `Settings` ohne eigene Felder ist ein Verweis — auch mit **null** Kindern.**
        //
        // ⚠️ *Der Eigentümer: «`validator` müsste eigentlich Select-Feld sein, `choice_renderer`,
        // ausgegraut, weil aktuell kein Validator existiert.» Und es braucht keine neue Regel:
        // [D-541](../../../docs/NewConcept/90-decision-log.md) sagt es wörtlich — «hat es eigene Felder,
        // braucht er einen Teil; **hat es nur Kinder, wählt man eines aus**». `Validator` hat weder, also
        // ist es der Wahlfall mit **keinem** Ausgang, und [R28](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete)
        // sagt, was dann gilt: **gesperrt und markiert.***
        //
        // ⚠️ **Die Kinderregel allein konnte das nicht sehen**: sie fragt «hat das Ziel Kinder», und null
        // Kinder heisst dort «keine Auswahl» — was als **Eingabefeld** endete. *Ein Textfeld für einen
        // Validator ist die Einladung, einen Namen hinzuschreiben, den niemand kennt.*
        //
        // ⚠️ **Nur im Ast `Settings`, und das ist Absicht.** *Ein Ziel im Ast `Model` ist ein Verweis auf
        // einen **Datensatz** und will den Zusammenfassungs-Renderer ([D-106](../../../docs/NewConcept/90-decision-log.md));
        // es hier zum Knotenverweis zu machen wäre ein Verweis auf die falsche Art Sache. Gemessen ändert
        // die Regel genau **eine** Kante: `validator`.*
        //
        // ⚠️ *In **einer** Abfrage für alle offenen Ziele (`CD-7`) — die Felder eines Ziels sind Kanten,
        // und `fieldEdgesOf()` nimmt eine Liste.*
        if ($offen !== [] && $this->relations !== null) {
            $mitFeldern = [];

            foreach ($this->relations->fieldEdgesOf(array_values(array_unique($offen))) as $eine) {
                $mitFeldern[$eine->fromId] = true;
            }

            foreach ($offen as $edgeId => $targetId) {
                $ziel = $targets[$targetId] ?? null;

                if ($ziel === null || isset($mitFeldern[$targetId])) {
                    continue;
                }

                if ($this->framework->branchOf($ziel) === Branch::Settings) {
                    $types[$edgeId] = SimpleType::NodeRef;
                }
            }
        }

        return $types;
    }

    /**
     * Woraus eine Auswahl besteht — je Kante die Kinder ihres Ziels.
     *
     * ⚠️ **Dieselbe Frage wie in {@see typesOf()}, mit derselben Antwort.** *[D-540](../../../docs/NewConcept/90-decision-log.md)
     * sagt beides in einem Satz: dass es eine Auswahl **ist** und woraus sie **besteht**, hängt an den
     * sichtbaren Kindern des Ziels. Deshalb ein Leser für beides.*
     *
     * ⚠️ **Auch für ein Ziel mit eigenem Typ, wenn es Kinder hat** — *und das ist nicht dasselbe wie
     * oben: `Boolean` bekommt seinen Schalter, weil der **Typ** zuerst gefragt wird; hier zählt nur,
     * ob es etwas zu wählen gibt. Die Zeile bekommt am Ende genau eines von beidem, und diese Methode
     * entscheidet es nicht.*
     *
     * ⚠️ *Nicht `choicesFor()` — den Namen trägt schon eine andere Frage: **welche Renderer** an einer
     * Verwendungsstelle wählbar sind (`R14a`). Zwei Fragen, ein Wort, und beim Lesen fällt es nicht
     * auf; deshalb heisst diese nach dem, was sie füllt ({@see Surroundings::$options}).*
     *
     * @param  list<Relation>                 $edges
     * @return array<int, array<int, string>> Kanten-Id => (Knoten-Id => Name)
     */
    /**
     * Wie tief der Abstieg geht.
     *
     * ⚠️ *`Kontakt → Adresse → Text` braucht zwei Stufen; die dritte ist Luft. **Eine Bremse muss es
     * geben**: ein Feld kann auf einen Knoten zeigen, dessen Feld wieder hierher zeigt, und dann liefe
     * der Gang endlos. Tiefer wird nicht abgebrochen, sondern der gewöhnliche Renderer genommen — dann
     * bleibt sichtbar, dass dort etwas ist.*
     */
    private const TIEFSTENS = 3;

    /**
     * Unter diesem Namen kommen die Werte eines **Teils** zurück — `taxmod_part[<Satz-Id>][<Kanten-Id>]`.
     *
     * ⚠️ **Die Satz-Id ist die Adresse, und das ist seine Lehre von heute Abend.** *Zweimal hat er mich
     * gestossen — «warum wieder path? verstehe ich nicht» und «arbeitest auf einmal mit Pfaden anstatt
     * mit den Ids, die wir haben» — und beim dritten Mal, als es dastand: «siehst du, Satz-Id».*
     *
     * ⚠️ *Sie ist eindeutig, wo eine Kette es nicht wäre: bei mehreren Teilen
     * ([D-548](../../../docs/NewConcept/90-decision-log.md)) tragen alle **dieselben** Kanten, und nur
     * der Satz unterscheidet sie.*
     */
    public const PART_FIELD = 'taxmod_part';

    /**
     * Alle Feldkanten, die der Abstieg brauchen wird — **eine Abfrage je Stufe**.
     *
     * ⚠️ **[D-159](../../../docs/NewConcept/90-decision-log.md), und der Satz gilt wörtlich:** *«the
     * descent has two inputs, both loaded before it starts … a descent that fetches per edge is N+1 by
     * construction».*
     *
     * ⚠️ *Deshalb wird je **Stufe** geladen und nicht je Feld: `fieldEdgesOf()` nimmt eine Liste von
     * Besitzern, also kostet eine Ebene eine Abfrage, gleich wie breit sie ist. Drei Stufen sind drei
     * Abfragen.*
     *
     * ```mermaid
     * flowchart LR
     *   A["Kanten der Stufe 0"] --> B["ihre Ziele"]
     *   B --> C["eine Abfrage: Feldkanten aller Ziele"]
     *   C --> D["deren Ziele … bis TIEFSTENS"]
     * ```
     *
     * @param  list<Relation>            $edges
     * @return array<int, list<Relation>> Knoten-Id => seine Feldkanten
     */
    private function subgraph(array $edges, int $tiefstens): array
    {
        if ($this->relations === null) {
            return [];
        }

        $unterbau = [];
        $offen    = array_values(array_unique(array_map(static fn (Relation $e): int => $e->toId, $edges)));

        for ($stufe = 0; $stufe < $tiefstens && $offen !== []; $stufe++) {
            // ⚠️ *Nur Ziele, die überhaupt eigene Felder haben könnten — ein `Text` hat keine, und ihn
            // zu fragen wäre eine Abfrage für eine Antwort, die schon feststeht.*
            //
            // ⚠️ **Und die Ausnahmeliste sagt, wer **keine** hat, nicht wer welche hat.** *Mein erster
            // Entwurf fragte nur `Compositions` — und `DisplayOption` liegt seit
            // [D-541](../../../docs/NewConcept/90-decision-log.md) unter **`Settings`**. **Damit blieb
            // genau die Zeile leer, um die es ging**: der Eigentümer sah eine Wertspalte ohne Wert.
            // Eine Liste der erlaubten Äste ist eine Liste, die beim nächsten Zweig wieder falsch ist.*
            $knoten = $this->nodes->byIds($offen);
            $fragen = [];

            foreach ($offen as $id) {
                $ziel = $knoten[$id] ?? null;

                if ($ziel === null || isset($unterbau[$id])) {
                    continue;
                }

                $ast = $this->framework->branchOf($ziel);

                if ($ast === Branch::DataTypes || $ast === Branch::Constants || $ast === null) {
                    continue;
                }

                $fragen[] = $id;
            }

            if ($fragen === []) {
                break;
            }

            $weiter = [];

            foreach ($this->relations->fieldEdgesOf($fragen) as $kante) {
                $unterbau[$kante->fromId][] = $kante;
                $weiter[]                   = $kante->toId;
            }

            // ⚠️ *Ein Besitzer ohne Kanten bekommt einen leeren Eintrag — sonst würde die nächste Runde
            // ihn wieder fragen, und das wäre die Abfrage je Feld, die vermieden werden soll.*
            foreach ($fragen as $id) {
                $unterbau[$id] ??= [];
            }

            $offen = array_values(array_unique($weiter));
        }

        return $unterbau;
    }

    /**
     * Der Teil unter einem zusammengesetzten Feld, gezeichnet wie ein Knoten.
     *
     * ⚠️ **Seine Diagnose:** *«heisst wohl Renderkette ist unterbrochen»* — *und «Form-Render sollte ja
     * die Knoten durchgehen». Durchgehen tut der **Abstieg**; der Behälter legt aus, was er bekommt
     * ([D-366](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ *Gemessen an `Kontakt`: `Address` bekam Typ «keiner» und `plain`, während `Adresse` fünf eigene
     * Felder trägt. **Vier von fünf waren nie zu sehen.***
     *
     * @return array{renderer: string, result: RenderResult}|null `null`, wenn hier kein Teil liegt.
     */
    private function partBelow(
        Relation $edge,
        ?SimpleType $type,
        Purpose $purpose,
        string $fieldPrefix,
        string $locale,
        Level $level,
        bool $editable,
        string $formId,
        int $tiefe,
        array $unterbau,
        array $values,
        array $gesehen = [],
        array $teile = [],
        int $forNode = 0,
    ): ?array {
        // ⚠️ *Ein Ziel mit eigenem Typ ist fertig beantwortet — `int` hat keine Felder, und der Abstieg
        // hat dort nichts zu suchen.*
        // WICHTIG: Ein Teil schlaegt den Typ. Diese Zeile hiess "$type !== null" und stammt aus der
        // Zeit, in der ein Knotenverweis nie Felder hatte -- die Kante "render" traegt den Typ
        // NodeRef, und damit endete der Abstieg genau an der Auswahl. Seit D-583 ist ein gewaehlter
        // Renderer ein Datensatz mit eigenen Feldern (D-585: converter, geerbt). Ein Teil entsteht
        // nur, wo das Modell sagt, dass das Ziel einen eigenen Satz braucht -- also ist sein
        // Vorhandensein der bessere Beleg als der Typ.
        if (($type !== null && $teile === []) || $tiefe >= self::TIEFSTENS) {
            return null;
        }

        // ⚠️ **Ein Ziel, in dem der Lauf schon war, wird nicht wieder aufgeklappt.** *`DisplayOption`
        // erbt `Display Option` mit **sich selbst** als Ziel ([OQ-133](../../../docs/NewConcept/91-open-questions.md)),
        // und ohne diese Zeile stand `render` in jedem seiner Datensätze doppelt.*
        if (isset($gesehen[$edge->toId])) {
            return null;
        }

        $innen = $unterbau[$edge->toId] ?? [];

        // ⚠️ **Was aus einem Teil gezeichnet wird, hängt daran, was der Teil ist.**
        //
        // ⚠️ *Ist die tragende Kante ein **Feld**, gehören die Einstellungen des Teils nicht in die
        // Maske — dieselbe Trennung, die die Vorschau macht: niemand füllt beim Erfassen einer Adresse
        // deren Renderer aus.*
        //
        // ⚠️ *Ist sie eine **Einstellung**, sind ihre Einstellungen der ganze Inhalt. **Gemessen, als
        // `render` und `converter` auf sein Wort zu Einstellungskanten wurden — «warum sehe ich hier
        // wieder die Einstellungen als Fields, nur damit du rendern kannst, das ist falsch»: die Zelle
        // von `Display Option` wurde im selben Zug leer, weil dieser Filter sie wegnahm.***
        $nurEchte = ! $edge->kind->isSetting();

        $innen = array_values(array_filter(
            $innen,
            static fn (Relation $e): bool => ! $e->hide && ($nurEchte === false || ! $e->kind->isSetting())
        ));

        if ($innen === []) {
            return null;
        }

        $ziel = $this->nodes->find($edge->toId);

        if ($ziel === null) {
            return null;
        }

        // ⚠️ *Der Name des Feldes trägt den Weg: `v[<aussen>][<innen>]`. Damit ist die Adresse im
        // Formular dieselbe Kette von Kanten-Ids, die auch der Pfad im Datensatz ist.*
        // ⚠️ **Ein Teil wird über seine Satz-Id angesprochen, nicht über eine Kette von Kanten.**
        //
        // ⚠️ *Hier stand `v[<aussen>][<innen>]` — die Kette. Der Eigentümer hat mich zweimal daran
        // gestossen: «warum wieder path? verstehe ich nicht» und «arbeitest auf einmal mit Pfaden
        // anstatt mit den Ids, die wir haben». **Die Daten geben ihm recht:** die Werte eines Teils
        // liegen in **seinem eigenen** Knoten-Datensatz, dort über seine eigenen Kanten geschlüsselt;
        // verbunden wird über den Verweis. Also ist die Satz-Id die Adresse — und sie ist eindeutig,
        // auch bei mehreren Teilen ([D-548](../../../docs/NewConcept/90-decision-log.md)).*
        //
        // ⚠️ **Je Teil eine Zeile.** *Ohne Teil eine leere Zeile **ohne Namen**, also ohne Adresse: sie
        // kann nichts abschicken, und das ist richtig — es gibt nichts, worin sie schreiben könnte.*
        $zeilen = [];

        foreach ($teile === [] ? [null] : $teile as $teil) {
            // WICHTIG: Die Felder des *gewaehlten* Knotens, nicht die des Kantenziels (D-584).
            // Die Kante zeigt auf den Basisknoten «Renderer»; im Datensatz steht «compact», und
            // gezeichnet gehoeren dessen Felder. Ohne das endet der Abstieg an der Auswahl.
            $dieseFelder = $teil === null ? $innen : $this->fieldsOfChosen($teil, $edge->toId, $innen);

            $zeilen[] = $this->fieldsFor(
                $dieseFelder,
                $teil === null ? [] : $teil['werte'],
                $purpose,
                $teil === null || $fieldPrefix === '' ? '' : self::PART_FIELD . '[' . $teil['id'] . ']',
                $locale,
                $level,
                $editable,
                $formId,
                $tiefe + 1,
                $unterbau,
                [...$gesehen, $edge->toId => true],
                // WICHTIG: Hier stand eine leere Liste, und daran endete der Abstieg. Die Teile
                // *dieses* Teils sind der gewaehlte Renderer und was unter ihm haengt (D-583).
                $teil['teile'] ?? [],
                $forNode
            );
        }

        $teile = $zeilen[0] ?? [];

        // ⚠️ **Eine Einstellung wird immer als Tabelle gezeichnet, auf sein Wort:** *«bei Einstellung
        // kann ich damit rechnen — ich möchte, dass sie immer mit table_render gerendert wird, egal ob
        // ein Feld oder mehrere, egal ob Multiplizität `0..1` oder `...*`»*
        // ([D-546](../../../docs/NewConcept/90-decision-log.md)).
        //
        // ⚠️ **Das ist die Auflösung seines Dilemmas.** *Eine Einstellung muss gezeichnet werden, und
        // das, was sagt **wie**, ist selbst eine Einstellung — erben Einstellungen Einstellungen,
        // entsteht die Selbstbezüglichkeit, die [D-545](../../../docs/NewConcept/90-decision-log.md)
        // beseitigt hat; erben sie keine, sagt niemand, wie sie zu zeichnen sind. **Also wird es nicht
        // gesagt, sondern abgeleitet:** der Behälter ist die Tabelle, und was in den Zellen steht,
        // entscheidet seine Regel [D-540](../../../docs/NewConcept/90-decision-log.md) — Auswahl oder
        // Eingabe. **Nichts stellt seine eigene Bearbeitungsfläche ein.***
        //
        // ⚠️ *Kein `Level`-Vorbehalt: eine Einstellung wird nur im Modell bearbeitet, nie im Frontend
        // gezeichnet — eine Bedingung darauf hätte einen Fall unterschieden, den es nicht gibt.*
        $behaelter = $edge->kind->isSetting()
            ? $this->renderers->byName(TableRenderer::NAME)
            : $this->containerFor($ziel, $purpose);

        return [
            'renderer' => $behaelter->name(),
            'result'   => $behaelter->render(
                $ziel,
                new RenderContext(
                    purpose: $purpose,
                    value: TypedValue::nothing(),
                    locale: $locale,
                    level: $level,
                    editable: $editable,
                    // ⚠️ **`records` ist der Platz, den der Table-Renderer für mehrere Zeilen hat, und
                    // er stand leer** — *der Grund, warum eine Einstellung mit `1..*` trotzdem nur eine
                    // Zeile zeigte. `parts` bleibt daneben für die Behälter, die nur einen Satz kennen.*
                    surroundings: new Surroundings(parts: $teile, records: $zeilen, formId: $formId),
                )
            ),
        ];
    }

    /**
     * Welche Felder ein Teil zeigt -- die seines eigenen Knotens.
     *
     * WICHTIG: Mit Vererbung, sonst fehlt genau das, was der Basisknoten beisteuert -- seit D-585
     * ist das der «converter», den jeder Renderer erbt.
     *
     * WICHTIG: Faellt auf die Felder des Kantenziels zurueck, wenn der Knoten des Teils derselbe ist
     * oder nichts zu holen war. Ohne den Rueckfall waere eine Zeile leer, sobald etwas fehlt -- und
     * eine leere Maske sieht aus wie «nichts eingestellt» statt wie ein Fehler.
     *
     * @param array{nodeId?:int} $teil
     * @param list<Relation> $innen
     * @return list<Relation>
     */
    private function fieldsOfChosen(array $teil, int $targetId, array $innen): array
    {
        $gewaehlt = $teil['nodeId'] ?? 0;

        if ($gewaehlt === 0 || $gewaehlt === $targetId || $this->relations === null) {
            return $innen;
        }

        $knoten = $this->nodes->find($gewaehlt);

        if ($knoten === null) {
            return $innen;
        }

        $eigene = array_values(array_filter(
            $this->relations->fieldEdgesOf($this->framework->inheritanceOwnersOf($knoten)),
            static fn (Relation $e): bool => ! $e->hide
        ));

        return $eigene === [] ? $innen : $eigene;
    }

    /**
     * Der Auswahlkasten und die Felder des Gewaehlten, nebeneinander.
     *
     * WICHTIG: Nur bei einem Knotenverweis. Ein zusammengesetztes Feld -- eine Adresse in einem
     * Kunden -- hat nichts zu waehlen; dort waere ein Kasten davor sinnlos.
     */
    private function chosenAndItsFields(
        Relation $edge,
        ?SimpleType $type,
        Renderer $renderer,
        RenderContext $context,
        RenderResult $tiefer
    ): RenderResult {
        if ($type !== SimpleType::NodeRef) {
            return $tiefer;
        }

        $wahl = $renderer->render($edge, $context);

        return new RenderResult(
            $wahl->markup . $tiefer->markup,
            [...$wahl->usedEdges, ...$tiefer->usedEdges],
            $tiefer->condition
        );
    }

    /**
     * Ein Klapper je Zeile mit Kindern, der im Browser klappt statt die Seite zu laden.
     *
     * @param list<array{node: Node, hasChildren: bool}> $walked
     * @return array<int, string>
     */
    private function foldsInPlace(array $walked): array
    {
        $aus = [];

        foreach ($walked as $row) {
            if ($row['hasChildren']) {
                $aus[$row['node']->id] = '#';
            }
        }

        return $aus;
    }

    /**
     * Der Baumlaeufer, aus den Behaeltern gebaut, die es ohnehin hat.
     *
     * WICHTIG: Als Parameter waere er an elf Stellen nachzutragen gewesen, und einer davon haette
     * ihn vergessen -- package7-check hat genau das sofort gemeldet. Tree ist ein reiner
     * Kerndienst ueber zwei Behaeltern, also kann er hier entstehen statt hereingereicht zu werden.
     */
    private function walker(): ?Tree
    {
        return $this->relations === null ? null : new Tree($this->nodes, $this->relations);
    }

    /**
     * Ein Knoten-Chooser aus seinen drei Angaben -- Wurzel, offener Ast, Vorauswahl.
     *
     * ⚠️ **Das ist sein Konzept, und es gehoert hierher und nicht in die Maske.** *Er hat es
     * benannt: «wir haben ein Konzept fuer den Knoten-Chooser: Root-Knoten, optional Ast der
     * [aufgeklappt] ist, optional Knoten der vorselektiert ist.» **Ich hatte es im Feldformular
     * ausgerechnet** — damit haette der naechste Aufrufer es noch einmal ausgerechnet, und die
     * zweite Rechnung waere irgendwann anders ausgefallen.*
     *
     * ⚠️ *Der offene Ast schliesst seinen Weg mit ein: liegt er zwei Ebenen tief, muessen seine
     * Vorfahren offen sein, sonst waere «offen» eine Angabe ueber etwas, das niemand sieht.*
     *
     * ⚠️ **Ungekuerzt geholt.** *Der Dialog klappt nur im Browser -- ein Neuaufbau haelt ihn nicht
     * offen, also gibt es keinen zweiten Weg zum Server, der einen erst jetzt gebrauchten Ast
     * nachliefern koennte. Jede Zeile muss also schon im Dokument stehen, auch die eines
     * geschlossenen Astes; nur ihre Anzeige startet zu.* **Gemessen:** «Compositions» liess sich im
     * Dialog nicht aufklappen, weil die alte Fassung `Tree::rowsUnder()` mit dem geschlossenen Ast
     * fuetterte und der genau dort aufhoert zu sammeln (`Tree::collect()`).
     *
     * @param list<int> $unpickable
     */
    public function nodeChooser(
        Node $root,
        string $fieldName,
        ?Node $expanded = null,
        ?int $preselected = null,
        array $skip = [],
        array $unpickable = [],
        ?string $chosenName = null,
        string $nothingToChoose = '',
        string $chooser = DialogChooserRenderer::NAME,
        string $locale = '',
        Level $level = Level::Admin,
        string $trigger = '',
        string $confirm = '',
    ): RenderResult {
        $laeufer = $this->walker();

        if ($laeufer === null) {
            return RenderResult::of('');
        }

        $walked = $this->closedApartFrom($laeufer->rowsUnder($root, $skip), $expanded);

        return $this->chooserFor(
            $walked,
            $fieldName,
            $preselected,
            $unpickable,
            $chosenName,
            $nothingToChoose,
            $chooser,
            $locale,
            $level,
            $trigger,
            $confirm
        );
    }

    /**
     * Dieselben Zeilen, nur die Klapp-Markierung neu gesetzt: offen ist der Ast und sein Weg,
     * zu ist der Rest.
     *
     * ⚠️ *Der offene Ast schliesst seinen Weg mit ein: liegt er zwei Ebenen tief, muessen seine
     * Vorfahren offen sein, sonst waere «offen» eine Angabe ueber etwas, das niemand sieht.*
     *
     * @param  list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool, hidden: bool}> $walked
     * @return list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool, hidden: bool}>
     */
    private function closedApartFrom(array $walked, ?Node $expanded): array
    {
        // WICHTIG: Ohne genannten Ast ist ALLES zu, nicht alles offen. Auf sein Wort: "der
        // gewaehlte Ast im Dialog entscheidet nur, welcher Ast expanded ist, die anderen sollten
        // collapsed sein, nicht mehr und nicht weniger". Vorher kamen die Zeilen unveraendert
        // zurueck -- gemessen 125 Zeilen mit dem ganzen Modellast darin.
        $offen = [];

        if ($expanded !== null) {
            $offen = [$expanded->id => true];

            foreach ($expanded->ancestorIds() as $id) {
                $offen[$id] = true;
            }
        }

        foreach ($walked as &$row) {
            $row['collapsed'] = ! isset($offen[$row['node']->id]);
        }

        return $walked;
    }

    private function optionsFor(array $edges): array
    {
        $ziele = [];

        foreach ($edges as $edge) {
            $ziele[$edge->id] = $edge->toId;
        }

        if ($ziele === []) {
            return [];
        }

        $unter = $this->offeredUnder(array_values(array_unique($ziele)));
        $wahl  = [];

        foreach ($ziele as $edgeId => $targetId) {
            $angebot = $unter[$targetId] ?? [];

            if ($angebot !== []) {
                $wahl[$edgeId] = $angebot;
            }
        }

        return $wahl;
    }

    /**
     * Woraus unter diesen Knoten gewählt werden kann — **durch markierte Knoten hindurch**.
     *
     * ⚠️ **[D-540](../../../docs/NewConcept/90-decision-log.md) sagt «sichtbare, unmarkierte Kinder»,
     * und «unmarkiert» ist dort ausdrücklich die Bedingung:** *«nur weil sie markiert waren, war
     * `Integer` keine Auswahl aus seinen eigenen Einstellungen».*
     *
     * ⚠️ **Was die Entscheidung noch nicht kannte, ist der Zwischenknoten.** *Der Eigentümer hat am
     * 2026-08-30 «render label roles» und «render with label» zu einem zusammengelegt und die Renderer
     * darunter geschoben — danach sind `form`, `table`, `compact` **Enkel** von `Renderer`. Auf die
     * Kinderreihe allein war «render with label» wählbar und `table` nicht; er: **«table, form, compact
     * muss wählbar bleiben, warum auch nicht?»***
     *
     * ⚠️ **Die Erweiterung folgt aus den eigenen Worten der Regel:** *markiert heisst «kein Wert, nur
     * Struktur» — also ist ein markierter Knoten **keine** Möglichkeit und die Auswahl sieht durch ihn
     * hindurch. Ein unmarkierter ist eine Möglichkeit und wird nicht weiter aufgeklappt.*
     *
     * ⚠️ **Die **eigene** Sorte entscheidet, nicht die aufgelöste.** *`resolvedKinds()` erbt nach unten:
     * wäre sie gefragt, gälten `form`, `table`, `compact` als markiert, weil ihr Elternteil es ist —
     * und die Auswahl wäre leer statt vollständig.*
     *
     * ⚠️ *Eine Abfrage je Stufe, drei Stufen (`CD-7`).*
     *
     * @param  list<int>                       $parentIds
     * @return array<int, array<int, string>>  Eltern-Id => (Knoten-Id => Name)
     */
    /**
     * Von den angebotenen Renderern die, die diesen Knoten zeichnen können.
     *
     * ⚠️ **Die Antwort kommt aus der Registratur und wird hier nicht nachgebaut** (`R14a`): *sie kennt,
     * welcher Renderer welchen Typ anfasst, und der Eigentümer hat den Fall genannt — «Integer sieht
     * jetzt alle Renderer, wobei nur int-Renderer ok wären», dazu «allgemeiner `field` wäre auch noch
     * ok». **Gemessen sagt `eligibleFor()` für `Integer` genau `field, spinner, slider`.***
     *
     * ⚠️ **Das Gespeicherte bleibt stehen, auch wenn es nicht mehr angeboten würde**
     * ([D-360](../../../docs/NewConcept/90-decision-log.md)): *die zulässige Menge ist ein Angebot und
     * kein Zaun. Fiele der gesetzte Wert aus der Liste, zeigte die Auswahl ihn nicht mehr — und das
     * nächste Speichern hätte ihn stillschweigend ersetzt.*
     *
     * @param  array<int, string> $angebot Knoten-Id => Name
     * @return array<int, string>
     */
    private function onlyUsableRenderers(array $angebot, int $forNode, ?TypedValue $gesetzt): array
    {
        $knoten = $this->nodes->find($forNode);

        if ($knoten === null) {
            return $angebot;
        }

        $erlaubt = [];

        foreach ($this->renderers->eligibleFor($knoten, $this->typeOfNode($knoten), Purpose::Edit) as $einer) {
            $erlaubt[$einer->name()] = true;
        }

        if ($erlaubt === []) {
            return $angebot;
        }

        return array_filter(
            $angebot,
            static fn (string $name, int $id): bool => isset($erlaubt[$name]) || $gesetzt?->reference === $id,
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function offeredUnder(array $parentIds): array
    {
        $angebot = array_fill_keys($parentIds, []);
        $offen   = [];

        foreach ($parentIds as $id) {
            $offen[$id] = [$id];
        }

        for ($stufe = 0; $stufe < self::TIEFSTENS && $offen !== []; $stufe++) {
            $alle = [];

            foreach ($offen as $ids) {
                foreach ($ids as $id) {
                    $alle[$id] = true;
                }
            }

            $kinder = $this->nodes->visibleChildrenOf(array_keys($alle));
            $weiter = [];

            foreach ($offen as $wurzel => $ids) {
                foreach ($ids as $id) {
                    foreach ($kinder[$id] ?? [] as $kind) {
                        if ($kind->kind === NodeKind::Setting) {
                            $weiter[$wurzel][] = $kind->id;

                            continue;
                        }

                        $angebot[$wurzel][$kind->id] = $kind->name;
                    }
                }
            }

            $offen = $weiter;
        }

        return $angebot;
    }

    private function typeOf(Node $target): ?SimpleType
    {
        $branch = $this->framework->branchOf($target);

        // ⚠️ **A constant is drawn as a reference, and [D-232](90-decision-log.md) is where that
        // comes from** — the branch decides where a value lives, and for `Constants` the value
        // **is** a reference to a node. So the type to draw is `node_ref` whatever the constant
        // happens to be called; nothing is read off its name.
        //
        // ⚠️ A `Model` target is a reference to a **record**, which has no simple type of its own
        // and no renderer either — it wants the summary renderer (D-106) and stays undrawn until
        // then, honestly rather than as a reference to the wrong kind of thing.
        if ($branch === Branch::Constants) {
            return SimpleType::NodeRef;
        }

        if ($branch !== Branch::DataTypes) {
            return null;
        }

        // ⚠️ **By the id the seed wrote down, never by the node's name** ([D-510](../../../docs/NewConcept/90-decision-log.md)).
        // *A name is a beschriftung and may change; [D-022](../../../docs/NewConcept/90-decision-log.md)
        // says node names are deliberately not unique, so a name could never have been a key. The
        // Notnagel — and the writing-back of the id — sits in {@see TypeNodes}, in one place.*
        $own = $this->typeNodes->typeOf($target->id);

        if ($own !== null) {
            return $own;
        }

        foreach (array_reverse($target->ancestorIds()) as $id) {
            $found = $this->typeNodes->typeOf($id);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
