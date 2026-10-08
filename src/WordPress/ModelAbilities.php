<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Tree;
use Taxmod\Core\Repository\Changelog;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Backup;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;

/**
 * Das Modell über die WordPress-Abilities lesen und ändern — damit es online gepflegt werden kann, auch von einem KI-Werkzeug ([D-911](../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   C["Aufruf (MCP / REST)"] --> P["Fähigkeit + Stand prüfen"]
 *   P --> R["read-tree · read-node"]
 *   P --> A["apply: Liste von Änderungen"]
 *   A --> T["eine Transaktion, ein Akt im Änderungsbuch"]
 *   T --> W["dieselben Wege wie die Maske"]
 * ```
 *
 * ⚠️ **Kein zweiter Schreibweg.** *Jede Änderung geht durch {@see ModelEditor} oder die Maske selbst
 * ({@see NodesScreen::createChild()}, {@see NodesScreen::writeRecord()}) — Typen, Wandler, Prüfer, Vorbelegung und
 * Zusammenfassung gelten hier genau wie dort.*
 *
 * ⚠️ **Alles oder nichts.** *Eine Liste ist eine Handlung: eine Änderungsnummer, eine Transaktion. Scheitert ein Schritt,
 * wird zurückgerollt und der Schritt genannt.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class ModelAbilities
{
    public const CATEGORY = 'taxmod';

    /** So viele Ebenen liefert `read-tree` höchstens — mehr wäre eine Antwort, die niemand liest. */
    private const MAX_TREE_DEPTH = 12;

    /** So viele Änderungen nimmt ein Aufruf — darüber teilt der Aufrufer. */
    private const MAX_CHANGES = 500;

    /**
     * @param \Closure(): NodesScreen $screen Erst beim Ausführen gebaut — das Registrieren läuft in jeder Anfrage.
     * @param \Closure(): ModelEditor $editor
     * @param \Closure(): Changelog   $changelog
     * @param \Closure(): SeededFrameworkNodes $framework
     */
    public function __construct(
        private readonly \Closure $screen,
        private readonly \Closure $editor,
        private readonly \Closure $changelog,
        private readonly \Closure $framework,
    ) {
    }

    public function registerCategory(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label'       => __('Taxonomy Modeller', 'taxmod'),
            'description' => __('Read and change the model of the Taxonomy Modeller.', 'taxmod'),
        ]);
    }

    public function register(): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];

        wp_register_ability('taxmod/read-tree', [
            'label'               => __('Read the model tree', 'taxmod'),
            'description'         => __('Lists the nodes under a node (default: the root) with id, name, parent, class and depth. The trash is left out.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'root'  => $id + ['description' => __('Start node id; empty means the root.', 'taxmod')],
                    'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_TREE_DEPTH, 'default' => 3],
                ],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->readTree(is_array($input) ? $input : [])),
            'permission_callback' => $this->allowed(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        wp_register_ability('taxmod/read-node', [
            'label'               => __('Read a node', 'taxmod'),
            'description'         => __('One node with its children, its fields (relation id, name, target, kind, multiplicity, simple type) and its records with their values.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => ['node' => $id],
                'required'   => ['node'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->readNode((int) (is_array($input) ? ($input['node'] ?? 0) : 0))),
            'permission_callback' => $this->allowed(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        $ziel = ['type' => ['integer', 'string'], 'description' => __('A node id, or "@ref" of a node created earlier in this list.', 'taxmod')];

        wp_register_ability('taxmod/apply', [
            'label'               => __('Change the model', 'taxmod'),
            'description'         => __(
                'Applies a list of changes as one act (one change number, all or nothing). Ops: create_node {name, parent, class?}, rename_node {node, name}, trash_node {node}, add_field {node, target, name?, kind?}, create_record {node, record_type?}, set_values {record, values: {relation_id or @ref: text, or {values: [text, ...]} for a field taking several}, own_value?, record_type?, locale?}, remove_record {record}. Any op may carry "ref"; later ops use "@ref" in place of an id. Values are typed as on the admin screen.',
                'taxmod'
            ),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'changes' => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => self::MAX_CHANGES,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'op'          => ['type' => 'string', 'enum' => ['create_node', 'rename_node', 'trash_node', 'add_field', 'create_record', 'set_values', 'remove_record']],
                                'ref'         => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]+$'],
                                'name'        => ['type' => 'string'],
                                'parent'      => $ziel,
                                'node'        => $ziel,
                                'target'      => $ziel,
                                'record'      => $ziel,
                                'class'       => ['type' => 'string'],
                                'kind'        => ['type' => 'string', 'enum' => array_map(static fn (RelationKind $k): string => $k->value, RelationKind::cases())],
                                'record_type' => ['type' => 'string', 'enum' => array_map(static fn (RecordType $t): string => $t->value, RecordType::cases())],
                                'values'      => ['type' => 'object'],
                                'own_value'   => ['type' => 'string'],
                                'locale'      => ['type' => 'string'],
                            ],
                            'required'   => ['op'],
                        ],
                    ],
                    'backup'  => ['type' => 'boolean', 'default' => false, 'description' => __('Save the whole model to uploads/taxmod-backups first.', 'taxmod')],
                ],
                'required'   => ['changes'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->apply(is_array($input) ? $input : [])),
            'permission_callback' => $this->allowed(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);
    }

    public function allowed(mixed $input = null): bool
    {
        return current_user_can(Plugin::CAPABILITY);
    }

    /**
     * Die Grenze: ein veralteter Stand wird nicht beschrieben, eine Ausnahme des Kerns wird ein `WP_Error` (`CD-10`).
     *
     * ⚠️ *Ein Aufruf über MCP durchläuft kein `admin_init`, also auch keinen Umbau der Tabellen. Steht die Schema-Version
     * nicht, wo der Code sie erwartet, oder scheiterte die Aktivierung, antwortet jede Ability mit dem Grund
     * ([D-909](../../docs/NewConcept/90-decision-log.md)).*
     *
     * @param \Closure(): array<string, mixed> $work
     * @return array<string, mixed>|\WP_Error
     */
    private function guarded(\Closure $work): array|\WP_Error
    {
        $stand   = (int) get_option(Schema::VERSION_OPTION, 0);
        $fehler  = (string) get_option(Plugin::ACTIVATION_FAILURE, '');

        if ($stand !== Schema::VERSION || $fehler !== '') {
            return new \WP_Error('taxmod_not_ready', sprintf(
                /* translators: 1: stored schema version, 2: expected schema version, 3: activation error */
                __('The model is not ready (schema %1$d, expected %2$d). Open wp-admin once, or restore a backup. %3$s', 'taxmod'),
                $stand,
                Schema::VERSION,
                $fehler
            ));
        }

        try {
            return $work();
        } catch (\Throwable $e) {
            return new \WP_Error('taxmod_refused', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{root: int, rows: list<array<string, mixed>>}
     */
    private function readTree(array $input): array
    {
        $framework = $this->framework();
        $root      = isset($input['root']) ? $this->node((int) $input['root']) : $framework->root();
        $depth     = max(1, min(self::MAX_TREE_DEPTH, (int) ($input['depth'] ?? 3)));
        $tree      = new Tree(new Persistence\WpdbNodeRepository());
        $rows      = [];

        foreach ($tree->rowsUnder($root, [$framework->trash()->id], [], true, true) as $row) {
            if ($row['depth'] > $depth) {
                continue;
            }

            $rows[] = $this->nodeRow($row['node']) + ['depth' => $row['depth'], 'has_children' => $row['hasChildren']];
        }

        return ['root' => $root->id, 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function readNode(int $id): array
    {
        $node    = $this->node($id);
        $screen  = $this->screen();
        $entries = $screen->entries();
        $felder  = [];

        foreach ($screen->fieldsWithTypes($id) as ['relation' => $r, 'type' => $type]) {
            $felder[] = [
                'relation_id'  => $r->id,
                'name'         => $r->name,
                'target'       => $r->toNodeId,
                'declared_at'  => $r->fromNodeId,
                'kind'         => $r->kind->value,
                'multiplicity' => $r->multiplicity->value,
                'type'         => $type?->value,
            ];
        }

        $records = $entries->recordsOf($id);
        $werte   = $entries->valuesOfMany(array_map(static fn ($r): int => $r->id, $records));
        $saetze  = [];

        foreach ($records as $record) {
            $zeilen = [];

            foreach ($werte[$record->id] ?? [] as $wert) {
                $zeilen[] = [
                    'relation_id' => $wert->relationId,
                    'locale'      => $wert->locale,
                    'type'        => $wert->value->typeName(),
                    'value'       => $wert->value->rawValue(),
                ];
            }

            $saetze[] = ['id' => $record->id, 'record_type' => $record->recordType->value, 'values' => $zeilen];
        }

        return [
            'node'     => $this->nodeRow($node),
            'children' => array_map($this->nodeRow(...), $this->editor()->childrenOf($id)),
            'fields'   => $felder,
            'records'  => $saetze,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function apply(array $input): array
    {
        global $wpdb;

        $changes = is_array($input['changes'] ?? null) ? array_values($input['changes']) : [];

        if ($changes === [] || count($changes) > self::MAX_CHANGES) {
            throw new \InvalidArgumentException(sprintf('Between 1 and %d changes per call.', self::MAX_CHANGES));
        }

        $sicherung = ! empty($input['backup']) ? (new Backup())->safetyCopy('vor-ability') : null;
        $refs      = [];
        $ergebnis  = [];
        $changelog = ($this->changelog)();

        // ⚠️ *Unter der Klammer der Wächter ist schon eine Transaktion offen, und ein zweites `START TRANSACTION` bestätigte
        // sie stillschweigend (`scripts/dev/lib/no-write.php`). Darum der Sicherungspunkt — die eigene Transaktion nur, wo keine steht.*
        $eigene = ! defined('TAXMOD_NO_WRITE');

        if ($eigene) {
            $wpdb->query('START TRANSACTION');
        }

        $wpdb->query('SAVEPOINT taxmod_apply');
        $changelog->beginAct();

        try {
            foreach ($changes as $stelle => $change) {
                try {
                    $id = $this->applyOne(is_array($change) ? $change : [], $refs);
                } catch (\Throwable $e) {
                    throw new \RuntimeException(sprintf('Change %d (%s) refused, nothing was written: %s', $stelle, (string) ($change['op'] ?? '?'), $e->getMessage()), 0, $e);
                }

                if (isset($change['ref']) && $id !== null) {
                    $refs[(string) $change['ref']] = $id;
                }

                $ergebnis[] = ['op' => (string) $change['op'], 'id' => $id];
            }

            $wpdb->query($eigene ? 'COMMIT' : 'RELEASE SAVEPOINT taxmod_apply');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK TO SAVEPOINT taxmod_apply');

            if ($eigene) {
                $wpdb->query('ROLLBACK');
            }

            throw $e;
        } finally {
            $changelog->endAct();
        }

        return ['applied' => $ergebnis, 'refs' => $refs, 'backup' => $sicherung];
    }

    /**
     * Eine Änderung — die Wege sind dieselben wie die Knöpfe der Maske.
     *
     * @param array<string, mixed> $change
     * @param array<string, int>   $refs
     * @return int|null Die Id dessen, was angelegt oder geändert wurde.
     */
    private function applyOne(array $change, array $refs): ?int
    {
        $editor = $this->editor();
        $screen = $this->screen();
        $name   = trim(sanitize_text_field((string) ($change['name'] ?? '')));
        $at     = fn (string $key): int => $this->resolve($change[$key] ?? null, $refs, $key);

        switch ((string) ($change['op'] ?? '')) {
            case 'create_node':
                if ($name === '') {
                    throw new \InvalidArgumentException('create_node needs a name.');
                }

                return $screen->createChild($name, $this->node($at('parent'))->id, $this->nodeClass((string) ($change['class'] ?? '')))->id;

            case 'rename_node':
                return $editor->rename($at('node'), $name)->id;

            case 'trash_node':
                return $editor->moveToTrash($at('node'))->id;

            case 'add_field':
                $target = $this->node($at('target'));
                $kind   = isset($change['kind']) ? RelationKind::from((string) $change['kind']) : null;

                return $editor->addField($at('node'), $target->id, $name === '' ? $target->name : $name, $kind)->id;

            case 'create_record':
                $type = isset($change['record_type']) ? RecordType::from((string) $change['record_type']) : RecordType::User;

                return $screen->entries()->create($this->node($at('node'))->id, $type)->id;

            case 'set_values':
                $recordId = $at('record');
                $record   = $screen->entries()->find($recordId) ?? throw new \InvalidArgumentException("No record {$recordId}.");
                $values   = [];

                // ⚠️ *Ein Feld aus derselben Liste heisst hier «@ref» — erst so lässt sich eine Initiative in einem Aufruf anlegen.*
                foreach (is_array($change['values'] ?? null) ? $change['values'] : [] as $kante => $wert) {
                    $values[$this->resolve($kante, $refs, 'values')] = $wert;
                }
                $own      = isset($change['own_value']) ? (string) $change['own_value'] : null;
                $locale   = sanitize_text_field((string) ($change['locale'] ?? ''));

                // ⚠️ *`wp_slash()`, weil {@see NodesScreen::writeRecord()} Eingaben in der Form von `$_POST` liest.*
                $screen->writeRecord(
                    $record->nodeId,
                    $recordId,
                    wp_slash($values),
                    isset($change['record_type']) ? RecordType::from((string) $change['record_type']) : null,
                    $own === null ? null : wp_slash($own),
                    $locale === '' ? SettingsScreen::neutralLocale() : $locale
                );

                return $recordId;

            case 'remove_record':
                $recordId = $at('record');
                $screen->entries()->removeRecord($recordId);

                return $recordId;
        }

        throw new \InvalidArgumentException('Unknown op.');
    }

    /** @param array<string, int> $refs */
    private function resolve(mixed $given, array $refs, string $key): int
    {
        if (is_string($given) && str_starts_with($given, '@')) {
            return $refs[substr($given, 1)] ?? throw new \InvalidArgumentException("Unknown reference {$given} in {$key}.");
        }

        $id = is_numeric($given) ? (int) $given : 0;

        if ($id <= 0) {
            throw new \InvalidArgumentException("{$key} is missing.");
        }

        return $id;
    }

    private function node(int $id): Node
    {
        return $this->editor()->find($id) ?? throw new \InvalidArgumentException("No node {$id}.");
    }

    /** @return class-string<\Taxmod\Core\Model\NodeClass\NodeClass>|null */
    private function nodeClass(string $given): ?string
    {
        if ($given === '') {
            return null;
        }

        foreach (Contracts::all() as $klasse) {
            if ($klasse === $given || substr(strrchr('\\' . $klasse, '\\'), 1) === $given) {
                return $klasse;
            }
        }

        throw new \InvalidArgumentException("Unknown class {$given}.");
    }

    /** @return array<string, mixed> */
    private function nodeRow(Node $node): array
    {
        return [
            'id'     => $node->id,
            'name'   => $node->name,
            'parent' => $node->parentNodeId,
            'class'  => substr(strrchr('\\' . $node->klasse, '\\'), 1),
            'hidden' => $node->hide,
        ];
    }

    private ?NodesScreen $screenOnce = null;

    private ?ModelEditor $editorOnce = null;

    /** Einmal je Anfrage gebaut — die Maske trägt Zeichenlauf und Nachlauf, die nicht je Änderung neu entstehen sollen. */
    private function screen(): NodesScreen
    {
        return $this->screenOnce ??= ($this->screen)();
    }

    private function editor(): ModelEditor
    {
        return $this->editorOnce ??= ($this->editor)();
    }

    private function framework(): SeededFrameworkNodes
    {
        return ($this->framework)();
    }
}
