<?php declare(strict_types=1);

/**
 * Bezeichnung und Beschreibung an «Model» — für alles, was darunter liegt (2026-09-19).
 *
 *     php scripts/dev/bezeichnung-an-model.php            # nur zeigen
 *     php scripts/dev/bezeichnung-an-model.php --write    # umbauen
 *
 * ⚠️ *Seine Worte: «hat nicht alles einen titel und beschreibung ?», dann «ja bau das, kontakt ohne, nein ich würde bezeichnung schon
 * als muss feld sehen jeder sollte das haben» (D-883, INF-061).*
 *
 * 1. «Hardware › Bezeichnung» wandert Stufe für Stufe hinauf bis «Model» (D-750: die Kante behält ihre Id, also bleiben Werte und
 *    Einstellungen). «Projekte › Beschreibung» ebenso.
 * 2. An «Model» stehen beide vorn: Bezeichnung, Beschreibung, dann Titelbild, Bilder, Quellen, Herkunft, Herkunftshinweis.
 * 3. Die übrigen Namensfelder gehen mit ihren Werten in «Bezeichnung» auf, die übrigen Beschreibungen in «Beschreibung»; die alten
 *    Kanten werden entfernt (geparkt, D-128). «Kontact › Name» bleibt — sein Wort «kontakt ohne».
 * 4. Einstellungen, die auf eine alte Kante zeigten (Zusammenfassungen), zeigen auf die neue.
 *
 * Wiederholbar: was schon oben steht oder schon leer ist, wird übersprungen.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
/** @var \Taxmod\Core\Service\SettingsEditor $attributes */
$attributes = (new ReflectionProperty($screen, 'attributes'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-19
const MODEL = 402, BEZEICHNUNG = 149000103839, BESCHREIBUNG = 149000107372;

/** Kante ⇒ wohin ihre Werte gehen. */
$aufgehen = [
    149000108181 => BEZEICHNUNG,   // Quellenverzeichnis › Titel
    149000104235 => BEZEICHNUNG,   // Prozessor-Familien › Name
    149000107978 => BEZEICHNUNG,   // Schnittstellen › Bezeichnung
    149000104347 => BEZEICHNUNG,   // Projekte › Project Name
    149000108481 => BEZEICHNUNG,   // Seitenvorlagen › Name
    149000105893 => BEZEICHNUNG,   // Platine › Name
    149000107749 => BEZEICHNUNG,   // Formfaktoren › Bezeichnung
    149000104313 => BESCHREIBUNG,  // Electronic Parts › Beschreibung
    149000105895 => BESCHREIBUNG,  // Platine › Beschreibung
];

$besitzer = static fn (int $kante): int => (int) $wpdb->get_var($wpdb->prepare("SELECT from_node_id FROM {$p}relations WHERE id = %d", $kante));

// ── 1. Hinauf ──────────────────────────────────────────────────────────────────────────────────────
foreach ([BEZEICHNUNG, BESCHREIBUNG] as $kante) {
    for ($stufe = 0; $stufe < 10 && ($jetzt = $besitzer($kante)) !== MODEL; $stufe++) {
        echo "Kante {$kante} von {$jetzt} einen Knoten hinauf\n";

        if (! $schreiben) {
            break;
        }

        $editor->moveFieldToParent($jetzt, $kante);
    }
}

// ── 2. Vorn an «Model» ─────────────────────────────────────────────────────────────────────────────
if ($schreiben) {
    foreach ([BEZEICHNUNG => 0, BESCHREIBUNG => 1] as $kante => $ziel) {
        for ($i = 0; $i < 20; $i++) {
            $eigene = array_values(array_filter($editor->fieldsOf(MODEL), static fn ($r): bool => ! $r->isSetting() && $r->fromNodeId === MODEL));
            $stelle = array_search($kante, array_map(static fn ($r): int => $r->id, $eigene), true);

            if ($stelle === false || $stelle <= $ziel) {
                break;
            }

            $editor->moveField(MODEL, $kante, -1);
        }
    }
}

// ── 3. Aufgehen ────────────────────────────────────────────────────────────────────────────────────
$entfernen = [];

foreach ($aufgehen as $alt => $neu) {
    $eigner = $besitzer($alt);
    $zeilen = $wpdb->get_results($wpdb->prepare(
        "SELECT v.node_record_id, v.locale, v.value_text FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
         WHERE v.relation_id = %d AND v.value_text IS NOT NULL AND v.value_text <> '' AND r.record_type = 'user'",
        $alt
    ));
    $kante  = $editor->relationById($alt);

    if ($kante === null || $kante->hide) {
        continue;
    }

    echo "«{$kante->name}» an {$eigner}: " . count($zeilen) . " Werte → " . ($neu === BEZEICHNUNG ? 'Bezeichnung' : 'Beschreibung') . "\n";

    if (! $schreiben) {
        continue;
    }

    foreach ($zeilen as $zeile) {
        $steht = $wpdb->get_var($wpdb->prepare(
            "SELECT value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d AND locale = %s",
            $zeile->node_record_id,
            $neu,
            $zeile->locale
        ));

        // *Steht schon etwas Eigenes da, gewinnt es nicht still — beides bleibt lesbar im Hinweis dieses Laufs.*
        if ($steht !== null && $steht !== '' && $steht !== $zeile->value_text) {
            echo "  ⚠ Satz #{$zeile->node_record_id}: «{$steht}» steht schon, «{$zeile->value_text}» nicht übernommen\n";
            continue;
        }

        // ⚠️ *Ein Satz im Papierkorb erbt nichts mehr — sein Wert bleibt an der alten Kante und wird mit ihr geparkt (gemessen: «Leiterplatte»).*
        try {
            $data->put((int) $zeile->node_record_id, $neu, TypedValue::ofText((string) $zeile->value_text), (string) $zeile->locale);
        } catch (\Taxmod\Core\Exception\NotYetStorable $nichtHier) {
            echo "  übersprungen: Satz #{$zeile->node_record_id} — {$nichtHier->getMessage()}\n";
            continue;
        }

        $data->clear((int) $zeile->node_record_id, $alt, (string) $zeile->locale);
    }

    $entfernen[$alt] = $eigner;
}

// ── 4. Einstellungen ───────────────────────────────────────────────────────────────────────────────
$verweise = $wpdb->get_results(
    "SELECT DISTINCT node_id, relation_id FROM {$p}settings_value WHERE attribut = 'summary_fields' AND aktiv = 1 AND wert_kante_id IN ("
    . implode(',', array_map('intval', array_keys($aufgehen))) . ')'
);

foreach ($verweise as $v) {
    $knoten = $editor->find((int) $v->node_id);
    $an     = $v->relation_id === null ? null : $editor->relationById((int) $v->relation_id);
    $liste  = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT wert_kante_id FROM {$p}settings_value WHERE attribut = 'summary_fields' AND aktiv = 1 AND node_id = %d AND "
        . ($v->relation_id === null ? 'relation_id IS NULL' : 'relation_id = ' . (int) $v->relation_id) . ' ORDER BY position, id',
        (int) $v->node_id
    )));
    $neu    = array_values(array_unique(array_map(static fn (int $k): int => $aufgehen[$k] ?? $k, $liste)));

    echo "Zusammenfassung an {$knoten?->name}" . ($an === null ? '' : " (Feld «{$an->name}»)") . ': ' . implode(',', $liste) . ' → ' . implode(',', $neu) . "\n";

    if ($schreiben && $knoten !== null) {
        $attributes->setMembers($knoten, 'summary_fields', $neu, $an);
    }
}

// ── 5. Die alten Kanten parken — erst jetzt: eine Einstellung, die noch auf eine zeigt, verbietet das Parken (gemessen) ──────────
if ($schreiben) {
    // ⚠️ *`setMembers` schaltet ein abgewähltes Glied nur ab (aktiv = 0); die Zeile zeigt weiter auf die alte Kante und hält sie fest.
    // Sie wird vergessen wie in revision-migrate.php.*
    $settings = (new ReflectionProperty($attributes, 'settings'))->getValue($attributes);

    foreach ($wpdb->get_col(
        "SELECT id FROM {$p}settings_value WHERE aktiv = 0 AND wert_kante_id IN (" . implode(',', array_map('intval', array_keys($aufgehen))) . ')'
    ) as $abgewaehlt) {
        $settings->forgetValue((int) $abgewaehlt);
    }
}

foreach ($entfernen as $alt => $eigner) {
    $editor->removeField($eigner, $alt);
}

echo $schreiben ? "fertig\n" :"— nur gezeigt. Mit --write umbauen.\n";
