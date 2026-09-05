<?php declare(strict_types=1);

/**
 * Ein Gerüst, das ein Wächter sich selbst baut — und wieder abbaut.
 *
 * ⚠️ **Es gibt das, weil drei Wächter an den Knotennamen des Eigentümers hingen** (TASK-025).
 * *Sein Satz: «warum haben wir einen Check auf Adresse, ich hatte das mal so angelegt, aber das war
 * kein Vertrag.» Und `CLAUDE.md` verbietet es ausdrücklich — «Special-casing by display name,
 * label, path, or a specific node».*
 *
 * ⚠️ **Der Schaden war messbar:** *als er `Adresse` um eine Schachtelung erweiterte, wurden
 * `composition-check` und `page-blocks-check` rot — **ohne dass etwas kaputt war**. Ein Wächter, der
 * bei richtiger Arbeit rot wird, erzieht dazu, rote Wächter zu übersehen.*
 *
 * ⚠️ *Vorbild ist `package3-check` mit seinen `__p3`-Knoten. Der Namensraum `__` sagt: nicht vom
 * Menschen, und {@see abbauen()} nimmt sie wieder weg.*
 *
 * @see docs/pakete/modelltabellen/tasks.md
 */

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\{Schema, SeededFrameworkNodes, WpdbChangelog,
    WpdbNodeRepository, WpdbRecordRepository, WpdbRelationRepository};
use Taxmod\WordPress\SystemClock;

final class Geruest
{
    /**
     * Wo die Id des Testastes eines Astes steht — je Ast einer.
     *
     * ⚠️ *Dasselbe Muster wie die Rahmenwerksknoten ([D-510](../../docs/NewConcept/90-decision-log.md)):
     * **die Id steht in einer Option, nicht der Name im Kode.** Der Behälter heisst `__Test`, aber
     * gefunden wird er nie darüber.*
     */
    private const TESTAST_OPTION_PREFIX = 'taxmod_testast_';

    private const TESTAST_NAME = '__Test';

    private readonly ModelEditor $editor;

    private readonly SeededFrameworkNodes $framework;

    private readonly WpdbNodeRepository $nodes;

    /** @var list<int> Was gebaut wurde, in der Reihenfolge des Bauens. */
    private array $gebaut = [];

    public function __construct(private readonly string $vorsatz = '__geruest')
    {
        $knoten          = new WpdbNodeRepository();
        $kanten          = new WpdbRelationRepository();
        $log             = new WpdbChangelog(new SystemClock());
        $this->nodes     = $knoten;
        $this->framework = new SeededFrameworkNodes($knoten, $kanten, $log);
        $this->editor    = new ModelEditor($knoten, $kanten, $this->framework, $log);
    }

