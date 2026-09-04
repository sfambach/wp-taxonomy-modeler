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

        $ziel = $this->editor->createNode($this->vorsatz . ' ' . $name, $this->framework->rootOf(Branch::Compositions)->id);
        $this->gebaut[] = $ziel->id;

        foreach ($felder as $feld) {
            $this->editor->addField($ziel->id, $text, $feld);
        }

        $besitzer = $this->editor->createNode($this->vorsatz . ' Traeger', $this->framework->rootOf(Branch::Model)->id);
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
        $ziel = $this->editor->createNode($this->vorsatz . ' ' . $name, $this->framework->rootOf(Branch::Compositions)->id);
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
        $von  = $this->editor->createNode($this->vorsatz . ' ' . $vonName, $this->framework->rootOf(Branch::Model)->id);
        $this->gebaut[] = $von->id;

        $kante = $this->editor->addField($von->id, $ziel, $feldName);

        $wpdb->update(Schema::table('relations'), ['multiplicity' => $multiplicity], ['id' => $kante->id]);

        return ['von' => $von->id, 'kante' => $kante->id];
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
        $einstellung = $this->editor->createNode($this->vorsatz . ' ' . $name, $this->framework->rootOf(Branch::Settings)->id);
        $this->gebaut[] = $einstellung->id;

        // Der Ast allein markiert nicht -- D-518 hat die Spalte gesetzt, nicht abgeleitet.
        $this->editor->setKind($einstellung->id, \Taxmod\Core\Model\NodeKind::Setting);

        $traeger = $this->editor->createNode($this->vorsatz . ' Nutzer', $this->framework->rootOf(Branch::Model)->id);
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

        $id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name = %s AND path LIKE %s LIMIT 1',
            $name,
            $wpdb->esc_like($this->nodes->byId($wurzel->id)->path . '.') . '%'
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
                'DELETE FROM ' . Schema::table('relations') . ' WHERE from_id = %d OR to_id = %d',
                $id,
                $id
            ));

            $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));
        }

        $this->gebaut = [];
    }
}
