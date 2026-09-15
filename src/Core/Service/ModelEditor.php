<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\CannotRestore;
use Taxmod\Core\Exception\ClassNotAllowedUnder;
use Taxmod\Core\Exception\ImpossibleMove;
use Taxmod\Core\Exception\MultiplicityNotAllowed;
use Taxmod\Core\Exception\UnknownNodeClass;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Exception\NodeIsProtected;
use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Model\FrozenState;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Repository\LabelRepository;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * Everything a person can do to the shape of the model: make a node, rename it, move it,
 * reorder it among its siblings, throw it away.
 *
 * ⚠️ **The tree is the inheritance relations; `path` is derived from them** (D-014). Every operation
 * here changes the relation first and rewrites the path afterwards. Writing the path alone would
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
        private readonly FrameworkNodes $framework,
        private readonly Changelog $changelog,
        // ⚠️ **Only `duplicate()` uses this, and that is why it is optional.** A copy has to
        // resolve exactly like its original or it is not a copy — which means its **own** labels
        // travel with it. *Everything else in this service moves nodes and relations around and has
        // no business reading them.*
        //
        // ⚠️ *Hier stand daneben ein `SettingRepository`. Die `settings`-Tabelle ist mit
        // [D-579](../../../docs/NewConcept/90-decision-log.md) gestrichen; was eine Kopie an
        // Angaben mitnimmt, steht seit [D-529](../../../docs/NewConcept/90-decision-log.md) im
        // Datensatz und hängt an den Kanten, die `duplicate()` ohnehin mitkopiert.*
        private readonly ?LabelRepository $labels = null,
        // ⚠️ *Hier stand der `Settings`-Dienst als **Materialisierer**
        // ([D-423](../../../docs/NewConcept/90-decision-log.md)): ein neuer Knoten bekam das, was
        // sein Elternteil **auflöst**, als eigene Zeilen geschrieben. **Das war die Kette, und die
        // Kette ist mit der Tabelle gestrichen** ([D-579](../../../docs/NewConcept/90-decision-log.md)).
        // *Ein geerbtes Feld ist seit [D-526](../../../docs/NewConcept/90-decision-log.md) **dieselbe**
        // Kante — es gibt nichts mehr zu kopieren, damit der Erbe dasselbe sieht.*
        // ⚠️ **Nur `clearTrash()` braucht es, und es fehlte — deshalb überlebten Records ihren Knoten.**
        // *Optional aus demselben Grund wie die drei darüber: die achtzehn Stellen, die diesen Dienst
        // bauen, verschieben meist nur Knoten und hätten sonst eine Abhängigkeit zu erklären, die sie
        // nie benutzen.*
        private readonly ?RecordRepository $records = null,
        /** ⚠️ *Nur `pushFieldToChildren()` liest es: die Einstellungen der alten Stelle gehen auf die neuen Kanten über (D-750).* */
        private readonly ?\Taxmod\Core\Repository\SettingsRepository $settings = null,
    ) {
    }

    /**
     * @param class-string<\Taxmod\Core\Model\NodeClass\NodeClass>|null $klasse Die Knotenklasse — oder
     *        `null` für die Vorwahl der Vaterklasse ([D-716](../../../docs/NewConcept/90-decision-log.md),
     *        Anforderung 2.2.3).
     */
    public function createNode(string $name, int $parentId, ?string $klasse = null): Node
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
            return $this->createdNode($name, $parentId, $klasse);
        } finally {
            $this->changelog->endAct();
        }
    }

    private function createdNode(string $name, int $parentId, ?string $klasse): Node
    {
        $parent = $this->nodes->byId($parentId);

        // ⚠️ **Die Klasse kommt vom Vater, wenn niemand eine wählt — und nur eine, die er erlaubt**
        // ([D-716](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «er sagt, mein kind darf
        // diese und jene klassen tragen, und es gibt einen default, der bei anlage des kindes
        // vorgewählt wird.» Beides steht im Vertrag der Vaterklasse und nirgends sonst.*
        $vertrag = Contracts::of($parent->klasse);
        $klasse ??= $vertrag->defaultChildClass;

        if (! Contracts::isKnown($klasse)) {
            throw UnknownNodeClass::named($klasse);
        }

        if (! $vertrag->allowsChild($klasse)) {
            throw ClassNotAllowedUnder::parent($klasse, $parent->klasse);
        }

        // ⚠️ **Zwei Ids aus zwei Räumen** — seit TASK-004 vergibt jede Tabelle ihre eigene
        // ([`package.md` §6](../../../docs/pakete/modelltabellen/package.md)). *Eine Kante bleibt ein
        // Ding erster Ordnung, das eigene Einstellungen und Labels trägt (C8); nur die Nummer kommt
        // nicht mehr aus einem geteilten Topf. **`0` heisst «vergib eine»**, und der Speicher gibt
        // die geschriebene Zeile mit ihrer Nummer zurück.*
        // ⚠️ *Eine Zeile statt zweier, seit TASK-018* ([D-581](../../../docs/NewConcept/90-decision-log.md)).
        // *Vater und Stelle kommen **mit** dem Knoten; vorher folgte gleich danach eine
        // Vererbungskante, und zwischen den beiden Schreibvorgängen war der Knoten kurz nirgends.*
        $node = $this->nodes->add(Node::create(
            0,
            $name,
            $parent->path,
            $parent->id,
            $this->nodes->nextPositionUnder($parent->id),
            $klasse
        ));
        $this->changelog->record($node->id, 'node', 'created', null, $this->state($node), $node->version);

        // ⚠️ *Hier stand `materialise($parent, $node)` — [D-423](../../../docs/NewConcept/90-decision-log.md)s
        // Kopie dessen, was das Elternteil in der `settings`-Tabelle **auflöste**. Die Tabelle ist
        // mit [D-579](../../../docs/NewConcept/90-decision-log.md) gestrichen, und damit auch die
        // Kette, aus der kopiert wurde.*
        return $node;
    }

    /**
     * Die Klasse eines Knotens wechseln — auf eine, die der Vater erlaubt und die jedes vorhandene Kind erlaubt
     * ([D-733](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort am 2026-09-12: «wir müssen typ wechsel möglich
     * machen ist lästig immer den knoten zu löschen und wieder anzulegen, die einstellungen die nicht übereinstimmen
     * gehen dabei verloren».* Die Einstellungen, die der neue Vertrag nicht erklärt, räumt der
     * {@see SettingsEditor::dropWhatDoesNotApply()} — der Rand ruft beide nacheinander.
     */
    public function changeClass(int $id, string $klasse): Node
    {
        $node = $this->nodes->byId($id);

        if (! Contracts::isKnown($klasse)) {
            throw UnknownNodeClass::named($klasse);
        }

        $parent = $node->parentNodeId === null ? null : $this->nodes->byId($node->parentNodeId);

        if ($parent !== null && ! Contracts::of($parent->klasse)->allowsChild($klasse)) {
            throw ClassNotAllowedUnder::parent($klasse, $parent->klasse);
        }

        $vertrag = Contracts::of($klasse);

        foreach ($this->childrenOf($id) as $kind) {
            if (! $vertrag->allowsChild($kind->klasse)) {
                throw ClassNotAllowedUnder::parent($kind->klasse, $klasse);
            }
        }

        $neu = $node->ofClass($klasse);

        if ($neu === $node) {
            return $node;
        }

        $this->nodes->save($neu, $node->version);
        $this->changelog->record($id, 'node', 'class changed', $this->state($node), $this->state($neu), $neu->version);

        return $neu;
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
        $this->changelog->record($id, 'node', 'renamed', $this->state($node), $this->state($renamed), $renamed->version);

        return $renamed;
    }

    /**
     * Sagen, welche PHP-Klasse diesen Knoten umsetzt — oder dass es keine gibt (TASK-008).
     *
     * ⚠️ **Der Klassenname steht im Knoten, auf sein Wort:** *«wenn das ohne Factory geht, weil der
     * Klassenname da drinsteht, perfekt.»* *Damit sagt der Knoten selbst, dass er der
     * Schieber-Renderer ist — statt einer WordPress-Option ausserhalb des Modells (`AR-1`,
     * TASK-009).*
     *
     * ⚠️ *Journalisiert wie ein Umbenennen, und aus demselben Grund: es ist eine Modelländerung.
     * Ein Setzen, das nichts ändert, schreibt nichts und hebt keine Fassung
     * ([D-282](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ **Geprüft wird hier nicht, ob es die Klasse gibt** — *das täte der Kern zur Ladezeit des
     * Aufrufers und würde eine Saat abbrechen lassen, weil eine Klasse gerade umbenannt wurde. **Der
     * Ausgleich ist der Wächter**, `implemented-by-check.php`, der genau diese Frage stellt, ohne
     * jemandem die Arbeit zu blockieren.*
     */
    public function setImplementedBy(int $id, ?string $className): Node
    {
        $node   = $this->nodes->byId($id);
        $marked = $node->implementedBy($className);

        if ($marked === $node) {
            return $node;
        }

        $this->nodes->save($marked, $node->version);
        $this->changelog->record(
            $id,
            'node',
            'implemented by',
            $node->implementedBy,
            $marked->implementedBy,
            $marked->version
        );

        return $marked;
    }

    /**
     * Der Knoten, den diese PHP-Klasse umsetzt — oder `null` (TASK-009).
     *
     * ⚠️ **Das ist der Weg, der die WordPress-Optionen ablöst.** *`taxmod_render_renderer_slider_id`
     * hielt die Bindung «welcher Knoten ist der Schieber-Renderer» **ausserhalb** des Modells, gegen
     * [`AR-1`](../../../CLAUDE.md). Jetzt steht sie im Knoten, und dies ist die Frage danach.*
     *
     * ⚠️ *Ein Knoten im Müll zählt nicht: eine Saat ist danach gewöhnlicher Inhalt
     * ([D-119](../../../docs/NewConcept/90-decision-log.md)), und wer den Schieber weggeworfen hat,
     * soll ihn nicht durch die Hintertür zurückbekommen. **Ein Umzug innerhalb des Modells gilt** —
     * genau die Unterscheidung, an der die Saat am 2026-08-31 vierundzwanzig Knoten doppelt angelegt
     * hat.*
     */
    public function nodeImplementing(string $className): ?Node
    {
        $node = $this->nodes->byImplementations([$className])[$className] ?? null;

        if ($node === null) {
            return null;
        }

        $muell = $this->framework->trash();

        return $node->id === $muell->id || $node->isDescendantOf($muell) ? null : $node;
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
    public function setMultiplicity(int $ownerId, int $relationId, Multiplicity $multiplicity): Relation
    {
        $relation     = $this->ownAttribute($ownerId, $relationId);
        $geaendert = $relation->withMultiplicity($multiplicity);

        if ($geaendert === $relation) {
            return $relation;
        }

        // ⚠️ **Die Zielklasse darf einschränken, und nur sie** (Modell 1.2.3, [D-713](../../../docs/NewConcept/90-decision-log.md)):
        // *«bool = 1..1, an knotenklasse bool.»* Die Kantenklasse schränkt nichts ein.
        $ziel = $this->nodes->find($relation->toNodeId);

        if ($ziel !== null && ! Contracts::of($ziel->klasse)->allowsMultiplicity($multiplicity)) {
            throw MultiplicityNotAllowed::byTarget($multiplicity->value, Contracts::of($ziel->klasse)->key);
        }

        $this->changelog->record(
            $relation->id,
            'relation',
            'multiplicity set',
            $this->relationState($relation),
            $this->relationState($geaendert),
            $geaendert->version
        );

        $this->relations->save($geaendert, $relation->version);

        return $geaendert;
    }

    /**
     * Ein Feld an dieser Stelle nur lesbar machen oder wieder freigeben — eine Spalte der Kante
     * ([D-714](../../../docs/NewConcept/90-decision-log.md)), derselbe Akt-Rahmen wie {@see setMultiplicity()}.
     */
    /** «Eindeutig» an der Kante setzen ([D-735](../../../docs/NewConcept/90-decision-log.md)) — dieselbe Form wie {@see self::setReadOnly()}. */
    public function setUnique(int $ownerId, int $relationId, bool $unique): Relation
    {
        $relation  = $this->ownAttribute($ownerId, $relationId);
        $geaendert = $relation->withUnique($unique);

        if ($geaendert === $relation) {
            return $relation;
        }

        $this->changelog->record($relation->id, 'relation', 'unique set', $relation->unique ? '1' : '0', $geaendert->unique ? '1' : '0', $geaendert->version);
        $this->relations->save($geaendert, $relation->version);

        return $geaendert;
    }

    public function setReadOnly(int $ownerId, int $relationId, bool $readOnly): Relation
    {
        $relation  = $this->ownAttribute($ownerId, $relationId);
        $geaendert = $relation->withReadOnly($readOnly);

        if ($geaendert === $relation) {
            return $relation;
        }

        $this->changelog->record(
            $relation->id,
            'relation',
            'read only set',
            $relation->readOnly ? '1' : '0',
            $geaendert->readOnly ? '1' : '0',
            $geaendert->version
        );

        $this->relations->save($geaendert, $relation->version);

        return $geaendert;
    }

    public function hideField(int $ownerId, int $relationId, ?bool $hide = null): Relation
    {
        $relation   = $this->ownAttribute($ownerId, $relationId);
        $wanted = $hide ?? ! $relation->hide;
        $hidden = $relation->withHide($wanted);

        if ($hidden === $relation) {
            return $relation;
        }

        $this->changelog->record(
            $relation->id,
            'relation',
            $wanted ? 'field hidden' : 'field shown',
            $this->relationState($relation),
            $this->relationState($hidden),
            $hidden->version
        );

        $this->relations->save($hidden, $relation->version);

        return $hidden;
    }

    /**
     * ⚠️ **Gibt seit TASK-018 einen `Node` zurück und keine `Relation`**
     * ([D-581](../../../docs/NewConcept/90-decision-log.md)). *`hide` sass auf der Vererbungskante,
     * und mit ihr sind **alle** seine Benutzer umgezogen — die Angabe steht jetzt am Knoten.*
     */
    public function hidePlacement(int $nodeId, ?bool $hide = null): ?Node
    {
        $node = $this->nodes->find($nodeId);

        // ⚠️ *The root has no placement, so it cannot be hidden — correct rather than a gap
        // ([D-194](../../../docs/NewConcept/90-decision-log.md)): it is machinery. Answering `null`
        // keeps the caller from having to know that.*
        if ($node === null || $node->parentNodeId === null) {
            return null;
        }

        // ⚠️ *`null` means «the other way», which is what a switch in a tree row wants. An explicit
        // value is for callers that know the state they want — a data pack, a migration, a test.*
        $wanted   = $hide ?? ! $node->hide;
        $versteckt = $node->withHide($wanted);

        if ($versteckt === $node) {
            return $node;
        }

        $this->nodes->save($versteckt, $node->version);
        $this->changelog->record(
            $node->id,
            'node',
            $wanted ? 'hidden' : 'shown',
            $node->hide ? '1' : '0',
            $wanted ? '1' : '0',
            $versteckt->version
        );

        return $versteckt;
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
     * differently from its original is not a copy. Its **own attribute declarations**, as new relations —
     * an attribute is an relation owned by the node ([D-031](../../../docs/NewConcept/90-decision-log.md)),
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

        // ⚠️ *Die Kopie ist, was das Original ist — dieselbe Klasse, und der Vater erlaubt sie schon.*
        $copy = $this->createNode($node->name, $parentId, $node->klasse);

        // ⚠️ **Its own declarations only**, which is what `ownAttribute()` already distinguishes: an
        // inherited attribute belongs to an ancestor and the copy inherits it too, by sitting where it
        // sits.
        //
        // ⚠️ **The pairs are kept, because a copy gets *new* relations** and everything the original said
        // *about* one of its attributes is addressed by that attribute's **relation id**
        // ([D-413](../../../docs/NewConcept/90-decision-log.md)). *Without the map those rows either
        // vanished or, restored naively, would have addressed the **original's** attributes — which
        // {@see \Taxmod\Core\Service\Settings::materialise()} names in its own docblock as «different
        // act, real problem, not this one». This is that act.*
        $newRelations = [];

        foreach ($this->fieldsOf($node->id) as $relation) {
            // ⚠️ **`fromNodeId` is what «own» means** — the same test {@see ownAttribute()} makes. An
            // inherited relation belongs to an ancestor, and the copy inherits it by sitting where it
            // sits; declaring it again would give the subtree the same attribute twice.
            if ($relation->fromNodeId !== $node->id) {
                continue;
            }

            // ⚠️ **Die Art der Vorlage reist mit** (TASK-053). *Vorher las die Kopie sie am Zielast
            // neu ab — **und eine Einstellungskante kam als Komposition heraus**, weil `setting` die
            // einzige Art ist, die kein Ast hergibt ([D-618](../../../docs/NewConcept/90-decision-log.md)).
            // Eine Kopie, deren Kanten anders heissen als die des Originals, ist keine.*
            $kopie = $this->addField($copy->id, $relation->toNodeId, $relation->name, $relation->kind);

            // ⚠️ **Die Spalten der Kante kommen mit** ([D-713](../../../docs/NewConcept/90-decision-log.md),
            // [D-714](../../../docs/NewConcept/90-decision-log.md)): *eine Kopie, die anders auflöst
            // als ihr Original, ist keine Kopie — und «wie oft» und «nur lesbar» sind seit Schritt 2
            // des Bauplans Spalten, keine Einstellungen, die der Satz mitbrächte.*
            if ($relation->multiplicity !== $kopie->multiplicity) {
                $kopie = $this->setMultiplicity($copy->id, $kopie->id, $relation->multiplicity);
            }

            if ($relation->readOnly) {
                $kopie = $this->setReadOnly($copy->id, $kopie->id, true);
            }

            $newRelations[$relation->id] = $kopie->id;
        }

        $this->copyLabels($node->id, $copy->id, $newRelations);

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
     * **duplicate relation** — same `from`, `kind`, `to` **and name** — because *`Breite` and `Höhe` both
     * reach `int` and are two different things; the name is part of what makes an relation itself.* **So a
     * copy with the same name is not a copy, it is the same relation, and the core refuses it.**
     *
     * ⚠️ **Which is why the name is a parameter and not derived here.** Inventing «Breite 2» would be
     * the core writing user-visible content, and a suffix like *(copy)* is a translatable string that
     * belongs at the boundary (`AR-2`, `CD-1`). *The surface knows what «copy» is called; this does
     * not.*
     *
     * ⚠️ **Its own settings travel with it**, for the same reason a node's do: a copy that resolves
     * differently from its original is not a copy. *Its labels do not, because an relation owns none —
     * measured 2026-08-26: 17 relations carry a name and zero labels belong to an relation
     * ([OQ-095](../../../docs/NewConcept/91-open-questions.md)).*
     */
    public function duplicateField(int $ownerId, int $relationId, string $name): Relation
    {
        // ⚠️ **Ein Akt, eine Änderungsnummer** ([Zeile 45](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
        // *Dasselbe wie beim Knoten, eine Ebene tiefer. Verschachtelte Klammern werden gezählt, also gewinnt die äußere — die,
        // die jemand tatsächlich ausgeführt hat.*
        $this->changelog->beginAct();

        try {
            return $this->duplicatedField($ownerId, $relationId, $name);
        } finally {
            $this->changelog->endAct();
        }
    }

    private function duplicatedField(int $ownerId, int $relationId, string $name): Relation
    {
        // ⚠️ **`ownAttribute()` and not `fieldsOf()`**: an inherited attribute belongs to the
        // ancestor that declared it, and copying it from a descendant would put a second declaration
        // in a place that never had the first ([D-376](../../../docs/NewConcept/90-decision-log.md)
        // refuses renaming for the same reason).
        $relation = $this->ownAttribute($ownerId, $relationId);

        // ⚠️ **Die Art der Vorlage reist mit** (TASK-053) — derselbe Grund wie bei der Kopie eines
        // Knotens: eine Einstellungskante, die als Komposition zurückkommt, ist keine Kopie.
        $copy = $this->addField($ownerId, $relation->toNodeId, $name, $relation->kind);

        return $copy;
    }

    /*
     * Hier stand `remapPath()` — eine Adresse am Original, gelesen als dieselbe Adresse an der
     * Kopie.
     *
     * **Sie faellt mit `labels.path` (TASK-019, D-580).** Der umgedrehte Verweis gibt einer
     * Beschriftung genau einen Eigentuemer und keine Stelle darin; es gibt keine Adresse mehr, die
     * umzuschreiben waere. *Gemessen trug keine der 52 Beschriftungen einen Pfad.*
     */

    /**
     * The original's labels, onto the copy — every role, every locale.
     *
     * ⚠️ *The owner spotted the settings half through the **icon**; labels are the same argument.
     * A copy whose name reads differently in German than its original is not a copy either.*
     *
     * ⚠️ **And the `path` fault was here too, one line over, unmentioned by the row that found it.**
     * *`labels.path` addresses a place the same way `settings.path` does — [D-413](../../../docs/NewConcept/90-decision-log.md)
     * says it is the same choice — and this method passed `$one->path` straight through. So a label
     * written **for one attribute** of the original arrived on the copy naming the **original's** relation.
     * **The same map fixes both, because it is one act.***
     *
     * @param array<int, int> $relationMap Original relation id ⇒ the copy's own new relation id.
     */
    private function copyLabels(int $fromNodeId, int $toNodeId, array $relationMap = []): void
    {
        if ($this->labels === null) {
            return;
        }

        // ⚠️ *Beide Seiten sind Knoten — eine Kopie eines Knotens (Fassung 31, `INF-035`).*
        foreach ($this->labels->forOwners([$fromNodeId], IdentitySpace::Node) as $one) {
            // ⚠️ *Seit TASK-019 gibt es keinen Pfad mehr an einer Beschriftung
            // ([D-580](../../../docs/NewConcept/90-decision-log.md)); `$relationMap` wird hier
            // deshalb nicht mehr gebraucht.*
            $this->labels->put(new Label(
                $toNodeId,
                IdentitySpace::Node,
                $one->role,
                $one->number,
                $one->locale,
                $one->text
            ));
        }
    }
    /**
     * Ein Feld anlegen — **und die Art wird genannt, nicht geraten**
     * ([D-618](../../../docs/NewConcept/90-decision-log.md), TASK-053).
     *
     * ⚠️ **Sein Wort:** *«der benutzer legt fest, automation machen wir später aber auch nur
     * vielleicht».* **Seit [D-639](../../../docs/NewConcept/90-decision-log.md) gibt es genau drei
     * Arten mit je einer Klasse** — `setting`, `aggregation`, `composition` —, und `$kind` wählt
     * unter diesen dreien.
     *
     * ⚠️ **Warum das die vorsichtigere Hälfte ist:** *ein Akt, der rät, ist schwerer zu prüfen als
     * einer, dem man es sagt — und geraten wurde hier an einem Tag zweimal falsch. Der Code legte
     * `Renderer --converter--> Converter` als Aggregation an, obwohl das Ziel im Einstellungsast
     * liegt, und es fiel nur seinem Blick auf.*
     *
     * ⚠️ **`null` heisst «niemand hat es gesagt», und dann liest der Akt weiter den Zielast** —
     * *der Rückfall für Saatgut, Gerüste und Wächter, die keine Benutzer sind. **Die Maske gibt die
     * Art immer an.** Ob die ~140 übrigen Aufrufstellen ihre Art nennen sollen, ist eine Aufgabe und
     * steht im Eingang als `INF-052`; geraten wird sie nicht (`PR-4`).*
     */
    public function addField(int $ownerId, int $targetId, string $name, ?RelationKind $kind = null): Relation
    {
        // ⚠️ **Ein Akt, eine Änderungsnummer** ([Zeile 45](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
        // *Ein Feld anlegen schreibt die Kante **und** materialisiert, was der Zieltyp sagt. Verschachtelte Klammern werden gezählt, also gewinnt die äußere — die,
        // die jemand tatsächlich ausgeführt hat.*
        $this->changelog->beginAct();

        try {
            return $this->addedField($ownerId, $targetId, $name, $kind);
        } finally {
            $this->changelog->endAct();
        }
    }

    private function addedField(int $ownerId, int $targetId, string $name, ?RelationKind $kind): Relation
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

        $relation = Relation::attribute(
            // ⚠️ *`0` heisst «die Tabelle vergibt sie» (TASK-004).*
            0,
            $owner->id,
            $target->id,
            // ⚠️ **Die Angabe gewinnt, der Ast ist nur noch der Rückfall** (TASK-053,
            // [D-618](../../../docs/NewConcept/90-decision-log.md)).
            $kind ?? $branch->relationKind(),
            $name,
            $this->relations->nextFieldPositionUnder($owner->id)
        );

        $relation = $this->relations->add($relation);
        $this->changelog->record(
            $relation->id,
            'relation',
            'attribute added',
            null,
            sprintf('%s: %s → %s (%s)', $owner->name, $relation->name, $target->name, $relation->kind->value),
            $relation->version
        );

        // ⚠️ *Hier wurden dem neuen Feld die Angaben seines **Ziels** als eigene Zeilen
        // hineingeschrieben ([D-423](../../../docs/NewConcept/90-decision-log.md)). Mit der
        // `settings`-Tabelle ist auch das gestrichen ([D-579](../../../docs/NewConcept/90-decision-log.md)).*
        return $relation;
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
        $node   = $this->nodes->byId($nodeId);
        $zeilen = $this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($node));

        // ⚠️ **In der Reihenfolge, die an diesem Knoten gilt** ([D-698](../../../docs/NewConcept/90-decision-log.md)):
        // *ein Kind darf geerbte Felder anordnen; steht nichts, gilt die Reihenfolge der Besitzer.*
        return $this->fieldOrder()?->orderedAt($nodeId, $zeilen) ?? $zeilen;
    }

    /** Die Anordnung braucht die Sätze — ohne sie gilt die Reihenfolge der Besitzer. */
    public function fieldOrder(): ?FieldOrder
    {
        return $this->records === null ? null : new FieldOrder($this->records, $this->relations, $this->nodes, $this->framework);
    }

    /**
     * Die Kante mit dieser Nummer, oder `null`.
     *
     * ⚠️ **Weil eine Kantennummer eine Adresse ist** ([D-667](../../../docs/NewConcept/90-decision-log.md):
     * *«Adressiert wird über die letzte Kante»*). *Der Rand bekommt sie aus einem Formular und muss
     * sie nachschlagen können, ohne zu raten — **eine Nummer aus einer Eingabe wird gefunden, nicht
     * geglaubt.***
     */
    public function relationById(int $id): ?Relation
    {
        return $this->relations->byId($id);
    }

    /**
     * Who uses this node — the attributes of **other** nodes that are typed by it.
     *
     * ⚠️ **[D-199](../../../docs/NewConcept/90-decision-log.md), and it is one direction on purpose.**
     * The owner: *«everything going out of the current node is in the attributes. As long as that
     * stays so, we do not need to show them in the relations.»* **His condition is recorded with the
     * decision** — the section may hold one direction only *because every outgoing relation is currently
     * visible elsewhere*, and if an outgoing relation appears that is neither an attribute nor
     * inheritance, the section has to grow back. *Measured before building: the model holds three
     * kinds — 102 inheritance, 29 composition, 5 aggregation — and every composition and aggregation
     * relation carries a name, which is what makes it an attribute. The condition still holds.*
     *
     * ⚠️ **Direct incoming relations and not the descendants of this node.** *An attribute typed by an
     * **ancestor** accepts this node too, but deleting this node does not break it — and the section
     * is an impact estimate ([D-122](../../../docs/NewConcept/90-decision-log.md)). Widening it to
     * the ancestors would answer a question nobody asked.*
     *
     * ⚠️ *No ancestors on the way in either, so this is not the mirror of {@see fieldsOf()} in that
     * respect: **inherited** attributes are not repeated per descendant here, because the relation is
     * owned once and that one owner is what a person has to go and look at.*
     *
     * @return list<Relation>
     */
    public function usedBy(int $nodeId): array
    {
        return $this->relations->fieldRelationsTo([$nodeId]);
    }

    /**
     * Empty the trash for good — the act [row 10](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)
     * asks for, and the owner: *build a button behind the Trash label, «clear», so we can tidy up.*
     *
     * ⚠️ **What it keeps is the whole design, not an oversight.** The **changelog** stays, because
     * [D-065](../../../docs/NewConcept/90-decision-log.md) built it to *outlive what it refers to*.
     * **So a purge removes the thing and keeps the record that it existed.**
     *
     * ⚠️ **Seit TASK-004 gibt es keine `identities`-Zeile mehr, die eine verbrauchte Nummer
     * festhielte.** *Was [D-340](../../../docs/NewConcept/90-decision-log.md) verlangt — eine einmal
     * vergebene Id wird nie wieder vergeben —, hält jetzt das `AUTO_INCREMENT` der jeweiligen
     * Tabelle: InnoDB senkt den Zähler beim Löschen nicht.*
     *
     * ⚠️ **And it takes what belongs to a node with it**, which is
     * [row 28](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)'s rule from the
     * writing side: settings, labels, records and relations. *720 rows once belonged to owners that no
     * longer existed, because deleting a node used to take only the row called «node».*
     *
     * ⚠️ **The trash itself is never touched** — it is framework-protected
     * ([D-194](../../../docs/NewConcept/90-decision-log.md)) and it is the parent of what it holds, so
     * its own inheritance relations go with the children and it stays behind, empty.
     *
     * ```mermaid
     * flowchart LR
     *   T["Trash"] --> P["parked · everything under it"]
     *   P --> G["records · values · settings · labels · relations · nodes"]
     *   P --> K["changelog<br/>bleibt"]
     * ```
     *
     * ⚠️ **Und es geht auch für eine Auswahl** (TASK-039). *`$nur` nennt die Knoten, die geräumt werden
     * sollen — samt allem, was unter ihnen liegt; alles andere im Papierkorb bleibt unberührt. **Der
     * Grund ist gemessener Schaden:** ein Wächterlauf rief diese Methode ohne Auswahl und löschte
     * `DisplayOption` des Eigentümers endgültig, den er selbst geparkt hatte — 190 Datensätze standen
     * danach ohne Knoten da. Die Zusage des Papierkorbs lautet «geparkt, nicht gelöscht»; **wer nur
     * seinen eigenen Unrat wegräumen will, muss das sagen können, statt den ganzen Korb zu leeren.**
     * *Ohne Angabe bleibt es der Akt hinter dem Knopf: der Papierkorb wird ganz geleert.*
     *
     * @param  list<int>|null    $nur Nur diese geparkten Knoten (mit ihren Unterbäumen); `null` = alles.
     * @return array<string, int> What went, keyed for a surface to report.
     */
    public function clearTrash(?array $nur = null): array
    {
        $trash  = $this->framework->trash();
        $parked = $this->nodes->subtreeOf($trash);

        if ($nur !== null) {
            $gewaehlt = array_values(array_filter(
                $parked,
                static fn (Node $one): bool => in_array($one->id, $nur, true)
            ));

            // ⚠️ *Ein gewählter Knoten nimmt mit, was unter ihm liegt — sonst bliebe ein Kind stehen,
            // dessen Elternteil weg ist. Über den Pfad, nicht über die Kanten: `purgeSubtree()` löscht
            // ohnehin nach Pfad-Präfix, und die beiden dürfen nicht auseinanderlaufen.*
            $parked = array_values(array_filter(
                $parked,
                static function (Node $one) use ($gewaehlt): bool {
                    foreach ($gewaehlt as $eigen) {
                        if ($one->id === $eigen->id || str_starts_with($one->path, $eigen->path . '.')) {
                            return true;
                        }
                    }

                    return false;
                }
            ));
        }

        if ($parked === []) {
            return ['nodes' => 0, 'relations' => 0, 'labels' => 0, 'records' => 0, 'values' => 0];
        }

        $ids   = array_map(static fn (Node $one): int => $one->id, $parked);
        $relations = [];

        foreach ($this->relations->relationsTouching($ids) as $relation) {
            $relations[] = $relation->id;
        }

        // ⚠️ **Order matters**: what points at something goes before what it points at, or a foreign
        // key refuses. *Settings and labels hang off both nodes and relations, so they go first of all.*
        //
        // ⚠️ **Hier stand eine gemischte Liste `[...$ids, ...$relations]`, und sie war die Falle aus
        // `INF-035`** (Fassung 31, [D-597](../../../docs/NewConcept/90-decision-log.md)): *Knoten- und
        // Kantennummern in einem Topf, an eine Ablage gereicht, die den Raum nicht kannte. **Eine
        // gelöschte Kante hätte damit die Beschriftungen eines gleichnummerigen, gesunden Knotens
        // mitgenommen.** Jetzt sind es zwei Fragen, jede mit ihrem Raum.*

        // ⚠️ **Die Daten gehen mit, und das fehlte — [C102](../../../docs/NewConcept/10-domain-core.md)
        // durchgesetzt.** *Der Docblock über dieser Methode behauptete es seit dem Anfang («und es nimmt
        // mit, was zu einem Knoten gehört: settings, labels, **records** und relations», und das Diagramm
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

        // ⚠️ **Die Einstellungszeilen gehen mit — sonst bleibt der Knoten still liegen.** *Gemessen am 2026-09-15: seit jeder neue Knoten
        // seinen Renderer als Zeile trägt ([D-808](../../../docs/NewConcept/90-decision-log.md)), verweigerte der Fremdschlüssel
        // `taxmod_sv_node` das Löschen, und `$wpdb` meldete es nur ins Protokoll. Die eigenen Zeilen der Knoten und die, die auf sie zeigen.*
        // *Ein Objekt, das danach keine lebende Zeile mehr nennt (der Renderer des Knotens), geht mit — sonst stünde es verwaist da.*
        $objekte = [];

        foreach ($this->settings === null ? [] : [...array_merge([], ...array_values($this->settings->valuesOfNodes($ids))), ...$this->settings->valuesReferring($ids)] as $zeile) {
            $this->settings->forgetValue($zeile->id);

            if ($zeile->valueObjectId !== null) {
                $objekte[$zeile->valueObjectId] = true;
            }
        }

        if ($this->settings !== null && $objekte !== []) {
            $genannt = [];

            foreach ($this->settings->valuesNamingObjects(array_keys($objekte)) as $zeile) {
                $genannt[(int) $zeile->valueObjectId] = true;
            }

            foreach (array_diff_key($objekte, $genannt) as $objektId => $_) {
                $this->settings->forgetObject($objektId);
            }
        }

        $gone = [
            'labels'   => ($this->labels?->forgetOwners($ids, IdentitySpace::Node) ?? 0)
                + ($this->labels?->forgetOwners($relations, IdentitySpace::Relation) ?? 0),
            'records'  => $data['records'],
            'values'   => $data['values'],
            'relations'    => count($relations),
            'nodes'    => count($ids),
        ];

        // ⚠️ **The children are read before anything is deleted, and getting that wrong cost a run.**
        // The first version purged the relations first — *and the relations are how a child of the trash is
        // found.* `childrenOf()` then returned nothing, `purgeSubtree()` was never called, and 53 nodes
        // stayed behind while the act reported them gone. **A tidy-up must not destroy its own map
        // before reading it.**
        //
        // ⚠️ *And the relation loop was not merely mis-ordered, it was redundant:
        // {@see NodeRepository::purgeSubtree()} deletes a subtree's relations **and** its nodes in two
        // statements. Its own comment says why the relations go first — «a relation row whose node is gone
        // is the dangling reference the whole two-stage deletion exists to avoid».*
        // ⚠️ **By path and not by relation, and measuring is what settled it.** `childrenOf()` reads the
        // **inheritance relations**, and a first run left **53 nodes standing**: under the trash sit nodes
        // whose relation was removed by an earlier raw-SQL tidy-up of mine, so an relation-walk cannot see them
        // at all. *`subtreeOf()` asks the materialised path, which is the truth about «under the trash»
        // — [D-014](../../../docs/NewConcept/90-decision-log.md) derives the path from the relations, and
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
                '%d nodes, %d relations, %d labels, %d records, %d values',
                $gone['nodes'],
                $gone['relations'],
                $gone['labels'],
                $gone['records'],
                $gone['values']
            ),
            // ⚠️ **Hier gibt es keine Version, und das ist ein Befund, keine Bequemlichkeit**
            // ([D-634](../../../docs/NewConcept/90-decision-log.md), `PR-4`): *dieser Eintrag steht
            // gegen den Papierkorb, der sich selbst gar nicht ändert; die Änderung besteht aus
            // hunderten entfernten Zeilen mit je eigener Version. Eine davon auszusuchen wäre eine
            // erfundene Zahl. `scripts/dev/version-check.php` kennt dieses Verb namentlich.*
            null
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
     *
     * ⚠️ **`$withUses` ist die Antwort auf eine Frage, die der Rand gestellt hat**
     * ([D-604](../../../docs/NewConcept/90-decision-log.md), TASK-037). *Sein Wort: «ein Knoten, der
     * verwendet wird, darf nicht einfach so gelöscht werden. Es muss einen Dialog für den Benutzer
     * geben, der fragt, ob die Verwendungen mitgelöscht werden sollen. Bei ja müssen diese auch in
     * den Papierkorb wandern, bei nein haben wir Leichen im Baum.»* **Der Kern rät hier nicht** — er
     * bekommt gesagt, was der Benutzer bestätigt hat, und `false` ist ausdrücklich die Hälfte, die
     * Leichen zurücklässt, nicht ein Versehen.
     *
     * ⚠️ **Was mitgeht, sind die Kanten, nicht die Datensätze.** *Komposition ist nach
     * [D-639](../../../docs/NewConcept/90-decision-log.md) eine Aussage über **Datensätze** — «wenn
     * ich den Datensatz von Kunde A lösche, muss auch die Adresse von Kunde A gelöscht werden» — und
     * nicht über Knoten. Ein geparkter Knoten nimmt darum die **Verwendungsstellen** mit, und jede
     * geparkte Kante nimmt ihre Wertzeilen mit ([D-619](../../../docs/NewConcept/90-decision-log.md)).
     * Beides ist umkehrbar; erst das endgültige Leeren ist es nicht.*
     */
    public function moveToTrash(int $id, bool $withUses = false): Node
    {
        // ⚠️ **Ein Akt, eine Änderungsnummer.** *Parken und die Verwendungen, die mitgehen, sind
        // **eine** Sache, die jemand bestätigt hat — verschachtelte Klammern werden gezählt, also
        // gewinnt die äussere, die der Rand schon geöffnet hat.*
        $this->changelog->beginAct();

        try {
            if ($withUses) {
                // ⚠️ **Erst die Verwendungen, dann der Knoten.** *{@see usedBy()} liest die lebenden
                // Kanten; wäre der Knoten schon im Papierkorb, stünden sie noch genauso da — aber die
                // Reihenfolge hält das Änderungsbuch lesbar: zuerst fällt, was auf ihn zeigte.*
                foreach ($this->usedBy($id) as $use) {
                    $this->removeField($use->fromNodeId, $use->id);
                }
            }

            return $this->reparent($id, $this->framework->trash(), 'parked');
        } finally {
            $this->changelog->endAct();
        }
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

        $grandparent = $this->nodes->byId($node->parentNodeId ?? throw ImpossibleMove::ofTheRoot());

        // ⚠️ **One row per promoted child, not one row saying *the children moved*** (D-348).
        // A restore has to know **which** child went **where** to put it back, and *these
        // children moved* is not an answer. Read before the move, written in one statement.
        $promoted = [];

        foreach ($this->nodes->childrenOf($node) as $child) {
            $promoted[] = [
                'ownerId'   => $child->id,
                'ownerKind' => 'node',
                'what'      => 'promoted',
                'before'    => $child->path,
                'after'     => $grandparent->path . '.' . $child->id,
                // ⚠️ *Die Zeile wird gleich umgehängt, und {@see NodeRepository::reparentChildren()}
                // zählt dabei `version = version + 1` — die Version, die diese Änderung erzeugt, ist
                // also die nächste. Gelesen wird vorher, weil danach der alte Pfad weg wäre.*
                'version'   => $child->version + 1,
            ];
        }

        // Both halves in one statement each: the children repoint together, and the paths of every
        // descendant are rewritten by dropping this node out of the middle of them. Done child
        // by child, either would be a write per row (`CD-7`).
        //
        // ⚠️ *Zwei Anweisungen auf **dieselbe** Tabelle, seit TASK-018 — vorher traf die erste
        // `relations` und die zweite `nodes`. Die Reihenfolge bleibt trotzdem: erst umhängen, dann
        // die Pfade nachziehen, sonst zieht der zweite Lauf Pfade nach, die noch nicht gelten.*
        $this->nodes->reparentChildren($id, $grandparent->id, $this->nodes->nextPositionUnder($grandparent->id));
        $this->nodes->moveSubtree($node->path, $grandparent->path);

        // The bracket opens here and the parking joins it, so both are one act (D-348).
        $group = $this->changelog->recordMany($promoted);

        if ($promoted === []) {
            $group = null;
        }

        // The node is childless now, so parking it is the ordinary move.
        return $this->reparent($id, $this->framework->trash(), 'parked', $group);
    }

    /**
     * Put a node at a different place among its siblings.
     *
     * ⚠️ *Die Reihenfolge steht seit TASK-018 **am Knoten**, nicht an der Kante
     * ([D-581](../../../docs/NewConcept/90-decision-log.md)) — sein «`sort_order` wandert an den
     * Knoten».*
     */
    public function reorder(int $id, int $sortOrder): void
    {
        $node = $this->nodes->byId($id);

        if ($node->parentNodeId === null) {
            throw ImpossibleMove::ofTheRoot();
        }

        $moved = $node->movedTo(max(0, $sortOrder));

        if ($moved === $node) {
            return;
        }

        $this->nodes->save($moved, $node->version);
        $this->changelog->record($id, 'node', 'reordered', (string) $node->sortOrder, (string) $moved->sortOrder, $moved->version);
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
     * part of relation, that is why I had moved it into the `Identity` class — which we do not have.»*
     *
     * ⚠️ **Measured, and the answer is better than the expectation: `position` lives *only* on the
     * relation.** `Node` carries `id`, `version`, `name`, `path` and no position at all — **a node's order
     * among its siblings is its *inheritance relation's* position**, which is
     * [D-014](../../../docs/NewConcept/90-decision-log.md) working as designed: *the tree **is** the
     * relations.* So this is not a fact waiting for a shared base class; **it is the same column, reached
     * through a different sibling list**, and {@see self::swapWithNeighbour()} was already doing it for
     * nodes.
     *
     * ```mermaid
     * flowchart LR
     *   N["a node"] --> I["its inheritance relation · position"]
     *   A["an attribute"] --> E["its own relation · position"]
     *   I --> S["one swap"]
     *   E --> S
     * ```
     *
     * ⚠️ *Only among the attributes **declared here** ([D-376](../../../docs/NewConcept/90-decision-log.md)):
     * an inherited one belongs to an ancestor, and reordering it from a descendant would reorder it
     * for everybody.*
     */
    public function moveField(int $ownerId, int $relationId, int $direction): void
    {
        $own = [];

        foreach ($this->relations->fieldRelationsOf([$ownerId]) as $relation) {
            if ($relation->fromNodeId === $ownerId) {
                $own[] = $relation;
            }
        }

        $this->swapAmong(
            $relationId,
            array_map(
                static fn (Relation $e): array => ['id' => $e->id, 'sortOrder' => $e->sortOrder, 'version' => $e->version],
                $own
            ),
            $direction,
            'relation',
            function (int $wen, int $stelle, int $erwartet): int {
                $kante = ($this->relations->byId($wen) ?? throw ImpossibleMove::ofTheRoot())->movedTo($stelle);
                $this->relations->save($kante, $erwartet);

                return $kante->version;
            }
        );
    }

    /** The node with this id, or null. Used by surfaces that may be handed a stale link. */
    /**
     * The nodes a list of attributes points at, in one query (`CD-7`).
     *
     * @param  list<Relation>   $relations
     * @return array<int, Node> Keyed by node id.
     */
    public function targetsOf(array $relations): array
    {
        return $this->nodes->byIds(array_map(static fn (Relation $relation): int => $relation->toNodeId, $relations));
    }

    /**
     * The other end — the nodes these relations come **from**, in one query (`CD-7`).
     *
     * ⚠️ *What {@see usedBy()} needs to be readable: an incoming attribute means nothing without the
     * node that owns it, and asking per relation would be the loop the code standard forbids.*
     *
     * @param  list<Relation>   $relations
     * @return array<int, Node> Keyed by id.
     */
    public function ownersOf(array $relations): array
    {
        return $this->nodes->byIds(array_map(static fn (Relation $relation): int => $relation->fromNodeId, $relations));
    }

    /**
     * One of a node's **own** attributes, by relation id.
     *
     * ⚠️ **Ownership is checked here rather than trusted from the request.** An inherited relation
     * belongs to an ancestor, and writing to it would change it for every sibling too.
     *
     * @throws \Taxmod\Core\Exception\NotAPossibleTarget
     */
    public function ownAttribute(int $ownerId, int $relationId): Relation
    {
        foreach ($this->fieldsOf($ownerId) as $relation) {
            if ($relation->id === $relationId && $relation->fromNodeId === $ownerId) {
                return $relation;
            }
        }

        throw NotAPossibleTarget::notAnOwnField($relationId);
    }

    /**
     * Remove an attribute — **parked, not purged**, and under one bracket.
     *
     * ⚠️ **This was missing since Package 3, and the reason was storage rather than reluctance:**
     * `relations` had nowhere to record that an relation was gone. [D-371](../../../docs/NewConcept/90-decision-log.md)
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
    public function removeField(int $ownerId, int $relationId): Relation
    {
        // ⚠️ **The parked ones are looked at too, and that is not tidiness.** Once parked, an relation
        // leaves the live list (D-128), so a second click — a double tap, a back button, a stale
        // form — would otherwise be refused with *not one this node owns*, which is both wrong and
        // confusing. It **is** owned; it is already gone. So the act is idempotent.
        foreach ($this->relations->parkedFieldRelationsOf([$ownerId]) as $already) {
            if ($already->id === $relationId) {
                return $already;
            }
        }

        $relation = $this->ownAttribute($ownerId, $relationId);

        $group = $this->changelog->record(
            $relation->id,
            'relation',
            'attribute removed',
            $this->relationState($relation),
            $this->relationState($relation->parkedBy(0)),
            // ⚠️ *Das Parken hebt die Version **nicht**: die Zeile wandert unverändert in den
            // Schatten ({@see \Taxmod\WordPress\Persistence\Shadow::keepOne()}) und verschwindet
            // lebend. Die Version, die diese Änderung erzeugt hat, ist also die der gelesenen Zeile.*
            $relation->version
        );

        // ⚠️ **Seit [D-619](../../../docs/NewConcept/90-decision-log.md) ein Umzug und kein
        // Spaltenschreiben** (TASK-013): die Kante wandert in den Schatten, **und ihre Wertzeilen
        // wandern mit**. Eine Gruppe, ein Akt, umkehrbar.
        $this->relations->park($relation->id, $group);

        return $relation->parkedBy($group);
    }

    /**
     * Put a removed attribute back.
     *
     * ⚠️ **A new change written forwards, never a rewind** ([D-172](../../../docs/NewConcept/90-decision-log.md)):
     * history is extended, because the changelog is also the migration script
     * ([D-061](../../../docs/NewConcept/90-decision-log.md)).
     */
    public function restoreField(int $ownerId, int $relationId): Relation
    {
        foreach ($this->relations->parkedFieldRelationsOf([$ownerId]) as $relation) {
            if ($relation->id !== $relationId) {
                continue;
            }

            $revived = $relation->revived();

            // ⚠️ *Die Umkehrung des Umzugs, **mit den Wertzeilen**
            // ([D-619](../../../docs/NewConcept/90-decision-log.md), TASK-013) — und nicht ein
            // zweiter Weg, der dasselbe noch einmal beschreibt.*
            //
            // ⚠️ **Zuerst zurückholen, dann melden, seit die Version Pflicht ist**
            // ([D-634](../../../docs/NewConcept/90-decision-log.md)): *das Zurückholen schreibt die
            // Zeile mit einer **neuen** Version, und die kennt erst der Speicher. Vorher gemeldet
            // hätte die Zeile die Version von gestern getragen.*
            $zurueck = $this->relations->unpark($relation->id) ?? $revived;

            $this->changelog->record(
                $relation->id,
                'relation',
                'attribute restored',
                $this->relationState($relation),
                $this->relationState($zurueck),
                $zurueck->version
            );

            return $zurueck;
        }

        throw NotAPossibleTarget::notAnOwnField($relationId);
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
    public function markAsSetting(int $ownerId, int $relationId, bool $isSetting): Relation
    {
        $relation = $this->ownAttribute($ownerId, $relationId);

        if ($isSetting) {
            $art = RelationKind::Setting;
        } else {
            $target = $this->nodes->byId($relation->toNodeId);
            $branch = $this->framework->branchOf($target)
                ?? throw NotAPossibleTarget::itSitsInNoBranch($target->name);

            $art = $branch->relationKind();
        }

        return $this->setKind($ownerId, $relationId, $art);
    }

    /**
     * Die Art einer eigenen Kante auf einen der drei Werte stellen — der Benutzer legt sie fest.
     *
     * ⚠️ *[D-618](../../../docs/NewConcept/90-decision-log.md): «der benutzer legt fest, automation
     * machen wir später aber auch nur vielleicht»; [D-639](../../../docs/NewConcept/90-decision-log.md):
     * drei Werte, je eine Klasse. **Was mit den Werten geschieht, wenn die Art wechselt, sagt
     * [D-690](../../../docs/NewConcept/90-decision-log.md) und tut {@see DataEntry::moveValuesForKindChange()}** —
     * vor diesem Aufruf, weil sie die alte Art kennen muss.*
     */
    public function setKind(int $ownerId, int $relationId, RelationKind $art): Relation
    {
        $relation = $this->ownAttribute($ownerId, $relationId);
        $marked   = $relation->withKind($art);

        if ($marked === $relation) {
            return $relation;
        }

        $this->changelog->record(
            $relation->id,
            'relation',
            $art === RelationKind::Setting
                ? 'field became a setting'
                : ($relation->isSetting() ? 'setting became a field' : 'field changed its kind'),
            $this->relationState($relation),
            $this->relationState($marked),
            $marked->version
        );

        $this->relations->save($marked, $relation->version);

        return $marked;
    }

    /**
     * Ein eigenes Feld in den Vater schieben — die Kante wechselt den Besitzer und behält ihre Id.
     *
     * ⚠️ **Seine Form, 2026-09-12 ([D-750](../../../docs/NewConcept/90-decision-log.md)):** *«Ich kann beim Feld an der
     * Deklaration sagen: schiebe es in den Vater.»* *Die Id bleibt, also bleibt jeder Wert, der an der Kante steht,
     * und die Geschwister erben das Feld von nun an mit. Was am Vater hinten angehängt wird, darf das Kind nach
     * D-698 wieder anordnen.*
     */
    public function moveFieldToParent(int $ownerId, int $relationId): Relation
    {
        $relation = $this->ownAttribute($ownerId, $relationId);
        $owner    = $this->nodes->byId($ownerId);
        $parent   = $owner->parentNodeId === null ? null : $this->nodes->find($owner->parentNodeId);

        if ($parent === null || $parent->id === $this->framework->root()->id) {
            throw ImpossibleMove::noParentTakesTheField($relation->name);
        }

        $moved = $relation->withOwner($parent->id, $this->relations->nextFieldPositionUnder($parent->id));

        $this->changelog->record(
            $relation->id,
            'relation',
            'field moved to parent',
            $this->relationState($relation),
            $this->relationState($moved),
            $moved->version
        );

        $this->relations->save($moved, $relation->version);

        return $moved;
    }

    /**
     * Ein eigenes Feld in gewählte Kinder schieben — je Kind eine neue Kante, die alte wird geparkt.
     *
     * ⚠️ **Seine Form, 2026-09-12 ([D-750](../../../docs/NewConcept/90-decision-log.md)):** *«Am Vater kann ich sagen:
     * schiebe es in die Kinder, und dann Kinder auswählen, die es bekommen sollen.»* *Jedes gewählte Kind bekommt
     * eine eigene Kante mit Ziel, Art und Schaltern der alten; die Werte der Sätze unter diesem Kind ziehen auf die
     * neue Kante um. Die alte Kante wird geparkt wie beim Entfernen (D-128) — mit ihr die Werte der Sätze, die kein
     * gewähltes Kind mehr erreicht.*
     *
     * @param  list<int>      $childIds Unmittelbare Kinder des Besitzers.
     * @return list<Relation> Die neuen Kanten, in der Reihenfolge der Kinder.
     */
    public function pushFieldToChildren(int $ownerId, int $relationId, array $childIds): array
    {
        $this->changelog->beginAct();

        try {
            $relation = $this->ownAttribute($ownerId, $relationId);
            $owner    = $this->nodes->byId($ownerId);
            $kinder   = [];

            foreach ($this->nodes->childrenOf($owner) as $kind) {
                $kinder[$kind->id] = $kind;
            }

            $neue = [];

            foreach (array_values(array_unique(array_map(intval(...), $childIds))) as $childId) {
                $kind = $kinder[$childId] ?? throw ImpossibleMove::notAChildOf($childId, $owner->name);
                $neu  = $this->addedField($kind->id, $relation->toNodeId, $relation->name, $relation->kind);
                $wie  = $neu
                    ->withMultiplicity($relation->multiplicity)
                    ->withReadOnly($relation->readOnly)
                    ->withUnique($relation->unique)
                    ->withHide($relation->hide);

                if ($wie !== $neu) {
                    $this->relations->save($wie, $neu->version);
                    $neu = $wie;
                }

                $this->carryValuesDown($kind, $relation->id, $neu->id);
                $this->carrySettingsDown($relation, $neu);
                $neue[] = $neu;
            }

            if ($neue !== []) {
                $this->removeField($ownerId, $relationId);
            }

            return $neue;
        } finally {
            $this->changelog->endAct();
        }
    }

    /**
     * Die Einstellungen an der alten Stelle auf die neue Kante kopieren — Schalter wie `with_node` am Weg-Feld (D-755) gehören zur
     * Stelle und gingen sonst mit dem Parken der alten Kante in den Schatten.
     */
    private function carrySettingsDown(Relation $alt, Relation $neu): void
    {
        if ($this->settings === null) {
            return;
        }

        foreach ($this->settings->valuesOfNodes([$alt->toNodeId])[$alt->toNodeId] ?? [] as $zeile) {
            if ($zeile->relationId !== $alt->id) {
                continue;
            }

            $this->settings->addValue(
                $zeile->valueObjectId !== null
                    ? \Taxmod\Core\Model\Setting\SettingsValue::objectAtNode($zeile->nodeId, $zeile->klasse, $zeile->attribut, $zeile->valueObjectId, $neu->id, $zeile->position, $zeile->aktiv)
                    : \Taxmod\Core\Model\Setting\SettingsValue::atNode($zeile->nodeId, $zeile->klasse, $zeile->attribut, $zeile->value, $neu->id, $zeile->position, $zeile->aktiv)
            );
        }
    }

    /** Die Werte der Sätze unter einem Kind von der alten auf die neue Kante umhängen — in wenigen Abfragen (CD-7). */
    private function carryValuesDown(Node $kind, int $alteKante, int $neueKante): void
    {
        if ($this->records === null) {
            return;
        }

        $knoten = [$kind->id];

        foreach ($this->nodes->subtreeOf($kind) as $unten) {
            $knoten[] = $unten->id;
        }

        $saetze = [];

        foreach ($this->records->ofNodes(array_values(array_unique($knoten))) as $liste) {
            foreach ($liste as $satz) {
                $saetze[] = $satz->id;
            }
        }

        if ($saetze === []) {
            return;
        }

        foreach ($this->records->valuesOfMany($saetze) as $werte) {
            foreach ($werte as $wert) {
                if ($wert->relationId === $alteKante && $wert->id !== null) {
                    $this->records->putValue(new RelationRecord($wert->recordId, $neueKante, $wert->locale, $wert->value, $wert->id, $wert->position));
                }
            }
        }
    }

    public function retargetField(int $ownerId, int $relationId, int $targetId): Relation
    {
        $relation   = $this->ownAttribute($ownerId, $relationId);
        $target = $this->nodes->byId($targetId);

        $branch = $this->framework->branchOf($target)
            ?? throw NotAPossibleTarget::itSitsInNoBranch($target->name);

        if ($target->id === $this->framework->rootOf($branch)->id) {
            throw NotAPossibleTarget::itIsABranchRoot($target->name);
        }

        if ($target->isDescendantOf($this->framework->trash())) {
            throw NotAPossibleTarget::itIsInTheTrash($target->name);
        }

        // ⚠️ **Eine Einstellungskante bleibt eine Einstellungskante** — *sein Befund am 2026-09-06:
        // «die Typzuordnung am Knoten `render with label` kann ich nicht auf diesen Typ ändern,
        // auswählen geht, er übernimmt ihn aber nicht».* **Gemessen war es kein verweigertes
        // Speichern, sondern ein stiller Artwechsel:** *das Ziel wurde übernommen, und mit ihm die Art
        // des Astes — aus `setting` wurde `composition`. Danach steht die Zeile nicht mehr im
        // Einstellungsblock, und von aussen sieht das aus wie «nichts passiert».*
        //
        // ⚠️ **Und die Art gehört ohnehin nicht dem Ast** ([D-618](../../../docs/NewConcept/90-decision-log.md),
        // [D-621](../../../docs/NewConcept/90-decision-log.md)): *«der Benutzer bestimmt die Kantenart,
        // kein Ast-Automatismus». Für eine Einstellung ist das hier nachgezogen; für die übrigen Arten
        // steht der Rückbau noch aus und wird nicht nebenbei entschieden (`PR-4`).*
        $moved = $relation->retargetedTo(
            $targetId,
            $relation->isSetting() ? $relation->kind : $branch->relationKind()
        );

        if ($moved === $relation) {
            return $relation;
        }

        $this->changelog->record(
            $relation->id,
            'relation',
            'field retargeted',
            $this->relationState($relation),
            $this->relationState($moved),
            $moved->version
        );

        $this->relations->save($moved, $relation->version);

        return $moved;
    }

    public function renameField(int $ownerId, int $relationId, string $name): Relation
    {
        $relation    = $this->ownAttribute($ownerId, $relationId);
        $renamed = $relation->renamedTo($name);

        $this->changelog->record(
            $relation->id,
            'relation',
            'attribute renamed',
            $this->relationState($relation),
            $this->relationState($renamed),
            $renamed->version
        );

        $this->relations->save($renamed, $relation->version);

        return $renamed;
    }

    /** @return list<Relation> The removed attributes of one node — D-128's *show deleted*. */
    public function removedFieldsOf(int $ownerId): array
    {
        return $this->relations->parkedFieldRelationsOf([$ownerId]);
    }

    /**
     * What a changelog row records about an relation.
     *
     * ⚠️ *`name` last, for the same reason it is last in {@see state()}: an attribute's name may hold
     * a space, and a field behind it could not be told apart from the name.*
     */
    private function relationState(Relation $relation): string
    {
        return FrozenState::of([
            'to'     => $relation->toNodeId,
            'kind'   => $relation->kind->value,
            'parked' => $relation->parkedByGroup ?? 0,
            'name'   => $relation->name,
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
     * Exchange two neighbouring relations' positions.
     *
     * ⚠️ **A swap, not a renumbering.** Reordering by rewriting every sibling would be a write
     * per row, which is the loop `CD-7` forbids; a swap is always exactly two, however many
     * siblings there are.
     */
    private function swapWithNeighbour(int $id, int $direction): void
    {
        $node = $this->nodes->byId($id);

        if ($node->parentNodeId === null) {
            throw ImpossibleMove::ofTheRoot();
        }

        $geschwister = $this->nodes->childrenOf($this->nodes->byId($node->parentNodeId));

        $this->swapAmong(
            $id,
            array_map(
                static fn (Node $n): array => ['id' => $n->id, 'sortOrder' => $n->sortOrder, 'version' => $n->version],
                $geschwister
            ),
            $direction,
            'node',
            function (int $wen, int $stelle, int $erwartet): int {
                $knoten = $this->nodes->byId($wen)->movedTo($stelle);
                $this->nodes->save($knoten, $erwartet);

                return $knoten->version;
            }
        );
    }

    /**
     * Swap one thing with its neighbour in a given list — the whole of reordering, for both callers.
     *
     * ⚠️ **Extracted rather than copied** ([D-435](../../../docs/NewConcept/90-decision-log.md)): a
     * node reorders **itself** among its parent's children, an attribute reorders **its own** relation
     * among the attributes its owner declares. *Two sibling lists, one column, one swap — and a
     * second copy of the equal-positions trick below is exactly how the two would drift.*
     *
     * ⚠️ **Seit TASK-018 sind es zwei **Tabellen** und nicht mehr zwei Listen einer Tabelle**
     * ([D-581](../../../docs/NewConcept/90-decision-log.md)). *Die Reihenfolge eines Knotens steht in
     * `nodes.sort_order`, die eines Feldes in `relations.sort_order`. **Darum reicht der Aufrufer
     * jetzt das Schreiben herein** — die Rechnung darüber, wer wohin rutscht, bleibt genau einmal
     * hier stehen, und sie ist der Teil, der zweimal falsch sein könnte.*
     *
     * @param list<array{id: int, sortOrder: int, version: int}> $siblings In the order the list is drawn.
     * @param \Closure(int, int, int): int                       $move     Id, Zielstelle, erwartete
     *                                                                     Fassung — gibt die neue zurück.
     */
    private function swapAmong(int $subjectId, array $siblings, int $direction, string $kind, \Closure $move): void
    {
        $here = null;

        foreach ($siblings as $index => $sibling) {
            if ($sibling['id'] === $subjectId) {
                $here = $index;
                break;
            }
        }

        if ($here === null) {
            return;
        }

        $there = $here + $direction;

        if (! isset($siblings[$there])) {
            return;
        }

        $mir   = $siblings[$here];
        $other = $siblings[$there];

        // Positions may be equal — nothing forbids it, and the list then falls back to id
        // order. Swapping equal numbers would move nothing, so they are forced apart.
        $mine  = $mir['sortOrder'];
        $yours = $other['sortOrder'];

        if ($mine === $yours) {
            $mine  = $here;
            $yours = $there;
        }

        // ⚠️ **Über eine freie Stelle und nicht direkt, seit die Stelle eindeutig ist** (TASK-012,
        // und seit TASK-018 gilt derselbe Schlüsseltyp auch für `nodes`). *Ein Tausch schreibt
        // zwangsläufig einmal auf eine Stelle, die noch besetzt ist — **MySQL weist das zurück, und
        // `$wpdb` sagt darüber nichts**: `package2-check` meldete «moving up swaps them» als rot,
        // ohne dass irgendwo ein Fehler stand. Also erst zur Seite, dann der andere, dann hin.*
        $frei = 1 + max(array_map(static fn (array $e): int => $e['sortOrder'], $siblings));

        $fassung = $move($subjectId, $frei, $mir['version']);
        $move($other['id'], $mine, $other['version']);
        $letzte = $move($subjectId, $yours, $fassung);

        // ⚠️ *Die Version des **letzten** Schreibvorgangs, nicht die des Zwischenschritts auf die
        // freie Stelle: die Änderung, die hier gemeldet wird, ist der fertige Tausch.*
        $this->changelog->record($subjectId, $kind, 'reordered', (string) $here, (string) $there, $letzte);
    }

    /**
     * Change which parent an relation points at, then bring the paths along.
     *
     * ⚠️ **The order is not arbitrary.** The relation is the truth, so it moves first; the paths of
     * the node and everything under it are rewritten from it afterwards, in one statement
     * rather than one per descendant (`CD-7`).
     */
    private function reparent(int $id, Node $newParent, string $verb, ?int $changeGroup = null): Node
    {
        $node = $this->nodes->byId($id);

        if ($this->framework->isProtected($node)) {
            throw NodeIsProtected::named($node->name);
        }

        if ($node->parentNodeId === null) {
            throw ImpossibleMove::ofTheRoot();
        }

        // A node dropped onto its own descendant would cut its whole subtree out of the tree,
        // silently. The path already answers this — that is what a materialised path is for.
        if ($newParent->id === $node->id || $newParent->isDescendantOf($node)) {
            throw ImpossibleMove::intoItsOwnDescendant($node->name);
        }

        // ⚠️ *Ein Schreibvorgang statt zweier, seit TASK-018* ([D-581](../../../docs/NewConcept/90-decision-log.md)).
        // *Vorher zog die Kante um und der Pfad hinterher; **zwischen den beiden hing der Knoten an
        // zwei verschiedenen Vätern**, je nachdem, wen man fragte.*
        $moved = $node->movedUnder(
            $newParent->path,
            $newParent->id,
            $this->nodes->nextPositionUnder($newParent->id)
        );

        if ($moved === $node) {
            return $node;
        }

        $this->nodes->save($moved, $node->version);
        $this->nodes->moveSubtree($node->path, $moved->path);
        $this->changelog->record($id, 'node', $verb, $this->state($node), $this->state($moved), $moved->version, $changeGroup);

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
