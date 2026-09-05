<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\FieldType;

/**
 * Storage for nodes, stated as the core needs it rather than as a database offers it.
 *
 * ⚠️ **`moveSubtree` gab es, damit `CD-7` zu halten war** — Umhängen schrieb den `path` jedes
 * Nachfahren um, und das je Knoten wäre N+1 gewesen. **Seit Fassung 35 gibt es keinen `path` mehr**
 * (TASK-001): der Weg wird beim Lesen aus `parent_node_id` gerechnet, und ein Umzug ändert genau
 * **eine** Zeile. *Die Methode steht noch, weil ihr Aufrufer in einer Datei liegt, die dieser
 * Durchgang nicht anfassen durfte; dass sie fallen soll, steht als `INF-052` im Eingang.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
interface NodeRepository
{
    /** @throws \Taxmod\Core\Exception\NodeNotFound */
    public function byId(int $id): Node;

    public function find(int $id): ?Node;

    /**
     * Several nodes at once, so a caller with a list does not query in a loop (`CD-7`).
     *
     * @param  list<int>        $ids
     * @return array<int, Node> Keyed by id. Ids that no longer exist are simply absent.
     */
    public function byIds(array $ids): array;

    /**
     * Einen neuen Knoten schreiben — **und den Knoten zurückgeben, wie er nun dasteht**.
     *
     * ⚠️ **Seit TASK-004 vergibt die Tabelle die Id selbst.** *`identities` ist gestrichen, jede
     * Tabelle hat ihren eigenen Id-Raum ([`package.md` §6](../../../docs/pakete/modelltabellen/package.md)),
     * und der Speicher ist die einzige Stelle, die weiss, welche Nummer frei ist.* **Id `0` heisst
     * «vergib eine»**; jede andere Id wird so übernommen, wie sie ankommt — das braucht der
     * Wiederaufbau, der eine bekannte Nummer zurückschreibt.
     */
    public function add(Node $node): Node;

    /**
     * @param int $expectedVersion The version the caller read. Guards against a concurrent
     *                             change (P4c).
     *
     * @throws \Taxmod\Core\Exception\ConcurrentChange
     * @throws \Taxmod\Core\Exception\NodeNotFound
     */
    public function save(Node $node, int $expectedVersion): void;

    /**
     * Direct children, in `position` order once relations carry one.
     *
     * @return list<Node>
     */
    public function childrenOf(Node $parent): array;

    /**
     * Eine Stelle hinter der letzten unter diesem Elternteil — wo ein neues Kind hinkommt.
     *
     * ⚠️ *Kam mit TASK-018 von `RelationRepository::nextPositionUnder()` hierher
     * ([D-581](../../../docs/NewConcept/90-decision-log.md)): die Reihenfolge steht jetzt am Kind.*
     */
    public function nextPositionUnder(int $parentId): int;

    /**
     * Jedes Kind eines Elternteils unter ein anderes hängen, in einer Anweisung.
     *
     * ⚠️ **Damit [U4](../../../docs/NewConcept/20-interaction.md) keine Schleife ist** — ein Knoten
     * allein zu löschen hebt seine Kinder zum Grosselternteil, und Kind für Kind wäre das ein
     * Schreibvorgang je Kind, den `CD-7` verbietet.
     *
     * @param int $startPosition Wo die gehobenen Kinder unter ihren neuen Geschwistern stehen —
     *                           ihre Reihenfolge untereinander bleibt.
     */
    public function reparentChildren(int $fromParentId, int $toParentId, int $startPosition): void;

    /**
     * Wo jeder Knoten des Modells hängt — Vater, Stelle, und ob er gezeichnet wird.
     *
     * ⚠️ **Die Ablösung von `RelationRepository::allInheritanceRelations()`** (TASK-018,
     * [D-581](../../../docs/NewConcept/90-decision-log.md)). *Bewusst unbegrenzt, aus demselben
     * Grund wie dort: das Modell ist entwurfsgemäss klein ([D-308](../../../docs/NewConcept/90-decision-log.md)),
     * und je Elternteil zu fragen wäre eine Abfrage je Ebene.*
     *
     * @return array<int, array{parent: ?int, sortOrder: int, hide: bool}> Knoten-Id => seine Stelle.
     */
    public function allPlacements(): array;

    /**
     * ⚠️ **Seit Fassung 35 ist hier nichts mehr zu tun** (TASK-001) — *der Weg steht nicht mehr als
     * Spalte da, und `parent_node_id`, das ihn ergibt, hat der Aufrufer schon gesetzt.* Siehe
     * `INF-052`.
     *
     * @param string $oldPath The subtree's path before the move.
     * @param string $newPath The same subtree's path after it.
     */
    public function moveSubtree(string $oldPath, string $newPath): void;

    /**
     * Everything below a node, in one statement, at any depth.
     *
     * ⚠️ **Eine Anweisung und keine Runde je Ebene** (`CD-7`). *Bis Fassung 35 war das ein `LIKE`
     * auf die Pfadspalte; seither steigt derselbe rekursive Ausdruck über `parent_node_id` ab, der
     * auch den Weg rechnet. **Die Zusage ist dieselbe geblieben**, nur ihre Grundlage ist es, die
     * nicht mehr doppelt gespeichert wird.*
     *
     * @return list<Node>
     */
    public function subtreeOf(Node $root): array;

    /** Remove a node and everything under it, for good. The irreversible half of D-123. */
    public function purgeSubtree(Node $node): void;

    /**
     * Die **eigene** Sorte je Knoten — aus seinen eingehenden Kanten, sonst `null`.
     *
     * ⚠️ **[D-621](../../../docs/NewConcept/90-decision-log.md):** *«die Kante sagt, was etwas hier
     * ist — nicht der Knoten und nicht der Ast.» **Ein Knoten ist selbst eine Einstellung nur dann,
     * wenn jede Kante, die auf ihn zeigt, eine Einstellungskante ist.*** *Wer auch anders erreicht
     * wird — `Integer`, `Decimal` — ist an dieser Stelle nichts Besonderes, und dann entscheidet die
     * Kante, über die man kommt.*
     *
     * ⚠️ *`null` heisst «keine eingehende Kante», also hat hier niemand etwas gesagt. Das ist die
     * Antwort, die {@see \Taxmod\Core\Service\Rendering} für die Wählbarkeit braucht — **die eigene
     * Sorte, nicht die aufgelöste** ([D-544](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * @param  list<int>              $ids
     * @return array<int, ?FieldType>  Je angefragte Id genau ein Eintrag.
     */
    public function ownFieldTypes(array $ids): array;

    /**
     * Die aufgelöste Sorte je Knoten — die eigene, sonst die des nächsten Vorfahren, der eine hat.
     *
     * ⚠️ **Der Lauf ist [D-621](../../../docs/NewConcept/90-decision-log.md)s eigener Satz:** *«wenn
     * man am Vater irgendwas anhaengt, ist es genauso in den Kindern verfuegbar; da bestimmt auch die
     * Kante darueber, wie's beim Vater angehaengt ist.» **Ein Knoten, den nur Vererbung erreicht,
     * bekommt seinen Charakter von der Kante über seinem nächsten Vorfahren, der eine hat** — alles
     * darunter leitet ab.*
     *
     * ⚠️ **In einer festen Zahl von Abfragen, nicht einer je Ebene** (`CD-7`). *Der Pfad ist
     * materialisiert, also stehen alle Vorfahren-Ids schon da; es braucht keinen Aufstieg mit einer
     * Abfrage je Stufe.*
     *
     * @param  list<int>              $ids
     * @return array<int, FieldType>   Je angefragte Id genau ein Eintrag — nie `null`, weil
     *                                {@see FieldType::standard()} das Ende des Laufs beantwortet.
     */
    public function resolvedFieldTypes(array $ids): array;

    /**
     * Wie viele Kinder ein Knoten zu zeigen hat.
     *
     * ⚠️ **Das ist die Frage, an der [D-540](../../../docs/NewConcept/90-decision-log.md) hängt.** *Seine
     * Regel: ein Feld ist eine **Auswahl**, wenn sein Ziel sichtbare, unmarkierte Kinder hat — sonst
     * eine Eingabe. Nicht der Zweig entscheidet das und nicht der Name des Ziels, sondern was unter dem
     * Ziel steht.*
     *
     * ⚠️ **Gebündelt, weil sonst jede Zeile einer Feldtabelle eine Abfrage kostet** (`CD-7`). *Eine
     * Tabelle mit dreissig Feldern ist dreissig Ziele, und die Antwort wird für alle in einem Zug
     * gebraucht.*
     *
     * ⚠️ *Versteckt zählt nicht mit: eine ausgeblendete Kante wird nicht gezeichnet, also steht sie
     * auch nicht zur Wahl. **Eine Auswahl mit null Möglichkeiten wäre schlechter als ein Textfeld.***
     *
     * ⚠️ **Die Kinder selbst und nicht ihre Zahl**, weil derselbe Lauf beides braucht: *ob* es eine
     * Auswahl ist, und *woraus* sie besteht. *Zwei Leser für eine Frage wären zwei Gelegenheiten,
     * verschieden zu antworten.*
     *
     * @param  list<int>                $parentIds
     * @return array<int, list<Node>>   Je angefragte Id genau ein Eintrag, notfalls leer, in der
     *                                  Reihenfolge des Modells.
     */
    public function visibleChildrenOf(array $parentIds): array;

    /**
     * Die Knoten, die diese PHP-Klassen umsetzen (TASK-008).
     *
     * ⚠️ **Das ist die Ablösung der WordPress-Optionen** (TASK-009,
     * [`package.md` §3.2](../../../docs/pakete/modelltabellen/package.md)): *bisher hielt
     * `taxmod_render_renderer_slider_id` ausserhalb des Modells fest, welcher Knoten der
     * Schieber-Renderer ist — gegen `AR-1`. **Jetzt sagt es der Knoten selbst.***
     *
     * ⚠️ *Alle Klassen in einem Zug, weil die Saat über zwanzig auf einmal fragt und eine Abfrage je
     * Name das N+1 wäre, das `CD-7` verbietet.*
     *
     * ⚠️ **Zwei Knoten, die dieselbe Klasse nennen, sind ein Fehler und keine Auswahl** — der
     * Wächter [`implemented-by-check.php`](../../../scripts/dev/implemented-by-check.php) hält es
     * fest. *Hier gewinnt darum die kleinste Id, damit die Antwort wenigstens stabil ist, statt von
     * der Sortierung der Datenbank abzuhängen.*
     *
     * @param  list<string>            $classNames Voll qualifiziert.
     * @return array<string, Node>     Klassenname => Knoten. Wen niemand umsetzt, fehlt.
     */
    public function byImplementations(array $classNames): array;
}
