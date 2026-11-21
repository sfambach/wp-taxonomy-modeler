<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Addon\VariantAddon;
use Taxmod\Core\Addon\VariantOfAddon;
use Taxmod\Core\Exception\ModelDoorRefused;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Setting\NodeProperties;
use Taxmod\Core\Model\Variants;
use Taxmod\Core\Rules\RuleBook;
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
    /** @var (\Closure(): \Taxmod\Core\Repository\Changelog)|null the modeller's one changelog, handed in at boot (D-1419) */
    private static ?\Closure $changelog = null;

    /** @var array<string, array<int, array<int, list<string>>>> node id → its records as read (D-1489) */
    private array $read = [];

    /** @var array<string, array<string, mixed>> node| → the answer of `read-node` without records: the fields of the node */
    private array $narrow = [];

    /** @var array<string, array<string, mixed>> root|depth → the answer of `read-tree` */
    private array $trees = [];

    /**
     * The records of a node with their raw values — a variant with the values it shows (D-1208).
     *
     * Folded straight from the value rows (D-1489): the answer of `read-node` for a node with thousands of records cost some
     * 1600 bytes a value while it was built, and the door kept it beside its own result.
     *
     * @return array<int, array<int, list<string>>> record id → relation id → values
     */
    public function records(int $node): array
    {
        return $this->read[$node] ??= $this->shown((new WpdbRecordRepository())->rawValuesOfNode($node), $this->addonsAt($node));
    }

    /** @return array<int, list<string>> relation id → the names of the add-ons on that field */
    private function addonsAt(int $node): array
    {
        $addons = [];

        foreach ($this->fieldList($node) as $field) {
            $addons[(int) $field['relation_id']] = array_map('strval', (array) ($field['addons'] ?? []));
        }

        return $addons;
    }

    /**
     * The fields of a node without its records, asked once.
     *
     * @return list<array<string, mixed>> `mixed`: a field as `read-node` lists it
     */
    private function fieldList(int $node): array
    {
        return ($this->narrow[$node . '|'] ??= $this->execute('taxmod/read-node', ['node' => $node, 'records' => false, 'used_by' => false]))['fields'] ?? [];
    }

    /**
     * The fields of a node as the modeller lists them, inherited ones included — from the same read as its records.
     *
     * @return array<int, array{name: string, target: int, type: string|null, kind: string, multiplicity: string, addons: list<string>, records: bool}> relation id → field
     */
    public function fields(int $node): array
    {
        $out = [];

        foreach ($this->fieldList($node) as $field) {
            $out[(int) $field['relation_id']] = [
                'name'         => (string) ($field['name'] ?? ''),
                'target'       => (int) ($field['target'] ?? 0),
                'type'         => isset($field['type']) ? (string) $field['type'] : null,
                'kind'         => (string) ($field['kind'] ?? ''),
                'multiplicity' => (string) ($field['multiplicity'] ?? ''),
                'addons'       => array_map('strval', (array) ($field['addons'] ?? [])),
                // D-1487: whether its value is a record — «Modell» says `node_ref` and holds records.
                'records'      => ! empty($field['records']),
            ];
        }

        return $out;
    }

    /**
     * D-1284: the node a field points to, read by the field's own id — a module names a field, not the node beneath it (one
     * query, no node read). 0 when the field is unknown.
     */
    public function targetOf(int $relation): int
    {
        return (new Persistence\WpdbRelationRepository())->byId($relation)?->toNodeId ?? 0;
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
     * Mark records a module created with only what it could derive as «Review needed» (D-1255); saving them once in the full form
     * clears the mark. Needs the capability to write data.
     *
     * @param list<int> $records
     */
    public function markForReview(array $records): void
    {
        if (! current_user_can(ModelAbilities::DATA_CAPABILITY) && ! current_user_can(Plugin::CAPABILITY)) {
            throw ModelDoorRefused::because('Not allowed to mark records for review.');
        }

        foreach (array_unique(array_map('intval', $records)) as $record) {
            Persistence\RecordReviews::mark($record);
        }
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
            $type  = is_array($declared) && in_array($declared['type'] ?? '', [NodeProperties::ID, NodeProperties::NUMBER], true) ? (string) $declared['type'] : NodeProperties::YES_NO;

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
     * The roles of this site (D-1394): which node, field or value a module means by `board_revision`, `revision_layers` …
     * A module names roles, never ids; a role not set throws `RoleMissing`.
     */
    public static function roles(): \Taxmod\Core\Role\RoleMap
    {
        return Roles::current();
    }

    /**
     * Take a module's own role table into the shared one (D-1394), once per module release while it moves over. Nothing set
     * is overwritten; a role set to another id comes back as a conflict.
     *
     * @param array<string, mixed> $roles {nodes: {name: id}, fields: {…}, values: {…}}
     * @return list<array{group: string, key: string, stored: int, offered: int}>
     */
    public static function adoptRoles(array $roles): array
    {
        return Roles::adopt($roles);
    }

    /**
     * The required roles modules declared over `taxmod_roles` that this site has not set.
     *
     * @return list<array{module: string, group: string, key: string}>
     */
    public static function missingRoles(): array
    {
        return Roles::missing();
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
     * The values these nodes carry themselves, without inheritance (D-1259) — a form shows «own» apart from «as above». One query.
     *
     * @param list<int> $nodes
     * @return array<int, bool|int> node id → own value; nodes without one are left out
     */
    public function ownPropertiesOf(array $nodes, string $key): array
    {
        return array_intersect_key(self::propertyTypes()->own((new Persistence\WpdbSettingsRepository())->valuesNamed($key), $key), array_flip(array_map('intval', $nodes)));
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

        $this->read = [];
        $this->narrow = [];
        $this->trees = [];
        $this->execute('taxmod/apply', ['changes' => [['op' => 'set_setting', 'node' => $node, 'attribute' => $key, 'value' => match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default         => (string) $value,
        }]]]);
    }

    /**
     * Create a node below `$parent` with the parent's default child class (D-716) — a module's «+ new subgroup» (D-1331).
     * One act, undoable; the name is a label the person typed, never a value the module made up.
     */
    public function createChild(int $parent, string $name): int
    {
        $this->read = [];
        $this->narrow = [];
        $this->trees = [];
        $answer = $this->execute('taxmod/apply', ['changes' => [['op' => 'create_node', 'parent' => $parent, 'name' => $name]]]);
        $id     = (int) ($answer['applied'][0]['id'] ?? 0);

        return $id > 0 ? $id : throw ModelDoorRefused::because('The modeller created no node below ' . $parent . '.');
    }

    /**
     * D-1474: Stammdaten › Auswahllisten — the model's changes a module may ask for on a choice list, each one act through
     * `taxmod/apply`, so the person's capability for the modeller applies and the answer's change number takes it back.
     */
    public function renameNode(int $node, string $name): int
    {
        return $this->applied([['op' => 'rename_node', 'node' => $node, 'name' => $name]]);
    }

    /** D-1474: the node every choice list hangs below (D-1474: «Konstanten», one category per module) — the framework says which. */
    public function constantsRoot(): int
    {
        $nodes = new Persistence\WpdbNodeRepository();

        return (new Persistence\SeededFrameworkNodes($nodes, new Persistence\WpdbRelationRepository(), new Persistence\WpdbChangelog(new SystemClock())))->rootOf(\Taxmod\Core\Model\Branch::Constants)->id;
    }

    /**
     * The Feldkatalog ([D-1555](../../docs/NewConcept/90-decision-log.md)): the branch «Data Types» and every composite data type
     * with what is below it, in tree order — each entry with its role and how many fields point at it. The Logistik page shows
     * this branch and keeps no copy. Three reads, whatever the size (`CD-7`): the tree, the composites, the fields.
     *
     * @return list<array{id: int, name: string, parent: int, depth: int, role: string, uses: int}>
     */
    public function catalogue(): array
    {
        if (! current_user_can('manage_options')) {
            return [];
        }

        $nodes     = new Persistence\WpdbNodeRepository();
        $relations = new Persistence\WpdbRelationRepository();
        $framework = new Persistence\SeededFrameworkNodes($nodes, $relations, new Persistence\WpdbChangelog(new SystemClock()));
        $katalog   = Persistence\CatalogueBase::catalogue(new Persistence\SeededTypeNodes($nodes, $framework));
        $typen     = $framework->rootOf(\Taxmod\Core\Model\Branch::DataTypes);
        $uses      = [];

        foreach ($relations->everyFieldRelation() as $relation) {
            if ($relation->kind !== \Taxmod\Core\Model\RelationKind::Setting) {
                $uses[$relation->toNodeId] = ($uses[$relation->toNodeId] ?? 0) + 1;
            }
        }

        $out = [];

        foreach ((new \Taxmod\Core\Service\Tree($nodes))->rowsUnder($framework->root(), [$framework->trash()->id], [], true, true) as $row) {
            $node = $row['node'];
            $role = $katalog->roleOf($node);

            if ($role === \Taxmod\Core\Model\CatalogueRole::Outside && ! $node->isDescendantOf($typen)) {
                continue;
            }

            $out[] = ['id' => $node->id, 'name' => $node->name, 'parent' => (int) $node->parentNodeId, 'depth' => $row['depth'], 'role' => $role->value, 'uses' => $uses[$node->id] ?? 0];
        }

        return $out;
    }

    /** D-1458, D-1474: every choice of `$from` chooses `$into`, `$from` goes to the trash; the modeller's rules refuse with a reason. */
    public function mergeConstant(int $from, int $into): int
    {
        return $this->applied([['op' => 'merge_constant', 'from' => $from, 'into' => $into]]);
    }

    /** D-1474: a value nothing chooses into the trash; one still chosen is refused, naming how many choose it. */
    public function trashUnusedConstant(int $node): int
    {
        return $this->applied([['op' => 'trash_unused_constant', 'node' => $node]]);
    }

    /** D-1474: take back one of the acts above (or {@see createChild()}) by its number; its answer is the undo's own number. */
    public function undoModel(int $act): int
    {
        return $this->applied([['op' => 'undo_act', 'act' => $act]]);
    }

    /**
     * D-1474: how many values (records and settings) choose each of these nodes, in one query — a list page shows it per row.
     *
     * @param list<int> $nodes
     * @return array<int, int> node id → values choosing it; a node nothing chooses is absent
     */
    public function constantUses(array $nodes): array
    {
        return $nodes === [] ? [] : (new WpdbRecordRepository())->nodeReferrerCounts($nodes);
    }

    /** @param list<array<string, mixed>> $changes */
    private function applied(array $changes): int
    {
        $this->read = [];
        $this->trees = [];
        $act         = (int) ($this->execute('taxmod/apply', ['changes' => $changes])['act'] ?? 0);

        return $act > 0 ? $act : throw ModelDoorRefused::because('The modeller wrote no change for ' . (string) ($changes[0]['op'] ?? '') . '.');
    }

    /**
     * The modeller's own controls for these fields of `$node` (D-1250) — a quantity is the unit-value control it is in the
     * material, never an input of the module's own. Posted as `$prefix[relation id]`; read back with {@see typed()}.
     *
     * @param list<int>          $relations
     * @param array<int, string> $values characters by relation id where there is no record yet; leave out what is not known
     * @param array<int, int>    $roots  relation id → a node below the field's target: the chooser offers only that branch (D-1298);
     *                                   a node outside the target is ignored. The handler still checks what came back.
     * @return array<int, string> relation id → control markup, escaped
     */
    public function controls(int $node, array $relations, string $prefix, string $formId = '', int $record = 0, array $values = [], array $roots = []): array
    {
        $controls = apply_filters(Plugin::FIELD_CONTROLS_FILTER, [], $node, $relations, $prefix, $formId, $record, $values, $roots);

        return is_array($controls) ? $controls : [];
    }

    /**
     * What was typed into {@see controls()}, as raw values for {@see write()}; an empty field is left out. A part field
     * (composition, e.g. a unit value) comes back in the part form of write-data: part key → inner relation → characters (D-1538).
     *
     * @param array<int|string, mixed> $submitted `$_POST[$prefix]`, as posted
     * @return array<int, string|array<int|string, array<int, string>>> relation id → raw value, or the parts
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

    /**
     * The symbol of each of these nodes (D-1534) — a code that stays when the name is translated, such as the ISO code of a
     * country under «Länder» («DE» beside «Deutschland» / «Germany»). Only a stored symbol counts, never the name as a
     * fallback: a node without one is absent. The default language wins over another one. One query (CD-7).
     *
     * @param list<int> $nodes
     * @return array<int, string> node id → symbol
     */
    public function symbolsOf(array $nodes): array
    {
        $ids     = array_values(array_unique(array_map('intval', $nodes)));
        $default = Admin\SettingsScreen::neutralLocale();
        $out     = [];

        foreach ($ids === [] ? [] : (new Persistence\WpdbLabelRepository())->forOwners($ids, \Taxmod\Core\Model\IdentitySpace::Node) as $label) {
            if ($label->role === \Taxmod\Core\Model\SeededRole::Symbol && $label->number === \Taxmod\Core\Model\Label::BASE_NUMBER && $label->text !== ''
                && (! isset($out[$label->ownerId]) || $label->locale === $default)) {
                $out[$label->ownerId] = $label->text;
            }
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
        return $records === [] ? [] : (new WpdbRecordRepository())->referringRecords($records, $relation);
    }

    /**
     * Which records point at these records through any field, in one query (D-1268) — so a module can tell whether a record
     * it is about to remove is still referred to anywhere, also by fields of other modules it does not know.
     *
     * @param list<int> $records
     * @return array<int, list<int>> referred record id → the records pointing at it; a record nobody refers to is absent
     */
    public function referrersOf(array $records): array
    {
        $out = [];

        foreach ($records === [] ? [] : (new WpdbRecordRepository())->referrersOfMany($records) as $referred => $values) {
            foreach ($values as $value) {
                $out[(int) $referred][$value->recordId] = true;
            }
        }

        return array_map(array_keys(...), $out);
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
     * D-1540: which candidates carry the same key values of their category as the record — the fields the category computes
     * its name from, a quantity compared in its base unit. One call for all records (CD-7): a read for the nodes of the
     * records, one field read per category, one for the values, one for the parts and one for the conversions they name.
     *
     * @param array<int, list<int>> $candidates record → candidates
     * @return array<int, list<int>> record → the candidates that match; a record without match is absent
     */
    public function sameKeyValuesOf(array $candidates): array
    {
        $records = [];

        foreach ($candidates as $record => $others) {
            $records[(int) $record] = true;

            foreach ($others as $other) {
                $records[(int) $other] = true;
            }
        }

        if ($records === []) {
            return [];
        }

        $nodes     = $this->nodesOf(array_keys($records));
        $byNode    = [];
        $keyFields = [];

        foreach ($nodes as $record => $node) {
            $keyFields[$record] = $byNode[$node] ??= $this->keyFieldsOf($node);
        }

        $repository = new WpdbRecordRepository();
        $rows       = $repository->valuesOfMany(array_keys($records));
        $parts      = [];

        foreach ($rows as $record => $values) {
            $wanted = array_flip($keyFields[$record] ?? []);

            foreach ($values as $value) {
                if (isset($wanted[$value->relationId]) && $value->value->reference !== null && $value->value->referenceSpace === \Taxmod\Core\Model\ReferenceSpace::Record) {
                    $parts[$value->value->reference] = true;
                }
            }
        }

        $partRows = $parts === [] ? [] : $repository->valuesOfMany(array_keys($parts));

        return (new \Taxmod\Core\Service\KeyValues($keyFields, $rows, $partRows, $this->conversionsIn($partRows)))->matching($candidates);
    }

    /**
     * The fields a category computes its name from (`composed_of` of its name field), without the name field itself.
     *
     * @return list<int>
     */
    private function keyFieldsOf(int $node): array
    {
        foreach ($this->fieldList($node) as $field) {
            $parts = array_map('intval', (array) ($field['composed_of'] ?? []));

            if ($parts !== []) {
                return array_values(array_diff($parts, [(int) $field['relation_id']]));
            }
        }

        return [];
    }

    /**
     * The conversions (factor, offset) of the nodes these part rows point at — a prefix, a unit with a factor.
     *
     * @param array<int, list<\Taxmod\Core\Model\RelationRecord>> $partRows
     * @return array<int, array{factor: string, offset: string}>
     */
    private function conversionsIn(array $partRows): array
    {
        $ids = [];

        foreach ($partRows as $rows) {
            foreach ($rows as $row) {
                if ($row->value->reference !== null && $row->value->referenceSpace === \Taxmod\Core\Model\ReferenceSpace::Node) {
                    $ids[$row->value->reference] = true;
                }
            }
        }

        $nodes = $ids === [] ? [] : (new Persistence\WpdbNodeRepository())->byIds(array_keys($ids));

        if ($nodes === []) {
            return [];
        }

        $resolver = new \Taxmod\Core\Service\SettingsResolver(
            new Persistence\WpdbSettingsRepository(),
            new Persistence\WpdbNodeRepository(),
            \Taxmod\Core\Renderer\ShippedRenderers::registry(),
            \Taxmod\Core\Converter\ShippedConverters::registry(),
            \Taxmod\Core\Addon\ShippedAddons::registry(),
            new Persistence\WpdbRelationRepository()
        );
        $resolver->preload(array_values($nodes));
        $out = [];

        foreach ($nodes as $node) {
            $settings = $resolver->forNode($node);
            $factor   = isset($settings['factor']) && $settings['factor']->fromOwnerId !== 0 ? $settings['factor']->value->rawValue() : null;

            if ($factor !== null && $factor !== '') {
                $out[(int) $node->id] = [
                    'factor' => (string) $factor,
                    'offset' => isset($settings['offset']) ? (string) $settings['offset']->value->rawValue() : '0',
                ];
            }
        }

        return $out;
    }

    /**
     * D-1479: which records of these nodes are variants, and of which parent — for a list that sums a parent's stock and
     * draws its variants beneath it. A read for the nodes, one for their fields, one per «Variante von» field (CD-7).
     *
     * @param list<int> $nodeIds
     * @return array<int, int> variant → parent
     */
    public function variantParents(array $nodeIds): array
    {
        $nodes = $nodeIds === [] ? [] : (new Persistence\WpdbNodeRepository())->byIds(array_values(array_unique($nodeIds)));
        $owners = [];

        foreach ($nodes as $node) {
            foreach ([...$node->ancestorIds(), $node->id] as $owner) {
                $owners[$owner] = true;
            }
        }

        $fields = $owners === [] ? [] : array_filter(
            (new Persistence\WpdbRelationRepository())->fieldRelationsOf(array_keys($owners)),
            static fn (\Taxmod\Core\Model\Relation $field): bool => $field->kind === \Taxmod\Core\Model\RelationKind::Aggregation && isset($owners[$field->toNodeId])
        );
        $out    = [];

        foreach (self::addonsOf(array_map(static fn ($field): int => $field->id, array_values($fields))) as $relation => $names) {
            if (! in_array(VariantOfAddon::NAME, $names, true)) {
                continue;
            }

            foreach ((new WpdbRecordRepository())->parentLinksOf(array_map(static fn ($node): int => $node->id, array_values($nodes)), (int) $relation) as $variant => $parent) {
                if ($parent !== null && $parent > 0) {
                    $out[(int) $variant] = $parent;
                }
            }
        }

        return $out;
    }

    /**
     * D-1295: the words of one record for the fields a label names — by field name (any case) or id, «node» for the node's
     * name; a reference gives the word of the record it points at, an empty field is empty (D-1160, D-1222).
     *
     * @param list<string> $fields
     * @return array{values: array<string, string>, unknown: list<string>, node: int}
     */
    public function labelValues(int $record, array $fields): array
    {
        $answer = apply_filters(Plugin::LABEL_VALUES_FILTER, null, $record, $fields);

        return is_array($answer) ? $answer : ['values' => [], 'unknown' => $fields, 'node' => 0];
    }

    /**
     * D-1294: queue a finished print job — the module renders the PDF with all its pages; the print service fetches it as it is.
     *
     * @param array{queue?: string, options?: list<string>, retry?: int, roll?: int|float} $delivery printer queue, options (D-1191), roll width in mm
     * @return int the job's id
     */
    public function queuePrint(string $pdf, string $format, string $code, string $title, int $copies = 1, array $delivery = [], bool $test = false): int
    {
        return (new Printing\PrintJobs())->queuePdf($pdf, $format, $code, $title, $copies, $delivery, $test);
    }

    /**
     * D-1291: the words of these fields of these records, as the modeller's screen shows them — a price with its unit — in one
     * run (CD-7). A module shows them as they are and formats no value itself.
     *
     * @param list<int> $records
     * @param list<int> $relations
     * @return array<int, array<int, string>> relation id → record id → word
     */
    public function fieldWords(array $records, array $relations): array
    {
        $answer = $records === [] || $relations === [] ? [] : apply_filters(Plugin::FIELD_WORDS_FILTER, [], $records, $relations);

        return is_array($answer) ? $answer : [];
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
     * The modeller's read memory around one drawn page (D-814): the same statement asked twice while `$work` runs is answered
     * once, and any write makes it forget. A module draws its pages inside it instead of reaching into the persistence layer
     * (D-1186, design guard G5).
     */
    public static function remembering(callable $work): mixed
    {
        return Persistence\Query::remembering($work);
    }

    /**
     * The first rule whose every condition and filter holds — one rule set for printing and testing (D-1541), so no module
     * builds its own. The module reads its rule records and hands them in; an empty answer is the caller's default.
     *
     * @param list<array{id: int, position: int, conditions: array<int, int>, filters?: list<array{field: int, value: string, operator?: string}>}> $rules
     * @param array<int, list<int>>    $context condition key → the nodes that count, a thing with every node above
     * @param array<int, list<string>> $values  field → the thing's values, for the filters
     * @param ?\Closure(int): bool     $usable  a further test the caller adds per rule
     */
    public function firstRule(array $rules, array $context, array $values = [], ?\Closure $usable = null): ?int
    {
        return (new RuleBook($rules))->first($context, $values, $usable);
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, mixed> the modeller's answer, with the new ids under `refs` and its change number under `act` (D-1410)
     */
    public function write(array $changes): array
    {
        $this->read = [];
        $this->narrow = [];
        $this->trees = [];

        return $this->execute('taxmod/write-data', ['changes' => $changes]);
    }

    /** Plugin boot hands in its one changelog; a second copy could not hold the act open for the first (D-1419). */
    public static function useChangelog(\Closure $changelog): void
    {
        self::$changelog ??= $changelog;
    }

    /**
     * Several writes of a module — its own and those it asks other modules for through their filters — as one change number,
     * whole or not at all (D-1419, D-1410): the answer's `act` takes all of them back with one `undo_act`. A write that fails
     * rolls back every write before it in the bracket.
     *
     * ```mermaid
     * flowchart LR
     *   B[beginAct] --> T["Transaction::whole"] --> W1[write] --> W2["filter → write"] --> E[endAct]
     *   W2 -->|throws| R["rollback all"] --> E
     * ```
     *
     * @template T
     * @param callable(): T $work
     * @return array{result: T, act: ?int} act: null when nothing was written
     */
    public static function inAct(callable $work): array
    {
        $changelog = (self::$changelog ?? throw ModelDoorRefused::because('The modeller has not handed in its changelog — is it active?'))();
        $changelog->beginAct();

        try {
            $result = Persistence\Transaction::whole('taxmod_module_act', $work);
            $act    = $changelog->currentAct();
        } finally {
            $changelog->endAct();
        }

        return ['result' => $result, 'act' => $act];
    }

    /**
     * Take back a change this module wrote, by the number its answer named as `act` (D-1410). Refused, naming the other change, when
     * the same records were written since; only a change that wrote records alone.
     *
     * @return array<string, mixed> the modeller's answer; its `act` is the undo's own number
     */
    public function undo(int $act): array
    {
        return $this->write([['op' => 'undo_act', 'act' => $act]]);
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
