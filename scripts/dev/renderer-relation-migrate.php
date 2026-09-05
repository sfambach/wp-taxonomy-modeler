<?php declare(strict_types=1);

/**
 * Die Wanderung aus TASK-057: der Renderer zieht aus der Spalte `nodes.settings_record_id` auf eine
 * gewöhnliche **Einstellungskante** `renderer` mit Mehrfachheit `1..1`.
 *
 * ⚠️ **Es ist eine Umkehrung und keine Erfindung** ([D-642](../../docs/NewConcept/90-decision-log.md)).
 * *Der Eigentümer hat berichtigt, was ich aus [D-584](../../docs/NewConcept/90-decision-log.md)
 * gemacht hatte: «das hast du leider falsch verstanden, ich meinte einfach eine Multiplizität von 1»
 * — und auf die Rückfrage, wo: «am Knoten». **Der Schluss «also braucht es keine Kante» war meiner.***
 *
 * ⚠️ **Der Datensatz wird *weitergereicht*, nicht neu angelegt.** *Gemessen am 2026-09-05 tragen zwei
 * der 29 Trägersätze eigene Wertzeilen — `Integer → slider` und `Base units → table`. Ein neuer Satz
 * hätte sie stillschweigend verloren.*
 *
 * ⚠️ **Der Massstab ist, was jeder Knoten zeichnet, nicht wie viele Zeilen umziehen.** *Vorher und
 * nachher wird über die **echte Auflösung** ({@see \Taxmod\Core\Service\ModelValues::forNode()}) je
 * Knoten der Renderername abgenommen. Weicht einer ab, nimmt dieses Skript alles zurück.*
 *
 * ⚠️ *Was hier ausdrücklich **nicht** entsteht: irgendetwas an einer Kante. Der Renderer fällt dort
 * ersatzlos ([D-643](../../docs/NewConcept/90-decision-log.md)) — `relations.settings_record_id` und
 * `relations.target_settings_record_id` hatten je 0 Zeilen.*
 *
 *     php scripts/dev/renderer-relation-migrate.php            (nur zaehlen)
 *     php scripts/dev/renderer-relation-migrate.php --go       (schreiben)
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null || str_starts_with((string) $root, '--')) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$go = in_array('--go', $argv, true);
$p  = $wpdb->prefix . 'taxmod_';

$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$clock     = new SystemClock();
$log       = new WpdbChangelog($clock);
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$records   = new WpdbRecordRepository();
$editor    = new ModelEditor($nodes, $relations, $framework, $log, records: $records);

/**
 * Der Renderername je Knoten — der Massstab dieser Wanderung.
 *
 * ⚠️ **Der Abdruck *vorher* kann nicht aus dem heutigen Leser kommen, und das ist keine Nachlässigkeit
 * — der Leser der Spalte ist in diesem Umbau schon gefallen.** *Also steht die alte Auflösung hier
 * einmal ausgeschrieben: **die Spalte des nächsten Vorfahren, der eine trägt, gewinnt**, und erst
 * wenn keiner eine trägt, antwortet {@see \Taxmod\Core\Service\ModelValues}. Genau die Reihenfolge,
 * die {@see \Taxmod\Core\Service\ModelValues::stufe()} hatte, solange es die Spalte gab.*
 *
 * ⚠️ *Nach der Wanderung ist keine Spalte mehr gefüllt, und dieselbe Funktion liefert dann
 * ausschliesslich die Antwort der Kantenform. **Beide Male derselbe Massstab, ohne einen zweiten
 * Kode für das Nachher.***
 */
