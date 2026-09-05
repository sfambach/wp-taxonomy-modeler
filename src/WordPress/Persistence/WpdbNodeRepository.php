<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Exception\NodeNotFound;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\FieldType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\WordPress\Admin\SettingsScreen;

/**
 * Nodes in a table of our own (AR-1), reached through `$wpdb`.
 *
 * ⚠️ **Every variable goes through `prepare()`** — `CD-6`, without exception, including the
 * `LIKE` patterns, whose `%` must be escaped with `esc_like()` first or a name containing a
 * percent sign silently widens the match.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WpdbNodeRepository implements NodeRepository
{
    /**
     * Die Spalten eines Knotens — **und der Name kommt aus den Beschriftungen** (TASK-019,
     * [D-580](../../../docs/NewConcept/90-decision-log.md),
     * [D-646](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *`nodes.name` gibt es nicht mehr. Was ein Knoten heisst, steht als `text_name` in
     * `label_texts` — je Sprache, seit D-646. **Hier wird die Standardsprache gelesen**: sie ist der
     * letzte Schritt jeder Rückfallkette ({@see \Taxmod\Core\Service\Labels}), und ein `Node` trägt
     * genau diesen einen Namen. Die sprachabhängige Anzeige läuft über die Kette und nicht über
     * dieses Feld.*
     */
    private const COLUMNS = "n.id, n.version, COALESCE(t.text_name, '') AS name, n.path, n.implemented_by, n.parent_node_id, n.sort_order, n.hide";

    /**
     * ⚠️ **`LEFT JOIN` und kein `JOIN`:** *ein Knoten ohne Beschriftungszeile hätte sonst gar keine
     * Zeile mehr — er wäre unsichtbar statt namenlos, und das ist die schlechtere Störung. Dass es
     * ihn nicht geben darf, hält `label-texts-check.php` fest, nicht dieser Leser.*
     */
    private static function fromNodes(): string
    {
        return ' FROM ' . Schema::table('nodes') . ' n LEFT JOIN ' . Schema::table('label_texts') . ' t'
            . ' ON t.label_id = n.label_id AND t.locale = %s AND t.number = %s ';
    }

    /**
     * ⚠️ *Die beiden Werte des Verbunds stehen **vorn** in der Argumentliste, weil `FROM` vor `WHERE`
     * steht und `prepare()` der Reihe nach füllt.*
     *
     * @return array{0: string, 1: string}
     */
    private static function nameArgs(): array
    {
        return [SettingsScreen::neutralLocale(), Label::BASE_NUMBER];
    }

    public function byId(int $id): Node
    {
        return $this->find($id) ?? throw NodeNotFound::withId($id);
    }

    public function find(int $id): ?Node
    {
        global $wpdb;

        $row = Query::row('Knoten lesen', $wpdb->prepare(
            'SELECT ' . self::COLUMNS . self::fromNodes() . 'WHERE n.id = %d',
            ...[...self::nameArgs(), $id]
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function byIds(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        // ⚠️ The ids are cast to int above, but the placeholders are still built rather than
        // interpolated — `CD-6` has no exception for values that look safe.
        $slots = implode(',', array_fill(0, count($ids), '%d'));

        $rows = Query::rows('Knoten nach Ids lesen', $wpdb->prepare(
            'SELECT ' . self::COLUMNS . self::fromNodes() . "WHERE n.id IN ($slots)",
            ...[...self::nameArgs(), ...array_map(intval(...), $ids)]
        ));

        $found = [];

        foreach ($rows ?: [] as $row) {
            $found[(int) $row['id']] = $this->hydrate($row);
        }

        return $found;
    }

    /**
     * ⚠️ **Die Id kommt aus dem `AUTO_INCREMENT` dieser Tabelle** (TASK-004). *`identities` ist
     * gestrichen; wer mit Id `0` ankommt, bekommt die nächste freie Nummer **dieses** Raums, und der
     * Pfad wird mit ihr nachgezogen ({@see Node::withAssignedId()}). Eine mitgebrachte Id bleibt,
     * wie sie ist — sonst könnte ein Wiederaufbau seine Nummern nicht zurückschreiben.*
     */
    public function add(Node $node): Node
    {
        global $wpdb;

        $spalten = [
            'version'        => $node->version,
            'path'           => $node->path,
            'implemented_by' => $node->implementedBy,
            // ⚠️ *Seit TASK-018 kommt die Einordnung mit der Zeile* ([D-581](../../../docs/NewConcept/90-decision-log.md)).
            // *`null` ist die Wurzel und nicht «weiss nicht» — `$wpdb->insert()` schreibt dafür ein
            // echtes NULL, was `prepare('%d', null)` nicht täte.*
            'parent_node_id' => $node->parentNodeId,
            'sort_order'     => $node->sortOrder,
            'hide'           => $node->hide ? 1 : 0,
        ];
        $formate = ['%d', '%s', '%s', '%d', '%d', '%d'];

        if ($node->id !== 0) {
            $spalten = ['id' => $node->id, ...$spalten];
            $formate = ['%d', ...$formate];
        }

        $wpdb->insert(Schema::table('nodes'), $spalten, $formate);

        if ($node->id !== 0) {
            $this->writeName($node);

            return $node;
        }

        $node = $node->withAssignedId((int) $wpdb->insert_id);

        // Der Pfad trug bis eben die 0 an letzter Stelle; er wird mit der vergebenen Id nachgezogen.
        $wpdb->update(
            Schema::table('nodes'),
            ['path' => $node->path],
            ['id' => $node->id],
            ['%s'],
            ['%d']
        );

        $this->writeName($node);

        return $node;
    }

    /**
     * Der Name geht in die Beschriftungen, nicht in die Knotenzeile (TASK-019, D-580, D-646).
     *
     * ⚠️ **Und hier entsteht die `label_id`, die am Knoten Pflicht ist.** *Ein Knoten ohne sie hätte
     * keinen Namen mehr — darum legt die Ablage die Beschriftungszeile beim ersten Schreiben an
     * ({@see WpdbLabelRepository::put()}), und `label-texts-check.php` misst, dass keiner ohne
     * durchkommt.*
     *
     * ⚠️ *Die **Standardsprache**, weil `Node::$name` genau die eine ist, auf die jede Rückfallkette
     * zuletzt läuft ([D-387](../../../docs/NewConcept/90-decision-log.md),
     * [D-645](../../../docs/NewConcept/90-decision-log.md)). Eine Übersetzung schreibt die Maske, nicht
     * dieser Weg.*
     */
    private function writeName(Node $node): void
    {
        (new WpdbLabelRepository())->put(new Label(
            $node->id,
            IdentitySpace::Node,
            SeededRole::Name,
            Label::BASE_NUMBER,
            SettingsScreen::neutralLocale(),
            $node->name
        ));
    }

    public function save(Node $node, int $expectedVersion): void
    {
        global $wpdb;

        // The WHERE carries the expected version, so the guard is the write itself rather than
        // a read followed by a hopeful update (P4c).
        // ⚠️ *`kind` fährt mit, sonst hätte ein Umbenennen die Sorte gelöscht — dieselbe Falle, die
        // `path` hier schon hat.*
        //
        // ⚠️ **`update()` und nicht `query(prepare(...))`, und der Grund ist gemessen:
        // `$wpdb->prepare('kind = %s', null)` ergibt `kind = ''` — eine leere Zeichenkette, nicht
        // NULL.** *Das hat am 2026-08-29 eine Zeile mit `kind = ''` hinterlassen, und damit **zwei
        // Darstellungen desselben Zustands**: `fromStorage()` liest beide als «niemand hat etwas
        // gesagt», aber `WHERE kind IS NOT NULL` findet nur eine. `$wpdb->update()` schreibt für
        // `null` ein echtes NULL — gemessen, nicht erinnert.*
        //
        // ⚠️ *Der Fassungswächter bleibt derselbe: er steht im `WHERE` und ist damit der Schreibvorgang
        // selbst statt eines Lesens mit Hoffnung (P4c).*
        // ⚠️ *Vor dem Schreiben in den Schatten ([D-536](../../../docs/NewConcept/90-decision-log.md)).
        // Die Version zählt der Kern hoch, nicht dieser Weg — ein Knoten weiss, in welcher Version er
        // ist.*
        Shadow::keepOne('nodes', $node->id);

        $written = $wpdb->update(
            Schema::table('nodes'),
            [
                'version'        => $node->version,
                'path'           => $node->path,
                // ⚠️ *Fährt mit, aus demselben Grund wie `kind`: ein Umbenennen hätte sonst die
                // Klassenangabe gelöscht (TASK-008).*
                'implemented_by' => $node->implementedBy,
                // ⚠️ *Fahren mit, aus demselben Grund wie `implemented_by`: ein
                // Umbenennen hätte den Knoten sonst aus dem Baum geschrieben (TASK-018).*
                'parent_node_id' => $node->parentNodeId,
                'sort_order'     => $node->sortOrder,
                'hide'           => $node->hide ? 1 : 0,
            ],
            [
                'id'      => $node->id,
                'version' => $expectedVersion,
            ],
            ['%d', '%s', '%s', '%d', '%d', '%d'],

        );

        if ($written === 1) {
            $this->writeName($node);

            return;
        }

        $current = $this->find($node->id);

        if ($current === null) {
            throw NodeNotFound::withId($node->id);
        }

        // MySQL reports 0 changed rows for a write that matched but altered nothing. Only a
        // version that actually moved on is a collision.
        if ($current->version !== $expectedVersion) {
            throw ConcurrentChange::on($node->id, $expectedVersion, $current->version);
        }
    }

    /**
     * ⚠️ *Eine Abfrage für alle Eltern zusammen — `GROUP BY` statt einer Runde je Zeile (`CD-7`).*
     *
     * @param  list<int>              $parentIds
     * @return array<int, list<Node>>
     */
    public function visibleChildrenOf(array $parentIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $parentIds))));

        if ($ids === []) {
            return [];
        }

        // ⚠️ *Jede angefragte Id bekommt einen Eintrag, auch die ohne Kinder — sonst müsste jeder
        // Aufrufer denselben `?? []` schreiben, und einer würde ihn vergessen.*
        $kinder = array_fill_keys($ids, []);

        $platzhalter = implode(',', array_fill(0, count($ids), '%d'));

        // ⚠️ *Kein Join mehr, seit die Einordnung eine Spalte ist* (TASK-018,
        // [D-581](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort dazu: «wäre
        // selektionstechnisch billiger».*
        $rows = Query::rows('sichtbare Kinder lesen', $wpdb->prepare(
            'SELECT ' . self::COLUMNS . self::fromNodes()
                . 'WHERE n.hide = 0 AND n.parent_node_id IN (' . $platzhalter . ')
             ORDER BY n.parent_node_id ASC, n.sort_order ASC, n.id ASC',
            ...[...self::nameArgs(), ...$ids]
        ));

        foreach ($rows ?: [] as $row) {
            $kinder[(int) $row['parent_node_id']][] = $this->hydrate($row);
        }

        return $kinder;
    }

    public function childrenOf(Node $parent): array
    {
        global $wpdb;

        // ⚠️ **Asked of the column, not of the path** (TASK-018,
        // [D-581](../../../docs/NewConcept/90-decision-log.md)). *`parent_node_id` **ist** der Baum;
        // der Pfad ist die daraus abgeleitete Abkürzung ([D-014](../../../docs/NewConcept/90-decision-log.md))
        // und bleibt es. Bis TASK-018 stand die Wahrheit in den Vererbungskanten und dieser Leser
        // war ein Join.*
        $rows = Query::rows('Kinder lesen', $wpdb->prepare(
            'SELECT ' . self::COLUMNS . self::fromNodes()
                . 'WHERE n.parent_node_id = %d ORDER BY n.sort_order ASC, n.id ASC',
            ...[...self::nameArgs(), $parent->id]
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }

    public function nextPositionUnder(int $parentId): int
    {
        global $wpdb;

        $hoechste = Query::value('naechste Stelle unter dem Knoten lesen', $wpdb->prepare(
            'SELECT MAX(sort_order) FROM ' . Schema::table('nodes') . ' WHERE parent_node_id = %d',
            $parentId
        ));

        return $hoechste === null ? 0 : (int) $hoechste + 1;
    }

    public function reparentChildren(int $fromParentId, int $toParentId, int $startPosition): void
    {
        global $wpdb;

        // ⚠️ *Eine Anweisung, wie viele Kinder es auch sind. `sort_order + start` behält ihre
        // Reihenfolge untereinander und setzt sie hinter ihre neuen Geschwister (`CD-7`).*
        //
        // ⚠️ *Auch eine Massenänderung hebt in den Schatten* ([D-536](../../../docs/NewConcept/90-decision-log.md))
        // — **sie zählt `version` selbst hoch**, also muss der alte Stand vorher hinüber. *Bis
        // TASK-018 lag der alte Stand in `relations` und `Shadow::keep()` stand in
        // `reparentChildRelations()`; die Zusage zieht mit der Spalte um.*
        Shadow::keep('nodes', 'parent_node_id = %d', [$fromParentId]);

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::table('nodes') . '
             SET parent_node_id = %d, sort_order = sort_order + %d, version = version + 1
             WHERE parent_node_id = %d',
            $toParentId,
            $startPosition,
            $fromParentId
        ));
    }

    public function allPlacements(): array
    {
        global $wpdb;

        $rows = Query::rows(
            'alle Einordnungen lesen',
            'SELECT id, parent_node_id, sort_order, hide FROM ' . Schema::table('nodes')
                . ' ORDER BY parent_node_id ASC, sort_order ASC, id ASC'
        );

        $aus = [];

        foreach ($rows ?: [] as $row) {
            $aus[(int) $row['id']] = [
                'parent'    => $row['parent_node_id'] === null ? null : (int) $row['parent_node_id'],
                'sortOrder' => (int) $row['sort_order'],
                'hide'      => (bool) (int) $row['hide'],
            ];
        }

        return $aus;
    }



    public function subtreeOf(Node $root): array
    {
        global $wpdb;

        $rows = Query::rows('Teilbaum lesen', $wpdb->prepare(
            'SELECT ' . self::COLUMNS . self::fromNodes() . 'WHERE n.path LIKE %s ORDER BY n.path ASC',
            ...[...self::nameArgs(), $wpdb->esc_like($root->path . '.') . '%']
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }
    public function moveSubtree(string $oldPath, string $newPath): void
    {
        global $wpdb;

        // One statement for the whole subtree. Done node by node this would be N+1, which the
        // code standard forbids outright (`CD-7`).
        //
        // ⚠️ **The counter rides along in the same UPDATE** (D-349). It has to move: `save()`
        // writes name and path together, so without it a stale form could rename a node and
        // write its old path back, silently undoing somebody else's move. Five hundred
        // descendants cost no extra statement for it.
        // ⚠️ *Auch eine Massenänderung hebt auf ([D-536](../../../docs/NewConcept/90-decision-log.md)).
        // **Sie zählt `version` selbst hoch** — also muss der alte Stand vorher hinüber, sonst fehlt
        // genau die Version, auf die ein Zurückspringen zielt.*
        Shadow::keep('nodes', 'path LIKE %s', [$wpdb->esc_like($oldPath . '.') . '%']);

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Schema::table('nodes') . '
                 SET path = CONCAT(%s, SUBSTRING(path, %d)), version = version + 1
                 WHERE path LIKE %s',
                $newPath,
                strlen($oldPath) + 1,
                $wpdb->esc_like($oldPath . '.') . '%'
            )
        );
    }

    public function purgeSubtree(Node $node): void
    {
        global $wpdb;

        $nodes     = Schema::table('nodes');
        $relations = Schema::table('relations');
        $under     = $wpdb->esc_like($node->path . '.') . '%';

        // ⚠️ **Erst in den Schatten, dann weg** ([D-536](../../../docs/NewConcept/90-decision-log.md)).
        // *Der Eigentümer: «auch wenn es gelöscht ist, nur mit Löschkennzeichen versehen». **Hier ist
        // es endgültig für die lebende Tabelle und nicht endgültig für die Geschichte** — und der
        // Anlass steht in den Daten: gemessen am 2026-08-30 gab es **8 Datensätze, deren Knoten es
        // nicht mehr gab**, und niemand konnte mehr sagen, was sie bedeuteten.
        Shadow::keep(
            'relations',
            "id IN (SELECT x.id FROM {$relations} x
                    INNER JOIN {$nodes} n ON n.id = x.to_node_id OR n.id = x.from_node_id
                    WHERE n.id = %d OR n.path LIKE %s)",
            [$node->id, $under],
            true
        );

        Shadow::keep('nodes', 'id = %d OR path LIKE %s', [$node->id, $under], true);

        // ⚠️ **Die Beschriftungen gehen mit, und das ist seit TASK-019 nicht mehr optional**
        // ([D-580](../../../docs/NewConcept/90-decision-log.md)). *Vorher hatte ein Knoten nur dann
        // eine Beschriftungszeile, wenn jemand einen Text geschrieben hatte; **jetzt hat sie jeder**,
        // weil der Name eine ist. Ein Löschen, das sie stehenlässt, hinterlässt eine Waise je
        // gelöschtem Knoten — gemessen an den Prüfläufen, die genau das taten.*
        $this->forgetLabelsOf(
            "SELECT n.label_id FROM {$nodes} n WHERE n.id = %d OR n.path LIKE %s",
            [$node->id, $under]
        );

        $this->forgetLabelsOf(
            "SELECT r.label_id FROM {$relations} r
             INNER JOIN {$nodes} n ON n.id = r.to_node_id OR n.id = r.from_node_id
             WHERE n.id = %d OR n.path LIKE %s",
            [$node->id, $under]
        );

        // The relations go first, because a relation row whose node is gone is the dangling
        // reference the whole two-stage deletion exists to avoid. Both are one statement.
        $wpdb->query($wpdb->prepare(
            "DELETE r FROM {$relations} r
             INNER JOIN {$nodes} n ON n.id = r.to_node_id OR n.id = r.from_node_id
             WHERE n.id = %d OR n.path LIKE %s",
            $node->id,
            $under
        ));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$nodes} WHERE id = %d OR path LIKE %s",
            $node->id,
            $under
        ));
    }

    /**
     * Die Beschriftungen, auf die eine gleich verschwindende Zeile zeigt — samt ihren Texten.
     *
     * ⚠️ *Vor dem Löschen der Zeile aufgerufen, weil danach niemand mehr sagen könnte, worauf sie
     * zeigte. **Die Geschichte behält sie trotzdem**: der Schatten trägt `label_id` und `name`.*
     *
     * @param list<int|string> $args
     */
    private function forgetLabelsOf(string $auswahl, array $args): void
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map(
            intval(...),
            Query::column('Beschriftungen des Weggeraeumten lesen', $wpdb->prepare($auswahl, ...$args))
        )));

        if ($ids === []) {
            return;
        }

        $slots = implode(',', array_fill(0, count($ids), '%d'));

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('label_texts') . " WHERE label_id IN ({$slots})",
            ...$ids
        ));

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('labels') . " WHERE id IN ({$slots})",
            ...$ids
        ));
    }

    /**
     * Die eigene Sorte je Knoten — aus den **eingehenden Kanten**, sonst `null`.
     *
     * ⚠️ **[D-621](../../../docs/NewConcept/90-decision-log.md):** *«die Kante sagt, was etwas hier
     * ist — nicht der Knoten und nicht der Ast.» **Damit gibt es keine Spalte mehr zu lesen**: was
     * ein Knoten ist, steht in den Kanten, die auf ihn zeigen.*
     *
     * ⚠️ **Alle oder keine, und das ist die Berichtigung, die [D-621](../../../docs/NewConcept/90-decision-log.md)
     * gemessen hat.** *Ein Knoten kann beides sein — `Integer` und `Decimal` sind Ziel einer
     * Kompositions- **und** einer Einstellungskante. **Wer beides ist, ist an dieser Stelle nichts
     * Besonderes**: nur wo jede eingehende Kante eine Einstellungskante ist, ist der Knoten selbst
     * eine Einstellung. Sonst entscheidet die Kante, über die man kommt, und nicht der Knoten.*
     *
     * ⚠️ *Kein eingehender Kantensatz heisst `null` — «hier hat niemand etwas gesagt», also fragt
     * {@see resolvedFieldTypes()} weiter oben.*
     *
     * @param  list<int>              $ids
     * @return array<int, ?FieldType>
     */
    public function ownFieldTypes(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $slots = implode(',', array_fill(0, count($ids), '%d'));

        $rows = Query::rows('Kantenarten der eingehenden Kanten lesen', $wpdb->prepare(
            'SELECT to_node_id, kind FROM ' . Schema::table('relations') . " WHERE to_node_id IN ($slots)",
            ...$ids
        )) ?: [];

        $sorten = [];

        foreach ($rows as $row) {
            $ziel = (int) $row['to_node_id'];

            $sorten[$ziel] = ($sorten[$ziel] ?? FieldType::Setting) === FieldType::Setting
                && (string) $row['kind'] === RelationKind::Setting->value
                    ? FieldType::Setting
                    : FieldType::Model;
        }

        foreach ($ids as $id) {
            $sorten[$id] ??= null;
        }

        return $sorten;
    }

    /**
     * Zwei Abfragen für beliebig viele Knoten und beliebige Tiefe.
     *
     * ⚠️ *Erst die angefragten Knoten mit ihrem Pfad, dann **alle** darin genannten Vorfahren mit
     * ihren eingehenden Kanten — eine Abfrage, nicht eine je Stufe (`CD-7`). Danach läuft die
     * Auflösung in PHP über den Pfad von hinten nach vorn.*
     *
     * ⚠️ **Der Lauf ist [D-621](../../../docs/NewConcept/90-decision-log.md)s eigener Satz:** *«wenn
     * man am Vater irgendwas anhaengt, ist es genauso in den Kindern verfuegbar; da bestimmt auch die
     * Kante darueber, wie's beim Vater angehaengt ist.» **Ein Knoten, den nur Vererbung erreicht,
     * bekommt seinen Charakter von der Kante über seinem nächsten Vorfahren, der eine hat.***
     */
    public function resolvedFieldTypes(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $slots = implode(',', array_fill(0, count($ids), '%d'));

        $rows = Query::rows('Pfade für den Sortenlauf lesen', $wpdb->prepare(
            'SELECT id, path FROM ' . Schema::table('nodes') . " WHERE id IN ($slots)",
            ...$ids
        )) ?: [];

        // Jede Id, die in irgendeinem Pfad vorkommt — das sind die Kandidaten für den Lauf.
        $entlang = [];

        foreach ($rows as $row) {
            foreach (explode('.', (string) $row['path']) as $stufe) {
                $entlang[(int) $stufe] = true;
            }
        }

        $sorten = array_filter($this->ownFieldTypes(array_keys($entlang)));

        $aufgeloest = [];

        foreach ($rows as $row) {
            $stufen = array_reverse(explode('.', (string) $row['path']));
            $gefunden = FieldType::standard();

            foreach ($stufen as $stufe) {
                if (isset($sorten[(int) $stufe])) {
                    $gefunden = $sorten[(int) $stufe];

                    break;
                }
            }

            $aufgeloest[(int) $row['id']] = $gefunden;
        }

        // ⚠️ *Eine Id, die es nicht gibt, bekommt trotzdem eine Antwort — der Aufrufer soll nicht
        // zwischen «kein Knoten» und «keine Sorte» unterscheiden müssen, um eine Zeile einzuordnen.*
        foreach ($ids as $id) {
            $aufgeloest[$id] ??= FieldType::standard();
        }

        return $aufgeloest;
    }

    public function byImplementations(array $classNames): array
    {
        global $wpdb;

        $classNames = array_values(array_unique(array_filter(
            array_map(static fn (string $n): string => trim($n), $classNames),
            static fn (string $n): bool => $n !== ''
        )));

        if ($classNames === []) {
            return [];
        }

        $slots = implode(',', array_fill(0, count($classNames), '%s'));

        // ⚠️ *`ORDER BY id` — die kleinste Id gewinnt, wenn zwei Zeilen dieselbe Klasse nennen. Das
        // ist ein Befund und keine Auswahl; der Wächter meldet ihn, dieser Weg bleibt nur stabil.*
        $rows = Query::rows('Knoten nach Klasse lesen', $wpdb->prepare(
            'SELECT ' . self::COLUMNS . self::fromNodes() . "WHERE n.implemented_by IN ($slots) ORDER BY n.id",
            ...[...self::nameArgs(), ...$classNames]
        ));

        $aus = [];

        foreach ($rows ?: [] as $row) {
            $klasse = (string) $row['implemented_by'];

            $aus[$klasse] ??= $this->hydrate($row);
        }

        return $aus;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Node
    {
        return Node::fromStorage(
            (int) $row['id'],
            (int) $row['version'],
            (string) $row['name'],
            (string) $row['path'],
            // ⚠️ *Eine leere Zeichenkette ist `null`. **Zwei
            // Schreibweisen für «nichts» sind der Fehler, den `kind` schon einmal hatte.***
            isset($row['implemented_by']) && (string) $row['implemented_by'] !== ''
                ? (string) $row['implemented_by']
                : null,
            // ⚠️ *Dieselbe Vorsicht wie oben, und hier zählt sie doppelt: `parent_node_id` ist bei der
            // Wurzel echt `NULL`, und eine Abfrage ohne die Spalte darf daraus keine Wurzel machen.
            // Beides liest sich hier als `null` — deshalb steht der Wächter daneben, der die eine
            // Wurzel **zählt** (TASK-018).*
            isset($row['parent_node_id']) && (int) $row['parent_node_id'] !== 0
                ? (int) $row['parent_node_id']
                : null,
            (int) ($row['sort_order'] ?? 0),
            (bool) (int) ($row['hide'] ?? 0),
        );
    }
}
