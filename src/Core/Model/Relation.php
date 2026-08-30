<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

use Taxmod\Core\Renderer\Renderable;

use Taxmod\Core\Exception\InvalidName;

/**
 * An edge — and seen from the node that owns it, an **attribute** (D-031). Two names, one thing.
 *
 * ⚠️ **The kind is never chosen.** It is read off the branch the target sits in (sentence 5 of
 * the core on one page), which is why {@see inheritance()} is a named constructor and there is
 * no way to hand this class an arbitrary kind from a form.
 *
 * ⚠️ **`position` belongs here and not on the node**, because order is per parent: the same
 * node reached from two parents may sit third under one and first under the other.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class Relation extends Identity implements Renderable
{
    /**
     * @param int    $id       From the model identity space, shared with nodes (C11) — which is
     *                         what lets an edge carry settings and labels of its own (C8).
     * @param string $name     Empty for an inheritance edge: the tree edge has no name of its
     *                         own, the child does.
     * @param int      $position     Order among the siblings of `fromId`, counted from zero.
     * @param int|null $parkedByGroup The act that parked it, or null while it is live.
     *
     * ⚠️ **An edge is parked by a column, and a node is not** ([D-371](../../../docs/NewConcept/90-decision-log.md)).
     * A node's **position** is its mark — it sits under the trash — so a flag would be the same fact
     * twice; an edge has no position in the tree, so there is nothing to duplicate. **It holds the
     * change group rather than a bare flag** because [D-128](../../../docs/NewConcept/90-decision-log.md)
     * wants a parked attribute labelled *deleted with «X»*, and the group is where that act is
     * written down ([D-348](../../../docs/NewConcept/90-decision-log.md)).
     */
    private function __construct(
        int $id,
        int $version,
        public readonly int $fromId,
        public readonly int $toId,
        public readonly RelationKind $kind,
        string $name,
        public readonly int $position,
        public readonly ?int $parkedByGroup = null,
        /**
         * Whether the walk stops at this placement.
         *
         * ⚠️ **On the edge alone, and the owner narrowed it there himself** ([D-467](../../../docs/NewConcept/90-decision-log.md)):
         * *«then we only need it on the edge»*, once the access he thought was missing —
         * {@see \Taxmod\Core\Repository\RelationRepository::inheritanceEdgeTo()} — turned out to exist.
         *
         * ⚠️ **Hiding is about a placement, not about a thing.** *«I do not simply create a model node
         * and then say I will not draw it — that would be nonsense. Where I would say it is on the
         * **fields** of a model node, when I only want something in the background, to calculate with.»*
         *
         * ⚠️ *A column and never a setting ([D-426](../../../docs/NewConcept/90-decision-log.md)): as a
         * setting it sat in the resolution chain, and an attribute chain contains its **target node** —
         * so hiding a type blanked every field of that type. Measured twice.*
         *
         * ⚠️ *It means «render no further» ([D-456](../../../docs/NewConcept/90-decision-log.md)) and it
         * is an **abort**: the walk stops before drawing and before looking for children. In the tree
         * that falls out of `$skip` — a placement not followed takes its subtree with it.*
         */
        public readonly bool $hide = false,
        /**
         * Wie oft dieses Feld vorkommen darf — **eine Spalte, seit [D-528](../../../docs/NewConcept/90-decision-log.md)**.
         *
         * ⚠️ **Sie lag bis 2026-08-30 in der `settings`-Tabelle**, und [D-086](../../../docs/NewConcept/90-decision-log.md)
         * begründete das damit, dass sie *«inherits and can be narrowed»* — die Auflösungskette gab
         * beides gratis. **Der erste Grund gilt weiter und kostet nichts:** ein Feld *ist* die Kante,
         * ein geerbtes Feld ist **dieselbe** Kante ([D-526](../../../docs/NewConcept/90-decision-log.md)),
         * also erbt die Spalte, weil die Kante erbt.
         *
         * ⚠️ **Der zweite ist weggefallen, und der Eigentümer hat ihn ausdrücklich aufgegeben:**
         * *«wir vereinfachen, Multiplizität hängen wir als Spalte an die Kante, **sie kann in Zukunft
         * nicht mehr überschrieben werden**».* Es gibt unterhalb auch keine zweite Stelle mehr, an der
         * jemand einschränken könnte.
         *
         * ⚠️ *Die Vorgabe ist `1..1` ([D-434](../../../docs/NewConcept/90-decision-log.md): «weil das
         * der Standard beim Eingeben ist»). Als Setting war sie dünn besetzt — eine fehlende Zeile
         * **bedeutete** die Vorgabe; als Spalte steht sie überall, mit demselben Ergebnis.*
         */
        public readonly Multiplicity $multiplicity = Multiplicity::ExactlyOne,
    ) {
        // ⚠️ *Wie beim Knoten: die zwei gemeinsamen Felder wohnen bei {@see Identity}.*
        parent::__construct($id, $version, $name);
    }

    /**
     * Dieselbe Kante mit einzelnen geänderten Angaben — **der einzige Ort, der alle Felder kennt**.
     *
     * ⚠️ **Gemessen am 2026-08-30, und das ist der Grund für diese Methode:** *von zehn Stellen, die
     * `new self(...)` schrieben, liessen **zwei** `hide` weg. Eine Kante umzubenennen machte ein
     * verstecktes Feld sichtbar, ohne dass jemand das gesagt hätte. **Mit einem vierten getragenen
     * Feld wäre derselbe Fehler viermal möglich gewesen** — hier ist er einmal möglich und einmal
     * getestet ({@see \Taxmod\Tests\Core\RelationCopyTest}).*
     *
     * ⚠️ *`$unpark` statt `parkedByGroup: null`, weil `null` hier zwei Dinge heissen müsste —
     * «nicht ändern» und «wiederbeleben». Ein eigener Schalter sagt, welches gemeint ist.*
     */
    private function copy(
        ?int $version = null,
        ?int $fromId = null,
        ?int $toId = null,
        ?RelationKind $kind = null,
        ?string $name = null,
        ?int $position = null,
        ?int $parkedByGroup = null,
        ?bool $hide = null,
        ?Multiplicity $multiplicity = null,
        bool $unpark = false,
    ): self {
        return new self(
            $this->id,
            $version ?? $this->version,
            $fromId ?? $this->fromId,
            $toId ?? $this->toId,
            $kind ?? $this->kind,
            $name ?? $this->name,
            $position ?? $this->position,
            $unpark ? null : ($parkedByGroup ?? $this->parkedByGroup),
            $hide ?? $this->hide,
            $multiplicity ?? $this->multiplicity,
        );
    }

    /**
     * The same edge under another name, one version on.
     *
     * ⚠️ **An attribute's name is trimmed like a node's, and an empty one is refused.** Nothing
     * decided that for edges — [D-022](../../../docs/NewConcept/90-decision-log.md) governs node
     * names — but *the use site is an attribute* argues they are the same kind of thing, and it is
     * the assumption Package 3 recorded rather than invented quietly.
     */
    public function renamedTo(string $name): self
    {
        $name = trim($name);

        if ($name === '') {
            throw InvalidName::empty();
        }

        return $this->copy(version: $this->version + 1, name: $name);
    }

    /** Whether it has been removed — parked, not purged (D-123's two stages). */
    public function isParked(): bool
    {
        return $this->parkedByGroup !== null;
    }

    /** The same edge, parked by one act. */
    public function parkedBy(int $changeGroup): self
    {
        return $this->copy(parkedByGroup: $changeGroup);
    }

    /** The same edge, live again — what a restore writes (D-172: forwards, never a rewind). */
    public function revived(): self
    {
        return $this->copy(unpark: true);
    }

    /** The tree edge: parent to child, and the only kind the tree is made of (V3). */
    public static function inheritance(int $id, int $parentId, int $childId, int $position): self
    {
        return new self($id, 1, $parentId, $childId, RelationKind::Inheritance, '', $position);
    }

    /**
     * An attribute edge: the owner points at a target, and the **kind comes from the caller
     * having read it off the target's branch** — never from a person choosing it (D-161).
     */
    public static function attribute(
        int $id,
        int $ownerId,
        int $targetId,
        RelationKind $kind,
        string $name,
        int $position,
        Multiplicity $multiplicity = Multiplicity::ExactlyOne,
    ): self {
        $name = trim($name);

        if ($name === '') {
            throw InvalidName::empty();
        }

        return new self($id, 1, $ownerId, $targetId, $kind, $name, $position, null, false, $multiplicity);
    }

    public static function fromStorage(
        int $id,
        int $version,
        int $fromId,
        int $toId,
        string $kind,
        string $name,
        int $position,
        ?int $parkedByGroup = null,
        bool $hide = false,
        string $multiplicity = '1..1',
    ): self {
        return new self(
            $id,
            $version,
            $fromId,
            $toId,
            RelationKind::from($kind),
            $name,
            $position,
            $parkedByGroup,
            $hide,
            // ⚠️ *Ein unbekannter Wert fällt auf die Vorgabe zurück statt zu werfen: die Spalte
            // kam mit [D-528](../../../docs/NewConcept/90-decision-log.md) und alte Zeilen sollen
            // lesbar bleiben.*
            Multiplicity::tryFrom($multiplicity) ?? Multiplicity::ExactlyOne
        );
    }

    /** The same edge pointing at a new parent, one version on. */
    public function reparentedTo(int $parentId, int $position): self
    {
        if ($parentId === $this->fromId && $position === $this->position) {
            return $this;
        }

        return $this->copy(version: $this->version + 1, fromId: $parentId, position: $position, unpark: true);
    }

    /**
     * The same edge hidden or shown again, one version on.
     *
     * ⚠️ *Same shape as {@see Node::withHide()}, and it exists because the owner asked for both:
     * «edge and node both having an attribute `hide`» ([D-457](../../../docs/NewConcept/90-decision-log.md)).
     * A node hides itself; a **placement** hides what hangs there.*
     */
    /**
     * Dieselbe Kante auf ein anderes Ziel, eine Fassung weiter.
     *
     * ⚠️ **Die Id bleibt, und das ist der ganze Zweck.** *An ihr hängen die Werte: gemessen tragen
     * **20 Setting-Zeilen** `path = 4654` — die Exponenten aller Präfixe. Löschen und neu anlegen
     * gäbe eine neue Id und liesse sie verwaist zurück. **Ein Feld umzuhängen ist keine Neuanlage,
     * sondern eine Änderung** ([D-282](../../../docs/NewConcept/90-decision-log.md)).*
     */
    /**
     * Dieselbe Kante mit einer anderen Art, eine Fassung weiter.
     *
     * ⚠️ **Es gibt sie, weil `Setting` die einzige Art ist, die *nicht* vom Ast abgelesen wird**
     * ([D-526](../../../docs/NewConcept/90-decision-log.md)). *Die anderen stehen beim Anlegen fest;
     * diese eine sagt jemand — und kann sie zurücknehmen.*
     *
     * ⚠️ *Gibt **dasselbe** Exemplar zurück, wenn sich nichts ändert
     * ([D-282](../../../docs/NewConcept/90-decision-log.md)).*
     */
    public function withKind(RelationKind $kind): self
    {
        if ($kind === $this->kind) {
            return $this;
        }

        return $this->copy(version: $this->version + 1, kind: $kind);
    }

    public function retargetedTo(int $targetId, RelationKind $kind): self
    {
        if ($targetId === $this->toId && $kind === $this->kind) {
            return $this;
        }

        return $this->copy(version: $this->version + 1, toId: $targetId, kind: $kind);
    }

    public function withHide(bool $hide): self
    {
        if ($hide === $this->hide) {
            return $this;
        }

        return $this->copy(version: $this->version + 1, hide: $hide);
    }
    /**
     * Dieselbe Kante mit einer anderen Multiplizität, eine Version weiter.
     *
     * ⚠️ *Kein Einschränken mehr, kein Vergleich mit einem Elternwert — [D-528](../../../docs/NewConcept/90-decision-log.md)
     * hat das Überschreiben aufgegeben. **Wer die Multiplizität ändert, ändert sie**, und es gibt
     * keine zweite Stelle, gegen die das geprüft werden müsste.*
     */
    public function withMultiplicity(Multiplicity $multiplicity): self
    {
        if ($multiplicity === $this->multiplicity) {
            return $this;
        }

        return $this->copy(version: $this->version + 1, multiplicity: $multiplicity);
    }

    /** The same edge in a different place among its siblings, one version on. */
    public function movedTo(int $position): self
    {
        return $this->reparentedTo($this->fromId, $position);
    }

    /**
     * Was ein Leser fuer diese Kante liest — der Name des Attributs.
     *
     * ⚠️ *Noch der rohe Name. [D-105](../../../docs/NewConcept/90-decision-log.md) will eine Referenz
     * als **Label des Ziels** gezeichnet sehen, und [Zeile 21](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)
     * ist genau darum offen — **eine Methode ist, was das an einer Stelle behebbar macht.***
     */
    public function label(): string
    {
        return $this->name;
    }

    /**
     * Leer: eine Kante haelt keinen Wert, sie sagt nur, wo einer hingehoert.
     */
    public function content(): string
    {
        return '';
    }
}
