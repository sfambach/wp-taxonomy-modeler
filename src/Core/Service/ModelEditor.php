<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\CannotRestore;
use Taxmod\Core\Exception\ImpossibleMove;
use Taxmod\Core\Exception\NodeIsProtected;
use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Model\FrozenState;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeKind;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\IdentityAllocator;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SettingRecord;
use Taxmod\Core\Repository\LabelRepository;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Repository\SettingRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * Everything a person can do to the shape of the model: make a node, rename it, move it,
 * reorder it among its siblings, throw it away.
 *
 * ⚠️ **The tree is the inheritance edges; `path` is derived from them** (D-014). Every operation
 * here changes the edge first and rewrites the path afterwards. Writing the path alone would
 * make the derived value the only truth, which is exactly what D-014 forbids.
 *
 * ⚠️ **Deletion is two stages, and only the second is irreversible** (D-123). Parking is an
 * ordinary move — under the trash. The node stays a real node, so nothing that pointed at it
 * dangles and a conflict can be sorted out afterwards in peace instead of in a dialog blocking
 * the delete.
 *
 * ```mermaid
 * flowchart LR
 *   A[in the model] -->|park = move under the trash| B[under the trash]
 *   B -->|restore = move back| A
 *   B -->|purge| C[gone]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class ModelEditor
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly RelationRepository $relations,
        private readonly IdentityAllocator $identities,
        private readonly FrameworkNodes $framework,
        private readonly Changelog $changelog,
        // ⚠️ **Only `duplicate()` uses these, and that is why they are optional.** A copy has to
        // resolve exactly like its original or it is not a copy — which means its **own** settings
        // and labels travel with it. *Everything else in this service moves nodes and edges around
        // and has no business reading either.*
        private readonly ?SettingRepository $settings = null,
        private readonly ?LabelRepository $labels = null,
        // ⚠️ **The *service*, not the repository, and it is here for materialising**
        // ([D-423](../../../docs/NewConcept/90-decision-log.md)). A new node's rows are what its
        // parent **resolves** to, which only the service can answer — the repository above holds
        // rows and knows nothing of chains. *Optional for the same reason as the other two: the
        // eighteen places that construct this service mostly move nodes around, and a required
        // argument would make every one of them declare a dependency it never uses.*
        private readonly ?Settings $materialiser = null,
        // ⚠️ **Nur `clearTrash()` braucht es, und es fehlte — deshalb überlebten Records ihren Knoten.**
        // *Optional aus demselben Grund wie die drei darüber: die achtzehn Stellen, die diesen Dienst
        // bauen, verschieben meist nur Knoten und hätten sonst eine Abhängigkeit zu erklären, die sie
        // nie benutzen.*
        private readonly ?RecordRepository $records = null,
    ) {
    }

    public function createNode(string $name, int $parentId): Node
    {
        // ⚠️ **Creating a node is one change, not three** ([list row 45](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
        // *Measured against the real database: `created` plus the two settings this method materialises
        // landed in **three** change groups, because a group was opened per write. **Nobody performs
        // «materialise `read_only`» as an act** — they create a node, and the settings come with it.*
        //
        // ⚠️ *Nested brackets are counted, so this is harmless when the boundary already opened one
        // ({@see \Taxmod\WordPress\Admin\NodesScreen::handlePost()}) — **the outermost bracket is the
        // act**, which is the one a person performed. And it means a caller that is not a screen
        // (activation, a scaffold, WP-CLI) still gets one group per node instead of three.*
        $this->changelog->beginAct();

        try {
            return $this->createdNode($name, $parentId);
        } finally {
            $this->changelog->endAct();
        }
    }

    private function createdNode(string $name, int $parentId): Node
    {
        $parent = $this->nodes->byId($parentId);

        // Two identities, because an edge is a first-class thing that can carry settings and
        // labels of its own (C8) — and both come from the one model space (C11).
        $node = Node::create($this->identities->next(), $name, $parent->path);
        $edge = Relation::inheritance(
            $this->identities->next(),
            $parent->id,
            $node->id,
            $this->relations->nextPositionUnder($parent->id)
        );

        $this->nodes->add($node);
        $this->relations->add($edge);
        $this->changelog->record($node->id, 'node', 'created', null, $this->state($node));

        // ⚠️ **The parent's settings are written into the child** ([D-423](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *on inheriting, the settings are written into the inheriting node, where they can
        // be changed.* **After the changelog entry on purpose** — the node exists before it is
        // furnished, and each setting write journals itself ([D-403](../../../docs/NewConcept/90-decision-log.md)),
        // so the order in the log reads the way it happened.
        $this->materialise($parent, $node);

        return $node;
    }

    /**
     * Give a fresh node its own copy of what its parent resolves to.
     *
     * ⚠️ *Silently absent where no materialiser was handed in, which is the same contract the
     * settings and labels repositories above already have — a caller that only moves nodes about
     * gets the old sparse behaviour and nothing breaks.*
     */
    private function materialise(Node $parent, Node $child): void
    {
        if ($this->materialiser === null) {
            return;
        }

        $this->materialiser->materialise(
            $this->materialiser->chainFor($parent),
            $this->materialiser->chainFor($child)
        );
    }

    public function rename(int $id, string $name): Node
    {
        $node    = $this->nodes->byId($id);
        $renamed = $node->renamedTo($name);

        // Same instance means nothing changed, and an unchanged save does not raise the
        // version (D-282) — so there is nothing to write and nothing to log either.
        if ($renamed === $node) {
            return $node;
        }

        $this->nodes->save($renamed, $node->version);
        $this->changelog->record($id, 'node', 'renamed', $this->state($node), $this->state($renamed));

        return $renamed;
    }

    /**
     * Sagen, was Felder halten, die auf diesen Knoten zeigen — oder es wieder offenlassen.
     *
     * ⚠️ **[D-518](../../../docs/NewConcept/90-decision-log.md), seine Frage:** *«eine Option
     * erfinden, die sagt: ist Field oder ist Setting?»* — und **`null` ist der dritte Zustand**, nicht
     * das Fehlen einer Antwort: *«frag meine Vorfahren»*, derselbe Vorfahrenlauf, den
     * [D-516](../../../docs/NewConcept/90-decision-log.md) für den Typ gemessen hat.
     *
     * ⚠️ *Journalisiert wie ein Umbenennen, weil es eine Modelländerung ist: sie überlebt eine
     * Wanderung ([D-061](../../../docs/NewConcept/90-decision-log.md)) und jeder Bearbeiter sieht sie.
     * Ein Setzen, das nichts ändert, schreibt nichts und hebt keine Fassung
     * ([D-282](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ **Kein Wächter darüber, welcher Knoten das sein darf, und das ist Absicht.** *Die Frage «darf
     * ein Zweigwurzel eine Einstellung sein» ist nicht entschieden, und `PR-4` verbietet, sie hier
     * beiläufig zu beantworten. **Was gemessen schon feststeht: der Wert an einem Knoten trifft keine
     * andere Kante**, weil [D-516](../../../docs/NewConcept/90-decision-log.md) jeder Angabe ihren
     * eigenen Knoten gibt.*
     */
    public function setKind(int $id, ?NodeKind $kind): Node
    {
        $node   = $this->nodes->byId($id);
        $marked = $node->withKind($kind);

        if ($marked === $node) {
            return $node;
        }

        $this->nodes->save($marked, $node->version);
        $this->changelog->record(
            $id,
            'node',
            'kind set',
            $node->kind?->value,
            $marked->kind?->value
        );

        return $marked;
    }

    /**
     * Hide a node, or show it again.
     *
     * ⚠️ **A column and not a setting** ([D-426](../../../docs/NewConcept/90-decision-log.md),
     * [D-457](../../../docs/NewConcept/90-decision-log.md)). *That is what makes it impossible for
     * hiding a **type** to blank every field of that type — a column is not in the resolution chain,
     * «by construction rather than by a rule somebody has to remember».*
     *
     * ⚠️ **It means «render no further»** ([D-456](../../../docs/NewConcept/90-decision-log.md)) and it
     * is an **abort**: the walk stops before drawing this node and before looking for its children
     * ([D-450](../../../docs/NewConcept/90-decision-log.md)). *So the subtree disappears because it is
     * never reached — not because anything inherits, which is how it happened by accident before.*
     *
     * ⚠️ *Logged like a rename, because it is a model change: it survives a migration and every editor
     * sees it. An unchanged switch writes nothing and raises no version ([D-282](../../../docs/NewConcept/90-decision-log.md)).*
     */
    /**
     * Ein **Feld** verstecken oder wieder zeigen — an der Deklaration, wo es erklärt wurde.
     *
     * ⚠️ **[D-467](../../../docs/NewConcept/90-decision-log.md) hatte genau diesen Fall als Grund**,
     * und er war der einzige, den der Eigentümer selbst genannt hat: *«wo ich es sagen würde, ist an
     * den **Feldern** eines Modellknotens, wenn ich etwas nur im Hintergrund haben will, um damit zu
     * rechnen.»* **Die Spalte trägt es seit Schema 12, der Akt fehlte.**
     *
     * ⚠️ *Gemessen am 2026-08-30: von sieben versteckten Kanten sind **sieben Vererbungskanten** —
     * kein einziges Feld. Nicht weil niemand wollte, sondern weil es keinen Knopf gab.*
     *
     * ⚠️ **Nur an der eigenen Deklaration** ({@see ownAttribute()}): ein geerbtes Feld ist dieselbe
     * Kante, und sie hier zu verstecken hiesse, sie **überall** zu verstecken. *Das mag man wollen —
     * aber dann sagt man es dort, wo sie erklärt ist, und sieht dabei, wen es trifft.*
     */
    /**
     * Die Multiplizität eines Feldes setzen — **an der Kante**, seit [D-528](../../../docs/NewConcept/90-decision-log.md).
     *
     * ⚠️ **Kein Einschränken mehr und kein Vergleich mit einem Elternwert.** *Der Eigentümer:
     * «Multiplizität hängen wir als Spalte an die Kante, **sie kann in Zukunft nicht mehr überschrieben
     * werden**.» Damit gibt es keine Kette, gegen die etwas geprüft werden müsste — wer sie ändert,
     * ändert sie.*
     *
     * ⚠️ *Derselbe Akt-Rahmen wie {@see hideField()}: ein Journaleintrag mit Vorher und Nachher, und
     * ein Schreiben, das an der Version scheitert, wenn jemand dazwischenkam.*
     */
    public function setMultiplicity(int $ownerId, int $edgeId, Multiplicity $multiplicity): Relation
    {
        $edge     = $this->ownAttribute($ownerId, $edgeId);
        $geaendert = $edge->withMultiplicity($multiplicity);

        if ($geaendert === $edge) {
            return $edge;
        }

        $this->changelog->record(
            $edge->id,
            'relation',
            'multiplicity set',
            $this->edgeState($edge),
            $this->edgeState($geaendert),
            null,
            $geaendert->version
        );

        $this->relations->save($geaendert, $edge->version);

        return $geaendert;
    }

    public function hideField(int $ownerId, int $edgeId, ?bool $hide = null): Relation
    {
        $edge   = $this->ownAttribute($ownerId, $edgeId);
        $wanted = $hide ?? ! $edge->hide;
        $hidden = $edge->withHide($wanted);

        if ($hidden === $edge) {
            return $edge;
        }

        $this->changelog->record(
            $edge->id,
            'relation',
            $wanted ? 'field hidden' : 'field shown',
            $this->edgeState($edge),
            $this->edgeState($hidden)
        );

        $this->relations->save($hidden, $edge->version);

        return $hidden;
    }

    public function hidePlacement(int $nodeId, ?bool $hide = null): ?Relation
    {
        $edge = $this->relations->inheritanceEdgeTo($nodeId);

        // ⚠️ *The root has no inheritance edge, so it cannot be hidden — correct rather than a gap
        // ([D-194](../../../docs/NewConcept/90-decision-log.md)): it is machinery. Answering `null`
        // keeps the caller from having to know that.*
        if ($edge === null) {
            return null;
        }

        // ⚠️ *`null` means «the other way», which is what a switch in a tree row wants. An explicit
        // value is for callers that know the state they want — a data pack, a migration, a test.*
        $wanted = $hide ?? ! $edge->hide;
        $hidden = $edge->withHide($wanted);

        if ($hidden === $edge) {
            return $edge;
        }

        $this->relations->save($hidden, $edge->version);
        $this->changelog->record(
            $edge->id,
            'relation',
            $wanted ? 'hidden' : 'shown',
            $edge->hide ? '1' : '0',
            $wanted ? '1' : '0'
        );

        return $hidden;
    }

    /**
     * Give a node an attribute by pointing it at a target. **The kind is not a parameter.**
     *
     * ⚠️ **An attribute *is* a relation** (D-031) — two names for one thing, seen from the node
     * that owns it. And its **kind is read off the branch the target sits in** (D-161), never
     * chosen: the author picks *what* the attribute points at, and composition or aggregation
     * follows.
     *
     * ```mermaid
     * flowchart LR
     *   T["the target's branch"] -->|decides| K["the kind"]
     *   T -->|decides| D["whether it holds data"]
     *   T -->|decides| S["where a value is stored"]
     * ```
     *
     * **That is what removes the error the storage rule exists to prevent** — a supplier
     * accidentally composed into an order, so every order breeds its own supplier — not by
     * catching it afterwards but by never offering it.
     */
    /**
     * Copy a node beside itself — **the node, not its subtree and not its records.**
     *
     * The owner asked for it three times, the last one bluntly: *duplicating `my_int` does not work,
     * no button in the tree nor in the head of the settings.*
     *
     * ```mermaid
     * flowchart LR
     *   N["the node"] --> C["a sibling copy"]
     *   S["its own settings"] --> C
     *   A["its own attribute declarations"] --> C
     *   K["its children"] -.->|not copied| C
     *   R["its records"] -.->|not copied| C
     * ```
     *
     * ⚠️ **Nothing in the concept covered this**, so the scope is stated here rather than assumed:
     * every mention of «duplicate» in `docs/NewConcept/` is about duplicate **detection**
     * ([D-167](../../../docs/NewConcept/90-decision-log.md)), which is a different thing entirely.
     *
     * ⚠️ **Why the subtree is left out.** A copy of `Electronic Parts` that silently brought forty
     * descendants along is not a duplicate, it is an import — and the person who wanted *this node,
     * like that one* now has forty nodes to park. *The narrow act composes: duplicate, then move
     * children in. The wide one does not decompose.*
     *
     * ⚠️ **Why records are left out.** A record belongs to the model it was written against
     * ([D-060](../../../docs/NewConcept/90-decision-log.md)) — copying twenty of them onto a new model
     * would invent twenty facts nobody entered.
     *
     * ⚠️ **What *does* come along, and why each.** Its **own settings**, because a copy that resolves
     * differently from its original is not a copy. Its **own attribute declarations**, as new edges —
     * an attribute is an edge owned by the node ([D-031](../../../docs/NewConcept/90-decision-log.md)),
     * so there is nothing to share and a copy either declares its own or declares none. *Inherited
     * attributes are not copied because they were never here: the copy is a sibling, so it inherits
     * exactly what the original inherits.*
     *
     * ⚠️ *Names need no trick — [D-022](../../../docs/NewConcept/90-decision-log.md) makes them
     * explicitly **not unique**, so the copy simply carries the same name and the person renames it.*
     */
    public function duplicate(int $nodeId): Node
    {
        // ⚠️ **Ein Akt, eine Änderungsnummer** ([Zeile 45](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
        // *Ein Duplikat legt einen Knoten an, kopiert seine Felder, seine Settings und seine Labels — **fünf Schreibstellen, ein Akt.** Verschachtelte Klammern werden gezählt, also gewinnt die äußere — die,
        // die jemand tatsächlich ausgeführt hat.*
        $this->changelog->beginAct();

        try {
            return $this->duplicatedNode($nodeId);
        } finally {
            $this->changelog->endAct();
        }
    }

    private function duplicatedNode(int $nodeId): Node
    {
        $node = $this->nodes->byId($nodeId);

        // ⚠️ **The machinery's own nodes are not copyable** ([D-194]): a second `Trash` or a second
        // `Primitives` would give the framework two places to look and one of them would be wrong.
        if ($this->framework->isProtected($node)) {
            throw NodeIsProtected::named($node->name);
        }

        // ⚠️ *The root has no parent, so a copy would have nowhere to be a sibling of.*
        $parentId = $node->parentId()
            ?? throw NodeIsProtected::named($node->name);

        $copy = $this->createNode($node->name, $parentId);

        // ⚠️ **Its own declarations only**, which is what `ownAttribute()` already distinguishes: an
        // inherited attribute belongs to an ancestor and the copy inherits it too, by sitting where it
        // sits.
        //
        // ⚠️ **The pairs are kept, because a copy gets *new* edges** and everything the original said
        // *about* one of its attributes is addressed by that attribute's **edge id**
        // ([D-413](../../../docs/NewConcept/90-decision-log.md)). *Without the map those rows either
        // vanished or, restored naively, would have addressed the **original's** attributes — which
        // {@see \Taxmod\Core\Service\Settings::materialise()} names in its own docblock as «different
        // act, real problem, not this one». This is that act.*
        $newEdges = [];

        foreach ($this->fieldsOf($node->id) as $edge) {
            // ⚠️ **`fromId` is what «own» means** — the same test {@see ownAttribute()} makes. An
            // inherited edge belongs to an ancestor, and the copy inherits it by sitting where it
            // sits; declaring it again would give the subtree the same attribute twice.
            if ($edge->fromId !== $node->id) {
                continue;
            }

            $newEdges[$edge->id] = $this->addField($copy->id, $edge->toId, $edge->name)->id;
        }

        $this->copySettings($node->id, $copy->id, $newEdges);
        $this->copyLabels($node->id, $copy->id, $newEdges);

        return $copy;
    }

    /**
     * Copy one attribute beside itself — **and the name has to come in**, unlike a node's copy.
     *
     * The owner: *duplicate for the attribute is missing too.*
     *
     * ⚠️ **A node's copy may carry the same name and an attribute's may not.**
     * [D-022](../../../docs/NewConcept/90-decision-log.md) makes node names explicitly *not unique*,
     * so `duplicate()` reuses one. But [D-281](../../../docs/NewConcept/90-decision-log.md) refuses a
     * **duplicate edge** — same `from`, `kind`, `to` **and name** — because *`Breite` and `Höhe` both
     * reach `int` and are two different things; the name is part of what makes an edge itself.* **So a
     * copy with the same name is not a copy, it is the same edge, and the core refuses it.**
     *
     * ⚠️ **Which is why the name is a parameter and not derived here.** Inventing «Breite 2» would be
     * the core writing user-visible content, and a suffix like *(copy)* is a translatable string that
     * belongs at the boundary (`AR-2`, `CD-1`). *The surface knows what «copy» is called; this does
     * not.*
     *
     * ⚠️ **Its own settings travel with it**, for the same reason a node's do: a copy that resolves
     * differently from its original is not a copy. *Its labels do not, because an edge owns none —
     * measured 2026-08-26: 17 edges carry a name and zero labels belong to an edge
     * ([OQ-095](../../../docs/NewConcept/91-open-questions.md)).*
     */
    public function duplicateField(int $ownerId, int $edgeId, string $name): Relation
    {
        // ⚠️ **Ein Akt, eine Änderungsnummer** ([Zeile 45](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
        // *Dasselbe wie beim Knoten, eine Ebene tiefer. Verschachtelte Klammern werden gezählt, also gewinnt die äußere — die,
        // die jemand tatsächlich ausgeführt hat.*
        $this->changelog->beginAct();

        try {
            return $this->duplicatedField($ownerId, $edgeId, $name);
        } finally {
            $this->changelog->endAct();
        }
    }

    private function duplicatedField(int $ownerId, int $edgeId, string $name): Relation
    {
        // ⚠️ **`ownAttribute()` and not `fieldsOf()`**: an inherited attribute belongs to the
        // ancestor that declared it, and copying it from a descendant would put a second declaration
        // in a place that never had the first ([D-376](../../../docs/NewConcept/90-decision-log.md)
        // refuses renaming for the same reason).
        $edge = $this->ownAttribute($ownerId, $edgeId);

        $copy = $this->addField($ownerId, $edge->toId, $name);

        $this->copySettings($edge->id, $copy->id);

        return $copy;
    }

    /**
     * The original's **own** settings, onto the copy.
     *
     * ⚠️ **Own, not resolved** — and the difference is the whole point. Copying what the original
     * *resolves* would freeze its ancestors' answers into the copy, so a later change above would
     * reach the original and not the copy. *Copying only what it holds keeps both of them children
     * of the same parent, which is what a sibling copy is.*
     *
     * ⚠️ *Written straight to the repository rather than through {@see \Taxmod\Core\Service\Settings}:
     * the values were already accepted once at this exact place in the chain, so re-running the
     * bounds checks would refuse nothing and could refuse something — a bound the original was
     * narrowed **to** is not a widening for the copy.*
     *
     * ⚠️ **The `path` used to be dropped and that lost everything the original said about its
     * individual attributes** ([list row 43](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
     * *Found by the owner asking «the path is there via the node edges, isn't it?». The fourth
     * argument was simply not passed, so a copy kept the node's own values and none of its per-attribute
     * ones.*
     *
     * @param array<int, int> $edgeMap Original edge id ⇒ the copy's own new edge id.
     */
    private function copySettings(int $fromId, int $toId, array $edgeMap = []): void
    {
        if ($this->settings === null) {
            return;
        }

        foreach ($this->settings->ownedBy($fromId) as $one) {
            $this->settings->put(new SettingRecord($toId, $one->key, $one->value, $this->remapPath($one->path, $edgeMap)));
        }
    }

    /**
     * An address on the original, read as the same address on the copy.
     *
     * ⚠️ **An unmapped segment is kept rather than dropped, and that is the correct half of it.** *A
     * path may name an **inherited** edge, and a copy sitting under the same parent inherits **the
     * same edge** — the same id ([D-405](../../../docs/NewConcept/90-decision-log.md): an inherited
     * attribute *is* the same edge). So the address is already the copy's own address. Only the
     * original's **own** declarations get new ids, and only those are in the map.*
     *
     * ⚠️ *Split on `.` although nothing writes a multi-segment path today — a `path` is documented as
     * a **chain** of edge ids and `Node::$path` already spells a chain that way. **One line now, or a
     * silent half-remap the day the second segment arrives.***
     *
     * @param array<int, int> $edgeMap
     */
    private function remapPath(string $path, array $edgeMap): string
    {
        if ($path === '' || $edgeMap === []) {
            return $path;
        }

        $moved = array_map(
            static fn (string $segment): string => (string) ($edgeMap[(int) $segment] ?? $segment),
            explode('.', $path)
        );

        return implode('.', $moved);
    }

    /**
     * The original's labels, onto the copy — every role, every locale.
     *
     * ⚠️ *The owner spotted the settings half through the **icon**; labels are the same argument.
     * A copy whose name reads differently in German than its original is not a copy either.*
     *
     * ⚠️ **And the `path` fault was here too, one line over, unmentioned by the row that found it.**
     * *`labels.path` addresses a place the same way `settings.path` does — [D-413](../../../docs/NewConcept/90-decision-log.md)
     * says it is the same choice — and this method passed `$one->path` straight through. So a label
     * written **for one attribute** of the original arrived on the copy naming the **original's** edge.
     * **The same map fixes both, because it is one act.***
     *
     * @param array<int, int> $edgeMap Original edge id ⇒ the copy's own new edge id.
     */
    private function copyLabels(int $fromId, int $toId, array $edgeMap = []): void
    {
        if ($this->labels === null) {
            return;
        }

        foreach ($this->labels->forOwners([$fromId]) as $one) {
            $this->labels->put(new Label(
                $toId,
                $this->remapPath($one->path, $edgeMap),
                $one->roleId,
                $one->number,
                $one->locale,
                $one->text
            ));
        }
    }
    public function addField(int $ownerId, int $targetId, string $name): Relation
    {
        // ⚠️ **Ein Akt, eine Änderungsnummer** ([Zeile 45](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
        // *Ein Feld anlegen schreibt die Kante **und** materialisiert, was der Zieltyp sagt. Verschachtelte Klammern werden gezählt, also gewinnt die äußere — die,
        // die jemand tatsächlich ausgeführt hat.*
        $this->changelog->beginAct();

        try {
            return $this->addedField($ownerId, $targetId, $name);
        } finally {
            $this->changelog->endAct();
        }
    }

    private function addedField(int $ownerId, int $targetId, string $name): Relation
    {
        $owner  = $this->nodes->byId($ownerId);
        $target = $this->nodes->byId($targetId);

        $branch = $this->framework->branchOf($target)
            ?? throw NotAPossibleTarget::itSitsInNoBranch($target->name);

        // D-238: everything **but** the branch root is selectable. The root stands for the
        // branch itself, not for a thing in it.
        if ($target->id === $this->framework->rootOf($branch)->id) {
            throw NotAPossibleTarget::itIsABranchRoot($target->name);
        }

        if ($target->isDescendantOf($this->framework->trash())) {
            throw NotAPossibleTarget::itIsInTheTrash($target->name);
        }

        $edge = Relation::attribute(
            $this->identities->next(),
            $owner->id,
            $target->id,
            $branch->relationKind(),
            $name,
            $this->relations->nextFieldPositionUnder($owner->id)
        );

        $this->relations->add($edge);
        $this->changelog->record(
            $edge->id,
            'relation',
            'attribute added',
            null,
            sprintf('%s: %s → %s (%s)', $owner->name, $edge->name, $target->name, $edge->kind->value)
        );

        // ⚠️ **The target's settings are written into the attribute** ([D-423](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *when an attribute is created, **all** settings of the node are taken into the
        // attribute and can be changed there.* **The source is the *target*, not the owner** — the
        // type is what an attribute is configured like, and it is also what its `reset` pulls from,
        // so creation and reset agree by construction.
        if ($this->materialiser !== null) {
            $this->materialiser->materialise(
                $this->materialiser->chainFor($target),
                $this->materialiser->chainForUseSite($edge)
            );
        }

        return $edge;
    }

    /**
     * A node's attributes: its own, and every one it inherits.
     *
     * ⚠️ **Inheritance is why this takes the ancestors in one go.** The tree *is* inheritance
     * (D-041), so a node carries what its ancestors declare; asking per level would be the walk
     * `CD-7` forbids, and the path already holds the list.
     *
     * @return list<Relation>
     */
    public function fieldsOf(int $nodeId): array
    {
        $node = $this->nodes->byId($nodeId);

        return $this->relations->fieldEdgesOf([...$node->ancestorIds(), $node->id]);
    }

    /**
     * Who uses this node — the attributes of **other** nodes that are typed by it.
     *
     * ⚠️ **[D-199](../../../docs/NewConcept/90-decision-log.md), and it is one direction on purpose.**
     * The owner: *«everything going out of the current node is in the attributes. As long as that
     * stays so, we do not need to show them in the relations.»* **His condition is recorded with the
     * decision** — the section may hold one direction only *because every outgoing edge is currently
     * visible elsewhere*, and if an outgoing edge appears that is neither an attribute nor
     * inheritance, the section has to grow back. *Measured before building: the model holds three
     * kinds — 102 inheritance, 29 composition, 5 aggregation — and every composition and aggregation
     * edge carries a name, which is what makes it an attribute. The condition still holds.*
     *
     * ⚠️ **Direct incoming edges and not the descendants of this node.** *An attribute typed by an
     * **ancestor** accepts this node too, but deleting this node does not break it — and the section
     * is an impact estimate ([D-122](../../../docs/NewConcept/90-decision-log.md)). Widening it to
     * the ancestors would answer a question nobody asked.*
     *
     * ⚠️ *No ancestors on the way in either, so this is not the mirror of {@see fieldsOf()} in that
     * respect: **inherited** attributes are not repeated per descendant here, because the edge is
     * owned once and that one owner is what a person has to go and look at.*
     *
     * @return list<Relation>
     */
    public function usedBy(int $nodeId): array
    {
        return $this->relations->fieldEdgesTo([$nodeId]);
    }

    /**
     * Empty the trash for good — the act [row 10](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)
     * asks for, and the owner: *build a button behind the Trash label, «clear», so we can tidy up.*
     *
     * ⚠️ **What it keeps is the whole design, not an oversight.** The **identities** stay, because
     * [D-340](../../../docs/NewConcept/90-decision-log.md) says an id once handed out is never
     * reissued; the **changelog** stays, because [D-065](../../../docs/NewConcept/90-decision-log.md)
     * built it to *outlive what it refers to*. **So a purge removes the thing and keeps the record
     * that it existed.**
     *
     * ⚠️ **And it takes what belongs to a node with it**, which is
     * [row 28](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)'s rule from the
     * writing side: settings, labels, records and edges. *720 rows once belonged to owners that no
     * longer existed, because deleting a node used to take only the row called «node».*
     *
     * ⚠️ **The trash itself is never touched** — it is framework-protected
     * ([D-194](../../../docs/NewConcept/90-decision-log.md)) and it is the parent of what it holds, so
     * its own inheritance edges go with the children and it stays behind, empty.
     *
     * ```mermaid
     * flowchart LR
     *   T["Trash"] --> P["parked · everything under it"]
     *   P --> G["records · values · settings · labels · edges · nodes"]
     *   P --> K["identities · changelog<br/>stay"]
     * ```
     *
     * @return array<string, int> What went, keyed for a surface to report.
     */
    public function clearTrash(): array
    {
        $trash  = $this->framework->trash();
        $parked = $this->nodes->subtreeOf($trash);

        if ($parked === []) {
            return ['nodes' => 0, 'edges' => 0, 'settings' => 0, 'labels' => 0, 'records' => 0, 'values' => 0];
        }

        $ids   = array_map(static fn (Node $one): int => $one->id, $parked);
        $edges = [];

        foreach ($this->relations->edgesTouching($ids) as $edge) {
            $edges[] = $edge->id;
        }

        // ⚠️ **Order matters**: what points at something goes before what it points at, or a foreign
        // key refuses. *Settings and labels hang off both nodes and edges, so they go first of all.*
        $owners = [...$ids, ...$edges];

        // ⚠️ **Die Daten gehen mit, und das fehlte — [C102](../../../docs/NewConcept/10-domain-core.md)
        // durchgesetzt.** *Der Docblock über dieser Methode behauptete es seit dem Anfang («und es nimmt
        // mit, was zu einem Knoten gehört: settings, labels, **records** und edges», und das Diagramm
        // zeichnet «records · values»), **gelöscht wurden vier von sechs**. Geschrieben und nicht
        // gebaut, dasselbe Muster wie `hide` gespeichert-und-nie-gelesen
        // ([D-396](../../../docs/NewConcept/90-decision-log.md)) und der Container in
        // {@see Rendering::containerFor()} — **das dritte Mal am selben Tag.***
        //
        // ⚠️ **Der Eigentümer, auf seine eigene Regel hin:** *«solange noch eine Referenz da ist, kann
        // ein Knoten nicht endgültig gelöscht werden. Somit wäre ein Record ohne Knoten undenkbar. Und
        // wenn man ihn löschen will und das Risiko eingeht, dann müssen die Daten mitgelöscht werden …
        // **sonst weiss man ja auch gar nicht, wie dieser Record interpretiert werden soll.**»*
        //
        // ⚠️ *Vor den Knoten, aus demselben Grund wie settings und labels: was zeigt, geht vor dem,
        // worauf es zeigt.*
        $data = $this->records?->forgetNodes($ids) ?? ['records' => 0, 'values' => 0];

        $gone = [
            'settings' => $this->settings?->forgetOwners($owners) ?? 0,
            'labels'   => $this->labels?->forgetOwners($owners) ?? 0,
            'records'  => $data['records'],
            'values'   => $data['values'],
            'edges'    => count($edges),
            'nodes'    => count($ids),
        ];

        // ⚠️ **The children are read before anything is deleted, and getting that wrong cost a run.**
        // The first version purged the edges first — *and the edges are how a child of the trash is
        // found.* `childrenOf()` then returned nothing, `purgeSubtree()` was never called, and 53 nodes
        // stayed behind while the act reported them gone. **A tidy-up must not destroy its own map
        // before reading it.**
        //
        // ⚠️ *And the edge loop was not merely mis-ordered, it was redundant:
        // {@see NodeRepository::purgeSubtree()} deletes a subtree's edges **and** its nodes in two
        // statements. Its own comment says why the edges go first — «a relation row whose node is gone
        // is the dangling reference the whole two-stage deletion exists to avoid».*
        // ⚠️ **By path and not by edge, and measuring is what settled it.** `childrenOf()` reads the
        // **inheritance edges**, and a first run left **53 nodes standing**: under the trash sit nodes
        // whose edge was removed by an earlier raw-SQL tidy-up of mine, so an edge-walk cannot see them
        // at all. *`subtreeOf()` asks the materialised path, which is the truth about «under the trash»
        // — [D-014](../../../docs/NewConcept/90-decision-log.md) derives the path from the edges, and
        // when the two disagree the orphan is exactly what has to go.*
        //
        // ⚠️ *Each call deletes by path prefix, so overlapping subtrees cost a statement and change
        // nothing — no ordering by depth is needed.*
        foreach ($parked as $one) {
            $this->nodes->purgeSubtree($one);
        }

        // ⚠️ *One entry for the act, against the trash — the individual nodes keep their own history,
        // which is the point of keeping the changelog at all.*
        $this->changelog->record(
            $trash->id,
            'node',
            'trash cleared',
            sprintf('%d parked', count($ids)),
            sprintf(
                '%d nodes, %d edges, %d settings, %d labels, %d records, %d values',
                $gone['nodes'],
                $gone['edges'],
                $gone['settings'],
                $gone['labels'],
                $gone['records'],
                $gone['values']
            )
        );

        return $gone;
    }

    /** Hang a node under a different parent, taking everything below it along. */
    public function move(int $id, int $newParentId): Node
    {
        return $this->reparent($id, $this->nodes->byId($newParentId), 'moved');
    }

    /**
     * Park a node and everything under it.
     *
     * ⚠️ **The old path is written to the changelog and nowhere else.** That is what a restore
     * reads, and it is why the node itself needs no `parked_from` column — one place owns each
     * fact.
     */
    public function moveToTrash(int $id): Node
    {
        return $this->reparent($id, $this->framework->trash(), 'parked');
    }

    /**
     * Park **only** this node; its children are hung on its parent.
     *
     * ⚠️ **Not the harmless half of the choice** ([U4](../../../docs/NewConcept/20-interaction.md)).
     * The tree is inheritance (D-041), so children reattached to the grandparent **lose whatever
     * they inherited from the node being removed**. That is D-155's move reached through a
     * different button, and it is why deleting asks rather than guesses.
     */
    public function moveToTrashPromotingChildren(int $id): Node
    {
        $node = $this->nodes->byId($id);

        if ($this->framework->isProtected($node)) {
            throw NodeIsProtected::named($node->name);
        }

        $edge        = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();
        $grandparent = $this->nodes->byId($edge->fromId);

        // ⚠️ **One row per promoted child, not one row saying *the children moved*** (D-348).
        // A restore has to know **which** child went **where** to put it back, and *these
        // children moved* is not an answer. Read before the move, written in one statement.
        $promoted = [];

        foreach ($this->relations->childEdgesOf($id) as $childEdge) {
            $child = $this->nodes->find($childEdge->toId);

            if ($child === null) {
                continue;
            }

            $promoted[] = [
                'ownerId'   => $child->id,
                'ownerKind' => 'node',
                'what'      => 'promoted',
                'before'    => $child->path,
                'after'     => $grandparent->path . '.' . $child->id,
            ];
        }

        // Both halves in one statement each: the edges repoint together, and the paths of every
        // descendant are rewritten by dropping this node out of the middle of them. Done child
        // by child, either would be a write per row (`CD-7`).
        $this->relations->reparentChildEdges($id, $grandparent->id, $this->relations->nextPositionUnder($grandparent->id));
        $this->nodes->moveSubtree($node->path, $grandparent->path);

        // The bracket opens here and the parking joins it, so both are one act (D-348).
        $group = $this->changelog->recordMany($promoted);

        if ($promoted === []) {
            $group = null;
        }

        // The node is childless now, so parking it is the ordinary move.
        return $this->reparent($id, $this->framework->trash(), 'parked', $group);
    }

    /** Put a node at a different place among its siblings. Order lives on the edge, not the node. */
    public function reorder(int $id, int $position): void
    {
        $edge = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();
        $moved = $edge->movedTo(max(0, $position));

        if ($moved === $edge) {
            return;
        }

        $this->relations->save($moved, $edge->version);
        $this->changelog->record($id, 'node', 'reordered', (string) $edge->position, (string) $moved->position);
    }

    /**
     * Put a parked node back where it came from, with everything under it.
     *
     * ⚠️ **This is what makes the trash a trash rather than a graveyard.** *Undo reaches
     * exactly as far as the trash* (D-172), and without a way back the first stage of the
     * two-stage deletion buys nothing.
     *
     * **Where it came from is read out of the changelog and nowhere else** — no `parked_from`
     * column, because one place owns each fact (D-123, D-065).
     */
    public function restore(int $id): RestoreResult
    {
        $node  = $this->nodes->byId($id);
        $trash = $this->framework->trash();

        if (! $node->isDescendantOf($trash)) {
            throw CannotRestore::itWasNeverParked($node->name);
        }

        $was = $this->changelog->pathBeforeLastParking($id);

        if ($was === null) {
            throw CannotRestore::theOldPlaceIsGone($node->name);
        }

        $segments = explode('.', $was);
        array_pop($segments);
        $parent = $segments === [] ? null : $this->nodes->find((int) end($segments));

        if ($parent === null) {
            throw CannotRestore::theOldPlaceIsGone($node->name);
        }

        // Restoring into the trash would look like success and change nothing.
        if ($parent->id === $trash->id || $parent->isDescendantOf($trash)) {
            throw CannotRestore::theOldPlaceIsAlsoParked($node->name, $parent->name);
        }

        // The whole act comes back, not the row (D-347). The bracket is what makes *the whole
        // act* nameable at all (D-348) — without it there is only a list of unrelated lines.
        $act      = $this->changelog->actAround($id, 'parked');
        $restored = $this->reparent($id, $parent, 'restored');

        $back = [];
        $left = [];

        foreach ($act as $row) {
            if ($row['what'] !== 'promoted') {
                continue;
            }

            $child = $this->nodes->find($row['ownerId']);

            // ⚠️ Untouched means *still exactly where the promotion put it*. Anything else is a
            // newer decision by a person, and it wins.
            if ($child === null || $child->path !== $row['after']) {
                if ($child !== null) {
                    $left[] = $child->name;
                }

                continue;
            }

            $this->reparent($child->id, $restored, 'restored');
            $back[] = $child->name;
        }

        return new RestoreResult($restored, $back, $left);
    }

    /** Swap a node with the sibling before it. Does nothing if it is already first. */
    public function moveUp(int $id): void
    {
        $this->swapWithNeighbour($id, -1);
    }

    /** Swap a node with the sibling after it. Does nothing if it is already last. */
    public function moveDown(int $id): void
    {
        $this->swapWithNeighbour($id, 1);
    }

    /**
     * Move an attribute among the attributes its owner declares.
     *
     * ⚠️ **The owner, 2026-08-26: *the attribute row should have up and down buttons like the nodes in
     * the tree.*** And his reason for expecting it to be shared: *«`position` is part of node and also
     * part of edge, that is why I had moved it into the `Identity` class — which we do not have.»*
     *
     * ⚠️ **Measured, and the answer is better than the expectation: `position` lives *only* on the
     * edge.** `Node` carries `id`, `version`, `name`, `path` and no position at all — **a node's order
     * among its siblings is its *inheritance edge's* position**, which is
     * [D-014](../../../docs/NewConcept/90-decision-log.md) working as designed: *the tree **is** the
     * edges.* So this is not a fact waiting for a shared base class; **it is the same column, reached
     * through a different sibling list**, and {@see self::swapWithNeighbour()} was already doing it for
     * nodes.
     *
     * ```mermaid
     * flowchart LR
     *   N["a node"] --> I["its inheritance edge · position"]
     *   A["an attribute"] --> E["its own edge · position"]
     *   I --> S["one swap"]
     *   E --> S
     * ```
     *
     * ⚠️ *Only among the attributes **declared here** ([D-376](../../../docs/NewConcept/90-decision-log.md)):
     * an inherited one belongs to an ancestor, and reordering it from a descendant would reorder it
     * for everybody.*
     */
    public function moveField(int $ownerId, int $edgeId, int $direction): void
    {
        $own = [];

        foreach ($this->relations->fieldEdgesOf([$ownerId]) as $edge) {
            if ($edge->fromId === $ownerId) {
                $own[] = $edge;
            }
        }

        foreach ($own as $edge) {
            if ($edge->id === $edgeId) {
                $this->swapAmong($edge, $own, $direction);

                return;
            }
        }
    }


    /** The node with this id, or null. Used by surfaces that may be handed a stale link. */
    /**
     * The nodes a list of attributes points at, in one query (`CD-7`).
     *
     * @param  list<Relation>   $edges
     * @return array<int, Node> Keyed by node id.
     */
    public function targetsOf(array $edges): array
    {
        return $this->nodes->byIds(array_map(static fn (Relation $edge): int => $edge->toId, $edges));
    }

    /**
     * The other end — the nodes these edges come **from**, in one query (`CD-7`).
     *
     * ⚠️ *What {@see usedBy()} needs to be readable: an incoming attribute means nothing without the
     * node that owns it, and asking per edge would be the loop the code standard forbids.*
     *
     * @param  list<Relation>   $edges
     * @return array<int, Node> Keyed by id.
     */
    public function ownersOf(array $edges): array
    {
        return $this->nodes->byIds(array_map(static fn (Relation $edge): int => $edge->fromId, $edges));
    }

    /**
     * One of a node's **own** attributes, by edge id.
     *
     * ⚠️ **Ownership is checked here rather than trusted from the request.** An inherited edge
     * belongs to an ancestor, and writing to it would change it for every sibling too.
     *
     * @throws \Taxmod\Core\Exception\NotAPossibleTarget
     */
    public function ownAttribute(int $ownerId, int $edgeId): Relation
    {
        foreach ($this->fieldsOf($ownerId) as $edge) {
            if ($edge->id === $edgeId && $edge->fromId === $ownerId) {
                return $edge;
            }
        }

        throw NotAPossibleTarget::notAnOwnField($edgeId);
    }

    /**
     * Remove an attribute — **parked, not purged**, and under one bracket.
     *
     * ⚠️ **This was missing since Package 3, and the reason was storage rather than reluctance:**
     * `relations` had nowhere to record that an edge was gone. [D-371](../../../docs/NewConcept/90-decision-log.md)
     * gives it `parked_by_group_id` — the **act** that parked it, not a bare flag, so
     * [D-128](../../../docs/NewConcept/90-decision-log.md)'s *deleted with «X»* has something to
     * name.
     *
     * ⚠️ **Two stages, as everywhere** ([D-123](../../../docs/NewConcept/90-decision-log.md)):
     * parking is reversible and purging is a separate act. **So nothing else is touched** — the
     * settings written at this use site stay, and so do the values records hold through it. *That is
     * not laziness: [D-156](../../../docs/NewConcept/90-decision-log.md) observes that the trash
     * preserves exactly the information a later decision needs, and deleting the overrides here
     * would throw away what a restore has to put back.*
     *
     * ⚠️ **One changelog row, one group** ([D-348](../../../docs/NewConcept/90-decision-log.md)).
     * Today the act is a single row, so the bracket is the row's own id — which is exactly what that
     * decision prescribes, and what makes the bracket cost nothing.
     */
    public function removeField(int $ownerId, int $edgeId): Relation
    {
        // ⚠️ **The parked ones are looked at too, and that is not tidiness.** Once parked, an edge
        // leaves the live list (D-128), so a second click — a double tap, a back button, a stale
        // form — would otherwise be refused with *not one this node owns*, which is both wrong and
        // confusing. It **is** owned; it is already gone. So the act is idempotent.
        foreach ($this->relations->parkedFieldEdgesOf([$ownerId]) as $already) {
            if ($already->id === $edgeId) {
                return $already;
            }
        }

        $edge = $this->ownAttribute($ownerId, $edgeId);

        $group = $this->changelog->record(
            $edge->id,
            'relation',
            'attribute removed',
            $this->edgeState($edge),
            $this->edgeState($edge->parkedBy(0))
        );

        $parked = $edge->parkedBy($group);

        $this->relations->save($parked, $edge->version);

        return $parked;
    }

    /**
     * Put a removed attribute back.
     *
     * ⚠️ **A new change written forwards, never a rewind** ([D-172](../../../docs/NewConcept/90-decision-log.md)):
     * history is extended, because the changelog is also the migration script
     * ([D-061](../../../docs/NewConcept/90-decision-log.md)).
     */
    public function restoreField(int $ownerId, int $edgeId): Relation
    {
        foreach ($this->relations->parkedFieldEdgesOf([$ownerId]) as $edge) {
            if ($edge->id !== $edgeId) {
                continue;
            }

            $revived = $edge->revived();

            $this->changelog->record(
                $edge->id,
                'relation',
                'attribute restored',
                $this->edgeState($edge),
                $this->edgeState($revived)
            );

            $this->relations->save($revived, $edge->version);

            return $revived;
        }

        throw NotAPossibleTarget::notAnOwnField($edgeId);
    }

    /**
     * Rename an attribute.
     *
     * ⚠️ **Only where it is declared.** An inherited attribute belongs to the ancestor that
     * declared it, so renaming it from a descendant would rename it for every other user too —
     * silently. {@see ownAttribute()} refuses that, which is the same guard removal uses.
     */
    /**
     * Ein Feld auf ein anderes Ziel zeigen lassen — **ohne seine Werte zu verlieren**.
     *
     * ⚠️ **Es gab das nicht, und der Fall, der es verlangt, ist seiner:** *«ich möchte dennoch
     * exponent als Setting umwandeln.»* Dafür muss `Prefixes.exponent` von `Integer` auf einen
     * spezialisierten Typ zeigen — und **an der Kanten-Id hängen 20 gespeicherte Exponenten**
     * (`settings.path = 4654`). Entfernen und neu anlegen gäbe eine neue Id und liesse sie hinter
     * sich.
     *
     * ⚠️ *Dieselben Wächter wie beim Anlegen ({@see addField()}): das Ziel muss in einem Zweig
     * liegen, darf keine Zweigwurzel und nicht im Müll sein. **Und die Art wird neu abgelesen**, weil
     * der Zweig sie bestimmt ([D-497](../../../docs/NewConcept/90-decision-log.md)) — ein Ziel in
     * einem anderen Zweig macht aus einer Aggregation eine Komposition.*
     */
    /**
     * Sagen, ob dieses Feld eine **Einstellung** erklärt — oder es wieder zurücknehmen.
     *
     * ⚠️ **[D-526](../../../docs/NewConcept/90-decision-log.md).** *Die Marke sass vorher am
     * Zielknoten und zwang damit zu Typknoten, deren einziger Zweck es war, sie tragen zu können.
     * **An der Kante genügt eine Angabe je Deklaration — und weil ein geerbtes Feld dieselbe Kante
     * ist, gilt sie sofort für alle Erben**, ohne Vorfahrenlauf.*
     *
     * ⚠️ **Beim Zurücknehmen fragt der Ast wieder, was er immer gesagt hätte.** *`Setting` ist die
     * einzige Art, die nicht vom Ort abgelesen wird — also muss das Zurücknehmen sie neu ablesen und
     * darf nicht raten, welche es vorher war.*
     */
    public function markAsSetting(int $ownerId, int $edgeId, bool $isSetting): Relation
    {
        $edge = $this->ownAttribute($ownerId, $edgeId);

        if ($isSetting) {
            $art = RelationKind::Setting;
        } else {
            $target = $this->nodes->byId($edge->toId);
            $branch = $this->framework->branchOf($target)
                ?? throw NotAPossibleTarget::itSitsInNoBranch($target->name);

            $art = $branch->relationKind();
        }

        $marked = $edge->withKind($art);

        if ($marked === $edge) {
            return $edge;
        }

        $this->changelog->record(
            $edge->id,
            'relation',
            $isSetting ? 'field became a setting' : 'setting became a field',
            $this->edgeState($edge),
            $this->edgeState($marked)
        );

        $this->relations->save($marked, $edge->version);

        return $marked;
    }

    public function retargetField(int $ownerId, int $edgeId, int $targetId): Relation
    {
        $edge   = $this->ownAttribute($ownerId, $edgeId);
        $target = $this->nodes->byId($targetId);

        $branch = $this->framework->branchOf($target)
            ?? throw NotAPossibleTarget::itSitsInNoBranch($target->name);

        if ($target->id === $this->framework->rootOf($branch)->id) {
            throw NotAPossibleTarget::itIsABranchRoot($target->name);
        }

        if ($target->isDescendantOf($this->framework->trash())) {
            throw NotAPossibleTarget::itIsInTheTrash($target->name);
        }

        $moved = $edge->retargetedTo($targetId, $branch->relationKind());

        if ($moved === $edge) {
            return $edge;
        }

        $this->changelog->record(
            $edge->id,
            'relation',
            'field retargeted',
            $this->edgeState($edge),
            $this->edgeState($moved)
        );

        $this->relations->save($moved, $edge->version);

        return $moved;
    }

    public function renameField(int $ownerId, int $edgeId, string $name): Relation
    {
        $edge    = $this->ownAttribute($ownerId, $edgeId);
        $renamed = $edge->renamedTo($name);

        $this->changelog->record(
            $edge->id,
            'relation',
            'attribute renamed',
            $this->edgeState($edge),
            $this->edgeState($renamed)
        );

        $this->relations->save($renamed, $edge->version);

        return $renamed;
    }

    /**
     * Die aufgelöste Sorte des **Ziels** je Kante — womit ein Feld in seinen Block gehört.
     *
     * ⚠️ *Am Ziel und nicht an der Kante, auf sein Wort ([D-518](../../../docs/NewConcept/90-decision-log.md)):
     * «der Knoten bekommt eine zusätzliche Spalte». **Ein Feld ist eine Einstellung, weil das, worauf es
     * zeigt, eine ist** — und seit [D-516](../../../docs/NewConcept/90-decision-log.md) hat jede Angabe
     * ihren eigenen Zielknoten, also trifft die Markierung nichts anderes mit.*
     *
     * @param  list<Relation>          $edges
     * @return array<int, NodeKind>    Je Kanten-Id genau ein Eintrag.
     */
    /**
     * Die aufgelöste Sorte je Knoten — für den Wähler, der zeigen muss, **was geerbt würde**.
     *
     * @param  list<int>            $ids
     * @return array<int, NodeKind>
     */
    public function kindsOfNodes(array $ids): array
    {
        return $this->nodes->resolvedKinds($ids);
    }

    public function kindsOfTargets(array $edges): array
    {
        if ($edges === []) {
            return [];
        }

        $sorten = $this->nodes->resolvedKinds(array_map(static fn (Relation $e): int => $e->toId, $edges));

        $je = [];

        foreach ($edges as $edge) {
            $je[$edge->id] = $sorten[$edge->toId] ?? NodeKind::standard();
        }

        return $je;
    }

    /** @return list<Relation> The removed attributes of one node — D-128's *show deleted*. */
    public function removedFieldsOf(int $ownerId): array
    {
        return $this->relations->parkedFieldEdgesOf([$ownerId]);
    }

    /**
     * What a changelog row records about an edge.
     *
     * ⚠️ *`name` last, for the same reason it is last in {@see state()}: an attribute's name may hold
     * a space, and a field behind it could not be told apart from the name.*
     */
    private function edgeState(Relation $edge): string
    {
        return FrozenState::of([
            'to'     => $edge->toId,
            'kind'   => $edge->kind->value,
            'parked' => $edge->parkedByGroup ?? 0,
            'name'   => $edge->name,
        ])->write();
    }

    public function find(int $id): ?Node
    {
        return $this->nodes->find($id);
    }

    /** @return list<Node> */
    public function childrenOf(int $parentId): array
    {
        return $this->nodes->childrenOf($this->nodes->byId($parentId));
    }

    /**
     * Exchange two neighbouring edges' positions.
     *
     * ⚠️ **A swap, not a renumbering.** Reordering by rewriting every sibling would be a write
     * per row, which is the loop `CD-7` forbids; a swap is always exactly two, however many
     * siblings there are.
     */
    private function swapWithNeighbour(int $id, int $direction): void
    {
        $edge     = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();
        $siblings = $this->relations->childEdgesOf($edge->fromId);

        $this->swapAmong($edge, $siblings, $direction, $id, 'node');
    }

    /**
     * Swap one edge with its neighbour in a given list — the whole of reordering, for both callers.
     *
     * ⚠️ **Extracted rather than copied** ([D-435](../../../docs/NewConcept/90-decision-log.md)): a
     * node reorders its **inheritance** edge among its parent's children, an attribute reorders **its
     * own** edge among the attributes its owner declares. *Two sibling lists, one column, one swap —
     * and a second copy of the equal-positions trick below is exactly how the two would drift.*
     *
     * @param list<Relation> $siblings In the order the list is drawn.
     */
    private function swapAmong(Relation $edge, array $siblings, int $direction, ?int $subject = null, string $kind = 'relation'): void
    {

        $here = null;

        foreach ($siblings as $index => $sibling) {
            if ($sibling->id === $edge->id) {
                $here = $index;
                break;
            }
        }

        $there = $here + $direction;

        if ($here === null || ! isset($siblings[$there])) {
            return;
        }

        $other = $siblings[$there];

        // Positions may be equal — nothing forbids it, and the list then falls back to id
        // order. Swapping equal numbers would move nothing, so they are forced apart.
        $mine  = $edge->position;
        $yours = $other->position;

        if ($mine === $yours) {
            $mine  = $here;
            $yours = $there;
        }

        $this->relations->save($edge->movedTo($yours), $edge->version);
        $this->relations->save($other->movedTo($mine), $other->version);

        $this->changelog->record($subject ?? $edge->id, $kind, 'reordered', (string) $here, (string) $there);
    }

    /**
     * Change which parent an edge points at, then bring the paths along.
     *
     * ⚠️ **The order is not arbitrary.** The edge is the truth, so it moves first; the paths of
     * the node and everything under it are rewritten from it afterwards, in one statement
     * rather than one per descendant (`CD-7`).
     */
    private function reparent(int $id, Node $newParent, string $verb, ?int $changeGroup = null): Node
    {
        $node = $this->nodes->byId($id);

        if ($this->framework->isProtected($node)) {
            throw NodeIsProtected::named($node->name);
        }

        $edge = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();

        // A node dropped onto its own descendant would cut its whole subtree out of the tree,
        // silently. The path already answers this — that is what a materialised path is for.
        if ($newParent->id === $node->id || $newParent->isDescendantOf($node)) {
            throw ImpossibleMove::intoItsOwnDescendant($node->name);
        }

        $moved = $node->movedUnder($newParent->path);

        if ($moved === $node) {
            return $node;
        }

        $this->relations->save(
            $edge->reparentedTo($newParent->id, $this->relations->nextPositionUnder($newParent->id)),
            $edge->version
        );

        $this->nodes->save($moved, $node->version);
        $this->nodes->moveSubtree($node->path, $moved->path);
        $this->changelog->record($id, 'node', $verb, $this->state($node), $this->state($moved), $changeGroup);

        return $moved;
    }

    /**
     * What the changelog freezes about a node at one moment.
     *
     * Deliberately the four fixed attributes and nothing else — the changelog records what the
     * object *was*, and a node is exactly those four things.
     *
     * ⚠️ **`name` moved to the end, and that is the whole change.** *A name may contain a space —
     * measured, **844** existing rows do — so it is the one field that cannot have another field
     * behind it. {@see FrozenState} refuses the old order outright rather than writing a row whose
     * `path` has to be dug out with a search from the right. **Both orders read the same**, which the
     * boundary check asserts against every row in the table rather than in principle.*
     */
    private function state(Node $node): string
    {
        return FrozenState::of([
            'id'      => $node->id,
            'version' => $node->version,
            'path'    => $node->path,
            'name'    => $node->name,
        ])->write();
    }
}
