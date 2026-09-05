<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * The nodes the engine stands on, created once and then found by their stored ids.
 *
 * ⚠️ **The ids live in options, not in the code.** An id is meaningless (sentence 2), so a
 * hard-coded `1` would be exactly the special-casing the code standard forbids — and it would
 * break the moment a second installation allocated differently.
 *
 * ```mermaid
 * flowchart TB
 *   R["Root"] --> M["Model"]
 *   R --> C["Compositions"]
 *   R --> P["Primitives"]
 *   R --> T["Trash"]
 *   P --> DT["Data Types"]
 *   P --> K["Constants"]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class SeededFrameworkNodes implements FrameworkNodes
{
    public const ROOT_OPTION  = 'taxmod_root_id';
    public const TRASH_OPTION = 'taxmod_trash_id';

    /** The reserved identity installation-wide settings hang on (OQ-039). Not a node. */
    private const INSTALLATION_OPTION = 'taxmod_installation_id';

    /** Label roles are nodes (D-151) and live in their own container, not in a data branch. */
    private const ROLES_OPTION        = 'taxmod_roles_id';
    private const ROLE_OPTION_PREFIX  = 'taxmod_role_';

    /**
     * Wo die Ids der Einstellungskanten stehen ([D-543](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Dasselbe Muster wie {@see self::ROLE_OPTION_PREFIX} — **kein zweiter Mechanismus**, auf seine
     * Korrektur: «ich verstehe auch nicht, warum wir hier was Neues erfinden.»*
     *
     * ⚠️ **Der Name der Konstante ist mit TASK-016 gewandert, die Zeichenkette nicht — und das ist
     * kein Versehen.** *`taxmod_setting_edge_…` ist der **Schlüssel einer WordPress-Option** und
     * damit ein Datum: ein neuer Schlüssel fände die vorhandenen Zeilen nicht mehr wieder. Sie
     * umzuschreiben wäre eine Wanderung und keine Umbenennung, und
     * [D-576](../../../docs/NewConcept/90-decision-log.md) verlangt das Wort im **Quelltext**.*
     */
    private const SETTING_RELATION_PREFIX = 'taxmod_setting_edge_';

    private const SETTING_VALUE_RELATION_PREFIX = 'taxmod_setting_value_edge_';

    /** `Primitives` is a container that splits; the branches are the two nodes beneath it. */
    private const PRIMITIVES_OPTION = 'taxmod_primitives_id';

    /** @var array<string,string> */
    private const BRANCH_OPTIONS = [
        'model'        => 'taxmod_branch_model_id',
        'compositions' => 'taxmod_branch_compositions_id',
        'data-types'   => 'taxmod_branch_data_types_id',
        'constants'    => 'taxmod_branch_constants_id',
        'settings'     => 'taxmod_branch_settings_id',
    ];

    public function __construct(
        private readonly NodeRepository $nodes,
        /**
         * ⚠️ **Wird seit TASK-018 nicht mehr gelesen** ([D-581](../../../docs/NewConcept/90-decision-log.md)).
         * *Die Saat schrieb Knoten **und** Vererbungskante; die Kante gibt es nicht mehr. Der
         * Parameter steht noch, weil ihn 58 Aufrufer der Reihe nach übergeben — **ihn hier zu
         * streichen wäre eine Änderung an 58 Stellen mitten in einer Datenwanderung**, und das ist
         * genau die Vermischung, die `PR-2` nicht will. Er fällt mit TASK-032, wo die Kantenart
         * ohnehin angefasst wird; bis dahin steht er als `INF-034` im Eingang.*
         */
        private readonly RelationRepository $relations,
        private readonly Changelog $changelog,
    ) {
    }

    /**
     * @var array<string, Node> The seeded nodes this request has already read, by option name.
     *
     * ⚠️ **Measured, and it is the same fault {@see self::branchRoots()} was already fixed for.** One
     * page of the modelling screen made **111 queries, and 83 of them read a single node by id — 70 of
     * those from `rootOf()`.** *The comment beside `branchRoots()` had already written the lesson down —
     * «asking again per node made this an N+1 through the back door» — and this method never got it.*
     *
     * ⚠️ *Safe because a framework node cannot move ([D-194](../../../docs/NewConcept/90-decision-log.md)):
     * it is protected, so nothing can reparent or rename it mid-request. The one thing that **creates**
     * them is {@see self::seed()}, which clears this.*
     */
    private array $seeded = [];

    public function root(): Node
    {
        return $this->remembered(self::ROOT_OPTION);
    }

    public function trash(): Node
    {
        return $this->remembered(self::TRASH_OPTION);
    }

    public function rootOf(Branch $branch): Node
    {
        return $this->remembered(self::BRANCH_OPTIONS[$branch->value]);
    }

    /**
     * The node an option points at — read once per request.
     *
     * ⚠️ *`byId()` and not `find()`: a missing framework node is a broken installation, and the
     * exception says so where a `null` would travel on and surface as something else entirely.*
     */
    private function remembered(string $option): Node
    {
        return $this->seeded[$option] ??= $this->nodes->byId((int) get_option($option, 0));
    }

    /**
     * ⚠️ **Null is a real answer, not a failure.** The root, the trash and `Primitives` itself
     * sit in no branch — and so does anything hung directly under `Primitives`, because the
     * concept splits it into `Data Types` and `Constants` and says nothing about the space
     * between. Refusing there is honest; guessing a branch would invent a rule.
     *
     * ⚠️ **The four roots are read once per request, and that is not a micro-optimisation.**
     * Asking again per node made this an N+1 through the back door: drawing seven fields cost
     * twenty-five queries, because each field asked which branch its type sat in and each answer
     * looked up four roots. **Found by the boundary run counting queries** rather than by reading
     * the code, which is why that check exists (`CD-7`).
     */
    public function branchOf(Node $node): ?Branch
    {
        foreach ($this->branchRoots() as $value => $root) {
            if ($node->id === $root->id || $node->isDescendantOf($root)) {
                return Branch::from($value);
            }
        }

        return null;
    }

    /**
     * @var array<string,Node>|null Kept for the life of this object, which is one request. The
     *                             branch roots are framework nodes: they cannot be moved or
     *                             deleted (D-194), so nothing can invalidate them mid-request.
     */
    private ?array $branchRoots = null;

    /** @return array<string,Node> */
    private function branchRoots(): array
    {
        if ($this->branchRoots !== null) {
            return $this->branchRoots;
        }

        $roots = [];

        // ⚠️ **Through the same store, so a branch root is read once per request and not twice.**
        // *Two caches over the same eight nodes is the duplicated-fact prohibition in miniature: after
        // `rootOf()` was memoised, the page still read 14 single nodes where 8 exist, because this
        // method kept its own copy. Measured, that was 6 wasted queries a page.*
        foreach (self::BRANCH_OPTIONS as $value => $option) {
            $root = $this->seeded[$option] ?? $this->nodes->find((int) get_option($option, 0));

            if ($root !== null) {
                $this->seeded[$option] = $root;
                $roots[$value]         = $root;
            }
        }

        return $this->branchRoots = $roots;
    }


    public function installationId(): int
    {
        $id = (int) get_option(self::INSTALLATION_OPTION, 0);

        if ($id === 0) {
            // ⚠️ **Eine Identität, hinter der kein Knoten steht** — der Kopf der Einstellungskette.
            //
            // ⚠️ **Seit TASK-004 gibt es keinen geteilten Nummernraum mehr, aus dem sie kommen
            // könnte.** *`1` ist deshalb reserviert: {@see Schema} setzt das `AUTO_INCREMENT` von
            // `nodes` und `relations` auf einer frischen Installation auf `2`, damit diese Nummer
            // niemand anders bekommt. **Auf einer bestehenden Installation steht hier weiter die
            // alte Nummer** — es wird nichts umnummeriert.*
            //
            // ⚠️ *Wo die Installationsidentität künftig wohnen soll, ist eine Frage an den
            // Eigentümer und steht als `INF-008` in
            // [`inbox.md`](../../../docs/pakete/modelltabellen/inbox.md).*
            $id = 1;
            update_option(self::INSTALLATION_OPTION, $id, true);
        }

        return $id;
    }


    public function roleId(SeededRole $role): int
    {
        return (int) get_option(self::ROLE_OPTION_PREFIX . $role->value, 0);
    }

    /**
     * ⚠️ *Der Schnitt liegt an der Wurzel des Settings-Astes ([D-545](../../../docs/NewConcept/90-decision-log.md)).
     * Kein Aufstieg mit einer Abfrage je Stufe: der Pfad ist materialisiert, die Vorfahren-Ids stehen
     * schon da (`CD-7`).*
     *
     * @return list<int>
     */
    public function inheritanceOwnersOf(Node $node): array
    {
        $kette = [...$node->ancestorIds(), $node->id];
        $ast   = $this->rootOf(Branch::Settings);

        $wo = array_search($ast->id, $kette, true);

        // ⚠️ *Ein Knoten ausserhalb des Astes erbt wie immer — die Regel gilt nur drinnen.*
        if ($wo === false) {
            return $kette;
        }

        return array_values(array_slice($kette, (int) $wo));
    }

    public function settingRelationId(SettingKey $key): int
    {
        return (int) get_option(self::SETTING_RELATION_PREFIX . $key->value, 0);
    }

    public function settingValueRelationId(SettingKey $key): int
    {
        return (int) get_option(self::SETTING_VALUE_RELATION_PREFIX . $key->value, 0);
    }

    /**
     * ⚠️ *`autoload` an, wie bei den Rollen: die Angabe wird auf **jeder** gezeichneten Seite
     * gebraucht, und ein Nachschlag je Aufruf wäre eine Abfrage, die niemand sieht.*
     */
    public function rememberSettingRelations(SettingKey $key, int $relationId, int $valueRelationId): void
    {
        update_option(self::SETTING_RELATION_PREFIX . $key->value, $relationId, true);
        update_option(self::SETTING_VALUE_RELATION_PREFIX . $key->value, $valueRelationId, true);
    }

    public function isProtected(Node $node): bool
    {
        return in_array($node->id, $this->protectedIds(), true);
    }

    /**
     * Create what is missing. Safe to call on every activation — it adds, never replaces.
     *
     * ⚠️ **The names here are `name`, not labels.** Labels are per role and per locale and
     * arrive with Package 5; until then these nodes carry a plain internal name.
     */
    public function seed(): void
    {
        // Seeding is the one thing that can make a cached branch root wrong — it is what creates
        // them. Everything afterwards may cache freely, because they cannot move (D-194).
        $this->branchRoots = null;
        $this->seeded      = [];

        $root = $this->ensure(self::ROOT_OPTION, 'Root', null);

        $this->ensure(self::TRASH_OPTION, 'Trash', $root);
        $this->ensure(self::BRANCH_OPTIONS['model'], 'Model', $root);
        $this->ensure(self::BRANCH_OPTIONS['compositions'], 'Compositions', $root);

        $primitives = $this->ensure(self::PRIMITIVES_OPTION, 'Primitives', $root);

        $this->ensure(self::BRANCH_OPTIONS['data-types'], 'Data Types', $primitives);
        $this->ensure(self::BRANCH_OPTIONS['constants'], 'Constants', $primitives);

        // ⚠️ *Direkt unter der Wurzel und nicht unter `Primitives`: die Mengen, aus denen eine
        // Einstellung gewählt wird, sind keine Datentypen.*
        $this->ensure(self::BRANCH_OPTIONS['settings'], 'Settings', $root);

        // ⚠️ Roles are nodes and sit in **no data branch** — an attribute must not be able to
        // point at one. They are the engine's own vocabulary (D-151).
        $roles = $this->ensure(self::ROLES_OPTION, 'Label roles', $root);

        foreach (SeededRole::cases() as $role) {
            $this->ensure(self::ROLE_OPTION_PREFIX . $role->value, $role->value, $roles);
        }
    }

    /** @return list<int> */
    private function protectedIds(): array
    {
        $ids = [
            (int) get_option(self::ROOT_OPTION, 0),
            (int) get_option(self::TRASH_OPTION, 0),
            (int) get_option(self::PRIMITIVES_OPTION, 0),
        ];

        foreach (self::BRANCH_OPTIONS as $option) {
            $ids[] = (int) get_option($option, 0);
        }

        return $ids;
    }

    /** Find the node an option points at, or make it under the given parent. */
    private function ensure(string $option, string $name, ?Node $parent): Node
    {
        $id       = (int) get_option($option, 0);
        $existing = $id === 0 ? null : $this->nodes->find($id);

        if ($existing !== null) {
            return $existing;
        }

        // ⚠️ *`0` heisst «die Tabelle vergibt die Id» (TASK-004) — der Speicher gibt den
        // geschriebenen Knoten mit seiner Nummer und seinem fertigen Pfad zurück.*
        // ⚠️ *Vater und Stelle kommen seit TASK-018 mit der Zeile* ([D-581](../../../docs/NewConcept/90-decision-log.md)).
        // *Hier stand danach eine Vererbungskante — «ein Rahmenknoten ohne Kante wäre ein Knoten, den
        // der Baum nicht sieht». **Das kann jetzt nicht mehr auseinanderfallen**, weil es eine Zeile
        // ist.*
        $node = $this->nodes->add(Node::create(
            0,
            $name,
            $parent?->path,
            $parent?->id,
            $parent === null ? 0 : $this->nodes->nextPositionUnder($parent->id)
        ));

        $this->changelog->record($node->id, 'node', 'created', null, 'framework: ' . $name, $node->version);
        update_option($option, $node->id, true);

        return $node;
    }
}
