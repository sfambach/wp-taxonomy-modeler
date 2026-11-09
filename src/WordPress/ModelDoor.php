<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Addon\VariantAddon;
use Taxmod\Core\Addon\VariantOfAddon;
use Taxmod\Core\Exception\ModelDoorRefused;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Setting\NodeProperties;
use Taxmod\Core\Model\Variants;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;

/**
 * The one door a module has into the model (D-1186): reads through `taxmod/read-node` and `taxmod/read-tree`, writes
 * through `taxmod/write-data` (D-936), and the few reads no ability offers through the modeller's own record repository —
 * so no module touches the persistence classes or copies this door.
 *
 * A door reads a node at most once (CD-7) and forgets it when it writes. ⚠️ The memory belongs to the door, not to the
 * request: a write through another path (`taxmod/apply`, the modeller's screens) would leave a request-wide memory stale.
 *
 * ```mermaid
 * flowchart LR
 *   Lager & Fertigung --> D[ModelDoor] -->|read-node · read-tree · write-data| A[Abilities]
 *   D -->|byIds · ofNodes · valuesOfMany · recordsMatching| R[WpdbRecordRepository]
 * ```
 *
 * @see docs/gestaltung-und-architektur.md R1, R2
 */
final class ModelDoor
{
    /** @var array<int, array<string, mixed>> node id → the answer of `read-node` */
    private array $nodes = [];

    /** @var array<string, array<string, mixed>> root|depth → the answer of `read-tree` */
    private array $trees = [];

    /**
     * The records of a node with their raw values — a variant with the values it shows (D-1208).
     *
     * @return array<int, array<int, list<string>>> record id → relation id → values
     */
    public function records(int $node): array
    {
        $out    = [];
        $addons = [];

        foreach ($this->readNode($node)['records'] ?? [] as $record) {
            $values = [];

            foreach ($record['values'] ?? [] as $value) {
                $values[(int) $value['relation_id']][] = (string) $value['value'];
            }

            $out[(int) $record['id']] = $values;
        }

        foreach ($this->readNode($node)['fields'] ?? [] as $field) {
            $addons[(int) $field['relation_id']] = array_map('strval', (array) ($field['addons'] ?? []));
        }

        return $this->shown($out, $addons);
    }

