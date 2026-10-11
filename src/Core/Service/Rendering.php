<?php declare(strict_types=1);

namespace Taxmod\Core\Service;
use Taxmod\Core\Renderer\SummaryRenderer;

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
use Taxmod\Core\Renderer\CheckboxRenderer;
use Taxmod\Core\Renderer\ChoiceRenderer;
use Taxmod\Core\Renderer\ToggleRenderer;
use Taxmod\Core\Renderer\HeadRenderer;
use Taxmod\Core\Renderer\ChooserCellRenderer;
use Taxmod\Core\Renderer\ChooserRenderer;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;
use Taxmod\Core\Renderer\SelectMarkup;
use Taxmod\Core\Renderer\IconMarkup;
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
        /** ⚠️ *Für die Zusammenfassung verwiesener Sätze (D-753) — die Sätze eines Blocks in einer Abfrage.* */
        private readonly ?\Taxmod\Core\Repository\RecordRepository $records = null,
        /** ⚠️ *Die Zusatzfunktionen (D-845): Vorbelegung, «Mehrere hinzufügen», Prüfen beim Speichern — ohne Registratur greift keine ein.* */
        private readonly ?\Taxmod\Core\Addon\AddonRegistry $addons = null,
        /** ⚠️ *Die Mediathek des Randes (D-865): eine Datei wird mit ihrer Id gespeichert, Adresse und Vorschaubild reicht der Rand.* */
        private readonly ?\Taxmod\Core\Port\MediaLibrary $media = null,
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
        $type = $this->typeOf($node, $this->gemerkteKnoten($node->ancestorIds()));

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
    /** @var array{add?: string, remove?: string} Die Worte der Knöpfe an mehrfachen Teilen — vom Rand (`AR-2`). Leer: keine Knöpfe. */
    private array $partActs = [];

    /** @var (\Closure(int, int, string): string)|null Die Adresse eines Sprungs: Zielknoten, Filterfeld, Wert — vom Rand, weil der Kern keine Adresse kennt (D-769). */
    private ?\Closure $jumpUrl = null;

    /** Das Wort des Sprung-Links für den Screenreader — vom Rand (`AR-2`). */
    private string $jumpWord = '';

    /**
     * Die Knoten, die dieser Zeichner schon gelesen hat — Id ⇒ Knoten, `null` für «gibt es nicht».
     *
     * ⚠️ **Gemessen am 2026-09-13, und der Grund für diese drei Methoden** ([D-764](../../../docs/NewConcept/90-decision-log.md)):
     * *`CPUs` mit fünf Sätzen auf der Seite — **1 208** Einzelabfragen nach je einem Knoten, 1,17 s von 1,5 s SQL. Jeder
     * Satz und jeder seiner Teile fragte **dieselben** Knoten neu (`Einheitenwert`, `Text`, `Hertz`, `Bit`); die Werte
     * selbst kosteten 115 Abfragen.* **Also merkt sich der Zeichner, was er gelesen hat** — *so wie er sich schon Ketten
     * und Sätze merkt. Er schreibt nicht, und wer nach einem Schreiben neu zeichnen will, nimmt einen frischen Zeichner
     * (das tun Rand und Wächter ohnehin: ein Aufruf, ein Zeichner).*
     *
     * @var array<int, Node|null>
     */
    private array $gelesen = [];

    /** @var array<int, list<Node>> Unterbäume, nach der Id ihrer Wurzel. */
    private array $gelesenUnter = [];

    /** @var array<string, string|null> Die Beschriftung einer Wahl im Wähler, nach Rolle, Locale und Id ([D-780](../../../docs/NewConcept/90-decision-log.md)). */
    private array $gelesenWahlNamen = [];

    /** @var array<int, SeededRole> Die Rolle, die ein Ziel selbst sagt, nach seiner Id. */
    private array $gelesenZielRollen = [];

    /**
     * Die gewählte Einheit als ihr Zeichen — gemerkt, denn jeder Satz auf der Seite fragt dieselben Einheiten (D-764).
     *
     * @param array<string, ResolvedSetting> $settings
     */
    private function gemerkterWahlName(int $id, array $settings, Node $ziel, string $locale): ?string
    {
        $rolle = isset($settings[self::LABEL_ROLE])
            ? $this->roleOf($settings)
            : ($this->gelesenZielRollen[$ziel->id] ??= $this->roleOf([], $ziel));
        $key   = $rolle->value . '|' . $locale . '|' . $id;

        if (! array_key_exists($key, $this->gelesenWahlNamen)) {
            $knoten                       = $this->gemerkterKnoten($id);
            $this->gelesenWahlNamen[$key] = $knoten === null ? null
                : (($this->labels?->forNodes([$knoten], $rolle, $locale) ?? [])[$id] ?? $knoten->name);
        }

        return $this->gelesenWahlNamen[$key];
    }

    private function gemerkterKnoten(int $id): ?Node
    {
        if (! array_key_exists($id, $this->gelesen)) {
            $speicher          = $this->nodes;
            $this->gelesen[$id] = $speicher->find($id);
        }

        return $this->gelesen[$id];
    }

    /**
     * Wie `byIds()`, aber nur die noch nicht gelesenen gehen an die Datenbank — in **einer** Abfrage.
     *
     * @param  list<int>|array<int> $ids
     * @return array<int, Node>
     */
    private function gemerkteKnoten(array $ids): array
    {
        $ids    = array_values(array_unique(array_map(intval(...), array_filter($ids))));
        $fehlen = array_values(array_filter($ids, fn (int $id): bool => ! array_key_exists($id, $this->gelesen)));

        if ($fehlen !== []) {
            $speicher = $this->nodes;
            $geholt   = $speicher->byIds($fehlen);

            foreach ($fehlen as $id) {
                $this->gelesen[$id] = $geholt[$id] ?? null;
            }
        }

        $aus = [];

        foreach ($ids as $id) {
            if ($this->gelesen[$id] !== null) {
                $aus[$id] = $this->gelesen[$id];
            }
        }

        return $aus;
    }

    /** @return list<Node> */
    private function gemerkterUnterbaum(Node $wurzel): array
    {
        if (! isset($this->gelesenUnter[$wurzel->id])) {
            $speicher                         = $this->nodes;
            $this->gelesenUnter[$wurzel->id] = $speicher->subtreeOf($wurzel);

            foreach ($this->gelesenUnter[$wurzel->id] as $knoten) {
                $this->gelesen[$knoten->id] ??= $knoten;
            }
        }

        return $this->gelesenUnter[$wurzel->id];
    }

    /** @var array<int, list<Node>> Sichtbare Kinder, nach der Id ihres Vaters — auch leere Listen, damit ein kinderloser Vater nicht neu gefragt wird. */
    private array $gelesenKinder = [];

    /**
     * Wie `visibleChildrenOf()`, aber jeder Vater nur einmal je Zeichner (D-764).
     *
     * ⚠️ *Gemessen am 2026-09-13 an `CPUs` mit zwanzig Sätzen: die Auswahlfelder lasen die Kinder ihres Ankers **je Satz**
     * neu — 192 von 325 verbliebenen Knotenabfragen.*
     *
     * @param  list<int>              $parentIds
     * @return array<int, list<Node>> wie das Original: nur Väter, die Kinder haben
     */
    private function gemerkteSichtbareKinder(array $parentIds): array
    {
        $parentIds = array_values(array_unique(array_map(intval(...), $parentIds)));
        $fehlen    = array_values(array_filter($parentIds, fn (int $id): bool => ! isset($this->gelesenKinder[$id])));

        if ($fehlen !== []) {
            $speicher = $this->nodes;
            $geholt   = $speicher->visibleChildrenOf($fehlen);

            foreach ($fehlen as $id) {
                $this->gelesenKinder[$id] = $geholt[$id] ?? [];
            }
        }

        $aus = [];

        foreach ($parentIds as $id) {
            if ($this->gelesenKinder[$id] !== []) {
                $aus[$id] = $this->gelesenKinder[$id];
            }
        }

        return $aus;
    }

    /** @var array<int, NodeRecord|null> Sätze, einmal je Zeichner gelesen — auch die, die es nicht gibt. */
    private array $gelesenSaetze = [];

    /** @var array<int, list<\Taxmod\Core\Model\RelationRecord>> Wertzeilen je Satz, einmal je Zeichner gelesen. */
    private array $gelesenSatzWerte = [];

    /**
     * Diese Sätze noch einmal lesen — für den, der zwischen zwei Rechnungen schreibt
     * ([D-885](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Ein Zeichenlauf liest jeden Satz einmal, und das ist richtig, solange er nur zeichnet. Wer während desselben
     * Laufs schreibt — der Nachlauf der Zusammenfassung —, bekäme sonst den Stand von vorhin. Gemessen: die zweite
     * Änderung an einem Teil ging nicht mehr in die Zusammenfassung.*
     *
     * @param list<int> $recordIds
     */
    public function forgetRecords(array $recordIds): void
    {
        foreach ($recordIds as $id) {
            unset($this->gelesenSaetze[(int) $id], $this->gelesenSatzWerte[(int) $id]);
        }

        // *Wer eine Revision hält, kann sich mit jedem Schreiben ändern (D-903).*
        $this->gehaltenAn = [];
    }

    /**
     * Sätze und ihre Wertzeilen — nur die noch nicht gelesenen gehen an die Datenbank, in je einer Abfrage.
     *
     * ⚠️ *Gemessen am 2026-09-15: die Satztabelle fragte die Zusammenfassung **je Zeile** — 103 Satz- und 103 Wertabfragen auf fünf
     * Seiten ([D-814](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * @param  list<int>|array<int> $ids
     * @return array{0: array<int, NodeRecord>, 1: array<int, list<\Taxmod\Core\Model\RelationRecord>>} Sätze nach Id; Werte je angefragter Id, notfalls leer.
     */
    /** @var array<int, list<int>> Knoten-Id ⇒ die eindeutigen Kanten, die ihn als letzte Stufe halten (D-903). */
    private array $eindeutigeKantenJeKnoten = [];

    /** @var array<int, array<int, int>> Kanten-Id ⇒ gehaltener Satz ⇒ Halter, je Zeichenlauf einmal gelesen (D-903). */
    private array $gehaltenAn = [];

    /**
     * Der Halter jedes Satzes, der als **letzte Stufe** einer eindeutigen Kette hängt — Satz-Id ⇒ Halter-Id ([D-903](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Eindeutig heisst: an einer Aggregation mit `unique` (D-838, D-853) — Platine › Revision, Mainboard › Revision, Projekt › Platine,
     * Reihe › Modell. **Letzte Stufe** heisst: der Knoten des Satzes hält selbst nichts eindeutig. So bekommen die Revisionen den Namen
     * ihres Boards, die Platinen und Modelle aber nicht den ihres Projekts oder ihrer Reihe — über die hat er nichts gesagt, und dort
     * stünde der Hersteller doppelt («Octek · Jaguar · Octek · IV 386»). Das ist meine Grenze, im Beschluss als Annahme genannt.*
     *
     * @param  array<int, \Taxmod\Core\Model\NodeRecord> $saetze
     * @return array<int, int>
     */
    private function eindeutigeHalter(array $saetze): array
    {
        if ($saetze === [] || $this->relations === null || $this->records === null) {
            return [];
        }

        $knotenIds = array_values(array_unique(array_map(static fn (\Taxmod\Core\Model\NodeRecord $s): int => $s->nodeId, $saetze)));
        $fehlen    = array_values(array_filter($knotenIds, fn (int $id): bool => ! array_key_exists($id, $this->eindeutigeKantenJeKnoten)));

        if ($fehlen !== []) {
            $eindeutig = static fn (Relation $r): bool => $r->unique && $r->kind === \Taxmod\Core\Model\RelationKind::Aggregation;
            $hinein    = array_values(array_filter($this->relations->fieldRelationsTo($fehlen), $eindeutig));
            $hinaus    = array_values(array_filter($this->relations->fieldRelationsOf($fehlen), $eindeutig));
            $haeltSelbst = array_fill_keys(array_map(static fn (Relation $r): int => $r->fromNodeId, $hinaus), true);

            foreach ($fehlen as $id) {
                $this->eindeutigeKantenJeKnoten[$id] = [];
            }

            foreach ($hinein as $kante) {
                if (! isset($haeltSelbst[$kante->toNodeId])) {
                    $this->eindeutigeKantenJeKnoten[$kante->toNodeId][] = $kante->id;
                }
            }
        }

        $aus = [];

        foreach ($saetze as $satz) {
            foreach ($this->eindeutigeKantenJeKnoten[$satz->nodeId] ?? [] as $kanteId) {
                $this->gehaltenAn[$kanteId] ??= $this->records->recordRefsHeldAt($kanteId);

                if (isset($this->gehaltenAn[$kanteId][$satz->id])) {
                    $aus[$satz->id] = $this->gehaltenAn[$kanteId][$satz->id];
                    break;
                }
            }
        }

        return $aus;
    }

    private function gemerkteSaetze(array $ids): array
    {
        $ids    = array_values(array_unique(array_map(intval(...), array_filter($ids))));
        $fehlen = array_values(array_filter($ids, fn (int $id): bool => ! array_key_exists($id, $this->gelesenSaetze)));

        if ($fehlen !== [] && $this->records !== null) {
            $saetze = $this->records->byIds($fehlen);
            $werte  = $this->records->valuesOfMany($fehlen);

            foreach ($fehlen as $id) {
                $this->gelesenSaetze[$id]    = $saetze[$id] ?? null;
                $this->gelesenSatzWerte[$id] = $werte[$id] ?? [];
            }
        }

        $saetze = [];
        $werte  = [];

        foreach ($ids as $id) {
            if (($this->gelesenSaetze[$id] ?? null) !== null) {
                $saetze[$id] = $this->gelesenSaetze[$id];
            }

            $werte[$id] = $this->gelesenSatzWerte[$id] ?? [];
        }

        return [$saetze, $werte];
    }

    /** @var array<string, array<int, array<string, mixed>>> Zeilen des Baumwählers, nach Wurzel und Ausnahmen. */
    private array $gelesenZeilen = [];

    /**
     * Die Zeilen unter einer Wurzel für den Baumwähler — einmal je Wurzel und Ausnahmen, nicht je Satz (D-764).
     *
     * ⚠️ *Gemessen: der Wähler eines Knotenverweises lief den Unterbaum für jeden Satz neu, 63 Abfragen bei zwanzig Sätzen.*
     *
     * @param  list<int>                            $skip
     * @return array<int, array<string, mixed>>
     */
    private function gemerkteZeilenUnter(Tree $laeufer, Node $root, array $skip): array
    {
        $schluessel = $root->id . '|' . implode(',', array_map(intval(...), $skip));

        return $this->gelesenZeilen[$schluessel] ??= $laeufer->rowsUnder($root, $skip);
    }

    /** Dieselbe Zeichnung, aber mit Knöpfen zum Hinzufügen und Entfernen von Teil-Zeilen (D-758); die Worte kommen vom Rand. */
    public function withPartActs(string $add, string $remove, string $many = '', string $insert = ''): static
    {
        $kopie           = clone $this;
        // *Dazu das Wort für «mehrere hinzufügen» (D-806) und für «darunter einfügen» (D-830).*
        $kopie->partActs = ['add' => $add, 'remove' => $remove, 'many' => $many, 'insert' => $insert];

        return $kopie;
    }

    /**
     * Das Feld, über das eine neue Zeile eines mehrfachen Teils gewählt wird — `pick_field` am Ziel, an der Kante überschreibbar
     * ([D-806](../../../docs/NewConcept/90-decision-log.md)). `null`, wo keines eingestellt ist.
     */
    public function pickFieldOf(Relation $relation): ?Relation
    {
        if ($this->resolver === null || $this->relations === null) {
            return null;
        }

        $ziel = $this->gemerkterKnoten($relation->toNodeId);

        if ($ziel === null) {
            return null;
        }

        // ⚠️ *Seit D-845 die Zusatzfunktion «Mehrere hinzufügen» an der Kante (oder am Ziel) und nicht mehr ein Feld jeder Kategorie.*
        foreach ($this->resolver->addonsAt($ziel, $relation) as $gewaehlt) {
            $addon = $this->addons?->byClass($gewaehlt->klasse);
            $feld  = $addon instanceof \Taxmod\Core\Addon\PicksRows ? $addon->pickField($gewaehlt->settings) : null;

            if ($feld !== null) {
                return $this->relations->byId($feld);
            }
        }

        return null;
    }

    /**
     * Dieselbe Zeichnung, aber mit Sprung-Feldern, die eine Adresse haben ([D-769](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @param \Closure(int, int, string): string $url  Zielknoten, Filterfeld am Ziel, eingesetzter Wert ⇒ Adresse
     */
    public function withJumps(\Closure $url, string $word): static
    {
        $kopie           = clone $this;
        $kopie->jumpUrl  = $url;
        $kopie->jumpWord = $word;

        return $kopie;
    }

    /** @var array{ok?: string, cancel?: string} Die Worte der Dialogknöpfe, vom Rand (D-804). Leer: kein Fuss, wo der Aufrufer keinen gibt. */
    private array $dialogWords = [];

    /**
     * Dieselbe Zeichnung, aber jeder Dialog mit «OK» und «Abbrechen» ([D-804](../../../docs/NewConcept/90-decision-log.md)) — *sein Wort: «they
     * should have buttons ok/confirm, cancel … this is a general rule for all dialogs».*
     */
    public function withDialogWords(string $ok, string $cancel, string $tree = '', string $upload = '', string $clear = '', string $link = ''): static
    {
        $kopie              = clone $this;
        // *Dazu das Wort des Schalters für den Baum im Satzdialog (D-805), das des Dateiknopfs (D-846), das des Mülleimers an einer
        // gesetzten Referenz (D-851) und das des Linkknopfs (D-857).*
        $kopie->dialogWords = ['ok' => $ok, 'cancel' => $cancel, 'tree' => $tree, 'upload' => $upload, 'clear' => $clear, 'link' => $link];

        return $kopie;
    }

    /** Ob die Seite den einen Auswahlbaum zeichnet — dann öffnet ein ganzer Knotenbaum ihn, statt einen eigenen zu tragen (D-815). */
    private bool $sharedPicker = false;

    /**
     * Der Abschnitt einer Einstellung: wer sie erklärt — ein Schlüssel, das Wort macht der Rand (`AR-2`). Sein Wort: «rules als kategorie
     * und dann darunter jump field».
     */
    private function sectionOf(string $klasse, string $knotenKlasse = ''): string
    {
        // *Was die gemeinsame Oberklasse der Typen erklärt (`display_size`), gehört zum Typ, der gerade eingestellt wird — «jump field».*
        if ($klasse === \Taxmod\Core\Model\Type\SpecialisedType::class && $knotenKlasse !== '') {
            $klasse = $knotenKlasse;
        }

        if ($klasse === \Taxmod\Core\Model\NodeClass\NodeAttributes::class) {
            return 'node';
        }

        if (\Taxmod\Core\Model\NodeClass\Contracts::isKnown($klasse)) {
            return 'class:' . \Taxmod\Core\Model\NodeClass\Contracts::of($klasse)->key;
        }

        if (is_subclass_of($klasse, \Taxmod\Core\Addon\Addon::class)) {
            return 'addon:' . ($this->addons?->byClass($klasse)?->name() ?? strtolower(\Taxmod\Core\Model\NodeClass\Contracts::shortName($klasse)));
        }

        if (is_subclass_of($klasse, Renderer::class)) {
            foreach ([...$this->renderers->namesForNodes(), ...$this->renderers->namesForSurfaces()] as $name) {
                if ($this->renderers->classFor($name) === $klasse) {
                    return 'renderer:' . $name;
                }
            }
        }

        return strtolower(\Taxmod\Core\Model\NodeClass\Contracts::shortName($klasse));
    }

    /**
     * Welche Medienfelder dieser Reihe ihre Beschriftung aus einem Nachbarfeld nehmen ([D-856](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @param  list<Relation>               $relations
     * @param  array<int, SimpleType|null>  $types
     * @return array<int, int> Medienfeld ⇒ Beschriftungsfeld
     */
    private function captionFieldsOf(array $relations, array $types): array
    {
        if ($this->resolver === null) {
            return [];
        }

        $hier = array_flip(array_map(static fn (Relation $r): int => $r->id, $relations));
        $aus  = [];

        foreach ($relations as $relation) {
            if (($types[$relation->id] ?? null) !== SimpleType::Media) {
                continue;
            }

            $ziel = $this->gemerkterKnoten($relation->toNodeId);

            foreach ($ziel === null ? [] : $this->resolver->listOf($ziel, \Taxmod\Core\Model\Type\MediaType::CAPTION_FIELD, $relation) as $glied) {
                if ($glied->aktiv && $glied->reference !== null && isset($hier[$glied->reference])) {
                    $aus[$relation->id] = (int) $glied->reference;

                    break;
                }
            }
        }

        return $aus;
    }

    /**
     * Ob ein Medienfeld in einem neuen Tab öffnet — seine Einstellung `new_tab` ([D-858](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @param list<Relation> $felder
     */
    private function mediaOpensNewTab(array $felder, int $medium): bool
    {
        foreach ($felder as $feld) {
            if ($feld->id === $medium) {
                $wert = ($this->settingsForUseSites([$feld])[$feld->id][\Taxmod\Core\Model\Type\MediaType::NEW_TAB] ?? null)?->value;

                return $wert === null || $wert->isNothing() || (bool) $wert->rawValue();
            }
        }

        return true;
    }

    /** @var \Closure(int): string|null Satz-Id ⇒ Adresse seiner Seite, vom Rand (D-852). */
    private ?\Closure $recordLink = null;

    /**
     * Dieselbe Zeichnung, aber ein angezeigter Satzverweis ist ein Link auf den Satz ([D-852](../../../docs/NewConcept/90-decision-log.md)) —
     * *sein Wort: «wäre gut wenn man unten in der ansicht dann auf octek (link) klicken könnte und landet im entsprechenden knoten mit
     * aktiviertem datensatz».* Der Kern baut keine Adressen (`CD-1`); die Naht bekommt die Satznummer und antwortet mit der Adresse.
     *
     * @param \Closure(int): string $url
     */
    public function withRecordLinks(\Closure $url): static
    {
        $kopie             = clone $this;
        $kopie->recordLink = $url;

        return $kopie;
    }

    /** @var array<string, string> Die Worte der Zusatzfunktionen, vom Rand (D-845): `addon:<name>`, `field:<feld>`, `enum:<wert>`, `add`, `inherited`. */
    private array $addonWords = [];

    /**
     * Dieselbe Zeichnung, mit den Worten der Zusatzfunktionen ([D-845](../../../docs/NewConcept/90-decision-log.md)); ohne sie stehen die Schlüssel da.
     *
     * @param array<string, string> $words
     */
    public function withAddonWords(array $words): static
    {
        $kopie             = clone $this;
        $kopie->addonWords = $words;

        return $kopie;
    }

    /**
     * Die Zusatzfunktionen einer Stelle als geordnete Liste ([D-845](../../../docs/NewConcept/90-decision-log.md)) — je Glied Name, eigene
     * Felder, Pfeile und Mülleimer; darunter die Wahl einer weiteren mit «+». Gestalt wie die Schalterliste (D-794, D-799).
     *
     * ⚠️ *Die Adresse ist `<prefix>_addons[<glied>][name]` und `…[fields][<feld>]`, dazu `[present]`, damit eine geleerte Liste ankommt.
     * Die Reihenfolge der Glieder ist die, in der die Maske sie schickt. Für ein neues Glied liegt je wählbarer Funktion eine Vorlage bereit.*
     *
     * ⚠️ **Angenommen, nicht von ihm gesagt:** *an einer Kante ohne eigene Glieder stehen die des Knotens grau darüber und werden nicht
     * mitgeschickt; wer an der Kante eine hinzufügt, ersetzt sie dort.*
     */
    private function drawAddons(Renderable $subject, string $fieldPrefix, string $formId): RenderResult
    {
        $knoten = $subject instanceof Node ? $subject : ($subject instanceof Relation ? $this->gemerkterKnoten($subject->toNodeId) : null);

        if ($knoten === null || $this->resolver === null || $this->addons === null) {
            return RenderResult::of('');
        }

        $kante = $subject instanceof Relation ? $subject : null;
        $orte  = [\Taxmod\Core\Addon\AddonSite::Node];

        if ($kante !== null) {
            $orte = [\Taxmod\Core\Addon\AddonSite::Edge];

            if ($kante->multiplicity->allowsMany()) {
                $orte[] = \Taxmod\Core\Addon\AddonSite::ManyEdge;
            }

            if ($this->referencesRecords($kante)) {
                $orte[] = \Taxmod\Core\Addon\AddonSite::RecordEdge;
            }
        }

        $wort    = fn (string $schluessel, string $sonst): string => $this->addonWords[$schluessel] ?? $sonst;
        $prefix  = (string) preg_replace('/^([A-Za-z0-9_]+)/', '$1_addons', $fieldPrefix, 1);
        $form    = $formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"';
        $gewaehlt = $this->resolver->addonsAt($knoten, $kante);
        $eigene  = array_values(array_filter($gewaehlt, static fn (\Taxmod\Core\Addon\ChosenAddon $eine): bool => $eine->setHere));
        $geerbt  = $eigene === [] ? $gewaehlt : [];

        $html = '<span class="taxmod-switch-picker taxmod-addon-picker">'
            . '<input type="hidden" name="' . RenderResult::escape($prefix . '[present]') . '" value="1"' . $form . '>';

        if ($geerbt !== []) {
            $html .= '<ul class="taxmod-addon-inherited description">';

            foreach ($geerbt as $eine) {
                $addon = $this->addons->byClass($eine->klasse);
                $html .= '<li>' . RenderResult::escape($addon === null ? $eine->klasse : $wort('addon:' . $addon->name(), $addon->name()))
                    . ' <em>' . RenderResult::escape($wort('inherited', '')) . '</em></li>';
            }

            $html .= '</ul>';
        }

        $html .= '<ol class="taxmod-switch-cascade">';

        foreach ($eigene as $stelle => $eine) {
            $addon = $this->addons->byClass($eine->klasse);

            if ($addon !== null) {
                $html .= $this->addonEntry($addon, $eine->settings, $prefix . '[' . $stelle . ']', $form, $knoten, $kante, $stelle === 0, $stelle === count($eigene) - 1);
            }
        }

        $vorlagen = '';
        $wahlen   = [];

        foreach ($this->addons->forSites($orte) as $addon) {
            $wahlen[$addon->name()] = $wort('addon:' . $addon->name(), $addon->name());
            $vorlagen .= '<template class="taxmod-addon-template" data-taxmod-addon="' . RenderResult::escape($addon->name()) . '">'
                // *Die Vorlage trägt ihre Adressen nur als `data-taxmod-name` — ein Name in einer Vorlage stünde für jede Funktion gleich da;
                // das Skript macht beim Einfügen Namen daraus.*
                . str_replace(' name="', ' data-taxmod-name="', $this->addonEntry($addon, [], $prefix . '[__glied__]', $form, $knoten, $kante, false, false))
                . '</template>';
        }

        // *Das eine Auswahlfeld (SelectMarkup): ohne wählbare Funktion ausgegraut, der «+» mit (D-380, D-370).*
        $html .= '</ol><span class="taxmod-switch-add">'
            . SelectMarkup::of('', $wahlen, null, true, trim($form) === '' ? '' : (string) preg_replace('/^ form="(.*)"$/', '$1', $form), ['class' => 'taxmod-addon-candidates'])
            . SelectMarkup::addButton($wahlen, $wort('add', '+'), 'taxmod-addon-add')
            . '</span>' . $vorlagen . '</span>';

        return RenderResult::of($html);
    }

    /**
     * Ein Glied der Liste: verborgener Name, Wort, je eigenes Feld ein Steuerelement, Pfeile und Mülleimer.
     *
     * @param array<string, TypedValue> $werte
     */
    private function addonEntry(\Taxmod\Core\Addon\Addon $addon, array $werte, string $name, string $form, Node $knoten, ?Relation $kante, bool $erstes, bool $letztes): string
    {
        $wort  = fn (string $schluessel, string $sonst): string => $this->addonWords[$schluessel] ?? $sonst;
        $titel = $wort('addon:' . $addon->name(), $addon->name());
        $html  = '<li class="taxmod-switch-chosen taxmod-addon-entry" data-taxmod-id="' . RenderResult::escape($addon->name()) . '">'
            . '<input type="hidden" name="' . RenderResult::escape($name . '[name]') . '" value="' . RenderResult::escape($addon->name()) . '"' . $form . '>'
            . '<span class="taxmod-switch-name">' . RenderResult::escape($titel) . '</span> ';

        foreach (\Taxmod\Core\Model\NodeClass\Contracts::ofValueClass($addon::class)->attributes as $feldName => $erklaert) {
            $wert     = $werte[$feldName] ?? $erklaert->default;
            $feld     = RenderResult::escape($name . '[fields][' . $feldName . ']');
            $beschrift = RenderResult::escape($wort('field:' . $feldName, $feldName));
            $html    .= '<label class="taxmod-addon-field">' . $beschrift . ' ';

            if ($erklaert->type === \Taxmod\Core\Model\NodeClass\AttributeType::RelationRef) {
                $html .= SelectMarkup::of(html_entity_decode($feld, ENT_QUOTES), $this->addonFieldCandidates($erklaert->fieldsFrom, $knoten, $kante), $wert?->reference === null ? null : (string) $wert->reference, true, (string) preg_replace('/^ form="(.*)"$/', '$1', $form));
            } elseif ($erklaert->type === \Taxmod\Core\Model\NodeClass\AttributeType::Enum) {
                $faelle = [];

                foreach ($erklaert->enumCases() as $fall) {
                    $faelle[$fall] = $wort('enum:' . $fall, $fall);
                }

                $html .= SelectMarkup::of(html_entity_decode($feld, ENT_QUOTES), $faelle, $wert?->text, false, (string) preg_replace('/^ form="(.*)"$/', '$1', $form));
            } elseif ($erklaert->type === \Taxmod\Core\Model\NodeClass\AttributeType::Bool) {
                $html .= '<input type="hidden" name="' . $feld . '" value="0"' . $form . '><input type="checkbox" name="' . $feld . '" value="1"' . ((bool) $wert?->rawValue() ? ' checked' : '') . $form . '>';
            } else {
                $html .= '<input type="text" size="10" name="' . $feld . '" value="' . RenderResult::escape((string) ($wert?->rawValue() ?? '')) . '"' . $form . '>';
            }

            $html .= '</label> ';
        }

        return $html
            . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-list-move" data-taxmod-move="up"' . ($erstes ? ' disabled style="color:#1d2327;opacity:.35"' : ' style="color:#1d2327"') . '>'
            . IconMarkup::dashicon('arrow-up-alt2', $titel) . '</button>'
            . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-list-move" data-taxmod-move="down"' . ($letztes ? ' disabled style="color:#1d2327;opacity:.35"' : ' style="color:#1d2327"') . '>'
            . IconMarkup::dashicon('arrow-down-alt2', $titel) . '</button>'
            . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-list-remove" style="color:#b32d2e">'
            . IconMarkup::dashicon('trash', $titel) . '</button>'
            . '</li>';
    }

    /**
     * Die Felder, die ein Feldverweis einer Zusatzfunktion anbietet ([D-844](../../../docs/NewConcept/90-decision-log.md)): am angebotenen Satz
     * die Felder des Ziels; im tragenden Satz die Felder des Knotens, von dem die Kante ausgeht, und die der Knoten, die ihn halten.
     *
     * @return array<int, string>
     */
    private function addonFieldCandidates(?\Taxmod\Core\Model\NodeClass\FieldSource $quelle, Node $knoten, ?Relation $kante): array
    {
        if ($this->relations === null) {
            return [];
        }

        $felderVon = function (Node $traeger, string $vorn): array {
            $aus = [];

            foreach ($this->relations?->fieldRelationsOf($this->framework->inheritanceOwnersOf($traeger)) ?? [] as $feld) {
                if (! $feld->isSetting()) {
                    $aus[$feld->id] = $vorn . $feld->name;
                }
            }

            return $aus;
        };

        if ($quelle === \Taxmod\Core\Model\NodeClass\FieldSource::ChosenTarget) {
            // *Das gewählte Ziel derselben Stelle, erstes aktives Glied von `ziel`.*
            foreach ($this->resolver?->listOf($knoten, \Taxmod\Core\Model\Type\JumpType::ZIEL, $kante) ?? [] as $glied) {
                $ziel = $glied->aktiv && $glied->reference !== null ? $this->gemerkterKnoten($glied->reference) : null;

                if ($ziel !== null) {
                    return $felderVon($ziel, '');
                }
            }

            return [];
        }

        if ($quelle === \Taxmod\Core\Model\NodeClass\FieldSource::Owner) {
            $eigner = $kante === null ? $knoten : $this->gemerkterKnoten($kante->fromNodeId);

            return $eigner === null ? [] : $felderVon($eigner, '');
        }

        if ($kante === null || $quelle !== \Taxmod\Core\Model\NodeClass\FieldSource::Holder) {
            return $felderVon($knoten, '');
        }

        $traeger = $this->gemerkterKnoten($kante->fromNodeId);

        if ($traeger === null) {
            return [];
        }

        $aus = $felderVon($traeger, $traeger->name . ' › ');

        foreach ($this->relations->fieldRelationsTo([$traeger->id]) as $haltend) {
            $halter = $this->gemerkterKnoten($haltend->fromNodeId);

            if ($halter !== null && ! $haltend->isSetting()) {
                $aus += $felderVon($halter, $halter->name . ' › ');
            }
        }

        return $aus;
    }

    /** Wohin die Körper der Satzdialoge gehen, wenn die Seite sie teilt (D-866) — ein Exemplar, das alle Kopien dieser Zeichnung teilen. */
    private ?\Taxmod\Core\Renderer\SharedBodies $geteilteKoerper = null;

    /**
     * Dieselbe Zeichnung, aber jeder gleiche Satzdialog-Körper steht einmal als Vorlage ([D-866](../../../docs/NewConcept/90-decision-log.md)) —
     * nur für eine Seite, die {@see sharedRecordBodies()} am Ende ausgibt.
     */
    public function withSharedRecordBodies(\Taxmod\Core\Renderer\SharedBodies $koerper): static
    {
        $kopie                  = clone $this;
        $kopie->geteilteKoerper = $koerper;

        return $kopie;
    }

    /**
     * Die gesammelten Vorlagen — leer, wenn die Seite nicht teilt.
     *
     * @param (\Closure(string, string): ?string)|null $auslagern Wohin ein grosser Körper geht (D-898); null heisst: alle in der Seite.
     */
    public function sharedRecordBodies(?\Closure $auslagern = null): string
    {
        return $this->geteilteKoerper?->markup($auslagern) ?? '';
    }

    /** Dieselbe Zeichnung, aber ganze Knotenbäume öffnen den gemeinsamen Auswahlbaum — nur für eine Seite, die ihn zeichnet ([D-815](../../../docs/NewConcept/90-decision-log.md)). */
    public function withSharedPicker(): static
    {
        $kopie               = clone $this;
        $kopie->sharedPicker = true;

        return $kopie;
    }

    /**
     * Ein Öffner des gemeinsamen Auswahlbaums: der Knopf mit dem, was das Skript braucht, und das versteckte Feld, in das die Wahl geht
     * ([D-815](../../../docs/NewConcept/90-decision-log.md), [D-821](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @param list<int> $barred Hier nicht wählbar.
     * @param list<int> $open   Beim Öffnen offen stehende Äste (D-615).
     * @param string    $face   Was der Knopf zeigt — fertiges, sicheres Markup.
     */
    public static function pickOpener(
        string $field,
        string $formId,
        ?int $chosen,
        ?int $initial,
        array $barred,
        array $open,
        string $title,
        string $face,
        string $class = 'button taxmod-icon-button taxmod-pick-open',
        bool $showsName = false,
    ): string {
        return '<button type="button" class="' . RenderResult::escape($class) . '"'
            . ' data-field="' . RenderResult::escape($field) . '"'
            . ' data-chosen="' . ($chosen === null ? '' : $chosen) . '"'
            . ' data-barred="' . implode(',', array_map('intval', $barred)) . '"'
            . ' data-open="' . implode(',', array_map('intval', $open)) . '"'
            . ($showsName ? ' data-names="1"' : '')
            . ' title="' . RenderResult::escape($title) . '">' . $face . '</button>'
            . '<input type="hidden" name="' . RenderResult::escape($field) . '" value="' . ($initial === null ? '' : $initial) . '"'
            . ($formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"') . '>';
    }

    /** @var (\Closure(int): string)|null Die Adresse, unter der man an einem Knoten einen neuen Satz anlegt — vom Rand (D-792, Zeile 154). */
    private ?\Closure $newRecordUrl = null;

    /** Das Wort dazu, für den Screenreader und den Titel (`AR-2`). */
    private string $newRecordWord = '';

    /**
     * Dieselbe Zeichnung, aber mit einem Weg zum Anlegen im Satzdialog ([D-792](../../../docs/NewConcept/90-decision-log.md), Zeile 154) —
     * *sein Wort: «if the part does not exists he needs to enter a new one».*
     *
     * @param \Closure(int): string $url Knoten ⇒ Adresse seiner Seite
     */
    public function withRecordCreation(\Closure $url, string $word): static
    {
        $kopie                = clone $this;
        $kopie->newRecordUrl  = $url;
        $kopie->newRecordWord = $word;

        return $kopie;
    }

    /**
     * Die Teile **eines** Satzes für diese Felder — für eine Zeichnung ausserhalb des Satzblocks, etwa die Vorschau (D-743, D-577).
     *
     * @param  list<Relation> $relations
     * @return array<int, list<array{id: int, nodeId: int, werte: array<int, TypedValue>, teile: array}>>
     */
    public function partsOfRecord(int $recordId, array $relations): array
    {
        return $this->partsOfRecords([$recordId], $relations, $this->subgraph($relations, self::TIEFSTENS))[$recordId] ?? [];
    }

    /**
     * Die Teile mehrerer Sätze, Stufe um Stufe — **ein zusammengesetzter Wert ist ein eigener Satz, auf den der Besitzer zeigt**.
     *
     * ⚠️ **[D-577](../../../docs/NewConcept/90-decision-log.md), von ihm aufgebaut:** *«Eine Adresse — sie bekommt ihren eigenen
     * `node_record`, der Kunde verweist darauf. Zwei Adressen — zwei Wertzeilen, und die Reihenfolge braucht `sort_order`.»*
     * *Nur Kompositionen: ein Verweis an einer Aggregation zeigt auf einen fremden Satz und wird zusammengefasst (D-753).*
     *
     * ```mermaid
     * flowchart LR
     *   S["Satz"] -->|Kante · position| T1["Teil 1"]
     *   S -->|Kante · position| T2["Teil 2"]
     *   T1 --> W["seine Werte, seine Teile"]
     * ```
     *
     * @param  list<int>                  $recordIds
     * @param  list<Relation>             $relations
     * @param  array<int, list<Relation>> $unterbau
     * @return array<int, array<int, list<array{id: int, nodeId: int, werte: array<int, TypedValue>, teile: array}>>>
     */
    private function partsOfRecords(array $recordIds, array $relations, array $unterbau): array
    {
        if ($this->records === null || $recordIds === []) {
            return [];
        }

        $kompositionen = [];

        foreach ([$relations, ...array_values($unterbau)] as $liste) {
            foreach ($liste as $kante) {
                if ($kante->kind === RelationKind::Composition && ! $kante->isSetting()) {
                    $kompositionen[$kante->id] = true;
                }
            }
        }

        $gehalten = [];
        $werte    = [];
        $knoten   = [];
        $gesehen  = array_fill_keys($recordIds, true);
        $ebene    = $recordIds;

        // ⚠️ *Eine Abfrage je Stufe für die Werte, eine für die Knoten der neuen Teile — nie eine je Teil (`CD-7`).*
        for ($stufe = 0; $stufe <= self::TIEFSTENS && $ebene !== []; $stufe++) {
            $weiter = [];

            foreach ($this->records->valuesOfMany($ebene) as $halter => $zeilen) {
                foreach ($zeilen as $zeile) {
                    $wert = $zeile->value;

                    if (isset($kompositionen[$zeile->relationId]) && $wert->reference !== null && $wert->referenceSpace === ReferenceSpace::Record) {
                        $gehalten[$halter][$zeile->relationId][] = [$wert->reference, $zeile->position];

                        if (! isset($gesehen[$wert->reference])) {
                            $gesehen[$wert->reference] = true;
                            $weiter[]                  = $wert->reference;
                        }

                        continue;
                    }

                    $werte[$halter][$zeile->relationId] = self::collectedValue($werte[$halter][$zeile->relationId] ?? null, $wert);
                }
            }

            foreach ($weiter === [] ? [] : $this->records->byIds($weiter) as $id => $satz) {
                $knoten[$id] = $satz->nodeId;
            }

            $ebene = $weiter;
        }

        $bauen = static function (int $halter, array $weg) use (&$bauen, $gehalten, $werte, $knoten): array {
            $aus = [];

            foreach ($gehalten[$halter] ?? [] as $kanteId => $liste) {
                usort($liste, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

                foreach ($liste as [$teilId]) {
                    if (isset($weg[$teilId])) {
                        continue;
                    }

                    $aus[$kanteId][] = [
                        'id'     => $teilId,
                        'nodeId' => $knoten[$teilId] ?? 0,
                        'werte'  => $werte[$teilId] ?? [],
                        'teile'  => $bauen($teilId, $weg + [$teilId => true]),
                    ];
                }
            }

            return $aus;
        };

        $aus = [];

        foreach ($recordIds as $id) {
            $aus[$id] = $bauen($id, [$id => true]);
        }

        return $aus;
    }

    /** Ein gespeicherter Wert als Zeichen, wie er dasteht — für eine Zusammenfassung (D-758). */
    private function wordsOf(TypedValue $value): string
    {
        return (string) ($value->text ?? $value->decimal ?? $value->date ?? ($value->int === null ? '' : (string) $value->int));
    }

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

        // Which targets each role has to answer for — the role is read off the relation that points,
        // und wo die nichts sagt, vom Ziel selbst (D-728). *Die Ziele in einem Zug geladen (`CD-7`).*
        $wanted = [];
        $ziele  = $this->gemerkteKnoten(array_values(array_filter(array_map(
            // ⚠️ *Nur Knotenverweise: ein Satzverweis (D-753) trägt eine Satz-Id, und die ist kein Knoten — gemessen, als die Nummer 1 «Root» hiess.*
            static fn (Relation $r): ?int => (($values[$r->id] ?? null)?->referenceSpace === ReferenceSpace::Node) ? $values[$r->id]->reference : null,
            $relations
        ))));
        $this->resolver?->preload(array_values($ziele));
        $vaeter = [];

        foreach ($relations as $relation) {
            $wert      = $values[$relation->id] ?? null;
            $reference = $wert?->referenceSpace === ReferenceSpace::Node ? $wert->reference : null;

            if ($reference === null) {
                continue;
            }

            $role = $this->roleOf($resolved[$relation->id] ?? [], $ziele[$reference] ?? null);

            $wanted[$role->value][$reference][] = $relation->id;

            // ⚠️ *«schalter für Vater» (D-734): der Vater wird in derselben Rolle mitgeholt, in einem Zug.*
            $vater = ($ziele[$reference] ?? null)?->parentNodeId;

            if ($vater !== null && (($resolved[$relation->id][\Taxmod\Core\Renderer\ReferenceRenderer::WITH_PARENT] ?? null)?->value->asBool() ?? false)) {
                $vaeter[$relation->id] = $vater;
                $wanted[$role->value][$vater][] = -$relation->id;
            }
        }

        $names      = [];
        $vaterNamen = [];

        foreach ($wanted as $role => $targets) {
            $resolvedNames = $this->labels->forNodes(
                array_values($this->gemerkteKnoten(array_keys($targets))),
                SeededRole::from($role),
                $locale
            );

            foreach ($targets as $target => $relationIds) {
                foreach ($relationIds as $relationId) {
                    // ⚠️ Absent stays absent: a dangling reference is drawn as a marked fault
                    // rather than as its id (D-363), and that decision is the renderer's to make.
                    if ($relationId < 0) {
                        $vaterNamen[-$relationId] = $resolvedNames[$target] ?? null;
                    } elseif (isset($resolvedNames[$target])) {
                        $names[$relationId] = $resolvedNames[$target];
                    }
                }
            }
        }

        foreach ($vaeter as $relationId => $vater) {
            if (isset($names[$relationId]) && ($vaterNamen[$relationId] ?? null) !== null) {
                $names[$relationId] = $vaterNamen[$relationId] . ' ' . $names[$relationId];
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

        $targets = $this->gemerkteKnoten(array_map(static fn (Relation $e): int => $e->toNodeId, $relations));
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

        // ⚠️ *Alle Knoten in einem Zug vorladen — sonst fragt `forNode()` je Knoten einzeln: gemessen ~320 Abfragen je Seite ([D-814](../../../docs/NewConcept/90-decision-log.md)).*
        $this->resolver?->preload(array_values($nodes));

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
                EdgeColumn::UNIQUE => new ResolvedSetting(
                    EdgeColumn::UNIQUE,
                    TypedValue::ofBool($relation->unique),
                    $relation->id,
                    true
                ),
            ];
        }

        return $aus;
    }

    /**
     * Der Name eines Ziels in einer Rolle — mit dem Namen des Vaters davor, wo `with_parent` gilt (D-734).
     *
     * @param array<string, ResolvedSetting> $settings
     */
    private function nameWithParent(Node $ziel, SeededRole $role, string $locale, array $settings): string
    {
        $vater  = $ziel->parentNodeId === null ? null : $this->gemerkterKnoten($ziel->parentNodeId);
        $knoten = $vater === null ? [$ziel] : [$ziel, $vater];
        $namen  = $this->labels?->forNodes($knoten, $role, $locale) ?? [];
        $eigen  = $namen[$ziel->id] ?? $ziel->name;

        if ($vater === null || ! (($settings[\Taxmod\Core\Renderer\ReferenceRenderer::WITH_PARENT] ?? null)?->value->asBool() ?? false)) {
            return $eigen;
        }

        return ($namen[$vater->id] ?? $vater->name) . ' ' . $eigen;
    }

    /**
     * Die Rolle, in der ein Verweis beschriftet wird.
     *
     * ⚠️ *Seit dem Einstellungsmodell (D-712) ist `label_role` ein **Verweis** auf den Rollenknoten, dessen Name das
     * Wort der Rolle ist — hier stand nur `->text`, und jede gewählte Rolle las sich als «keine».* ⚠️ **Und die
     * Konstante sagt selbst, welches Label sie zeigt** ([D-728](../../../docs/NewConcept/90-decision-log.md), sein
     * Wort: «einfacher wäre im typ»): *sagt die Stelle nichts, gilt, was am Ziel steht; sonst die Formularrolle.*
     */
    private function roleOf(array $settings, ?Node $ziel = null): SeededRole
    {
        $wahl  = ($settings[self::LABEL_ROLE] ?? null)?->value;
        $asked = $wahl?->text ?? ($wahl?->reference === null ? null : $this->gemerkterKnoten($wahl->reference)?->name);

        if ($asked === null && $ziel !== null && $this->resolver !== null) {
            return $this->roleOf($this->resolver->forNode($ziel));
        }

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
        /** Der Satz, dessen Werte hier stehen — ein Sprung-Feld setzt ihn als Filterwert ein (D-769). `0` heisst «kein Satz», dann zeichnet ein Sprung nichts. */
        int $recordId = 0,
        /** @var array<int, list<int>> Je Feld die erlaubten Knoten seines Wählers — gesetzt vom Teil, der die Felder trägt (D-783). Fehlt ein Feld, gilt alles. */
        array $allowedChoices = [],
        /** @var array<int, TypedValue> Die Werte des Satzes, der diesen Teil hält — die Vorbelegung eines Filters liest auch dort (D-791 Schritt 3). */
        array $ownerValues = [],
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
        // ⚠️ *Die Zusammenfassungen verwiesener Sätze, für alle Verweise dieses Blocks in einer Abfrage (D-753, D-363).*
        $mehrfach = [];

        // ⚠️ *Ein mehrfacher Verweis trägt mehrere Sätze; die Werte-Liste des Satzes nennt alle, die Einzelwerte nur einen (D-859).*
        if ($recordId !== 0 && $this->records !== null) {
            $vielfach = [];

            foreach ($relations as $relation) {
                if (! $relation->isSetting() && $relation->multiplicity->allowsMany()) {
                    $vielfach[$relation->id] = true;
                }
            }

            foreach ($vielfach === [] ? [] : $this->werteJeSatz($recordId) as $zeile) {
                if (isset($vielfach[$zeile->relationId]) && $zeile->value->referenceSpace === ReferenceSpace::Record && $zeile->value->reference !== null) {
                    $mehrfach[$zeile->relationId][] = $zeile->value->reference;
                }
            }
        }

        $saetze   = $this->summariesOf($relations, $values, $resolved, $purpose, $types, $ownerValues, $mehrfach);
        $fields   = [];
        // ⚠️ *Ein Medienfeld mit Beschriftungsfeld zeigt die Beschriftung als Linktext (D-856); angezeigt steht die Beschriftung dann nicht
        // noch einmal daneben.*
        $beschriftet     = $this->captionFieldsOf($relations, $types);
        $alsBeschriftung = array_flip(array_values($beschriftet));

        // ⚠️ *Einmal, ganz oben, in einer festen Zahl von Abfragen — und danach rührt der Abstieg die
        // Datenbank nicht mehr an.*
        if ($tiefe === 0 && $unterbau === []) {
            $unterbau = $this->subgraph($relations, self::TIEFSTENS);
        }

        foreach ($relations as $relation) {
            if ($purpose === Purpose::Display && isset($alsBeschriftung[$relation->id])) {
                continue;
            }

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

            // ⚠️ **Ein Verweis auf einen Satz zeigt seine Zusammenfassung von selbst** ([D-753](../../../docs/NewConcept/90-decision-log.md),
            // berichtigt): *sein Wort: «an der Kante bin ich mir unsicher, ob wir einen Renderer brauchen, aber da es kein simpler
            // Datentyp ist, muss eine Zusammenfassung gezeigt werden». Der Knoten behält seinen Behälter; die Kante braucht keine Wahl.*
            $alsZusammenfassung = $this->drawsAsSummary($relation, $type, $resolved[$relation->id] ?? []);

            if ($alsZusammenfassung) {
                $renderer = $this->renderers->byName(SummaryRenderer::NAME);
            }

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
            $gewaehlt = $values[$relation->id] ?? null;

            // ⚠️ **Ein gespeicherter Satzverweis zeigt seine Zusammenfassung, was auch immer das Ziel ist** (D-753). *Sein Befund:
            // «warum sieht das anders aus als summary bei Hersteller» — der Nachfolger zeigt auf «Software», das Kinder hat, und ist
            // darum nach D-540 eine Knotenauswahl; der Satz darin wurde als Nummer gezeichnet. Der Raum des Wertes entscheidet die Anzeige.*
            // ⚠️ *Nicht an einer Komposition: dort zeigt der Verweis auf den eigenen Teil, und der wird gezeichnet, nicht zusammengefasst (D-577).*
            $satzverweis = $gewaehlt?->referenceSpace === ReferenceSpace::Record && $relation->kind !== RelationKind::Composition;

            if ($satzverweis && ! $renderer instanceof SummaryRenderer) {
                $renderer = $this->renderers->byName(SummaryRenderer::NAME);
            }

            // ⚠️ *Wer Sätze anbietet, ist keine Knotenauswahl — sonst schlüge die Kinderregel (D-540) die Satzauswahl wieder.*
            $istWahl = $purpose === Purpose::Edit && $type === SimpleType::NodeRef && ! $satzverweis && ! $alsZusammenfassung;

            // ⚠️ **Ein Weg wird gerechnet, nicht gelesen, und ist nie eingebbar** ([D-751](../../../docs/NewConcept/90-decision-log.md)).
            // *Was im Satz steht, zählt nicht; die Kette der Namen vom erklärenden Vater bis hierher ist der Wert.*
            if ($type === SimpleType::Path) {
                $gewaehlt = $this->pathValueFor($relation, $forNode, $settings);
                $settings[EdgeColumn::READ_ONLY] = new ResolvedSetting(EdgeColumn::READ_ONLY, TypedValue::ofBool(true), $relation->id, true);
            }

            // ⚠️ **Eine Zusammenfassung wird geschrieben, nicht eingegeben** ([D-885](../../../docs/NewConcept/90-decision-log.md)).
            // *Sie steht im Satz wie jeder andere Wert — gelesen wird sie von dort —, aber die Maske bietet sie nicht zum Tippen an.*
            // ⚠️ *Dasselbe gilt für ein Textfeld, an dem **hier** steht, woraus es sich zusammensetzt ([D-888](../../../docs/NewConcept/90-decision-log.md),
            // sein Wort: «bezeichnung sollte eigentlich abgeleitet sein somit readonly für den benutzer»): die Bezeichnung eines Bauteils
            // kommt aus seinen Werten, und was gerechnet wird, tippt niemand.*
            // ⚠️ *Die Filterzeile ist Satz 0 und bleibt tippbar — gerade nach einem gerechneten Feld will er suchen (D-885).*
            $gerechnet = $recordId !== 0 && ($type === SimpleType::Summary || $this->zusammengesetztHier($relation, $forNode));

            if ($gerechnet) {
                $settings[EdgeColumn::READ_ONLY] = new ResolvedSetting(EdgeColumn::READ_ONLY, TypedValue::ofBool(true), $relation->id, true);
            }

            // ⚠️ **Ein Sprung wird gerechnet, nicht gelesen, und ist nie eingebbar** ([D-769](../../../docs/NewConcept/90-decision-log.md)).
            if ($type === SimpleType::Jump) {
                $gewaehlt = $this->jumpValueFor($relation, $settings, $values, $recordId);
                $settings[EdgeColumn::READ_ONLY] = new ResolvedSetting(EdgeColumn::READ_ONLY, TypedValue::ofBool(true), $relation->id, true);
            }

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
            // ⚠️ *Ohne Kandidaten ist es keine Liste — ein Verweis auf den Typ selbst bekommt den Wähler über den ganzen Baum
            // ({@see self::chooserMarkup()}); sein Befund an `Organisation`: «type in preview ist leer» (D-740).*
            if ($istWahl && ($moeglich !== [] || $verweis !== null) && $dieWahl->canShowItsState() && $this->chosenRendererName($settings) === '') {
                $renderer = $this->renderers->byName(
                    ChoiceRenderer::NAME
                );
            }

            // ⚠️ *Ein Verweis auf den Typ «Node reference» selbst, ohne Wahl: der Wähler über den ganzen Baum (D-740) — auch wenn
            // der Typstandard sonst der Anzeige-Renderer wäre.*
            if ($istWahl && $moeglich === [] && $this->chosenRendererName($settings) === '' && $this->typeNodes->nodeId(SimpleType::NodeRef) === $relation->toNodeId) {
                $renderer = $this->renderers->byName(ChooserRenderer::NAME);
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
                // ⚠️ *Ein gerechnetes Feld wird gezeigt, nicht getippt (D-885, D-888) — sonst stünde eine Eingabe da, die beim nächsten
                // Schreiben überschrieben wird.*
                editable: $editable && ! $gerechnet,
                fieldName: $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $relation->id . ']',
                type: $type,
                surroundings: new Surroundings(
                    // ⚠️ By **relation**, not by target: the role that decided this text belongs to the
                    // relation, so two attributes pointing at one node can show `k` and `kilo`.
                    // ⚠️ *Ein Benutzerverweis ist kein Knotenverweis — sein Wert ist Text
                    // ([D-171](../../../docs/NewConcept/90-decision-log.md)), also kommt sein Name aus
                    // der anderen Naht. **Dasselbe Feld**, weil es dieselbe Aussage ist.*
                    refersTo: isset($beschriftet[$relation->id])
                        // *Das Wort eines Mediums ist seine Beschriftung (D-856); leer heisst: die aus der Datei gerechnete.*
                        ? (string) (($values[$beschriftet[$relation->id]] ?? null)?->text ?? '')
                        : ($value->reference === null
                            ? ($userNames[$relation->id] ?? null)
                            : ($names[$relation->id] ?? $saetze['worte'][$relation->id] ?? null)),
                    // ⚠️ **Already known, so it is handed over rather than looked up** (D-445). A
                    // reference with no simple type behind it is a reference to a record: `typeOf()`
                    // answers `node_ref` for a constant and a real type for a data type, so `null`
                    // here is the composed case — *and it is the summary renderer (D-106) that is
                    // missing, not a renderer that is mis-set.*
                    refersToARecord: $value->reference !== null && $type === null,
                    // *Wohin der Linkdialog die Beschriftung schreibt: das Nachbarfeld derselben Zeile (D-857).*
                    captionName: isset($beschriftet[$relation->id]) && $fieldPrefix !== '' ? $fieldPrefix . '[' . $beschriftet[$relation->id] . ']' : '',
                    // *Beim Anzeigen führt ein Satzverweis zu seinem Satz (D-852); beim Bearbeiten steht dort der Wähler.*
                    href: $purpose === Purpose::Display && $this->recordLink !== null && $value->referenceSpace === ReferenceSpace::Record && $value->reference !== null
                        ? ($this->recordLink)($value->reference)
                        : null,
                    // ⚠️ *Ohne Knotenangebot die Sätze des Ziels, zusammengefasst — der Wähler der Zusammenfassung (D-753).*
                    options: $angebot !== [] ? $angebot : ($saetze['angebot'][$relation->id] ?? []),
                    // ⚠️ *Dieselben Sätze als Baum ihrer Knoten — der Dialog der Satzauswahl (D-791).*
                    recordTree: $saetze['baum'][$relation->id] ?? [],
                    dialogWords: $this->dialogWords,
                    mediaLibrary: $this->media,
                    sharedBodies: $this->geteilteKoerper,
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
                shown: $type === SimpleType::Jump ? $this->jumpWord : $this->convertedCharacters($value, $settings, $type),
            );

            // ⚠️ **Hier war die Kette unterbrochen**, und die Diagnose ist seine: *«heisst wohl
            // Renderkette ist unterbrochen»*, *«Form-Render sollte ja die Knoten durchgehen»*. *Zeigt ein
            // Feld auf einen Knoten mit **eigenen Feldern**, ist sein Wert ein eigener Teil
            // ([D-541](../../../docs/NewConcept/90-decision-log.md)) — und dessen Felder gehören
            // gezeichnet. Vorher endete der Abstieg hier und lieferte `plain`.*
            // ⚠️ *Eine Zusammenfassung steigt nicht ab: sie zeigt Worte des verwiesenen Satzes, nicht die Felder des Ziels (D-753) —
            // das wäre die dritte Stufe, «expand» (D-106).*
            $tiefer = ($onlySettingParts && ! $relation->isSetting()) || $renderer instanceof SummaryRenderer
                ? null
                : $this->partBelow($relation, $type, $purpose, $fieldPrefix, $locale, $level, $editable, $formId, $tiefe, $unterbau, $values, $gesehen, $parts[$relation->id] ?? [], $forNode, $settings);

            if ($tiefer === null) {
                // ⚠️ **Mehrfach heisst Zeilen, gleich welcher Art das Feld ist** ([D-842](../../../docs/NewConcept/90-decision-log.md)) — *sein
                // Wort: «ja bau das so, sonst kann man ja auch keine zeilen eingeben». Zeilen entstanden bisher nur für Teile mit eigenen Sätzen.*
                $tiefer = $this->valueListBelow($relation, $context, $purpose, $fieldPrefix, $formId, $editable, $recordId, $saetze['mehrfach'][$relation->id] ?? []);
            }

            $fields[] = new RenderedField(
                $relation,
                $type,
                $tiefer === null ? $renderer->name() : $tiefer['renderer'],
                // WICHTIG: Bei einer Auswahl bleiben *beide* stehen -- der Kasten, in dem gewaehlt
                // wird, und daneben die Felder des Gewaehlten (D-583: "einfach rechts davon
                // anhaengen finde ich am schoensten"). Vorher ersetzte der Abstieg den Kasten,
                // und der Renderer liess sich nicht mehr wechseln.
                $tiefer === null
                    // ⚠️ *Das Ziel wird nur für einen Wähler-Renderer nachgeschlagen — sonst wäre es eine Abfrage je Feld (CD-7).*
                    ? (($renderer instanceof ChooserRenderer
                        ? $this->chooserMarkup($this->gemerkterKnoten($relation->toNodeId), $renderer, $context->settings, $value, $context->fieldName, $formId, $locale, $level, $allowedChoices[$relation->id] ?? null)
                        : null) ?? $renderer->render($relation, $context))
                    : $this->chosenAndItsFields($relation, $type, $renderer, $context, $tiefer['result']),
                // Carried for the **layout**: R75 puts read-only values first, as context rather
                // than as something to fill in. A container must not resolve the chain again.
                $context->setting(EdgeColumn::READ_ONLY)?->asBool() ?? false,
                '',
                // ⚠️ *Aus demselben Grund mitgegeben: der Behälter zeichnet das Fragezeichen und
                // darf nichts nachschlagen ([D-662](../../../docs/NewConcept/90-decision-log.md)).*
                $hilfen[$relation->id] ?? '',
                $tiefer['rows'] ?? [],
                // ⚠️ *Die Worte des Wertes, ohne Bedienelement — die Zusammenfassung eines tieferen Teils (D-758).*
                $context->shown ?? $context->surroundings->refersTo ?? $this->wordsOf($value),
                $tiefer['rowActs'] ?? [],
                $tiefer['after'] ?? ''
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
        $type = $this->typeOf($node, $this->gemerkteKnoten($node->ancestorIds()));

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

        // ⚠️ **Jeder Verweis bekommt seinen Namen, nicht nur der auf sich selbst** — *gemessen am 2026-09-12 an `Prefixes`
        // mit Beispielsatz: die Leser-Seite zeigte `#3990` als verwaist, weil nur der eigene Verweis beschriftet wurde.
        // Die Rolle: die der Stelle, sonst die des Ziels (D-728).*
        $verwiesen = $value->reference === null ? null : $this->gemerkterKnoten($value->reference);

        if (($wahl = $this->chooserMarkup($node, $renderer, $settings, $value, $fieldName, $formId, $locale, $level)) !== null) {
            return $wahl;
        }

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
                refersTo: $verwiesen === null
                    ? null
                    : $this->nameWithParent($verwiesen, $this->roleOf($settings, $verwiesen), $locale, $settings),
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
        string $chooser = ChooserRenderer::NAME,
        string $locale = '',
        Level $level = Level::Admin,
        // ⚠️ **What opens the dialog, and what confirms inside it** — both boundary markup, because
        // both are buttons with capabilities, titles and translated labels behind them. *The owner
        // wants the **move button** to be the opener: «button move with dialog tree chooser», then
        // «nicht inline». So the surface hands in its own trigger and the renderer stops guessing.*
        string $trigger = '',
        string $confirm = '',
        string $formId = '',
        /** @var array<string, ResolvedSetting> Die Angaben des Wählers — `dialog`, `display_size`; die Knotenseite gibt {@see ChooserRenderer::asDialog()}. */
        array $settings = [],
    ): RenderResult {
        $barred = [];

        foreach ($unpickable as $id) {
            $barred[$id] = [new Control(ChooserCellRenderer::UNPICKABLE, '', '')];
        }

        $nodes = array_map(static fn (array $row): Node => $row['node'], $walked);

        // ⚠️ **One value for every cell, because the radio has to know which row is checked** — and
        // the checked row is a property of the *chooser*, not of the node. *`cellsFor()` hands every
        // cell the same context apart from its own settings, which is exactly what is wanted here.*
        $cells = $this->cellsForChoosing($nodes, $fieldName, $chosen, $barred, $locale, $level, $formId);
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
                // ⚠️ **The field name reaches the chooser, and it has to.** {@see ChooserRenderer}
                // builds its switch id from the subject **and** this — and both choosers on the node
                // page are built from the *first walked node*, so without it the ids matched and **each
                // trigger opened both dialogs**. *Measured: `taxmod-dialog-402` twice.*
                fieldName: $fieldName,
                settings: $settings,
                surroundings: new Surroundings(
                    refersTo: $chosenName,
                    sections: [
                        ChooserRenderer::CANDIDATES => new Section($nothingToChoose, $tree->markup),
                        ChooserRenderer::TRIGGER    => new Section('', $trigger),
                        ChooserRenderer::CONFIRM    => new Section('', $confirm),
                    ],
                    dialogWords: $this->dialogWords,
                    sharedBodies: $this->geteilteKoerper,
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
        string $formId = '',
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
                    // ⚠️ *Das Formular, zu dem die Wahl gehört — ohne es schickt ein Radio in einer Tabellenzelle nichts
                    // (sein Befund am 2026-09-11: «change type button macht nichts»).*
                    surroundings: new Surroundings(actions: $barred[$node->id] ?? [], formId: $formId),
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
        $links      = [];

        // ⚠️ **Die Teile aller Sätze, vor dem Abstieg geladen** ([D-577](../../../docs/NewConcept/90-decision-log.md),
        // D-159, `CD-7`): *ein zusammengesetzter Wert ist ein eigener Satz, auf den der Besitzer zeigt — je Stufe eine Abfrage.*
        $unterbau = $this->subgraph($relations, self::TIEFSTENS);
        $teile    = $this->partsOfRecords(array_map(static fn (array $row): int => $row['id'], $rows), $relations, $unterbau);

        // ⚠️ *Die Sätze, auf die irgendeine Zeile zeigt, und eine Stufe tiefer, samt ihrer Knoten — in je einer Abfrage vor den Zeilen;
        // sonst liest die Zusammenfassung je Zeile nach ([D-814](../../../docs/NewConcept/90-decision-log.md)).*
        $verwiesen = [];
        $knoten    = [];

        foreach ($rows as $row) {
            foreach ($row['values'] as $wert) {
                if ($wert instanceof TypedValue && $wert->referenceSpace === ReferenceSpace::Record && $wert->reference !== null) {
                    $verwiesen[] = $wert->reference;
                }
            }
        }

        for ($stufe = 0; $stufe < 2 && $verwiesen !== []; $stufe++) {
            [, $werteVerwiesen] = $this->gemerkteSaetze($verwiesen);
            $verwiesen          = [];

            foreach ($werteVerwiesen as $zeilen) {
                foreach ($zeilen as $zeile) {
                    if ($zeile->value->reference === null) {
                        continue;
                    }

                    if ($zeile->value->referenceSpace === ReferenceSpace::Record) {
                        $verwiesen[] = $zeile->value->reference;
                    } elseif ($zeile->value->referenceSpace === ReferenceSpace::Node) {
                        $knoten[] = $zeile->value->reference;
                    }
                }
            }
        }

        $this->gemerkteKnoten($knoten);

        // ⚠️ *Die Werte aller Zeilen in **einer** Abfrage — ein mehrfacher Verweis liest je Satz alle seine Werte (D-859), und je Zeile
        // gefragt war das die Abfrage, die die Seitenlast gesprengt hat (gemessen: 27-mal auf einer Seite, `CD-7`).*
        $fehlend = array_values(array_filter(
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
            fn (int $id): bool => $id !== 0 && ! isset($this->werteJeSatzGelesen[$id])
        ));

        if ($fehlend !== [] && $this->records !== null) {
            foreach ($this->records->valuesOfMany($fehlend) + array_fill_keys($fehlend, []) as $id => $zeilen) {
                $this->werteJeSatzGelesen[$id] ??= $zeilen;
            }
        }

        // ⚠️ *In der Satztabelle steht von mehreren Medien nur ihre Anzahl (D-878) — der Merker gilt nur, solange die Zeilen gezeichnet werden.*
        $this->inSatztabelle = true;

        foreach ($rows as $row) {
            $formId = 'taxmod-record-' . $row['id'];

            // ⚠️ *Der Knoten selbst gilt als «schon besucht»: eine Einstellung, die auf ihn zeigt, würde
            // ihn sonst ein zweites Mal aufklappen ([OQ-133](../../../docs/NewConcept/91-open-questions.md)).*
            // ⚠️ **Eine Zeile darf nur anzeigen** ([D-785](../../../docs/NewConcept/90-decision-log.md)) — *sein Wort: «zeigen wir unten
            // die Records nur noch an und benutzen die Eingabe … oben». Eine solche Zeile trägt keine Feldnamen: sie schickt nichts ab.*
            $nurAnzeige = ($row['editable'] ?? true) === false;

            // ⚠️ **In der Tabelle steht ein zusammengesetzter Teil als Worte** ([D-889](../../../docs/NewConcept/90-decision-log.md),
            // sein Befund an der Adresse: *«das sieht auch nicht so schön aus»*) — *vier Eingaben in einer Zelle waren es vorher.
            // In der Filterzeile (Satz 0) bleibt die Zelle leer: über zusammengesetzte Felder filtert die Zeile ohnehin nicht (D-768).*
            $alsWorte = static fn (array $felder): array => array_map(
                static fn (RenderedField $feld): RenderedField => $feld->rows === [] || $feld->relation->isSetting() || $feld->isHidden()
                    ? $feld
                    : \Taxmod\Core\Renderer\ComplexRenderer::asWords($feld, $row['id'] === 0),
                $felder
            );

            $gezeichnet[] = $alsWorte($this->fieldsFor(
                $relations,
                $row['values'],
                $nurAnzeige ? Purpose::Display : $purpose,
                $fieldPrefix === '' || $nurAnzeige ? '' : $fieldPrefix . '[' . $row['id'] . ']',
                $locale,
                $level,
                ! $nurAnzeige,
                $formId,
                0,
                $unterbau,
                [$model->id => true],
                $teile[$row['id']] ?? [],
                // ⚠️ *Der Satz gehört diesem Knoten — ein Weg-Feld (D-751) rechnet aus ihm seine Kette.*
                $model->id,
                // ⚠️ *Und ein Sprung-Feld (D-769) setzt den Satz selbst als Filterwert ein.*
                recordId: $row['id']
            ));

            $vorne[] = $row['lead'];
            $akte[]  = ControlMarkup::actsForm($formId, $row['submits'], $row['acts']);
            // ⚠️ *Wohin die Anzahl eines 1..n-Teils führt: der Satz der Zeile, geöffnet an seiner Eingabe (D-825).*
            $links[] = (string) ($row['link'] ?? '');
        }

        $this->inSatztabelle = false;

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
                    rowActs: $akte,
                    rowLinks: $links
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
        $targets  = $this->gemerkteKnoten(array_map(static fn (Relation $e): int => $e->toNodeId, $relations));

        // ⚠️ One query for the whole table, not one per row (`CD-7`) — and through the ordinary
        // label walk, so an attribute's target reads the same here as it does anywhere else.
        $names = $this->labels === null
            ? []
            : $this->labels->forNodes(array_values($targets), SeededRole::Form, $locale);

        // ⚠️ **Die Kette des Knotens, an dem die Zeilen stehen — einmal, nicht je Zeile** (`CD-7`).
        // *Sie sagt der Wertspalte einer Einstellungszeile, ob der gezeigte Wert geerbt ist
        // ([D-689](../../../docs/NewConcept/90-decision-log.md): dann gesperrt) und von wem — und ob
        // er hier überhaupt zulässig ist ([D-687](../../../docs/NewConcept/90-decision-log.md)).*
        $knotenHier = $showValue && $valuePrefix !== '' && $declaredBy !== 0 ? $this->gemerkterKnoten($declaredBy) : null;
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
                // ⚠️ *Die drei Spalten der Kante stehen in der Zeile selbst — «readonly direkt an der kante» (D-735).*
                $offen ? [] : [EdgeColumn::MULTIPLICITY, EdgeColumn::READ_ONLY, EdgeColumn::UNIQUE],
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
            'von'        => (string) ($this->gemerkterKnoten($angabe->fromOwnerId)?->name ?? ''),
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
        // ⚠️ **Die drei Spalten der Kante stehen in der Zeile selbst (D-735) und nicht noch einmal hier** — sein Befund am
        // 2026-09-12: «read only verschwindet nach Speichern, das hatten wir jetzt schon mehrfach». *Gemessen: die offene Zeile
        // trug `read_only` und `unique` zweimal unter einem Namen — die Spalte und die Kopie im Bereich —, und der Browser schickt
        // die zweite, deren verborgene 0. Der Wächter schickt die Seite seither wie ein Browser (D-754).*
        unset($configured[EdgeColumn::MULTIPLICITY], $configured[EdgeColumn::READ_ONLY], $configured[EdgeColumn::UNIQUE], $configured[self::KIND_KEY]);

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

        // ⚠️ **In der Reihenfolge des Vertrags, nicht des Alphabets** ([D-736](../../../docs/NewConcept/90-decision-log.md)) — *sein
        // Befund: «min und max sollten vertauscht sein». Was der Vertrag nicht kennt, kommt danach, alphabetisch.*
        $reihenfolge = $node instanceof Node || $node instanceof Relation ? array_flip(array_keys($this->attributesDrawnFor($node))) : [];
        uksort($resolved, static fn (string $a, string $b): int => (($reihenfolge[$a] ?? PHP_INT_MAX) <=> ($reihenfolge[$b] ?? PHP_INT_MAX)) ?: strcmp($a, $b));

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

            if ($node instanceof Relation && ($key === EdgeColumn::READ_ONLY || $key === EdgeColumn::UNIQUE)) {
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
                $drawn[] = $this->drawAttribute($node, $erklaert, $setting, $purpose, $fieldPrefix, $locale, $level, $subject, $formId)->inSection($this->sectionOf($erklaert->declaredBy, $node instanceof Node ? $node->klasse : ($this->gemerkterKnoten($node->toNodeId)?->klasse ?? '')));

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
                $knotenHier = $this->gemerkterKnoten($forNode);

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
                ? (string) ($this->gemerkterKnoten($eine->setting->fromOwnerId)?->name ?? '')
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

        // ⚠️ **Über die gehaltenen Werte, nicht über die Kanten des Knotens** — sein Befund am 2026-09-12:
        // *«Datensatz wird jetzt gespeichert, aber nicht in Preview angezeigt».* *Die Adresse eines Satzes
        // liegt an den **inneren** Kanten (D-742), und diese Schleife lief nur über die äusseren — alles
        // darunter fiel heraus, die Vorschau zeichnete die Adressfelder leer, der Satzblock daneben voll.
        // Der Satz ist gewählt ({@see previewRecordAmong()}), also gilt alles, was er trägt.*
        //
        // ⚠️ **Real data wins, and the rung between it and the defaults is
        // {@see previewRecordAmong()}** — the caller has already chosen *which* record the
        // values came from, so what is left here is the decided *«fällt auf die Vorgaben
        // zurück, wo keine da sind»* of [D-028](90-decision-log.md).
        foreach ($held as $relationId => $value) {
            if (! $value->isNothing()) {
                $values[$relationId] = $value;
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
        /**
         * ⚠️ *Die Teile des gezeichneten Satzes, aus {@see self::partsOfRecord()} — ohne sie stünden die Felder eines
         * zusammengesetzten Wertes leer da, denn ihre Werte liegen im eigenen Teil (D-577).*
         *
         * @var array<int, list<array{id: int, nodeId: int, werte: array<int, TypedValue>, teile: array}>>
         */
        array $recordParts = [],
        /** Der Satz, der gezeichnet wird — die Zeilen eines mehrfachen Feldes lesen aus ihm ([D-842](../../../docs/NewConcept/90-decision-log.md)). */
        int $recordId = 0,
        /** Das Formular, zu dem die Felder gehören, wenn sie ausserhalb von ihm stehen — die bearbeitbare Vorschau (D-785). */
        string $formId = '',
    ): RenderResult {
        // ⚠️ *Der gezeichnete Knoten gilt als «schon besucht» — sonst klappt ein Feld, das auf ihn
        // selbst zeigt, ihn ein zweites Mal auf. Genau das war auf `DisplayOption` zu sehen.*
        // ⚠️ *Der Knoten reist als `forNode` mit — ein Weg-Feld (D-751) rechnet aus ihm seine Kette.*
        $parts = $this->fieldsFor($relations, $values, $purpose, $fieldPrefix, $locale, $level, $editable, $formId, 0, [], [$node->id => true], $recordParts, $node->id, recordId: $recordId);

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
    /**
     * Ob der Knoten selbst einen Behälter gewählt hat — die Vorschau nimmt sonst die Tabelle (D-748).
     *
     * ⚠️ *Sein Wort am 2026-09-12: «wenn keine Tabelle, Form oder sonstiger gruppierender Renderer involviert ist,
     * sollte der Standard Tabelle sein». Der Rückfall in {@see self::containerFor()} bleibt das Formular — er gilt
     * für Teile in einer Maske, wo eine einzeilige Tabelle nichts gewinnt; die Vorschau ist die Seite, die er meint.*
     */
    public function containerChosenFor(Node $node): bool
    {
        return $this->chosenRendererName($this->withModelValues([], $node)) !== '';
    }

    /**
     * Der Wert eines Weg-Feldes: die Namen vom erklärenden Vater bis zum Vater des Knotens, mit dem Knoten
     * selbst, wo `with_node` gilt (D-751).
     *
     * ⚠️ *Beginnt am Knoten, der das Feld erklärt, nicht an der Wurzel — sonst stünde überall «Root → Model → …»
     * davor. Am erklärenden Knoten selbst ist der Weg leer. Eine Abfrage je Weg-Feld (`byIds`), kein Aufstieg je Stufe.*
     *
     * @param array<string, ResolvedSetting> $settings
     */
    /**
     * Der Weg eines Feldes für einen Knoten, mit den Angaben der Verwendungsstelle — für den Satz, der ihn
     * **speichert** ([D-755](../../../docs/NewConcept/90-decision-log.md): *«und das in den Datensatz auch reinschreiben»*).
     */
    /**
     * Der Wert eines Sprung-Feldes: die Adresse zum Zielknoten, gefiltert nach dem eingestellten Feld ([D-769](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Gerechnet beim Zeichnen, nie gespeichert — wie der Weg. Ohne Adressgeber vom Rand, ohne Satz, ohne Ziel oder ohne
     * Filterfeld zeichnet das Feld nichts: ein halb eingestellter Sprung führt nirgendwohin, statt irgendwohin.*
     *
     * @param array<string, ResolvedSetting> $settings Was an dieser Stelle gilt — `ziel` steht darin.
     * @param array<int, TypedValue>         $values   Die Werte des Satzes, für ein eingestelltes Quellfeld.
     */
    private function jumpValueFor(Relation $relation, array $settings, array $values, int $recordId): TypedValue
    {
        if ($this->jumpUrl === null || $recordId === 0 || $this->resolver === null) {
            return TypedValue::nothing();
        }

        $typKnoten = $this->gemerkterKnoten($relation->toNodeId);

        if ($typKnoten === null) {
            return TypedValue::nothing();
        }

        // ⚠️ *Über `listOf()` und nicht über `$settings`: aufgelöste Einstellungen sind Wörter, die Glieder einer Liste tragen ihre Nummer.*
        $erstes = static function (iterable $glieder): ?int {
            foreach ($glieder as $glied) {
                if ($glied->aktiv && $glied->reference !== null) {
                    return (int) $glied->reference;
                }
            }

            return null;
        };

        $ziel       = $erstes($this->resolver->listOf($typKnoten, \Taxmod\Core\Model\Type\JumpType::ZIEL, $relation));
        $filterFeld = $erstes($this->resolver->listOf($typKnoten, \Taxmod\Core\Model\Type\JumpType::FILTER_FELD, $relation));

        if ($ziel === null || $filterFeld === null) {
            return TypedValue::nothing();
        }

        // ⚠️ *Leer heisst «der Satz selbst» — sein Wort: «setzte filter von = aktulle zeile».*
        $quellFeld = $erstes($this->resolver->listOf($typKnoten, \Taxmod\Core\Model\Type\JumpType::QUELL_FELD, $relation));
        $wert      = $quellFeld === null ? (string) $recordId : (($values[$quellFeld] ?? null)?->rawValue() ?? '');

        if ($wert === '') {
            return TypedValue::nothing();
        }

        return TypedValue::ofText(($this->jumpUrl)((int) $ziel, $filterFeld, $wert));
    }

    public function pathTextFor(Relation $relation, int $forNode): TypedValue
    {
        $resolved = $this->settingsForUseSites([$relation]);

        return $this->pathValueFor($relation, $forNode, $this->withModelValues($resolved[$relation->id] ?? [], $relation));
    }

    /**
     * Ob dieses Feld **an diesem Knoten** zusammengesetzt wird ([D-888](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Die Zusage steht als `summary_fields` am Knoten **zur Kante** — dieselbe Adresse, an der ein Kind geerbte Felder
     * anordnet ([D-698](../../../docs/NewConcept/90-decision-log.md)). Steht dort eine Liste, schreibt {@see SummaryWriter}
     * das Feld, und die Maske sperrt es. Steht nichts, ist es ein gewöhnliches Feld.*
     */
    public function zusammengesetztHier(Relation $relation, int $forNode): bool
    {
        return $this->zusammensetzungAn($relation, $forNode) !== [];
    }

    /**
     * Die Felder, aus denen dieses Feld **an diesem Knoten** zusammengesetzt wird — leer heisst: wird es nicht.
     *
     * ⚠️ *Nur, was die Zeile **zur Kante** nennt (`setHere`): die Liste am Knoten ohne Kante sagt, was ein Wähler zeigt.
     * Gemessen am 2026-09-20: solange die Rechnung nur die Knotenliste nahm, blieb «am Feld auswählen, was es zusammenfasst»
     * ([D-885](../../../docs/NewConcept/90-decision-log.md)) eine Zusage ohne Wirkung — der Steckertyp stand weiter im Namen.*
     *
     * @return list<int>
     */
    public function zusammensetzungAn(Relation $relation, int $forNode): array
    {
        if ($forNode <= 0 || $relation->isSetting() || $this->resolver === null) {
            return [];
        }

        $knoten = $this->gemerkterKnoten($forNode);

        if ($knoten === null) {
            return [];
        }

        $aus = [];

        foreach ($this->resolver->listOf($knoten, SummaryRenderer::FIELDS, $relation) as $glied) {
            // ⚠️ *`setHere` ist der Unterschied, auf den es ankommt: die Liste **am Knoten** sagt, was ein Wähler zeigt, und gilt für
            // jedes Feld. Nur eine Zeile, die diese **Kante** nennt, sagt «dieses Feld wird hier zusammengesetzt». Gemessen: ohne die
            // Unterscheidung galt an einem Bauteil auch «Alternative Bezeichnungen» als zusammengesetzt.*
            if ($glied->setHere && $glied->aktiv && $glied->reference !== null) {
                $aus[] = $glied->reference;
            }
        }

        return $aus;
    }

    private function pathValueFor(Relation $relation, int $forNode, array $settings): TypedValue
    {
        $knoten = $forNode === 0 ? null : $this->gemerkterKnoten($forNode);

        if ($knoten === null) {
            return TypedValue::nothing();
        }

        $vorfahren = $knoten->ancestorIds();
        $start     = array_search($relation->fromNodeId, $vorfahren, true);
        $glieder   = $start === false ? [] : array_slice($vorfahren, $start);

        // ⚠️ **Nur der Ast** ([D-755](../../../docs/NewConcept/90-decision-log.md)): *das erste Glied unter dem erklärenden Knoten —
        // an «Internal» unter «PC → Hardware» also «Hardware»; am direkten Kind ist es das Kind selbst.*
        if (($settings[\Taxmod\Core\Model\Type\PathType::ONLY_DIRECT_CHILD] ?? null)?->value->asBool() === true) {
            $ast = $start === false ? null : ($vorfahren[$start + 1] ?? $knoten->id);

            if ($ast === null || $ast === $relation->fromNodeId) {
                return TypedValue::nothing();
            }

            $glieder = [$ast];
        } elseif (($settings[\Taxmod\Core\Model\Type\PathType::WITH_NODE] ?? null)?->value->asBool() === true) {
            $glieder[] = $knoten->id;
        }

        if ($glieder === []) {
            return TypedValue::nothing();
        }

        $namen  = $this->gemerkteKnoten($glieder);
        $worte  = [];

        foreach ($glieder as $id) {
            $worte[] = ($namen[$id] ?? null)?->name ?? '#' . $id;
        }

        return TypedValue::ofText(implode(\Taxmod\Core\Model\Type\PathType::SEPARATOR, $worte));
    }

    /**
     * Die Zusammenfassungen verwiesener Sätze — je Verweis das Wort des gezeigten Satzes und das Angebot der
     * Sätze des Ziels, **in einer Abfrage** für den ganzen Block ([D-753](../../../docs/NewConcept/90-decision-log.md),
     * [D-363](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Nur Verweise, die der Summary-Renderer zeichnet. Die Felder sagt der Zielknoten in `summary_fields`, an der
     * Kante überschreibbar; ohne Wahl das erste Textfeld des Ziels. Verweise in einem Satz werden nicht ausgeschrieben —
     * eine Zusammenfassung fasst Worte, keine Verweise.*
     *
     * @param  list<Relation>                                  $relations
     * @param  array<int, TypedValue>                          $values
     * @param  array<int, array<string, ResolvedSetting>>      $resolved
     * @return array{worte: array<int, string>, angebot: array<int, array<int, string>>}
     */
    /**
     * Ob ein Feld als Zusammenfassung gezeichnet wird: ohne einfachen Typ, an einer Aggregation — es sei denn, an der
     * Stelle ist ausdrücklich ein anderer Renderer gewählt.
     *
     * @param array<string, ResolvedSetting> $resolved
     */
    private function drawsAsSummary(Relation $relation, ?SimpleType $type, array $resolved): bool
    {
        if ($relation->kind !== RelationKind::Aggregation) {
            return false;
        }

        // ⚠️ **Ein Ziel mit Sätzen bietet Sätze an, auch wenn es Kinder hat** — *sein Bild der leeren Position in `Parts List`:
        // «Part» ohne Auswahl. Gemessen: `Electronic Parts` hat Kinder, also antwortete der Typ `node_ref`, und eine leere Zeile
        // bekam den Anzeige-Renderer eines Knotenverweises; erst ein gespeicherter Satzverweis machte daraus die Satzauswahl.
        // Die Sätze liegen aber genau dort (D-753: «alle Sätze unter dem Ziel»). Eine Auswahl von Knoten bleibt, was eine ist:
        // eine `Choice`, eine Konstante, eine Einheit — auch wenn sie im Datenast liegt wie `Bestückungsseiten`.*
        if ($type !== null) {
            $ziel = $type === SimpleType::NodeRef ? $this->gemerkterKnoten($relation->toNodeId) : null;

            if ($ziel === null
                || in_array($ziel->klasse, [\Taxmod\Core\Model\NodeClass\Choice::class, \Taxmod\Core\Model\NodeClass\Constant::class, \Taxmod\Core\Model\NodeClass\Unit::class], true)
                // ⚠️ *Ohne benannten Ast hält ein Knoten Sätze, solange er kein Rahmenknoten ist ([D-890](../../../docs/NewConcept/90-decision-log.md)).
                // Sein Bild an BerryBase: «übernommen durch» → `Organisation` unter `Kontakt` stand ohne Auswahl da, weil hier `false` der Rückfall war.*
                || ! ($this->framework->branchOf($ziel)?->holdsData() ?? ! $this->framework->isProtected($ziel))
            ) {
                return false;
            }
        }

        // ⚠️ **Nur eine Wahl an der Kante zählt, nicht der Behälter des Zielknotens** (D-786) — *sein Bild der leeren Position
        // in `Parts List`: «Part» ohne Auswahl. Gemessen: seit `complex` an `Electronic Parts` steht, las diese Zeile die Wahl des
        // Knotens als Wahl der Stelle, und die neue Zeile bekam kein Auswahlfeld; gefüllte Zeilen retteten sich über ihren
        // Satzverweis. Ein Behälter sagt, wie der Knoten seine Felder legt — nicht, wie ein Verweis auf seine Sätze aussieht.*
        $wahl = $resolved['renderer'] ?? null;
        $name = $wahl instanceof ResolvedSetting && $wahl->fromOwnerId === $relation->id ? (string) ($wahl->value->text ?? '') : '';

        return $name === '' || $name === SummaryRenderer::NAME;
    }

    /**
     * Die Zusammenfassung dieser Sätze als Text — dieselben Worte, die ein Wähler zeigt
     * ([D-885](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Für ein Feld vom Typ «Zusammenfassung»: die Kante sagt über ihre Einstellung `summary_fields`, welche Felder
     * zusammengefasst werden; ohne Wahl gilt derselbe Rückfall wie im Wähler. Alle Sätze einer Kante in **einem** Lauf
     * (`CD-7`) — deshalb die Liste je Kante und nicht je Satz.*
     *
     * ⚠️ *`ownWords`: die Sätze mit ihren eigenen Worten benennen, ohne die Feldwahl am Ziel der Kante (D-902).*
     *
     * @param  list<array{relation: Relation, records: list<int>, fields?: list<int>, ownWords?: bool}> $auftrag
     * @return array<int, array<int, string>> Kanten-Id ⇒ Satz-Id ⇒ Text
     */
    public function summaryTextsOf(array $auftrag): array
    {
        $relations = [];
        $mehrfach  = [];
        $feldwahl  = [];

        foreach ($auftrag as $eines) {
            $relations[]                        = $eines['relation'];
            $mehrfach[$eines['relation']->id]   = array_values($eines['records']);

            if (($eines['fields'] ?? []) !== [] || ($eines['ownWords'] ?? false)) {
                $feldwahl[$eines['relation']->id] = array_values($eines['fields'] ?? []);
            }
        }

        if ($relations === []) {
            return [];
        }

        return $this->summariesOf($relations, [], [], Purpose::Display, [], [], $mehrfach, $feldwahl)['mehrfach'] ?? [];
    }

    private function summariesOf(array $relations, array $values, array $resolved, Purpose $purpose, array $types = [], array $ownerValues = [], array $mehrfach = [], array $feldwahl = []): array
    {
        // *`mehrfach`: je mehrfachem Verweis alle seine Sätze, damit jeder ein Wort bekommt und nicht nur der erste (D-859).*
        $leer = ['worte' => [], 'angebot' => [], 'baum' => [], 'mehrfach' => []];

        if ($this->records === null || $this->resolver === null) {
            return $leer;
        }

        $betroffen = [];

        foreach ($relations as $relation) {
            if ($this->drawsAsSummary($relation, $types[$relation->id] ?? null, $resolved[$relation->id] ?? [])
                || ($values[$relation->id] ?? null)?->referenceSpace === ReferenceSpace::Record
                // ⚠️ *Auch eine Kante, für die nur Sätze genannt sind — der Weg von {@see self::summaryTextsOf()} (D-885).*
                || ($mehrfach[$relation->id] ?? []) !== []) {
                $betroffen[] = $relation;
            }
        }

        if ($betroffen === []) {
            return $leer;
        }

        $ziele    = $this->gemerkteKnoten(array_values(array_unique(array_map(static fn (Relation $r): int => $r->toNodeId, $betroffen))));
        $angebote = [];
        $satzIds  = [];

        // ⚠️ **Das Angebot sind alle Sätze unter dem Ziel, nicht nur die am Ziel selbst.** *Sein Befund: «Software hat Hersteller,
        // Version als Summary, aber angezeigt wird die Id» — die Sätze der Betriebssysteme liegen an DOS, Windows, OS/2 unter
        // «Software»; das Angebot kannte nur das Ziel, der gewählte Satz stand nicht darin, also blieb die Nummer.* Ein Unterbaum
        // je Ziel, dann **eine** Abfrage für alle Sätze aller Knoten (`CD-7`).
        $unterZiel = [];

        if ($purpose === Purpose::Edit) {
            foreach ($ziele as $ziel) {
                $unterZiel[$ziel->id] = [$ziel->id, ...array_map(static fn (Node $n): int => $n->id, $this->gemerkterUnterbaum($ziel))];
            }
        }

        $saetzeJeKnoten = $unterZiel === [] ? [] : $this->records->ofNodes(array_values(array_unique(array_merge(...array_values($unterZiel)))));

        foreach ($betroffen as $relation) {
            if ($purpose === Purpose::Edit) {
                foreach ($unterZiel[$relation->toNodeId] ?? [] as $knotenId) {
                    foreach ($saetzeJeKnoten[$knotenId] ?? [] as $satz) {
                        if ($satz->recordType !== RecordType::Settings) {
                            $angebote[$relation->id][] = $satz->id;
                            $satzIds[]                 = $satz->id;
                        }
                    }
                }
            }

            $wert = $values[$relation->id] ?? null;

            if ($wert?->referenceSpace === ReferenceSpace::Record && $wert->reference !== null) {
                $satzIds[] = $wert->reference;
            }

            foreach ($mehrfach[$relation->id] ?? [] as $weiterer) {
                $satzIds[] = $weiterer;
            }
        }

        $satzIds = array_values(array_unique($satzIds));

        if ($satzIds === []) {
            return $leer;
        }

        [$saetze, $werte] = $this->gemerkteSaetze($satzIds);

        // ⚠️ *Die Halter der Revisionen gleich mitladen — ihr Wort steht vor dem des Satzes (D-903), samt ihren inneren Verweisen.*
        $halter = $this->eindeutigeHalter($saetze);

        if ($halter !== []) {
            [$halterSaetze, $halterWerte] = $this->gemerkteSaetze(array_values($halter));
            $saetze += $halterSaetze;
            $werte  += $halterWerte;
        }

        // ⚠️ **Ein Verweis in der Zusammenfassung bekommt selbst sein Wort — eine Stufe tief.** *Sein Wort: «ist doch ein Verweis auf
        // den Datensatz, eigentlich sollte da Microsoft Corp. DOS 4.0 stehen». Ein Knotenverweis heisst wie sein Knoten, ein
        // Satzverweis wie die Zusammenfassung seines Satzes; eine zweite Stufe gibt es nicht, sonst läse die Seite Satz für Satz
        // weiter. Die inneren Sätze und Knoten in je einer Abfrage mehr.*
        $innereSaetze = [];
        $innereKnoten = [];

        foreach ($werte as $zeilen) {
            foreach ($zeilen as $zeile) {
                if ($zeile->value->reference === null) {
                    continue;
                }

                if ($zeile->value->referenceSpace === ReferenceSpace::Record) {
                    $innereSaetze[$zeile->value->reference] = true;
                } elseif ($zeile->value->referenceSpace === ReferenceSpace::Node) {
                    $innereKnoten[$zeile->value->reference] = true;
                }
            }
        }

        $innereSaetze = array_values(array_diff(array_keys($innereSaetze), $satzIds));

        if ($innereSaetze !== []) {
            [$innereSaetzeGelesen, $innereWerte] = $this->gemerkteSaetze($innereSaetze);
            $saetze += $innereSaetzeGelesen;
            $werte  += $innereWerte;

            // *Auch ein innerer Satz kann eine Revision sein — das Exemplar nennt sein Board über sie.*
            $innereHalter = $this->eindeutigeHalter($innereSaetzeGelesen);

            if ($innereHalter !== []) {
                $halter += $innereHalter;
                [$halterSaetze, $halterWerte] = $this->gemerkteSaetze(array_values($innereHalter));
                $saetze += $halterSaetze;
                $werte  += $halterWerte;
            }
        }

        $knotenNamen = [];

        // *Auch die Knoten der Teile — ein Einheitenwert nennt seine Einheit und sein Präfix erst eine Stufe tiefer («330 Ohm»).*
        foreach ($innereSaetze as $innererSatz) {
            foreach ($werte[$innererSatz] ?? [] as $zeile) {
                if ($zeile->value->referenceSpace === ReferenceSpace::Node && $zeile->value->reference !== null) {
                    $innereKnoten[$zeile->value->reference] = true;
                }
            }
        }

        foreach ($innereKnoten === [] ? [] : $this->gemerkteKnoten(array_keys($innereKnoten)) as $knoten) {
            $knotenNamen[$knoten->id] = $knoten->name;
        }

        // ⚠️ **Präfix und Einheit als Zeichen** ([D-797](../../../docs/NewConcept/90-decision-log.md)) — *sein Bild: «33 Farad micro»
        // und «-20 20 Prozent» in der Stückliste, sein Wort: «should be -/+20%». Erkannt am Anker des Gerüsts (Kind von `Prefixes`,
        // unter `Base units`), nicht am Namen; das Zeichen ist die Beschriftung der Rolle `symbol`, sonst der Name.*
        $praefixAnker = $this->framework->anchor(\Taxmod\Core\Model\NodeClass\Anchor::Prefixes);
        $einheitAnker = $this->framework->anchor(\Taxmod\Core\Model\NodeClass\Anchor::Units);
        $istPraefix   = [];
        $istEinheit   = [];
        $bekannte     = $innereKnoten === [] ? [] : $this->gemerkteKnoten(array_keys($innereKnoten));

        foreach ($bekannte as $knoten) {
            if ($praefixAnker !== null && $knoten->parentNodeId === $praefixAnker->id) {
                $istPraefix[$knoten->id] = true;
            } elseif ($einheitAnker !== null && in_array($einheitAnker->id, array_map('intval', $knoten->ancestorIds()), true)) {
                $istEinheit[$knoten->id] = true;
            }
        }

        $zeichenKnoten = array_values(array_intersect_key($bekannte, $istPraefix + $istEinheit));
        $zeichen       = $zeichenKnoten === [] ? [] : ($this->labels?->forNodes($zeichenKnoten, SeededRole::Symbol, '') ?? []);

        $felder = [];

        foreach ($betroffen as $relation) {
            $ziel = $ziele[$relation->toNodeId] ?? null;

            if ($ziel === null) {
                continue;
            }

            $gewaehlt = [];

            foreach ($this->resolver->listOf($ziel, SummaryRenderer::FIELDS, $relation) as $glied) {
                if ($glied->aktiv && $glied->reference !== null) {
                    $gewaehlt[] = $glied->reference;
                }
            }

            // ⚠️ *Wer die Felder selbst mitbringt, bekommt sie auch — der Weg von {@see self::summaryTextsOf()} (D-885).*
            // ⚠️ *Eine leere Liste heisst «die Worte des Satzes selbst», nicht «keine Wahl» (D-902): wer die verweisenden Sätze benennt,
            // fragt über deren Kante — und die Feldwahl ihres Ziels gehört zum Satz, auf den gezeigt wird, nicht zu ihnen.*
            $felder[$relation->id] = array_key_exists($relation->id, $feldwahl) ? $feldwahl[$relation->id] : $gewaehlt;
        }

        // ⚠️ **Ohne Wahl das erste Textfeld — beim Knoten des Satzes, nicht am Ziel der Kante.** *Sein Befund am
        // 2026-09-12: «warum sieht das anders aus als summary bei Hersteller» — der Nachfolger zeigte «#20068», weil die Kante auf
        // «Software» zeigt und Software keine Felder hat; die Felder des Satzes gehören OS, dem Vater seines Knotens. Die Sätze
        // liegen unter dem Ziel, also entscheidet ihr Knoten. Alle Besitzer in einer Abfrage, dann je Knoten das erste Textfeld.*
        $knotenIds = [];

        foreach ($saetze as $satz) {
            $knotenIds[$satz->nodeId] = true;
        }

        $erstesTextfeld     = [];
        $feldwahlJeKnoten   = [];
        $textfelderJeKnoten = [];

        if ($knotenIds !== [] && $this->relations !== null) {
            $besitzerJeKnoten = [];
            $alleBesitzer     = [];

            foreach ($this->gemerkteKnoten(array_keys($knotenIds)) as $knoten) {
                $besitzerJeKnoten[$knoten->id] = $this->framework->inheritanceOwnersOf($knoten);
                $alleBesitzer                  = [...$alleBesitzer, ...$besitzerJeKnoten[$knoten->id]];

                // ⚠️ *Die Feldwahl eines inneren Satzes kommt von seinem Knoten oder dem nächsten Vorfahren, der eine trägt —
                // `summary_fields` steht an «Software», der Satz liegt an «DOS». Gesucht vom Knoten aufwärts, der nächste gewinnt.*
                foreach (array_reverse($besitzerJeKnoten[$knoten->id]) as $besitzerId) {
                    $besitzer = $besitzerId === $knoten->id ? $knoten : $this->gemerkterKnoten($besitzerId);

                    if ($besitzer === null) {
                        continue;
                    }

                    $gewaehlt = [];

                    foreach ($this->resolver->listOf($besitzer, SummaryRenderer::FIELDS) as $glied) {
                        if ($glied->aktiv && $glied->reference !== null) {
                            $gewaehlt[] = $glied->reference;
                        }
                    }

                    if ($gewaehlt !== []) {
                        $feldwahlJeKnoten[$knoten->id] = $gewaehlt;
                        break;
                    }
                }
            }

            $alleFelder = $this->relations->fieldRelationsOf(array_values(array_unique($alleBesitzer)));
            $typen      = $this->typesOf($alleFelder);
            $nachBesitzer = [];

            $alleTexte = [];

            foreach ($alleFelder as $feld) {
                if (! $feld->isSetting() && ($typen[$feld->id] ?? null) === SimpleType::Text) {
                    $nachBesitzer[$feld->fromNodeId] ??= $feld->id;
                    $alleTexte[$feld->fromNodeId][] = $feld->id;
                }
            }

            foreach ($besitzerJeKnoten as $knotenId => $besitzer) {
                foreach ($besitzer as $einer) {
                    if (isset($nachBesitzer[$einer])) {
                        $erstesTextfeld[$knotenId] = $nachBesitzer[$einer];
                        break;
                    }
                }

                // ⚠️ *Alle Textfelder in Erbfolge, von oben nach unten — der Rückfall nimmt das erste, das im Satz einen Wert hat. Gemessen am
                // Mainboard: das erste Textfeld von oben war leer, und die Revision zeigte «#29946» statt ihrer Bezeichnung «1.3».*
                foreach ($besitzer as $einer) {
                    foreach ($alleTexte[$einer] ?? [] as $textfeld) {
                        $textfelderJeKnoten[$knotenId][] = $textfeld;
                    }
                }
            }
        }

        $eigenesWort = static function (int $satzId, array $feldIds, int $stufe = 0) use (&$wort, $werte, $saetze, $erstesTextfeld, $feldwahlJeKnoten, $textfelderJeKnoten, $knotenNamen, $zeichen, $istPraefix, $istEinheit): string {
            $teile    = [];
            $knotenId = ($saetze[$satzId] ?? null)?->nodeId;

            if ($feldIds === [] && $knotenId !== null && isset($feldwahlJeKnoten[$knotenId])) {
                $feldIds = $feldwahlJeKnoten[$knotenId];
            } elseif ($feldIds === [] && $knotenId !== null) {
                // *Ohne Feldwahl das erste Textfeld mit Wert, in Erbfolge von oben; hat keines einen, das erste überhaupt.*
                $gefuellt = [];

                foreach ($werte[$satzId] ?? [] as $zeile) {
                    if ($zeile->value->text !== null && trim($zeile->value->text) !== '') {
                        $gefuellt[$zeile->relationId] = true;
                    }
                }

                foreach ($textfelderJeKnoten[$knotenId] ?? [] as $textfeld) {
                    if (isset($gefuellt[$textfeld])) {
                        $feldIds = [$textfeld];

                        break;
                    }
                }

                if ($feldIds === [] && isset($erstesTextfeld[$knotenId])) {
                    $feldIds = [$erstesTextfeld[$knotenId]];
                }
            }

            // ⚠️ **Ein Teil ohne Feldwahl und ohne Textfeld zeigt seine Werte** — *gemessen an einem Widerstand: die Zusammenfassung las
            // «0207 · #24558 · #25187», weil «Widerstandswert» ein Einheitenwert-Teil ist und keinen Text trägt. Eine Stufe tief also alle
            // Werte in ihrer Reihenfolge: «330 Ohm», «-20 20 Prozent».*
            if ($feldIds === [] && $stufe > 0) {
                $zahlen  = [];
                $praefix = '';
                $einheit = '';
                $sonst   = [];

                foreach ($werte[$satzId] ?? [] as $zeile) {
                    $v = $zeile->value;

                    if ($v->isNothing() || $v->referenceSpace === ReferenceSpace::Record) {
                        continue;
                    }

                    if ($v->referenceSpace === ReferenceSpace::Node && $v->reference !== null) {
                        $knotenWort = (string) ($zeichen[$v->reference] ?? $knotenNamen[$v->reference] ?? '');

                        if (isset($istPraefix[$v->reference])) {
                            $praefix = $knotenWort;
                        } elseif (isset($istEinheit[$v->reference])) {
                            $einheit = $knotenWort;
                        } else {
                            $sonst[] = $knotenWort;
                        }
                    } elseif ($v->decimal !== null || $v->int !== null) {
                        $roh      = $v->decimal ?? (string) $v->int;
                        $zahlen[] = str_contains($roh, '.') ? rtrim(rtrim($roh, '0'), '.') : $roh;
                    } elseif (! $v->isAReference()) {
                        $sonst[] = $v->rawValue();
                    }
                }

                // ⚠️ *Zwei Zahlen sind ein Bereich: gleich gross mit verschiedenem Vorzeichen «±20», sonst «-20 … 80»; eine Zahl steht allein.
                // Präfix und Einheit folgen als Zeichen: «±20 %», «33 µF», «250 mW» (D-797).*
                $betrag = match (true) {
                    count($zahlen) === 2 && ltrim($zahlen[0], '-') === ltrim($zahlen[1], '-') && str_starts_with($zahlen[0], '-') !== str_starts_with($zahlen[1], '-')
                                           => '±' . ltrim($zahlen[0], '-'),
                    count($zahlen) === 2   => $zahlen[0] . ' … ' . $zahlen[1],
                    default                => implode(' ', $zahlen),
                };

                $teile = array_values(array_filter(
                    [$betrag === '' ? '' : trim($betrag . ' ' . $praefix . $einheit), ...$sonst],
                    static fn (string $teil): bool => $teil !== ''
                ));

                return $teile === [] ? '#' . $satzId : implode(' ', $teile);
            }

            foreach ($feldIds as $feldId) {
                foreach ($werte[$satzId] ?? [] as $zeile) {
                    if ($zeile->relationId !== $feldId || $zeile->value->isNothing()) {
                        continue;
                    }

                    if ($zeile->value->referenceSpace === ReferenceSpace::Node) {
                        $teile[] = $knotenNamen[$zeile->value->reference] ?? '#' . $zeile->value->reference;
                    } elseif ($zeile->value->referenceSpace === ReferenceSpace::Record) {
                        if ($stufe === 0 && $zeile->value->reference !== null) {
                            $teile[] = $wort($zeile->value->reference, [], 1);
                        }
                    } elseif (! $zeile->value->isAReference()) {
                        $teile[] = $zeile->value->rawValue();
                    }

                    break;
                }
            }

            return $teile === [] ? '#' . $satzId : implode(SummaryRenderer::SEPARATOR, $teile);
        };

        // ⚠️ **Eine Revision heisst mit ihrem Halter** ([D-903](../../../docs/NewConcept/90-decision-log.md)) — *sein «ok» auf den Vorschlag
        // «FIC 386-SC-HG · A». Gemessen am 2026-09-22: das Board im Escom-PC hiess nur «A». Das Wort des Halters ohne dessen eigenen
        // Halter, damit die Kette nicht wächst. Was vorn schon im Halter steht, fällt weg — die Revision erbt den Hersteller und hiess
        // sonst «Fujitsu · D3400-A · Fujitsu · A11 GS 5».*
        $wort = static function (int $satzId, array $feldIds, int $stufe = 0) use (&$eigenesWort, $halter): string {
            if (! isset($halter[$satzId])) {
                return $eigenesWort($satzId, $feldIds, $stufe);
            }

            $vorn  = explode(SummaryRenderer::SEPARATOR, $eigenesWort($halter[$satzId], [], $stufe));
            $eigen = explode(SummaryRenderer::SEPARATOR, $eigenesWort($satzId, $feldIds, $stufe));

            while (count($eigen) > 1 && in_array($eigen[0], $vorn, true)) {
                array_shift($eigen);
            }

            return implode(SummaryRenderer::SEPARATOR, [...$vorn, ...$eigen]);
        };

        // ⚠️ **Was die Suche im Dialog durchsucht** ([D-791](../../../docs/NewConcept/90-decision-log.md) Schritt 2, Zeile 150) — *jeder
        // Wert des Satzes und eine Stufe tiefer die seiner Teile; ein Knotenverweis mit den Namen seiner Vorfahren, damit «SMD» auch einen
        // Satz mit «0603» unter SMD findet. Aus den schon geladenen Werten; Knoten und Vorfahren in zwei Zügen (`CD-7`).*
        $suche = [];

        if ($purpose === Purpose::Edit) {
            $knotenVerweise = [];

            foreach ($werte as $zeilen) {
                foreach ($zeilen as $zeile) {
                    if ($zeile->value->referenceSpace === ReferenceSpace::Node && $zeile->value->reference !== null) {
                        $knotenVerweise[$zeile->value->reference] = true;
                    }
                }
            }

            $verwiesen    = $knotenVerweise === [] ? [] : $this->gemerkteKnoten(array_keys($knotenVerweise));
            $vorfahrenIds = [];

            foreach ($verwiesen as $knoten) {
                foreach ($knoten->ancestorIds() as $vorfahrId) {
                    $vorfahrenIds[(int) $vorfahrId] = true;
                }
            }

            $vorfahren  = $vorfahrenIds === [] ? [] : $this->gemerkteKnoten(array_keys($vorfahrenIds));
            // *Nicht `static`: sie fragt das Gerüst, welche Vorfahren nichts sagen — gemessen, `static` warf «Using $this» in record-pages.*
            $knotenWort = function (int $id) use ($verwiesen, $vorfahren): string {
                $knoten = $verwiesen[$id] ?? null;

                if ($knoten === null) {
                    return '';
                }

                $namen = [];

                foreach ($knoten->ancestorIds() as $vorfahrId) {
                    // *Die Knoten des Gerüsts (Root, Primitives, Constants …) sagen über einen Satz nichts — gemessen standen sie in
                    // jedem Suchtext und hätten «units» oder «model» überall treffen lassen.*
                    if (isset($vorfahren[(int) $vorfahrId]) && ! $this->framework->isProtected($vorfahren[(int) $vorfahrId])) {
                        $namen[] = $vorfahren[(int) $vorfahrId]->name;
                    }
                }

                return implode(' ', [...$namen, $knoten->name]);
            };
            $worteVon = static function (int $satzId, int $stufe) use (&$worteVon, $werte, $knotenWort): string {
                $teile = [];

                foreach ($werte[$satzId] ?? [] as $zeile) {
                    $wert = $zeile->value;

                    if ($wert->isNothing()) {
                        continue;
                    }

                    if ($wert->referenceSpace === ReferenceSpace::Node && $wert->reference !== null) {
                        $teile[] = $knotenWort($wert->reference);
                    } elseif ($wert->referenceSpace === ReferenceSpace::Record && $wert->reference !== null) {
                        if ($stufe === 0) {
                            $teile[] = $worteVon($wert->reference, 1);
                        }
                    } elseif ($wert->decimal !== null) {
                        // ⚠️ *Ohne Nullen hinten (D-792, Zeile 153): «1.7» und «330» statt «1.7000000000» — sonst träfe eine getippte «1» auch «100».*
                        $teile[] = str_contains($wert->decimal, '.') ? rtrim(rtrim($wert->decimal, '0'), '.') : $wert->decimal;
                    } elseif (! $wert->isAReference()) {
                        $roh = (string) $wert->rawValue();

                        // ⚠️ *Gesucht wird, was einen Satz benennt, nicht seine Prosa ([D-877](../../../docs/NewConcept/90-decision-log.md)):
                        // ein Text über SUCHTEXT_HOECHSTENS Zeichen bleibt draussen, ein Datum ohne seine Mitternacht. Gemessen am 2026-09-19:
                        // mit 25 Mainboards wuchs die Satzauswahl «Models» auf 208 KB, zwei Drittel davon Suchtext, meist Herkunftshinweise.*
                        if ($wert->date !== null) {
                            $teile[] = str_replace(' 00:00:00', '', $roh);
                        } elseif (mb_strlen($roh) <= self::SUCHTEXT_HOECHSTENS) {
                            $teile[] = $roh;
                        }
                    }
                }

                return implode(' ', $teile);
            };

            foreach ($satzIds as $satzId) {
                $suche[$satzId] = mb_strtolower($worteVon($satzId, 0));
            }
        }

        $aus = $leer;

        foreach ($betroffen as $relation) {
            $feldIds = $felder[$relation->id] ?? [];
            $wert    = $values[$relation->id] ?? null;

            if ($wert?->referenceSpace === ReferenceSpace::Record && $wert->reference !== null) {
                $aus['worte'][$relation->id] = $wort($wert->reference, $feldIds);
            }

            foreach ($mehrfach[$relation->id] ?? [] as $weiterer) {
                $aus['mehrfach'][$relation->id][$weiterer] = $wort($weiterer, $feldIds);
            }

            // ⚠️ **Die Vorbelegung** ([D-791](../../../docs/NewConcept/90-decision-log.md) Schritt 3): *«filter» lässt nur passende
            // Sätze im Angebot, «first» behält alle und reicht die passenden an den Baum. Ohne Einstellung oder ohne Wert: nichts.*
            // ⚠️ *Seit D-844/D-845 sagen die gewählten Vorbelegungen der Stelle, was bleibt und was nach vorn gehört — je Paar gefiltert
            // oder sortiert.*
            [$behalten, $vorn] = $purpose === Purpose::Edit
                ? $this->presetFor($relation, $ziele[$relation->toNodeId] ?? null, $angebote[$relation->id] ?? [], $werte, $values, $ownerValues)
                : [null, null];
            $passend = $vorn === null ? null : array_fill_keys(array_keys($vorn), true);
            $reihe   = $angebote[$relation->id] ?? [];

            if ($vorn !== null) {
                usort($reihe, static fn (int $a, int $b): int => ($vorn[$b] ?? 0) <=> ($vorn[$a] ?? 0));
            }

            foreach ($reihe as $satzId) {
                if ($behalten !== null && ! isset($behalten[$satzId])) {
                    continue;
                }

                $aus['angebot'][$relation->id][$satzId] = $wort($satzId, $feldIds);
            }

            // ⚠️ *Der gewählte Satz steht im Angebot, auch wenn er nicht unter dem Ziel liegt — sonst zeigt das Auswahlfeld eine Nummer.*
            if ($purpose === Purpose::Edit && $wert?->referenceSpace === ReferenceSpace::Record && $wert->reference !== null && ! isset($aus['angebot'][$relation->id][$wert->reference])) {
                $aus['angebot'][$relation->id][$wert->reference] = $wort($wert->reference, $feldIds);
            }

            // ⚠️ **Der Baum des Dialogs** ([D-791](../../../docs/NewConcept/90-decision-log.md), Zeile 149) — *sein Wort: «einen baum
            // ansicht … wo ich erst den knoten auswähle … dann … datensatz aus». Dieselben Sätze wie das Angebot, nur nach ihren Knoten
            // gelegt; aus dem schon geladenen Unterbaum, ohne weitere Abfrage.*
            if ($purpose === Purpose::Edit && isset($ziele[$relation->toNodeId])) {
                $aus['baum'][$relation->id] = $this->recordTreeOf($ziele[$relation->toNodeId], $saetzeJeKnoten, $aus['angebot'][$relation->id] ?? [], $suche, $passend);
            }
        }

        return $aus;
    }

    /**
     * Die Vorbelegung an einem Verweisfeld ([D-791](../../../docs/NewConcept/90-decision-log.md) Schritt 3, als Vergleichspaare seit
     * [D-844](../../../docs/NewConcept/90-decision-log.md)/[D-845](../../../docs/NewConcept/90-decision-log.md)): welche der angebotenen Sätze
     * bleiben und welche nach vorn gehören.
     *
     * ⚠️ *Jedes Paar vergleicht eine Stufe: ein Feld des Satzes, in dem das Feld steht, oder des Satzes, der ihn hält, mit einem Feld des
     * angebotenen. Der Weg über mehrere Stufen ist mit D-844 entfallen. Ohne Wert gibt es kein Urteil — dann steht alles da wie ohne sie.*
     *
     * @param  list<int>                                                  $angebot
     * @param  array<int, list<\Taxmod\Core\Model\RelationRecord>>        $werte       Die Werte der angebotenen Sätze.
     * @param  array<int, TypedValue>                                     $values      Die Werte des Satzes, in dem das Feld steht.
     * @param  array<int, TypedValue>                                     $ownerValues Die Werte des Satzes, der ihn hält.
     * @return array{0: array<int, true>|null, 1: array<int, int>|null} Was bleibt (`null`: alles) und je Satz, wie viele Paare ihn nach vorn stellen.
     */
    private function presetFor(Relation $relation, ?Node $ziel, array $angebot, array $werte, array $values, array $ownerValues): array
    {
        if ($ziel === null || $this->resolver === null || $this->addons === null) {
            return [null, null];
        }

        $angebotWerte = null;
        $urteile      = [];

        foreach ($this->resolver->addonsAt($ziel, $relation) as $gewaehlt) {
            $addon = $this->addons->byClass($gewaehlt->klasse);

            if (! $addon instanceof \Taxmod\Core\Addon\ShapesOffer) {
                continue;
            }

            if ($angebotWerte === null) {
                $angebotWerte = [];

                foreach ($angebot as $satzId) {
                    foreach ($werte[$satzId] ?? [] as $zeile) {
                        $angebotWerte[$satzId][$zeile->relationId][] = $zeile->value;
                    }
                }
            }

            $urteile[] = $addon->judge(
                $angebot,
                $angebotWerte,
                $values + $ownerValues,
                $gewaehlt->settings,
                fn (TypedValue $wert, TypedValue $gesucht): bool => $this->presetValueMatches($wert, $gesucht),
                // *Das Bedingte am gesuchten Wert: «SMD filter, THT filter, fast SMD sort» (D-845).*
                function (TypedValue $gesucht) use ($addon): ?string {
                    if ($gesucht->referenceSpace !== ReferenceSpace::Node || $gesucht->reference === null) {
                        return null;
                    }

                    $knoten = $this->gemerkterKnoten($gesucht->reference);

                    return $knoten === null ? null : $this->resolver?->requirementValue($knoten, $addon::class, \Taxmod\Core\Addon\PresetAddon::BEHAVIOUR);
                }
            );
        }

        $behalten = null;
        $vorn     = null;

        // *Die Urteile zusammen: behalten wird, was jedes filternde Paar behält; nach vorn kommt, was die meisten Paare treffen.*
        foreach ($urteile as $urteil) {
            if ($urteil->keep !== null) {
                $behalten = $behalten === null ? $urteil->keep : array_intersect_key($behalten, $urteil->keep);
            }

            if ($urteil->first !== null) {
                $vorn ??= [];

                foreach (array_keys($urteil->first) as $satzId) {
                    $vorn[$satzId] = ($vorn[$satzId] ?? 0) + 1;
                }
            }
        }

        return [$behalten, $vorn];
    }

    /**
     * Passt ein Wert eines angebotenen Satzes zum vorbelegten Wert? Wie die Filterzeile: ein Knotenverweis trifft auch alles darunter,
     * Text enthält, eine Zahl ist gleich (D-768, D-791).
     */
    private function presetValueMatches(TypedValue $wert, TypedValue $gesucht): bool
    {
        if ($gesucht->referenceSpace === ReferenceSpace::Node && $gesucht->reference !== null) {
            if ($wert->referenceSpace !== ReferenceSpace::Node || $wert->reference === null) {
                return false;
            }

            return $wert->reference === $gesucht->reference
                || in_array($gesucht->reference, array_map('intval', $this->gemerkterKnoten($wert->reference)?->ancestorIds() ?? []), true);
        }

        return match (true) {
            $gesucht->text !== null => $wert->text !== null && mb_stripos($wert->text, $gesucht->text) !== false,
            $gesucht->int !== null, $gesucht->decimal !== null => $wert->comparedTo($gesucht) === 0,
            default => $wert->rawValue() === $gesucht->rawValue(),
        };
    }

    /**
     * Die Sätze unter einem Ziel als Baum seiner Knoten — je Knoten Tiefe, Name und seine eigenen Sätze; ein Ast ohne Satz fällt
     * weg, damit der Dialog nicht durch leere Ordner führt (D-791).
     *
     * @param  array<int, list<\Taxmod\Core\Model\NodeRecord>> $saetzeJeKnoten
     * @param  array<int, string>                              $worte Die Zusammenfassung je Satz-Id.
     * @param  array<int, string>                              $suche Was die Suche im Dialog je Satz-Id durchsucht (Schritt 2).
     * @return list<array{depth: int, name: string, records: array<int, string>, search: array<int, string>}>
     */
    private function recordTreeOf(Node $ziel, array $saetzeJeKnoten, array $worte, array $suche = [], ?array $passend = null): array
    {
        $kinder = [];

        foreach ($this->gemerkterUnterbaum($ziel) as $knoten) {
            if ($knoten->id !== $ziel->id && $knoten->parentNodeId !== null && ! $knoten->hide) {
                $kinder[$knoten->parentNodeId][] = $knoten;
            }
        }

        foreach ($kinder as $vater => $reihe) {
            usort($reihe, static fn (Node $a, Node $b): int => [$a->sortOrder, $a->name] <=> [$b->sortOrder, $b->name]);
            $kinder[$vater] = $reihe;
        }

        $zeilen = [];
        $lauf   = function (Node $knoten, int $tiefe) use (&$lauf, &$zeilen, $kinder, $saetzeJeKnoten, $worte, $suche, $passend): bool {
            $eigene  = [];
            $gesucht = [];

            foreach ($saetzeJeKnoten[$knoten->id] ?? [] as $satz) {
                if (isset($worte[$satz->id])) {
                    $eigene[$satz->id]  = $worte[$satz->id];
                    // *Die Zusammenfassung und der Knotenname gehören mit hinein — «Condensator» soll den Kondensator finden.*
                    // ⚠️ *Jedes Wort einmal (D-869): gesucht wird, ob jedes getippte Wort vorkommt — ein zweites «intel» findet nichts mehr, kostete
                    // aber in der Satzauswahl über alle Models ein Viertel der Vorlage.*
                    $gesucht[$satz->id] = implode(' ', array_unique(preg_split('/\s+/u', trim(
                        mb_strtolower($worte[$satz->id] . ' ' . $knoten->name) . ' ' . ($suche[$satz->id] ?? '')
                    )) ?: []));
                }
            }

            $stelle   = count($zeilen);
            // ⚠️ *Passende zuerst (D-791 Schritt 3) — innerhalb des Astes, damit der Baum der Baum bleibt.*
            $treffer = $passend === null ? [] : array_intersect_key($passend, $eigene);

            if ($treffer !== []) {
                $eigene = array_replace(array_intersect_key($eigene, $treffer), $eigene);
            }

            $zeilen[] = [
                'depth'   => $tiefe,
                'name'    => $knoten->name,
                'records' => $eigene,
                'search'  => $gesucht,
                'match'   => $treffer,
                // *Wo ein neuer Satz entstehen kann — die Seite des Knotens, vom Rand (Zeile 154).*
                'add'     => $this->newRecordUrl === null ? '' : ($this->newRecordUrl)($knoten->id),
                'addWord' => $this->newRecordWord,
            ];
            $etwas    = $eigene !== [];

            foreach ($kinder[$knoten->id] ?? [] as $kind) {
                $etwas = $lauf($kind, $tiefe + 1) || $etwas;
            }

            // *Ein leerer Ast bleibt stehen, wo man dort anlegen kann — sonst fände man den Knoten nicht, in dem der neue Satz entstehen soll.*
            if (! $etwas && $this->newRecordUrl === null) {
                array_splice($zeilen, $stelle);
            }

            return $etwas;
        };

        $lauf($ziel, 0);

        return $zeilen;
    }

    /**
     * Was die Validatoren einer Stelle an einem Wert auszusetzen haben — leer heisst: der Wert darf gespeichert werden.
     *
     * ⚠️ **Zeile 8, gebaut nach [D-158](../../../docs/NewConcept/90-decision-log.md) und [D-760](../../../docs/NewConcept/90-decision-log.md):**
     * *die Validatoren kommen aus der Einstellung `validator` der Stelle (Vorgabe am Typ, am Knoten, an der Kante), die Grenzen aus
     * denselben Einstellungen; alle laufen, und jede Beschwerde wird gemeldet — «einen Menschen nicht dreimal speichern lassen,
     * um drei Dinge zu erfahren».* Die Worte macht der Rand aus dem Schlüssel der Beschwerde (`AR-2`).
     *
     * @return list<\Taxmod\Core\Addon\Complaint>
     */
    public function complaintsFor(Relation $relation, TypedValue $value): array
    {
        if ($this->addons === null || $this->resolver === null || $value->isNothing()) {
            return [];
        }

        $ziel = $this->gemerkterKnoten($relation->toNodeId);

        if ($ziel === null) {
            return [];
        }

        // ⚠️ *Seit D-845 jede gewählte Zusatzfunktion, die beim Speichern prüft — nicht mehr nur die erste gewählte.*
        $gewaehlt = array_values(array_filter(
            $this->resolver->addonsAt($ziel, $relation),
            fn (\Taxmod\Core\Addon\ChosenAddon $eine): bool => $this->addons?->byClass($eine->klasse) instanceof \Taxmod\Core\Addon\ChecksOnSave
        ));

        if ($gewaehlt === []) {
            return [];
        }

        $einstellungen = [];

        foreach ($this->settingsForUseSites([$relation])[$relation->id] ?? [] as $key => $resolved) {
            $einstellungen[$key] = $resolved->value;
        }

        return $this->addons->complaintsAbout($value, $this->typeAt($relation), $gewaehlt, $einstellungen);
    }

    /**
     * Nur die Renderer-Wahl, die an dieser Stelle selbst gesetzt ist (D-759).
     *
     * @param array<string,ResolvedSetting> $settings
     * @return array<string,ResolvedSetting>
     */
    private function chosenHereOnly(array $settings): array
    {
        $wahl = $settings['renderer'] ?? null;

        if ($wahl instanceof ResolvedSetting && ! $wahl->setHere) {
            unset($settings['renderer']);
        }

        return $settings;
    }

    private function chosenRendererName(array $settings): string
    {
        $wahl = $settings['renderer'] ?? null;

        if (! $wahl instanceof ResolvedSetting || ($wahl->fromOwnerId === 0 && ! $wahl->setHere)) {
            return '';
        }

        return (string) ($wahl->value->text ?? '');
    }

    /**
     * Ein Wähler-Renderer (Dialog, Inline) zeichnet einen Baum der Kandidaten — die Kinder des Ziels. Den
     * reicht ihm kein Aufrufer, denn die Kandidaten stehen nicht in einer Angabe, sondern im Modell; hier wird
     * er gebaut. *Sein Befund am 2026-09-11 an `Prefixes` mit `chooser-inline`: «warum kein auswahlfeld
     * angezeigt wird» — der Renderer bekam keinen Baum und zeichnete ein leeres Stück.*
     */
    /**
     * @param array<string, ResolvedSetting> $settings Die aufgelösten Angaben der Stelle — darunter `dialog` und `display_size` des Wählers.
     */
    private function chooserMarkup(?Node $target, Renderer $renderer, array $settings, TypedValue $value, string $fieldName, string $formId, string $locale, Level $level, ?array $erlaubt = null): ?RenderResult
    {
        if ($target === null || ! $renderer instanceof ChooserRenderer) {
            return null;
        }

        $gewaehlt = $value->reference !== null && $value->reference !== $target->id ? $value->reference : null;

        // ⚠️ **Nur die erlaubten, wo der Teil sie nennt** (D-783) — *erlaubte Einheiten am Feld, erlaubte Präfixe an der Einheit.*
        // *Eine leere Liste, die der Teil **nennt**, heisst «nichts zu wählen» — der Präfix einer Einheit ohne Präfix.*
        if ($erlaubt !== null) {
            return $erlaubt === [] ? RenderResult::of('') : $this->allowedChoiceMarkup($target, $erlaubt, $gewaehlt, $fieldName, $formId, $locale, $level, $settings);
        }

        // ⚠️ **Ein Verweis auf den Typ «Node reference» selbst ist unbeschränkt: der ganze Baum, als Dialog** ([D-740](../../../docs/NewConcept/90-decision-log.md)) —
        // *sein Befund an `Organisation`: «preview funktioniert nicht reference type type … type in preview ist leer».*
        if ($this->typeNodes->nodeId(SimpleType::NodeRef) === $target->id) {
            $wurzel = $this->framework->root();

            // ⚠️ **Wo die Seite den einen Auswahlbaum zeichnet, öffnet der Verweis ihn** ([D-815](../../../docs/NewConcept/90-decision-log.md)) —
            // *gemessen am 2026-09-15 trug `Mikrocontroller` den ganzen Baum hier noch zweimal (Vorschau und neuer Satz), 310 KB.*
            if ($this->sharedPicker) {
                $name = $gewaehlt === null ? null : $this->gemerkterKnoten($gewaehlt)?->name;

                return RenderResult::of(self::pickOpener(
                    $fieldName,
                    $formId,
                    $gewaehlt,
                    $gewaehlt,
                    [$wurzel->id],
                    [],
                    $name ?? '',
                    // ⚠️ *Ohne Wahl trägt der Öffner kein lesbares Wort — der Name des Gegenstands steht für den Vorleser dabei, wie im Wähler selbst.*
                    $name === null
                        ? '<span class="taxmod-nothing">—</span><span class="screen-reader-text">' . RenderResult::escape($target->name) . '</span>'
                        : RenderResult::escape($name),
                    'button taxmod-dialog-open taxmod-pick-open',
                    true
                ));
            }

            return $this->nodeChooser(
                $wurzel,
                $fieldName,
                null,
                $gewaehlt,
                [$this->framework->trash()->id],
                [$wurzel->id],
                $gewaehlt === null ? null : $this->gemerkterKnoten($gewaehlt)?->name,
                '',
                ChooserRenderer::NAME,
                $locale,
                $level,
                '',
                '',
                $formId,
                [...$settings, ...ChooserRenderer::asDialog()]
            );
        }

        // ⚠️ **Eine Ebene ist eine Liste, kein Baum** — *sein Wort am 2026-09-12 an `Prefixes`: «sollte eine normale
        // dropdown liste anzeigen ist nur eine ebene, sollte list label zur anzeige verwenden».* Haben die Kinder des
        // Ziels selbst keine Kinder, zeichnet der Wähler ein Auswahlfeld, beschriftet mit der Rolle `select` — *ausser
        // jemand hat den Dialog eingeschaltet ([D-727](../../../docs/NewConcept/90-decision-log.md)): dann bleibt der Baum.*
        if (($settings[ChooserRenderer::DIALOG] ?? null)?->value->asBool() !== true) {
            $kinder = $this->gemerkteSichtbareKinder([$target->id])[$target->id] ?? [];
            $enkel  = $kinder === [] ? [] : array_filter($this->gemerkteSichtbareKinder(array_map(static fn (Node $k): int => $k->id, $kinder)));

            if ($kinder !== [] && $enkel === []) {
                $namen   = $this->labels?->forNodes($kinder, SeededRole::Select, $locale) ?? [];
                $options = [];

                foreach ($kinder as $kind) {
                    $options[$kind->id] = $namen[$kind->id] ?? $kind->name;
                }

                return $this->renderers->byName(ChoiceRenderer::NAME)->render($target, new RenderContext(
                    purpose: Purpose::Edit,
                    value: $gewaehlt === null ? TypedValue::nothing() : TypedValue::ofReference($gewaehlt),
                    // ⚠️ *Nur die Breite reist mit — die übrigen Angaben gehören dem Wähler, nicht dem Auswahlfeld.*
                    settings: isset($settings['display_size']) ? ['display_size' => $settings['display_size']] : [],
                    locale: $locale,
                    level: $level,
                    editable: true,
                    fieldName: $fieldName,
                    type: SimpleType::NodeRef,
                    surroundings: new Surroundings(options: $options, mayBeNothing: true, formId: $formId),
                ));
            }
        }

        // ⚠️ **Die gewählte Einheit steht als ihr Zeichen da** ([D-780](../../../docs/NewConcept/90-decision-log.md)) —
        // *sein Wort: «sollte eigentlich das Ohmzeichen sein und bei Watt W».* Die Rolle sagt die Stelle oder das Ziel;
        // fehlt die Beschriftung in der Rolle, bleibt der Name.
        $gewaehlterName = $gewaehlt === null ? null : $this->gemerkterWahlName($gewaehlt, $settings, $target, $locale);

        return $this->nodeChooser(
            $target,
            $fieldName,
            $target,
            $gewaehlt,
            [],
            [$target->id],
            $gewaehlterName,
            '',
            $renderer->name(),
            $locale,
            $level,
            '',
            '',
            $formId,
            $settings
        );
    }

    /**
     * Der an der Verwendungsstelle gewählte Behälter, wenn er für das Ziel taugt — sonst `null` (D-749).
     *
     * @param array<string, \Taxmod\Core\Model\ResolvedSetting> $useSiteSettings
     */
    private function containerChosenAt(array $useSiteSettings, Node $ziel, Purpose $purpose): ?Renderer
    {
        $name = $this->chosenRendererName($useSiteSettings);

        if ($name === '') {
            return null;
        }

        foreach ($this->renderers->eligibleFor($ziel, null, $purpose) as $one) {
            if ($one->name() === $name) {
                return $one;
            }
        }

        return null;
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
        /** Das Formular, dem das Suchfeld gehört — es steht ausserhalb des Baums, weil die Zeilen eigene Formulare tragen. */
        string $filterForm = '',
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
                surroundings: new Surroundings(rows: $rows, filterName: $filterName, filterValue: $filterValue, formId: $filterForm),
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
     * Ob eine Kante Sätze anbietet, also ein gewählter Wert ein Satzverweis ist ([D-812](../../../docs/NewConcept/90-decision-log.md)) —
     * dieselbe Frage, die das Zeichnen stellt, damit Zeichnen und Zurücklesen nicht zwei Antworten geben.
     */
    public function referencesRecords(Relation $relation): bool
    {
        return $this->drawsAsSummary($relation, $this->typesOf([$relation])[$relation->id] ?? null, $this->settingsForUseSites([$relation])[$relation->id] ?? []);
    }

    /**
     * Der Renderer, den ein neuer Knoten bekommt ([D-808](../../../docs/NewConcept/90-decision-log.md)): der Standard seines Typs, sonst
     * der, der am Vater gilt, sonst das Formular.
     *
     * ⚠️ *Sein Wort: «every node has a renderer, it is defined by its type or by the type of the father node during creation». Der
     * Rückfall ist nie die Antwort — er ist die Fehlermarke (R14b) und nicht wählbar.*
     */
    public function rendererForNewNode(Node $node, ?Node $parent): string
    {
        $typ = $this->typeOfNode($node);

        if ($typ !== null && ($standard = $this->renderers->defaultFor($typ)) !== $this->renderers->fallback()) {
            return $standard->name();
        }

        $amVater = $parent === null ? null : $this->rendererNameFor($parent, Purpose::Display);

        return $amVater !== null && $this->renderers->knows($amVater) ? $amVater : FormRenderer::NAME;
    }

    /**
     * Mehrere Werte eines einfachen Textfeldes für die Eingabe zusammengelegt ([D-811](../../../docs/NewConcept/90-decision-log.md)): «C1, C2».
     *
     * ⚠️ *Sein Befund: «add row does not work». Die Maske zeigte nur den letzten Wert, und das nächste Speichern verweigerte das Feld.
     * Nur Text wird zusammengelegt — eine Zahl mit Komma wäre eine andere Zahl.*
     */
    public static function collectedValue(?TypedValue $vorher, TypedValue $neu): TypedValue
    {
        if ($vorher === null || $vorher->text === null || $neu->text === null || $vorher->isAReference() || $neu->isAReference()) {
            return $neu;
        }

        return TypedValue::ofText($vorher->text . self::VALUE_JOIN . $neu->text);
    }

    /** Was mehrere Textwerte in einer Eingabe trennt ([D-811](../../../docs/NewConcept/90-decision-log.md)). */
    public const VALUE_JOIN = ', ';

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

        $knoten = $node instanceof Node ? $node : $this->gemerkterKnoten($node->toNodeId);

        if ($knoten === null) {
            return [];
        }

        $aus = $this->resolver->contractOf($knoten)->attributes;

        // ⚠️ *Was eine gewählte Zusatzfunktion an diesem Knoten bedingt (D-845) — nur am Knoten, und nur wo sie hinzeigt.*
        if ($node instanceof Node) {
            foreach ($this->resolver->requirementsAt($knoten) as $name => $bedingt) {
                $aus[$name] ??= $bedingt;
            }
        }

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

        // ⚠️ **Die Zusatzfunktionen als geordnete Liste mit ihren Feldern je Glied** ([D-845](../../../docs/NewConcept/90-decision-log.md)).
        if ($typ === \Taxmod\Core\Model\NodeClass\AttributeType::Object && $erklaert->objectClass === \Taxmod\Core\Addon\Addon::class) {
            return new RenderedSetting($key, SettingShape::Switch, null, $setting, $fieldPrefix === '' ? RenderResult::of('') : $this->drawAddons($subject, $fieldPrefix, $formId), CheckboxRenderer::NAME, $subjectType, band: $erklaert->band);
        }

        [$shape, $simple] = match ($typ) {
            \Taxmod\Core\Model\NodeClass\AttributeType::Bool    => [SettingShape::Switch, SimpleType::Bool],
            \Taxmod\Core\Model\NodeClass\AttributeType::Int     => [SettingShape::Whole, SimpleType::Int],
            \Taxmod\Core\Model\NodeClass\AttributeType::Decimal => [SettingShape::Exact, SimpleType::Decimal],
            \Taxmod\Core\Model\NodeClass\AttributeType::Text    => [SettingShape::Words, SimpleType::Text],
            \Taxmod\Core\Model\NodeClass\AttributeType::Date    => [SettingShape::Words, SimpleType::DateTime],
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
                $subjectType,
                band: $erklaert->band
            );
        }

        // ⚠️ **Eine Verweisliste mit Anker ist eine Kaskade von Schaltern, kein Auswahlfeld** ([D-732](../../../docs/NewConcept/90-decision-log.md)).
        // *Sein Befund an `erlaubte_praefixe`: «müsste multi auswahl sein ist es aber nicht, ausserdem würde ich eine schalter
        // kaskade netter finden als jedes einzeln aus der liste auszuwählen».*
        // ⚠️ *Eine Liste von Feldern (D-752) wird wie eine verankerte Knotenliste gezeichnet: Haken je Kandidat, die Kandidaten sind die Felder des Knotens.*
        if ($erklaert->list && $fieldPrefix !== '' && (($typ === \Taxmod\Core\Model\NodeClass\AttributeType::NodeRef && $erklaert->from !== null) || $typ === \Taxmod\Core\Model\NodeClass\AttributeType::RelationRef)) {
            return new RenderedSetting($key, SettingShape::Switch, null, $setting, $this->switchCascade($subject, $erklaert, $fieldPrefix, $formId, $locale), CheckboxRenderer::NAME, $subjectType, band: $erklaert->band);
        }

        $options = match ($typ) {
            \Taxmod\Core\Model\NodeClass\AttributeType::Enum    => array_combine($erklaert->enumCases(), $erklaert->enumCases()),
            \Taxmod\Core\Model\NodeClass\AttributeType::NodeRef => $this->nodesOffered($erklaert),
            \Taxmod\Core\Model\NodeClass\AttributeType::RelationRef => [],
            default                                             => $this->objectsOffered($erklaert, $subject),
        };

        // ⚠️ **Die Auswahl zeigt den Renderer, der gilt, nie bloss die erste Zeile** ([D-808](../../../docs/NewConcept/90-decision-log.md)) — *sein
        // Wort: «you see the renderer that is choosen not one that is the first line in the select and not chooseen». Ohne eigene Wahl steht
        // hier die Vorgabe der Auflösung; gilt am Knoten ein anderer, steht der da, und fehlt er unter den angebotenen, wird er vorn angeboten.*
        $gezeigt = $setting->value;

        if ($key === 'renderer' && $typ === \Taxmod\Core\Model\NodeClass\AttributeType::Object) {
            if ($subject instanceof Node && $setting->fromOwnerId === 0 && ! $setting->setHere) {
                $gilt    = $this->rendererNameFor($subject, Purpose::Display);
                $gezeigt = $gilt !== null && $this->renderers->knows($gilt) ? TypedValue::ofText($gilt) : $gezeigt;
            }

            $name = (string) ($gezeigt->text ?? '');

            if ($name !== '' && ! isset($options[$name])) {
                $options = [$name => $name] + $options;
            }
        }

        $renderer  = $this->renderers->byName(ChoiceRenderer::NAME);
        $gezeichnet = $renderer->render($subject, new RenderContext(
            purpose: $purpose,
            value: $gezeigt,
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

        return new RenderedSetting($key, $shape, null, $setting, $gezeichnet, $renderer->name(), $subjectType, band: $erklaert->band);
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

        $knoten = $subject instanceof Node ? $subject : $this->gemerkterKnoten($subject->toNodeId);

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
     * Je Kandidat des Ankers ein Schalter: an, wenn ein aktives Glied auf ihn zeigt. Die Felder heissen
     * `<prefix>_set[<attribut>][<knoten>]` mit `0`/`1`; die Beschriftung ist die Rolle `select` des Kandidaten.
     */
    private function switchCascade(Renderable $subject, \Taxmod\Core\Model\NodeClass\AttributeDeclaration $erklaert, string $fieldPrefix, string $formId, string $locale): RenderResult
    {
        $knoten = $subject instanceof Node ? $subject : ($subject instanceof Relation ? $this->gemerkterKnoten($subject->toNodeId) : null);

        if ($knoten === null) {
            return RenderResult::of('');
        }

        // ⚠️ **Die Kandidaten: Kinder des Ankers für eine Knotenliste, die Felder des Knotens für eine Feldliste**
        // ([D-752](../../../docs/NewConcept/90-decision-log.md)). *Je Kandidat Id und Wort; für Felder ist das Wort
        // der Feldname, denn das Feld ist es, das gewählt wird.*
        $kandidaten = [];

        if ($erklaert->type === \Taxmod\Core\Model\NodeClass\AttributeType::RelationRef && $erklaert->fieldsFrom !== null) {
            // ⚠️ *Woher die Felder kommen, sagt die Erklärung (D-844, D-769) — derselbe Weg wie bei den Zusatzfunktionen. Sein Befund am Sprung
            // «Kompatibilität»: «felder sind hier aber nicht definiert» — gesucht wurde am Typ «Jump», der keine Felder hat.*
            $kandidaten = $this->addonFieldCandidates($erklaert->fieldsFrom, $knoten, $subject instanceof Relation ? $subject : null);
        } elseif ($erklaert->type === \Taxmod\Core\Model\NodeClass\AttributeType::RelationRef) {
            foreach ($this->relations?->fieldRelationsOf($this->framework->inheritanceOwnersOf($knoten)) ?? [] as $feld) {
                if (! $feld->isSetting()) {
                    $kandidaten[$feld->id] = $feld->name;
                }
            }
        } else {
            $anker = $erklaert->from === null ? null : $this->framework->anchor($erklaert->from);

            if ($anker === null) {
                return RenderResult::of('');
            }

            $kinder = $this->candidatesUnder($anker, $erklaert);
            $namen  = $this->labels?->forNodes($kinder, SeededRole::Select, $locale) ?? [];

            foreach ($kinder as $kind) {
                $kandidaten[$kind->id] = $namen[$kind->id] ?? $kind->name;
            }
        }

        $an     = [];
        $zeile  = [];
        $stelle = [];

        foreach ($this->resolver?->listOf($knoten, $erklaert->name, $subject instanceof Relation ? $subject : null) ?? [] as $glied) {
            if ($glied->reference === null) {
                continue;
            }

            $zeile[$glied->reference]  = $glied->rowId;
            $stelle[$glied->reference] = $glied->position;

            if ($glied->aktiv) {
                $an[$glied->reference] = true;
            }
        }

        // ⚠️ **Eine geordnete Liste, deren Glieder sich verschieben lassen** ([D-794](../../../docs/NewConcept/90-decision-log.md)) — *sein
        // Wort an `summary_fields`: «should be a ordered list and position should be moveable». Die gewählten stehen oben in ihrer
        // Reihenfolge, jede mit ihrer Stelle; die übrigen darunter, wie der Anker oder der Knoten sie liefert. Ohne Skript: die Stelle
        // ist eine Zahl und wird mit der Seite gespeichert, über denselben Weg wie die Glieder einer Liste (`_list`).*
        $gewaehlt = array_values(array_filter(array_keys($an), static fn (int $id): bool => isset($kandidaten[$id])));
        usort($gewaehlt, static fn (int $a, int $b): int => ($stelle[$a] ?? 0) <=> ($stelle[$b] ?? 0));

        $prefix     = (string) preg_replace('/^([A-Za-z0-9_]+)/', '$1_set', $fieldPrefix, 1) . '[' . $erklaert->name . ']';
        $listPrefix = (string) preg_replace('/^([A-Za-z0-9_]+)/', '$1_list', $fieldPrefix, 1) . '[' . $erklaert->name . ']';
        $form       = $formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"';

        // ⚠️ **Gewählte als Liste, der Rest als Auswahlfeld mit «hinzufügen»** ([D-799](../../../docs/NewConcept/90-decision-log.md)) — *sein
        // Wort an `erlaubte_einheiten`: «to space consuming, better would be a selection list and an add button. select unit and press add,
        // added showing up in a list and can be removed again … also used for the field selections». Ein gewähltes Glied reist als
        // verborgene 1 (entfernt: 0), das Auswahlfeld als `[add]` — ohne Skript wählt man dort und speichert die Seite.*
        $html = '<span class="taxmod-switch-picker"><ol class="taxmod-switch-cascade">';

        foreach ($gewaehlt as $platz => $kandidatId) {
            $name  = RenderResult::escape($prefix . '[' . $kandidatId . ']');
            $html .= '<li class="taxmod-switch-chosen" data-taxmod-id="' . (int) $kandidatId . '">'
                . '<input type="hidden" class="taxmod-switch-member" name="' . $name . '" value="1"' . $form . '>'
                . '<span class="taxmod-switch-name">' . RenderResult::escape($kandidaten[$kandidatId]) . '</span>'
                // ⚠️ *Verschoben mit Pfeilen — sein Wort: «more ordered by arrows». Die Stelle reist verborgen; das Skript tauscht die
                // Zeile mit ihrer Nachbarin und zählt die Stellen neu. Die Pfeile sind `type="button"` und schicken nie ab.*
                . (isset($an[$kandidatId], $zeile[$kandidatId])
                    ? '<input type="hidden" class="taxmod-setting-list-position"'
                        . ' name="' . RenderResult::escape($listPrefix . '[' . $zeile[$kandidatId] . '][position]') . '"'
                        . ' value="' . (int) ($stelle[$kandidatId] ?? $platz) . '"' . $form . '>'
                        // *Wie die Pfeile der Feldzeilen: dasselbe Zeichen, randlos, der erste nach oben und der letzte nach unten ausgegraut —
                        // sein Wort «like the fields».*
                        . ' <button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-list-move" data-taxmod-move="up"'
                        . ($platz === 0 ? ' disabled style="color:#1d2327;opacity:.35"' : ' style="color:#1d2327"') . '>'
                        . IconMarkup::dashicon('arrow-up-alt2', $kandidaten[$kandidatId]) . '</button>'
                        . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-list-move" data-taxmod-move="down"'
                        . ($platz === count($gewaehlt) - 1 ? ' disabled style="color:#1d2327;opacity:.35"' : ' style="color:#1d2327"') . '>'
                        . IconMarkup::dashicon('arrow-down-alt2', $kandidaten[$kandidatId]) . '</button>'
                    : '')
                . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-list-remove" style="color:#b32d2e">'
                // *Löschen ist immer der Mülleimer (D-828).*
                . IconMarkup::dashicon('trash', $kandidaten[$kandidatId]) . '</button>'
                . '</li>';
        }

        $frei = [];

        foreach (array_diff(array_keys($kandidaten), $gewaehlt) as $kandidatId) {
            $frei[(int) $kandidatId] = $kandidaten[$kandidatId];
        }

        // *Das eine Auswahlfeld (SelectMarkup): sein Befund an `filter_feld` — «select felder leer aber nicht ausgegraut».*
        return RenderResult::of($html . '</ol><span class="taxmod-switch-add">'
            . SelectMarkup::of($prefix . '[add]', $frei, null, true, $formId, ['class' => 'taxmod-switch-candidates'])
            . SelectMarkup::addButton($frei, $erklaert->name)
            . '</span></span>');
    }

    /**
     * Die Kandidaten unter einem Anker: seine Kinder — oder, wo die Kinder nicht von der Verweisklasse sind, die Knoten dieser
     * Klasse im ganzen Ast.
     *
     * ⚠️ *Die Einheiten liegen unter «With prefix» und «Without prefix», eine Ebene tiefer als die Präfixe (D-783). Wo die Kinder
     * schon passen — Präfixe, Rollen —, bleibt es bei den Kindern.*
     *
     * @return list<Node>
     */
    private function candidatesUnder(Node $anker, \Taxmod\Core\Model\NodeClass\AttributeDeclaration $erklaert): array
    {
        $kinder = $this->nodes->childrenOf($anker);

        if ($erklaert->refersTo === null || array_filter($kinder, static fn (Node $kind): bool => $kind->klasse !== $erklaert->refersTo) === []) {
            return $kinder;
        }

        return array_values(array_filter(
            $this->gemerkterUnterbaum($anker),
            static fn (Node $knoten): bool => $knoten->id !== $anker->id && $knoten->klasse === $erklaert->refersTo
        ));
    }

    /**
     * Welche Knoten die Wähler in einem Teil anbieten dürfen: die Einheiten, die das tragende Feld erlaubt (D-783), und die
     * Präfixe, die die gewählte Einheit erlaubt ([D-697](../../../docs/NewConcept/90-decision-log.md)). Leer heisst alle — dann
     * fehlt das Feld in der Antwort.
     *
     * ⚠️ *Erkannt am Ziel des inneren Feldes, das ein Anker des Gerüsts ist — nicht an einem Namen (`CD · Prohibited`). Ist keine
     * Einheit gewählt, aber genau eine erlaubt, gilt sie: sonst stünde bei einem neuen Satz die volle Präfixliste neben «Ohm».*
     *
     * @param  list<Relation>         $felder
     * @param  array<int, TypedValue> $werte
     * @return array<int, list<int>>
     */
    private function allowedChoicesIn(Node $ziel, Relation $traeger, array $felder, array $werte): array
    {
        if ($this->resolver === null) {
            return [];
        }

        $einheiten = $this->framework->anchor(\Taxmod\Core\Model\NodeClass\Anchor::Units);
        $praefixe  = $this->framework->anchor(\Taxmod\Core\Model\NodeClass\Anchor::Prefixes);
        $aktive    = static fn (array $glieder): array => array_values(array_map(
            static fn ($glied): int => (int) $glied->reference,
            array_filter($glieder, static fn ($glied): bool => $glied->aktiv && $glied->reference !== null)
        ));
        $aus     = [];
        $einheit = null;

        foreach ($felder as $feld) {
            if ($einheiten === null || $feld->toNodeId !== $einheiten->id) {
                continue;
            }

            $erlaubt = $aktive($this->resolver->listOf($ziel, \Taxmod\Core\Model\NodeClass\UnitValue::ERLAUBTE_EINHEITEN, $traeger));

            if ($erlaubt !== []) {
                $aus[$feld->id] = $erlaubt;
            }

            $einheit = ($werte[$feld->id] ?? null)?->reference ?? (count($erlaubt) === 1 ? $erlaubt[0] : null);
        }

        $einheitKnoten = $einheit === null ? null : $this->gemerkterKnoten($einheit);

        foreach ($felder as $feld) {
            if ($praefixe === null || $einheitKnoten === null || $feld->toNodeId !== $praefixe->id) {
                continue;
            }

            // ⚠️ *Eine Einheit ohne Präfix (`mit_praefix` aus — Prozent, Kelvin) bietet keinen an: die leere Liste heisst hier «keiner»,
            // nicht «alle». Gemessen an `Toleranz`: neben «% - Prozent» stand die ganze Präfixliste.*
            if (($this->resolver->forNode($einheitKnoten)['mit_praefix'] ?? null)?->value->asBool() !== true) {
                $aus[$feld->id] = [];

                continue;
            }

            $erlaubt = $aktive($this->resolver->listOf($einheitKnoten, \Taxmod\Core\Model\NodeClass\Unit::ERLAUBTE_PRAEFIXE));

            if ($erlaubt !== []) {
                $aus[$feld->id] = $erlaubt;
            }
        }

        return $aus;
    }

    /**
     * Ein Wähler, der nur die erlaubten Knoten anbietet — als Auswahlfeld, gleich wie tief sie im Ziel liegen (D-783).
     *
     * ⚠️ *Sein Wort: «only Ohms allowed, and so this is preselected and not changeable». Bei genau einem erlaubten Knoten zeichnet
     * {@see ChoiceRenderer} ihn vorgewählt und ausgegraut (R30). Ein gesperrtes Feld schickt nichts ab — also trägt ein verstecktes
     * Feld den Wert, damit auch ein neuer Satz ihn speichert. Ein gespeicherter Wert, der nicht mehr erlaubt ist, bleibt ein
     * Eintrag ([D-360](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * @param list<int>                      $erlaubt
     * @param array<string, ResolvedSetting> $settings
     */
    private function allowedChoiceMarkup(Node $target, array $erlaubt, ?int $gewaehlt, string $fieldName, string $formId, string $locale, Level $level, array $settings): RenderResult
    {
        $ids    = $gewaehlt === null || in_array($gewaehlt, $erlaubt, true) ? $erlaubt : [...$erlaubt, $gewaehlt];
        $knoten = $this->gemerkteKnoten($ids);
        $namen  = $this->labels?->forNodes(array_values($knoten), SeededRole::Select, $locale) ?? [];
        $options = [];

        foreach ($ids as $id) {
            if (isset($knoten[$id])) {
                $options[$id] = $namen[$id] ?? $knoten[$id]->name;
            }
        }

        $einzig = count($options) === 1 ? (int) array_key_first($options) : null;
        $wert   = $gewaehlt ?? $einzig;

        $auswahl = $this->renderers->byName(ChoiceRenderer::NAME)->render($target, new RenderContext(
            purpose: Purpose::Edit,
            value: $wert === null ? TypedValue::nothing() : TypedValue::ofReference($wert),
            settings: isset($settings['display_size']) ? ['display_size' => $settings['display_size']] : [],
            locale: $locale,
            level: $level,
            editable: true,
            fieldName: $fieldName,
            type: SimpleType::NodeRef,
            surroundings: new Surroundings(options: $options, mayBeNothing: $einzig === null, formId: $formId),
        ));

        if ($einzig === null || $fieldName === '') {
            return $auswahl;
        }

        return new RenderResult(
            $auswahl->markup
                . '<input type="hidden" name="' . RenderResult::escape($fieldName) . '" value="' . $einzig . '"'
                . ($formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"') . '>',
            $auswahl->usedRelations,
            $auswahl->condition
        );
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

        // ⚠️ *Mit Anker aus dessen Kindern, sonst aus allen der Klasse — sein Befund: «warum werden die konstanten bei
        // label role angezeigt» (D-728).*
        $anker = $erklaert->from === null ? null : $this->framework->anchor($erklaert->from);

        foreach ($erklaert->from === null ? $this->nodes->ofClass($erklaert->refersTo) : ($anker === null ? [] : $this->candidatesUnder($anker, $erklaert)) as $knoten) {
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

        $ziel    = $this->gemerkterKnoten($subject->toNodeId);
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
        $type = $this->typeAt($relation);

        // ⚠️ **Zeigt das Feld auf einen Knoten ohne Typ — eine Kategorie, eine Auswahl —, wird der Zielknoten
        // ausgelegt, also gelten seine Behälter** (D-749). *Sein Wort am 2026-09-12: «Klasse/Knoten Kategorie sollte
        // auch table, form, compact haben». Gemessen: die Auswahl am Feld `Address` war leer, weil Formular, Tabelle
        // und Compact nur einen Knoten annehmen und hier die Kante gefragt wurde.*
        $subject = $type === null ? ($this->gemerkterKnoten($relation->toNodeId) ?? $relation) : $relation;

        return $this->renderers->eligibleFor(
            $subject,
            $type,
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
        $knoten = $this->gemerkterKnoten($nodeId);

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

        $besitzer = $this->gemerkterKnoten($subject->fromNodeId);

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
        $targets = $this->gemerkteKnoten(array_map(static fn (Relation $e): int => $e->toNodeId, $relations));

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
            $kinder = $this->gemerkteSichtbareKinder(array_values(array_unique($offen)));

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

    /** Längster Text, der noch in den Suchtext einer Satzauswahl kommt ([D-877](../../../docs/NewConcept/90-decision-log.md)). */
    private const SUCHTEXT_HOECHSTENS = 120;

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
            $knoten = $this->gemerkteKnoten($offen);
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
     * Ein **mehrfaches** Feld als geordnete Liste ([D-842](../../../docs/NewConcept/90-decision-log.md), Gestalt nach
     * [D-794](../../../docs/NewConcept/90-decision-log.md)/[D-799](../../../docs/NewConcept/90-decision-log.md)) — je Wert eine Zeile mit
     * Pfeilen und Mülleimer, darunter die Eingabe für den nächsten mit «+».
     *
     * ⚠️ *Sein Wort: «wir haben grundsätzlich schon eine darstellungsform für multiple ordered lists mit aktivierung die sollten wir auch
     * für den renderer verwenden» — also dieselben Klassen wie die Schalterliste der Einstellungen, damit Bild und Skript dieselben sind.*
     *
     * ⚠️ *Die Reihenfolge ist die der Zeilen: die Adresse lautet `…[<Kante>][values][]`, und der Rand liest sie in der Reihenfolge, in der
     * die Maske sie schickt. Ein Haken zum Abschalten gibt es hier nicht — ein Wert steht oder steht nicht.*
     */
    private function valueListBelow(
        Relation $relation,
        RenderContext $context,
        Purpose $purpose,
        string $fieldPrefix,
        string $formId,
        bool $editable,
        int $recordId,
        /** @var array<int, string> Satz-Id ⇒ Wort, für die Sätze eines mehrfachen Verweises (D-859). */
        array $woerter = []
    ): ?array {
        if ($recordId === 0 || $relation->isSetting() || ! $relation->multiplicity->allowsMany() || $this->records === null) {
            return null;
        }

        $werte = [];

        foreach ($this->werteJeSatz($recordId) as $zeile) {
            if ($zeile->relationId === $relation->id && ! $zeile->value->isNothing()) {
                $werte[] = $zeile->value;
            }
        }

        $verweise = $woerter !== [] || $context->surroundings->options !== [] || array_filter($werte, static fn (TypedValue $w): bool => $w->referenceSpace === ReferenceSpace::Record) !== [];

        // ⚠️ **Angezeigt stehen alle Werte** ([D-859](../../../docs/NewConcept/90-decision-log.md)) — sein Befund: beim IV 386 stand nur eine
        // CPU, obwohl zwei eingetragen sind. *Ein Satzverweis ist ein Link auf seinen Satz (D-852).*
        if ($purpose === Purpose::Display) {
            // ⚠️ *Mehrere Medien — «Bilder», «Quellen» — jedes für sich (D-865): gemessen stand sonst «media:15685, media:15686, …» als
            // ein einziger Link da, weil der alte Weg die Werte mit Komma zu einem verklebt. Bilder nebeneinander, Dateien untereinander.*
            if ($werte !== [] && $this->typeAt($relation) === SimpleType::Media && $this->inSatztabelle) {
                // ⚠️ **In der Tabelle nur die Anzahl** ([D-878](../../../docs/NewConcept/90-decision-log.md)) — sein Wort: *«records titelbild
                // anzuzeigen ist ganz nett, die andern stören aber eher also nur anzahl zeigen»*. Ein einzelnes Medium (Titelbild) bleibt Bild.*
                return ['renderer' => \Taxmod\Core\Renderer\MediaRenderer::NAME, 'rows' => [], 'rowActs' => [], 'after' => '', 'result' => RenderResult::of(
                    '<span class="taxmod-value taxmod-media-count">' . \Taxmod\Core\Renderer\IconMarkup::dashicon('format-gallery') . ' ' . count($werte) . '</span>'
                )];
            }

            if ($werte !== [] && $this->typeAt($relation) === SimpleType::Media) {
                $neuerTab = $this->mediaOpensNewTab([$relation], $relation->id);
                $bilder   = '';
                $dateien  = [];

                foreach ($werte as $wert) {
                    $adresse = trim((string) $wert->text);
                    $link    = \Taxmod\Core\Renderer\MediaRenderer::link($adresse, '', $neuerTab, $this->media);

                    if (\Taxmod\Core\Renderer\MediaRenderer::fileOf($adresse, $this->media)?->isImage() === true) {
                        $bilder .= $link . ' ';
                    } else {
                        $dateien[] = $link;
                    }
                }

                return ['renderer' => \Taxmod\Core\Renderer\MediaRenderer::NAME, 'rows' => [], 'rowActs' => [], 'after' => '', 'result' => RenderResult::of(
                    '<span class="taxmod-value taxmod-media-list">' . trim($bilder) . ($bilder !== '' && $dateien !== [] ? '<br>' : '') . implode('<br>', $dateien) . '</span>'
                )];
            }

            // ⚠️ **Auch mehrere Konstanten stehen alle da** (D-900) — *gemessen am 2026-09-21 an der Netzwerkkarte #37641: drei
            // Herkunftsarten gespeichert, in der Zelle stand nur «Vermutung». Beim Anzeigen gibt es kein Angebot, also galt ein Verweis
            // auf Knoten nicht als Liste, und die Zelle fiel auf den Einzelwert zurück. Die Namen kommen wie im Wähler aus den Labels.*
            $aufKnoten = array_values(array_filter(
                $werte,
                static fn (TypedValue $w): bool => $w->referenceSpace === ReferenceSpace::Node && $w->reference !== null
            ));

            if ($woerter === [] && count($aufKnoten) > 1) {
                $knoten = $this->gemerkteKnoten(array_map(static fn (TypedValue $w): int => (int) $w->reference, $aufKnoten));
                $namen  = $this->labels?->forNodes(array_values($knoten), SeededRole::Select, $context->locale) ?? [];

                return ['renderer' => SummaryRenderer::NAME, 'rows' => [], 'rowActs' => [], 'after' => '', 'result' => RenderResult::of(
                    '<span class="taxmod-value taxmod-ref-list">' . implode('<br>', array_map(
                        static fn (TypedValue $w): string => RenderResult::escape($namen[(int) $w->reference] ?? $knoten[(int) $w->reference]->name ?? '#' . (int) $w->reference),
                        $aufKnoten
                    )) . '</span>'
                )];
            }

            if ($werte === [] || ! $verweise) {
                return null;
            }

            $teile = [];

            foreach ($werte as $wert) {
                $wort = RenderResult::escape($woerter[(int) $wert->reference] ?? '#' . (int) $wert->reference);
                $ziel = $this->recordLink === null || $wert->reference === null ? '' : ($this->recordLink)($wert->reference);
                $teile[] = $ziel === '' ? $wort : '<a class="taxmod-record-link" href="' . RenderResult::escape($ziel) . '">' . $wort . '</a>';
            }

            return ['renderer' => SummaryRenderer::NAME, 'rows' => [], 'rowActs' => [], 'after' => '', 'result' => RenderResult::of('<span class="taxmod-value taxmod-ref-list">' . implode('<br>', $teile) . '</span>')];
        }

        if ($purpose !== Purpose::Edit || ! $editable || $fieldPrefix === '') {
            return null;
        }

        // ⚠️ **Mehrfache Verweise als Zeilen wie die Teile** ([D-859](../../../docs/NewConcept/90-decision-log.md)) — sein Wort: *«ja bitte folge
        // deinen vorschlag für mehrfach verweise»*. Je Wert eine Zeile mit dem Auswahlfeld, dahinter «+» (eine leere Zeile darunter) und
        // der Mülleimer; ohne Wert eine leere Zeile. Gespeichert wird in der Reihenfolge der Zeilen.
        if ($verweise) {
            return $this->referenceRows($relation, $context, $fieldPrefix, $formId, $recordId, $werte, $woerter);
        }

        $name    = $fieldPrefix . '[' . $relation->id . '][values][]';
        $form    = $formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"';
        $angebot = $context->surroundings->options;
        $medium  = $this->typeAt($relation) === SimpleType::Media;
        $bibliothek = $this->media;
        $wortVon    = static function (TypedValue $wert) use ($angebot, $medium, $bibliothek): string {
            if ($wert->reference !== null) {
                return (string) ($angebot[$wert->reference] ?? '#' . $wert->reference);
            }

            // *Ein Medium heisst wie seine Datei (D-846), nicht wie seine Adresse.*
            return $medium ? \Taxmod\Core\Renderer\MediaRenderer::describe((string) $wert->rawValue(), $bibliothek) : (string) $wert->rawValue();
        };

        $html = '<span class="taxmod-switch-picker taxmod-value-picker"><ol class="taxmod-switch-cascade">';

        foreach ($werte as $platz => $wert) {
            $roh   = (string) ($wert->reference ?? $wert->rawValue());
            $wort  = $wortVon($wert);
            $html .= '<li class="taxmod-switch-chosen" data-taxmod-id="' . RenderResult::escape($roh) . '">'
                . '<input type="hidden" class="taxmod-switch-member" name="' . RenderResult::escape($name) . '" value="' . RenderResult::escape($roh) . '"' . $form . '>'
                . '<span class="taxmod-switch-name">' . RenderResult::escape($wort) . '</span>'
                . ' <button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-list-move" data-taxmod-move="up"'
                . ($platz === 0 ? ' disabled style="color:#1d2327;opacity:.35"' : ' style="color:#1d2327"') . '>'
                . IconMarkup::dashicon('arrow-up-alt2', $wort) . '</button>'
                . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-list-move" data-taxmod-move="down"'
                . ($platz === count($werte) - 1 ? ' disabled style="color:#1d2327;opacity:.35"' : ' style="color:#1d2327"') . '>'
                . IconMarkup::dashicon('arrow-down-alt2', $wort) . '</button>'
                . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-list-remove" style="color:#b32d2e">'
                . IconMarkup::dashicon('trash', $wort) . '</button>'
                . '</li>';
        }

        $html .= '</ol><span class="taxmod-switch-add">';
        $genommen = array_map(static fn (TypedValue $wert): string => (string) ($wert->reference ?? $wert->rawValue()), $werte);

        // ⚠️ *Eine eindeutige Kante bietet nicht an, was schon ein anderer Satz hält (D-838) — sein Befund: «zusätzliche pcb [speichert] nicht».
        // Die Platine gehört einem Projekt; angeboten und dann beim Speichern abgewiesen war sie eine Falle.*
        $waehlt = $angebot !== [];

        if ($relation->unique && $angebot !== [] && $this->records !== null) {
            foreach ($this->records->recordRefsHeldAt($relation->id) as $satz => $halter) {
                if ($halter !== $recordId) {
                    unset($angebot[$satz]);
                }
            }
        }

        // *Ein Verweis wählt aus dem Angebot; gewählte Sätze stehen nicht noch einmal darin.*
        $frei = array_filter($angebot, static fn ($wort, $id): bool => ! in_array((string) $id, $genommen, true), ARRAY_FILTER_USE_BOTH);

        // ⚠️ *Bleibt nichts zu wählen, sind Auswahl und «+» ausgegraut — sein Wort: «eine leere auswahl müssen wir denke ich nicht anzeigen
        // bzw ausgrauen» — und das ist die bestehende Regel: D-380 «select fields always greyed out when there is no entry», D-370 eine unmögliche
        // Handlung ist ausgegraut, nicht weg. Hier von Hand gebaut, weil die Liste nicht durch den ChoiceRenderer geht; ein gesperrtes Feld wird nicht geschickt.*
        if ($waehlt) {
            // *Das eine Auswahlfeld (SelectMarkup) — ohne freie Wahl gesperrt und ausgegraut, der «+» mit.*
            $html .= SelectMarkup::of($name, array_map('strval', $frei), null, true, $formId, ['class' => 'taxmod-switch-candidates taxmod-value-candidates', 'data-taxmod-name' => $name]);
        } else {
            // *Alles andere wird geschrieben: ein Feld für den nächsten Wert, in der Gestalt seines Typs.*
            $html .= '<input type="text" class="taxmod-value-new" name="' . RenderResult::escape($name) . '" data-taxmod-name="' . RenderResult::escape($name) . '"' . $form . ' size="30">';

            // ⚠️ *Ein mehrfaches Medienfeld bekommt dieselben Knöpfe wie ein einfaches (D-858): Mediathek und Linkdialog füllen das Feld
            // für den nächsten Wert.*
            if ($medium) {
                $html .= \Taxmod\Core\Renderer\MediaRenderer::buttons('', '', $this->dialogWords, true);
            }
        }

        $html .= ($waehlt ? SelectMarkup::addButton($frei, $relation->name) : SelectMarkup::addButton(['x' => ''], $relation->name)) . '</span></span>';

        return [
            'renderer' => $context->surroundings->options !== [] ? SummaryRenderer::NAME : 'field',
            // *Keine Zeilen für den Behälter: die Liste ist ein Bedienelement wie jedes andere und steht bei den einfachen Feldern.*
            'rows'     => [],
            'rowActs'  => [],
            'after'    => '',
            'result'   => RenderResult::of($html),
        ];
    }

    /**
     * Die Zeilen eines mehrfachen Verweises beim Bearbeiten ([D-859](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @param list<TypedValue>   $werte
     * @param array<int, string> $woerter
     * @return array{renderer: string, rows: list<mixed>, rowActs: list<string>, after: string, result: RenderResult}
     */
    private function referenceRows(Relation $relation, RenderContext $context, string $fieldPrefix, string $formId, int $recordId, array $werte, array $woerter): array
    {
        $name    = $fieldPrefix . '[' . $relation->id . '][values][]';
        $angebot = array_map('strval', $context->surroundings->options);

        // *Eine eindeutige Kante bietet nicht an, was ein anderer Satz hält (D-838).*
        if ($relation->unique && $angebot !== [] && $this->records !== null) {
            foreach ($this->records->recordRefsHeldAt($relation->id) as $satz => $halter) {
                if ($halter !== $recordId) {
                    unset($angebot[$satz]);
                }
            }
        }

        $einfuegen = (string) ($this->partActs['insert'] ?? '');
        $entfernen = (string) ($this->partActs['remove'] ?? '');
        $html      = '<table class="taxmod-ref-rows"><tbody>'
            // *Damit eine ganz geleerte Liste ankommt und gelöscht wird.*
            . '<input type="hidden" name="' . RenderResult::escape($name) . '" value=""' . ($formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"') . '>';

        foreach ($werte === [] ? [null] : $werte as $wert) {
            $jetzt    = $wert?->reference;
            $optionen = $angebot;

            if ($jetzt !== null && ! isset($optionen[$jetzt])) {
                $optionen = [$jetzt => (string) ($woerter[$jetzt] ?? '#' . $jetzt)] + $optionen;
            }

            $html .= '<tr class="taxmod-ref-row"><td>'
                . SelectMarkup::of($name, $optionen, $jetzt === null ? null : (string) $jetzt, true, $formId, ['class' => 'taxmod-ref-select'])
                . '</td><td class="taxmod-table-acts">'
                . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-ref-add" style="color:#1d2327"' . ($einfuegen === '' ? '' : ' title="' . RenderResult::escape($einfuegen) . '"') . '>'
                . IconMarkup::dashicon('plus-alt2', $einfuegen) . '</button>'
                . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-ref-remove" style="color:#b32d2e"' . ($entfernen === '' ? '' : ' title="' . RenderResult::escape($entfernen) . '"') . '>'
                . IconMarkup::dashicon('trash', $entfernen) . '</button>'
                . '</td></tr>';
        }

        return [
            'renderer' => SummaryRenderer::NAME,
            'rows'     => [],
            'rowActs'  => [],
            'after'    => '',
            'result'   => RenderResult::of($html . '</tbody></table>'),
        ];
    }

    /** @var array<int, list<\Taxmod\Core\Model\RelationRecord>> Die Werte eines Satzes, einmal je Zeichenlauf gelesen (`CD-7`). */
    private array $werteJeSatzGelesen = [];

    /** Ob gerade die Zeilen einer Satztabelle gezeichnet werden (D-878). */
    private bool $inSatztabelle = false;

    /** @return list<\Taxmod\Core\Model\RelationRecord> */
    private function werteJeSatz(int $recordId): array
    {
        return $this->werteJeSatzGelesen[$recordId] ??= ($this->records?->valuesOfMany([$recordId])[$recordId] ?? []);
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
     * @return array{renderer: string, rows: list<list<RenderedField>>, result: RenderResult}|null `null`, wenn hier kein Teil liegt.
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
        /** @var array<string, \Taxmod\Core\Model\ResolvedSetting> Was an der Verwendungsstelle gilt — der dort gewählte Behälter zählt (D-749). */
        array $useSiteSettings = [],
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

        $ziel = $this->gemerkterKnoten($relation->toNodeId);

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
        $zeilen  = [];
        // *Je Zeile ihre Felder und Werte — für den Link hinter den Knöpfen (D-856).*
        $zeilenFelder = [];
        $zeilenWerte  = [];
        $teilIds = [];

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
            $wort      = (string) ($teil['geerbt'] ?? '');
            $teilIds[] = (int) ($teil['id'] ?? 0);

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
            // ⚠️ **Ohne eigenen Teil tragen die inneren Felder die Werte des Satzes** — *adressiert über die letzte Kante (D-667),
            // also stehen sie im selben Vorrat wie die äusseren; hier stand `[]`, und die Adresse blieb leer (D-741).*
            $erlaubteWahl = $purpose === Purpose::Edit ? $this->allowedChoicesIn($ziel, $relation, $dieseFelder, $teil === null ? $values : $teil['werte']) : [];

            $zeilenWerte[]  = $teil === null ? $values : $teil['werte'];
            $zeilenFelder[] = $dieseFelder;
            $zeilen[] = $this->fieldsFor(
                $dieseFelder,
                $teil === null
                    ? $values
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
                // ⚠️ **Ein Feld ohne Teil trägt trotzdem seinen Namen** — sein Befund am 2026-09-12, nach D-741:
                // «Adresse bei entry immer noch leer … eingegeben, gespeichert, Inhalt der Felder leer». *Gemessen:
                // die Adressfelder standen im Satz, aber **ohne `name`** — diese Bedingung nahm ihn jeder Kante ohne
                // Teil, und ein Feld auf einen zusammengesetzten Typ hat nie einen. Die Maske schickte nichts, und
                // D-741s Leser bekam nichts zu lesen. Namenlos bleibt nur die **Einstellung** ohne geltenden Knoten.*
                $fieldPrefix === '' || ($teil === null && $aufgeloest === 0 && $relation->isSetting())
                    ? ''
                    : ($relation->isSetting()
                        ? (string) preg_replace('/^([A-Za-z_]+)/', '$1_inner', $fieldPrefix) . '[' . $relation->id . ']'
                        // ⚠️ **Ein Teil ist ein eigener Satz und wird über seine Id angesprochen** ([D-577](../../../docs/NewConcept/90-decision-log.md),
                        // sein Wort 2026-09-13: «ein komplexer Typ wird gruppiert gespeichert»): `[<Kante>][<Teil>]`, `0` für einen, der
                        // beim ersten Speichern entsteht. *Hier stand `[<Kante>]` allein, und D-741 schrieb die inneren Werte flach in den Besitzer.*
                        : $fieldPrefix . '[' . $relation->id . '][' . (int) ($teil['id'] ?? 0) . ']'),
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
                $forNode,
                allowedChoices: $erlaubteWahl,
                // ⚠️ *Der haltende Satz: eine Position liest die Vorbelegung ihres Filters aus der Stückliste (D-791 Schritt 3).*
                ownerValues: $values
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
            : ($relation->multiplicity->allowsMany()
                // ⚠️ *Mehrere Teile sind Zeilen: ohne Wahl **an der Stelle** zeichnet die Tabelle — ein Formular zeigte nur den ersten (D-758, D-759).
                // Die Wahl am Zielknoten zählt hier nicht: `form` an `Part List Item` liess an einer neuen Liste nur die erste Position stehen (D-914).*
                ? ($this->containerChosenAt($this->chosenHereOnly($useSiteSettings), $ziel, $purpose) ?? $this->renderers->byName(TableRenderer::NAME))
                : ($this->containerChosenAt($useSiteSettings, $ziel, $purpose) ?? $this->containerFor($ziel, $purpose)));

        // ⚠️ **Zeilen hinzufügen und entfernen** (D-758, sein Wort: *«bei höherer Multiplizität Datensätze hinzufügen und entfernen»*).
        // *Nur beim Bearbeiten, nur an einer Komposition mit mehreren, nur mit Worten vom Rand (`AR-2`). Der Halter ist der Satz, dessen
        // Name zuletzt im Präfix steht — ein Teil, den es noch nicht gibt (`0`), bekommt keine eigenen Kinder.*
        $akte   = [];
        $danach = '';

        if ($purpose === Purpose::Edit && $editable && $this->partActs !== [] && $fieldPrefix !== ''
            && ! $relation->isSetting() && $relation->kind === RelationKind::Composition && $relation->multiplicity->allowsMany()
        ) {
            foreach ($teilIds as $teilId) {
                // ⚠️ *Erst das «+», das direkt unter dieser Zeile eine gleicher Art einfügt (D-830), dann der Mülleimer — sein Wort: «bitte + und
                // müll tauschen erst + dann müll» (D-831).*
                $akte[] = $teilId === 0 ? '' : (($this->partActs['insert'] ?? '') === '' ? '' : ControlMarkup::button(
                        new \Taxmod\Core\Renderer\Control('do[' . $teilId . ']', 'insert_part', $this->partActs['insert'], '', true, false, 'plus-alt2', $formId)
                    ))
                    . ControlMarkup::button(
                        new \Taxmod\Core\Renderer\Control('do[' . $teilId . ']', 'remove_part', $this->partActs['remove'], '', true, true, 'trash', $formId)
                    );
            }

            // ⚠️ **Hinter «+» und Mülleimer der gezeichnete Link** ([D-856](../../../docs/NewConcept/90-decision-log.md)) — sein Wort:
            // *«reihenfolge address feld, beschriftungs feld +, müll link gerendert»*. Nur wo ein Medienfeld der Zeile ein Beschriftungsfeld hat.
            foreach ($akte as $i => $akt) {
                $felder = $zeilenFelder[$i] ?? [];
                $werte  = $zeilenWerte[$i] ?? [];

                foreach ($this->captionFieldsOf($felder, $this->typesOf($felder)) as $medium => $beschriftung) {
                    $adresse    = trim((string) (($werte[$medium] ?? null)?->text ?? ''));
                    $neuerTab   = $this->mediaOpensNewTab($felder, $medium);
                    $teilPrefix = $fieldPrefix . '[' . $relation->id . '][' . ($teilIds[$i] ?? 0) . ']';
                    // ⚠️ *Sein Wort: «alle buttons bitte nach rechts» (D-858): Mediathek und Linkdialog vor «+» und Mülleimer, der Link dahinter.*
                    $akte[$i]   = \Taxmod\Core\Renderer\MediaRenderer::buttons($teilPrefix . '[' . $medium . ']', $teilPrefix . '[' . $beschriftung . ']', $this->dialogWords, $neuerTab)
                        . $akt
                        . ($adresse === '' ? '' : ' ' . \Taxmod\Core\Renderer\MediaRenderer::link($adresse, (string) (($werte[$beschriftung] ?? null)?->text ?? ''), $neuerTab, $this->media));
                }
            }

            $halter = preg_match('/\[(\d+)\]$/', $fieldPrefix, $treffer) === 1 ? (int) $treffer[1] : 0;

            if ($halter !== 0) {
                // ⚠️ **Mehrere hinzufügen** ([D-806](../../../docs/NewConcept/90-decision-log.md)) — *sein Wort: «i select multiple parts and for
                // each part a item is created … propose a button to add multiple». Nur, wo die Kante sagt, über welches Feld gewählt wird
                // (`pick_field`); der Dialog ist der Satzdialog mit Baum und Liste, je Satz ein Haken; «bestätigen» schickt `add_parts`.*
                $mehrere  = '';
                $wahlFeld = ($this->partActs['many'] ?? '') === '' ? null : $this->pickFieldOf($relation);

                if ($wahlFeld !== null) {
                    // ⚠️ **Die Vorbelegung gilt auch hier** (D-826) — *sein Wort: «2. ja». Eine neue Zeile hat noch keine Werte; der Weg der
                    // Vorbelegung fällt darum auf die Werte des haltenden Satzes zurück, wie bei der Einzelwahl einer leeren Zeile.*
                    $wahlBaum   = $this->summariesOf([$wahlFeld], [], $this->settingsForUseSites([$wahlFeld]), Purpose::Edit, $this->typesOf([$wahlFeld]), $values)['baum'][$wahlFeld->id] ?? [];
                    $schluessel = $halter . '-' . $relation->id;

                    if ($wahlBaum !== []) {
                        $mehrere = SummaryRenderer::multiDialog(
                            $wahlBaum,
                            'taxmod_multi[' . $schluessel . '][]',
                            $formId,
                            'taxmod-multi-dialog-' . preg_replace('/[^a-z0-9_-]/i', '', $formId . $schluessel),
                            IconMarkup::dashicon('plus-alt', $this->partActs['many']) . ' ' . RenderResult::escape($this->partActs['many']),
                            ControlMarkup::button(new \Taxmod\Core\Renderer\Control('do[' . $schluessel . ']', 'add_parts', $this->partActs['many'], '', true, false, '', $formId, true)),
                            $this->dialogWords
                        );
                    }
                }

                // ⚠️ **«Add row» nur, solange es keine Zeile gibt** ([D-832](../../../docs/NewConcept/90-decision-log.md)) — *sein Bild mit zwei «+»:
                // «zweimal plus». Jede Zeile trägt ihr eigenes «+» (D-830); unter der letzten eingefügt ist dasselbe wie hinten angehängt.
                // Ohne Zeile gäbe es sonst keinen Weg zur ersten.*
                $zeilenDa = array_filter($teilIds, static fn (int $id): bool => $id !== 0) !== [];
                $danach   = '<div class="taxmod-part-add">' . ($zeilenDa && ($this->partActs['insert'] ?? '') !== '' ? '' : ControlMarkup::button(
                    new \Taxmod\Core\Renderer\Control('do[' . $halter . '-' . $relation->id . ']', 'add_part', $this->partActs['add'], '', true, false, 'plus-alt2', $formId)
                )) . $mehrere . '</div>';
            }
        }

        $gezeichnet = $behaelter->render(
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
                    surroundings: new Surroundings(parts: $teile, records: $zeilen, formId: $formId, rowLead: $vorspalten, rowActs: implode('', $akte) === '' ? [] : $akte),
                )
            );

        return [
            'renderer' => $behaelter->name(),
            // ⚠️ *Die Zeilen einzeln, für den Komplex-Renderer, der sie selbst auslegt (D-758) — mit ihren Knöpfen.*
            'rows'     => $zeilen,
            'rowActs'  => $akte,
            'after'    => $danach,
            'result'   => new RenderResult($gezeichnet->markup . $danach, $gezeichnet->usedRelations, $gezeichnet->condition),
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

        $knoten = $this->gemerkterKnoten($gewaehlt);

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
        // ⚠️ *Ein mehrfaches Feld wählt in seinen Zeilen (D-859) — ein Kasten davor war ein zweites, leeres Auswahlfeld ohne «+» (D-881, sein
        // Befund: «bei voltage blaster herkunft sieht komisch aus gleich zwei leere felder übereinander»).*
        if ($type !== SimpleType::NodeRef || $relation->multiplicity->allowsMany()) {
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
        string $chooser = ChooserRenderer::NAME,
        string $locale = '',
        Level $level = Level::Admin,
        string $trigger = '',
        string $confirm = '',
        string $formId = '',
        /** @var array<string, ResolvedSetting> Die Angaben des Wählers — `dialog`, `display_size`; die Knotenseite gibt {@see ChooserRenderer::asDialog()}. */
        array $settings = [],
    ): RenderResult {
        $laeufer = $this->walker();

        if ($laeufer === null) {
            return RenderResult::of('');
        }

        // ⚠️ *Teilt die Seite die Körper (D-866), klappt der Baum eines Satzformulars nicht auf die Wahl hin auf — sonst wäre jeder Baum ein
        // anderer und keiner teilbar (gemessen: 25 Einheitenbäume, 25 Vorlagen). Das Skript öffnet den Ast der Wahl nach dem Einsetzen.*
        $geteilt = $this->geteilteKoerper !== null && $formId !== '';
        $walked  = $this->closedApartFrom($this->gemerkteZeilenUnter($laeufer, $root, $skip), $expanded, $geteilt ? null : $preselected);

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
            $confirm,
            $formId,
            $settings
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

            $kinder = $this->gemerkteSichtbareKinder(array_keys($alle));
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
