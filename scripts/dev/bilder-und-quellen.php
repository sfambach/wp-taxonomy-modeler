<?php declare(strict_types=1);

/**
 * Bilder und Quellen am gemeinsamen Vater aller Modelle, Mediathek-Dateien als Id (2026-09-19).
 *
 *     php scripts/dev/bilder-und-quellen.php            # nur zeigen, was geschähe
 *     php scripts/dev/bilder-und-quellen.php --write    # umbauen
 *
 * ⚠️ *Sein Wort: «id, am vater zu allen sollte es bilder geben, ja quellen mit ziehen» (D-865).*
 *
 * 1. Felder «Titelbild» (0..1) und «Bilder» (0..*) vom Typ Media an «Model»; «Quellen» wandert von «Projekte» hinauf zu «Model».
 * 2. Jede Medienadresse, die auf eine hochgeladene Datei dieser Seite zeigt, wird `media:<Id>` — was WordPress nicht zuordnen kann, bleibt.
 * 3. Die Projekte mit einem Beitrag bekommen dessen Beitragsbild als Titelbild und die Bilder im Beitrag als «Bilder».
 *
 * Wiederholbar: jeder Schritt sucht erst, ob es das schon gibt.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\Type\MediaType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);

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
const MODEL = 402, HARDWARE_PROJECT = 18507, PROJEKTE = 149000104440, MEDIA = 149000105224;

$sagen = static function (string $satz): void {
    echo $satz, "\n";
};

$feldAn = static function (int $eigner, string $name) use ($wpdb, $p): ?int {
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
        $eigner,
        $name
    ));

    return $id === null ? null : (int) $id;
};

// ── 1. Felder am Vater ─────────────────────────────────────────────────────────────────────────────
$felder = [];

foreach (['Titelbild' => Multiplicity::ZeroToOne, 'Bilder' => Multiplicity::ZeroToMany] as $name => $wieOft) {
    $felder[$name] = $feldAn(MODEL, $name);

    if ($felder[$name] === null) {
        $sagen("Feld «{$name}» an Model → Media ({$wieOft->value})");

        if ($schreiben) {
            $kante = $editor->addField(MODEL, MEDIA, $name, RelationKind::Composition);

            if ($kante->multiplicity !== $wieOft) {
                $kante = $editor->setMultiplicity(MODEL, $kante->id, $wieOft);
            }

            $felder[$name] = $kante->id;
        }
    }
}

// «Quellen» zwei Stufen hinauf: Projekte → Hardware Project → Model. Die Werte bleiben, es ist dieselbe Kante.
$quellen = $feldAn(MODEL, 'Quellen');

if ($quellen === null) {
    foreach ([PROJEKTE, HARDWARE_PROJECT] as $von) {
        $hier = $feldAn($von, 'Quellen');

        if ($hier !== null) {
            $sagen("Feld «Quellen» von {$von} eine Stufe hinauf");

            if ($schreiben) {
                $editor->moveFieldToParent($von, $hier);
            }
        }
    }

    $quellen = $feldAn(MODEL, 'Quellen');
}

// ── 2. Hochgeladene Dateien als Id ────────────────────────────────────────────────────────────────
$zeilen = $wpdb->get_results(
    "SELECT v.id, v.value_text FROM {$p}relation_records v JOIN {$p}relations r ON r.id = v.relation_id
     WHERE r.to_node_id = " . MEDIA . " AND v.value_text LIKE '%/wp-content/uploads/%'"
);
$umgestellt = 0;
$fremd      = [];

foreach ($zeilen as $zeile) {
    // *Auch eine Adresse von fambach.net zeigt auf dieselbe Datei wie die lokale — WordPress kennt nur den Weg ab `uploads/`.*
    $lokal = (string) preg_replace('#^https?://[^/]+/wp-content/uploads/#', wp_get_upload_dir()['baseurl'] . '/', (string) $zeile->value_text);
    $id    = attachment_url_to_postid($lokal);

    if ($id <= 0) {
        $fremd[] = (string) $zeile->value_text;
        continue;
    }

    ++$umgestellt;

    if ($schreiben) {
        // ⚠️ *Nur die eine Wertzeile, in ihrer Spalte — dieselbe Zeile, dieselbe Stelle; der Wert wird nur anders geschrieben.*
        $wpdb->update("{$p}relation_records", ['value_text' => MediaType::libraryAddress($id)], ['id' => (int) $zeile->id]);
    }
}

$sagen("\n{$umgestellt} hochgeladene Dateien als Id" . ($fremd === [] ? '' : ', nicht zuzuordnen: ' . count($fremd)));

foreach ($fremd as $adresse) {
    $sagen("  bleibt Adresse: {$adresse}");
}

// ── 3. Bilder der Projekte aus ihren Beiträgen ────────────────────────────────────────────────────
$beitraege = [28722 => 14508, 28723 => 12601, 28726 => 14916, 30763 => 15680, 30779 => 13131, 30781 => 14449, 30783 => 15877, 30785 => 16409, 30788 => 13577];

$steht = static function (int $satz, ?int $feld) use ($wpdb, $p): bool {
    return $feld === null || (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
        $satz,
        $feld
    )) > 0;
};

foreach ($beitraege as $satz => $beitrag) {
    $titel  = (int) get_post_thumbnail_id($beitrag);
    $inhalt = (string) get_post_field('post_content', $beitrag);
    // *Die Bilder im Beitrag in ihrer Reihenfolge — jedes Bild-Block trägt seine Id als «wp-image-<Id>».*
    preg_match_all('/wp-image-(\d+)/', $inhalt, $treffer);
    $bilder = array_values(array_unique(array_map('intval', $treffer[1])));
    $bilder = array_values(array_filter($bilder, static fn (int $id): bool => $id !== $titel && wp_attachment_is_image($id)));

    $sagen(sprintf('Satz %d ← Beitrag %d: Titelbild %s, %d Bilder', $satz, $beitrag, $titel > 0 ? '#' . $titel : '—', count($bilder)));

    if (! $schreiben) {
        continue;
    }

    if ($titel > 0 && ! $steht($satz, $felder['Titelbild'])) {
        $data->put($satz, (int) $felder['Titelbild'], TypedValue::ofText(MediaType::libraryAddress($titel)));
    }

    if ($bilder !== [] && ! $steht($satz, $felder['Bilder'])) {
        foreach ($bilder as $id) {
            $data->appendValue($satz, (int) $felder['Bilder'], TypedValue::ofText(MediaType::libraryAddress($id)));
        }
    }
}

$sagen($schreiben ? "\nFertig." : "\nNur gezeigt. Mit --write umbauen.");