$abdruck = static function () use ($nodes, $relations, $records, $framework, $wpdb, $p): array {
    $werte = new ModelValues($records, $relations, $nodes, $framework);
    $aus   = [];

    $spalten = [];

    foreach ($wpdb->get_results("SELECT n.id, z.name FROM {$p}nodes n JOIN {$p}node_records s ON s.id = n.settings_record_id JOIN {$p}nodes z ON z.id = s.node_id", ARRAY_A) as $row) {
        $spalten[(int) $row['id']] = (string) $row['name'];
    }

    foreach ($wpdb->get_col("SELECT id FROM {$p}nodes ORDER BY id") as $id) {
        $knoten = $nodes->find((int) $id);

        if ($knoten === null) {
            continue;
        }

        $ausSpalte = null;

        // ⚠️ *Von nah nach fern — `inheritanceOwnersOf()` gibt oben zuerst.*
        foreach (array_reverse($framework->inheritanceOwnersOf($knoten)) as $stufe) {
            if (isset($spalten[$stufe])) {
                $ausSpalte = $spalten[$stufe];

                break;
            }
        }

        $aus[(int) $id] = $ausSpalte ?? ($werte->forNode($knoten)['renderer'] ?? null)?->value->text;
    }

    return $aus;
};

// ---------------------------------------------------------------- messen

$traeger = $wpdb->get_results(
    "SELECT n.id, n.name, n.settings_record_id AS satz, s.node_id AS renderer, z.name AS renderername
       FROM {$p}nodes n
       JOIN {$p}node_records s ON s.id = n.settings_record_id
       LEFT JOIN {$p}nodes z ON z.id = s.node_id
      WHERE n.settings_record_id IS NOT NULL
      ORDER BY n.id",
    ARRAY_A
);

$kanteAnKanten = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relations WHERE settings_record_id IS NOT NULL OR target_settings_record_id IS NOT NULL");

printf("Traeger am Knoten:            %d\n", count($traeger));
printf("Traeger an Kanten:            %d  (fallen ersatzlos, D-643)\n", $kanteAnKanten);

$vorher = $abdruck();
$zeichnend = array_filter($vorher, static fn (?string $n): bool => $n !== null);

printf("Knoten, die einen Renderer aufloesen: %d von %d\n\n", count($zeichnend), count($vorher));

foreach ($traeger as $t) {
    printf("   %6d %-26s → %s\n", $t['id'], $t['name'], $t['renderername'] ?? ('?' . $t['renderer']));
}

$vorhandene = $framework->settingRelationId(SettingKey::Renderer);

printf("\nEinstellungskante `renderer` heute: %s\n", $vorhandene !== 0 ? (string) $vorhandene : 'keine — sie wird angelegt');

if (! $go) {
    echo "\n— Probelauf. Mit --go wird geschrieben. —\n";

    exit(0);
}

// ---------------------------------------------------------------- wandern

// ⚠️ *Eine Änderungsgruppe über alles: die Kante, die 29 Verweise und die geleerten Spalten sind
// **ein** Akt, und Rückgängig muss sie als einen sehen ([D-634](../../docs/NewConcept/90-decision-log.md)).*
$log->beginAct();

$angelegt = 0;
$rendererKnoten = $nodes->find((int) get_option('taxmod_render_renderer_id', 0));

if ($rendererKnoten === null) {
    fwrite(STDERR, "ABBRUCH: der Knoten `Renderer` ist nicht auffindbar.\n");

    exit(1);
}

$kanteId = $vorhandene;

if ($kanteId === 0) {
    $kante = $editor->addField($framework->root()->id, $rendererKnoten->id, 'renderer');
    $editor->markAsSetting($framework->root()->id, $kante->id, true);

    // ⚠️ **Das ist die Zahl, um die es die ganze Zeit ging:** *«ich meinte einfach eine
    // Multiplizität von 1» ([D-642](../../docs/NewConcept/90-decision-log.md)).*
    $editor->setMultiplicity($framework->root()->id, $kante->id, Multiplicity::ExactlyOne);

    $kanteId = $kante->id;
    $angelegt = 1;

    $framework->rememberSettingRelations(SettingKey::Renderer, $kanteId, 0);
}

printf("Einstellungskante `renderer`: %d%s\n", $kanteId, $angelegt === 1 ? ' (neu)' : ' (vorhanden)');

$zurueck = [];   // Knoten-Id => Satz-Id, für die Rücknahme
$fehler  = null;

