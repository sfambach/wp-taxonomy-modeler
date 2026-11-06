<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Exception\ModelDoorRefused;
use Taxmod\Core\Model\RecordType;
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
     * The records of a node with their raw values.
     *
     * @return array<int, array<int, list<string>>> record id → relation id → values
     */
    public function records(int $node): array
    {
        $out = [];

        foreach ($this->readNode($node)['records'] ?? [] as $record) {
            $values = [];

            foreach ($record['values'] ?? [] as $value) {
                $values[(int) $value['relation_id']][] = (string) $value['value'];
            }

            $out[(int) $record['id']] = $values;
        }

        return $out;
    }

    /**
     * The fields of a node as the modeller lists them, inherited ones included — from the same read as its records.
     *
     * @return array<int, array{name: string, target: int, type: string|null, kind: string, multiplicity: string}> relation id → field
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
            ];
        }

        return $out;
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
     * Only these records with their values, in one query — for a few records of a node too large to read whole.
     *
     * @param list<int> $records
     * @return array<int, array<int, list<string>>> record id → relation id → values
     */
    public function valuesOf(array $records): array
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
