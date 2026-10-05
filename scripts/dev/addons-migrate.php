<?php declare(strict_types=1);

/**
 * Die Vorbelegung und «Mehrere hinzufügen» ziehen in die Zusatzfunktionen um ([D-845](../../docs/NewConcept/90-decision-log.md)).
 *
 * *Vorher standen sie als Listen `preset_field`, `preset_source`, `preset_mode` und `pick_field` an jeder Kategorie. Jedes Paar aus
 * `preset_source` und `preset_field` derselben Stelle (Knoten und Kante) wird eine gewählte Vorbelegung — gepaart in der Reihenfolge der
 * Zeilen, wie D-844 es festhält: «x aus vater mit y aus kind». Ein Weg über mehrere Stufen ist mit D-844 entfallen; von ihm zählt das
 * letzte Glied. Jedes `pick_field` wird ein «Mehrere hinzufügen» an seiner Kante. Die alten Zeilen wandern in den Schatten.*
 *
 * Wiederholbar: eine Stelle, die schon Zusatzfunktionen trägt, wird übersprungen.
 *
 * Aufruf: php scripts/dev/addons-migrate.php [--trocken]
 */

define('WP_USE_THEMES', false);
require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Addon\PickRowsAddon;
use Taxmod\Core\Addon\PresetAddon;
use Taxmod\Core\Addon\PresetMode;
use Taxmod\Core\Model\NodeClass\NodeAttributes;
use Taxmod\Core\Model\Setting\SettingsObject;
use Taxmod\Core\Model\Setting\SettingsValue;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Persistence\WpdbSettingsRepository;

$trocken = in_array('--trocken', $argv, true);

global $wpdb;
$p        = $wpdb->prefix . 'taxmod_';
$settings = new WpdbSettingsRepository();
$alt      = $wpdb->get_results("SELECT * FROM {$p}settings_value WHERE attribut IN ('preset_field','preset_source','preset_mode','pick_field') AND node_id IS NOT NULL ORDER BY position, id");

if ($wpdb->last_error !== '') {
    exit("Lesen fehlgeschlagen: {$wpdb->last_error}\n");
}

$stellen = [];

foreach ($alt as $zeile) {
    $stellen[$zeile->node_id . ':' . ($zeile->relation_id ?? '')][$zeile->attribut][] = $zeile;
}

$traeger = NodeAttributes::class;

foreach ($stellen as $stelle => $attribute) {
    [$knoten, $kante] = explode(':', $stelle);
    $knoten = (int) $knoten;
    $kante  = $kante === '' ? null : (int) $kante;
    $schon  = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}settings_value WHERE node_id = %d AND attribut = %s AND " . ($kante === null ? 'relation_id IS NULL' : 'relation_id = ' . $kante),
        $knoten,
        \Taxmod\Core\Addon\AddonRegistry::ATTRIBUTE
    ));

    if ($schon > 0) {
        echo "Stelle {$stelle}: trägt schon Zusatzfunktionen, übersprungen\n";

        continue;
    }

    $glieder = [];
    $modus   = PresetMode::Sort;

    foreach ($attribute['preset_mode'] ?? [] as $zeile) {
        $modus = $zeile->wert_text === 'filter' ? PresetMode::Filter : PresetMode::Sort;
    }

    $felder  = array_values(array_filter($attribute['preset_field'] ?? [], static fn ($z): bool => (int) $z->aktiv === 1));
    $quellen = array_values(array_filter($attribute['preset_source'] ?? [], static fn ($z): bool => (int) $z->aktiv === 1));

    foreach ($felder as $i => $feld) {
        $quelle = $quellen[$i] ?? end($quellen);

        if ($quelle === false || $quelle === null) {
            echo "Stelle {$stelle}: Feld {$feld->wert_kante_id} ohne Quelle — nicht übernommen\n";

            continue;
        }

        $glieder[] = [PresetAddon::class, [
            'source_field'  => TypedValue::ofRelationReference((int) $quelle->wert_kante_id),
            'offered_field' => TypedValue::ofRelationReference((int) $feld->wert_kante_id),
            'mode'          => TypedValue::ofText($modus->value),
        ]];
    }

    foreach ($attribute['pick_field'] ?? [] as $zeile) {
        if ((int) $zeile->aktiv === 1) {
            $glieder[] = [PickRowsAddon::class, ['pick_field' => TypedValue::ofRelationReference((int) $zeile->wert_kante_id)]];
        }
    }

    foreach ($glieder as $platz => [$klasse, $werte]) {
        $beschrieben = implode(', ', array_map(static fn (string $k, TypedValue $w): string => $k . '=' . $w->rawValue(), array_keys($werte), $werte));
        echo "Stelle {$stelle}: " . basename(str_replace('\\', '/', $klasse)) . " ({$beschrieben})\n";

        if ($trocken) {
            continue;
        }

        $objekt = $settings->addObject(SettingsObject::create($klasse));

        foreach ($werte as $name => $wert) {
            $settings->addValue(SettingsValue::inObject($objekt->id, $klasse, $name, $wert, null));
        }

        $settings->addValue(SettingsValue::objectAtNode($knoten, $traeger, \Taxmod\Core\Addon\AddonRegistry::ATTRIBUTE, $objekt->id, $kante, $platz));
    }

    foreach ($attribute as $zeilen) {
        foreach ($zeilen as $zeile) {
            echo "  alte Zeile {$zeile->id} ({$zeile->attribut}) → Schatten\n";

            if (! $trocken) {
                $settings->forgetValue((int) $zeile->id);
            }
        }
    }
}

echo $trocken ? "trocken — nichts geschrieben\n" : "fertig\n";
