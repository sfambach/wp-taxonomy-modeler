<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Model\CatalogueRole;

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
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Service\BlockUsage;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\RecordQuery;
use Taxmod\Core\Service\SimilarFields;
use Taxmod\Core\Service\Tree;
use Taxmod\Core\Repository\Changelog;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Admin\SettingsScreen;
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
        'set_class' => '{node, class} — switches the node to another class, as the page does; refused unless the parent allows it and it allows every child; settings the new class does not declare fall (D-733)',
        'trash_node' => '{node}',
        'trash_unused_constant' => '{node} — a value of a choice list into the trash, one act; refused if it has children, is a field target, is a framework node, or any record or setting still chooses it (then merge_constant) (D-1474)',
        'move_node' => '{node, parent} — hangs the node and its subtree under another parent',
        'add_field' => '{node, target, name?, kind?, multiplicity?}',
        'rename_field' => '{node, field, name}',
        'remove_field' => '{node, field} — parks a field the node owns, with its values',
        'move_field' => '{node, field, to} — declares the field at another node, values stay; refused if a record with values would end up outside it',
        'retarget_field' => '{node, field, target, drop_values?} — points the field at another node and keeps its kind; refused while stored values would not fit the new target (a plain value on a part field or the reverse), unless drop_values: true removes them (D-1371)',
        'drop_unfit_values' => '{node, field} — removes the stored values of the field that do not fit its target, e.g. numbers left on a field that now holds unit-value parts (D-1371)',
        'make_variants' => '{records} — records of one node become the variants of a new parent: the parent takes the values they share, each keeps its «Je Variante» values, id, stock and labels; refused naming field and records where they differ elsewhere, or where one already is or has a variant; the node needs a field with «Variante von»; one change number (D-1482)',
        'add_variant' => '{record, values?} — a new variant beside record (of its parent, of itself when it has variants, else record first becomes the only variant of a new parent); values only on «Je Variante» fields (D-1482)',
        'merge_record' => '{from, into} — a doubled record: every reference to from points at into, a reference that would then stand twice falls, from and the modules\' own records of it (Lager-Stammsatz) go to the trash; one change number. Refused unless both stand in the same node, where from holds a value into lacks or holds differently (fields named), where a module vetoes, or where a taxmod/record block in a post names from (post ids named). The answer names moved per field, dropped, trashed (D-1480)',
        'merge_constant' => '{from, into} — a doubled constant: every value (records and settings) that chose from chooses into, a value that would then stand twice in the same field falls, from goes to the trash; one change number, so undo_act takes it back. Refused if from has children, is a field target, or a field holding it cannot choose into (retarget_field first) (D-1458)',
        'merge_field' => '{node, field, into, under?, first?} — moves the values of the field onto another field with the same target and parks it; refused if a record would end up outside «into» or already holds values there; with under only records below that node, and the field stays; with first the values go to the front of the other field\'s list, its own values move back and a value already there stands only once (D-1205)',
        'create_record' => '{node, record_type?, values?, locale?} — values as for set_values, written in the same act',
        'set_values' => '{record, values: {relation_id or @ref: text or @ref, or {values: [text, ...]} for a field taking several, or {part key: {inner relation_id: text}} for parts — key \"0\" fills the part the record already holds there, any other non-numeric key adds a new part}, own_value?, record_type?, locale?}',
        'undo_act' => '{act} — takes back change number act (the "act" of an earlier answer) as a new change: what it created is removed, what it changed or deleted gets its state from before, labels their text. Refused, naming the change, if another change wrote the same data since — take that back first; nothing is undone in part. Through write-data only a change that wrote records alone (D-1410)',
        'remove_record' => '{record} — removes the record with its own parts, nested ones included; refused while a record outside it references one of its parts, naming who',
        'reorder_values' => '{record, field, order: [record or node ids]} — stores the references the field holds in this order (e.g. pin rows by pin number); order must name exactly what the field holds, each once (D-1404)',
        'move_part' => '{part, to, field} — hangs a part held through field onto another record\'s list of the same field, keeping its id',
        'set_kind' => '{node, field, kind} — switches a field the node owns between aggregation and composition, the values stay',
        'set_multiplicity' => '{node, field, multiplicity} — sets how often a field the node owns may hold a value (1..1 makes it mandatory), the values stay',
        'move_record' => '{record, node, anywhere?, drop?} — moves a record to the parent or a child of its node (with anywhere: to any node of its branch) and rewrites its path, calculated and summary fields; refused if a value would be dropped, unless drop is true, and always if a field of another record that chooses it could not choose it at the new node',
        'set_list' => '{node, attribute, members: [relation or node ids]} — sets a list setting of the node itself (e.g. summary_fields) to exactly these members; with field {set_list, node: target of the field, field, attribute, members} only at that use site (e.g. erlaubte_einheiten of a unit-value field)',
        'set_label' => '{node, field?, role, text, locale?} — sets one label of the node (role help, select, form …) as the labels box would; with field the label belongs to that field of the node, e.g. its own help before the help of its target (D-1524); empty text removes it',
        'set_addons' => '{node, field?, addons: [{name, fields: {attribute: text or @ref}}]} — replaces the add-on functions of the node or, with field, of that use site (node then being the target of the field), in this order; name calculate with fields operation (plus, minus, times, divided_by) and field (a field of the record) makes the field a calculated one: read-only, every record recalculated (also when the steps are unchanged — sending them again recalculates); name metric on a node with fields title, measure (count, sum), value_field, date_field, filter_field (fields of the node), filter_value and months makes a key figure shown as a tile per month',
        'hide_field'  => '{node, field, hidden} — the eye at a field row: at the field\'s owner it hides the field everywhere, at any other node only there and below (D-1144); hidden true or false.',
        'set_confidential' => '{node, field, confidential} — «Vertraulich» for this node and below on one field (D-1578): true seals its values there, false keeps them readable; the nearer node wins, the edge\'s «Vertraulich» add-on holds where no node says anything. Needs the «confidential» setting at the root. Existing values follow with taxmod/field-seal (unseal true opens what no longer needs sealing).',
        'set_setting' => '{node, attribute, value, field?} — sets one plain setting (e.g. with_label, name_as_link_text) as the screen would; with field only at that use site, node then being the target of the field; an empty value removes it.',
        'duplicate_record' => '{record} — copies a record with its values and own parts next to itself.',
    ];

    /** So viele Sätze liefert eine Seite von `read-node` höchstens. */
    private const MAX_RECORDS_PAGE = 1000;

    /** So viele Felder liefert eine Seite von `fields` höchstens. */
    private const MAX_FIELDS_PAGE = 1000;

    /** Die Spalten einer kompakten Feldzeile — einmal genannt statt in jeder Zeile (D-1210). */
    private const FIELD_COLUMNS = ['id', 'name', 'node', 'node_name', 'target', 'target_name', 'type', 'kind', 'multiplicity', 'chooses_records', 'values', 'records', 'uses', 'settings', 'target_role'];

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
    // D-1482: making variants changes records only — the stock asks it of its own material list.
    private const DATA_OPS = ['create_record', 'set_values', 'remove_record', 'make_variants', 'add_variant', 'merge_record', 'move_record', 'move_part', 'reorder_values', 'duplicate_record', 'undo_act'];

    /** Whether the running change list came through `write-data` — an undo there may only take back record rows (D-1410). */
    private bool $onlyRecords = false;

    /** @var array{moved: array<int, int>, dropped: int, trashed: list<int>}|null what the last merge_record did, for its answer line (D-1480) */
    private ?array $merged = null;

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
            'description'         => __('One node with its children, its fields (relation id, name, target, kind, multiplicity, simple type), used_by (the fields that choose from this node: relation id, name, owning node; used_by false leaves it out) and its records with their values; total_records counts them all. Saves tokens: records false (structure only), limit and offset (a page of records), fields (only these relation ids), compact (values as {relation_id: value}, a list where a field holds several, "relation_id@locale" only off the neutral locale). A field computed at this node (e.g. Bezeichnung) names its parts in composed_of — its own id there stands for the node name; a value written to it is overwritten.', 'taxmod'),
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
                    'used_by' => ['type' => 'boolean', 'default' => true],
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
            'description'         => __('Records of a node (and its subtree unless subtree is false) that meet every condition, one page of them with the total. where: list of {field: relation id, op: eq | in | contains | empty | not_empty | within | fits, value or values}; eq and in compare the stored value (text, number, date YYYY-MM-DD, or the id a reference points at), contains a text ignoring case; within {field, value: record id, parent_field} matches a reference to that record or any record below it by its parent field (e.g. a storage place and its sub-places); fits {field, value: node id} on a field choosing a node matches that node, any node below it, and each ancestor of it up to the target of the field (e.g. «all computers» is found for XT). search: a word in any text value. fields: relation ids or names to answer with, as the words the screen shows; without fields each record comes with its own word only. A part (e.g. a position of a transaction) names its head as part_of. Without node the search covers everything under Model. limit 1–500 (default 50), offset.', 'taxmod'),
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
                'Applies a list of changes as one act (one change number, all or nothing). The answer names its change number as "act"; undo_act {act} takes a change back. Ops: create_node, rename_node, trash_node, trash_unused_constant, move_node, add_field, rename_field, remove_field, move_field, retarget_field, merge_field, create_record, set_values, remove_record, make_variants, add_variant, merge_record, move_part, reorder_values, duplicate_record, set_kind, set_multiplicity, move_record, set_list, set_label, set_addons, set_setting, hide_field, set_confidential. Fields and rules of one op: taxmod/help {op}. Any op may carry "ref"; later ops use "@ref" in place of an id. Values are typed as on the admin screen. brief: true answers with the count and the refs only. dry_run: true writes nothing: a list of only merge_field answers what each would move (records, both, duplicates); any other list is run whole and rolled back, answering fits, not_asked (module vetoes are not asked), applied, refs, reported and the first refusal.',
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
                                'op'          => ['type' => 'string', 'enum' => ['create_node', 'rename_node', 'set_class', 'trash_node', 'trash_unused_constant', 'move_node', 'add_field', 'rename_field', 'remove_field', 'move_field', 'merge_field', 'merge_constant', 'retarget_field', 'drop_unfit_values', 'create_record', 'set_values', 'remove_record', 'make_variants', 'add_variant', 'merge_record', 'move_record', 'move_part', 'reorder_values', 'duplicate_record', 'set_kind', 'set_multiplicity', 'set_list', 'set_setting', 'hide_field', 'set_confidential', 'set_label', 'set_addons', 'undo_act']],
                                'act'         => ['type' => 'integer', 'minimum' => 1],
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
                                'from'        => $ziel,
                                'into'        => $ziel,
                                'records'     => ['type' => 'array', 'items' => $ziel],
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
                    'backup'  => ['type' => 'boolean', 'default' => false, 'description' => __('No copy is made per change list; the answer names the nightly full copy (uploads/taxmod-backups, the newest seven kept) and when it was made.', 'taxmod')],
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

        // His word 2026-11-19: «dass du das mal mitloggst, vielleicht für jede Seite … dann musst du nicht messen».
        wp_register_ability('taxmod/page-times', [
            'label'               => __('Read the page times', 'taxmod'),
            'description'         => __('What the last Taxmod admin pages took: per page the calls, the middle and the longest time in ms and the middle count of database statements, slowest first; then the last calls themselves (limit, default 50, newest first) and the environment (PHP, OPcache, versions, what one empty database statement costs in microseconds). Per call also: ms until WordPress was loaded, until the page was drawn, and ms waiting for other servers. Measurements only.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => \Taxmod\Core\Service\PageTimes::KEEP, 'default' => 50],
                    'page'  => ['type' => 'string', 'description' => __('Only calls of this page, as the summary names it.', 'taxmod')],
                ],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->pageTimes(is_array($input) ? $input : [])),
            'permission_callback' => $this->allowedToRead(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        // His line 2026-11-21: «Detail: Vorschau 15825 ms / 661 Abfragen» — which field it is, measured where his data is (D-1482, Nachtrag 2).
        wp_register_ability('taxmod/preview-times', [
            'label'               => __('Measure the preview of a record', 'taxmod'),
            'description'         => __('What the editable preview of one record takes on the model page: the steps before the fields, then each field drawn alone, then both sides whole — ms, database statements and KB of markup each. Field names and numbers only, no values. Reads only.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'node'   => ['type' => 'integer', 'minimum' => 1],
                    'record' => ['type' => 'integer', 'minimum' => 1],
                ],
                'required'   => ['node', 'record'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->previewTimes(is_array($input) ? $input : [])),
            'permission_callback' => $this->allowedToRead(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
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
                'Like taxmod/apply, but only for data: create_record, set_values, remove_record, make_variants, add_variant, merge_record, move_record, move_part, reorder_values, duplicate_record on user records, and undo_act {act} for a change that wrote records only. Nodes, fields and settings cannot be changed here. One change number, all or nothing; "ref" and "@ref" work as in apply. A value a form validator (shape, syntax) refuses — also a row of a field taking several or a field of a part — refuses the whole list with error taxmod_invalid, its data listing every complaint (validator, key, values, node, field, record). A value outside its range is saved and listed under reported, with the same places (D-1554).',
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
                                'act'       => ['type' => 'integer', 'minimum' => 1],
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
                                // D-1482 make_variants, D-1480 merge_record.
                                'records'   => ['type' => 'array', 'items' => $ziel],
                                'from'      => $ziel,
                                'into'      => $ziel,
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

        // D-1547, Stefan 2026-11-21: «lass uns das ruhig mal einbauen, sodass wir da Gewissheit haben» — where settings stand before
        // deciding that they stand only at the data element (taxmod-felder/konzept.md §6). Reads, never writes.
        wp_register_ability('taxmod/settings-usage', [
            'label'               => __('Count settings by carrier', 'taxmod'),
            'description'         => __('How many setting rows each attribute (renderer, dialog, max …) has at a node, at a field (one use site) and inside a settings object, plus inactive rows; most field rows first. With attribute: also the rows of that attribute at fields — field (relation id), node, value — a page of them (limit, offset).', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'attribute' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'],
                    'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
                    'offset'    => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->settingsUsage(is_array($input) ? $input : [])),
            'permission_callback' => $this->allowedToRead(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        // konzept.md §8 M-b (Stefan 2026-11-21: «möglichst fehlerfrei und schnell und mit wenig Token»): every field in one call
        // instead of read-node per node, which costs hundreds of calls against the host's rate limit. Reads, never writes.
        wp_register_ability('taxmod/fields', [
            'label'               => __('List fields', 'taxmod'),
            'description'         => __('Every field of every live node in one call, a page of them: relation id, name, owning node, target, simple type, kind, multiplicity, chooses_records, the settings stored at the field itself, how many values and records it holds, and uses (fields with the same target). Filters: name (part, case and umlauts folded), node (owner), under (owners in that subtree), target, type, kind (setting edges only with kind setting), with_values, target_role (base_type, data_element, composite, outside: what the target is to the Feldkatalog, D-1553). Compact rows with columns unless compact is false.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'name'        => ['type' => 'string'],
                    'node'        => ['type' => 'integer', 'minimum' => 1],
                    'under'       => ['type' => 'integer', 'minimum' => 1],
                    'target'      => ['type' => 'integer', 'minimum' => 1],
                    'type'        => ['type' => 'string'],
                    'kind'        => ['type' => 'string', 'enum' => array_map(static fn (RelationKind $k): string => $k->value, RelationKind::cases())],
                    'with_values' => ['type' => 'boolean'],
                    'target_role' => ['type' => 'string', 'enum' => array_map(static fn (CatalogueRole $r): string => $r->value, CatalogueRole::cases())],
                    'compact'     => ['type' => 'boolean', 'default' => true],
                    'limit'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_FIELDS_PAGE, 'default' => 200],
                    'offset'      => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->fields(is_array($input) ? $input : [])),
            'permission_callback' => $this->allowedToRead(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        // konzept.md §8 M-c, U3: the same likeness the «create field» dialog is to ask — one logic for a person and for the MCP,
        // in the core ({@see SimilarFields}). Reads, never writes.
        wp_register_ability('taxmod/similar-fields', [
            'label'               => __('Find similar fields', 'taxmod'),
            'description'         => __('Fields that look like the one described: same simple type (if given), the given settings (attribute: value, compared with the settings at the field over those of its target node) and a similar name (case, umlauts and signs folded). Best first, each with score, name_similarity, matching_settings, differing_settings and its field row (as taxmod/fields, uses = fields with the same target). Setting edges are not searched.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'name'     => ['type' => 'string'],
                    'type'     => ['type' => 'string'],
                    'settings' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer', 'number']]],
                    'exclude'  => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => 500],
                    'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 20],
                ],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->similarFields(is_array($input) ? $input : [])),
            'permission_callback' => $this->allowedToRead(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        // D-1514 point 8: what a field held before it became confidential — counted first, sealed only when asked.
        wp_register_ability('taxmod/field-seal', [
            'label'               => __('Seal old values of confidential fields', 'taxmod'),
            'description'         => __('How many values on fields marked «Confidential» are still plain (plain) and how many are still sealed with a former key after a change of key (resealing_left; per former key: sealed_with_old_keys), each as live rows, history rows and chronicle entries; and how many are sealed although they are no longer confidential for the node of their record (unseal_left, e.g. the name of an organisation once «Confidential» stands only at Person, D-1578). dry_run (default true) only counts. dry_run false seals and reseals one batch (limit, default 500, at most 5000), with unseal true also opens what is no longer confidential, and answers how many rows it changed (sealed) and what is left; call again until nothing is left. This changes the stored form, not the value: it is no act and has no undo, because an undo would bring plain text back (D-1514, D-1562). Needs the data key.', 'taxmod'),
            'category'            => self::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'dry_run' => ['type' => 'boolean', 'default' => true],
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 500],
                    'unseal'  => ['type' => 'boolean', 'default' => false],
                ],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null) => $this->guarded(fn (): array => $this->fieldSeal(is_array($input) ? $input : [])),
            'permission_callback' => $this->allowed(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
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

    /**
     * @param  array<string, mixed> $input `mixed`: the ability's input, checked by its schema
     * @return array<string, mixed>
     */
    private function fields(array $input): array
    {
        $catalogue = $this->fieldCatalogue(isset($input['kind']) ? (string) $input['kind'] : null, isset($input['under']) ? (int) $input['under'] : null);
        $name      = SimilarFields::folded((string) ($input['name'] ?? ''));
        $rows      = array_values(array_filter($catalogue['rows'], static fn (array $row): bool =>
            ($name === '' || str_contains(SimilarFields::folded($row['name']), $name))
            && (! isset($input['node']) || $row['node'] === (int) $input['node'])
            && (! isset($input['target']) || $row['target'] === (int) $input['target'])
            && (! isset($input['type']) || $row['type'] === (string) $input['type'])
            && (empty($input['with_values']) || $row['values'] > 0)
            && (! isset($input['target_role']) || $row['target_role'] === (string) $input['target_role'])));
        $limit     = max(1, min(self::MAX_FIELDS_PAGE, (int) ($input['limit'] ?? 200)));
        $page      = $this->withRecordChoosers(array_slice($rows, max(0, (int) ($input['offset'] ?? 0)), $limit), $catalogue['relations']);
        $compact   = ($input['compact'] ?? true) !== false;

        return ['total' => count($rows), 'offset' => max(0, (int) ($input['offset'] ?? 0))]
            + ($compact
                ? ['columns' => self::FIELD_COLUMNS, 'rows' => array_map(static fn (array $row): array => array_map(static fn (string $c): mixed => $c === 'settings' ? (object) $row[$c] : $row[$c], self::FIELD_COLUMNS), $page)]
                : ['rows' => array_map(static fn (array $row): array => ['settings' => (object) $row['settings']] + $row, $page)]);
    }

    /**
     * @param  array<string, mixed> $input `mixed`: the ability's input, checked by its schema
     * @return array{count: int, matches: list<array<string, mixed>>}
     */
    private function similarFields(array $input): array
    {
        $catalogue = $this->fieldCatalogue(null, null);
        $exclude   = array_flip(array_map('intval', is_array($input['exclude'] ?? null) ? $input['exclude'] : []));
        $byId      = [];
        $fields    = [];

        foreach ($catalogue['rows'] as $row) {
            if (isset($exclude[$row['id']])) {
                continue;
            }

            $byId[$row['id']] = $row;
            $fields[]         = ['relation' => $row['id'], 'name' => $row['name'], 'type' => $row['type'], 'target' => $row['target'], 'settings' => $row['compared'], 'uses' => $row['uses']];
        }

        $wanted  = ['type' => isset($input['type']) ? (string) $input['type'] : null, 'name' => (string) ($input['name'] ?? ''), 'settings' => array_map('strval', is_array($input['settings'] ?? null) ? $input['settings'] : [])];
        $matches = (new SimilarFields())->among($wanted, $fields, max(1, min(200, (int) ($input['limit'] ?? 20))));
        $rows    = $this->withRecordChoosers(array_map(static fn (array $m): array => $byId[$m['relation']], $matches), $catalogue['relations']);
        $answer  = [];

        foreach ($matches as $i => $match) {
            $answer[] = $match + ['field' => array_combine(self::FIELD_COLUMNS, array_map(static fn (string $c): mixed => $c === 'settings' ? (object) $rows[$i][$c] : $rows[$i][$c], self::FIELD_COLUMNS))];
        }

        return ['count' => count($answer), 'matches' => $answer];
    }

    /**
     * Every field of every live node with what the list shows — in five reads whatever the number of fields (`CD-7`): the fields,
     * the tree, the types, the value counts, the settings. «chooses_records» is left to {@see withRecordChoosers()}, for a page only.
     *
     * @return array{rows: list<array<string, mixed>>, relations: array<int, \Taxmod\Core\Model\Relation>} `mixed`: one field row, its columns as {@see FIELD_COLUMNS} plus `compared`
     */
    private function fieldCatalogue(?string $kind, ?int $under): array
    {
        $framework = $this->framework();
        $tree      = new Tree(new Persistence\WpdbNodeRepository());
        $nodes     = [];

        foreach ($tree->rowsUnder($framework->root(), [$framework->trash()->id], [], true, true) as $row) {
            $nodes[$row['node']->id] = $row['node'];
        }

        $owners = $under === null ? $nodes : [];

        if ($under !== null) {
            foreach ($tree->rowsUnder($this->node($under), [$framework->trash()->id], [], true, true) as $row) {
                $owners[$row['node']->id] = true;
            }
        }

        $relations = array_values(array_filter(
            (new Persistence\WpdbRelationRepository())->everyFieldRelation(),
            static fn (\Taxmod\Core\Model\Relation $r): bool => isset($owners[$r->fromNodeId], $nodes[$r->fromNodeId]) && ($kind === null ? $r->kind !== RelationKind::Setting : $r->kind->value === $kind)
        ));
        $types     = $this->screen()->typesOfFields($relations);
        $counts    = (new WpdbRecordRepository())->valueCountsByField();
        $settings  = (new Persistence\WpdbSettingsRepository())->plainValuesByCarrier();
        $uses      = array_count_values(array_map(static fn (\Taxmod\Core\Model\Relation $r): int => $r->toNodeId, $relations));
        // D-1553: what each target is to the Feldkatalog.
        $roles     = Persistence\CatalogueBase::catalogue(new Persistence\SeededTypeNodes(new Persistence\WpdbNodeRepository(), $framework));
        $rows      = [];
        $byId      = [];

        foreach ($relations as $r) {
            $byId[$r->id] = $r;
            $own          = $settings['field'][$r->id] ?? [];
            $rows[]       = [
                'id'              => $r->id,
                'name'            => $r->name,
                'node'            => $r->fromNodeId,
                'node_name'       => isset($nodes[$r->fromNodeId]) ? $nodes[$r->fromNodeId]->name : '',
                'target'          => $r->toNodeId,
                'target_name'     => isset($nodes[$r->toNodeId]) ? $nodes[$r->toNodeId]->name : '',
                'type'            => ($types[$r->id] ?? null)?->value,
                'kind'            => $r->kind->value,
                'multiplicity'    => $r->multiplicity->value,
                'chooses_records' => false,
                'values'          => $counts[$r->id]['values'] ?? 0,
                'records'         => $counts[$r->id]['records'] ?? 0,
                'uses'            => $uses[$r->toNodeId] ?? 0,
                'settings'        => $own,
                // *What similar-fields compares: the field's own settings over those of its target node.*
                'compared'        => $own + ($settings['node'][$r->toNodeId] ?? []),
                'target_role'     => isset($nodes[$r->toNodeId]) ? $roles->roleOf($nodes[$r->toNodeId])->value : CatalogueRole::Outside->value,
            ];
        }

        return ['rows' => $rows, 'relations' => $byId];
    }

    /**
     * @param  list<array<string, mixed>>                     $rows `mixed`: field rows of {@see fieldCatalogue()}
     * @param  array<int, \Taxmod\Core\Model\Relation>    $relations
     * @return list<array<string, mixed>>
     */
    private function withRecordChoosers(array $rows, array $relations): array
    {
        $screen = $this->screen();
        $page   = array_values(array_filter(array_map(static fn (array $row): ?\Taxmod\Core\Model\Relation => $relations[$row['id']] ?? null, $rows)));
        $types  = [];

        foreach ($rows as $row) {
            $types[$row['id']] = $row['type'] === null ? null : SimpleType::from($row['type']);
        }

        $choosers = $screen->recordChoosersAmong($page, $types);

        return array_map(static fn (array $row): array => ['chooses_records' => isset($choosers[$row['id']])] + $row, $rows);
    }

    /** @return array{count: int, rows: list<array<string, int|string>>, csv: string} */
    /**
     * @param  array<string, mixed> $input `mixed`: the ability's input, checked by its schema
     * @return array{summary: list<array<string, int|string>>, fields?: list<array<string, bool|int|string>>}
     */
    /**
     * @param array{dry_run?: bool, limit?: int, unseal?: bool} $input
     * @return array<string, mixed> dry_run, edges, key, key_id, plain, unseal_left, resealing_left, sealed_with_old_keys; after a run also sealed and left
     */
    private function fieldSeal(array $input): array
    {
        $seal   = \Taxmod\WordPress\Privacy\FieldSeal::class;
        $job    = \Taxmod\WordPress\Privacy\FieldSealJob::class;
        $answer = [
            'dry_run'              => ($input['dry_run'] ?? true) !== false,
            'edges'                => count($seal::confidentialRelations()),
            'key'                  => $seal::cipher() !== null,
            'key_id'               => $seal::keyId(),
            'plain'                => $job::plainLeft(),
            'unseal_left'          => $job::unsealLeft(),
            'resealing_left'       => $job::resealLeft(),
            'sealed_with_old_keys' => $job::sealedWithRetired(),
        ];

        if ($answer['dry_run']) {
            return $answer;
        }

        if (! $answer['key']) {
            throw \Taxmod\Core\Exception\FieldDoesNotOpen::withoutKey();
        }

        $answer['sealed'] = $job::runBatch(max(1, min(5000, (int) ($input['limit'] ?? 500))), ($input['unseal'] ?? false) === true);
        $answer['left']   = ['plain' => $job::plainLeft(), 'unseal' => $job::unsealLeft(), 'resealing' => $job::resealLeft(), 'sealed_with_old_keys' => $job::sealedWithRetired()];
        $seal::noteSealedWith($answer['left']['sealed_with_old_keys'], time());

        return $answer;
    }

    private function settingsUsage(array $input): array
    {
        $repository = new \Taxmod\WordPress\Persistence\WpdbSettingsRepository();
        $answer     = ['summary' => (new \Taxmod\Core\Service\SettingsUsage())->summary($repository->usageGroups())];
        $attribute  = sanitize_key((string) ($input['attribute'] ?? ''));

        if ($attribute !== '') {
            $answer['fields'] = $repository->atFieldsNamed($attribute, max(1, min(500, (int) ($input['limit'] ?? 100))), max(0, (int) ($input['offset'] ?? 0)));
        }

        return $answer;
    }

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

            // konzept.md §8 M-e: a validator's complaints come back one by one with their place, so a caller sees every wrong value
            // of the list at once and where it stands — nothing was written.
            $abgewiesen = $e;

            while ($abgewiesen !== null && ! $abgewiesen instanceof \Taxmod\Core\Exception\RefusedByValidators) {
                $abgewiesen = $abgewiesen->getPrevious();
            }

            if ($abgewiesen !== null) {
                return new \WP_Error('taxmod_invalid', $e->getMessage(), ['complaints' => array_map(static fn (\Taxmod\Core\Addon\Complaint $c): array => [
                    'validator' => $c->validator,
                    'key'       => $c->key,
                    'values'    => (object) $c->values,
                    'node'      => $c->address?->nodeId,
                    'field'     => $c->address?->edgeId,
                    'record'    => $c->address?->recordId,
                ], $abgewiesen->complaints)]);
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
              // D-1487: a module tells a record reference from text and from a choice of nodes by this, not by the type.
              + ($screen->choosesRecords($r, $type) ? ['records' => true] : [])
              // D-1288: a computed field says what it is made of here, or a write to it is silently overwritten.
              + (($aus = $screen->composedOf($r, $id)) === [] ? [] : ['composed_of' => $aus]);
        }

        // Who asks without records — a module's door asking for the fields — gets their number, and no record is built for it.
        $ohne    = ($input['records'] ?? true) === false;
        $records = $ohne ? [] : $entries->recordsOf($id);
        $gesamt  = $ohne ? $entries->countRecordsOf($id) : count($records);
        $seite   = [];

        if (! $ohne) {
            $limit = isset($input['limit']) ? max(1, min(self::MAX_RECORDS_PAGE, (int) $input['limit'])) : null;
            $seite = array_slice($records, max(0, (int) ($input['offset'] ?? 0)), $limit);
        }

        // D-1486: asked for named fields and for every record, only those values are read — measured 2026-11-19 with purchases
        // as wide as the live ones, a goods receipt tab that read every value peaked at twice the memory. A page of records
        // keeps the read by ids.
        $werte   = match (true) {
            $seite === []                            => [],
            $nur !== [] && count($seite) === $gesamt => $entries->valuesOfNode($id, $nur),
            default                                  => $entries->valuesOfMany(array_map(static fn ($r): int => $r->id, $seite)),
        };
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
            // D-1458: the fields that choose from this node — a merge or a retarget needs them, and nothing else reads backwards.
            // A module's door asks without it: one query per node read, which no drawing needs (measured 18.11., +8 on «Angekommen»).
            'used_by'       => ($input['used_by'] ?? true) === false ? [] : array_map(static fn ($r): array => ['relation_id' => $r->id, 'name' => $r->name, 'node' => $r->fromNodeId], $this->editor()->usedBy($id)),
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
    /** D-1391: the records at and below a node, their derived fields written anew in one follow-up. */
    private function rewriteNamesUnder(NodesScreen $screen, \Taxmod\Core\Model\Node $node): void
    {
        $nodes = [$node->id];

        foreach ((new Tree(new Persistence\WpdbNodeRepository()))->rowsUnder($node, [$this->framework()->trash()->id]) as $row) {
            $nodes[] = $row['node']->id;
        }

        $ids = [];

        foreach ((new WpdbRecordRepository())->ofNodes($nodes) as $records) {
            foreach ($records as $record) {
                $ids[] = $record->id;
            }
        }

        $screen->entries()->rewriteDerivedOf($ids);
    }

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
            if ($c['op'] === 'fits') {
                $resolved[$i] = $this->fitting($screen, $nodes, $c['field'], (int) $c['values'][0]);
                continue;
            }

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
     * `fits` as ids (D-1477): the wanted node, its ancestors and its subtree are the candidates — one read for the ancestors,
     * one walk below — and the core rule picks among them.
     *
     * @param list<int> $nodes
     * @return list<string>
     */
    private function fitting(NodesScreen $screen, array $nodes, int $field, int $wanted): array
    {
        $target   = $this->node($this->targetOf($screen, $nodes, $field));
        $node     = $this->node($wanted);
        $repo     = new Persistence\WpdbNodeRepository();
        $candidates = [$node, ...array_values($repo->byIds($node->ancestorIds()))];

        foreach ((new Tree($repo))->rowsUnder($node, [$this->framework()->trash()->id]) as $row) {
            $candidates[] = $row['node'];
        }

        return RecordQuery::fitting($candidates, $node->path, $target->path);
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

        throw new \InvalidArgumentException(sprintf('field %d is not a field of node %d or below it.', $field, $nodes[0] ?? 0));
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
        $probe = ! empty($input['dry_run']);

        if ($probe && array_filter($changes, static fn (mixed $change): bool => ! is_array($change) || ($change['op'] ?? '') !== 'merge_field') === []) {
            return ['dry_run' => array_map(fn (mixed $change): array => $this->mergePreview(is_array($change) ? $change : [], $refs), $changes)];
        }

        // ⚠️ *No copy per change list any more (D-1407): a full copy took half a minute live; the nightly one stands, and the
        // answer names it.*
        $nacht     = ! $probe && ! $onlyData && ! empty($input['backup']) ? \Taxmod\WordPress\Persistence\DailyBackup::latest() : null;
        $sicherung = $nacht['file'] ?? null;
        $warnung   = ! $probe && ! $onlyData && ! empty($input['backup']) ? \Taxmod\WordPress\Persistence\DailyBackup::warning($nacht) : null;
        $ergebnis  = [];
        $changelog = ($this->changelog)();

        $changelog->beginAct();
        $this->onlyRecords = $onlyData;
        $akt               = null;
        // *Nothing left from a call before this one (D-1554).*
        $this->screen()->takeReported();
        $erwartet = RecordStamp::fromList(is_array($input['expect'] ?? null) ? array_values(array_filter($input['expect'], 'is_array')) : []);

        try {
            // ⚠️ *A plain closure, not `fn`: an arrow function would take $refs and $ergebnis by value, and the run would fill copies.*
            $lauf = function () use ($erwartet, $changes, $onlyData, $probe, &$refs, &$ergebnis): void {
                Transaction::whole('taxmod_apply', function () use ($erwartet, $changes, $onlyData, $probe, &$refs, &$ergebnis): void {
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

                    $ergebnis[] = ['op' => (string) $change['op'], 'id' => $id] + ($this->merged === null ? [] : ['merged' => $this->merged]);
                    $this->merged = null;
                }

                // ⚠️ *Der Probelauf jeder Op (Prüftor 21.11. 11:37, Pflicht vor einem grossen Umzug): dieselbe Liste, dieselben Prüfungen,
                // derselbe Akt — und am Ende zurückgerollt, also auch das Journal. Ein Umzug ohne Kopie der Nachtsicherung wird so prüfbar.*
                if ($probe) {
                    throw new ProbeRolledBack();
                }
                });
            };
            // D-1561: a dry run is heard by no module — what it wrote is rolled back, so nobody may act on it.
            $probe ? \Taxmod\Core\Event\SharedListeners::silenced($lauf) : $lauf();

            // *The number of this act, for its undo (D-1410); null when nothing was journaled.*
            $akt = $changelog->currentAct();
        } catch (\RuntimeException $e) {
            if (! $probe) {
                throw $e;
            }

            $this->forgetProbe();

            return $this->probeAnswer($e instanceof ProbeRolledBack, $ergebnis, $refs, $e instanceof ProbeRolledBack ? null : $e->getMessage());
        } finally {
            $changelog->endAct();
            $this->onlyRecords = false;
        }

        $beruehrt = array_values(array_unique([...$erwartet->ids(), ...array_filter(array_column($ergebnis, 'id'), 'is_int')]));

        // ⚠️ *The versions after the write, for the records the caller named or touched — its next change list can expect them (D-1148).*
        $versionen = $onlyData ? $this->screen()->entries()->stampOf($beruehrt)->versions : [];

        // D-1554: a range error was saved and is said here, each with its place — only when there is one.
        $screen   = $this->screen();
        $gemeldet = array_map(static fn (\Taxmod\Core\Addon\Complaint $c): array => [
            'validator' => $c->validator,
            'key'       => $c->key,
            'values'    => (object) $c->values,
            'text'      => $screen->reportedText($c),
            'node'      => $c->address?->nodeId,
            'field'     => $c->address?->edgeId,
            'record'    => $c->address?->recordId,
        ], $screen->takeReported());
        $extra    = $gemeldet === [] ? [] : ['reported' => $gemeldet];

        // ⚠️ *brief: the caller already holds its list; echoing every op back is tokens for nothing (D-1210).*
        return (! empty($input['brief'])
            ? ['count' => count($ergebnis), 'act' => $akt, 'refs' => $refs, 'backup' => $sicherung, 'backup_made' => $nacht === null ? null : false, 'backup_at' => $nacht['at'] ?? null, 'backup_warning' => $warnung, 'versions' => $versionen]
            : ['applied' => $ergebnis, 'act' => $akt, 'refs' => $refs, 'backup' => $sicherung, 'backup_made' => $nacht === null ? null : false, 'backup_at' => $nacht['at'] ?? null, 'backup_warning' => $warnung, 'versions' => $versionen]) + $extra;
    }

    /**
     * D-1561: after a rolled-back dry run nothing it wrote may still be remembered — not the act's pending rows (else the closing act
     * would journal them), not the reads of this request, not the object cache (options and transients it touched).
     */
    private function forgetProbe(): void
    {
        Persistence\ActStamp::discard();
        Persistence\Query::forget();
        \Taxmod\WordPress\Privacy\FieldSeal::forget();

        if (wp_using_ext_object_cache()) {
            wp_cache_flush();
        } else {
            wp_cache_flush_runtime();
        }
    }

    /**
     * The answer of a dry run over any op: what would have been applied, the refs it would have made, the range notes (D-1554), and
     * the first refusal — nothing written, no act. Ids in it are those the run had; a real run may get others.
     *
     * @param list<array<string, mixed>> $applied
     * @param array<string, int>         $refs
     * @return array<string, mixed>
     */
    private function probeAnswer(bool $fits, array $applied, array $refs, ?string $refused): array
    {
        $screen   = $this->screen();
        $gemeldet = array_map(static fn (\Taxmod\Core\Addon\Complaint $c): array => ['key' => $c->key, 'text' => $screen->reportedText($c), 'node' => $c->address?->nodeId, 'field' => $c->address?->edgeId, 'record' => $c->address?->recordId], $screen->takeReported());

        // D-1561: modules hear nothing in a dry run, so their vetoes (stock on merge_record) were not asked — fits is no guarantee.
        return ['dry_run' => true, 'written' => false, 'fits' => $fits, 'not_asked' => ['module vetoes'], 'applied' => $applied, 'refs' => $refs]
            + ($refused === null ? [] : ['refused' => $refused])
            + ($gemeldet === [] ? [] : ['reported' => $gemeldet]);
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

            case 'set_class':
                // ⚠️ *Gebaut, weil eine Konstante Unterarten bekommen soll und die Klasse bisher nur in der Maske wechselte (D-1297).*
                $klasse = $this->nodeClass((string) ($change['class'] ?? '')) ?? throw new \InvalidArgumentException('set_class needs a class.');

                return $screen->changeClass($this->node($at('node'))->id, $klasse)->id;

            case 'trash_node':
                return $editor->moveToTrash($at('node'))->id;

            case 'trash_unused_constant':
                // D-1474: «Löschen» of a choice-list value in Stammdaten — only what nothing chooses (merge_constant for the rest).
                return $editor->trashUnusedConstant($at('node'))->id;

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
                // D-1371: values that no longer fit the new target refuse the move, unless drop_values says to remove them.
                return $editor->retargetField($at('node'), $at('field'), $this->node($at('target'))->id, true, ! empty($change['drop_values']))->id;

            case 'drop_unfit_values':
                // D-1371: what an earlier retarget left behind — plain values on a part field, or parts on a plain one.
                $editor->dropUnfitValues($at('node'), $at('field'));

                return $at('field');

            case 'merge_constant':
                $editor->mergeConstant($this->node($at('from'))->id, $this->node($at('into'))->id);

                return $this->node($at('into'))->id;

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

                // ⚠️ *A field the record's node does not have is refused by name, never written to nowhere: a list of ops with a wrong
                // field id reported success and wrote nothing, not even an act (Prüftor 19.11., found by the D-1487 migration run).*
                $felder = array_flip(array_map(static fn (array $f): int => $f['relation']->id, $screen->fieldsWithTypes($record->nodeId)));
                $fremd  = array_diff(array_map('intval', array_keys($values)), array_keys($felder));

                if ($fremd !== []) {
                    throw new \InvalidArgumentException('Record ' . $recordId . ' has no field ' . implode(', ', $fremd) . ' — its node ' . $record->nodeId . ' does not carry it.');
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

            case 'make_variants':
                // D-1482: «Als Varianten zusammenfassen» — refused with field and records named where they differ outside «Je Variante».
                return $screen->makeVariants(array_map(fn (mixed $one): int => $this->resolve($one, $refs, 'records'), is_array($change['records'] ?? null) ? $change['records'] : []));

            case 'add_variant':
                // D-1482: «Variante anlegen» at a record; its own values in the same act.
                $variant = $screen->addVariant($at('record'));

                if (is_array($change['values'] ?? null) && $change['values'] !== []) {
                    $this->applyOne(['op' => 'set_values', 'record' => $variant, 'values' => $change['values']], $refs);
                }

                return $variant;

            case 'merge_record':
                // D-1480: a post block naming from would show nothing afterwards — refused with the post ids, never rewritten.
                $from  = $at('from');
                $posts = [];

                foreach ($this->blockUsage()['rows'] as $row) {
                    if ((int) $row['record'] === $from || in_array($from, array_map('intval', explode(',', (string) $row['records'])), true)) {
                        $posts[(int) $row['post']] = true;
                    }
                }

                if ($posts !== []) {
                    throw new \InvalidArgumentException(sprintf('#%d is named by a taxmod/record block in post %s — change the block first.', $from, implode(', ', array_keys($posts))));
                }

                $this->merged = $screen->mergeRecords($from, $at('into'));

                return $at('into');

            case 'remove_record':
                $recordId = $at('record');
                $screen->entries()->removeRecord($recordId);

                return $recordId;

            case 'undo_act':
                return \Taxmod\WordPress\Persistence\Restore::act((int) ($change['act'] ?? 0), ($this->changelog)(), $this->onlyRecords)['undone_by'];

            case 'move_part':
                // ⚠️ *D-1098, his word «f2 wecheln erlauben»: the part is re-hung, not created anew — its id and every reference to it stay.*
                $partId = $at('part');
                $screen->entries()->movePart($partId, $at('to'), $at('field'));

                return $partId;

            case 'reorder_values':
                $recordId = $at('record');
                $screen->entries()->reorderValues($recordId, $at('field'), array_map('intval', (array) ($change['order'] ?? [])));

                return $recordId;

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

                $attribute = sanitize_key((string) ($change['attribute'] ?? ''));
                $screen->setListMembers($nodeId, $attribute, $members, isset($change['field']) ? $at('field') : null);

                // D-1391: a list that names derive from rewrites the names of every record at and below the node (D-1390 left them old).
                if (in_array($attribute, [\Taxmod\Core\Renderer\SummaryRenderer::FIELDS, \Taxmod\Core\Model\NodeClass\Category::RASTER_FELDER, \Taxmod\Core\Model\NodeClass\Category::MASS_FELDER], true)) {
                    $this->rewriteNamesUnder($screen, $this->node($nodeId));
                }

                return $nodeId;

            case 'set_setting':
                // ⚠️ *Gebaut, weil «name_as_link_text» und «with_label» an den Quellen sonst nur in der Maske zu setzen waren (D-999).*
                $nodeId = $this->node($at('node'))->id;

                // D-1552: a value naming a node or field made in the same act («@ref») is that id, as everywhere else in a change.
                $value = (string) ($change['value'] ?? '');
                $value = str_starts_with($value, '@') ? (string) $at('value') : $value;

                $screen->setSetting($nodeId, sanitize_key((string) ($change['attribute'] ?? '')), $value, isset($change['field']) ? $at('field') : null);

                return $nodeId;

            case 'hide_field':
                // D-1348: the eye of D-1144 for an agent — hiding an inherited field at a subgroup was only possible in the screen.
                $nodeId = $this->node($at('node'))->id;
                $field  = $at('field');
                $hidden = filter_var($change['hidden'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException('hide_field needs hidden true or false.');
                $owned  = null;

                foreach ($editor->fieldsOf($nodeId) as $row) {
                    if ($row->id === $field) {
                        $owned = $row->fromNodeId === $nodeId;
                    }
                }

                if ($owned === null) {
                    throw new \InvalidArgumentException('hide_field: the node has no such field.');
                }

                if ($owned) {
                    $editor->hideField($nodeId, $field, $hidden);
                } elseif (! $screen->entries()->hideFieldAt($nodeId, $field, $hidden)) {
                    throw new \RuntimeException('hide_field: this model has no «hidden» setting at its root (D-1144), so a node cannot hide an inherited field.');
                }

                return $nodeId;

            case 'set_confidential':
                $nodeId       = $this->node($at('node'))->id;
                $field        = $at('field');
                $confidential = filter_var($change['confidential'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException('set_confidential needs confidential true or false.');

                if (! in_array($field, array_map(static fn ($row): int => $row->id, $editor->fieldsOf($nodeId)), true)) {
                    throw new \InvalidArgumentException('set_confidential: the node has no such field.');
                }

                if (! $screen->entries()->confidentialAt($nodeId, $field, $confidential)) {
                    throw new \RuntimeException('set_confidential: this model has no «confidential» setting at its root (D-1578).');
                }

                \Taxmod\WordPress\Privacy\FieldSeal::forget();

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
                // *D-1524: a field's own label — its help belongs to the use, not to the node it points at.*
                $feld   = isset($change['field']) ? $this->editor()->ownAttribute($nodeId, $at('field')) : null;
                // *Ohne Sprache die Standardsprache: die Hilfe fällt nur auf sie zurück, nie auf eine sprachneutrale Zeile (D-645).*
                $locale = (string) ($change['locale'] ?? '') === '' ? SettingsScreen::neutralLocale() : (string) $change['locale'];
                $label  = new Label($feld?->id ?? $nodeId, $feld === null ? IdentitySpace::Node : IdentitySpace::Relation, $role, Label::BASE_NUMBER, $locale, sanitize_textarea_field((string) ($change['text'] ?? '')));
                // *Über den Dienst, damit die Änderung im Änderungsbuch steht und sich zurücknehmen lässt (D-1010).*
                $labels = new \Taxmod\Core\Service\Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale(), ($this->changelog)());

                $label->text === '' ? $labels->forget($label) : $labels->put($label);

                return $feld?->id ?? $nodeId;
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
            throw new \InvalidArgumentException("{$op} changes the model; only create_record, set_values, remove_record, make_variants, add_variant, merge_record, move_record, move_part, reorder_values, duplicate_record and undo_act are allowed here.");
        }

        if (isset($change['record_type']) && (string) $change['record_type'] !== RecordType::User->value) {
            throw new \InvalidArgumentException('Only user records can be written here.');
        }

        // *move_part touches two records, the part and its new head; both must be user records. undo_act checks its rows itself.*
        // *make_variants and merge_record name their records under their own keys; every one must be a user record (D-1482, D-1480).*
        $named = match (true) {
            in_array($op, ['create_record', 'undo_act'], true) => [],
            $op === 'move_part'                               => [['part', $change['part'] ?? null], ['to', $change['to'] ?? null]],
            $op === 'merge_record'                            => [['from', $change['from'] ?? null], ['into', $change['into'] ?? null]],
            $op === 'make_variants'                           => array_map(static fn (mixed $one): array => ['records', $one], is_array($change['records'] ?? null) ? $change['records'] : []),
            default                                           => [['record', $change['record'] ?? null]],
        };

        foreach ($named as [$key, $one]) {
            $recordId = $this->resolve($one, $refs, $key);
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

    /**
     * @param array<string, mixed> $input
     * @return array{environment: array<string, string|bool|int>, pages: array<string, array<string, int|string>>, calls: list<array<string, int|string|bool>>} times as the site's clock says them
     */
    private function pageTimes(array $input): array
    {
        global $wpdb;

        $entries = Admin\PageTimesLog::entries();
        $page    = (string) ($input['page'] ?? '');
        $calls   = $page === '' ? $entries : array_values(array_filter($entries, static fn (array $e): bool => ($e['p'] ?? '') === $page));
        $limit   = max(0, min(\Taxmod\Core\Service\PageTimes::KEEP, (int) ($input['limit'] ?? 50)));

        return [
            'environment' => [
                'modeller' => Plugin::VERSION,
                'php'      => PHP_VERSION,
                'opcache'  => Admin\PageTimesLog::opcache(),
                'database' => (string) $wpdb->db_version(),
                'kept'     => count($entries),
                'db_round_trip_us' => Persistence\Query::roundTripMicroseconds(),
            ],
            'pages'       => array_map(
                static fn (array $took): array => ['last' => (string) wp_date('Y-m-d H:i:s', (int) $took['last'])] + $took,
                \Taxmod\Core\Service\PageTimes::summary($entries)
            ),
            'calls'       => $limit === 0 ? [] : array_map(static fn (array $e): array => [
                'at'      => (string) wp_date('Y-m-d H:i:s', (int) ($e['t'] ?? 0)),
                'page'    => (string) ($e['p'] ?? ''),
                'ms'      => (int) ($e['ms'] ?? 0),
                'queries' => (int) ($e['q'] ?? 0),
                'mb'      => (int) ($e['mb'] ?? 0),
                'opcache' => (int) ($e['o'] ?? 0) === 1,
                'loaded_ms' => (int) ($e['b'] ?? 0),
                'drawn_ms'  => (int) ($e['r'] ?? 0),
                'http_ms'   => (int) ($e['h'] ?? 0),
                'db_ms'     => (int) ($e['d'] ?? 0),
                'stream_ms' => (int) ($e['s'] ?? 0),
                'switches'  => (string) ($e['a'] ?? ''),
            ], array_reverse(array_slice($calls, -$limit))),
        ];
    }

    /**
     * D-1482, Nachtrag 2: the preview of one record, measured inside the read memory as the page draws it.
     *
     * @param array<string, mixed> $input
     * @return array{node: int, record: int, total_ms: int, total_queries: int, mb: int, steps: list<array<string, int|string>>, fields: list<array<string, int|string>>}
     */
    private function previewTimes(array $input): array
    {
        $node   = $this->node((int) ($input['node'] ?? 0));
        $record = (int) ($input['record'] ?? 0);
        $screen = ($this->screen)();

        if (! in_array($record, array_map(static fn (\Taxmod\Core\Model\NodeRecord $one): int => $one->id, $screen->entries()->recordsOf($node->id)), true)) {
            throw new \InvalidArgumentException("Record {$record} is not a record of node {$node->id}.");
        }

        $start   = hrtime(true);
        $queries = Persistence\Query::asked();
        $took    = Persistence\Query::remembering(static fn (): array => $screen->previewTimes($node, $record));

        return [
            'node'          => $node->id,
            'record'        => $record,
            'total_ms'      => (int) round((hrtime(true) - $start) / 1e6),
            'total_queries' => Persistence\Query::asked() - $queries,
            'mb'            => (int) round(memory_get_peak_usage(true) / 1048576),
        ] + $took;
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
