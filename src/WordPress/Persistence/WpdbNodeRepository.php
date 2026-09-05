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
     *
     * ⚠️ **`n.path` steht hier seit Fassung 35 nicht mehr** (TASK-001). *Der Pfad ist keine Spalte
     * mehr, sondern wird beim Lesen aus `parent_node_id` gerechnet — `a.path` kommt aus
     * {@see self::ancestry()}. **Ein `Node` trägt ihn weiter**, und zwar in derselben Form wie zuvor;
     * was fiel, ist die zweite Ablage derselben Tatsache, nicht die Tatsache.*
     */
    private const COLUMNS = "n.id, n.version, COALESCE(t.text_name, '') AS name, a.path, n.implemented_by, n.parent_node_id, n.sort_order, n.hide";

    /**
     * Der Vorfahrenweg, **einmal gerechnet statt gespeichert** (TASK-001,
     * [D-082](../../../docs/NewConcept/90-decision-log.md): «materialised ancestor path, derived and
     * rebuildable»).
     *
     * ```mermaid
     * flowchart LR
     *   W["Wurzel · parent_node_id IS NULL"] --> K["Kind · CONCAT(Weg, '.', id)"]
     *   K --> K
     * ```
     *
     * ⚠️ **Eine Anweisung und keine Runde je Ebene** (`CD-7`). *Ein rekursiver Ausdruck steigt vom
     * einen wurzellosen Knoten abwärts und setzt den Weg dabei zusammen; eine Funktion, die je Stufe
     * fragt, wäre genau das, was die Regel verbietet.*
     *
     * ⚠️ **Er rechnet den **ganzen** Baum, auch wenn nur eine Zeile gesucht ist, und das ist eine
     * bewusste Wahl.** *Ein Aufstieg von der gesuchten Zeile aus wäre billiger, aber sein Anker
     * hinge an der `WHERE`-Bedingung des äusseren Lesers — und die ist bei jedem Leser eine andere.
     * **Ein Ausdruck, den jeder Leser gleich benutzt, ist mehr wert als sechs verschiedene**
     * (`CD-7`: einmal gelöst, an einer Stelle). Gemessen am 2026-09-05 sind es 137 Zeilen.*
     *
     * ⚠️ *`CAST(... AS CHAR(255))` im Anker gibt der Spalte ihre Breite — MySQL nimmt sie von dort
     * und schneidet sonst am ersten Wert ab. **255 ist dieselbe Breite, die die gefallene Spalte
     * hatte**, also kann kein Weg dadurch kürzer werden, als er war.*
     */
    private static function ancestry(): string
    {
        $nodes = Schema::table('nodes');

        return "WITH RECURSIVE taxmod_ahnen (id, path) AS (
                    SELECT id, CAST(id AS CHAR(255)) FROM {$nodes} WHERE parent_node_id IS NULL
                    UNION ALL
                    SELECT k.id, CONCAT(v.path, '.', k.id)
                      FROM {$nodes} k INNER JOIN taxmod_ahnen v ON v.id = k.parent_node_id
                ) ";
    }

    /** Ein Knotenleser: der Vorfahrenausdruck, die Spalten, die Herkunft — und dann seine Bedingung. */
    private static function selectNodes(string $rest): string
    {
        return self::ancestry() . 'SELECT ' . self::COLUMNS . self::fromNodes() . $rest;
    }

    /**
     * ⚠️ **`LEFT JOIN` und kein `JOIN`:** *ein Knoten ohne Beschriftungszeile hätte sonst gar keine
     * Zeile mehr — er wäre unsichtbar statt namenlos, und das ist die schlechtere Störung. Dass es
     * ihn nicht geben darf, hält `label-texts-check.php` fest, nicht dieser Leser.*
     */
    private static function fromNodes(): string
    {
        // ⚠️ *`INNER JOIN` auf den Vorfahrenausdruck und kein `LEFT JOIN`: **ein Knoten, den der
        // Abstieg nicht erreicht, hat keinen Weg zur Wurzel** — er ist verwaist, nicht namenlos, und
        // das ist ein Befund für `orphans-check` und nicht eine Zeile mit leerem Pfad (TASK-001).*
        return ' FROM ' . Schema::table('nodes') . ' n'
            . ' INNER JOIN taxmod_ahnen a ON a.id = n.id'
            . ' LEFT JOIN ' . Schema::table('label_texts') . ' t'
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
            self::selectNodes('WHERE n.id = %d'),
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
            self::selectNodes("WHERE n.id IN ($slots)"),
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

        // ⚠️ *`path` steht hier seit Fassung 35 nicht mehr (TASK-001) — er wird gelesen, nicht
        // geschrieben. Was den Knoten einordnet, ist `parent_node_id`, und das steht schon da.*
        $spalten = [
            'version'        => $node->version,
            'implemented_by' => $node->implementedBy,
            // ⚠️ *Seit TASK-018 kommt die Einordnung mit der Zeile* ([D-581](../../../docs/NewConcept/90-decision-log.md)).
            // *`null` ist die Wurzel und nicht «weiss nicht» — `$wpdb->insert()` schreibt dafür ein
            // echtes NULL, was `prepare('%d', null)` nicht täte.*
            'parent_node_id' => $node->parentNodeId,
            'sort_order'     => $node->sortOrder,
            'hide'           => $node->hide ? 1 : 0,
        ];
        $formate = ['%d', '%s', '%d', '%d', '%d'];

        if ($node->id !== 0) {
            $spalten = ['id' => $node->id, ...$spalten];
            $formate = ['%d', ...$formate];
        }

        $wpdb->insert(Schema::table('nodes'), $spalten, $formate);

        if ($node->id !== 0) {
            $this->writeName($node);

            return $node;
        }

        // ⚠️ *Der Pfad des zurückgegebenen Knotens trug bis eben die `0` an letzter Stelle und wird
        // mit der vergebenen Nummer nachgezogen — **nur noch im Hauptspeicher** (TASK-001). Die zweite
        // Schreibrunde in die Tabelle ist mit der Spalte weggefallen.*
        $node = $node->withAssignedId((int) $wpdb->insert_id);

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
                // ⚠️ *`path` fährt seit Fassung 35 nicht mehr mit (TASK-001) — und damit ist auch die
                // Falle weg, die er hier trug: ein veraltetes Formular kann keinen alten Weg mehr
                // zurückschreiben, weil keiner geschrieben wird.*
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
            ['%d', '%s', '%d', '%d', '%d'],

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
            self::selectNodes(
                'WHERE n.hide = 0 AND n.parent_node_id IN (' . $platzhalter . ')
             ORDER BY n.parent_node_id ASC, n.sort_order ASC, n.id ASC'
            ),
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
            self::selectNodes(
                'WHERE n.parent_node_id = %d ORDER BY n.sort_order ASC, n.id ASC'
            ),
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

        // ⚠️ *`a.path` und nicht `n.path` — der Weg kommt aus {@see self::ancestry()}, seit die
        // Spalte gefallen ist (TASK-001). **Die Bedingung ist dieselbe geblieben**: alles, dessen Weg
        // mit dem der Wurzel und einem Punkt beginnt.*
        $rows = Query::rows('Teilbaum lesen', $wpdb->prepare(
            self::selectNodes('WHERE a.path LIKE %s ORDER BY a.path ASC'),
            ...[...self::nameArgs(), $wpdb->esc_like($root->path . '.') . '%']
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }

    /**
     * ⚠️ **Seit Fassung 35 gibt es hier nichts mehr zu tun, und das ist die ganze Aussage von
     * TASK-001.**
     *
     * *Diese Methode schrieb den Weg jedes Nachfahren um, weil er als Spalte dastand. **Er steht
     * nicht mehr da**: er wird beim Lesen aus `parent_node_id` gerechnet
     * ({@see self::ancestry()}), und `parent_node_id` hat der Aufrufer bereits gesetzt, bevor er
     * hierherkommt. Ein Umzug ändert also **eine** Zeile — die des umgezogenen Knotens —, und die
     * Wege aller Nachfahren stimmen im selben Augenblick.*
     *
     * ⚠️ **Auch der Versionszähler bleibt jetzt stehen, und der Grund dafür fällt mit derselben
     * Spalte.** *Er lief mit, weil «`save()` writes name and path together, so a stale form could
     * rename a node and write its old path back» — ein veraltetes Formular kann keinen Weg mehr
     * zurückschreiben, denn `save()` schreibt keinen. **Eine Version zu heben, ohne dass sich die
     * Zeile ändert, hiesse fünfhundert unveränderte Zeilen in den Schatten zu schreiben** und die
     * Geschichte mit Nichts zu füllen.*
     *
     * ⚠️ *Die Methode bleibt in der Schnittstelle stehen, statt in einem Zug mit gesperrten Dateien
     * zu verschwinden — dass sie fallen sollte, steht als `INF-052` im Eingang (`PR-4`).*
     */
    public function moveSubtree(string $oldPath, string $newPath): void
    {
    }

    public function purgeSubtree(Node $node): void
    {
        global $wpdb;

        $nodes     = Schema::table('nodes');
        $relations = Schema::table('relations');

        // ⚠️ **Der Knoten und alles unter ihm, als Liste von Nummern** (TASK-001). *Vorher stand hier
        // fünfmal `n.path LIKE '<Weg>.%'`. Der Weg ist keine Spalte mehr, und ein `LIKE` auf den
        // gerechneten Weg ginge in einem `DELETE` nicht: **MySQL verbietet, dieselbe Tabelle im
        // Unterausdruck zu lesen, aus der gelöscht wird.** Also **eine** Abfrage vorweg, die den Ast
        // einsammelt, und danach fünf Bedingungen auf dieselbe Liste — kein `LIKE`, keine Runde je
        // Ebene (`CD-7`).*
        $ast    = $this->subtreeIds($node->id);
        $plaetze = implode(',', array_fill(0, count($ast), '%d'));

        // ⚠️ **Erst in den Schatten, dann weg** ([D-536](../../../docs/NewConcept/90-decision-log.md)).
        // *Der Eigentümer: «auch wenn es gelöscht ist, nur mit Löschkennzeichen versehen». **Hier ist
        // es endgültig für die lebende Tabelle und nicht endgültig für die Geschichte** — und der
        // Anlass steht in den Daten: gemessen am 2026-08-30 gab es **8 Datensätze, deren Knoten es
        // nicht mehr gab**, und niemand konnte mehr sagen, was sie bedeuteten.
        Shadow::keep(
            'relations',
            "id IN (SELECT x.id FROM {$relations} x
                    WHERE x.to_node_id IN ({$plaetze}) OR x.from_node_id IN ({$plaetze}))",
            [...$ast, ...$ast],
            true
        );

        Shadow::keep('nodes', "id IN ({$plaetze})", $ast, true);

        // ⚠️ **Die Beschriftungen gehen mit, und das ist seit TASK-019 nicht mehr optional**
        // ([D-580](../../../docs/NewConcept/90-decision-log.md)). *Vorher hatte ein Knoten nur dann
        // eine Beschriftungszeile, wenn jemand einen Text geschrieben hatte; **jetzt hat sie jeder**,
        // weil der Name eine ist. Ein Löschen, das sie stehenlässt, hinterlässt eine Waise je
        // gelöschtem Knoten — gemessen an den Prüfläufen, die genau das taten.*
        $this->forgetLabelsOf(
            "SELECT n.label_id FROM {$nodes} n WHERE n.id IN ({$plaetze})",
            $ast
        );

        $this->forgetLabelsOf(
            "SELECT r.label_id FROM {$relations} r
             WHERE r.to_node_id IN ({$plaetze}) OR r.from_node_id IN ({$plaetze})",
            [...$ast, ...$ast]
        );

        // The relations go first, because a relation row whose node is gone is the dangling
        // reference the whole two-stage deletion exists to avoid. Both are one statement.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$relations}
             WHERE to_node_id IN ({$plaetze}) OR from_node_id IN ({$plaetze})",
            ...[...$ast, ...$ast]
        ));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$nodes} WHERE id IN ({$plaetze})",
            ...$ast
        ));
    }

    /**
     * Ein Knoten und alles unter ihm, als Nummern — **eine Abfrage, nicht eine je Ebene** (`CD-7`).
     *
     * ⚠️ *Der Aufstieg aus {@see self::ancestry()} taugt hier nicht: er rechnet **Wege** und würde
     * wieder auf ein `LIKE` hinauslaufen. Hier steigt derselbe rekursive Ausdruck vom Knoten selbst
     * abwärts und sammelt nur Nummern ein.*
     *
     * ⚠️ *Öffentlich, weil die Randprüfungen dieselbe Frage stellen und sie bisher als
     * `WHERE path LIKE '<Weg>.%'` selbst geschrieben haben. **Ein Ort für die Frage, nicht zwölf**
     * (`CD-7`) — und sie sollen dieselbe Antwort bekommen wie der Kode, den sie prüfen.*
     *
     * @return list<int> Die Nummer des Knotens selbst zuerst; nie leer.
     */
    public function subtreeIds(int $id): array
    {
        global $wpdb;

        $nodes = Schema::table('nodes');

        $rows = Query::column('Ast einsammeln', $wpdb->prepare(
            "WITH RECURSIVE taxmod_ast (id) AS (
                 SELECT id FROM {$nodes} WHERE id = %d
                 UNION ALL
                 SELECT k.id FROM {$nodes} k INNER JOIN taxmod_ast v ON v.id = k.parent_node_id
             )
             SELECT id FROM taxmod_ast",
            $id
        ));

        $ast = array_values(array_unique(array_map('intval', $rows)));

        // ⚠️ *Der Knoten selbst gehört dazu, auch wenn ihn die Abfrage nicht mehr fände — sonst hätte
        // eine leere Liste `IN ()` ergeben, und das ist ein Syntaxfehler, über den `$wpdb` schweigt.*
        return $ast === [] ? [$id] : $ast;
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

        // ⚠️ *Der Weg kommt aus {@see self::ancestry()} statt aus einer Spalte (TASK-001) — die Form
        // ist dieselbe, also ändert sich an der Auflösung darunter nichts.*
        $rows = Query::rows('Pfade für den Sortenlauf lesen', $wpdb->prepare(
            self::ancestry() . 'SELECT n.id, a.path FROM ' . Schema::table('nodes') . ' n'
                . " INNER JOIN taxmod_ahnen a ON a.id = n.id WHERE n.id IN ($slots)",
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
            self::selectNodes("WHERE n.implemented_by IN ($slots) ORDER BY n.id"),
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
