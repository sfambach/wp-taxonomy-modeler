<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

use Taxmod\Core\Renderer\Renderable;

use Taxmod\Core\Exception\InvalidName;

/**
 * An relation — and seen from the node that owns it, an **attribute** (D-031). Two names, one thing.
 *
 * ⚠️ **Nicht mehr `final`, und das ist [D-639](../../../docs/NewConcept/90-decision-log.md)** —
 * dieselbe Bewegung wie beim Knoten ([D-620](../../../docs/NewConcept/90-decision-log.md)). *Sein
 * Wort: «dann haben wir **ein Mittel**, das bestimmt, was für eine Verbindung es ist, und nicht noch
 * einen Schalter.» **Drei Werte, drei Klassen** — {@see Edge\SettingEdge}, {@see Edge\AggregationEdge},
 * {@see Edge\CompositionEdge} —, und `abstract` steht hier, damit keine vierte, kindlose Kante
 * entstehen kann.*
 *
 * ⚠️ **Der Unterschied zum Knoten ist der Unterscheider**, und er ist Absicht: *ein Knoten trägt
 * seinen Klassennamen in `implemented_by`, eine Kante nicht. **Die Menge der Kantenarten ist
 * geschlossen und hat drei Elemente**, also baut {@see classFor()} die Klasse aus dem Wert der
 * Spalte — an einer Stelle.*
 *
 * ⚠️ **The kind is never chosen.** It is read off the branch the target sits in (sentence 5 of
 * the core on one page), which is why there is no way to hand this class an arbitrary kind from a
 * form. *Was davon fällt, sobald der Benutzer die Art selbst wählt, steht in
 * [D-621](../../../docs/NewConcept/90-decision-log.md) und als `INF-038` im Eingang.*
 *
 * ⚠️ **`position` belongs here and not on the node**, because order is per parent: the same
 * node reached from two parents may sit third under one and first under the other.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
abstract class Relation extends Identity implements Renderable
{
    /**
     * **Die eine Stelle, an der aus dem Wert die Klasse wird**
     * ([D-639](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Der Klassenname steht ausdruecklich *nicht* in der Zeile** — anders als beim Knoten, wo
     * `implemented_by` ihn traegt ([D-620](../../../docs/NewConcept/90-decision-log.md)). *Die Menge
     * ist geschlossen und hat drei Elemente; ein Klassenname je Zeile waere dieselbe Auskunft ein
     * zweites Mal, und zwar an jeder der 57 lebenden Zeilen statt einmal.*
     *
     * @var array<string, class-string<Relation>>
     */
    private const KLASSEN = [
        'setting'     => Edge\SettingEdge::class,
        'aggregation' => Edge\AggregationEdge::class,
        'composition' => Edge\CompositionEdge::class,
    ];

    /**
     * Die Klasse, die diese Kantenart ausmacht.
     *
     * ⚠️ *Oeffentlich, damit ein Waechter die Ableitung gegen die Aufzaehlung halten kann — nicht,
     * damit jemand sie umgeht.*
     *
     * @return class-string<Relation>
     */
    public static function classFor(RelationKind $kind): string
    {
        return self::KLASSEN[$kind->value]
            ?? throw new \LogicException("Keine Kantenklasse für «{$kind->value}».");
    }

    /** Der einzige Weg, eine Kante zu bauen — jede andere Stelle geht hier durch. */
    private static function make(
        int $id,
        int $version,
        int $fromNodeId,
        int $toNodeId,
        RelationKind $kind,
        string $name,
        int $sortOrder,
        ?int $parkedByGroup,
        bool $hide,
        Multiplicity $multiplicity,
    ): self {
        $klasse = self::classFor($kind);

        return new $klasse(
            $id,
            $version,
            $fromNodeId,
            $toNodeId,
            $kind,
            $name,
            $sortOrder,
            $parkedByGroup,
            $hide,
            $multiplicity
        );
    }

    /**
     * Ob diese Kante eine Einstellung erklaert statt eines Feldes.
     *
     * ⚠️ **Stand als `$this === self::Setting` in der Aufzaehlung und ist jetzt Verhalten**
     * ([D-639](../../../docs/NewConcept/90-decision-log.md)): *«je Wert eine Klasse, wie bei den
     * Knoten.» Die 14 Stellen im Quelltext, die danach fragten, verzweigen nicht mehr.*
     */
    abstract public function isSetting(): bool;

    /**
     * Ob der **Datensatz** am Ziel mit dem Datensatz des Besitzers stirbt.
     *
     * ⚠️ **Das ist, was «Komposition» aussagt, und es ist eine Aussage ueber Daten und nicht ueber
     * Knoten** ([D-639](../../../docs/NewConcept/90-decision-log.md)). *Der Zielknoten bleibt
     * selbstverstaendlich stehen.*
     */
    abstract public function deletesRecordWithOwner(): bool;

    /**
     * @param int    $id       From the model identity space, shared with nodes (C11) — which is
     *                         what lets an relation carry settings and labels of its own (C8).
     * @param string $name     Empty for an inheritance relation: the tree relation has no name of its
     *                         own, the child does.
     * @param int      $sortOrder     Order among the siblings of `fromNodeId`, counted from zero.
     * @param int|null $parkedByGroup The act that parked it, or null while it is live.
     *
     * ⚠️ **An relation is parked by a column, and a node is not** ([D-371](../../../docs/NewConcept/90-decision-log.md)).
     * A node's **position** is its mark — it sits under the trash — so a flag would be the same fact
     * twice; an relation has no position in the tree, so there is nothing to duplicate. **It holds the
     * change group rather than a bare flag** because [D-128](../../../docs/NewConcept/90-decision-log.md)
     * wants a parked attribute labelled *deleted with «X»*, and the group is where that act is
     * written down ([D-348](../../../docs/NewConcept/90-decision-log.md)).
     */
    protected function __construct(
        int $id,
        int $version,
        public readonly int $fromNodeId,
        public readonly int $toNodeId,
        public readonly RelationKind $kind,
        string $name,
        public readonly int $sortOrder,
        public readonly ?int $parkedByGroup = null,
        /**
         * Whether the walk stops at this placement.
         *
         * ⚠️ **On the relation alone, and the owner narrowed it there himself** ([D-467](../../../docs/NewConcept/90-decision-log.md)):
         * *«then we only need it on the relation»*, once the access he thought was missing —
         * {@see \Taxmod\Core\Repository\RelationRepository::inheritanceRelationTo()} — turned out to exist.
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
        ?int $fromNodeId = null,
        ?int $toNodeId = null,
        ?RelationKind $kind = null,
        ?string $name = null,
        ?int $sortOrder = null,
        ?int $parkedByGroup = null,
        ?bool $hide = null,
        ?Multiplicity $multiplicity = null,
        bool $unpark = false,
    ): self {
        return self::make(
            $this->id,
            $version ?? $this->version,
            $fromNodeId ?? $this->fromNodeId,
            $toNodeId ?? $this->toNodeId,
            $kind ?? $this->kind,
            $name ?? $this->name,
            $sortOrder ?? $this->sortOrder,
            $unpark ? null : ($parkedByGroup ?? $this->parkedByGroup),
            $hide ?? $this->hide,
            $multiplicity ?? $this->multiplicity,
        );
    }

    /**
     * The same relation under another name, one version on.
     *
     * ⚠️ **An attribute's name is trimmed like a node's, and an empty one is refused.** Nothing
     * decided that for relations — [D-022](../../../docs/NewConcept/90-decision-log.md) governs node
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

    /** The same relation, parked by one act. */
    public function parkedBy(int $changeGroup): self
    {
        return $this->copy(parkedByGroup: $changeGroup);
    }

    /** The same relation, live again — what a restore writes (D-172: forwards, never a rewind). */
    public function revived(): self
    {
        return $this->copy(unpark: true);
    }

    // ⚠️ **Hier stand `inheritance()`** — die Baumkante (TASK-018,
    // [D-581](../../../docs/NewConcept/90-decision-log.md)). *Der Baum ist keine Kante mehr:
    // ein neuer Knoten bringt seinen Vater und seine Stelle mit ({@see Node::create()}),
    // statt gleich danach eine zweite Zeile zu brauchen.*

    /**
     * An attribute relation: the owner points at a target, and the **kind comes from the caller
     * having read it off the target's branch** — never from a person choosing it (D-161).
     */
    public static function attribute(
        int $id,
        int $ownerId,
        int $targetId,
        RelationKind $kind,
        string $name,
        int $sortOrder,
        Multiplicity $multiplicity = Multiplicity::ExactlyOne,
    ): self {
        $name = trim($name);

        if ($name === '') {
            throw InvalidName::empty();
        }

        return self::make($id, 1, $ownerId, $targetId, $kind, $name, $sortOrder, null, false, $multiplicity);
    }

    /**
     * Dieselbe frische Kante, nachdem ihre Tabelle ihr die Id vergeben hat (TASK-004).
     *
     * ⚠️ **Das Gegenstück zu {@see \Taxmod\Core\Model\Node::withAssignedId()}** — seit `identities`
     * gestrichen ist, vergibt jede Tabelle ihre Ids selbst, und eine Kante kennt ihre Nummer darum
     * erst nach dem Schreiben ([`package.md` §6](../../../docs/pakete/modelltabellen/package.md)).
     * *Eine Kante trägt keinen Pfad, also ist hier nichts nachzuziehen.*
     */
    public function withAssignedId(int $id): self
    {
        if ($id === $this->id) {
            return $this;
        }

        return self::make(
            $id,
            $this->version,
            $this->fromNodeId,
            $this->toNodeId,
            $this->kind,
            $this->name,
            $this->sortOrder,
            $this->parkedByGroup,
            $this->hide,
            $this->multiplicity,
        );
    }

    public static function fromStorage(
        int $id,
        int $version,
        int $fromNodeId,
        int $toNodeId,
        string $kind,
        string $name,
        int $sortOrder,
        ?int $parkedByGroup = null,
        bool $hide = false,
        string $multiplicity = '1..1',
    ): self {
        return self::make(
            $id,
            $version,
            $fromNodeId,
            $toNodeId,
            RelationKind::from($kind),
            $name,
            $sortOrder,
            $parkedByGroup,
            $hide,
            // ⚠️ *Ein unbekannter Wert fällt auf die Vorgabe zurück statt zu werfen: die Spalte
            // kam mit [D-528](../../../docs/NewConcept/90-decision-log.md) und alte Zeilen sollen
            // lesbar bleiben.*
            Multiplicity::tryFrom($multiplicity) ?? Multiplicity::ExactlyOne
        );
    }

    /** The same relation pointing at a new parent, one version on. */
    public function reparentedTo(int $parentId, int $sortOrder): self
    {
        if ($parentId === $this->fromNodeId && $sortOrder === $this->sortOrder) {
            return $this;
        }

        return $this->copy(version: $this->version + 1, fromNodeId: $parentId, sortOrder: $sortOrder, unpark: true);
    }

    /**
     * The same relation hidden or shown again, one version on.
     *
     * ⚠️ *Same shape as {@see Node::withHide()}, and it exists because the owner asked for both:
     * «relation and node both having an attribute `hide`» ([D-457](../../../docs/NewConcept/90-decision-log.md)).
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
        if ($targetId === $this->toNodeId && $kind === $this->kind) {
            return $this;
        }

        return $this->copy(version: $this->version + 1, toNodeId: $targetId, kind: $kind);
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

    /** The same relation in a different place among its siblings, one version on. */
    public function movedTo(int $sortOrder): self
    {
        return $this->reparentedTo($this->fromNodeId, $sortOrder);
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
