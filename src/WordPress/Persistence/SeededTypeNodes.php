<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\Type\SpecialisedTypes;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\TypeNodes;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\SystemClock;

/**
 * Die gesäten Datentyp-Knoten — gefunden über die **Klasse, die den Typ umsetzt**.
 *
 * ⚠️ **Bis zum 2026-09-05 hielten elf WordPress-Optionen diese Bindung** (`taxmod_type_int_id` und
 * zehn weitere), und das war der Rest von TASK-009. *Er stand, weil ein einfacher Typ ein
 * Aufzählungsfall war und keine Klasse — `nodes.implemented_by` trägt einen **Klassennamen**
 * (TASK-008, auf sein Wort: «wenn das ohne Factory geht, weil der Klassenname da drinsteht,
 * perfekt»), und ein Aufzählungsfall hat keinen.* **Mit [D-484](../../../docs/NewConcept/90-decision-log.md)
 * hat er einen**: {@see \Taxmod\Core\Model\Type\IntType} ist der Integer-Typ, und der Knoten sagt
 * das selbst. **Damit gilt `AR-1` auch hier: die Bindung steht im Modell und nicht daneben.**
 *
 * ```mermaid
 * flowchart LR
 *   A["nach einem Typ fragen"] --> B["nodes.implemented_by = IntType"]
 *   B -->|"nichts"| C["der Name unter Data Types · Notnagel"]
 *   C --> D["die Klasse in den Knoten schreiben"]
 * ```
 *
 * ⚠️ **Der Notnagel bleibt und tut jetzt den Umzug**: *eine Installation, die vor dieser Änderung
 * gesät wurde, trägt an ihren Typknoten keine Klasse. Der erste Zugriff findet sie über den Namen
 * und **schreibt die Klasse fest** — journalisiert, mit Schattenzeile, also umkehrbar
 * ([D-535](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * ⚠️ **Ein weggeworfener Typ bleibt weggeworfen.** *Eine Saat ist danach gewöhnlicher Inhalt
 * ([D-119](../../../docs/NewConcept/90-decision-log.md)); {@see ModelEditor::nodeImplementing()}
 * übergeht, was im Müll liegt, und der Notnagel sieht nur unter `Data Types` nach.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class SeededTypeNodes implements TypeNodes
{
    /** Die Klasse, die diesen Typ umsetzt — der Wert, der in `nodes.implemented_by` steht. */
    public static function classFor(SimpleType $type): string
    {
        return SpecialisedTypes::for($type)::class;
    }

    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
        // ⚠️ *Nachgereicht und nicht verlangt: die zwanzig Aufrufer bauen dieses Objekt mit zwei
        // Abhängigkeiten, und ein Schreibweg, der nur beim Nachtragen gebraucht wird, soll sie nicht
        // alle anfassen. Es ist der Rand — hier darf WordPress zusammengesteckt werden.*
        private ?ModelEditor $editor = null,
    ) {
    }

    /**
     * @var array<string,int>|null Die Ids dieses Aufrufs, nach {@see SimpleType::$value}. **Ein
     *                             Lesen je Objekt**, weil der Vorfahrenlauf in
     *                             {@see \Taxmod\Core\Service\Rendering} je Ebene fragt und eine
     *                             Abfrage je Ebene die Schleife wäre, die `CD-7` verbietet.
     */
    private ?array $ids = null;

    public function nodeId(SimpleType $type): ?int
    {
        return $this->ids()[$type->value] ?? null;
    }

    public function typeOf(int $nodeId): ?SimpleType
    {
        if ($nodeId <= 0) {
            return null;
        }

        $value = array_search($nodeId, $this->ids(), true);

        return $value === false ? null : SimpleType::from($value);
    }

    public function remember(SimpleType $type, int $nodeId): void
    {
        $this->editor()->setImplementedBy($nodeId, self::classFor($type));

        // ⚠️ *Nur, wenn der Zwischenspeicher schon steht. Ihn mit einem Eintrag anzulegen hiesse,
        // dass jeder andere Typ «nicht notiert» antwortet, ohne dass je gelesen wurde.*
        if ($this->ids !== null) {
            $this->ids[$type->value] = $nodeId;
        }
    }

    /** @return array<string,int> */
    private function ids(): array
    {
        if ($this->ids !== null) {
            return $this->ids;
        }

        $ids     = [];
        $missing = [];
        $muell   = $this->framework->trash();

        // ⚠️ *Eine Abfrage für alle elf, nicht elf Abfragen (`CD-7`).*
        $byClass = $this->nodes->byImplementations(array_map(
            static fn (SimpleType $type): string => self::classFor($type),
            SimpleType::cases()
        ));

        foreach (SimpleType::cases() as $type) {
            $node = $byClass[self::classFor($type)] ?? null;

            if ($node !== null && $node->id !== $muell->id && ! $node->isDescendantOf($muell)) {
                $ids[$type->value] = $node->id;
            } else {
                $missing[] = $type;
            }
        }

        return $this->ids = $missing === [] ? $ids : $this->fromTheirNames($ids, $missing);
    }

    /**
     * Der Notnagel — und er schreibt die Klasse fest, damit er kein zweites Mal gebraucht wird.
     *
     * ⚠️ **Ohne ihn wäre ein Aufstieg ein Verlust** ([D-510](../../../docs/NewConcept/90-decision-log.md)):
     * *eine bestehende Installation trägt an ihren Typknoten keine Klasse, und eine Suche, die nur
     * die Spalte kennt, meldete jeden gesäten Typ als abwesend.*
     *
     * ⚠️ *Eine Abfrage für den ganzen Ast, nicht eine je fehlendem Typ — und sie läuft einmal je
     * Installation, weil sie sich zuletzt selbst überflüssig macht.*
     *
     * @param  array<string,int> $ids     Was die Spalte schon beantwortet hat.
     * @param  list<SimpleType>  $missing Die Typen, an denen keine Klasse steht.
     * @return array<string,int>
     */
    private function fromTheirNames(array $ids, array $missing): array
    {
        $wanted = [];

        foreach ($missing as $type) {
            $wanted[$type->value] = true;
        }

        foreach ($this->nodes->childrenOf($this->framework->rootOf(Branch::DataTypes)) as $child) {
            $type = SimpleType::fromNodeName($child->name);

            // ⚠️ *Ein Knoten, mit dem ein anderer Typ schon antwortet, ist nicht dieser. Zwei Knoten
            // dürfen denselben Namen tragen (D-022), und `int` neben `Integer` ist genau das Paar,
            // an dem das schon einmal wehgetan hat.*
            if ($type === null || ! isset($wanted[$type->value]) || in_array($child->id, $ids, true)) {
                continue;
            }

            $ids[$type->value] = $child->id;
            unset($wanted[$type->value]);

            $this->remember($type, $child->id);
        }

        return $ids;
    }

    private function editor(): ModelEditor
    {
        return $this->editor ??= new ModelEditor(
            $this->nodes,
            new WpdbRelationRepository(),
            $this->framework,
            new WpdbChangelog(new SystemClock())
        );
    }
}
