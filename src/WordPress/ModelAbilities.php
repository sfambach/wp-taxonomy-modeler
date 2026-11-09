<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Model\RecordStamp;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Model\RecordInTree;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Service\BlockUsage;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\RecordQuery;
use Taxmod\Core\Service\Tree;
use Taxmod\Core\Repository\Changelog;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Backup;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\Query;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\Transaction;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;

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

    /**
     * What each op of `apply` takes and refuses — read on demand through `taxmod/help`, so the schema every session loads stays short (D-1210).
     *
     * ⚠️ *Plain English for the caller, not user-visible text: the texts are read by a model, not shown on a screen.*
     */
    private const OP_HELP = [
        'create_node' => '{name, parent, class?}',
        'rename_node' => '{node, name}',
        'trash_node' => '{node}',
        'move_node' => '{node, parent} — hangs the node and its subtree under another parent',
        'add_field' => '{node, target, name?, kind?, multiplicity?}',
        'rename_field' => '{node, field, name}',
        'remove_field' => '{node, field} — parks a field the node owns, with its values',
        'move_field' => '{node, field, to} — declares the field at another node, values stay; refused if a record with values would end up outside it',
        'retarget_field' => '{node, field, target} — points the field at another node and keeps its kind',
        'merge_field' => '{node, field, into, under?, first?} — moves the values of the field onto another field with the same target and parks it; refused if a record would end up outside «into» or already holds values there; with under only records below that node, and the field stays; with first the values go to the front of the other field\'s list, its own values move back and a value already there stands only once (D-1205)',
        'create_record' => '{node, record_type?, values?, locale?} — values as for set_values, written in the same act',
        'set_values' => '{record, values: {relation_id or @ref: text or @ref, or {values: [text, ...]} for a field taking several, or {part key: {inner relation_id: text}} for parts — key \"0\" fills the part the record already holds there, any other non-numeric key adds a new part}, own_value?, record_type?, locale?}',
        'remove_record' => '{record} — removes the record with its own parts, nested ones included; refused while a record outside it references one of its parts, naming who',
        'move_part' => '{part, to, field} — hangs a part held through field onto another record\'s list of the same field, keeping its id',
        'set_kind' => '{node, field, kind} — switches a field the node owns between aggregation and composition, the values stay',
        'set_multiplicity' => '{node, field, multiplicity} — sets how often a field the node owns may hold a value (1..1 makes it mandatory), the values stay',
        'move_record' => '{record, node, anywhere?, drop?} — moves a record to the parent or a child of its node (with anywhere: to any node of its branch) and rewrites its path, calculated and summary fields; refused if a value would be dropped, unless drop is true, and always if a field of another record that chooses it could not choose it at the new node',
        'set_list' => '{node, attribute, members: [relation or node ids]} — sets a list setting of the node itself (e.g. summary_fields) to exactly these members; with field {set_list, node: target of the field, field, attribute, members} only at that use site (e.g. erlaubte_einheiten of a unit-value field)',
        'set_label' => '{node, role, text, locale?} — sets one label of the node (role help, select, form …) as the labels box would; empty text removes it',
        'set_addons' => '{node, field?, addons: [{name, fields: {attribute: text or @ref}}]} — replaces the add-on functions of the node or, with field, of that use site (node then being the target of the field), in this order; name calculate with fields operation (plus, minus, times, divided_by) and field (a field of the record) makes the field a calculated one: read-only, every record recalculated (also when the steps are unchanged — sending them again recalculates); name metric on a node with fields title, measure (count, sum), value_field, date_field, filter_field (fields of the node), filter_value and months makes a key figure shown as a tile per month',
        'set_setting' => '{node, attribute, value, field?} — sets one plain setting (e.g. with_label, name_as_link_text) as the screen would; with field only at that use site, node then being the target of the field; an empty value removes it.',
        'duplicate_record' => '{record} — copies a record with its values and own parts next to itself.',
    ];

    /** So viele Sätze liefert eine Seite von `read-node` höchstens. */
    private const MAX_RECORDS_PAGE = 1000;

    /** So viele Änderungen nimmt ein Aufruf — darüber teilt der Aufrufer. */
    private const MAX_CHANGES = 500;

    /** Wer nur Sätze schreiben darf, das Modell aber nicht ändern (D-936) — ein Importer. */
    public const DATA_CAPABILITY = 'taxmod_write_data';

    /** Die Rolle, die nur diese Fähigkeit trägt — zusätzlich zur Rolle des Benutzers vergeben. */
    public const DATA_ROLE = 'taxmod_data_writer';

    /** Was `write-data` annimmt: Sätze und Werte, kein Knoten, kein Feld, keine Einstellung. */
    // D-1073: moving a record is data too — the stock's material page moves a material to another group.
    // D-1098: a part changing its head is data too — the stock moves a purchase between a requirement and a supplier draft.
    // D-1135: copying a record is data too — the stock copies a material and a storage place.
    private const DATA_OPS = ['create_record', 'set_values', 'remove_record', 'move_record', 'move_part', 'duplicate_record'];

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
            'description'         => __('Lists the nodes under a node (default: the root) with id, name, parent, class and depth. The trash is left out. Saves tokens: name (part of the name, searches the whole depth unless depth is given), class, compact (columns once, rows as lists, without class and hidden).', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'root'    => $id + ['description' => __('Start node id; empty means the root.', 'taxmod')],
                    'depth'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_TREE_DEPTH, 'default' => 3],
                    'name'    => ['type' => 'string', 'description' => __('Only nodes whose name contains this text, case ignored.', 'taxmod')],
                    'class'   => ['type' => 'string', 'description' => __('Only nodes of this class (short name as in the rows).', 'taxmod')],
                    'compact' => ['type' => 'boolean', 'default' => false],
                ],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->readTree(is_array($input) ? $input : [])),
            'permission_callback' => $this->allowedToRead(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        wp_register_ability('taxmod/read-node', [
            'label'               => __('Read a node', 'taxmod'),
            'description'         => __('One node with its children, its fields (relation id, name, target, kind, multiplicity, simple type) and its records with their values; total_records counts them all. Saves tokens: records false (structure only), limit and offset (a page of records), fields (only these relation ids), compact (values as {relation_id: value}, a list where a field holds several, "relation_id@locale" only off the neutral locale). A field computed at this node (e.g. Bezeichnung) names its parts in composed_of — its own id there stands for the node name; a value written to it is overwritten.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'node'    => $id,
                    'records' => ['type' => 'boolean', 'default' => true],
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_RECORDS_PAGE],
                    'offset'  => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                    'fields'  => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    'compact' => ['type' => 'boolean', 'default' => false],
                ],
                'required'   => ['node'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->readNode((int) (is_array($input) ? ($input['node'] ?? 0) : 0), is_array($input) ? $input : [])),
            'permission_callback' => $this->allowedToRead(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        // *D-1209: ask for the records that matter instead of reading a whole node — «is anything stored under Alex?» once meant 561 lots.*
        wp_register_ability('taxmod/query', [
            'label'               => __('Query records', 'taxmod'),
            'description'         => __('Records of a node (and its subtree unless subtree is false) that meet every condition, one page of them with the total. where: list of {field: relation id, op: eq | in | contains | empty | not_empty | within, value or values}; eq and in compare the stored value (text, number, date YYYY-MM-DD, or the id a reference points at), contains a text ignoring case; within {field, value: record id, parent_field} matches a reference to that record or any record below it by its parent field (e.g. a storage place and its sub-places). search: a word in any text value. fields: relation ids or names to answer with, as the words the screen shows; without fields each record comes with its own word only. A part (e.g. a position of a transaction) names its head as part_of. Without node the search covers everything under Model. limit 1–500 (default 50), offset.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'node'    => $id,
                    'subtree' => ['type' => 'boolean', 'default' => true],
                    'where'   => ['type' => 'array', 'items' => ['type' => 'object']],
                    'search'  => ['type' => 'string'],
                    'fields'  => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => RecordQuery::MAX_LIMIT, 'default' => RecordQuery::DEFAULT_LIMIT],
                    'offset'  => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->query(is_array($input) ? $input : [])),
            'permission_callback' => $this->allowedToRead(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        $ziel = ['type' => ['integer', 'string'], 'description' => __('A node id, or "@ref" of a node created earlier in this list.', 'taxmod')];

        wp_register_ability('taxmod/apply', [
            'label'               => __('Change the model', 'taxmod'),
            'description'         => __(
                'Applies a list of changes as one act (one change number, all or nothing). Ops: create_node, rename_node, trash_node, move_node, add_field, rename_field, remove_field, move_field, retarget_field, merge_field, create_record, set_values, remove_record, move_part, duplicate_record, set_kind, set_multiplicity, move_record, set_list, set_label, set_addons, set_setting. Fields and rules of one op: taxmod/help {op}. Any op may carry "ref"; later ops use "@ref" in place of an id. Values are typed as on the admin screen. brief: true answers with the count and the refs only. dry_run: true writes nothing and answers what each merge_field would move (records, both, duplicates); only merge_field.',
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
                                'op'          => ['type' => 'string', 'enum' => ['create_node', 'rename_node', 'trash_node', 'move_node', 'add_field', 'rename_field', 'remove_field', 'move_field', 'merge_field', 'retarget_field', 'create_record', 'set_values', 'remove_record', 'move_record', 'move_part', 'duplicate_record', 'set_kind', 'set_multiplicity', 'set_list', 'set_setting', 'set_label', 'set_addons']],
                                'ref'         => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]+$'],
                                'name'        => ['type' => 'string'],
                                'parent'      => $ziel,
                                'node'        => $ziel,
                                'target'      => $ziel,
                                'record'      => $ziel,
                                'part'        => $ziel,
                                'anywhere'    => ['type' => 'boolean'],
                                'drop'        => ['type' => 'boolean'],
                                'field'       => $ziel,
                                'to'          => $ziel,
                                'into'        => $ziel,
                                'under'       => $ziel,
                                'first'       => ['type' => 'boolean'],
                                'class'       => ['type' => 'string'],
                                'kind'        => ['type' => 'string', 'enum' => array_map(static fn (RelationKind $k): string => $k->value, RelationKind::cases())],
                                'multiplicity' => ['type' => 'string', 'enum' => array_map(static fn (Multiplicity $m): string => $m->value, Multiplicity::cases())],
                                'record_type' => ['type' => 'string', 'enum' => array_map(static fn (RecordType $t): string => $t->value, RecordType::cases())],
                                'values'      => ['type' => 'object'],
                                'own_value'   => ['type' => 'string'],
                                'locale'      => ['type' => 'string'],
                                'attribute'   => ['type' => 'string', 'pattern' => '^[a-z_]+$'],
                                'members'     => ['type' => 'array', 'items' => $ziel],
                                'value'       => ['type' => 'string'],
                                'role'        => ['type' => 'string', 'enum' => array_map(static fn (SeededRole $r): string => $r->value, SeededRole::cases())],
                                'text'        => ['type' => 'string'],
                                'addons'      => ['type' => 'array', 'items' => ['type' => 'object']],
                            ],
                            'required'   => ['op'],
                        ],
                    ],
                    'backup'  => ['type' => 'boolean', 'default' => false, 'description' => __('Save the whole model to uploads/taxmod-backups first.', 'taxmod')],
                    'brief'   => ['type' => 'boolean', 'default' => false],
                    'dry_run' => ['type' => 'boolean', 'default' => false],
                ],
                'required'   => ['changes'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->apply(is_array($input) ? $input : [])),
            'permission_callback' => $this->allowed(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);

        wp_register_ability('taxmod/help', [
            'label'               => __('Explain an op of apply', 'taxmod'),
            'description'         => __('What one op of taxmod/apply takes and refuses; without op, all of them.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => ['op' => ['type' => 'string', 'enum' => array_keys(self::OP_HELP)]],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->help(is_array($input) ? (string) ($input['op'] ?? '') : '')),
            'permission_callback' => $this->allowedToRead(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        // ⚠️ **Daten schreiben, ohne das Modell ändern zu dürfen** ([D-936](../../docs/NewConcept/90-decision-log.md)) — *für den
        // Importer der Bestellmails. Derselbe Weg wie `apply`, nur mit drei Ops und nur an Benutzersätzen.*
        wp_register_ability('taxmod/write-data', [
            'label'               => __('Write records', 'taxmod'),
            'description'         => __(
                'Like taxmod/apply, but only for data: create_record, set_values, remove_record, move_record, move_part, duplicate_record on user records. Nodes, fields and settings cannot be changed here. One change number, all or nothing; "ref" and "@ref" work as in apply.',
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
                                'op'        => ['type' => 'string', 'enum' => self::DATA_OPS],
                                'ref'       => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]+$'],
                                'node'      => $ziel,
                                'record'    => $ziel,
                                'part'      => $ziel,
                                'to'        => $ziel,
                                'field'     => $ziel,
                                'anywhere'  => ['type' => 'boolean'],
                                'drop'      => ['type' => 'boolean'],
                                'values'    => ['type' => 'object'],
                                'own_value' => ['type' => 'string'],
                                'locale'    => ['type' => 'string'],
                            ],
                            'required'   => ['op'],
                            'additionalProperties' => false,
                        ],
                    ],
                    // ⚠️ *D-1148: the versions the caller read — a record that moved on since refuses the whole list (`taxmod_conflict`).*
                    'expect'  => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => ['record' => ['type' => 'integer', 'minimum' => 1], 'version' => ['type' => 'integer', 'minimum' => 1]],
                            'required'   => ['record', 'version'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required'   => ['changes'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->apply(is_array($input) ? ['changes' => $input['changes'] ?? null, 'expect' => $input['expect'] ?? null] : [], true)),
            'permission_callback' => $this->allowedToWriteData(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);

        // *D-1288: before a record is removed, who points at it — a pointer to the record itself survives the removal (D-1270).*
        wp_register_ability('taxmod/referrers', [
            'label'               => __('List referrers', 'taxmod'),
            'description'         => __('For each given record id, every record that points at it: the pointing record, its node and the field (relation id). Read this before remove_record: a pointer to the record itself is not cleared by the removal.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => ['records' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'minItems' => 1, 'maxItems' => 500]],
                'required'   => ['records'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->referrers(array_values(array_unique(array_map('intval', is_array($input['records'] ?? null) ? $input['records'] : []))))),
            'permission_callback' => $this->allowedToRead(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        // *Vor und nach jedem Umbau an einem Renderer: welche Beiträge zeigen welchen Satz ([D-1052](../../docs/NewConcept/90-decision-log.md)).*
        wp_register_ability('taxmod/block-usage', [
            'label'               => __('List record block usage', 'taxmod'),
            'description'         => __('Every taxmod/record block in posts and pages of any status: post id, title, status, type, display, node, record, records, field, via and row fields; also as CSV.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->blockUsage()),
            'permission_callback' => $this->allowed(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    /**
     * @param  list<int> $ids
     * @return array{referrers: array<int, list<array{record: int, node: int, field: int}>>}
     */
    private function referrers(array $ids): array
    {
        $repository = new WpdbRecordRepository();
        $found      = $ids === [] ? [] : $repository->referrersOfMany($ids);
        $holders    = [];

        foreach ($found as $values) {
            foreach ($values as $value) {
                $holders[$value->recordId] = true;
            }
        }

        $nodes = [];

        foreach ($holders === [] ? [] : $repository->byIds(array_keys($holders)) as $id => $record) {
            $nodes[(int) $id] = (int) $record->nodeId;
        }

        $out = [];

        foreach ($ids as $id) {
            $out[$id] = [];

            foreach ($found[$id] ?? [] as $value) {
                $out[$id][] = ['record' => $value->recordId, 'node' => $nodes[$value->recordId] ?? 0, 'field' => $value->relationId];
            }
        }

        return ['referrers' => $out];
    }

    /** @return array{count: int, rows: list<array<string, int|string>>, csv: string} */
    private function blockUsage(): array
    {
        global $wpdb;

        $usage = new BlockUsage();
        $posts = [];

        foreach (Query::rows('Beiträge mit Satz-Block lesen', $wpdb->prepare(
            "SELECT ID, post_title, post_status, post_type, post_content FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_content LIKE %s ORDER BY ID",
            '%' . $wpdb->esc_like('<!-- wp:' . BlockUsage::BLOCK) . '%'
        )) as $post) {
            $posts[] = [
                'id'     => (int) $post['ID'],
                'title'  => (string) $post['post_title'],
                'status' => (string) $post['post_status'],
                'type'   => (string) $post['post_type'],
                'blocks' => parse_blocks((string) $post['post_content']),
            ];
        }

        $rows    = $usage->rows($posts);
        $missing = $usage->recordsWithoutNode($rows);
        $nodes   = [];

        foreach ($missing === [] ? [] : (new WpdbRecordRepository())->byIds($missing) as $id => $record) {
            $nodes[(int) $id] = (int) $record->nodeId;
        }

        $rows = $usage->withNodes($rows, $nodes);

        return ['count' => count($rows), 'rows' => $rows, 'csv' => $usage->csv($rows)];
    }

    public function allowed(mixed $input = null): bool
    {
        return current_user_can(Plugin::CAPABILITY);
    }

    public function allowedToWriteData(mixed $input = null): bool
    {
        return $this->allowed() || current_user_can(self::DATA_CAPABILITY);
    }

    /** Wer Sätze schreiben darf, muss sie finden können — sonst legt ein Importer jede Bestellung doppelt an. */
    public function allowedToRead(mixed $input = null): bool
    {
        return $this->allowedToWriteData();
    }

    /**
     * Die Rolle steht, sobald das Plugin läuft — eine Option, einmal je Anfrage gelesen, geschrieben nur beim ersten Mal.
     *
     * ⚠️ *Nur `read` dazu: die Rolle wird **neben** der des Benutzers vergeben (bei `auto_einkauf` Redakteur) und gibt ihm
     * nichts im Modell ausser dem Schreiben von Sätzen.*
     */
    public static function ensureDataRole(): void
    {
        if (get_role(self::DATA_ROLE) === null) {
            add_role(self::DATA_ROLE, __('Taxonomy Modeller: write records', 'taxmod'), ['read' => true, self::DATA_CAPABILITY => true]);
        }
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
            // ⚠️ *A conflict has its own code (D-1148), so a module can show the conflict dialog instead of a plain refusal.*
            $konflikt = $e instanceof ConcurrentChange ? $e : ($e->getPrevious() instanceof ConcurrentChange ? $e->getPrevious() : null);

            if ($konflikt !== null) {
                return new \WP_Error('taxmod_conflict', $konflikt->getMessage(), ['records' => $konflikt->records]);
            }

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
        $name      = mb_strtolower(trim((string) ($input['name'] ?? '')));
        $klasse    = trim((string) ($input['class'] ?? ''));
        // ⚠️ *A name search looks through the whole branch unless the caller limits it — the point is not to page through levels (D-1210).*
        $depth     = max(1, min(self::MAX_TREE_DEPTH, (int) ($input['depth'] ?? ($name !== '' ? self::MAX_TREE_DEPTH : 3))));
        $compact   = ! empty($input['compact']);
        $tree      = new Tree(new Persistence\WpdbNodeRepository());
        $rows      = [];

        foreach ($tree->rowsUnder($root, [$framework->trash()->id], [], true, true) as $row) {
            if ($row['depth'] > $depth) {
                continue;
            }

            $zeile = $this->nodeRow($row['node']) + ['depth' => $row['depth'], 'has_children' => $row['hasChildren']];

            if (($name !== '' && ! str_contains(mb_strtolower((string) $zeile['name']), $name)) || ($klasse !== '' && $zeile['class'] !== $klasse)) {
                continue;
            }

            $rows[] = $compact ? [$zeile['id'], $zeile['name'], $zeile['parent'], $zeile['depth'], $zeile['has_children']] : $zeile;
        }

        return $compact
            ? ['root' => $root->id, 'columns' => ['id', 'name', 'parent', 'depth', 'has_children'], 'rows' => $rows]
            : ['root' => $root->id, 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    /**
     * @param array<string, mixed> $input `records`, `limit`, `offset`, `fields`, `compact` — each one only shortens the answer (D-1210).
     */
    private function readNode(int $id, array $input = []): array
    {
        $node    = $this->node($id);
        $screen  = $this->screen();
        $entries = $screen->entries();
        $felder  = [];

        $nur     = array_map('intval', is_array($input['fields'] ?? null) ? $input['fields'] : []);
        $compact = ! empty($input['compact']);

        foreach ($screen->fieldsWithTypes($id) as ['relation' => $r, 'type' => $type, 'addons' => $addons]) {
            if ($nur !== [] && ! in_array($r->id, $nur, true)) {
                continue;
            }

            $felder[] = [
                'relation_id'  => $r->id,
                'name'         => $r->name,
                'target'       => $r->toNodeId,
                'declared_at'  => $r->fromNodeId,
                'kind'         => $r->kind->value,
                'multiplicity' => $r->multiplicity->value,
                'type'         => $type?->value,
            ] + ($addons === [] ? [] : ['addons' => $addons])
              // D-1288: a computed field says what it is made of here, or a write to it is silently overwritten.
              + (($aus = $screen->composedOf($r, $id)) === [] ? [] : ['composed_of' => $aus]);
        }

        $records = $entries->recordsOf($id);
        $gesamt  = count($records);
        $seite   = [];

        if (($input['records'] ?? true) !== false) {
            $limit = isset($input['limit']) ? max(1, min(self::MAX_RECORDS_PAGE, (int) $input['limit'])) : null;
            $seite = array_slice($records, max(0, (int) ($input['offset'] ?? 0)), $limit);
        }

        $werte   = $seite === [] ? [] : $entries->valuesOfMany(array_map(static fn ($r): int => $r->id, $seite));
        // D-1255: which of them still need a review, in one query.
        $pruefen = array_flip(\Taxmod\WordPress\Persistence\RecordReviews::among(array_map(static fn ($r): int => $r->id, $seite)));
        $neutral = $compact ? SettingsScreen::neutralLocale() : '';
        $saetze  = [];

        foreach ($seite as $record) {
            $zeilen = [];

            foreach ($werte[$record->id] ?? [] as $wert) {
                if ($nur !== [] && ! in_array($wert->relationId, $nur, true)) {
                    continue;
                }

                if ($compact) {
                    $schluessel = (string) $wert->relationId . ($wert->locale !== '' && $wert->locale !== $neutral ? '@' . $wert->locale : '');
                    $roh        = $wert->value->rawValue();
                    $zeilen[$schluessel] = array_key_exists($schluessel, $zeilen) ? [...(array) $zeilen[$schluessel], $roh] : $roh;
                    continue;
                }

                $zeilen[] = [
                    'relation_id' => $wert->relationId,
                    'locale'      => $wert->locale,
                    'type'        => $wert->value->typeName(),
                    'value'       => $wert->value->rawValue(),
                ];
            }

            $review   = isset($pruefen[$record->id]) ? ['needs_review' => true] : [];
            $saetze[] = $compact
                ? ['id' => $record->id, 'version' => $record->version] + ($record->recordType === RecordType::User ? [] : ['record_type' => $record->recordType->value]) + $review + ['v' => (object) $zeilen]
                : ['id' => $record->id, 'version' => $record->version, 'record_type' => $record->recordType->value] + $review + ['values' => $zeilen];
        }

        return [
            'node'          => $this->nodeRow($node),
            'children'      => array_map($this->nodeRow(...), $this->editor()->childrenOf($id)),
            'fields'        => $felder,
            'records'       => $saetze,
            'total_records' => $gesamt,
        ];
    }

    /**
     * The records a question needs and only the fields it names (D-1209): conditions in one query, the words in one run.
     *
     * @param array<string, mixed> $input
     * @return array{total: int, offset: int, records: list<array{id: int, node: int, word: string, part_of?: int, values?: array<string, string>}>, unknown_fields?: list<string>}
     */
    private function query(array $input): array
    {
        $q         = RecordQuery::from($input);
        $framework = $this->framework();
        $start     = (int) ($input['node'] ?? 0) > 0 ? $this->node((int) $input['node']) : $framework->rootOf(Branch::Model);
        $nodes     = [$start->id];

        if ($q->subtree) {
            foreach ((new Tree(new Persistence\WpdbNodeRepository()))->rowsUnder($start, [$framework->trash()->id]) as $row) {
                $nodes[] = $row['node']->id;
            }
        }

        $records  = new WpdbRecordRepository();
        $screen   = $this->screen();
        $resolved = [];

        foreach ($q->conditions as $i => $c) {
            if ($c['op'] !== 'within') {
                continue;
            }

            $target = $this->node($this->targetOf($screen, $nodes, $c['field']));
            $places = [$target->id];
            $tree   = [];

            foreach ((new Tree(new Persistence\WpdbNodeRepository()))->rowsUnder($target, [$framework->trash()->id]) as $row) {
                $places[] = $row['node']->id;
            }

            foreach ($records->parentLinksOf($places, $c['parent_field']) as $record => $parent) {
                $tree[] = new RecordInTree($record, $parent, '');
            }

            $resolved[$i] = RecordQuery::within($tree, (int) $c['values'][0]);
        }

        $page  = $records->recordsWhere($nodes, $q->forTable($resolved), $q->limit, $q->offset);
        $ids   = $page['ids'];
        $words = $screen->recordWords($ids, $start->id);
        $at    = [];

        $parts = [];

        foreach ($ids === [] ? [] : $records->byIds($ids) as $id => $record) {
            $at[(int) $id] = (int) $record->nodeId;

            if ($record->relationId !== 0) {
                $parts[(int) $id] = $record->relationId;
            }
        }

        // *A part names its head, so «which transaction is this position of» needs no second call.*
        $heads = [];

        foreach ($parts === [] ? [] : $records->holdersOf(array_keys($parts)) as $id => $holder) {
            if ($holder->relationId === $parts[(int) $id]) {
                $heads[(int) $id] = $holder->recordId;
            }
        }

        $values  = [];
        $unknown = [];

        if ($q->fields !== [] && $ids !== []) {
            $felder = [];

            foreach (array_values(array_unique($at)) as $node) {
                foreach ($screen->fieldsWithTypes($node) as ['relation' => $r]) {
                    $felder[$r->id] ??= $r;
                }
            }

            $chosen = [];

            foreach ($q->fields as $asked) {
                $found = null;

                foreach ($felder as $r) {
                    if ($r->id === $asked || (is_string($asked) && mb_strtolower(trim($r->name)) === mb_strtolower($asked))) {
                        $found = $r;
                        break;
                    }
                }

                if ($found === null) {
                    $unknown[] = (string) $asked;
                } else {
                    $chosen[$found->id] = $found;
                }
            }

            foreach ($chosen === [] ? [] : $screen->fieldTexts($ids, array_values($chosen)) as $feld => $texts) {
                foreach ($texts as $record => $text) {
                    if ($text !== '') {
                        $values[(int) $record][$chosen[$feld]->name] = $text;
                    }
                }
            }
        }

        $rows = [];

        foreach ($ids as $id) {
            $row = ['id' => $id, 'node' => $at[$id] ?? 0, 'word' => trim((string) ($words[$id] ?? ''))] + (isset($heads[$id]) ? ['part_of' => $heads[$id]] : []);

            if ($q->fields !== []) {
                $row['values'] = $values[$id] ?? [];
            }

            $rows[] = $row;
        }

        return ['total' => $page['total'], 'offset' => $q->offset, 'records' => $rows] + ($unknown === [] ? [] : ['unknown_fields' => $unknown]);
    }

    /**
     * The node a reference field points at, from the fields of the nodes asked — `within` walks that node's records.
     *
     * @param list<int> $nodes
     */
    private function targetOf(NodesScreen $screen, array $nodes, int $field): int
    {
        foreach ($nodes as $node) {
            foreach ($screen->fieldsWithTypes($node) as ['relation' => $r]) {
                if ($r->id === $field) {
                    return $r->toNodeId;
                }
            }
        }

        throw new \InvalidArgumentException(sprintf('within: field %d is not a field of node %d or below it.', $field, $nodes[0] ?? 0));
    }

    /**
     * Eine Änderungsliste aus einer hochgeladenen Datei ([D-973](../../docs/NewConcept/90-decision-log.md)) — derselbe Weg wie
     * `apply`, eine Handlung je Datei.
     *
     * ⚠️ *Die Grenze je Aufruf gilt hier nicht: sie schützt die Anfrage über das Netz, und gerade dort brach eine grosse Liste
     * am Webserver ab (HTTP 400 ab etwa 40 KB). Die Datei liegt schon auf dem Server.*
     *
     * @param list<mixed>        $changes
     * @param array<string, int> $refs Was frühere Dateien desselben Pakets angelegt haben — «@ref» gilt über die Datei hinaus.
     * @return array{applied: list<array{op: string, id: int|null}>, refs: array<string, int>, backup: string|null}
     */
    public function applyChangeList(array $changes, array $refs): array
    {
        return $this->apply(['changes' => $changes], false, $refs, PHP_INT_MAX);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, int>   $refs
     * @return array<string, mixed>
     */
    private function apply(array $input, bool $onlyData = false, array $refs = [], int $limit = self::MAX_CHANGES): array
    {
        $changes = is_array($input['changes'] ?? null) ? array_values($input['changes']) : [];

        if ($changes === [] || count($changes) > $limit) {
            throw new \InvalidArgumentException(sprintf('Between 1 and %d changes per call.', $limit));
        }

        // ⚠️ *Der Trockenlauf vor einem Umbau (D-1205): dieselben Prüfungen, kein Schreiben, nur die Zahlen. Nur für merge_field, weil nur
        // dort die Zahl gefragt war; jede andere Op würde stillschweigend «geprüft» heissen, ohne geprüft zu sein.*
        if (! empty($input['dry_run'])) {
            return ['dry_run' => array_map(fn (mixed $change): array => $this->mergePreview(is_array($change) ? $change : [], $refs), $changes)];
        }

        $sicherung = ! $onlyData && ! empty($input['backup']) ? (new Backup())->safetyCopy('vor-ability') : null;
        $ergebnis  = [];
        $changelog = ($this->changelog)();

        $changelog->beginAct();
        $erwartet = RecordStamp::fromList(is_array($input['expect'] ?? null) ? array_values(array_filter($input['expect'], 'is_array')) : []);

        try {
            Transaction::whole('taxmod_apply', function () use ($erwartet, $changes, $onlyData, &$refs, &$ergebnis): void {
                // ⚠️ *First, inside the transaction, so the rows stay held to the commit (D-1148).*
                $this->screen()->entries()->claim($erwartet);

                foreach ($changes as $stelle => $change) {
                    try {
                        if ($onlyData) {
                            $this->refuseUnlessData(is_array($change) ? $change : [], $refs);
                        }

                        $id = $this->applyOne(is_array($change) ? $change : [], $refs);
                    } catch (\Throwable $e) {
                        throw new \RuntimeException(sprintf('Change %d (%s) refused, nothing was written: %s', $stelle, (string) ($change['op'] ?? '?'), $e->getMessage()), 0, $e);
                    }

                    if (isset($change['ref']) && $id !== null) {
                        $refs[(string) $change['ref']] = $id;
                    }

                    $ergebnis[] = ['op' => (string) $change['op'], 'id' => $id];
                }
            });
        } finally {
            $changelog->endAct();
        }

        $beruehrt = array_values(array_unique([...$erwartet->ids(), ...array_filter(array_column($ergebnis, 'id'), 'is_int')]));

        // ⚠️ *The versions after the write, for the records the caller named or touched — its next change list can expect them (D-1148).*
        $versionen = $onlyData ? $this->screen()->entries()->stampOf($beruehrt)->versions : [];

        // ⚠️ *brief: the caller already holds its list; echoing every op back is tokens for nothing (D-1210).*
        return ! empty($input['brief'])
            ? ['count' => count($ergebnis), 'refs' => $refs, 'backup' => $sicherung, 'versions' => $versionen]
            : ['applied' => $ergebnis, 'refs' => $refs, 'backup' => $sicherung, 'versions' => $versionen];
    }

    /**
     * @param array<string, mixed> $change
     * @param array<string, int>   $refs
     * @return array{op: string, records: int, both: int, duplicates: int}
     */
    private function mergePreview(array $change, array $refs): array
    {
        if ((string) ($change['op'] ?? '') !== 'merge_field') {
            throw new \InvalidArgumentException('dry_run is only for merge_field.');
        }

        $at = fn (string $key): int => $this->resolve($change[$key] ?? null, $refs, $key);

        return ['op' => 'merge_field'] + $this->editor()->mergeFieldPreview(
            $at('node'),
            $at('field'),
            $at('into'),
            isset($change['under']) ? $this->node($at('under'))->id : null,
            ! empty($change['first'])
        );
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

            case 'move_node':
                // ⚠️ *Built so a currency can hang under «Base units» without a hand in the admin (D-928).*
                return $editor->move($at('node'), $this->node($at('parent'))->id)->id;

            case 'add_field':
                $target = $this->node($at('target'));
                $kind   = isset($change['kind']) ? RelationKind::from((string) $change['kind']) : null;

                $field  = $editor->addField($at('node'), $target->id, $name === '' ? $target->name : $name, $kind);

                // ⚠️ *Ohne Angabe gilt die Vorgabe der Maske (1..1); eine Liste wie «Bauteile» braucht 0..* (D-914).*
                return isset($change['multiplicity'])
                    ? $editor->setMultiplicity($field->fromNodeId, $field->id, Multiplicity::from((string) $change['multiplicity']))->id
                    : $field->id;

            case 'set_multiplicity':
                // ⚠️ *Gebaut für D-1171: «Bezeichnung» an «Alternative Bezeichnung» wird 0..1, wenn der Eintrag einen Satz wählt.*
                return $editor->setMultiplicity($at('node'), $at('field'), Multiplicity::from((string) ($change['multiplicity'] ?? '')))->id;

            case 'rename_field':
                return $editor->renameField($at('node'), $at('field'), $name === '' ? throw new \InvalidArgumentException('rename_field needs a name.') : $name)->id;

            case 'remove_field':
                return $editor->removeField($at('node'), $at('field'))->id;

            case 'move_field':
                // ⚠️ *Gebaut für den Umbau nach D-940: das Feld wandert, die Werte bleiben an ihrer Kante (D-941).*
                return $editor->moveFieldTo($at('node'), $at('field'), $this->node($at('to'))->id)->id;

            case 'retarget_field':
                return $editor->retargetField($at('node'), $at('field'), $this->node($at('target'))->id, true)->id;

            case 'merge_field':
                return $editor->mergeField($at('node'), $at('field'), $at('into'), isset($change['under']) ? $this->node($at('under'))->id : null, ! empty($change['first']))->id;

            case 'create_record':
                $type = isset($change['record_type']) ? RecordType::from((string) $change['record_type']) : RecordType::User;

                $created = $screen->entries()->create($this->node($at('node'))->id, $type)->id;

                // D-1276: values sent with create_record were ignored without a word, though the call said «applied» — now they
                // are written in the same act, as a set_values right after would.
                if (is_array($change['values'] ?? null) && $change['values'] !== []) {
                    $this->applyOne(['op' => 'set_values', 'record' => $created] + array_intersect_key($change, array_flip(['values', 'own_value', 'locale'])), $refs);
                }

                return $created;

            case 'set_values':
                $recordId = $at('record');
                $record   = $screen->entries()->find($recordId) ?? throw new \InvalidArgumentException("No record {$recordId}.");
                $values   = [];

                // ⚠️ *Ein Feld aus derselben Liste heisst hier «@ref» — erst so lässt sich eine Initiative in einem Aufruf anlegen.*
                foreach (is_array($change['values'] ?? null) ? $change['values'] : [] as $kante => $wert) {
                    $values[$this->resolve($kante, $refs, 'values')] = $this->resolveValues($wert, $refs);
                }
                $values   = $this->intoHeldParts($recordId, $record->nodeId, $values);
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

            case 'move_part':
                // ⚠️ *D-1098, his word «f2 wecheln erlauben»: the part is re-hung, not created anew — its id and every reference to it stay.*
                $partId = $at('part');
                $screen->entries()->movePart($partId, $at('to'), $at('field'));

                return $partId;

            case 'duplicate_record':
                // ⚠️ *D-1135, his word «die meisten objekt in tax sollten kopierbarsein»: the same copy as the record's button (D-1012)
                // — every value, an own copy of each part; the id answers «ref», so the list can change the copy in the same act.*
                return $screen->entries()->duplicate($at('record'))->id;

            case 'set_kind':
                // ⚠️ *D-1097: «Positionen» become compositions without moving a value — both kinds store alike (D-587). Only the two
                // kinds a person can choose; «setting» is not one (D-715).*
                $kind = RelationKind::tryFrom((string) ($change['kind'] ?? ''));

                if ($kind !== RelationKind::Aggregation && $kind !== RelationKind::Composition) {
                    throw new \InvalidArgumentException('set_kind needs kind aggregation or composition.');
                }

                return $editor->setKind($at('node'), $at('field'), $kind)->id;

            case 'move_record':
                // ⚠️ *Built to merge the two «Mikrocontroller» nodes: his choice on 2026-10-31, «Zusammenführen» (D-1057).*
                // D-1073: «anywhere» moves within the branch in one step, «drop» lets announced values fall.
                return $screen->moveRecordKeepingValues($at('record'), $this->node($at('node'))->id, ! empty($change['anywhere']), ! empty($change['drop']))->id;

            case 'set_list':
                // ⚠️ *Gebaut, weil die Feldwahl der Benchmarks sonst nur in der Maske zu setzen war (D-923, INF-068).*
                $nodeId  = $this->node($at('node'))->id;
                $members = array_map(fn (mixed $m): int => $this->resolve($m, $refs, 'members'), is_array($change['members'] ?? null) ? $change['members'] : []);

                $screen->setListMembers($nodeId, sanitize_key((string) ($change['attribute'] ?? '')), $members, isset($change['field']) ? $at('field') : null);

                return $nodeId;

            case 'set_setting':
                // ⚠️ *Gebaut, weil «name_as_link_text» und «with_label» an den Quellen sonst nur in der Maske zu setzen waren (D-999).*
                $nodeId = $this->node($at('node'))->id;

                $screen->setSetting($nodeId, sanitize_key((string) ($change['attribute'] ?? '')), (string) ($change['value'] ?? ''), isset($change['field']) ? $at('field') : null);

                return $nodeId;

            case 'set_addons':
                // ⚠️ *Gebaut für die gerechneten Felder (D-1022): die Rechenschritte sind Zusatzfunktionen an der Kante, und die gab es
                // bisher nur in der Maske.*
                $nodeId = $this->node($at('node'))->id;
                $addons = [];

                foreach (is_array($change['addons'] ?? null) ? $change['addons'] : [] as $eintrag) {
                    $felder = $this->resolveValues(is_array($eintrag['fields'] ?? null) ? $eintrag['fields'] : [], $refs);
                    $addons[] = [
                        'name'   => sanitize_key((string) ($eintrag['name'] ?? '')),
                        'fields' => array_map(static fn (mixed $v): string => sanitize_text_field((string) $v), array_combine(array_map(static fn (mixed $k): string => sanitize_key((string) $k), array_keys($felder)), array_values($felder))),
                    ];
                }

                $screen->setAddons($nodeId, $addons, isset($change['field']) ? $at('field') : null);

                return $nodeId;

            case 'set_label':
                // ⚠️ *Gebaut für die Hilfe an den Bauformen: sein Wort am 2026-10-29, «die tht unterschiede kenne ich nicht … kannst das
                // mal anpassen so das man das beim auswählen sieht». Eine Beschriftung des Knotens wie in der Maske; leer entfernt sie.*
                $nodeId = $this->node($at('node'))->id;
                $role   = SeededRole::tryFrom((string) ($change['role'] ?? '')) ?? throw new \InvalidArgumentException('set_label needs a role.');
                // *Ohne Sprache die Standardsprache: die Hilfe fällt nur auf sie zurück, nie auf eine sprachneutrale Zeile (D-645).*
                $locale = (string) ($change['locale'] ?? '') === '' ? SettingsScreen::neutralLocale() : (string) $change['locale'];
                $label  = new Label($nodeId, IdentitySpace::Node, $role, Label::BASE_NUMBER, $locale, sanitize_textarea_field((string) ($change['text'] ?? '')));
                // *Über den Dienst, damit die Änderung im Änderungsbuch steht und sich zurücknehmen lässt (D-1010).*
                $labels = new \Taxmod\Core\Service\Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale(), ($this->changelog)());

                $label->text === '' ? $labels->forget($label) : $labels->put($label);

                return $nodeId;
        }

        throw new \InvalidArgumentException('Unknown op.');
    }

    /**
     * Was über `write-data` kommt, darf nur Benutzersätze berühren — eine Einstellung ist ein Satz, aber Teil des Modells (D-936).
     *
     * @param array<string, mixed> $change
     * @param array<string, int>   $refs
     */
    private function refuseUnlessData(array $change, array $refs): void
    {
        $op = (string) ($change['op'] ?? '');

        if (! in_array($op, self::DATA_OPS, true)) {
            throw new \InvalidArgumentException("{$op} changes the model; only create_record, set_values, remove_record, move_record, move_part and duplicate_record are allowed here.");
        }

        if (isset($change['record_type']) && (string) $change['record_type'] !== RecordType::User->value) {
            throw new \InvalidArgumentException('Only user records can be written here.');
        }

        // *move_part touches two records, the part and its new head; both must be user records.*
        foreach ($op === 'create_record' ? [] : ($op === 'move_part' ? ['part', 'to'] : ['record']) as $key) {
            $recordId = $this->resolve($change[$key] ?? null, $refs, $key);
            $record   = $this->screen()->entries()->find($recordId) ?? throw new \InvalidArgumentException("No record {$recordId}.");

            if ($record->recordType !== RecordType::User) {
                throw new \InvalidArgumentException("Record {$recordId} is a {$record->recordType->value} record; only user records can be written here.");
            }
        }
    }

    /**
     * Teil «0» heisst hier: der Teil, den der Satz an diesem Feld schon hält — sonst ein neuer.
     *
     * ⚠️ *Ein neuer Satz bekommt für jedes Pflichtfeld aus Teilen gleich einen leeren Teil (die Vorbelegung beim ersten Schreiben).
     * Die Maske zeigt dessen Id und schreibt hinein; ein Aufrufer von aussen kennt sie nicht. Ohne diese Abbildung stünde neben dem
     * leeren Teil ein zweiter, und die Zusammenfassung läse den leeren.*
     *
     * ⚠️ **An einem Feld mit höchstens einem Wert ist auch ein neuer Teil («neu») der gehaltene** — *gemessen am 2026-10-23: 41
     * Bauteile aus dem Reichelt-Wareneingang trugen an Toleranz, Widerstandswert und Leistung je zwei Teile, den leeren vorbelegten
     * und den geschriebenen, und ihre Bezeichnung las «#43780 · #43779 · 0207». Ein leerer gehaltener Teil wird gefüllt; hält er schon
     * Werte, wird abgelehnt, statt einen zweiten anzulegen.*
     *
     * @param array<int, mixed> $values
     * @return array<int, mixed>
     */
    private function intoHeldParts(int $recordId, int $nodeId, array $values): array
    {
        $entries  = $this->screen()->entries();
        $gehalten = [];

        foreach ($entries->valuesOf($recordId) as $zeile) {
            if ($zeile->value->referenceSpace === \Taxmod\Core\Model\ReferenceSpace::Record && $zeile->value->reference !== null) {
                $gehalten[$zeile->relationId] ??= $zeile->value->reference;
            }
        }

        $einzeln = [];

        foreach ($this->screen()->fieldsWithTypes($nodeId) as ['relation' => $r]) {
            if (! $r->multiplicity->allowsMany()) {
                $einzeln[$r->id] = $r->name;
            }
        }

        foreach ($values as $kante => $teile) {
            if (! is_array($teile) || isset($teile['values']) || ! isset($gehalten[$kante])) {
                continue;
            }

            if (array_key_exists(0, $teile)) {
                $teile[$gehalten[$kante]] = $teile[0];
                unset($teile[0]);
            }

            if (isset($einzeln[$kante])) {
                foreach (array_keys($teile) as $schluessel) {
                    if (is_int($schluessel) || ctype_digit((string) $schluessel)) {
                        continue;
                    }

                    $leer = true;

                    foreach ($entries->valuesOf($gehalten[$kante]) as $zeile) {
                        $leer = $leer && $zeile->value->isNothing();
                    }

                    if (! $leer) {
                        throw new \InvalidArgumentException(sprintf('«%s» takes one part and record %d already holds part %d with values; write it with part key "0" instead of "%s".', $einzeln[$kante], $recordId, $gehalten[$kante], $schluessel));
                    }

                    $teile[$gehalten[$kante]] = $teile[$schluessel];
                    unset($teile[$schluessel]);
                }
            }

            $values[$kante] = $teile;
        }

        return $values;
    }

    /**
     * Ein Wert «@ref» wird die Id des früher Angelegten — so zeigt eine Position auf ein Bauteil aus derselben Liste.
     *
     * @param array<string, int> $refs
     */
    private function resolveValues(mixed $wert, array $refs): mixed
    {
        if (is_array($wert)) {
            $aus = [];

            // ⚠️ *Auch die Schlüssel: in einem Teil heisst das innere Feld «@betrag», wenn es in derselben Liste angelegt wurde.
            // Bis 0.1.11 blieb es stehen, und der Teil wurde leer angelegt.*
            foreach ($wert as $schluessel => $inner) {
                $schluessel       = is_string($schluessel) && preg_match('/^@[A-Za-z0-9_-]+$/', $schluessel) === 1 ? $this->resolve($schluessel, $refs, 'values') : $schluessel;
                $aus[$schluessel] = $this->resolveValues($inner, $refs);
            }

            return $aus;
        }

        return is_string($wert) && preg_match('/^@[A-Za-z0-9_-]+$/', $wert) === 1 ? (string) $this->resolve($wert, $refs, 'values') : $wert;
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

    /** @return array<string, string> op => what it takes and refuses */
    private function help(string $op): array
    {
        if ($op === '') {
            return self::OP_HELP;
        }

        return [$op => self::OP_HELP[$op] ?? throw new \InvalidArgumentException(sprintf('No op %s.', $op))];
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
