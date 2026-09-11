<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\TypeNodes;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Admin\SettingsScreen;

/**
 * Prefixes and base units, under `Constants`.
 *
 * ⚠️ **Constants, and the branch decides why:** a unit is *a fixed value a person may extend*, and
 * an attribute pointing at one stores **a reference to a node**
 * ([D-232](../../../docs/NewConcept/90-decision-log.md)) — which is exactly what a unit is on a
 * value. Nothing here is a data type.
 *
 * ⚠️ **The shape is decided; the list came from the owner.** [D-274](../../../docs/NewConcept/90-decision-log.md)
 * settles that *each unit carries its factor to the parent's reference unit*, and where a factor is
 * not enough, an **offset** — °C → °F is `×1.8 + 32`. The **members** of the list are not in
 * `NewConcept/`; they were read out of the legacy tree and confirmed one by one, because legacy is a
 * quarry and never a source (`PR-1`).
 *
 * ⚠️ **`Gramm`, not `Kilogramm`, and the owner said so for the right reason.** The prefix axis needs
 * an **unprefixed** base: `Kilogramm` already contains one, so prefixing it would produce
 * *kilo-kilogramm*. *The SI base unit is the kilogram and the modelling base cannot be — a case
 * where the physics and the model disagree, and the model has to win.*
 *
 * ```mermaid
 * flowchart TD
 *   C["Constants"] --> P["Prefixes · a power of ten each"]
 *   C --> B["Base units"]
 *   B --> W["With prefix · Gramm · Meter …"]
 *   B --> N["Without prefix · Kelvin · Celsius · Stück"]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class UnitScaffold
{
    public const OPTION = 'taxmod_unit_scaffold';

    /**
     * Wo der Einheitenwert steht — **als Id, nicht als Name**.
     *
     * ⚠️ **Sein Wort am 2026-09-07:** *«checks sind ok gut das due prüfst ob das noch geht, köntest
     * aber über id gehen 😉»* — *und das ist [D-510](../../../docs/NewConcept/90-decision-log.md),
     * derselbe Satz noch einmal: «Der Code findet seine gesäten Knoten über die **Id**, nicht über
     * den Namen. Ein Name ist eine Beschriftung und darf sich ändern.»*
     *
     * ⚠️ **Was es gekostet hat, es nicht zu tun:** *er hängte `Einheitenwert` nach `Combined` —
     * richtig nach [D-677](../../../docs/NewConcept/90-decision-log.md) —, und zwei Wächter fielen
     * um, obwohl am Kode nichts falsch war. **Der Name war nie das Problem; der Ort war es**, und
     * eine Id kennt beides nicht.*
     */
    public const UNIT_VALUE_OPTION = 'taxmod_unit_value_id';

    /**
     * Der gesäte Einheitenwert, oder `null` — für {@see CompositionScaffold} und die Wächter.
     *
     * ⚠️ *`null` heisst «noch nicht gesät» und ist eine Antwort, keine Störung. Wer ihn braucht,
     * sagt selbst, was dann gilt.*
     */
    public static function unitValueId(): ?int
    {
        $id = (int) get_option(self::UNIT_VALUE_OPTION, 0);

        return $id === 0 ? null : $id;
    }

    /** Raise it only to deliver something genuinely new; every raise re-enters every install. */
    public const VERSION = 5;

    /**
     * Jeder Knoten des Gerüsts notiert seine Id in einer Option — wie die Behälter der Renderer.
     *
     * ⚠️ **[D-709](../../../docs/NewConcept/90-decision-log.md), sein Wort: «ja notieren».** *Bis dahin
     * fand das Gerüst seine Knoten über den Namen, und drei Wächter taten es ihm nach — ein Name ist
     * absichtlich nicht eindeutig ([D-022](../../../docs/NewConcept/90-decision-log.md)). **Fassung 4
     * trägt die Notiz nach**: beim nächsten Lauf findet `ensure()` jeden Knoten noch einmal über den
     * Namen und merkt sich die Id; von da an gilt die Id, und der Name darf wechseln.*
     */
    public const NODE_OPTION_PREFIX = 'taxmod_unit_node_';

    public static function optionFor(string $name): string
    {
        return self::NODE_OPTION_PREFIX . strtolower(str_replace(' ', '_', $name));
    }

    /** Die notierte Id eines Gerüstknotens — `null`, solange Fassung 4 nicht gelaufen ist. */
    public static function nodeId(string $name): ?int
    {
        $id = (int) get_option(self::optionFor($name), 0);

        return $id === 0 ? null : $id;
    }

    /**
     * The SI prefixes, as **powers of ten**.
     *
     * ⚠️ **An exponent and not a factor**, because `decimal(30,10)` cannot hold 10⁻²⁴ or 10²⁴ — ten
     * decimal places and twenty integer ones. A prefix **is** a power of ten by definition, so the
     * exponent loses nothing (D-372).
     *
     * ⚠️ **Each value lands as the `prefix_exponent` **setting** on the prefix node** (D-377) —
     * the same shape as `factor` and `offset` on a unit (D-274), because a prefix is a constant and
     * a constant holds no records.
     *
     * @var array<string, int>
     */
    private const PREFIXES = [
        'yotta' => 24, 'zetta' => 21, 'exa' => 18, 'peta' => 15, 'tera' => 12,
        'giga'  => 9,  'mega'  => 6,  'kilo' => 3,  'hecto' => 2, 'deca' => 1,
        'deci'  => -1, 'centi' => -2, 'milli' => -3, 'micro' => -6, 'nano' => -9,
        'pico'  => -12, 'femto' => -15, 'atto' => -18, 'zepto' => -21, 'yocto' => -24,
    ];

    /**
     * Units a prefix may be put in front of.
     *
     * ⚠️ *No factors here: each **is** its parent's reference unit, so its factor is one and a
     * stored one would be a fact nobody needs (settings are sparse, [D-015](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * @var list<string>
     */
    private const WITH_PREFIX = [
        'Gramm', 'Meter', 'Liter', 'Sekunde', 'Ampere', 'Ohm',
        'Farad', 'Watt', 'Volt', 'Henry', 'Hertz',
    ];

    /**
     * Units that take no prefix.
     *
     * ⚠️ **`Celsius` is the one that needs D-274's second half.** It is `Kelvin` shifted, not
     * scaled: `°C = K − 273.15`. *Without an offset the commonest conversion of all would fall
     * outside the rule, which is why D-274 named both.*
     *
     * @var array<string, array{factor?: string, offset?: string}>
     */
    private const WITHOUT_PREFIX = [
        'Kelvin'  => [],
        'Celsius' => ['factor' => '1', 'offset' => '-273.15'],
        'Stück'   => [],
    ];

    /**
     * The short form of each prefix and unit — **labels, in the `symbol` role**.
     *
     * ⚠️ **A label and never an attribute, and [D-260](../../../docs/NewConcept/90-decision-log.md)
     * is where that was settled** — the modelled `symbol` attribute went, because a symbol in the
     * data *and* in the labels is one fact in two homes. **What a record holds is the reference to
     * `kilo`**; `k` is this row, resolved when something draws it.
     *
     * ⚠️ *The owner reached that conclusion himself while we argued about it: **we store the id of
     * the constant and therefore do not need the trick.** Before that we had a data type, a
     * read-only default and a `path` column on the table between us — three constructions for
     * something the concept had already solved.*
     *
     * ⚠️ **One locale row each, and that is D-260's own point:** `Ω` and `k` are fixed by a
     * standard, so they are identical in every language and need exactly one row. `Stück` is the
     * counter-example — `St` in German, `pc` in English — and it is why the mechanism is labels
     * rather than a language-neutral setting.
     *
     * ⚠️ *`deca` is `da` — **two** characters. Which is, incidentally, the proof that a symbol is
     * not a `char`: nineteen of the twenty fit in one and the twentieth does not.*
     *
     * @var array<string, string>
     */
    private const SYMBOLS = [
        'yotta' => 'Y', 'zetta' => 'Z', 'exa' => 'E', 'peta' => 'P', 'tera' => 'T',
        'giga'  => 'G', 'mega'  => 'M', 'kilo' => 'k', 'hecto' => 'h', 'deca' => 'da',
        'deci'  => 'd', 'centi' => 'c', 'milli' => 'm', 'micro' => 'µ', 'nano' => 'n',
        'pico'  => 'p', 'femto' => 'f', 'atto' => 'a', 'zepto' => 'z', 'yocto' => 'y',
        'Gramm' => 'g', 'Meter' => 'm', 'Liter' => 'l', 'Sekunde' => 's', 'Ampere' => 'A',
        'Ohm'   => 'Ω', 'Farad' => 'F', 'Watt' => 'W', 'Volt' => 'V', 'Henry' => 'H',
        'Hertz' => 'Hz', 'Kelvin' => 'K', 'Celsius' => '°C', 'Stück' => 'St',
    ];

    public function __construct(
        private readonly ModelEditor $editor,
        private readonly FrameworkNodes $framework,
        private readonly Labels $labels,
        /** ⚠️ *So a member's type is found by id and not by the node's name ([D-510](../../../docs/NewConcept/90-decision-log.md)).* */
        private readonly TypeNodes $typeNodes,
        // ⚠️ *Fassung 5 (Schritt 7 des Bauplans): das Gerüst schreibt Umrechnungssätze und «mit Präfix» in das
        // Einstellungsmodell ([D-712](../../../docs/NewConcept/90-decision-log.md)) — über denselben Schreiber wie die Maske.*
        private readonly ?\Taxmod\Core\Service\SettingsEditor $settings = null,
    ) {
    }

    /** @return list<string> The names actually created, so a caller can report what it did. */
    public function importOnce(): array
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return [];
        }

        $created = $this->import();

        update_option(self::OPTION, self::VERSION, true);

        return $created;
    }

    /**
     * Create what is not there, whatever the stored version says.
     *
     * ⚠️ **Once, and then hands off** ([D-119](../../../docs/NewConcept/90-decision-log.md)). After
     * the import these are ordinary authored content: a model with no use for `Henry` may throw it
     * away, and reactivating the plugin must not bring it back.
     *
     * @return list<string>
     */
    public function import(): array
    {
        $constants = $this->framework->rootOf(Branch::Constants);
        $created   = [];

        $prefixes = $this->ensure($constants, 'Prefixes', $created);

        // ⚠️ **An attribute that is declared **not persistent**** ([D-378](../../../docs/NewConcept/90-decision-log.md)).
        // The owner brought the distinction from object orientation and it is what finally justifies
        // an attribute here: *there are attributes that get persisted and ones that do not — a
        // multiplicator is not persistent, it counts only as an attribute.*
        //
        // ⚠️ **What this buys over a reserved setting key is the owner's own question answered:**
        // *how does the user know he needs the multiplier?* Because **`Prefixes` declares it** and
        // inheritance says who has one. A global key is offered on every text node in the system and
        // nothing says where it belongs.
        //
        // ⚠️ *And the value has a home without a trick:* [D-026](../../../docs/NewConcept/90-decision-log.md)
        // — *at model level there are no values, only defaults* — so each prefix's `default` **is**
        // its model-level value, which is what a default has always been.
        $exponent = $this->field($prefixes, 'exponent', 'int');

        // ⚠️ **Hier stand bis 2026-09-01 `persistent = false`, und es ist mit [D-538](../../../docs/NewConcept/90-decision-log.md)
        // ersatzlos gefallen.** *Der Eigentümer hat es selbst hergeleitet: «diese nicht persistenten
        // Datensätze sind eigentlich alles Settings … die Settings sind ja Eigenschaften des Modells.»
        // **Die Kante `exponent` trägt seit [D-526](../../../docs/NewConcept/90-decision-log.md) die
        // Relationsart `setting`, und die sagt dasselbe** — gemessen am 2026-09-01: Kante 4654,
        // `kind = setting`. Zwei Heimaten für eine Tatsache, und nur eine war je die Wahrheit.*

        foreach (self::PREFIXES as $name => $power) {
            $node = $this->ensure($prefixes, $name, $created);
            // ⚠️ *Sein Wort: «yotta … yocto — Konstante, je mit einem Umrechnungssatz (factor)» (K3, D-719).*
            $this->umrechnung($node, self::factorOf($power), '0');

            // ⚠️ *Hier stand der Exponent des Praefixes als `default` in der `settings`-Tabelle, an
            // der Adresse der Kante `exponent` ([D-413](../../../docs/NewConcept/90-decision-log.md)).
            // **Die Tabelle ist mit [D-579](../../../docs/NewConcept/90-decision-log.md) gestrichen**,
            // und gemessen am 2026-09-04 trug sie **keine einzige `default`-Zeile** mehr: der Wert
            // steht seit [D-529](../../../docs/NewConcept/90-decision-log.md) im Datensatz, und
            // {@see \Taxmod\Core\Service\Rendering::nonPersistentValue()} liest ihn dort.*
        }

        $base = $this->ensure($constants, 'Base units', $created);

        $withPrefix = $this->ensure($base, 'With prefix', $created);

        foreach (self::WITH_PREFIX as $name) {
            // ⚠️ *«mit/ohne präfix ist eine eigenschaft je blatt» (K2) — hier als Attribut des Einheitswerts.*
            $this->stelle($this->ensure($withPrefix, $name, $created), 'mit_praefix', '1');
        }

        $withoutPrefix = $this->ensure($base, 'Without prefix', $created);

        foreach (self::WITHOUT_PREFIX as $name => $conversion) {
            // ⚠️ *`factor` und `offset` standen hier als Zeilen der `settings`-Tabelle. Sie ist mit
            // [D-579](../../../docs/NewConcept/90-decision-log.md) gestrichen; gemessen trug sie
            // zuletzt weder das eine noch das andere — [D-529](../../../docs/NewConcept/90-decision-log.md)
            // hat beide als **Felder am Knoten** fortgeschrieben.*
            $einheit = $this->ensure($withoutPrefix, $name, $created);

            if ($conversion !== []) {
                $this->umrechnung($einheit, $conversion['factor'], $conversion['offset']);
            }
        }

        $this->unitValue($prefixes, $base, $created);

        return $created;
    }

    /**
     * The attribute of this name on this node, made if it is not there.
     *
     * ⚠️ **The kind is never chosen** ([D-161](../../../docs/NewConcept/90-decision-log.md)): the
     * target sits in `Data Types`, so the relation is a composition and nobody said so.
     */
    private function field(Node $owner, string $name, string $typeName): Relation
    {
        foreach ($this->editor->fieldsOf($owner->id) as $relation) {
            if ($relation->name === $name && $relation->fromNodeId === $owner->id) {
                return $relation;
            }
        }

        $type = null;

        // ⚠️ **The literal above is read as a type, the node is then found by its id**
        // ([D-510](../../../docs/NewConcept/90-decision-log.md)). *`$typeName` is a string in this
        // file's own source and may be read as one; the **node's** name is a beschriftung and may
        // not. Before this, a renamed `Decimal` would have made the scaffold refuse to run.*
        $wanted = SimpleType::fromNodeName($typeName);
        $id     = $wanted === null ? null : $this->typeNodes->nodeId($wanted);

        foreach ($this->editor->childrenOf($this->framework->rootOf(Branch::DataTypes)->id) as $child) {
            if ($id !== null && $child->id === $id) {
                $type = $child;
            }
        }

        if ($type === null) {
            // ⚠️ The base scaffold delivers the simple types; if it has not run there is nothing to
            // point at, and inventing a type here would be a second place that creates them.
            throw new \RuntimeException("The data type «{$typeName}» is not there yet.");
        }

        return $this->editor->addField($owner->id, $type->id, $name);
    }

    /**
     * An attribute pointing at a node given directly, rather than at a data type found by name.
     *
     * ⚠️ **Separate from {@see field()} because the two resolve differently, not because the
     * kind differs.** A data type is looked up by name under one branch root; a constant is a node
     * somebody already holds. *The kind itself is never passed either way — it is read off the
     * branch the target sits in ([D-161](../../../docs/NewConcept/90-decision-log.md)).*
     */
    private function fieldTo(Node $owner, string $name, Node $target): Relation
    {
        foreach ($this->editor->fieldsOf($owner->id) as $relation) {
            if ($relation->name === $name && $relation->fromNodeId === $owner->id) {
                return $relation;
            }
        }

        return $this->editor->addField($owner->id, $target->id, $name);
    }

    /**
     * The composed type of [D-039](../../../docs/NewConcept/90-decision-log.md): **value + optional
     * prefix + unit**, as one notion.
     *
     * ⚠️ **One notion and therefore one type, which is [D-220](../../../docs/NewConcept/90-decision-log.md)'s
     * whole point.** The owner, describing the entry: *I type `2k7` and it lands in two different
     * fields, the `2.7` and the `k` — I think we need some kind of combinatorial renderer here.*
     * D-220's answer: **those are not two fields**, they are members of one value that is incomplete
     * without them, so a composed type is the unit of rendering and no new kind of renderer is
     * needed. This is that type, existing at last.
     *
     * ```mermaid
     * flowchart LR
     *   U["Einheitenwert"] --> W["wert · decimal"]
     *   U --> P["prefix · 0..1 · → Prefixes"]
     *   U --> E["einheit · 1 · → Base units"]
     * ```
     *
     * ⚠️ **The prefix is `0..1` because D-039 says *optional*** — `12 Stück` has no prefix and
     * `2.7 kΩ` has one. *Mandatory would have forced a prefix onto every count in the system, and
     * `1` as a prefix node is not the same thing as no prefix at all.*
     *
     * ⚠️ **Both references ask for the `symbol` role**, which is the setting D-049 promised and
     * nothing had ever set. Without it the descent draws the `form` label and `2.7 kΩ` reads
     * *2.7 kilo Ohm*.
     *
     * ⚠️ **What this does *not* yet do is hold a value**, and the reason is narrower than I first
     * wrote. `DataEntry::put()` refuses a target whose branch stores `OwnRecords`, and `Compositions`
     * does — **correctly**: [D-232](../../../docs/NewConcept/90-decision-log.md) says *the branch
     * decides where a value is stored, not the multiplicity*, and it **supersedes**
     * [D-133](../../../docs/NewConcept/90-decision-log.md), which I had cited against the code.
     * *So a composed value gets **its own record**, owned by the holder and dying with it — and that
     * is what is not built. The type can be modelled and drawn; storing one is the next step.*
     *
     * @param list<string> $created
     */
    private function unitValue(Node $prefixes, Node $baseUnits, array &$created): void
    {
        // ⚠️ **Unter `Combined` und nicht mehr unter `Compositions`**
        // ([D-677](../../../docs/NewConcept/90-decision-log.md)). *Ein Einheitenwert ist aus Feldern
        // gebaut und hält **keine Benutzerdaten** — «nur example oder default wie bei typ». Genau
        // der Ast, den D-677 dafür angelegt hat.*
        //
        // ⚠️ **Und er hat es selbst schon so gehängt:** *gemessen am 2026-09-07 stehen
        // `Einheitenwert`, `Dimension`, `Street / H#` und `Zip/City` unter `Combined` (3984). Das
        // Gerüst zog nach, nicht umgekehrt.*
        $unitValue = $this->ensureAnywhere(
            $this->framework->rootOf(Branch::Combined),
            [$this->framework->rootOf(Branch::Compositions)],
            'Einheitenwert',
            $created
        );

        // ⚠️ **Ab hier über die Id** ({@see self::UNIT_VALUE_OPTION}, [D-510](../../../docs/NewConcept/90-decision-log.md)).
        // *Der Name wird genau einmal gebraucht — um ihn zu finden, wenn die Option noch leer ist.
        // Danach darf er heissen, wie er will, und stehen, wo er will.*
        update_option(self::UNIT_VALUE_OPTION, $unitValue->id, true);

        $this->field($unitValue, 'wert', 'decimal');

        $prefix = $this->fieldTo($unitValue, 'prefix', $prefixes);
        $unit   = $this->fieldTo($unitValue, 'einheit', $baseUnits);

        // ⚠️ *Die Multiplizität liegt an der Kante ([D-528](../../../docs/NewConcept/90-decision-log.md)).
        // Der Präfix ist optional, weil «10 Ohm» keinen hat.*
        $this->editor->setMultiplicity($prefix->fromNodeId, $prefix->id, Multiplicity::ZeroToOne);

        // ⚠️ **Hier stand `label_role = symbol` fuer `prefix` und `einheit`, und das sind genau die
        // drei Zeilen, die [D-579](../../../docs/NewConcept/90-decision-log.md) aufgibt.** *Der
        // Eigentümer hat zwischen Umzug und Neueingabe gewaehlt — «B» — und die sichtbare Folge
        // vorher benannt: bis `label_role` seinen neuen Ort hat (`OQ-134`), zeigt `Einheitenwert`
        // «Kiloohm» statt «kΩ». **Kein Fehler, umkehrbar, und bewusst in Kauf genommen.***
    }

    /**
     * The child of this name, made if it is not there.
     *
     * ⚠️ **Found by name among the children, never by a remembered id.** An id is meaningless
     * (sentence 2 of the core on one page) and storing one per seeded node would be a second place
     * where the tree lives.
     *
     * ⚠️ **Diese Begründung ist am 2026-08-29 widerlegt worden, an einer Stelle — und sie steht hier
     * nur noch als Beleg.** *[D-510](docs/NewConcept/90-decision-log.md) und
     * [D-512](docs/NewConcept/90-decision-log.md): der Code findet gesäte Knoten über eine **Id in
     * einer Option**, nicht über den Namen. Der Satz «eine Id ist für sich bedeutungslos» stimmt für
     * eine **nackte** Id — nicht für eine, die unter einem sprechenden Optionsnamen liegt.*
     *
     * ⚠️ **Und der Bruch ist hier gemessen, nicht befürchtet:** *`boundTheNumbers()` in dieser
     * Klassenfamilie suchte einen Knoten namens `int` — er heisst seit
     * [D-428](docs/NewConcept/90-decision-log.md) `Integer`. **Eine frisch gesäte Installation hätte
     * ihre Zahlengrenzen nie bekommen.***
     *
     * ⚠️ *Diese Methode bindet weiter über den Namen: die Umstellung der ~80 Einheiten- und
     * Kompositionsknoten ist **nicht beauftragt** und steht auf der Arbeitsliste. **Bis dahin gilt
     * hier der Namensweg — aber nicht mehr als Regel.***
     *
     * @param list<string> $created
     */
    /**
     * Wie {@see self::ensure()}, aber es **findet** den Knoten auch dort, wo er früher lag.
     *
     * ⚠️ **Weil ein gesätes Ding umziehen darf** ([D-119](../../../docs/NewConcept/90-decision-log.md)):
     * *«after the import these are ordinary authored content».* **Ein Gerüst, das nur an einer Stelle
     * nachsieht, legt beim nächsten Lauf ein zweites daneben** — und das ist die Doppelung, die
     * `CompositionScaffold::existing()` mit einer Ausnahme verhindern wollte, allerdings erst
     * **nachdem** sie schon wehtat.
     *
     * ⚠️ *Gemessen am 2026-09-07: `Einheitenwert` war unter `Combined` gewandert, und
     * `composition-check` brach mit «is not there yet» ab, obwohl der Knoten dastand.*
     *
     * @param list<Node>   $auchHier Die alten Plätze, in der Reihenfolge, in der nachgesehen wird.
     * @param list<string> $created
     */
    private function ensureAnywhere(Node $parent, array $auchHier, string $name, array &$created): Node
    {
        // ⚠️ **Zuerst die Id, und dann ist alles andere Notnagel** ([D-510](../../../docs/NewConcept/90-decision-log.md)).
        // *Steht sie, ist die Frage «wo liegt er, wie heisst er» gar nicht erst zu stellen.*
        $bekannt = self::unitValueId();
        $knoten  = $bekannt === null ? null : $this->editor->find($bekannt);

        if ($knoten !== null) {
            $this->label($knoten);

            return $knoten;
        }

        foreach ([$parent, ...$auchHier] as $wo) {
            foreach ($this->editor->childrenOf($wo->id) as $child) {
                if ($child->name === $name) {
                    $this->label($child);

                    return $child;
                }
            }
        }

        return $this->ensure($parent, $name, $created);
    }

    private function ensure(Node $parent, string $name, array &$created): Node
    {
        // ⚠️ *Erst die Notiz (D-709): steht die Id, und der Knoten lebt ausserhalb des Mülls, ist er es —
        // egal, wie er heute heisst.*
        $bekannt = self::nodeId($name);
        $muell   = $this->framework->trash();
        $gemerkt = $bekannt === null ? null : $this->editor->find($bekannt);

        if ($gemerkt !== null && $gemerkt->id !== $muell->id && ! $gemerkt->isDescendantOf($muell)) {
            $this->label($gemerkt);

            return $gemerkt;
        }

        foreach ($this->editor->childrenOf($parent->id) as $child) {
            if ($child->name === $name) {
                update_option(self::optionFor($name), $child->id, true);
                $this->label($child);

                return $child;
            }
        }

        $made      = $this->editor->createNode($name, $parent->id);
        $created[] = $name;
        update_option(self::optionFor($name), $made->id, true);

        $this->label($made);

        return $made;
    }

    /**
     * Give the node its `symbol` label, where one is known.
     *
     * ⚠️ **Written on every pass, not only on creation.** The nodes existed before the symbols did,
     * so a create-only write would have left every install that already ran the scaffold without
     * them — and the scaffold's version guard ([D-119](../../../docs/NewConcept/90-decision-log.md))
     * deliberately stops it from re-entering. *Putting a label that is already there costs one write
     * and is the difference between this working and only working on a fresh database.*
     *
     * ⚠️ **Die Standardsprache, auf die jeder Rückfall läuft** ([D-387](../../../docs/NewConcept/90-decision-log.md),
     * [D-645](../../../docs/NewConcept/90-decision-log.md)). *Hier stand die **leere** Locale mit der
     * Begründung, `Ω` sei in jeder Sprache richtig. **Die sprachneutrale Zeile gibt es nicht mehr** —
     * an ihre Stelle tritt die erklärte Standardsprache, und weil jede andere Sprache auf sie
     * zurückfällt, steht `Ω` weiterhin genau einmal da. Wo ein Symbol wirklich abweicht — `St` gegen
     * `pc` —, kommt eine Zeile dieser Sprache daneben und gewinnt.*
     */
    /** Ein Umrechnungssatz am Knoten — sein Wort: «umrechnungsatz hört sich gut an» (D-712). */
    private function umrechnung(Node $node, string $factor, string $offset): void
    {
        if ($this->settings === null) {
            return;
        }

        $this->settings->put($node, 'umrechnung', 'conversion');
        $this->settings->put($node, 'factor', $factor);
        $this->settings->put($node, 'offset', $offset);
    }

    private function stelle(Node $node, string $attribut, string $wert): void
    {
        $this->settings?->put($node, $attribut, $wert);
    }

    /** Zehn hoch n als Dezimalzahl in Zeichen: `1000` für 3, `0.001` für -3. */
    public static function factorOf(int $power): string
    {
        return $power >= 0 ? '1' . str_repeat('0', $power) : '0.' . str_repeat('0', -$power - 1) . '1';
    }

    private function label(Node $node): void
    {
        $symbol = self::SYMBOLS[$node->name] ?? null;

        if ($symbol === null) {
            return;
        }

        $this->labels->put(new Label(
            $node->id,
            IdentitySpace::Node,
            SeededRole::Symbol,
            Label::BASE_NUMBER,
            SettingsScreen::neutralLocale(),
            $symbol
        ));
    }
}