foreach ($traeger as $t) {
    $knotenId = (int) $t['id'];
    $satzId   = (int) $t['satz'];

    $default = 0;

    foreach ($records->ofNode($knotenId) as $satz) {
        if ($satz->recordType === RecordType::Default && $satz->id !== $satzId) {
            $default = $satz->id;

            break;
        }
    }

    if ($default === 0) {
        $fehler = "Knoten {$knotenId} ({$t['name']}) hat keinen eigenen default-Satz";

        break;
    }

    // ⚠️ *Der **vorhandene** Satz wird gehängt, nicht ein neuer angelegt — zwei der 29 tragen
    // eigene Wertzeilen, und ein neuer Satz hätte sie verloren.*
    $records->putValue(new RelationRecord(
        $default,
        (string) $kanteId,
        $kanteId,
        '',
        TypedValue::ofRecordReference($satzId)
    ));

    // ⚠️ *Roh und nicht über den Speicher: der Schreiber der Spalte ist mit TASK-057 schon gefallen,
    // und die Spalte selbst fällt einen Schritt später. **Ein Speicher für eine sterbende Spalte
    // wieder einzusetzen, um sie zu leeren, wäre der Umweg** — hier steht der einzige Ort, der sie
    // nach der Wanderung noch anfasst.*
    $wpdb->query($wpdb->prepare("UPDATE {$p}nodes SET settings_record_id = NULL WHERE id = %d", $knotenId));

    $zurueck[$knotenId] = $satzId;
}

$log->endAct();

if ($fehler !== null) {
    fwrite(STDERR, "\nABBRUCH: {$fehler}\n");
}

// ---------------------------------------------------------------- nachmessen

$nachher = $abdruck();

$abweichend = [];

foreach ($vorher as $id => $name) {
    if (($nachher[$id] ?? null) !== $name) {
        $abweichend[$id] = [$name, $nachher[$id] ?? null];
    }
}

printf("\nGewanderte Traeger: %d\n", count($zurueck));
printf("Gefuellte nodes.settings_record_id danach: %d\n", (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE settings_record_id IS NOT NULL"));
printf("Knoten, die einen Renderer aufloesen: %d (vorher %d)\n", count(array_filter($nachher, static fn (?string $n): bool => $n !== null)), count($zeichnend));
printf("Knoten, die etwas anderes zeichnen als vorher: %d\n", count($abweichend));

if ($abweichend === [] && $fehler === null) {
    echo "\nGeschrieben. Jeder Knoten zeichnet dasselbe wie vorher.\n";

    exit(0);
}

foreach ($abweichend as $id => [$alt, $neu]) {
    printf("   %6d  vorher %-16s nachher %s\n", $id, $alt ?? '—', $neu ?? '—');
}

// ⚠️ **Zurück, und zwar vollständig** — *die Wanderung bricht ab, wenn ein einziger Knoten etwas
// anderes zeichnet als vorher. Der Zustand davor ist wiederherstellbar, weil er aus zwei Teilen
// besteht: der Spalte und der Verweiszeile.*
fwrite(STDERR, "\nRUECKNAHME — die Wanderung wird zurueckgedreht.\n");

$log->beginAct();

foreach ($zurueck as $knotenId => $satzId) {
    $wpdb->query($wpdb->prepare("UPDATE {$p}nodes SET settings_record_id = %d WHERE id = %d", $satzId, $knotenId));

    $default = 0;

    foreach ($records->ofNode($knotenId) as $satz) {
        if ($satz->recordType === RecordType::Default && $satz->id !== $satzId) {
            $default = $satz->id;

            break;
        }
    }

    if ($default !== 0) {
        $records->forgetValue($default, (string) $kanteId, '');
    }
}

if ($angelegt === 1) {
    $editor->removeField($framework->root()->id, $kanteId);
    delete_option('taxmod_setting_edge_' . SettingKey::Renderer->value);
    delete_option('taxmod_setting_value_edge_' . SettingKey::Renderer->value);
}

$log->endAct();

fwrite(STDERR, "Zurueckgedreht. Nichts ist gewandert.\n");

exit(1);