    /**
     * Der Testast eines Astes — ein Behälter, unter dem alles Probeweise liegt (TASK-050, D-614).
     *
     * ⚠️ **Sein Wort:** *«von mir aus könnten sie auch irgendwo bestehen bleiben bis wir live gehen,
     * solange sie nicht den aktuellen Baum kaputt machen und nicht sichtbar oder nur in einem Testast
     * sich befinden.»* **Drei Bedingungen, und der Behälter erfüllt alle drei:**
     *
     * - **Im Testast**: alles Gebaute hängt unter ihm, an einer Stelle statt zwischen seinen Knoten.
     * - **Nicht sichtbar**: seine Vererbungskante ist versteckt, mit dem Mittel des Plugins selbst
     *   ([D-467](../../docs/NewConcept/90-decision-log.md)) — der Baum überspringt einen versteckten
     *   Platz samt allem, was darunter hängt. Kein Schirm musste dafür angefasst werden.
     * - **Den Arbeitsbaum nicht beschädigend**: der Behälter erklärt kein Feld, also erbt niemand
     *   etwas von ihm, und keine echte Kante zeigt hinein.
     *
     * ⚠️ **Warum er im Ast liegt und nicht neben ihm:** *die Speicherregel hängt am Ast des **Ziels**
     * ({@see \Taxmod\WordPress\Persistence\SeededFrameworkNodes::branchOf()}). Ein Behälter ausserhalb
     * jedes Astes hätte keine Semantik, und ein Probeknoten darin wäre kein gültiges Ziel — das
     * Gerüst könnte nichts mehr bauen, was dem echten Fall gleicht.*
     *
     * ⚠️ **Warum er stehenbleibt:** *dreimal an einem Tag lagen Probeknoten in seinem Baum — 60, dann
     * 12, dann 8 —, **jedes Mal nach einem Absturz mitten im Lauf. Wer abstürzt, räumt nicht auf.**
     * Der Behälter fängt genau diesen Rückstand auf; {@see rueckstand()} zählt ihn.*
     */
    public function testast(Branch $ast): int
    {
        $option = self::TESTAST_OPTION_PREFIX . str_replace('-', '_', $ast->value) . '_id';
        $id     = (int) get_option($option, 0);

        if ($id > 0 && $this->nodes->find($id) !== null) {
            return $id;
        }

        $behaelter = $this->editor->createNode(self::TESTAST_NAME, $this->framework->rootOf($ast)->id);

        // Unsichtbar — und zwar über den Platz, nicht über den Knoten (D-467).
        $this->editor->hidePlacement($behaelter->id, true);

        update_option($option, $behaelter->id);

        return $behaelter->id;
    }

    /**
     * Wie viel in den Testästen liegengeblieben ist — der Behälter selbst zählt nicht mit.
     *
     * ⚠️ *Das ist die zweite Hälfte von [D-614](../../docs/NewConcept/90-decision-log.md): der Ast
     * **macht den Rückstand zählbar**, «ohne dass jemand den Baum durchsieht».*
     */
    public function rueckstand(): int
    {
        global $wpdb;

        $summe = 0;

        foreach (Branch::cases() as $ast) {
            $option = self::TESTAST_OPTION_PREFIX . str_replace('-', '_', $ast->value) . '_id';
            $id     = (int) get_option($option, 0);

            if ($id === 0) {
                continue;
            }

            $knoten = $this->nodes->find($id);

            if ($knoten === null) {
                continue;
            }

            // ⚠️ *Seit Fassung 35 gibt es keine Spalte `path` mehr (TASK-001); die Frage «was haengt
            // unter diesem Knoten» stellt der Speicher, damit sie hier dieselbe Antwort bekommt wie
            // im Kode, den dieser Lauf prueft. **Ohne den Knoten selbst**, wie das `LIKE` vorher.*
            $summe += count($this->nodes->subtreeIds($knoten->id)) - 1;
        }

        return $summe;
    }

