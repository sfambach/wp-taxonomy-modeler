<?php declare(strict_types=1);

/**
 * Alternative Bezeichnungen der Netzteilstecker: andere Namen, kompatible und nur ähnliche Serien (2026-09-19).
 *
 *     php scripts/dev/steckverbinder-alternativen.php <steckverbinder.json> <steckverbinder-alternativen.json>            # nur zeigen
 *     php scripts/dev/steckverbinder-alternativen.php <steckverbinder.json> <steckverbinder-alternativen.json> --write    # anlegen
 *
 * ⚠️ *Sein Wort: «wenn es alternative beschreibungen für die stecker gibt sowas ph jst bitte auch mit aufnehmen» (D-873). Eine Bezeichnung
 * sagt, wie sie zum Stecker steht — «gleiche Sache», «kompatibel» oder «nur ähnlich»: ein Stecker mit gleichem Raster, der nicht passt,
 * darf nicht wie ein Ersatz aussehen.*
 *
 * 1. Auswahl «Bezeichnungsbeziehungen» unter den allgemeinen Konstanten.
 * 2. Teil «Alternative Bezeichnung» (Bezeichnung, Beziehung, Hinweis); Feld «Alternative Bezeichnungen» am Vater «Electronic Parts».
 * 3. Je Stecker: die Namen aus der ersten Recherche («auch genannt», gleiche Sache) und die aus der zweiten, mit ihrer Quelle.
 *    Der Satz «Auch genannt: …» im Feld «Einsatz» entfällt dann — er steht jetzt strukturiert da.
 *
 * Wiederholbar: eine Bezeichnung, die am Stecker schon steht, wird nicht noch einmal angelegt.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Choice;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben    = in_array('--write', $argv, true);
$stecker      = json_decode((string) file_get_contents($argv[1] ?? ''), true) ?: exit("keine Steckerdaten\n");
$alternativen = json_decode((string) file_get_contents($argv[2] ?? ''), true) ?: exit("keine Alternativen\n");

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\ModelEditor $editor */
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);
/** @var \Taxmod\Core\Service\DataEntry $data */
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-19
const TEILE = 3634, STECKVERBINDER = 149000104423, KOMPOSITIONEN = 404, KONSTANTEN = 410, MODEL = 402, TEXT = 1175;
const BESCHREIBUNG = 149000104313;

$sagen = static function (string $satz): void {
    echo $satz, "\n";
};

$knoten = static function (string $name, int $vater, string $klasse) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $name, $vater));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Knoten «{$name}» unter {$vater}");

    return $schreiben ? $editor->createNode($name, $vater, $klasse)->id : null;
};

$feldAn = static function (int $eigner, string $name) use ($wpdb, $p): ?int {
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
        $eigner,
        $name
    ));

    return $id === null ? null : (int) $id;
};

$feld = static function (?int $eigner, ?int $ziel, string $name, RelationKind $art, Multiplicity $wieOft) use ($editor, $feldAn, $schreiben, $sagen): ?int {
    if ($eigner === null || $ziel === null) {
        return null;
    }

    $steht = $feldAn($eigner, $name);

    if ($steht !== null) {
        return $steht;
    }

    $sagen("Feld «{$name}» an {$eigner} ({$wieOft->value})");

    if (! $schreiben) {
        return null;
    }

    $kante = $editor->addField($eigner, $ziel, $name, $art);

    if ($kante->multiplicity !== $wieOft) {
        $kante = $editor->setMultiplicity($eigner, $kante->id, $wieOft);
    }

    return $kante->id;
};

// ── Struktur ───────────────────────────────────────────────────────────────────────────────────────
$beziehungen = $knoten('Bezeichnungsbeziehungen', KONSTANTEN, Choice::class);
$beziehung   = [];

foreach (['gleiche Sache', 'kompatibel', 'nur ähnlich'] as $name) {
    $beziehung[$name] = $beziehungen === null ? null : $knoten($name, $beziehungen, Constant::class);
}

