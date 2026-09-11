<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Converter\Converter;
use Taxmod\Core\Converter\ConverterRegistry;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Identity;
use Taxmod\Core\Renderer\Renderable;
use Taxmod\Core\Model\EdgeColumn;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\ReferenceSpace;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\FieldRowRenderer;
use Taxmod\Core\Renderer\Choice;
use Taxmod\Core\Renderer\ChoiceRenderer;
use Taxmod\Core\Renderer\ToggleRenderer;
use Taxmod\Core\Renderer\HeadRenderer;
use Taxmod\Core\Renderer\ChooserCellRenderer;
use Taxmod\Core\Renderer\DialogChooserRenderer;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;
use Taxmod\Core\Renderer\DrawnRow;
use Taxmod\Core\Renderer\FormRenderer;
use Taxmod\Core\Renderer\LabelSlot;
use Taxmod\Core\Renderer\LabelsRenderer;
use Taxmod\Core\Renderer\PageRenderer;
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
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RelationRepository;
use Taxmod\Core\Repository\TypeNodes;
use Taxmod\Core\Model\Type\SpecialisedTypes;
use Taxmod\Core\Port\Presets;
use Taxmod\Core\Port\Users;

/**
 * The descent, for the attributes of one node.
 *
 * ```mermaid
 * flowchart LR
 *   E["attribute relation"] --> T["its target"] --> Y["the simple type<br/>own name, else an ancestor's"]
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
final class Rendering implements Presets
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
        /**
         * Die Auflösung Kante → Knoten → Vertrag aus `settings_value` — Schritt 4 des Bauplans
         * ([D-712](90-decision-log.md)).
         *
         * ⚠️ *Ist er da, liest der Zeichner **nur** noch ihn: {@see withModelValues()},
         * {@see withRendererValues()} und {@see zutreffendeKanten()} fragen keine Einstellungskante
         * mehr. Ist er nicht da, gilt der alte Weg über {@see ModelValues} — die Übergangszeit,
         * die der Bauplan bewusst nennt, bis Schritt 7 die Kanten fallen lässt.*
         */
        private readonly ?SettingsResolver $resolver = null,

        /**
         * Der Rand, soweit er über **Benutzer** Auskunft gibt — der Name und der Angemeldete.
         *
         * ⚠️ **Gereicht, nie geholt** (`CD-1`, [D-649](../../../docs/NewConcept/90-decision-log.md)):
         * *«wer ist gerade angemeldet» ist eine Frage an WordPress. Der Kern **nimmt eine Vorbelegung
         * entgegen, er beschafft sie nicht.** Dieselbe Bauart wie `$labels`: ohne die Naht antwortet
         * der Zeichenlauf «kein Name» und die Id zeigt sich als ungelöst — nicht als Zahl.*
         */
        private readonly ?Users $users = null,
    ) {
    }

    /**
     * Was in einem Feld stehen soll, bevor jemand etwas eingetragen hat — {@see Presets}.
     *
     * ⚠️ **Hier, weil hier schon aufgelöst wird, was die Frage braucht:** *welcher Typ hinter der
     * Kante steht und was `read_only` an dieser Verwendungsstelle sagt. **Der Schreibweg fragt und
     * rechnet nicht selbst** — eine zweite Auflösung wäre die Doppelung, die auseinanderläuft.*
     *
     * ⚠️ **Und die Entscheidung selbst fällt hier nicht.** *Es gibt keine Abfrage «wenn der Typ
     * `user_ref` ist» ([D-650](../../../docs/NewConcept/90-decision-log.md): «die Regel wohnt in
     * `UserRefType`»); gefragt wird **die Typklasse**, und alle übrigen antworten `null`.*
     *
     * @param  list<Relation>         $relations
     * @return array<int, TypedValue>
     */
    public function presetsFor(array $relations): array
    {
        if ($relations === [] || $this->users === null) {
            return [];
        }

        $signedIn = $this->users->signedIn();
        $types    = $this->typesOf($relations);
        $resolved = $this->settingsForUseSites($relations);
        $presets  = [];

        foreach ($relations as $relation) {
            $type = $types[$relation->id] ?? null;

            if ($type === null) {
                continue;
            }

            // ⚠️ *Der Schlüssel und seine eigene Vorgabe, nie ein `?? false` daneben — dieselbe Zeile
            // wie in {@see \Taxmod\Core\Renderer\RenderContext::mayEdit()}
            // ([D-401](../../../docs/NewConcept/90-decision-log.md)).*
            $readOnly = ($resolved[$relation->id][EdgeColumn::READ_ONLY] ?? null)?->value->asBool() ?? false;

            $preset = SpecialisedTypes::for($type)->presetFor($readOnly, $signedIn);

            if ($preset !== null) {
                $presets[$relation->id] = $preset;
            }
        }

        return $presets;
    }

    /**
     * Die Namen der Benutzer, auf die diese Werte zeigen — in **einem** Zug, wie bei den Verweisen.
     *
     * ⚠️ **Der Kern deutet die Id nicht** ([D-171](../../../docs/NewConcept/90-decision-log.md)): *er
     * beschreibt «Benutzerverweis, Wert 17» und der Rand macht den Namen daraus
     * ([D-649](../../../docs/NewConcept/90-decision-log.md)). **Er ist der Einzige, der WordPress
     * fragen darf** (`CD-1`).*
     *
     * ⚠️ *Eine Abfrage für alle Zeilen und nicht eine je Zeile — `CD-7`, genau wie
     * {@see self::namesOfReferences()}.*
     *
     * @param  list<Relation>            $relations
     * @param  array<int, TypedValue>    $values
     * @param  array<int, SimpleType|null> $types
     * @return array<int, string>        Nach Kanten-Id.
     */
    private function namesOfUsers(array $relations, array $values, array $types): array
    {
        if ($this->users === null) {
            return [];
        }

        $wanted = [];

        foreach ($relations as $relation) {
            if (($types[$relation->id] ?? null) !== SimpleType::UserRef) {
                continue;
            }

            $value = $values[$relation->id] ?? null;

            if ($value === null || $value->isNothing()) {
                continue;
            }

            $wanted[$value->describe()][] = $relation->id;
        }

        if ($wanted === []) {
            return [];
        }

        $names = [];

        // ⚠️ **`array_map('strval', …)`, und das ist keine Formsache — es war ein Fehler.** *PHP macht
        // aus dem Schlüssel `'17'` beim Ablegen wortlos die **Zahl** 17. Der Rand bekam also Zahlen,
        // wo die Naht Zeichenketten verspricht ([D-171](../../../docs/NewConcept/90-decision-log.md)),
        // und `ctype_digit(17)` ist `false` — **jeder Name fiel heraus und jeder Benutzer zeichnete
        // sich als ungelöst.** Am Rand gemessen, nachdem der Kerntest grün war: seine Doppelgängerin
        // verglich Schlüssel mit Schlüsseln und sah denselben Wandel auf beiden Seiten.*
        foreach ($this->users->namesFor(array_map('strval', array_keys($wanted))) as $id => $name) {
            foreach ($wanted[$id] ?? [] as $relationId) {
                $names[$relationId] = $name;
            }
        }

        return $names;
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
     * drawing side: settings and types for every relation at once, then a loop with no query in it.*
     *
     * ⚠️ **Only an invertible converter is asked** ([D-076](../../../docs/NewConcept/90-decision-log.md)).
     * *A lossy one is display only, so what a person typed into it is read by the type — which is the
     * honest reading: the characters on screen were never the whole value.*
     *
     * @param  list<Relation>        $relations      The attributes the form drew.
     * @param  array<int, string>    $characters What was typed, by relation id. Empty strings belong to
     *                                           the caller: an empty field means *unanswered* and the
     *                                           row goes, which is not this method's decision.
     * @return array<int, TypedValue>            By relation id, for every relation that had a type.
     */
    public function valuesFrom(array $relations, array $characters): array
    {
        if ($relations === []) {
            return [];
        }

        $types    = $this->typesOf($relations);
        $resolved = $this->settingsForUseSites($relations);
        $values   = [];

        foreach ($relations as $relation) {
            $typed = $characters[$relation->id] ?? null;
            $type  = $types[$relation->id] ?? null;

            if ($typed === null || $type === null) {
                continue;
            }

            $converter = $this->readingConverter($resolved[$relation->id] ?? [], $type);

            // ⚠️ *`NotAValueOfThatType` travels on either way — from the converter or from the type.
            // Both refuse rather than coerce, and the boundary turns it into a `WP_Error` (`CD-10`).*
            $values[$relation->id] = $converter === null
                ? $type->valueFrom($typed)
                : $converter->written($typed, $type);
        }

        return $values;
    }

    /**
     * Was jemand an einem **Knoten** geschrieben hat, als Wert zum Speichern.
     *
     * ⚠️ **Dieselbe Naht wie {@see self::valuesFrom()}, nur ohne Kante** — *der Gegenstück-Weg zu
     * {@see self::valueOfType()}, das den Knoten als Wert **zeichnet**. Zeichnen und Zurücklesen
     * gehören zusammen ([R36](../../../docs/NewConcept/30-renderer.md)): **ein Feld, das `XII`
     * zeichnet und `XII` als Text speichert, hat seinen Wert verloren.***
     *
     * ⚠️ *Die Einstellungen kommen aus {@see self::withRendererValues()} und nicht aus der Kette
     * des Knotens allein — sonst fände `converter` nicht statt, wo er am Satz des gewählten
     * Renderers steht. Dieselbe Quelle, aus der die Vorschau zeichnet; **zwei Quellen wären zwei
     * Antworten auf «welcher Wandler gilt hier».***
     *
     * ⚠️ *`null`, wo der Knoten kein einfacher Typ ist — dann hat der Aufrufer nichts zu speichern
     * und soll es sagen, statt einen Text abzulegen.*
     */
    public function valueOfNodeFrom(Node $node, string $characters): ?TypedValue
    {
        $type = $this->typeOf($node, $this->nodes->byIds($node->ancestorIds()));

        if ($type === null) {
            return null;
        }

        $converter = $this->readingConverter($this->withRendererValues($node), $type);

        return $converter === null
            ? $type->valueFrom($characters)
            : $converter->written($characters, $type);
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

        $name = ($settings['converter'] ?? null)?->value->text;

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

        $chosen = $settings['converter'] ?? null;
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
     * **which field do we hang the renderer on?** The answer is the referring relation, and this is the
     * key it carries.*
     *
     * ⚠️ *[D-264](90-decision-log.md) wants a **pattern** here eventually — roles and fixed
     * characters, `symbol – form` — and a single role is its first step rather than a rival to it.*
     */
    public const LABEL_ROLE = 'label_role';

    /** Der Schlüssel, unter dem die Art einer eigenen Feldzeile als Auswahlfeld mitreist (TASK-066). */
    public const KIND_KEY = 'kind';

    /** Der Haken «ich bestätige», mit dem ein Feld mit Benutzersätzen eine Einstellung werden darf (D-699). */
    public const KIND_CONFIRM_KEY = 'kind_confirm';

    /**
     * What the referenced nodes are called, for every reference in this batch, in one query
     * **per role** that anybody asked for.
     *
     * ⚠️ **Resolved before the descent begins** (D-159). A reference is drawn as its target's
     * label (D-105) and a renderer fetches nothing, so this is the only place the labels can come
     * from — and it is one query for the whole form rather than one per row, which is what `CD-7`
     * forbids and what made the legacy parts list slow.
     *
     * ⚠️ **Keyed by *relation*, not by target, because the role belongs to the relation.** The same node
     * reached from two attributes may want `symbol` in one and `form` in the other — *a parts list
     * showing `k` and a heading showing `kilo`* — so a map keyed by target could only hold one of
     * them and would silently give the second row the first one's answer.
     *
     * ⚠️ **Grouped by role rather than asked per relation.** `CD-7` bounds this at *one query per
     * distinct role*, which is at most a handful whatever the size of the form; asking per relation
     * would be the loop again, one level down and harder to see.
     *
     * @param  list<Relation>                                          $relations
     * @param  array<int, TypedValue>                                  $values
     * @param  array<int, array<string, \Taxmod\Core\Model\ResolvedSetting>> $resolved
     * @return array<int, string>                                      Keyed by the **relation's** id.
     */
    private function namesOfReferences(array $relations, array $values, array $resolved, string $locale): array
    {
        if ($this->labels === null) {
            return [];
        }

        // Which targets each role has to answer for — the role is read off the relation that points.
        $wanted = [];

        foreach ($relations as $relation) {
            $reference = ($values[$relation->id] ?? null)?->reference;

            if ($reference === null) {
                continue;
            }

            $role = $this->roleOf($resolved[$relation->id] ?? []);

            $wanted[$role->value][$reference][] = $relation->id;
        }

        $names = [];

        foreach ($wanted as $role => $targets) {
            $resolvedNames = $this->labels->forNodes(
                array_values($this->nodes->byIds(array_keys($targets))),
                SeededRole::from($role),
                $locale
            );

            foreach ($targets as $target => $relationIds) {
                foreach ($relationIds as $relationId) {
                    // ⚠️ Absent stays absent: a dangling reference is drawn as a marked fault
                    // rather than as its id (D-363), and that decision is the renderer's to make.
                    if (isset($resolvedNames[$target])) {
                        $names[$relationId] = $resolvedNames[$target];
                    }
                }
            }
        }

        return $names;
    }

    /**
     * Die Hilfe je Feld — **die Beschriftung in der Rolle `help` am Ziel der Kante**.
     *
     * ⚠️ **[D-662](../../../docs/NewConcept/90-decision-log.md), sein Wort:** *«überall dort, wo help
     * label ist, sollte auch ein kleines Fragezeichen hinter dem Feld stehen.»*
     *
     * ⚠️ **Am **Ziel** und nicht an der Kante, weil dort auch die sichtbare Beschriftung herkommt.**
     * *{@see self::namesOfReferences()} und {@see self::fieldRowsFor()} holen den Namen eines Feldes
     * genauso — als Beschriftung des Knotens, auf den es zeigt. **Die Hilfe folgt der Beschriftung**,
     * sonst erklärte das Fragezeichen etwas anderes, als daneben steht.*
     *
     * ⚠️ *Zwei Abfragen für die ganze Zeile und keine je Feld (`CD-7`,
     * [D-159](../../../docs/NewConcept/90-decision-log.md)) — dieselbe Form wie die Nachbarn darüber.*
     *
     * @param  list<Relation>     $relations
     * @return array<int, string> Nach Kanten-Id; **fehlt**, wo keine Hilfe geschrieben ist.
     */
    private function hintsOfFields(array $relations, string $locale): array
    {
        if ($this->labels === null || $relations === []) {
            return [];
        }

        $targets = $this->nodes->byIds(array_map(static fn (Relation $e): int => $e->toNodeId, $relations));
        $hilfen  = $this->labels->helpFor(array_values($targets), $locale);

        $found = [];

        foreach ($relations as $relation) {
            $text = $hilfen[$relation->toNodeId] ?? '';

            if ($text !== '') {
                $found[$relation->id] = $text;
            }
        }

        return $found;
    }

    /**
     * Which label role this relation asked for, or the ordinary one.
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
    public function labelRoleFor(Relation $relation): SeededRole
    {
        return $this->roleOf($this->settingsForUseSites([$relation])[$relation->id] ?? []);
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
     * @param  list<Relation>                            $relations
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
        // ⚠️ **Auch die Angaben des geltenden Renderers** ([D-684](../../../docs/NewConcept/90-decision-log.md)).
        // *Der Name sagt «was an diesem Knoten gilt», und `with_label`, `label_role` und
        // `orientation` gelten dort ebenso — sie stehen nur an einer anderen Kette
        // ({@see self::withRendererValues()}, dieselbe Naht, aus der auch gezeichnet wird).*
        //
        // ⚠️ **Was das Fehlen gekostet hat:** *der Schreiber vergleicht mit dieser Auskunft, ob eine
        // Angabe schon so dasteht ([D-609](../../../docs/NewConcept/90-decision-log.md)). **Ohne die
        // Renderer-Angaben fand er nie einen Vergleichswert**, und jedes Speichern legte am Kind eine
        // eigene Zeile an — aus «geerbt» wurde still «hier gesetzt» (`INF-076`).*
        return $this->withRendererValues($node);
    }

    private function vonDenKnoten(array $nodes): array
    {
        $aus = [];

        foreach ($nodes as $node) {
            $aus[$node->id] = $this->withModelValues([], $node);
        }

        return $aus;
    }

    private function vonDenKanten(array $relations): array
    {
        $aus = [];

        foreach ($relations as $relation) {
            // ⚠️ **Zwei Spalten der Kante, als Angaben gereicht** ([D-713](90-decision-log.md),
            // [D-714](90-decision-log.md)): *keine Einstellungen, aber die Feldzeile zeichnet sie
            // neben den Einstellungen, und ein Renderer fragt `read_only` unter diesem Namen
            // ({@see RenderContext::mayEdit()}). Die Spalte ist die Wahrheit, die Angabe ihr Abbild.*
            $aus[$relation->id] = [
                EdgeColumn::MULTIPLICITY => new ResolvedSetting(
                    EdgeColumn::MULTIPLICITY,
                    TypedValue::ofText($relation->multiplicity->value),
                    $relation->id,
                    true
                ),
                EdgeColumn::READ_ONLY => new ResolvedSetting(
                    EdgeColumn::READ_ONLY,
                    TypedValue::ofBool($relation->readOnly),
                    $relation->id,
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
     * @param  list<Relation>         $relations       The attributes, in the order they are shown.
     * @param  array<int, TypedValue> $values      What the record holds, keyed by relation id. A
     *                                             missing key is *not answered* (D-232).
     * @param  string                 $fieldPrefix Form fields become `prefix[relation id]`. Keyed by
     *                                             the relation and never by position: a checkbox that
     *                                             does not submit when unticked would shift every
     *                                             later field onto the wrong attribute.
     * @return list<RenderedField>
     */
    public function fieldsFor(
        array $relations,
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
         * zusammengesetztem Feld die Kanten des Ziels nachgeladen — «a descent that fetches per relation is
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
        if ($relations === []) {
            return [];
        }

        // ⚠️ **The abort, and it is the descent's job rather than a renderer's**
        // ([D-450](90-decision-log.md), [D-452](90-decision-log.md), [D-457](90-decision-log.md)): a
        // hidden placement is not drawn and **not enumerated**. *Before, a renderer returned an empty
        // string — which means it had already been asked, and for a composed value its members had
        // already been drawn and thrown away.*
        //
        // ⚠️ **The relation's `hide`, and deliberately not the target node's.** *That distinction is what
        // keeps [D-426](90-decision-log.md)'s fix: as a setting, `hide` on a **type** reached every
        // field of that type and blanked them all — measured twice. A field is one **placement** of a
        // type, so hiding the type must not hide the fields that point at it. **A node's own `hide`
        // stops the walk where the walk enters the node** — the tree, and a composed value's members —
        // not where something merely points at it.* Recorded as [OQ-118](91-open-questions.md), because
        // the concept says «render no further» and does not say which walk.
        $relations = array_values(array_filter($relations, static fn (Relation $relation): bool => ! $relation->hide));

        if ($relations === []) {
            return [];
        }

        // ⚠️ *Hier stand für einen Augenblick eine Sortierung «Einstellungen hinten» — und sie war an
        // der falschen Stelle: der **Behälter** legt aus, nicht der Zeichenlauf
        // ([D-366](../../../docs/NewConcept/90-decision-log.md)), und {@see FormRenderer::groupOf()} hat
        // die Gruppen dafür. **Gemessen: der Form-Renderer sortierte danach wieder nach `position` und
        // machte sie zunichte** — zwei Stellen für eine Reihenfolge, und die zweite gewann.*
        $types    = $this->typesOf($relations);
        $resolved = $this->settingsForUseSites($relations);
        $names    = $this->namesOfReferences($relations, $values, $resolved, $locale);
        // ⚠️ *Dieselbe Naht, ein anderes fremdes System: `refersTo` heisst «wie heisst das, worauf
        // dieser Wert zeigt» ([D-649](../../../docs/NewConcept/90-decision-log.md)).*
        $userNames = $this->namesOfUsers($relations, $values, $types);
        // ⚠️ *Die Hilfen der Zeile, in **einem** Zug (`CD-7`) — [D-662](../../../docs/NewConcept/90-decision-log.md).*
        $hilfen   = $this->hintsOfFields($relations, $locale);
        $wahl     = $this->optionsFor($relations);
        $fields   = [];

        // ⚠️ *Einmal, ganz oben, in einer festen Zahl von Abfragen — und danach rührt der Abstieg die
        // Datenbank nicht mehr an.*
        if ($tiefe === 0 && $unterbau === []) {
            $unterbau = $this->subgraph($relations, self::TIEFSTENS);
        }

        foreach ($relations as $relation) {
            $type     = $types[$relation->id] ?? null;
            // ⚠️ **Hier kommen die Werte des gewählten Renderers ausdrücklich *nicht* dazu, und der
            // Grund ist gemessen** ({@see self::withRendererValues()} tut es für den Behälter).
            // *Am Satz von `slider` steht `converter = hexadecimal`, am Satz von `checkbox` auch.
            // Liesse man den Satz des Renderers auch am Feld gelten, stünde **jede** Ganzzahl mit
            // Schieber als Hexzahl da — `einstellungen-check` sagt genau das («die 12 steht als 12 da»).
            // **Ob eine Abbildung eine Eigenschaft des Renderers ist oder des Feldes, ist nicht
            // entschieden** und steht als Frage im Eingangsblatt (`PR-4`); bis dahin bleibt sie da,
            // wo sie heute wirkt.*
            $settings = $this->withModelValues($resolved[$relation->id] ?? [], $relation);
            $renderer = $this->renderers->chosenFor($relation, $settings, $purpose, $type);

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

            $gewaehlt = $values[$relation->id] ?? null;

            $moeglich = $wahl[$relation->id] ?? [];

            // ⚠️ **Für den Renderer sagt die Registratur, was es gibt — nicht der Baum**
            // ([D-603](../../../docs/NewConcept/90-decision-log.md)): *«wofür ein Konverter oder ein
            // Renderer taugt, bleibt im Kode — `handles()` an der Klasse»*, und `eligibleFor()`
            // verengt auf den Typ, **am Knoten der Knoten selbst**.
            //
            // ⚠️ **Warum die Kinderregel hier nicht mehr trägt** ([D-644](../../../docs/NewConcept/90-decision-log.md)):
            // *[D-540](../../../docs/NewConcept/90-decision-log.md) sammelt die Kinder des Kantenziels
            // und sah früher durch **markierte** Knoten hindurch ([D-544](../../../docs/NewConcept/90-decision-log.md)).
            // Mit dem Fall der Marke wurde der Zwischenknoten `render with label` selbst eine
            // Möglichkeit statt eines Durchgangs — **gemessen verschwanden `form`, `table`, `compact`,
            // `chooser-inline` und `chooser-dialog` aus der Wahl**, und der eigene Renderer-Block war
            // der letzte Weg zu ihnen. *`reference` gehört zwar auch unter den Zwischenknoten, war aber
            // nie darunter: er zeichnet nur zum Anzeigen.*
            //
            // ⚠️ **Und die naheliegende Ersatzregel ist gemessen falsch:** *«ein Knoten mit Kindern ist
            // ein Durchgang» machte `Base units` von 2 auf 14 wählbare Werte und `Electronic Parts`
            // von 2 auf 4 — man könnte `With prefix` und `Passiv` nicht mehr wählen.*
            //
            // ⚠️ *Keine Sonderregel für **einen Knoten**: gefragt wird der Einstellungsschlüssel
            // `renderer`, nicht ein Name und nicht ein Ast (`CD · Prohibited`). Ein Knoten, der unter
            // `Renderer` hängt, aber in der Registratur fehlt, ist damit keine Möglichkeit.*
            //
            // ⚠️ **Seit [D-647](../../../docs/NewConcept/90-decision-log.md) liegt die Logik in einer
            // Klasse und nicht mehr hier verteilt** ({@see RendererChoiceRenderer}). *Hier steht nur
            // noch, **dass** diese Zeile die Renderer-Wahl ist; **was** sie anbietet und **was**
            // darin vorausgewählt steht, beantwortet der Wähler selbst.*

            // ⚠️ **Die Wahl wird gebaut und nicht stückweise ausgerechnet** ({@see Choice}). *Der
            // Eigentümer hat den Grund benannt: «von der Multiplizität zum Choice ist ein Weg … und es
            // kann sein, dass du den mehrfach erfindest». **Gemessen stand der Weg viermal**, und ein
            // Kerntest zählt jetzt nach, dass er einmal steht.*
            $dieWahl = Choice::atUseSite(
                $relation->multiplicity,
                $istWahl ? $moeglich : [],
                $gewaehlt,
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
            $verweis = $gewaehlt?->reference;

            if ($istWahl && $verweis !== null && isset($names[$relation->id])) {
                $dieWahl = $dieWahl->including($verweis, $names[$relation->id]);
            }

            // ⚠️ *Der gespeicherte Wert ist über {@see Choice::including()} schon darin
            // ([D-360](../../../docs/NewConcept/90-decision-log.md)) — die Menge selbst kommt für den
            // Renderer aus der Registratur, oben.*
            $angebot = $dieWahl->options;

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
            //
            // ⚠️ **Die Renderer-Wahl nimmt ihren eigenen** ([D-647](../../../docs/NewConcept/90-decision-log.md)).
            // *Er zeichnet dasselbe Auswahlfeld — {@see RendererChoiceRenderer::render()} reicht an
            // `choice` weiter, weil [R28–R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete)
            // nur an einer Stelle stehen dürfen. **Was ihn unterscheidet, ist die Menge, nicht die
            // Gestalt.***
            if ($istWahl && $dieWahl->canShowItsState() && $this->chosenRendererName($settings) === '') {
                $renderer = $this->renderers->byName(
                    ChoiceRenderer::NAME
                );
            }

            if ($renderer === null) {
                if ($purpose === Purpose::Search) {
                    continue;
                }

                $renderer = $this->renderers->fallback();
            }

            $value = $gewaehlt ?? TypedValue::nothing();

            $context = new RenderContext(
                purpose: $purpose,
                value: $value,
                settings: $settings,
                locale: $locale,
                level: $level,
                editable: $editable,
                fieldName: $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $relation->id . ']',
                type: $type,
                surroundings: new Surroundings(
                    // ⚠️ By **relation**, not by target: the role that decided this text belongs to the
                    // relation, so two attributes pointing at one node can show `k` and `kilo`.
                    // ⚠️ *Ein Benutzerverweis ist kein Knotenverweis — sein Wert ist Text
                    // ([D-171](../../../docs/NewConcept/90-decision-log.md)), also kommt sein Name aus
                    // der anderen Naht. **Dasselbe Feld**, weil es dieselbe Aussage ist.*
                    refersTo: $value->reference === null
                        ? ($userNames[$relation->id] ?? null)
                        : ($names[$relation->id] ?? null),
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
            $tiefer = $onlySettingParts && ! $relation->isSetting()
                ? null
                : $this->partBelow($relation, $type, $purpose, $fieldPrefix, $locale, $level, $editable, $formId, $tiefe, $unterbau, $values, $gesehen, $parts[$relation->id] ?? [], $forNode);

            $fields[] = new RenderedField(
                $relation,
                $type,
                $tiefer === null ? $renderer->name() : $tiefer['renderer'],
                // WICHTIG: Bei einer Auswahl bleiben *beide* stehen -- der Kasten, in dem gewaehlt
                // wird, und daneben die Felder des Gewaehlten (D-583: "einfach rechts davon
                // anhaengen finde ich am schoensten"). Vorher ersetzte der Abstieg den Kasten,
                // und der Renderer liess sich nicht mehr wechseln.
                $tiefer === null
                    ? $renderer->render($relation, $context)
                    : $this->chosenAndItsFields($relation, $type, $renderer, $context, $tiefer['result']),
                // Carried for the **layout**: R75 puts read-only values first, as context rather
                // than as something to fill in. A container must not resolve the chain again.
                $context->setting(EdgeColumn::READ_ONLY)?->asBool() ?? false,
                '',
                // ⚠️ *Aus demselben Grund mitgegeben: der Behälter zeichnet das Fragezeichen und
                // darf nichts nachschlagen ([D-662](../../../docs/NewConcept/90-decision-log.md)).*
                $hilfen[$relation->id] ?? ''
            );
        }

        return $fields;
    }

    /**
     * A node drawn **as a value** — what a field of this type looks like, with this node's settings.
     *
     * ⚠️ **[D-430](../../../docs/NewConcept/90-decision-log.md), and it exists because the descent
     * takes relations while a type node has none.** The owner: *why no preview on the simple data types?*
     * The panel refused them for a reason that answers a different question — *only a node that can
     * hold records has something to preview* — which is right about **records** and wrong about
     * **fields**: a data type does not hold one, it **is** one.
     *
     * ⚠️ **No synthetic relation.** {@see RendererRegistry::chosenFor()} and {@see Renderer::render()}
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
        /**
         * ⚠️ **Leer heisst Vorschau, gesetzt heisst Eingabe** ([D-679](../../../docs/NewConcept/90-decision-log.md)).
         *
         * *Sein Vorschlag: «was mir da einfällt wir könnten bei der preview eingabe einen button
         * hinzufügen add as example». **Und das Feld kann es schon** — es kennt den Typ, den
         * Renderer und die Kette; es warf seine Eingabe nur weg, weil es absichtlich namenlos war.
         * Ein Name macht daraus die Maske für den eigenen Wert des Knotens
         * ([D-673](../../../docs/NewConcept/90-decision-log.md)), ohne dass eine zweite gebaut wird.*
         */
        string $fieldName = '',
        string $formId = '',
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
        // ⚠️ **Auch die Angaben des gewählten Renderers, seit dem 2026-09-06** — *hier stand nur
        // `withModelValues()`, und damit fehlten genau die drei, die am Renderer selbst hängen:
        // `converter`, `with_label`, `label_role`. **Gemessen an `Gramm`:** *mit `label_role = symbol`
        // an `Base units` zeichnete die Vorschau weiter «Gramm» statt «g» — die Rolle lag im Satz des
        // Renderers, und dieser Leser sah nur die Kette des Knotens.*
        //
        // ⚠️ *Dieselbe Naht wie in {@see self::containerFor()}, die es schon macht — **der Gezeichnete
        // gewinnt, wo beide sprechen** ({@see self::withRendererValues()}).*
        $settings = $this->withRendererValues($node);

        if ($value === null || $value->isNothing()) {
            $value = ($settings['default'] ?? null)?->value ?? TypedValue::nothing();
        }

        // ⚠️ **Eine Konstante ist ihr eigener Wert** — *sein Wort am 2026-09-06 zu `Gramm`: «Preview
        // geht nicht — du hast Knoten, du hast Renderer, Daten gibts hier keine, sollte aber
        // ausreichend sein.» **Er hat recht:** ein Knoten unter `Constants` hält keinen Wert, er
        // **ist** einer. Ohne diese Zeilen zeichnete die Vorschau einen leeren Kasten oder — mit
        // `reference` — den roten Hinweis «Verweis zeigt ins Leere», weil niemand einen Wert
        // hineingab.*
        //
        // ⚠️ *Die Beschriftung kommt in der aufgelösten **Rolle** ([D-539](../../../docs/NewConcept/90-decision-log.md)):
        // steht `label_role = symbol`, zeigt die Vorschau «g» und nicht «Gramm» — genau das, was auf
        // der Seite später herauskommt.*
        $eigenerVerweis = $type === SimpleType::NodeRef && $value->isNothing();

        if ($eigenerVerweis) {
            $value = TypedValue::ofReference($node->id);
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
            // ⚠️ **Namenlos, solange es eine Vorschau ist**: *ein benanntes Feld im
            // Einstellungsformular würde mitgeschickt, als hätte es jemand ausgefüllt.* **Mit einem
            // Namen ist es die Eingabe für den eigenen Wert** ([D-679](../../../docs/NewConcept/90-decision-log.md)).
            fieldName: $fieldName,
            type: $type,
            surroundings: new Surroundings(
                // ⚠️ *Aufgelöst hereingegeben und nicht im Renderer nachgeschlagen — dieselbe Naht wie
                // im Formular ({@see self::fieldsOf()}): der Kern löst, der Renderer zeichnet.*
                refersTo: $eigenerVerweis
                    ? ($this->labels?->forNodes([$node], $this->roleOf($settings), $locale)[$node->id] ?? $node->name)
                    : null,
                formId: $formId,
            ),
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
     * descent that fetches per relation is N+1 by construction» and «the renderer never writes». **A
     * registry lookup is neither a fetch nor a write**, so nothing decided forbids a renderer from
     * descending. Three docblocks claimed it did, citing D-159, and then got quoted back as though
     * D-159 had said it — `PR-10`'s dangling rule, with a citation to make it look agreed.*
     *
     * @param list<Relation>         $relations  The model's attributes, in the order they are shown.
     * @param array<int, TypedValue> $values What this record holds, keyed by relation id.
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
     * @param list<Relation>                                                                                        $relations  Die Felder des Modells, in ihrer Reihenfolge.
     * @param list<array{id: int, values: array<int, TypedValue>, lead: array<string,string>, acts: list<Control>, submits: Submission}> $rows
     */
    public function recordsAsTable(
        Node $model,
        array $relations,
        array $rows,
        string $fieldPrefix = '',
        string $diagnostic = '',
        bool $developerMode = false,
        Purpose $purpose = Purpose::Edit,
        string $locale = '',
        Level $level = Level::Admin,
        /**
         * ⚠️ **Die Renderer-Diagnose, je Zelle** ([D-711](../../../docs/NewConcept/90-decision-log.md),
         * sein Wort: «je zelle»). *Gemessen am 2026-09-09 füllte sie niemand (TASK-085): der Rand hatte
         * die Worte, der Kern die Zeichnung, und keiner reichte dem anderen etwas. Jetzt bekommt der Rand
         * nach dem Zeichnen je Satz seine gezeichneten Felder und macht daraus den Text — die Worte
         * bleiben am Rand (`AR-2`), das Wissen, wer gezeichnet hat, im Kern.*
         *
         * @var (\Closure(list<array{id:int}>, list<list<RenderedField>>): string)|null
         */
        ?\Closure $diagnose = null,
    ): RenderResult {
        $gezeichnet = [];
        $vorne      = [];
        $akte       = [];

        foreach ($rows as $row) {
            $formId = 'taxmod-record-' . $row['id'];

            // ⚠️ *Der Knoten selbst gilt als «schon besucht»: eine Einstellung, die auf ihn zeigt, würde
            // ihn sonst ein zweites Mal aufklappen ([OQ-133](../../../docs/NewConcept/91-open-questions.md)).*
            $gezeichnet[] = $this->fieldsFor(
                $relations,
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
                // ⚠️ **Sonst käme der Umschalter nie an** (TASK-063). *Hier stand gar keine Angabe —
                // derselbe leere Zeichenkontext, den er am Kompaktrenderer gemeldet hat («compact mit
                // horizontal und ohne Label gewählt, aber gerendert wird vertikal»). `orientation` und
                // `with_label` hängen am **Satz des gewählten Renderers**, also holt sie
                // {@see self::withRendererValues()} und nicht die Kette des gezeichneten Knotens.*
                settings: $this->withRendererValues($model),
            )
        );

        $sections = [RecordRenderer::FORM => new Section('', $tabelle->markup)];

        if ($diagnostic === '' && $diagnose !== null) {
            $diagnostic = (string) $diagnose($rows, $gezeichnet);
        }

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
        array $relations,
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
                    $relations,
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
     * Draw a node's attributes as rows — one renderer per attribute, the subject being the **relation**.
     *
     * ⚠️ **The attribute table was the last hand-built markup on the detail page**, which `R1`
     * forbids: *everything that is displayed must be a renderer.* The owner found it by asking for
     * the one thing it could not do — *the name of the attributes should be changeable*
     * ([D-376](90-decision-log.md)).
     *
     * ```mermaid
     * flowchart LR
     *   E["each attribute relation"] --> C["its multiplicity, drawn"]
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
     * ordinary setting on the relation ([D-351](90-decision-log.md)), and a second select composed in
     * this method would be the same control twice. *That is precisely the defect D-376 records: the
     * hand-built one had been posting to a field name nobody read since the settings panel moved.*
     *
     * @param  list<Relation>                  $relations     The attributes, in the order shown.
     * @param  array<int, list<Control>>       $actions   What may be done, keyed by **relation** id.
     * @param  array<int, Submission>          $submits   Where those go, keyed by relation id.
     * @param  int                             $declaredBy The node whose page this is — an
     *                                                    attribute is editable only on the node that
     *                                                    declares it.
     * @param  array<int, string>              $targetHrefs Where a target node is reached, keyed by
     *                                                    **node** id — not by relation id, because two
     *                                                    attributes pointing at one node share the
     *                                                    address.
     * @return list<RenderedField>
     */
    public function fieldRowsFor(
        array $relations,
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
        /**
         * Welche Zeilen ihren Einstellungsbereich **aufgeklappt** zeigen — Kanten-Id ⇒ `true`.
         *
         * ⚠️ **Das ist die ganze Zusage aus [D-666](../../../docs/NewConcept/90-decision-log.md):**
         * *«Standard ist nicht ausgeklappt — das heisst auch nicht gelesen.» Eine Zeile, die hier
         * nicht steht, geht **nicht** durch {@see self::withModelValues()} und bekommt aus
         * {@see self::settingsFor()} nur «wie oft», das als Spalte an der Kante steht. Der Unterschied
         * ist messbar: keine Kette über Kante, Zielknoten und dessen Vorfahren
         * ([D-602](../../../docs/NewConcept/90-decision-log.md)), und im Markup steht kein einziges
         * Steuerelement des Bereichs.*
         *
         * @var array<int, bool>
         */
        array $expanded = [],
        /**
         * Die Worte des Randes für den aufgeklappten Bereich — Gruppennamen, «geerbt».
         *
         * ⚠️ *Der Kern kann kein Wort machen (`AR-2`, [OQ-087](../../../docs/NewConcept/91-open-questions.md)),
         * also reisen sie als `word:<key>` mit, wie in der Zeile selbst.*
         *
         * @var list<Control>
         */
        array $settingsWords = [],
    ): array {
        if ($relations === []) {
            return [];
        }

        $renderer = $this->renderers->byName(FieldRowRenderer::NAME);
        $resolved = $this->vonDenKanten($relations);
        $targets  = $this->nodes->byIds(array_map(static fn (Relation $e): int => $e->toNodeId, $relations));

        // ⚠️ One query for the whole table, not one per row (`CD-7`) — and through the ordinary
        // label walk, so an attribute's target reads the same here as it does anywhere else.
        $names = $this->labels === null
            ? []
            : $this->labels->forNodes(array_values($targets), SeededRole::Form, $locale);

        // ⚠️ **Die Kette des Knotens, an dem die Zeilen stehen — einmal, nicht je Zeile** (`CD-7`).
        // *Sie sagt der Wertspalte einer Einstellungszeile, ob der gezeigte Wert geerbt ist
        // ([D-689](../../../docs/NewConcept/90-decision-log.md): dann gesperrt) und von wem — und ob
        // er hier überhaupt zulässig ist ([D-687](../../../docs/NewConcept/90-decision-log.md)).*
        $knotenHier = $showValue && $valuePrefix !== '' && $declaredBy !== 0 ? $this->nodes->find($declaredBy) : null;
        $ketteHier  = $knotenHier === null ? [] : $this->withRendererValues($knotenHier);

        $rows = [];

        foreach ($relations as $relation) {
            $offen = $expanded[$relation->id] ?? false;

            // ⚠️ **Zugeklappt heisst nicht aufgelöst** ([D-666](../../../docs/NewConcept/90-decision-log.md)).
            // *`withModelValues()` geht für eine Kante die Kette über Ziel und Vorfahren
            // ([D-602](../../../docs/NewConcept/90-decision-log.md)); «wie oft» braucht sie nicht — das
            // ist eine **Spalte** der Kante ([D-351](../../../docs/NewConcept/90-decision-log.md)) und
            // liegt schon in `$resolved`. **Ein `display:none` hätte hier trotzdem gelesen**, und
            // genau das war der Beschluss.*
            $settings = $offen
                ? $this->withModelValues($resolved[$relation->id] ?? [], $relation)
                : ($resolved[$relation->id] ?? []);

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
            $rowSettings = $settingPrefix === '' ? '' : $settingPrefix . '[' . $relation->id . ']';
            $rowForm     = $pageForm === '' ? FieldRowRenderer::formFor($relation) : $pageForm;

            foreach ($this->settingsFor(
                $relation,
                $settings,
                Purpose::Edit,
                $rowSettings,
                $locale,
                $level,
                [],
                $rowForm,
                $relation->fromNodeId === $declaredBy,
                // ⚠️ *Zugeklappt: genau der eine Schlüssel, den die Zeile selbst zeigt.*
                $offen ? [] : [EdgeColumn::MULTIPLICITY],
                // ⚠️ *Der Knoten dieser Seite — er sagt, welcher Renderer hier gilt
                // ([D-682](../../../docs/NewConcept/90-decision-log.md)).*
                $declaredBy
            ) as $drawn) {
                $configured[$drawn->key] = $drawn;
            }

            // ⚠️ **Die Art ist in der eigenen Zeile änderbar** (TASK-066, [D-618](../../../docs/NewConcept/90-decision-log.md):
            // *«der benutzer legt fest»*). *Dasselbe Auswahlfeld wie beim Anlegen
            // ([D-665](../../../docs/NewConcept/90-decision-log.md): ein Auswahlfeld wird überall gleich
            // gezeichnet), unter dem Namen der Zeile — der Rand liest es neben «wie oft». Eine geerbte
            // Zeile zeigt die Art wie bisher als Wort: sie gehört dem Vorfahren.*
            if ($rowSettings !== '' && $relation->fromNodeId === $declaredBy) {
                $configured[self::KIND_KEY] = $this->kindChoice($relation, $rowSettings, $rowForm, $locale, $level, $settingsWords);
            }

            // ⚠️ **Der Bereich unter der Zeile, und er wird nur gezeichnet, wenn er offen ist**
            // ([D-666](../../../docs/NewConcept/90-decision-log.md)). *Gezeichnet von
            // {@see SettingsRenderer} — dem Einstellungsbereich, die es für genau diesen Zweck schon gibt —, damit
            // die Einstellungen einer Kante aussehen wie die eines Knotens und nicht wie eine zweite
            // Machart ([D-665](../../../docs/NewConcept/90-decision-log.md), `R1`).*
            //
            // ⚠️ *«wie oft» bleibt draussen: es steht als eigene Spalte in der Zeile, und zweimal
            // dasselbe Steuerelement ist genau der Mangel, den `R1` verbietet.*
            $bereich = ! $offen ? '' : $this->panelMarkup(
                $relation,
                $configured,
                $settingsWords,
                $rowForm,
                $locale,
                $level,
                $relation->fromNodeId === $declaredBy
            );

            // ⚠️ **Der Wert der Angabe, gezeichnet vom gewöhnlichen Abstieg.** *Bei `Display Option`
            // steigt der in den Teil hinein und liefert **beide** Felder — `render` und `converter`;
            // sein Satz dazu: «es sollte ja auch das zweite Feld für Converter zu sehen sein». Bei
            // `read_only` kommt ein Schalter, bei `label_role` eine Auswahl.*
            //
            // ⚠️ *Ein Aufruf je Zeile, und er kostet keine Abfrage: die Kanten des Unterbaus holt
            // {@see self::subgraph()} in einer festen Zahl von Abfragen
            // ([D-159](../../../docs/NewConcept/90-decision-log.md)).*
            // ⚠️ **Die drei Einstellungen des aufgelösten Renderers stehen an *jedem* Knoten** — seine
            // Regel, wörtlich: *«jeder knoten hat einen renderer vater knoten kann ihn vorgeben aber
            // nicht definieren»*, und der Fall dazu: *«wenn ich ein bool haben und darunter ein
            // read_only kann ich am readonly sagen das er als checkbox dargestellt wird»*.
            //
            // ⚠️ **Gemessen am 2026-09-06:** *an `Base units` (eigene Wahl, Teil #11691, `chooser-dialog`)
            // standen `converter`, `label_role` und `with_label` da; an `Gramm` **keine der drei**, weil
            // sein Renderer nur geerbt ist (Satz #11693 am Elternknoten #4032, `reference`) und die Werte
            // im Satz des Renderers wohnen. **Und heran kam er auch nicht**: die Renderer-Liste an `Gramm`
            // hat einen Eintrag und ist ausgegraut.*
            //
            // ⚠️ *Der geliehene Teil trägt die Satz-Id `0`; {@see self::partBelow()} macht daraus die
            // Adresse des **Knotens**, und der eigene Teil entsteht beim ersten Schreiben
            // ([D-609](../../../docs/NewConcept/90-decision-log.md)).*
            $dieseTeile = $parts;

            $sperre = $knotenHier === null ? null : $this->sperreFuer($relation, $values, $ketteHier);

            $gezeichneterWert = ! $showValue ? [] : $this->fieldsFor(
                [$relation],
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
                $dieseTeile,
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
                editable: $relation->fromNodeId === $declaredBy,
                fieldName: $namePrefix === '' ? '' : $namePrefix . '[' . $relation->id . ']',
                surroundings: new Surroundings(
                    refersTo: $names[$relation->toNodeId] ?? null,
                    actions: $actions[$relation->id] ?? [],
                    // ⚠️ **The target's address, so the row can be a way *to* it.** The owner,
                    // 2026-08-26: *should have a jump link to the node.* Reading a model meant
                    // finding `BOM Position` in the tree by eye.
                    //
                    // ⚠️ *Keyed by the **target's** id and handed in, because a URL is a boundary
                    // fact (`CD-1`) and one lookup per row would be `CD-7`'s loop. The screen
                    // builds it from the same method the tree rows use.*
                    href: $targetHrefs[$relation->toNodeId] ?? null,
                    // WICHTIG: Der Auswahldialog dieser Zeile -- TASK-029. Er kommt fertig vom
                    // Rand, weil er URL und Nonce braucht, und wird nur durchgereicht.

                    submits: $submits[$relation->id] ?? null,
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
                    locked: false,
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
                            : [
                                // ⚠️ *Der Haken zuerst, als eigene Spalte — sein Wort am 2026-09-10; leer, wo nichts gesperrt ist.*
                                FieldRowRenderer::OVERRIDE => new Section(
                                    '',
                                    $this->overrideHakenMarkup(
                                        $gezeichneterWert,
                                        $sperre,
                                        $valuePrefix === '' ? '' : $valuePrefix . '[' . $relation->id . ']',
                                        $pageForm,
                                        $settingsWords
                                    )
                                ),
                                FieldRowRenderer::VALUE => new Section(
                                    '',
                                    $this->wertspalteMarkup(
                                        $gezeichneterWert,
                                        $sperre,
                                        $valuePrefix === '' ? '' : $valuePrefix . '[' . $relation->id . ']',
                                        $pageForm,
                                        $settingsWords
                                    )
                                ),
                            ],
                        // WICHTIG: Der Auswahldialog dieser Zeile -- TASK-029. Er kommt fertig vom
                        // Rand, weil er URL und Nonce braucht (CD-1), und wird durchgereicht.
                        isset($targetChoosers[$relation->id])
                            ? ['target-chooser' => new Section('', $targetChoosers[$relation->id])]
                            : [],
                        // ⚠️ *Nur wenn aufgeklappt. Die Abwesenheit des Abschnitts **ist** die
                        // Aussage «nicht gelesen» — die Zeile zeichnet dann keine zweite Zeile.*
                        $bereich === ''
                            ? []
                            : [FieldRowRenderer::SETTINGS => new Section('', $bereich)]
                    )
                ),
            );

            $rows[] = new RenderedField(
                $relation,
                null,
                $renderer->name(),
                $renderer->render($relation, $context),
                false
            );
        }

        return $rows;
    }

    /**
     * Ein Wort, das der Rand übersetzt hat — leer, wenn er keins geschickt hat.
     *
     * ⚠️ *Der Kern kann kein Wort machen (`AR-2`), also reisen sie als `word:<key>`, wie «own» und
     * «inherited» in der Feldzeile längst. **Leer statt Schlüssel:** ein `title`, in dem der Schlüssel
     * steht, ist eine Programmierausgabe an einem Ort, an dem ein Satz stehen sollte.*
     *
     * @param list<Control> $woerter
     */
    /**
     * Ob die Wertspalte dieser Einstellungszeile gesperrt ist — und von wem der Wert kommt.
     *
     * ⚠️ **Geerbt heisst gesperrt** ([D-689](../../../docs/NewConcept/90-decision-log.md)): *ein Wert,
     * der hier nur **gezeigt** wird, darf nicht aussehen wie einer, der hier gesetzt ist — genau so
     * entstand sein Befund «in der gui eine default schalterstellung steht diese aber nicht
     * gespeichert ist». Eigen ist, was im `default`-Satz dieses Knotens steht (`$values`); alles
     * andere, was die Kette liefert, ist geerbt.*
     *
     * @param  array<int, TypedValue>              $values Die eigenen Werte des Knotens, je Kante.
     * @param  array<string, ResolvedSetting>      $kette  Die Kette des Knotens, je Schlüssel.
     * @return array{von: string, wert: string}|null
     */
    private function sperreFuer(Relation $relation, array $values, array $kette): ?array
    {
        if (! $relation->isSetting() || isset($values[$relation->id])) {
            return null;
        }

        $angabe = $kette[$relation->name] ?? null;

        if ($angabe === null || ! $angabe->isLocked() || $angabe->value->isNothing()) {
            return null;
        }

        $text = (string) ($angabe->value->text ?? '');

        return [
            'von'        => (string) ($this->nodes->find($angabe->fromOwnerId)?->name ?? ''),
            'wert'       => $text !== '' ? $text : $angabe->value->describe(),
            // Woran der geerbte Wert im gezeichneten Auswahlfeld zu erkennen ist: als Wert oder als Beschriftung.
            'kandidaten' => array_values(array_filter([$text, (string) ($angabe->value->reference ?? '')], static fn (string $k): bool => $k !== '')),
        ];
    }

    /**
     * Die Wertspalte — und bei einem geerbten Wert die Sperre, der Haken und die Herkunft darum herum.
     *
     * ⚠️ **Im Konflikt fällt die Sperre von selbst** ([D-688](../../../docs/NewConcept/90-decision-log.md),
     * [D-689](../../../docs/NewConcept/90-decision-log.md)): *ist der geerbte Wert hier nicht zulässig,
     * zeigt die Auswahl den Typ-Standard als gewählt und nicht ihn — dann steht die Zeile offen, der
     * Haken ist gesetzt, und der Satz daneben nennt, was ersetzt wurde. Gelesen wird das am
     * gezeichneten Steuerelement, weil dort und nirgends sonst die Menge steht, aus der hier gewählt
     * werden darf.*
     *
     * @param list<RenderedField> $gezeichnet
     * @param array{von: string, wert: string}|null $sperre
     * @param list<Control> $woerter
     */
    private function wertspalteMarkup(array $gezeichnet, ?array $sperre, string $feldName, string $formId, array $woerter): string
    {
        if ($gezeichnet === []) {
            return '';
        }

        $markup = $gezeichnet[0]->result->markup;

        if ($sperre === null || $feldName === '') {
            return $markup;
        }

        // ⚠️ *Nur das **erste** Auswahlfeld ist die Wahl — darunter zeichnet die Zelle die
        // Untereinstellungen des Gewählten, und die haben ihre eigenen gewählten Einträge. **Automatisch
        // ist die Zeile, wenn das Auswahlfeld etwas anderes als gewählt zeigt als die Kette liefert**:
        // dann hat {@see RendererRegistry::chosenFor()} den geerbten Namen verworfen und den
        // Typ-Standard eingesetzt, und genau der steht als gewählt da.*
        $automatisch = false;

        if (preg_match('/<select\b[^>]*>.*?<\/select>/s', $markup, $wahl) === 1
            && preg_match('/<option value="([^"]*)"[^>]*\bselected\b[^>]*>([^<]*)</', $wahl[0], $gewaehlt) === 1
        ) {
            $automatisch = ! in_array(trim($gewaehlt[1]), $sperre['kandidaten'], true)
                && ! in_array(trim(html_entity_decode($gewaehlt[2])), $sperre['kandidaten'], true);
        }
        $satz        = $automatisch
            ? $this->satzAus($woerter, 'automatic', $sperre['wert'])
            : $this->satzAus($woerter, 'inherited_from', $sperre['von']);
        // ⚠️ *Der Haken steht nicht mehr hier, sondern vorn in der Zeile, als eigene Spalte
        // ({@see self::overrideHakenMarkup()}) — sein Wort: «als richtige spalte».*
        return '<span class="taxmod-setting-locked' . ($automatisch ? ' taxmod-setting-automatic' : '') . '">'
            . '<span class="taxmod-setting-locked-control">' . $markup . '</span>'
            . ' <em class="' . ($automatisch ? 'taxmod-automatic' : 'taxmod-inherited') . '">' . RenderResult::escape($satz) . '</em>'
            . '</span>';
    }

    /**
     * Der Haken «hier überschreibe ich» für die erste Spalte der Zeile — leer, wo nichts gesperrt ist.
     *
     * ⚠️ **Sein Wort am 2026-09-10:** *«das override tickfeld mal an den anfang der setting zeile und als
     * richtige spalte».* *Derselbe Haken wie vorher, dieselbe Adresse (`<Feld>_override[...]`), nur nicht
     * mehr hinter dem Steuerelement. Ob er vorgesetzt ist, sagt dasselbe Merkmal wie bei der Wertspalte:
     * ein geerbter Eintrag, den die Stelle nicht zulässt, ist automatisch ersetzt ([D-688](../../../docs/NewConcept/90-decision-log.md)).*
     */
    private function overrideHakenMarkup(array $gezeichnet, ?array $sperre, string $feldName, string $formId, array $woerter): string
    {
        if ($gezeichnet === [] || $sperre === null || $feldName === '') {
            return '';
        }

        $automatisch = $this->automatischGewaehlt($gezeichnet[0]->result->markup, $sperre);
        $override    = (string) preg_replace('/^([A-Za-z_]+)/', '$1_override', $feldName);

        return '<label class="taxmod-override-act">'
            . '<input type="checkbox" class="taxmod-override" name="' . RenderResult::escape($override) . '" value="1"'
            . ($automatisch ? ' checked' : '')
            . ($formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"')
            . ' title="' . RenderResult::escape($this->wortAus($woerter, 'override')) . '"></label>';
    }

    /** Ob der gezeichnete Wähler einen Eintrag zeigt, den die Sperre nicht als geerbten Kandidaten kennt (D-688). */
    private function automatischGewaehlt(string $markup, array $sperre): bool
    {
        if (preg_match('/<select\b[^>]*>.*?<\/select>/s', $markup, $wahl) !== 1
            || preg_match('/<option value="([^"]*)"[^>]*\bselected\b[^>]*>([^<]*)</', $wahl[0], $gewaehlt) !== 1
        ) {
            return false;
        }

        return ! in_array(trim($gewaehlt[1]), $sperre['kandidaten'], true)
            && ! in_array(trim(html_entity_decode($gewaehlt[2])), $sperre['kandidaten'], true);
    }

    /** Ein Satz des Randes mit `%s` für einen Namen — oder der Name allein, wo der Rand keinen schickte. */
    private function satzAus(array $woerter, string $schluessel, string $name): string
    {
        $vorlage = $this->wortAus($woerter, $schluessel);

        return str_contains($vorlage, '%s') ? sprintf($vorlage, $name) : trim($vorlage . ' ' . $name);
    }

    /**
     * Die Art einer eigenen Feldzeile als Auswahlfeld — drei Werte, die der Benutzer festlegt.
     *
     * ⚠️ *[D-639](../../../docs/NewConcept/90-decision-log.md): die Kantenart ist eine Spalte mit drei
     * Werten; [D-618](../../../docs/NewConcept/90-decision-log.md): der Benutzer legt sie fest. Die
     * Wörter sind die Werte selbst — so stehen sie auch im Anlegeformular und in der Spalte «Kind».*
     */
    private function kindChoice(Relation $relation, string $rowSettings, string $formId, string $locale, Level $level, array $woerter = []): RenderedSetting
    {
        $options = [];

        // ⚠️ **Zwei Kantenklassen** ([D-715](../../../docs/NewConcept/90-decision-log.md)): *`setting` wird nicht mehr
        // angeboten. Die Art lebt nur noch an der geparkten Kante `position` der Wurzel (Modell 2.4 offen).*
        foreach (RelationKind::cases() as $kind) {
            if ($kind === RelationKind::Setting) {
                continue;
            }

            $options[$kind->value] = $kind->value;
        }

        // ⚠️ **Ein wartender Artwechsel** ([D-699](../../../docs/NewConcept/90-decision-log.md)): *der Rand
        // sagt als Wort, welche Art gewünscht war und was sie kostet; die Zeile zeigt sie vorgewählt,
        // den Satz und den Haken «ich bestätige». Ohne Haken bleibt beim nächsten Speichern alles.*
        $gewuenscht = $this->wortAus($woerter, 'kind_target:' . $relation->id);
        $satz       = $this->wortAus($woerter, 'kind_pending:' . $relation->id);
        $wartend    = $gewuenscht !== '' && RelationKind::tryFrom($gewuenscht) !== null;

        $setting = new ResolvedSetting(self::KIND_KEY, TypedValue::ofText($wartend ? $gewuenscht : $relation->kind->value), $relation->id, true);

        $auswahl = $this->renderers->byName(ChoiceRenderer::NAME)->render(
            $relation,
            new RenderContext(
                purpose: Purpose::Edit,
                value: $setting->value,
                settings: [],
                locale: $locale,
                level: $level,
                fieldName: $rowSettings . '[' . self::KIND_KEY . ']',
                surroundings: new Surroundings(options: $options, mayBeNothing: false, formId: $formId)
            )
        );

        $markup = $auswahl->markup;

        if ($wartend) {
            $markup .= '<label class="taxmod-kind-confirm">'
                . '<input type="checkbox" name="' . RenderResult::escape($rowSettings . '[' . self::KIND_CONFIRM_KEY . ']') . '" value="1"'
                . ($formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"') . '> '
                . RenderResult::escape($this->wortAus($woerter, 'kind_confirm')) . '</label>'
                . '<em class="taxmod-kind-pending description">' . RenderResult::escape($satz) . '</em>';
        }

        return new RenderedSetting(
            self::KIND_KEY,
            SettingShape::ARegisteredName,
            null,
            $setting,
            $wartend ? RenderResult::of($markup) : $auswahl,
            ChoiceRenderer::NAME
        );
    }

    private function wortAus(array $woerter, string $schluessel): string
    {
        foreach ($woerter as $control) {
            if ($control->name === 'word:' . $schluessel) {
                return $control->label;
            }
        }

        return '';
    }

    /**
     * Die Einstellungen **einer Verwendungsstelle**, für sich allein gezeichnet — der Rückweg.
     *
     * ```mermaid
     * flowchart LR
     *   R["der Rand: «diese Zeile wurde aufgeklappt»"] --> K[this]
     *   K --> A["Auflösung über Kante, Ziel, Vorfahren"]
     *   A --> P["der Einstellungsbereich · SettingsRenderer"]
     * ```
     *
     * ⚠️ **Das ist der erste Fall von [D-627](../../../docs/NewConcept/90-decision-log.md) in Code**,
     * *und deshalb ist der Zuschnitt so eng: der Rand stellt fest, **dass** etwas geschah — eine Zeile
     * wurde aufgeklappt — und fragt den Kern nach dem, was dazugehört. **Keine WordPress-Eigenheit
     * kommt herein** (`CD-1`): eine Kanten-Id, ein Knoten, zwei Namensvorsätze und die Worte, die der
     * Rand übersetzt hat. **Ein allgemeines Ereignissystem ist das hier ausdrücklich nicht** — es ist
     * die eine Naht, an der ein zweiter Fall ansetzen kann.*
     *
     * ⚠️ **Und es ist die einzige Stelle, die auflöst, wenn eine Zeile allein nachgeholt wird**
     * ([D-666](../../../docs/NewConcept/90-decision-log.md)): *für die Seite tut es
     * {@see self::fieldRowsFor()}, und beide enden in derselben Einstellungsbereich — eine Machart, nicht zwei
     * ([D-665](../../../docs/NewConcept/90-decision-log.md), `R1`).*
     *
     * @param list<Control> $words Die Worte des Randes, als `word:<key>`.
     */
    public function settingsPanelForUseSite(
        Relation $relation,
        int $declaredBy,
        string $fieldPrefix = '',
        string $formId = '',
        array $words = [],
        string $locale = '',
        Level $level = Level::Admin,
    ): string {
        $editable   = $relation->fromNodeId === $declaredBy;
        $settings   = $this->withModelValues($this->vonDenKanten([$relation])[$relation->id] ?? [], $relation);
        $configured = [];

        foreach ($this->settingsFor(
            $relation,
            $settings,
            Purpose::Edit,
            $fieldPrefix,
            $locale,
            $level,
            [],
            $formId,
            $editable
        ) as $drawn) {
            $configured[$drawn->key] = $drawn;
        }

        return $this->panelMarkup($relation, $configured, $words, $formId, $locale, $level, $editable);
    }

    /**
     * Dieselbe Frage mit den Kanten — und mit der Kette des **Gewählten**.
     *
     * ⚠️ **Zwei Lücken auf einmal, beide gemessen** ([D-682](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *(1) **Die Kette des Gewählten, nicht die des Kantenziels.** Sein Satz, der es entscheidet:
     * «aber das ist doch eine einstellung am renderer». `with_label` hängt an `render with label`,
     * `orientation` an `compact` — beide **unter** dem Kantenziel `Renderer`, und die Auskunft ging
     * vom Ziel nur nach oben. **Gemessen boten `Root`, `Model` und `Kontact` nur `converter` an**,
     * während `Parts List` alle drei zeigte — dort lag ein Teil, dessen Werte ohnehin schon
     * dastanden. Sein Wort dazu: «und nur weil ich ihn schon mal den rendere geändert habe wird er
     * richtig angezeigt».*
     *
     * ⚠️ *(2) **Ein Name ohne Aufzählungsfall kommt trotzdem durch.** `label_role` ist an der Kante
     * erklärt und war keiner der dreizehn Fälle in {@see SettingKey} — also fiel er lautlos heraus.
     * Was er ist, sagt jetzt seine eigene Kante ({@see ModelValues::declaredSettingEdges()}).*
     *
     * @return array<string, ?SettingKey> Name → sein Aufzählungsfall, oder `null`, wenn er keinen hat.
     */
    private function zutreffendeKanten(Node|Relation $node, ?SimpleType $subject, int $forNode = 0): array
    {
        $istKante = $node instanceof Relation;

        // ⚠️ **Seit Schritt 4 des Bauplans sagt der Vertrag, was es zu zeichnen gibt** ([D-712](90-decision-log.md),
        // Anforderung 2.4.2): *die Attribute der Knotenklasse, dazu die des gewählten Renderers —
        // keine Kante, kein Schlüssel. `null` als Wert heisst «kein Kantenobjekt», wie bei den Spalten.*
        $aus = [];

        if ($this->resolver !== null) {

            if ($istKante) {
                foreach (EdgeColumn::all() as $spalte) {
                    $aus[$spalte] = null;
                }
            }

            foreach (array_keys($this->attributesDrawnFor($node)) as $name) {
                $aus[$name] = null;
            }

            // ⚠️ *Die Hakenliste der erlaubten Kinder ([D-697](90-decision-log.md)) hängt noch an
            // einer Einstellungskante und ihren Sätzen — sie bleibt gezeichnet, bis Schritt 7 die
            // Kanten fallen lässt und `erlaubte_praefixe` (Anforderung 3.6.5) sie ablöst.*

            return $aus;
        }

        return $aus;
    }

    /**
     * Die Einstellungsbereich selbst — **eine Stelle, damit es eine Machart bleibt** (`R1`).
     *
     * ⚠️ *«wie oft» bleibt draussen: es hat seine eigene Spalte in der Zeile
     * ([D-351](../../../docs/NewConcept/90-decision-log.md)), und zweimal dasselbe Steuerelement ist
     * genau der Mangel, den [D-376](../../../docs/NewConcept/90-decision-log.md) gekostet hat.*
     *
     * ⚠️ *Kein `submits`, also **kein eigenes Formular**: die Steuerelemente nennen über `form="…"`
     * das Formular der Seite, und gespeichert wird oben
     * ([D-392](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * @param array<string, RenderedSetting> $configured
     * @param list<Control>                  $words
     */
    /**
     * Der Einstellungsbereich eines **Knotens** — seine Attribute aus dem Vertrag, mit dem gewählten
     * Renderer und dessen Attributen (Schritt 4/5 des Bauplans, [D-712](90-decision-log.md)).
     *
     * ⚠️ *Leer ohne Auflöser: dann zeichnet die Seite ihre Einstellungen noch als Feldzeilen der
     * Einstellungskanten, wie bis Schritt 4.*
     *
     * @param array<string, string> $words
     */
    public function settingsPanelForNode(
        Node $node,
        string $fieldPrefix = '',
        string $formId = '',
        array $words = [],
        string $locale = '',
        Level $level = Level::Admin,
    ): string {
        if ($this->resolver === null) {
            return '';
        }

        $configured = [];

        foreach ($this->settingsFor($node, $this->settingsForNode($node), Purpose::Edit, $fieldPrefix, $locale, $level, [], $formId) as $drawn) {
            $configured[$drawn->key] = $drawn;
        }

        return $this->panelMarkup($node, $configured, $words, $formId, $locale, $level, true);
    }

    private function panelMarkup(
        Node|Relation $relation,
        array $configured,
        array $words,
        string $formId,
        string $locale,
        Level $level,
        bool $editable,
    ): string {
        // ⚠️ *«Wie oft» und die Art stehen in eigenen Zellen der Feldzeile; `read_only` bleibt im
        // Bereich, als Schalter ([D-714](90-decision-log.md)).*
        unset($configured[EdgeColumn::MULTIPLICITY], $configured[self::KIND_KEY]);

        if ($configured === []) {
            return '';
        }

        return $this->renderers->byName(SettingsRenderer::NAME)->render(
            $relation,
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                editable: $editable,
                surroundings: new Surroundings(
                    actions: $words,
                    configured: $configured,
                    formId: $formId,
                ),
            )
        )->markup;
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
        /**
         * ⚠️ **Welche Schluessel ueberhaupt gezeichnet werden — leer heisst «alle».**
         *
         * *Das ist der Ort, an dem [D-666](../../../docs/NewConcept/90-decision-log.md)s «nicht
         * gelesen» wirksam wird: eine **zugeklappte** Feldzeile braucht genau einen Schluessel,
         * «wie oft», und der steht als Spalte an der Kante selbst. Ohne diese Schranke materialisiert
         * die Schleife unten jeden Schluessel, der auf das Ziel zutrifft, und zeichnet ihn — dreizehn
         * Steuerelemente je Zeile, die niemand sieht.*
         *
         * @var list<string>
         */
        array $onlyKeys = [],
        /**
         * ⚠️ **Der Knoten, an dem diese Zeile steht** — *für die Kette des Gewählten
         * ([D-682](../../../docs/NewConcept/90-decision-log.md)). Ohne ihn lässt sich nicht fragen,
         * welcher Renderer hier **gilt**, und die Einstellungen eines geerbten bleiben unsichtbar.*
         */
        int $forNode = 0,
    ): array {
        // ⚠️ **A use site is configured too, and its type is its target's.** [C8](../../../docs/NewConcept/10-domain-core.md)
        // gives an relation settings of its own and [D-091](90-decision-log.md) resolves them the same
        // way; what differs is only where the type comes from — a node *is* the type, an relation
        // *points* at it. *Until the attribute renderer wanted the multiplicity drawn, nothing had
        // ever asked this method about an relation, so the narrower signature had never been wrong.*
        $subject = $node instanceof Relation ? $this->typeAt($node) : $this->typeOfNode($node);

        // ⚠️ **Every key that applies, not only the ones somebody wrote.** The owner, on an `int`
        // node whose chain was empty: *the settings that belong firmly to the data type — min,
        // max, step — should be shown as such.* An unset key becomes a row with an empty control
        // and `setHere = false`, which is the truth about it: nothing along the chain has said.
        //
        // ⚠️ **Aber «zutreffend» sagt das Modell und nicht der Kode** — *sein Befund am 2026-09-06:
        // «auch scheinen es einfach alle Einstellungen zu sein, nicht nur die vom Typ Text».
        // {@see SettingKey::applyingTo()} beantwortet die Frage «lässt sich dafür ein Steuerelement
        // zeichnen», und das lässt sich für fast jeden Schlüssel — deshalb standen `min`, `max`,
        // `step`, `factor` und `offset` an einem Textfeld. **Zutreffend ist, was an der Kette als
        // Kante erklärt ist** ({@see ModelValues::declaredSettingKeys()}), und das ist der Weg, den
        // [D-529](../../../docs/NewConcept/90-decision-log.md) vorschreibt.*
        //
        // ⚠️ *Ohne `ModelValues` bleibt es bei der alten Auskunft: eine Vorschau ohne Modellzugang
        // hat keine Kette zu fragen, und eine leere Einstellungsbereich wäre dort die falschere Antwort.*
        //
        // ⚠️ **`multiplicity` applies only to an relation** and is the one key that does (D-351) — a
        // node describes a thing, and a thing has no multiplicity. *Sie hängt als **Spalte** an der
        // Kante und ist deshalb nirgends als Kante erklärt — sie käme aus dem Modell nie zurück.*
        // ⚠️ *Über die **Namen** und nicht über die Aufzählung: ein im Modell erklärter Schlüssel
        // ohne Fall in {@see SettingKey} soll eine Zeile bekommen wie jeder andere
        // ([D-682](../../../docs/NewConcept/90-decision-log.md)).*
        $kanten = $this->zutreffendeKanten($node, $subject, $forNode);

        foreach (array_keys($kanten) as $name) {
            $resolved[$name] ??= new ResolvedSetting($name, TypedValue::nothing(), 0, false);
        }

        // ⚠️ *Die Schranke wirkt **nach** dem Auffüllen und nicht davor: was der Aufrufer verlangt,
        // soll er auch dann bekommen, wenn niemand es geschrieben hat — eine leere Zeile ist die
        // Wahrheit über den Schlüssel ([D-266](90-decision-log.md)).*
        if ($onlyKeys !== []) {
            $resolved = array_intersect_key($resolved, array_flip($onlyKeys));
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
            // ⚠️ **Die zwei Spalten der Kante zuerst** ([D-713](90-decision-log.md), [D-714](90-decision-log.md)):
            // *keine Schlüssel, keine Kanten — Spalten, und als solche gezeichnet. Nur «wie oft» folgt
            // dem Besitzer der Kante ([D-376](90-decision-log.md)); `read_only` gehört der Stelle.*
            if ($node instanceof Relation && $key === EdgeColumn::MULTIPLICITY) {
                $drawn[] = $this->drawMultiplicity($node, $setting, $purpose, $fieldPrefix, $locale, $level, $subject, $formId, $editable);

                continue;
            }

            if ($node instanceof Relation && $key === EdgeColumn::READ_ONLY) {
                $drawn[] = $this->drawEdgeSwitch($node, $key, $setting, $purpose, $fieldPrefix, $locale, $level, $subject, $formId);

                continue;
            }

            $shape = SettingShape::Words;

            // ⚠️ **A choice is drawn now, by the choice renderer** — it was one of three rows that
            // came back undrawn, and the honest reason was *a chooser wants a set and none is
            // built*. It is built, so the reason is gone. *The other two remain honest: a free key
            // has no type the engine can know, and a borrowing key on a subject with no type of its
            // own has no shape to be drawn in.*

            // A free key, or a borrowed type the subject does not have. Nothing is drawn, and the
            // caller is told which of the two it is by the shape.
            // ⚠️ **Ein im Modell erklärter Schlüssel ist ein Feld und wird als eines gezeichnet**
            // ([D-529](../../../docs/NewConcept/90-decision-log.md)). *Sein Bau: «knoten -> relation
            // typ setting -> type». **Eine Einstellung *ist* eine Kante auf einen Typ**, also weiss
            // ihre eigene Kante, was zu zeichnen ist — `label_role` zeigt auf `Label roles` und wird
            // eine Auswahl, `with_label` auf `Boolean` und wird ein Schalter.*
            //
            // ⚠️ **Hier stand eine leere Zeile für jeden Namen, den die Aufzählung nicht kennt**, und
            // das war die Lücke, die [D-682](../../../docs/NewConcept/90-decision-log.md) offenliess:
            // *`label_role` stand als **Beschriftung ohne Bedienelement** da, obwohl das Modell die
            // Einstellung erklärt. **Eine geschlossene Liste von dreizehn Namen entschied, was
            // bedienbar ist** — gegen [D-529](../../../docs/NewConcept/90-decision-log.md).*
            // ⚠️ **Ein Attribut aus dem Vertrag wird aus dem Vertrag gezeichnet** (Schritt 4 des
            // Bauplans, [D-712](90-decision-log.md)): *Typ, Wahl und Vorgabe kennt die Erklärung;
            // die Zeile bringt den Wert. Keine Kante, kein Schlüssel.*
            if ($this->resolver !== null && ($erklaert = $this->attributesDrawnFor($node)[$key] ?? null) !== null) {
                $drawn[] = $this->drawAttribute($node, $erklaert, $setting, $purpose, $fieldPrefix, $locale, $level, $subject, $formId);

                continue;
            }

            $kante = $kanten[$key] ?? null;

            // ⚠️ **Die Liste der erlaubten Kinder — eine Hakenliste über dem Angebot des Feldes**
            // ([D-697](../../../docs/NewConcept/90-decision-log.md)). *Erkannt an der Struktur: eine
            // Einstellungskante auf `Node reference` mit mehreren Werten, an einer Verwendungsstelle
            // mit Kindern. Alle an heisst «nichts gespeichert, alle erlaubt»; abwählen verengt.
            // Gespeichert werden die erlaubten, an der Adresse Knoten × Kante.*

            // ⚠️ **Woher der Wert kommt, sagt die Auflösung am *Knoten*** — *nicht die an der
            // Kante ([D-684](../../../docs/NewConcept/90-decision-log.md)). Der Aufrufer reicht
            // die Einstellungen der **Verwendungsstelle** herein, und die melden «hier
            // gesetzt» für alles, was sie kennen. **Damit fehlte der Pfeil «geerbt»**, und
            // «zurücksetzen» stand an einer Zeile, an der es nichts zurückzusetzen gibt.*
            if ($kante !== null && $forNode !== 0) {
                $knotenHier = $this->nodes->find($forNode);

                if ($knotenHier !== null) {
                    $setting = $this->withRendererValues($knotenHier)[$key] ?? $setting;
                }
            }

            $gezeichnet = $kante === null
                ? []
                : $this->fieldsFor(
                    [$kante],
                    [$kante->id => $setting->value],
                    $purpose,
                    $fieldPrefix === '' ? '' : $fieldPrefix,
                    $locale,
                    $level,
                    true,
                    $formId
                );

            $feld = $gezeichnet[0] ?? null;

            $drawn[] = new RenderedSetting(
                $key,
                $shape,
                $feld?->type,
                $setting,
                $feld?->result,
                $feld?->rendererName,
                $subject
            );

        }

        // ⚠️ **Herkunft in Worten, und das Feld für «hier überschreibe ich»**
        // ([D-689](../../../docs/NewConcept/90-decision-log.md)). *Der Name des Kettenglieds kommt aus
        // dem Speicher, weil ein Renderer nichts holt ([D-159](../../../docs/NewConcept/90-decision-log.md));
        // das Überschreib-Feld heisst wie das Steuerelement, mit `_override` am Wortstamm — so findet
        // der Rand beide unter derselben Adresse. Ein Aufrufer ohne Vorsatz zeigt nur, und bekommt
        // kein Feld.*
        foreach ($drawn as $i => $eine) {
            $traeger = $kanten[$eine->key] ?? null;
            $frei    = $traeger !== null;
            $name    = $fieldPrefix === ''
                ? ''
                : $fieldPrefix . '[' . ($frei ? $traeger->id : $eine->key) . ']';
            $von     = $eine->setting->isInherited() && $eine->setting->fromOwnerId !== 0
                ? (string) ($this->nodes->find($eine->setting->fromOwnerId)?->name ?? '')
                : '';

            $drawn[$i] = $eine->withOrigin(
                $von,
                $name === '' ? '' : (string) preg_replace('/^([A-Za-z_]+)/', '$1_override', $name)
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
     * @param list<Relation>        $relations
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
     * relation: a default written at the type is exactly the one a preview should show.
     *
     * @param  list<Relation>                                    $relations
     * @param  array<int, array<string, ResolvedSetting>>         $resolved Settings per relation id.
     * @param  array<int, TypedValue>                             $held     What a record holds, if any.
     * @return array<int, TypedValue>                                       Keyed by relation id.
     */

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
     * ⚠️ **Ein *leerer* `default` zählt seit [D-653](../../../docs/NewConcept/90-decision-log.md)
     * nicht mehr als vorhanden.** *Sein Beschluss: «Wenn es ein default gibt und der gefuellt ist,
     * soll er den zum Rendern verwenden, ansonsten einen example-Satz.» **Und der Anlass ist gemessen:**
     * die Vorschau sagte «Filled from record #4756» und zog ihre Anzeige aus dem `default`-Satz —
     * **ein leerer Satz gewann also gegen ein gefülltes Beispiel und zeigte nichts.** Von 454
     * `default`-Sätzen waren 390 leer.*
     *
     * ⚠️ *Nur der `default` wird so geprüft, und das ist Absicht: ein leerer `user`-Satz ist eine
     * **Eingabe, die noch leer ist**, und die darf gezeichnet werden. Ein leerer `default` ist eine
     * Vorgabe, die nichts vorgibt.*
     *
     * @param  list<NodeRecord> $records
     * @param  list<int>        $filled Ids der Sätze, die mindestens eine Wertzeile tragen
     *                                  ({@see \Taxmod\Core\Service\DataEntry::filledAmong()}).
     */
    public function previewRecordAmong(array $records, array $filled = []): ?NodeRecord
    {
        $marked = null;

        foreach ($records as $record) {
            // ⚠️ *Die Reihenfolge ist **unverändert**: was nicht als Testdaten markiert ist, geht vor.
            // **Wo ein Autoren-Datensatz einzuordnen ist, ist nicht entschieden**
            // ([D-521](../../../docs/NewConcept/90-decision-log.md)) — er zählt darum vorerst wie eine
            // gewöhnliche Eingabe, was genau das ist, was `is_test = 0` bisher tat.*
            if ($record->recordType === RecordType::Example) {
                $marked ??= $record;

                continue;
            }

            // ⚠️ *Ein Einstellungssatz ist kein Eintrag und wird nie vorgeschaut ([D-704](../../../docs/NewConcept/90-decision-log.md)).*
            if ($record->recordType === RecordType::Settings) {
                continue;
            }

            if ($record->recordType === RecordType::Default && ! in_array($record->id, $filled, true)) {
                continue;
            }

            return $record;
        }

        return $marked;
    }

    public function previewValuesFor(array $relations, array $resolved, array $held = []): array
    {
        $values = [];

        foreach ($relations as $relation) {
            // ⚠️ **Real data wins, and the rung between it and the defaults is
            // {@see previewRecordAmong()}** — the caller has already chosen *which* record the
            // values came from, so what is left here is the decided *«fällt auf die Vorgaben
            // zurück, wo keine da sind»* of [D-028](90-decision-log.md).
            if (isset($held[$relation->id]) && ! $held[$relation->id]->isNothing()) {
                $values[$relation->id] = $held[$relation->id];

                continue;
            }

        }

        return $values;
    }

    /**
     * Which relations a preview may leave out, and which it must draw dead rather than absent.
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
     * @param  list<Relation>                            $relations
     * @param  array<int, array<string, ResolvedSetting>> $resolved
     * @return array{shown: list<Relation>, hidden: list<Relation>, settings: list<Relation>, fixed: list<int>}
     */
    public function previewVisibilityFor(array $relations, array $resolved): array
    {
        $shown    = [];
        $hidden   = [];
        $settings = [];
        $fixed    = [];

        foreach ($relations as $relation) {
            $keys = $resolved[$relation->id] ?? [];

            // ⚠️ `($a['x'] ?? null)?->y` and **not** `$a['x']?->y` — the second is a warning on a
            // missing key, which is a bug this file's own docblock warns about and which was written
            // two files away on 2026-08-26.
            // ⚠️ *The relation's own column ([D-457](90-decision-log.md)) — no chain, no resolution.*
            if ($relation->hide) {
                $hidden[] = $relation;

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
            if ($relation->isSetting()) {
                $settings[] = $relation;

                continue;
            }

            $shown[] = $relation;

            if ((($keys[EdgeColumn::READ_ONLY] ?? null)?->value->asBool() ?? false) === true) {
                $fixed[] = $relation->id;
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
        array $relations,
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
        $parts = $this->fieldsFor($relations, $values, $purpose, $fieldPrefix, $locale, $level, $editable, '', 0, [], [$node->id => true]);

        $container = $containerName === ''
            ? $this->containerFor($node, $purpose)
            : $this->renderers->byName($containerName);

        return $container->render(
            $node,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                // ⚠️ **Hier stand nichts, und das war der ganze Fehler.** *Der Behälter wurde aus dem
                // Modell **gewählt** und dann ohne eine einzige Angabe gerufen — er konnte seine Achse
                // nicht kennen, weil ihm niemand etwas sagte.*
                settings: $this->withRendererValues($node),
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
     * @param  list<Relation> $relations
     * @return array<int, array<string, \Taxmod\Core\Model\ResolvedSetting>> Kanten-Id => Angaben
     */
    public function settingsForUseSites(array $relations): array
    {
        $resolved = $this->vonDenKanten($relations);

        // ⚠️ *Alle Ketten auf einmal, bevor die erste gelesen wird (`CD-7`,
        // [D-602](../../../docs/NewConcept/90-decision-log.md)). Sieben Felder eines Formulars zeigen
        // auf sieben Typen, deren Vorfahren sich fast vollständig überschneiden — je Feld nachzusehen
        // wäre linear in der Zahl der Felder, und genau das misst `einstellungen-check.php`.*
        $this->resolver?->preloadUseSites($relations);

        $aus = [];

        foreach ($relations as $relation) {
            $aus[$relation->id] = $this->withModelValues($resolved[$relation->id] ?? [], $relation);
        }

        return $aus;
    }

    /**
     * Die Angaben, mit denen der **gewählte Renderer** zeichnet — seine eigenen unter denen des
     * Gezeichneten.
     *
     * ⚠️ **Sein Befund, zweimal gemeldet:** *«compact mit horizontal und ohne Label gewählt, aber
     * gerendert wird vertikal».* **Gemessen war der Zeichenkontext dieser Behälter leer** — hier stand
     * gar keine Angabe, und {@see \Taxmod\Core\Service\ModelValues::forChosenRenderer()} erklärt, warum
     * auch die Kette des Knotens die drei nicht kennt: sie hängen am Satz des Renderers.
     *
     * ⚠️ **Der Gezeichnete gewinnt, wo beide sprechen.** *Näher schlägt ferner, wie überall in der
     * Kette — und praktisch kollidiert heute nichts: `orientation` ist an `compact` erklärt, also kann
     * ein gewöhnlicher Knoten sie gar nicht tragen.*
     *
     * @return array<string,\Taxmod\Core\Model\ResolvedSetting>
     */
    private function withRendererValues(Node $subject): array
    {
        // ⚠️ **Seit Schritt 4 des Bauplans aus der Auflösung** ([D-712](90-decision-log.md)):
        // *der gewählte Renderer und seine Attribute kommen aus `settings_value` und dem Vertrag —
        // die Einstellungskanten werden nicht mehr gelesen, solange ein Auflöser da ist.*
        return $this->resolver === null ? [] : $this->resolver->forNode($subject);
    }

    private function withModelValues(array $resolved, Node|Relation $subject): array
    {
        if ($this->resolver !== null) {
            $ausDerAufloesung = $subject instanceof Node
                ? $this->resolver->forNode($subject)
                : $this->resolver->forUseSite($subject);

            return [...$resolved, ...$ausDerAufloesung];
        }

        return $resolved;
    }

    /**
     * Der gewählte Renderer als Name — leer, wo nur die Vorgabe der Auflösung steht (kein Besitzer, nicht hier
     * gesetzt): *die Vorgabe zeigt die Maske, damit der Renderer nie leer ist (sein Wort, 2026-09-11); gezeichnet
     * wird dann, wie Typ und Zweck es sagen — genau wie ohne Wahl.*
     */
    private function chosenRendererName(array $settings): string
    {
        $wahl = $settings['renderer'] ?? null;

        if (! $wahl instanceof ResolvedSetting || ($wahl->fromOwnerId === 0 && ! $wahl->setHere)) {
            return '';
        }

        return (string) ($wahl->value->text ?? '');
    }

    private function containerFor(Node $node, Purpose $purpose): Renderer
    {
        // ⚠️ **Ein Einstellungsknoten wird als Tabelle gezeichnet** ([D-546](../../../docs/NewConcept/90-decision-log.md)),
        // *dieselbe Regel wie für eine Einstellungskante, eine Ebene höher: er ist dasselbe Ding, nur
        // von aussen betrachtet. **Und er hat keine Renderer-Einstellung** — seit
        // [D-545](../../../docs/NewConcept/90-decision-log.md) erbt nichts im Settings-Ast von der
        // Wurzel, also gäbe es unten nichts zu lesen und `form` käme als stille Vorgabe heraus.*
        // ⚠️ **Die Auskunft kommt aus der Kante, nicht mehr aus einer Spalte am Knoten**
        // ([D-621](../../../docs/NewConcept/90-decision-log.md)): *«die Kante sagt, was etwas hier
        // ist.» Ein Knoten, den nur Vererbung erreicht — die neunzehn Renderer unter `Renderer` —,
        // bekommt seinen Charakter von der Kante über seinem nächsten Vorfahren.*

        // ⚠️ **Auch aus den Datensätzen, und ohne dies war die Wahl wirkungslos** ([D-529](../../../docs/NewConcept/90-decision-log.md)).
        // *Hier stand nur die Auflösung über die `settings`-Tabelle. Der Renderer liegt seit dem Umzug
        // im Datensatz — also hätte der Eigentümer `table` wählen können und weiter ein Formular
        // gesehen. **Fünfter Fall derselben Sache an einem Tag:** Daten umgezogen, ein Leser
        // stehengeblieben.*
        $chosen = $this->chosenRendererName($this->withModelValues([], $node));

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
        /** @var array<int, string> Je Knoten der übersetzte Name seiner Klasse — leer heisst: nicht anschreiben. */
        array $classLabels = [],
    ): array {
        if ($nodes === []) {
            return [];
        }

        $settings = $this->vonDenKnoten($nodes);
        $renderer = $this->renderers->byName($cell);

        $cells = [];

        foreach ($nodes as $node) {
            $eigene = $settings[$node->id] ?? [];

            // ⚠️ **Das Icon der Klasse, wo der Knoten keines trägt** ([D-723](90-decision-log.md)):
            // *«jede klasse nennt im vertrag ein icon, der baum zeichnet es; ein label-icon am knoten
            // geht vor.» Der Vertrag ist Code und wird einmal gelesen — keine Abfrage je Zeile.*
            if (($eigene['icon'] ?? null)?->value->text === null || ($eigene['icon']?->value->text ?? '') === '') {
                $eigene['icon'] = new ResolvedSetting(
                    'icon',
                    TypedValue::ofText(\Taxmod\Core\Model\NodeClass\Contracts::of($node->klasse)->icon),
                    0,
                    false
                );
            }

            $cells[$node->id] = $renderer->render(
                $node,
                new RenderContext(
                    purpose: Purpose::Display,
                    value: TypedValue::nothing(),
                    settings: $eigene,
                    locale: $locale,
                    level: $level,
                    editable: false,
                    surroundings: new Surroundings(
                        actions: $actions[$node->id] ?? [],
                        href: $hrefs[$node->id] ?? null,
                        submits: $submits[$node->id] ?? null,
                        // ⚠️ *Prepared, not asked: a cell draws a **node** and `hide` sits on its
                        // **relation** ([D-467](90-decision-log.md), [D-445](90-decision-log.md)).*
                        hidden: $hidden[$node->id] ?? false,
                        classLabel: $classLabels[$node->id] ?? ''
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
        /**
         * Wessen Baum es wäre, wenn keine Zeile übrig bleibt.
         *
         * ⚠️ **Nur für den leeren Fall mit Suchfeld** ([D-694](90-decision-log.md)). *Der Zeichner
         * braucht ein Subjekt, und ohne Zeile gibt es keines — dabei muss das Feld stehen bleiben,
         * sonst kommt man aus einer erfolglosen Suche nicht mehr heraus. **Er wird nie gezeichnet**:
         * der Baumzeichner rührt sein Subjekt nicht an, er nestet Zeilen.*
         */
        ?Node $leer = null,
        /** @var array<int, string> Je Knoten der übersetzte Name seiner Klasse ([D-716](90-decision-log.md)). */
        array $classLabels = [],
    ): RenderResult {
        if ($walked === [] && ($filterName === '' || $leer === null)) {
            return RenderResult::of('');
        }

        $nodes = $walked === []
            ? [$leer]
            : array_map(static fn (array $row): Node => $row['node'], $walked);
        // ⚠️ *The rows already carry it — {@see \Taxmod\Core\Service\Tree::rowsUnder()} reads it off
        // the inheritance relations it loads anyway ([D-467](90-decision-log.md)). No parameter at the
        // boundary, and no query here.*
        $hidden = [];

        foreach ($walked as $row) {
            $hidden[$row['node']->id] = $row['hidden'] ?? false;
        }

        $cells = $this->cellsFor($nodes, $actions, $hrefs, $submits, $cell, $locale, $level, $developerMode, $hidden, $classLabels);

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
        return $this->renderers->byName(PageRenderer::NAME)->render(
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
    public function typeAt(Relation $relation): ?SimpleType
    {
        return $this->typesFor([$relation])[$relation->id] ?? null;
    }

    /**
     * The same question for a whole form's worth of attributes, in two queries.
     *
     * ⚠️ **Public because writing needs it too.** A form comes back as characters and each one has
     * to be read as its own type; asking per field would be a query per field on **save** as well
     * as on draw, which is the loop `CD-7` forbids either way round.
     *
     * @param  list<Relation> $relations
     * @return array<int, SimpleType|null>
     */
    public function typesFor(array $relations): array
    {
        return $this->typesOf($relations);
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
    /**
     * Die Attribute, die der Einstellungsbereich für dieses Subjekt zeichnet: die der Knotenklasse,
     * dazu die des gewählten Renderers (Anforderung 2.4.2, 3.6.3) — aus dem Vertrag, nie aus Kanten.
     *
     * ⚠️ *Für eine Verwendungsstelle ist es der Vertrag des **Ziels**: die Kante hat keine eigenen
     * Attribute ([D-714](90-decision-log.md)), sie überschreibt die des Knotens.*
     *
     * @return array<string, \Taxmod\Core\Model\NodeClass\AttributeDeclaration>
     */
    private function attributesDrawnFor(Node|Relation $node): array
    {
        if ($this->resolver === null) {
            return [];
        }

        $knoten = $node instanceof Node ? $node : $this->nodes->find($node->toNodeId);

        if ($knoten === null) {
            return [];
        }

        $aus = $this->resolver->contractOf($knoten)->attributes;

        // ⚠️ *Die Attribute des gewählten Renderers stehen daneben — `orientation`, `with_label`,
        // `label_role` —, wie {@see ModelValues::forChosenRenderer()} sie vorher lieferte.*
        // ⚠️ *Und die Attribute jedes gewählten Objekts — des Renderers wie des Umrechnungssatzes — unter
        // ihrem eigenen Namen (Schritt 7 des Bauplans).*
        foreach ($this->resolver->chosenObjectClasses($knoten, $node instanceof Relation ? $node : null) as $klasse) {
            foreach (\Taxmod\Core\Model\NodeClass\Contracts::ofValueClass($klasse)->attributes as $name => $erklaert) {
                $aus[$name] ??= $erklaert;
            }
        }

        return $aus;
    }

    /**
     * Ein Attribut aus dem Vertrag zeichnen — nach seinem Typ (Anforderung 3.1): eine Zahl als
     * Zahlenfeld, ein Schalter als Schalter, ein Enum, ein Verweis und ein Objekt als Wahl.
     *
     * ⚠️ *Was die Wahl anbietet, sagt die Erklärung: die Fälle des Enums, die Knoten der
     * Verweisklasse, die Namen der Registratur. **Eine Liste zeichnet ihr erstes Glied** — mehr
     * kommt mit Schritt 5, wenn geschrieben wird.*
     */
    private function drawAttribute(
        Renderable $subject,
        \Taxmod\Core\Model\NodeClass\AttributeDeclaration $erklaert,
        ResolvedSetting $setting,
        Purpose $purpose,
        string $fieldPrefix,
        string $locale,
        Level $level,
        ?SimpleType $subjectType,
        string $formId,
    ): RenderedSetting {
        $key       = $erklaert->name;
        $fieldName = $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $key . ']';
        $typ       = $erklaert->type;

        [$shape, $simple] = match ($typ) {
            \Taxmod\Core\Model\NodeClass\AttributeType::Bool    => [SettingShape::Switch, SimpleType::Bool],
            \Taxmod\Core\Model\NodeClass\AttributeType::Int     => [SettingShape::Whole, SimpleType::Int],
            \Taxmod\Core\Model\NodeClass\AttributeType::Decimal => [SettingShape::Exact, SimpleType::Decimal],
            \Taxmod\Core\Model\NodeClass\AttributeType::Text    => [SettingShape::Words, SimpleType::Text],
            default                                             => [SettingShape::ARegisteredName, null],
        };

        if ($simple !== null) {
            $renderer = $this->renderers->defaultFor($simple);

            return new RenderedSetting(
                $key,
                $shape,
                $simple,
                $setting,
                $renderer->render($subject, new RenderContext(
                    purpose: $purpose,
                    value: $setting->value,
                    settings: [],
                    locale: $locale,
                    level: $level,
                    editable: true,
                    fieldName: $fieldName,
                    type: $simple,
                    surroundings: new Surroundings(formId: $formId),
                )),
                $renderer->name(),
                $subjectType
            );
        }

        $options = match ($typ) {
            \Taxmod\Core\Model\NodeClass\AttributeType::Enum    => array_combine($erklaert->enumCases(), $erklaert->enumCases()),
            \Taxmod\Core\Model\NodeClass\AttributeType::NodeRef => $this->nodesOffered($erklaert),
            default                                             => $this->objectsOffered($erklaert, $subject),
        };

        $renderer  = $this->renderers->byName(ChoiceRenderer::NAME);
        $gezeichnet = $renderer->render($subject, new RenderContext(
            purpose: $purpose,
            value: $setting->value,
            settings: [],
            locale: $locale,
            level: $level,
            editable: true,
            fieldName: $fieldName,
            surroundings: new Surroundings(options: $options, mayBeNothing: $setting->value->isNothing() || $typ !== \Taxmod\Core\Model\NodeClass\AttributeType::Object || $key !== 'renderer', formId: $formId),
        ));

        if ($erklaert->list && $fieldPrefix !== '') {
            $gezeichnet = new RenderResult($gezeichnet->markup . $this->listMarkup($subject, $erklaert, $fieldPrefix, $formId), $gezeichnet->usedRelations, $gezeichnet->condition);
        }

        return new RenderedSetting($key, $shape, null, $setting, $gezeichnet, $renderer->name(), $subjectType);
    }

    /**
     * Die Glieder einer Liste unter ihrem Wähler: je Glied ein Schalter «an» und seine Stelle
     * (Schritt 6 des Bauplans, Z3/Z3a). Der Wähler oben setzt das erste Glied; die Zeilen darunter
     * schalten und ordnen, was steht — auch ein geerbtes Glied an der Kante.
     *
     * *Die Felder heissen `<prefix>_list[<attribut>][<zeile>][aktiv|position]`; aus
     * `taxmod_field_setting[7]` wird `taxmod_field_setting_list[7]` — dieselbe Adresse, ein Wort weiter.*
     */
    private function listMarkup(Renderable $subject, \Taxmod\Core\Model\NodeClass\AttributeDeclaration $erklaert, string $fieldPrefix, string $formId): string
    {
        if ($this->resolver === null || ! ($subject instanceof Node || $subject instanceof Relation)) {
            return '';
        }

        $knoten = $subject instanceof Node ? $subject : $this->nodes->find($subject->toNodeId);

        if ($knoten === null) {
            return '';
        }

        $glieder = $this->resolver->listOf($knoten, $erklaert->name, $subject instanceof Relation ? $subject : null);

        if ($glieder === []) {
            return '';
        }

        $prefix = (string) preg_replace('/^([A-Za-z0-9_]+)/', '$1_list', $fieldPrefix, 1) . '[' . $erklaert->name . ']';
        $form   = $formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"';
        $html   = '<ul class="taxmod-setting-list">';

        foreach ($glieder as $glied) {
            $name  = $prefix . '[' . $glied->rowId . ']';
            $html .= '<li class="taxmod-setting-list-entry' . ($glied->setHere ? '' : ' taxmod-setting-list-inherited') . '">'
                . '<input type="hidden" name="' . RenderResult::escape($name) . '[aktiv]" value="0"' . $form . '>'
                . '<label><input type="checkbox" name="' . RenderResult::escape($name) . '[aktiv]" value="1"' . ($glied->aktiv ? ' checked' : '') . $form . '> '
                . RenderResult::escape($glied->word) . '</label> '
                . '<input type="number" class="taxmod-setting-list-position" name="' . RenderResult::escape($name) . '[position]" value="' . $glied->position . '" min="0" step="1"' . $form . '>'
                . '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * Die Knoten, die ein Verweisattribut anbietet: alle der Verweisklasse, nach Namen.
     *
     * @return array<string, string> Name ⇒ Name — der Wert einer Zeile ist der Verweis, gezeichnet wird das Wort (D-105)
     */
    private function nodesOffered(\Taxmod\Core\Model\NodeClass\AttributeDeclaration $erklaert): array
    {
        if ($erklaert->refersTo === null) {
            return [];
        }

        $aus = [];

        foreach ($this->nodes->ofClass($erklaert->refersTo) as $knoten) {
            $aus[$knoten->name] = $knoten->name;
        }

        return $aus;
    }

    /**
     * Die Wertklassen, die ein Objektattribut anbietet — aus der Registratur, die zur Klasse passt.
     *
     * @return array<string, string>
     */
    private function objectsOffered(\Taxmod\Core\Model\NodeClass\AttributeDeclaration $erklaert, Renderable $subject): array
    {
        $aus = [];

        if ($erklaert->objectClass === Renderer::class) {
            foreach ($this->choicesForWhatIsDrawn($subject) as $one) {
                $aus[$one->name()] = $one->name();
            }

            return $aus;
        }

        // ⚠️ *Eine feste Wertklasse — der Umrechnungssatz — wird unter ihrem Kurznamen angeboten.*
        if (! interface_exists($erklaert->objectClass) && class_exists($erklaert->objectClass)) {
            $kurz = \Taxmod\Core\Model\NodeClass\Contracts::shortName($erklaert->objectClass);

            return [$kurz => $kurz];
        }

        if ($erklaert->objectClass === Converter::class && $this->converters !== null) {
            $forType = $subject instanceof Relation ? $this->typeAt($subject) : ($subject instanceof Node ? $this->typeOfNode($subject) : null);

            foreach ($this->converters->eligibleFor($forType) as $one) {
                $aus[$one->name()] = $one->name();
            }

            return $aus;
        }

        // ⚠️ *Validatoren: keine Registratur im Zeichner, und heute prüft ohnehin keiner beim
        // Schreiben — das Attribut steht im Vertrag, die Wahl kommt, wenn Schritt 5 schreibt.*
        return $aus;
    }

    /**
     * «Wie oft» — die Spalte `multiplicity` der Kante, als Wahl aus den vier Werten gezeichnet
     * ([D-713](90-decision-log.md)).
     *
     * ⚠️ **Nie nichts** (D-379): *der Wähler bietet keine leere Zeile an; ungesetzt liest sich die
     * Vorgabe.* ⚠️ **Die Zielklasse schränkt ein** (Modell 1.2.3): *ein `bool` bekommt nur `1..1`
     * angeboten — sein Wort «bool = 1..1, an knotenklasse bool». Gefragt wird der Vertrag der
     * Zielklasse und keine Liste hier.* ⚠️ *Nur «wie oft» folgt dem Besitzer der Kante
     * ([D-376](90-decision-log.md)): eine geerbte Kante gehört dem Vorfahren, also ist der Wähler
     * dort gesperrt.*
     */
    private function drawMultiplicity(
        Relation $subject,
        ResolvedSetting $setting,
        Purpose $purpose,
        string $fieldPrefix,
        string $locale,
        Level $level,
        ?SimpleType $subjectType,
        string $formId,
        bool $editable,
    ): RenderedSetting {
        $setting = new ResolvedSetting(
            EdgeColumn::MULTIPLICITY,
            TypedValue::ofText(Multiplicity::fromSetting($setting->value->text)->value),
            $setting->fromOwnerId,
            $setting->setHere
        );

        $ziel    = $this->nodes->find($subject->toNodeId);
        $vertrag = $ziel === null ? null : \Taxmod\Core\Model\NodeClass\Contracts::of($ziel->klasse);
        $options = [];

        foreach (Multiplicity::cases() as $one) {
            if ($vertrag !== null && ! $vertrag->allowsMultiplicity($one)) {
                continue;
            }

            // ⚠️ The **notation**, deliberately not translated — `0..1` is not English and
            // survives a locale change without a label.
            $options[$one->value] = $one->notation();
        }

        $renderer = $this->renderers->byName(ChoiceRenderer::NAME);

        return new RenderedSetting(
            EdgeColumn::MULTIPLICITY,
            SettingShape::OneOfFour,
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
                    fieldName: $fieldPrefix === '' ? '' : $fieldPrefix . '[' . EdgeColumn::MULTIPLICITY . ']',
                    editable: $editable,
                    surroundings: new Surroundings(options: $options, mayBeNothing: false, formId: $formId)
                )
            ),
            $renderer->name(),
            $subjectType
        );
    }

    /**
     * Ein Schalter der Kante — heute `read_only` ([D-714](90-decision-log.md)) — als Schiebeschalter.
     *
     * ⚠️ *Eine Spalte, kein Schlüssel und keine Kante: die Angabe kommt aus {@see vonDenKanten()},
     * das Feld heisst wie die Spalte, und der Rand schreibt sie in die Spalte zurück
     * ({@see \Taxmod\WordPress\Admin\NodesScreen::saveField()}). Der Schalter schickt `0` oder `1`
     * ([D-370](90-decision-log.md)), also gibt es kein «nichts».*
     */
    private function drawEdgeSwitch(
        Relation $subject,
        string $column,
        ResolvedSetting $setting,
        Purpose $purpose,
        string $fieldPrefix,
        string $locale,
        Level $level,
        ?SimpleType $subjectType,
        string $formId,
    ): RenderedSetting {
        $renderer = $this->renderers->byName(ToggleRenderer::NAME);

        return new RenderedSetting(
            $column,
            SettingShape::Switch,
            SimpleType::Bool,
            $setting,
            $renderer->render(
                $subject,
                new RenderContext(
                    purpose: $purpose,
                    value: TypedValue::ofBool($setting->value->asBool()),
                    settings: [],
                    locale: $locale,
                    level: $level,
                    fieldName: $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $column . ']',
                    type: SimpleType::Bool,
                    editable: true,
                    surroundings: new Surroundings(formId: $formId)
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
    public function choicesFor(Relation $relation, ?Purpose $purpose = null): array
    {
        // ⚠️ *Gewählt wird aus den Kindern des **Ziels** — dorthin zeigt die Kante, und dort liegen
        // die Möglichkeiten.*
        return $this->renderers->eligibleFor(
            $relation,
            $this->typeAt($relation),
            $purpose,
            $this->hatEtwasZurAuswahl($relation->toNodeId)
        );
    }

    /**
     * Ob dieser Knoten Kinder hat — die Menge, aus der eine Auswahlliste wählt.
     *
     * ⚠️ **Sein Befund am 2026-09-06:** *«aktuell werden die beiden chooser angeboten das kann aber
     * nicht richig sein weil der knoten keine kinder hat»* — gemessen an `Ampere`, einem Blatt unter
     * `Base units`.
     *
     * ⚠️ *Gefragt wird der Speicher und nicht ein Feld am Knoten: **Kinder sind kein Zustand, den
     * ein Knoten mit sich trägt**, sondern eine Beziehung. Wer sie am Exemplar ablesen wollte,
     * müsste sie beim Laden mitschleppen — und hätte sie in dem Augenblick falsch, in dem jemand
     * ein Kind anlegt.*
     */
    private function hatEtwasZurAuswahl(int $nodeId): bool
    {
        $knoten = $this->nodes->find($nodeId);

        return $knoten !== null && $this->nodes->childrenOf($knoten) !== [];
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
    /**
     * Welche Renderer für **das** in Frage kommen, was hier gezeichnet wird.
     *
     * ⚠️ **Sein Befund am 2026-09-06, mit Bild:** *am Knoten `Ampere` bot die Renderer-Zeile
     * `chooser-dialog` und `chooser-inline` an. **Gemessen war der Gegenstand der Frage falsch:** die
     * Zeile fragte die **Einstellungskante** `renderer` — deren Ziel ist der Knoten `Renderer`, und
     * die Frage «was zeichnet einen Verweis auf einen Renderer» beantworten genau die zwei Chooser.
     * **Gefragt gehört, was `Ampere` zeichnet.***
     *
     * ⚠️ **Die Unterscheidung, auf die es ankommt:**
     * *eine **Einstellungskante** beschreibt nicht, was gezeichnet wird — sie hängt an dem, was
     * gezeichnet wird. Also ist der Gegenstand ihr **Besitzer**. Eine **Feldkante** dagegen ist die
     * Verwendungsstelle selbst; dort wird ihr Ziel gezeichnet, und danach fragt {@see self::choicesFor()}.*
     *
     * ⚠️ **Ohne Zweck gefragt, und das ist kein Versehen.** *Der Zweck des **Schirms** ist «bearbeiten»
     * — der Zweck der **Zeichnung** ist damit nicht festgelegt. Ein Konstantenknoten wie `Ampere` wird
     * angezeigt und nicht eingegeben; fragte man mit `Edit`, bliebe die Liste leer, und die Wahl, die
     * gilt, wäre nicht mehr sichtbar ([R33c](../../../docs/NewConcept/30-renderer.md)).*
     *
     * @return list<Renderer>
     */
    private function choicesForWhatIsDrawn(Node|Relation $subject): array
    {
        if ($subject instanceof Node) {
            return $this->choicesForNode($subject);
        }

        if (! $subject->isSetting()) {
            return $this->choicesFor($subject);
        }

        $besitzer = $this->nodes->find($subject->fromNodeId);

        return $besitzer === null ? [] : $this->choicesForNode($besitzer);
    }

    public function choicesForNode(Node $node, ?Purpose $purpose = null): array
    {
        return $this->renderers->eligibleFor(
            $node,
            $this->typeOfNode($node),
            $purpose,
            $this->nodes->childrenOf($node) !== []
        );
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

    /**
     * Das ganze Angebot eines Auswahlfeldes, unverengt — damit der Rand «alle gesetzt» erkennen kann.
     *
     * @return array<int, string> Knoten-Id => Name
     */
    public function offeredFor(Relation $useSite): array
    {
        return $this->offeredUnder([$useSite->toNodeId])[$useSite->toNodeId] ?? [];
    }

    /**
     * Das Angebot je Auswahlfeld eines Satzes, **verengt** — so, wie der Abstieg es dem Wähler gibt (D-697).
     *
     * @param  list<Relation>                 $relations
     * @param  array<int, TypedValue>         $values  Die Werte des Satzes, je Kante — sie entscheiden über die Geschwister.
     * @return array<int, array<int, string>> Kanten-Id => (Knoten-Id => Name)
     */
    public function offerIn(array $relations, array $values, int $forNode = 0): array
    {
        return $this->optionsFor($relations);
    }

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
     * The simple type behind each attribute's target, keyed by relation id.
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
     * @param  list<Relation> $relations
     * @return array<int, SimpleType|null>
     */
    private function typesOf(array $relations): array
    {
        $targets = $this->nodes->byIds(array_map(static fn (Relation $e): int => $e->toNodeId, $relations));

        $types = [];
        $offen = [];

        foreach ($relations as $relation) {
            $target           = $targets[$relation->toNodeId] ?? null;
            $types[$relation->id] = $target === null ? null : $this->typeOf($target);

            if ($target !== null && $types[$relation->id] === null) {
                $offen[$relation->id] = $target->id;
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

            foreach ($offen as $relationId => $targetId) {
                if (($kinder[$targetId] ?? []) !== []) {
                    $types[$relationId] = SimpleType::NodeRef;
                    unset($offen[$relationId]);
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
        // und `fieldRelationsOf()` nimmt eine Liste.*
        if ($offen !== [] && $this->relations !== null) {
            $mitFeldern = [];

            foreach ($this->relations->fieldRelationsOf(array_values(array_unique($offen))) as $eine) {
                $mitFeldern[$eine->fromNodeId] = true;
            }

            foreach ($offen as $relationId => $targetId) {
                $ziel = $targets[$targetId] ?? null;

                if ($ziel === null || isset($mitFeldern[$targetId])) {
                    continue;
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
     * @param  list<Relation>                 $relations
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
     * descent has two inputs, both loaded before it starts … a descent that fetches per relation is N+1 by
     * construction».*
     *
     * ⚠️ *Deshalb wird je **Stufe** geladen und nicht je Feld: `fieldRelationsOf()` nimmt eine Liste von
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
     * @param  list<Relation>            $relations
     * @return array<int, list<Relation>> Knoten-Id => seine Feldkanten
     */
    private function subgraph(array $relations, int $tiefstens): array
    {
        if ($this->relations === null) {
            return [];
        }

        $unterbau = [];
        $offen    = array_values(array_unique(array_map(static fn (Relation $e): int => $e->toNodeId, $relations)));

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

            foreach ($this->relations->fieldRelationsOf($fragen) as $kante) {
                $unterbau[$kante->fromNodeId][] = $kante;
                $weiter[]                   = $kante->toNodeId;
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
        Relation $relation,
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
        // ⚠️ **Ohne Teil, aber mit einem geltenden Knoten, wird trotzdem abgestiegen**
        // ([D-684](../../../docs/NewConcept/90-decision-log.md)). *Seit die Teile gefallen sind, ist
        // «kein Teil» der **Normalfall** und nicht mehr «nichts gewählt» — die Wahl steht als
        // Knotenverweis in der Wertzeile. **Ohne diese Zeile verschwänden `converter`, `with_label`
        // und `orientation` von jeder Seite**, weil sie an einem Teil hingen, den es nicht mehr gibt.*
        $aufgeloest = 0;

        if (($type !== null && $teile === [] && $aufgeloest === 0) || $tiefe >= self::TIEFSTENS) {
            return null;
        }

        // ⚠️ **Ein Ziel, in dem der Lauf schon war, wird nicht wieder aufgeklappt.** *`DisplayOption`
        // erbt `Display Option` mit **sich selbst** als Ziel ([OQ-133](../../../docs/NewConcept/91-open-questions.md)),
        // und ohne diese Zeile stand `render` in jedem seiner Datensätze doppelt.*
        if (isset($gesehen[$relation->toNodeId])) {
            return null;
        }

        $innen = $unterbau[$relation->toNodeId] ?? [];

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
        $nurEchte = ! $relation->isSetting();

        $innen = array_values(array_filter(
            $innen,
            static fn (Relation $e): bool => ! $e->hide && ($nurEchte === false || ! $e->isSetting())
        ));

        if ($innen === []) {
            return null;
        }

        $ziel = $this->nodes->find($relation->toNodeId);

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

        // ⚠️ **Die Zeile sagt, dass sie geliehen ist**, so wie die Einstellungstafel es tut
        // ({@see SettingsRenderer::whereFrom()}: der Pfeil, das Wort im `title`). *Ohne die Kennzeichnung
        // sähe ein geerbter Wert aus wie ein hier gesetzter — und genau davor warnt jene Spalte: einen
        // Wert des Vorfahren zu überschreiben im Glauben, das Feld sei leer.*
        //
        // ⚠️ *Das Wort kommt vom Rand mit dem Teil (`AR-2`); ohne Wort bleibt die Vorspalte weg.*
        $vorspalten = [];

        foreach ($teile === [] ? [null] : $teile as $teil) {
            // ⚠️ *Erkannt an der **Satz-Id `0`** und nicht am Wort: fehlt dem Rand die Übersetzung, wäre
            // die Zeile sonst still ungekennzeichnet — und ungekennzeichnet ist genau der Zustand, den
            // diese Vorspalte verhindern soll.*
            $wort = (string) ($teil['geerbt'] ?? '');

            $vorspalten[] = $teil === null || ($teil['id'] ?? 0) !== 0
                ? []
                : ['' => '<em class="taxmod-inherited"'
                    . ($wort === '' ? '' : ' title="' . RenderResult::escape($wort) . '"')
                    . '>↑</em>'];

            // WICHTIG: Die Felder des *gewaehlten* Knotens, nicht die des Kantenziels (D-584).
            // Die Kante zeigt auf den Basisknoten «Renderer»; im Datensatz steht «compact», und
            // gezeichnet gehoeren dessen Felder. Ohne das endet der Abstieg an der Auswahl.
            // ⚠️ *Ohne Teil sagt die Kette des **geltenden** Knotens, was hier steht — dieselbe
            // Auskunft wie mit Teil, nur ist die Quelle die Auflösung statt der Satz
            // ([D-684](../../../docs/NewConcept/90-decision-log.md)).*
            $dieseFelder = $teil === null
                ? ($aufgeloest === 0
                    ? $innen
                    : $this->fieldsOfChosen(['nodeId' => $aufgeloest], $relation->toNodeId, $innen))
                : $this->fieldsOfChosen($teil, $relation->toNodeId, $innen);

            // ⚠️ **Ohne Teil kommen die Werte aus dem Einstellungssatz des Knotens** — sein Fund am
            // 2026-09-10 an `Prefixes`: `label_role` gespeichert, die Zeile darunter zeigte «leer». *Seit
            // [D-684](../../../docs/NewConcept/90-decision-log.md) ist die Wahl ein Verweis und kein Teil;
            // was der Knoten zu `converter`, `label_role`, `with_label` sagt, liegt in seinem eigenen Satz
            // ({@see \Taxmod\Core\Service\ModelValues::ownSettingValuesOf()}).*
            $zeilen[] = $this->fieldsFor(
                $dieseFelder,
                $teil === null
                    ? []
                    : $teil['werte'],
                $purpose,
                // ⚠️ **Ein geliehener Teil hat keine Satz-Id, also nimmt er die Adresse des Knotens.**
                // *`taxmod_value[<Trägerkante>][<innere Kante>]` ist die zweistufige Form, die
                // {@see \Taxmod\WordPress\Admin\NodesScreen::saveSettingValues()} längst versteht — und
                // über sie legt {@see \Taxmod\Core\Service\DataEntry::putSettingAt()} beim **ersten
                // Schreiben** den eigenen Teil an ([D-609](../../../docs/NewConcept/90-decision-log.md)).
                // **Angesehen wird dabei nichts geschrieben.***
                // ⚠️ **Adressiert wird über Knoten und Kante, nicht über die Satz-Id**
                // ([D-684](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «settings
                // müssen zum knoten/kante geichert werden nicht zum datensatz». **Die Satz-Id war
                // eine Adresse, die derselbe Aufruf ersetzen konnte** — deshalb ging `with_label`
                // verloren, wenn er im selben Speichern den Renderer umstellte (`INF-072`).*
                //
                // ⚠️ *Ohne Teil bekommen die Felder trotzdem einen Namen, sobald ein Knoten gilt —
                // sonst wären sie zu sehen und nicht zu bedienen.*
                // ⚠️ **Die Zeilen unter einer Einstellung heissen anders als die Einstellung selbst** —
                // sein Fund am 2026-09-10 an `Parts List`: «stelle table ein → speichern → form steht wieder
                // da». *Die Wahl hiess `taxmod_value[<Kante>]`, die Zeilen darunter
                // `taxmod_value[<Kante>][<innen>]` — **derselbe Name, einmal als Wert, einmal als Liste**,
                // und PHP behält beim Lesen die Liste. Die Wahl kam nie an. Jetzt: `taxmod_value_inner[…]`.*
                $fieldPrefix === '' || ($teil === null && $aufgeloest === 0)
                    ? ''
                    : ($relation->isSetting()
                        ? (string) preg_replace('/^([A-Za-z_]+)/', '$1_inner', $fieldPrefix)
                        : $fieldPrefix) . '[' . $relation->id . ']',
                $locale,
                $level,
                $editable,
                $formId,
                $tiefe + 1,
                $unterbau,
                [...$gesehen, $relation->toNodeId => true],
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
        $behaelter = $relation->isSetting()
            ? $this->renderers->byName(TableRenderer::NAME)
            : $this->containerFor($ziel, $purpose);

        return [
            'renderer' => $behaelter->name(),
            'result'   => $behaelter->render(
                $ziel,
                new RenderContext(
                    purpose: $purpose,
                    value: TypedValue::nothing(),
                    // ⚠️ *Dieselbe Naht wie in {@see self::nodeAsForm()}: ein Teil wird von demselben
                    // gewählten Behälter gezeichnet und muss dieselben Angaben bekommen.*
                    settings: $relation->isSetting() ? [] : $this->withRendererValues($ziel),
                    locale: $locale,
                    level: $level,
                    editable: $editable,
                    // ⚠️ **`node_records` ist der Platz, den der Table-Renderer für mehrere Zeilen hat, und
                    // er stand leer** — *der Grund, warum eine Einstellung mit `1..*` trotzdem nur eine
                    // Zeile zeigte. `parts` bleibt daneben für die Behälter, die nur einen Satz kennen.*
                    surroundings: new Surroundings(parts: $teile, records: $zeilen, formId: $formId, rowLead: $vorspalten),
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
            $this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($knoten)),
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
        Relation $relation,
        ?SimpleType $type,
        Renderer $renderer,
        RenderContext $context,
        RenderResult $tiefer
    ): RenderResult {
        if ($type !== SimpleType::NodeRef) {
            return $tiefer;
        }

        $wahl = $renderer->render($relation, $context);

        return new RenderResult(
            $wahl->markup . $tiefer->markup,
            [...$wahl->usedRelations, ...$tiefer->usedRelations],
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
        return $this->relations === null ? null : new Tree($this->nodes);
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

        $walked = $this->closedApartFrom($laeufer->rowsUnder($root, $skip), $expanded, $preselected);

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
     * ⚠️ **Und der Weg zum vorgewaehlten Knoten kommt dazu — additiv, nicht ausschliessend.**
     * *[D-615](../../../docs/NewConcept/90-decision-log.md) trennt die beiden Angaben: der **Ast**
     * oeffnet einen Ast und schliesst den Rest, der **Knoten** oeffnet nur seinen Weg. Beides hier,
     * damit der Dialog den vorgewaehlten Knoten auch sieht, wenn er in einem anderen Ast liegt.*
     *
     * ⚠️ *Der vorgewaehlte Knoten selbst bleibt zu — seine Vorfahren machen ihn sichtbar, ihn
     * aufzuklappen oeffnete einen Ast, den niemand sehen wollte (dieselbe Regel wie in
     * {@see Tree::collapsedByDefault()}).*
     *
     * @param  list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool, hidden: bool}> $walked
     * @return list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool, hidden: bool}>
     */
    private function closedApartFrom(array $walked, ?Node $expanded, ?int $preselected = null): array
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

        if ($preselected !== null) {
            foreach ($walked as $row) {
                if ($row['node']->id === $preselected) {
                    foreach ($row['node']->ancestorIds() as $id) {
                        $offen[$id] = true;
                    }

                    break;
                }
            }
        }

        foreach ($walked as &$row) {
            $row['collapsed'] = ! isset($offen[$row['node']->id]);
        }

        return $walked;
    }

    private function optionsFor(array $relations): array
    {
        $ziele = [];

        foreach ($relations as $relation) {
            $ziele[$relation->id] = $relation->toNodeId;
        }

        if ($ziele === []) {
            return [];
        }

        $unter = $this->offeredUnder(array_values(array_unique($ziele)));
        $wahl  = [];

        foreach ($ziele as $relationId => $targetId) {
            $angebot = $unter[$targetId] ?? [];

            if ($angebot !== []) {
                $wahl[$relationId] = $angebot;
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
     * ⚠️ **Die **eigene** Sorte entscheidet, nicht die aufgelöste.** *`resolvedFieldTypes()` erbt nach unten:
     * wäre sie gefragt, gälten `form`, `table`, `compact` als markiert, weil ihr Elternteil es ist —
     * und die Auswahl wäre leer statt vollständig.*
     *
     * ⚠️ *Eine Abfrage je Stufe, drei Stufen (`CD-7`).*
     *
     * @param  list<int>                       $parentIds
     * @return array<int, array<int, string>>  Eltern-Id => (Knoten-Id => Name)
     */
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

            // ⚠️ **Die eigene Sorte kommt jetzt aus den eingehenden Kanten**
            // ([D-621](../../../docs/NewConcept/90-decision-log.md)). *Eine Abfrage je Stufe für alle
            // Kinder zusammen (`CD-7`) — und weiter die **eigene**, nicht die aufgelöste, sonst gälte
            // alles unter `Renderer` als markiert und die Auswahl wäre leer.*
            $eigene = [];

            foreach ($kinder as $reihe) {
                foreach ($reihe as $kind) {
                    $eigene[$kind->id] = true;
                }
            }

            foreach ($offen as $wurzel => $ids) {
                foreach ($ids as $id) {
                    foreach ($kinder[$id] ?? [] as $kind) {

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