    /**
     * Den Rückstand wegräumen — die Behälter bleiben, ihr Inhalt geht.
     *
     * ⚠️ *Aufräumen bleibt der Normalfall ([D-614](../../docs/NewConcept/90-decision-log.md)); das
     * hier ist der Nachtrag für die Läufe, die es nicht mehr geschafft haben.*
     */
    public function rueckstandRaeumen(): int
    {
        global $wpdb;

        $weg = 0;

        foreach (Branch::cases() as $ast) {
            $option = self::TESTAST_OPTION_PREFIX . str_replace('-', '_', $ast->value) . '_id';
            $id     = (int) get_option($option, 0);
            $knoten = $id === 0 ? null : $this->nodes->find($id);

            if ($knoten === null) {
                continue;
            }

            // ⚠️ *Ohne den Behaelter selbst — er bleibt stehen, sein Inhalt geht (TASK-001).*
            $ids = array_values(array_diff($this->nodes->subtreeIds($knoten->id), [$knoten->id]));

            $records = new WpdbRecordRepository();

            foreach (array_map(intval(...), $ids) as $weggehend) {
                foreach ($records->ofNode($weggehend) as $satz) {
                    $records->forgetRecord($satz->id);
                }

                $wpdb->query($wpdb->prepare(
                    'DELETE FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d',
                    $weggehend,
                    $weggehend
                ));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $weggehend));
                $weg++;
            }
        }

        return $weg;
    }

    /**
     * Eine Komposition aus lauter Textgliedern, und ein Besitzer, der auf sie zeigt.
     *
     * ⚠️ *Der Besitzer gehört dazu: eine Komposition ohne Verwendungsstelle beantwortet die Frage
     * nicht, ob die Renderkette **durch** sie hindurchgeht.*
     *
     * @param list<string> $felder
     * @return array{besitzer:int, ziel:int, feld:string}
     */
    public function komposition(string $name, array $felder): array
    {
        $text = $this->knotenNamens('Text', Branch::DataTypes);

        $ziel = $this->editor->createNode($this->vorsatz . ' ' . $name, $this->testast(Branch::Compositions));
        $this->gebaut[] = $ziel->id;

        foreach ($felder as $feld) {
            $this->editor->addField($ziel->id, $text, $feld);
        }

        $besitzer = $this->editor->createNode($this->vorsatz . ' Traeger', $this->testast(Branch::Model));
        $this->gebaut[] = $besitzer->id;

        $this->editor->addField($besitzer->id, $ziel->id, $name);

        return ['besitzer' => $besitzer->id, 'ziel' => $ziel->id, 'feld' => $name];
    }

    /**
     * Eine Komposition mit benannten Feldern, die auf beliebige Ziele zeigen duerfen.
     *
     * ⚠️ *Die allgemeine Form von {@see komposition()}: dort sind alle Glieder Text, hier sagt
     * jedes selbst, worauf es zeigt und wie viele. **Damit laesst sich eine Leiter bauen** —
     * einfache Glieder, zusammengesetzte Glieder, eine Sammlung davon — ohne einen einzigen
     * Knoten des Eigentuemers anzufassen (TASK-025).*
     *
     * @param array<string, array{ziel?: int, mult?: string}> $felder
     */
    public function kompositionMit(string $name, array $felder): \Taxmod\Core\Model\Node
    {
        global $wpdb;

        $text = $this->knotenNamens('Text', Branch::DataTypes);
        $ziel = $this->editor->createNode($this->vorsatz . ' ' . $name, $this->testast(Branch::Compositions));
        $this->gebaut[] = $ziel->id;

        foreach ($felder as $feld => $wie) {
            $kante = $this->editor->addField($ziel->id, $wie['ziel'] ?? $text, $feld);

            if (isset($wie['mult'])) {
                $wpdb->update(Schema::table('relations'), ['multiplicity' => $wie['mult']], ['id' => $kante->id]);
            }
        }

        return $this->nodes->byId($ziel->id);
    }

    /**
     * Ein Knoten mit einem Feld darauf, mit gewählter Multiplizität.
     *
     * ⚠️ *Die Multiplizität wird nach dem Anlegen gesetzt, weil {@see ModelEditor::addField()} sie
     * nicht entgegennimmt — sie ist eine Eigenschaft der Kante und keine des Aktes.*
     *
     * @return array{von:int, kante:int}
     */
    public function feldMit(string $vonName, string $feldName, string $multiplicity, ?int $zielId = null): array
    {
        global $wpdb;

        $ziel = $zielId ?? $this->knotenNamens('Text', Branch::DataTypes);
        $von  = $this->editor->createNode($this->vorsatz . ' ' . $vonName, $this->testast(Branch::Model));
        $this->gebaut[] = $von->id;

        $kante = $this->editor->addField($von->id, $ziel, $feldName);

        $wpdb->update(Schema::table('relations'), ['multiplicity' => $multiplicity], ['id' => $kante->id]);

        return ['von' => $von->id, 'kante' => $kante->id];
    }

    /**
     * Ein Kind unter einem gebauten Knoten — der Fall «erbt, erklärt aber nichts selbst».
     *
     * ⚠️ *Es braucht ihn, weil ein Fehler, der nur die **eigene** Deklaration trifft, an einem
     * Knoten mit eigenen Feldern nicht auffällt. Vorher wurden dafür Knoten des Eigentümers
     * herangezogen ([D-613](../../docs/NewConcept/90-decision-log.md)).*
     */
    public function kindVon(int $elternId, string $name): int
    {
        $kind = $this->editor->createNode($this->vorsatz . ' ' . $name, $elternId);
        $this->gebaut[] = $kind->id;

        return $kind->id;
    }

    /**
     * Ein Einstellungsknoten und ein Traeger, der ihn benutzt.
     *
     * ⚠️ *«Einstellung» ist keine Wahl, sondern folgt aus dem Ast — die Oberflaeche sagt es dem
     * Benutzer selbst: «Kind is not a choice, it follows from where the target sits in the tree».
     * Also wird der Knoten unter `Settings` gebaut, und der Rest ergibt sich.*
     *
     * @return array{einstellung:int, traeger:int, feld:string}
     */
    public function einstellung(string $name, string $feld): array
    {
        $einstellung = $this->editor->createNode($this->vorsatz . ' ' . $name, $this->testast(Branch::Settings));
        $this->gebaut[] = $einstellung->id;

        // ⚠️ *Hier stand ein Markieren des Knotens. Es ist mit `nodes.field_type` gefallen
        // (D-621) -- **die Kante unten sagt es jetzt**, und sie sagte es schon vorher auch.*
        $traeger = $this->editor->createNode($this->vorsatz . ' Nutzer', $this->testast(Branch::Model));
        $this->gebaut[] = $traeger->id;

        $kante = $this->editor->addField($traeger->id, $einstellung->id, $feld);

        // Die Kante wird ausdruecklich zur Einstellungskante -- der Ast allein reicht nicht,
        // gemessen kam sonst «aggregation» heraus.
        $this->editor->markAsSetting($traeger->id, $kante->id, true);

        return ['einstellung' => $einstellung->id, 'traeger' => $traeger->id, 'feld' => $feld];
    }

    /**
     * Ein Knoten aus dem Rahmenwerk, über seinen Ast gefunden.
     *
     * ⚠️ *Auch hier nicht blind über den Namen: gesucht wird **im Ast**, damit ein gleichnamiger
     * Knoten woanders — es gibt zwei namens `form` ([D-022](../../docs/NewConcept/90-decision-log.md)) —
     * nicht dazwischenkommt.*
     */
    private function knotenNamens(string $name, Branch $ast): int
    {
        global $wpdb;

        $wurzel = $this->framework->rootOf($ast);

        // ⚠️ *«Unter dieser Astwurzel» kommt seit Fassung 35 aus dem Speicher und nicht aus einem
        // `LIKE` auf eine Spalte, die es nicht mehr gibt (TASK-001).*
        $unten   = array_values(array_diff($this->nodes->subtreeIds($wurzel->id), [$wurzel->id]));
        $plaetze = implode(',', array_fill(0, count($unten), '%d'));

        $id = $unten === [] ? 0 : (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Schema::table('nodes_named') . " WHERE name = %s AND id IN ({$plaetze}) LIMIT 1",
            $name,
            ...$unten
        ));

        if ($id === 0) {
            throw new RuntimeException("Kein «{$name}» unter {$ast->value} — das Geruest kann nicht bauen.");
        }

        return $id;
    }

    /**
     * Alles wieder weg — Knoten, ihre Kanten und ihre Datensätze.
     *
     * ⚠️ **In umgekehrter Reihenfolge, und die Kanten über beide Enden.** *`orphans-check` hat genau
     * das einmal gemeldet: ein Wächter löschte seine Knoten und liess ihre Einstellungen stehen,
     * fünf Zeilen je Lauf.*
     */
    public function abbauen(): void
    {
        global $wpdb;

        $records = new WpdbRecordRepository();

        foreach (array_reverse($this->gebaut) as $id) {
            foreach ($records->ofNode($id) as $satz) {
                $records->forgetRecord($satz->id);
            }

            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d',
                $id,
                $id
            ));

            $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));
        }

        $this->gebaut = [];
    }
}