$teil        = $knoten('Alternative Bezeichnung', KOMPOSITIONEN, Category::class);
$tName       = $feld($teil, TEXT, 'Bezeichnung', RelationKind::Composition, Multiplicity::ExactlyOne);
$tBeziehung  = $feld($teil, $beziehungen, 'Beziehung', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$tHinweis    = $feld($teil, TEXT, 'Hinweis', RelationKind::Composition, Multiplicity::ZeroToOne);
$fAlternativ = $feld(TEILE, $teil, 'Alternative Bezeichnungen', RelationKind::Composition, Multiplicity::ZeroToMany);
$fEinsatz    = $feldAn(TEILE, 'Einsatz');
$fQuellen    = $feldAn(MODEL, 'Quellen');

// ── Quellen ────────────────────────────────────────────────────────────────────────────────────────
$verzeichnis = (int) $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name = 'Quellenverzeichnis' AND parent_node_id = " . MODEL);
$qTitel      = $feldAn($verzeichnis, 'Titel');
$qAdresse    = $feldAn($verzeichnis, 'Adresse');
$qArt        = $feldAn($verzeichnis, 'Art');
$qAbgerufen  = $feldAn($verzeichnis, 'Abgerufen');
$webseite    = $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name = 'Webseite' AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Quellenarten' LIMIT 1)");
$forum       = $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name = 'Forum' AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Quellenarten' LIMIT 1)");
$quelle      = static function (string $url) use ($wpdb, $p, $data, $verzeichnis, $qTitel, $qAdresse, $qArt, $qAbgerufen, $webseite, $forum): int {
    $steht = $wpdb->get_var($wpdb->prepare("SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text = %s LIMIT 1", $qAdresse, $url));

    if ($steht !== null) {
        return (int) $steht;
    }

    $neu = $data->create($verzeichnis, RecordType::User);
    $data->put($neu->id, (int) $qTitel, TypedValue::ofText((string) (parse_url($url, PHP_URL_HOST) ?: $url) . ': ' . basename((string) parse_url($url, PHP_URL_PATH))));
    $data->put($neu->id, (int) $qAdresse, TypedValue::ofText($url));
    $art = preg_match('~forum|vogons|reddit|stackexchange|eevblog~i', $url) === 1 ? $forum : $webseite;

    if ($art !== null) {
        $data->put($neu->id, (int) $qArt, TypedValue::ofReference((int) $art));
    }

    $data->put($neu->id, (int) $qAbgerufen, TypedValue::ofDate('2026-09-19 00:00:00'));

    return $neu->id;
};

// ── Je Stecker ─────────────────────────────────────────────────────────────────────────────────────
$schluessel = ['P8/P9' => 0, 'Laufwerk 4-polig' => 1, 'Floppy 4-polig' => 2, 'P4' => 3];
$zuStecker  = [];

foreach ($alternativen as $eintrag) {
    $zuStecker[$schluessel[(string) $eintrag['stecker']] ?? -1] = (array) $eintrag['alternativen'];
}

foreach ($stecker as $i => $s) {
    $name = (string) $s['bezeichnung'];
    $satz = $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s",
        STECKVERBINDER,
        BESCHREIBUNG,
        $name
    ));

    $sagen("\n■ {$name}" . ($satz === null ? ' — nicht gefunden' : ''));

    if ($satz === null) {
        continue;
    }

    $satz  = (int) $satz;
    $liste = [];

    foreach ((array) ($s['auch_genannt'] ?? []) as $n) {
        $liste[mb_strtolower(trim((string) $n))] = ['name' => trim((string) $n), 'art' => 'gleiche Sache', 'hinweis' => '', 'quelle' => ''];
    }

    foreach ($zuStecker[$i] ?? [] as $a) {
        $klein = mb_strtolower(trim((string) $a['name']));

        // *Ein Name der ersten Recherche, der in einem der zweiten steckt («Molex 8981» in «Molex 8981 ("Disk Drive Power")»), ist derselbe.*
        foreach (array_keys($liste) as $alt) {
            if ($liste[$alt]['quelle'] === '' && str_contains($klein, $alt)) {
                unset($liste[$alt]);
            }
        }

        $liste[$klein] = [
            'name'    => trim((string) $a['name']),
            // *Die Recherche schrieb «gleiches Ding» — dieselbe Beziehung wie «gleiche Sache».*
            'art'     => (string) $a['art'] === 'gleiches Ding' ? 'gleiche Sache' : (string) $a['art'],
            'hinweis' => trim((string) ($a['hinweis'] ?? '')),
            'quelle'  => trim((string) ($a['quelle'] ?? '')),
        ];
    }

    $vorhanden = $tName === null ? [] : array_map('mb_strtolower', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT n.value_text FROM {$p}relation_records v JOIN {$p}relation_records n ON n.node_record_id = v.value_ref AND n.relation_id = %d
         WHERE v.node_record_id = %d AND v.relation_id = %d",
        $tName,
        $satz,
        (int) $fAlternativ
    )));

    foreach ($liste as $klein => $a) {
        if (in_array($klein, $vorhanden, true)) {
            continue;
        }

        $sagen("  {$a['name']} — {$a['art']}" . ($a['hinweis'] === '' ? '' : " ({$a['hinweis']})"));

        if (! $schreiben) {
            continue;
        }

        $neu = $data->createPart($satz, (int) $fAlternativ);
        $data->put($neu->id, (int) $tName, TypedValue::ofText($a['name']));

        if (($beziehung[$a['art']] ?? null) !== null) {
            $data->put($neu->id, (int) $tBeziehung, TypedValue::ofReference((int) $beziehung[$a['art']]));
        }

        if ($a['hinweis'] !== '') {
            $data->put($neu->id, (int) $tHinweis, TypedValue::ofText($a['hinweis']));
        }

        if ($a['quelle'] !== '' && $fQuellen !== null) {
            $q = $quelle($a['quelle']);
            $schon = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d AND value_ref = %d",
                $satz,
                $fQuellen,
                $q
            ));

            if ($schon === 0) {
                $data->appendValue($satz, $fQuellen, TypedValue::ofRecordReference($q));
            }
        }
    }

    // *«Auch genannt: …» stand im Einsatz-Text; jetzt steht es strukturiert da (D-873).*
    if ($schreiben && $fEinsatz !== null) {
        $einsatz = (string) $wpdb->get_var($wpdb->prepare("SELECT value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $satz, $fEinsatz));
        $ohne    = trim((string) preg_replace('/\s*Auch genannt:.*$/su', '', $einsatz));

        if ($ohne !== $einsatz && $ohne !== '') {
            $data->put($satz, $fEinsatz, TypedValue::ofText($ohne));
        }
    }
}

$sagen($schreiben ? "\nFertig." : "\nNur gezeigt. Mit --write anlegen.");