    /**
     * The fields of a node as the modeller lists them, inherited ones included — from the same read as its records.
     *
     * @return array<int, array{name: string, target: int, type: string|null, kind: string, multiplicity: string, addons: list<string>}> relation id → field
     */
    public function fields(int $node): array
    {
        $out = [];

        foreach ($this->readNode($node)['fields'] ?? [] as $field) {
            $out[(int) $field['relation_id']] = [
                'name'         => (string) ($field['name'] ?? ''),
                'target'       => (int) ($field['target'] ?? 0),
                'type'         => isset($field['type']) ? (string) $field['type'] : null,
                'kind'         => (string) ($field['kind'] ?? ''),
                'multiplicity' => (string) ($field['multiplicity'] ?? ''),
                'addons'       => array_map('strval', (array) ($field['addons'] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * The records among these that still need a review: created with their name only, not yet saved in the full form (D-1255).
     *
     * @param list<int> $records
     * @return list<int>
     */
    public function needingReview(array $records): array
    {
        return Persistence\RecordReviews::among($records);
    }

    /**
     * The group properties modules have declared (D-1259): a label alone is a yes/no, `['label' => …, 'type' => 'id']` holds an id.
     *
     * @return array<string, array{label: string, type: string}>
     */
    public static function properties(): array
    {
        $out = [];

        foreach ((array) apply_filters(Plugin::NODE_PROPERTIES_FILTER, []) as $key => $declared) {
            $key   = sanitize_key((string) $key);
            $label = is_array($declared) ? (string) ($declared['label'] ?? '') : (string) $declared;
            $type  = is_array($declared) && ($declared['type'] ?? '') === NodeProperties::ID ? NodeProperties::ID : NodeProperties::YES_NO;

            if ($key !== '' && $label !== '') {
                $out[$key] = ['label' => $label, 'type' => $type];
            }
        }

        return $out;
    }

    /** The declared properties as the core knows them: key → type. */
    public static function propertyTypes(): NodeProperties
    {
        return new NodeProperties(array_map(static fn (array $p): string => $p['type'], self::properties()));
    }

    /**
     * Whether a group property holds at each of these nodes — its own value or the nearest ancestor's, null where none says (D-1259).
     * Two queries for any number of nodes (CD-7).
     *
     * @param list<int> $nodes
     * @return array<int, bool|int|null> node id → value: a yes/no as bool, an id as int
     */
    public function propertiesOf(array $nodes, string $key): array
    {
        $found = (new Persistence\WpdbNodeRepository())->byIds(array_values(array_unique(array_map('intval', $nodes))));

        return self::propertyTypes()->inherited(array_values($found), (new Persistence\WpdbSettingsRepository())->valuesNamed($key), $key);
    }

    /**
     * Set a group property at one node: a bool for a yes/no, an int for an id; null drops the node's own value so it inherits
     * again (D-1259). One act, undoable.
     */
    public function setProperty(int $node, string $key, bool|int|null $value): void
    {
        if (! isset(self::properties()[$key])) {
            throw ModelDoorRefused::because('No module declared the property ' . $key . '.');
        }

        $this->nodes = [];
        $this->trees = [];
        $this->execute('taxmod/apply', ['changes' => [['op' => 'set_setting', 'node' => $node, 'attribute' => $key, 'value' => match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default         => (string) $value,
        }]]]);
    }

    /**
     * The modeller's own controls for these fields of `$node` (D-1250) — a quantity is the unit-value control it is in the
     * material, never an input of the module's own. Posted as `$prefix[relation id]`; read back with {@see typed()}.
     *
     * @param list<int>          $relations
     * @param array<int, string> $values characters by relation id where there is no record yet; leave out what is not known
     * @return array<int, string> relation id → control markup, escaped
     */
    public function controls(int $node, array $relations, string $prefix, string $formId = '', int $record = 0, array $values = []): array
    {
        $controls = apply_filters(Plugin::FIELD_CONTROLS_FILTER, [], $node, $relations, $prefix, $formId, $record, $values);

        return is_array($controls) ? $controls : [];
    }

    /**
     * What was typed into {@see controls()}, as raw values for {@see write()}; an empty field is left out.
     *
     * @param array<int|string, mixed> $submitted `$_POST[$prefix]`, as posted
     * @return array<int, string> relation id → raw value
     */
    public function typed(int $node, array $submitted): array
    {
        $values = apply_filters(Plugin::TYPED_VALUES_FILTER, [], $node, $submitted);

        return is_array($values) ? $values : [];
    }

    /**
     * Every node under `$root` with its name and parent.
     *
     * @return array<int, array{name: string, parent: int, class: string, hidden: bool}>
     */
    public function tree(int $root, int $depth = 12): array
    {
        $out = [];

        foreach ($this->readTree($root, $depth)['rows'] ?? [] as $row) {
            $out[(int) $row['id']] = ['name' => (string) ($row['name'] ?? ''), 'parent' => (int) ($row['parent'] ?? 0), 'class' => (string) ($row['class'] ?? ''), 'hidden' => ! empty($row['hidden'])];
        }

        return $out;
    }

    /** @return array<int, string> child node id → name */
    public function children(int $node): array
    {
        $out = [];

        foreach ($this->readTree($node, 1)['rows'] ?? [] as $row) {
            if ((int) ($row['parent'] ?? 0) === $node) {
                $out[(int) $row['id']] = (string) $row['name'];
            }
        }

        return $out;
    }

    /**
     * The nodes below a node with their distance from it, and the node's own name.
     *
     * @return array{name: string, steps: array<int, int>} node id → steps below `$root` (the root itself is 0)
     */
    public function subtree(int $root): array
    {
        $parents = [];
        $name    = '';

        foreach ($this->readTree($root, 12)['rows'] ?? [] as $row) {
            $parents[(int) $row['id']] = (int) ($row['parent'] ?? 0);
            $name                      = (int) $row['id'] === $root ? (string) ($row['name'] ?? '') : $name;
        }

        $steps = [$root => 0];

        foreach (array_keys($parents) as $node) {
            $path = [];

            for ($at = $node; ! isset($steps[$at]) && isset($parents[$at]) && ! in_array($at, $path, true); $at = $parents[$at]) {
                $path[] = $at;
            }

            if (isset($steps[$at])) {
                foreach (array_reverse($path) as $i => $on) {
                    $steps[$on] = $steps[$at] + $i + 1;
                }
            }
        }

        return ['name' => $name, 'steps' => $steps];
    }

    /**
     * Which node each record belongs to, in one query — no ability says it, and reading every node would be hundreds of reads.
     *
     * @param list<int> $records
     * @return array<int, int> record id → node id
     */
    public function nodesOf(array $records): array
    {
        $out = [];

        foreach ($records === [] ? [] : (new WpdbRecordRepository())->byIds($records) as $id => $record) {
            $out[(int) $id] = (int) $record->nodeId;
        }

        return $out;
    }

    /**
     * The records in these nodes that every condition matches — the modeller's own record search (D-958 F3a).
     *
     * @param list<int>                                   $nodes
     * @param list<array{text: string, nodes: list<int>}> $conditions a word, found in a text value or in the name of one of these nodes
     * @return list<int>
     */
    public function recordsMatching(array $nodes, array $conditions, int $limit): array
    {
        return array_map(intval(...), (new WpdbRecordRepository())->recordsMatching($nodes, $conditions, $limit));
    }

    /**
     * The records whose field `$relation` points at one of these records, in one query — the few records of a large node
     * that belong to one thing, instead of the whole node (D-1195).
     *
     * @param list<int> $records
     * @return list<int>
     */
    public function referrers(array $records, int $relation): array
    {
        $out = [];

        foreach ($records === [] ? [] : (new WpdbRecordRepository())->referrersOfMany($records) as $values) {
            foreach ($values as $value) {
                if ($value->relationId === $relation) {
                    $out[$value->recordId] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * The user records of these nodes, at most `$limit`, with the node each lives in.
     *
     * @param list<int> $nodes
     * @return array<int, int> record id → node id
     */
    public function recordsOf(array $nodes, int $limit): array
    {
        $out = [];

        foreach ($nodes === [] ? [] : (new WpdbRecordRepository())->ofNodes($nodes) as $node => $records) {
            foreach ($records as $record) {
                if (count($out) >= $limit) {
                    break 2;
                }

                if ($record->recordType !== RecordType::Settings) {
                    $out[(int) $record->id] = (int) $node;
                }
            }
        }

        return $out;
    }

    /**
     * Only these records with their values, in one query — for a few records of a node too large to read whole. A variant
     * comes with the values it shows (D-1208).
     *
     * @param list<int> $records
     * @return array<int, array<int, list<string>>> record id → relation id → values
     */
    public function valuesOf(array $records): array
    {
        $out = $this->rawValuesOf($records);

        if ($out === []) {
            return [];
        }

        $relations = array_keys(array_replace(...array_values($out)));

        return $this->shown($out, self::addonsOf($relations));
    }

    /**
     * The add-on names chosen at these fields, asked of the modeller's one resolver (D-1208).
     *
     * @param list<int> $relations
     * @return array<int, list<string>> relation id → add-on names
     */
    private static function addonsOf(array $relations): array
    {
        $answer = $relations === [] ? [] : apply_filters(Plugin::FIELD_ADDONS_FILTER, [], $relations);

        return is_array($answer) ? $answer : [];
    }

    /**
     * What a variant shows instead of its raw values: its parent's on every field but the «Je Variante» ones (D-1208). A
     * parent outside `$values` costs one more read for all of them together (CD-7).
     *
     * @param array<int, array<int, list<string>>> $values record → relation → values
     * @param array<int, list<string>>             $addons relation → add-on names
     * @return array<int, array<int, list<string>>>
     */
    private function shown(array $values, array $addons): array
    {
        $variantOf = 0;
        $flagged   = [];

        foreach ($addons as $relation => $names) {
            if (in_array(VariantOfAddon::NAME, $names, true)) {
                $variantOf = (int) $relation;
            }

            if (in_array(VariantAddon::NAME, $names, true)) {
                $flagged[] = (int) $relation;
            }
        }

        $parents = $variantOf === 0 ? [] : Variants::parents($values, $variantOf);

        if ($parents === []) {
            return $values;
        }

        $missing = array_values(array_diff(array_unique(array_values($parents)), array_keys($values)));
        $known   = array_intersect_key($values, array_flip($parents)) + $this->rawValuesOf($missing);

        return Variants::shown($values, $known, $variantOf, $flagged);
    }

    /**
     * @param list<int> $records
     * @return array<int, array<int, list<string>>> record id → relation id → values, as stored
     */
    private function rawValuesOf(array $records): array
    {
        $out = [];

        foreach ($records === [] ? [] : (new WpdbRecordRepository())->valuesOfMany($records) as $id => $values) {
            $out[(int) $id] = [];

            foreach ($values as $value) {
                $out[(int) $id][(int) $value->relationId][] = (string) $value->value->rawValue();
            }
        }

        return $out;
    }

    /**
     * One writer at a time under `$key` for the length of the request (D-1147): two pickers who both read «1 left» must not
     * both book it. Throws {@see \Taxmod\Core\Exception\ConcurrentChange} when the other one holds it too long.
     */
    public static function serially(string $key, callable $work): mixed
    {
        return Persistence\WriteLock::around($key, $work);
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, mixed> the modeller's answer, with the new ids under `refs`
     */
    public function write(array $changes): array
    {
        $this->nodes = [];
        $this->trees = [];

        return $this->execute('taxmod/write-data', ['changes' => $changes]);
    }

    /** @return array<string, mixed> */
    private function readNode(int $node): array
    {
        return $this->nodes[$node] ??= $this->execute('taxmod/read-node', ['node' => $node]);
    }

    /** @return array<string, mixed> */
    private function readTree(int $root, int $depth): array
    {
        return $this->trees[$root . '|' . $depth] ??= $this->execute('taxmod/read-tree', ['root' => $root, 'depth' => $depth]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function execute(string $name, array $input): array
    {
        $ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;

        if ($ability === null) {
            throw ModelDoorRefused::because($name . ' is not available — is the Taxonomy Modeller active?');
        }

        $result = $ability->execute($input);

        if (is_wp_error($result)) {
            throw ModelDoorRefused::because($result->get_error_message());
        }

        return is_array($result) ? $result : [];
    }
}
