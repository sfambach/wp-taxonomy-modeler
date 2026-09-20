<?php declare(strict_types=1);

/**
 * Ein Feld vom Typ «Zusammenfassung» steht im Satz und wird bei jeder Änderung neu geschrieben.
 *
 *     php scripts/dev/zusammenfassung-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-885](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«bei jeder Änderung muss es natürlich neu
 * geschrieben werden»*. *Geprüft an einem Satz, der ein solches Feld trägt: der Text steht, und nach einer Änderung an
 * einem **Teil** des Satzes steht der neue Text da — der Fall, den die Zusammenfassung sonst verpasst, weil der Teil ein
 * eigener Satz ist. Die Klammer dreht alles zurück.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;

global $wpdb;
wp_set_current_user(1);

$p      = $wpdb->prefix . 'taxmod_';
$failed = 0;

function check(string $was, bool $ok, string $dazu = ''): void
{
    global $failed;

    if (! $ok) {
        $failed++;
    }

    echo '  ' . ($ok ? 'ok  ' : 'FAIL') . ' ' . $was . ($dazu === '' ? '' : ' — ' . $dazu) . "\n";
}

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\DataEntry $data */
$data      = (new ReflectionProperty($screen, 'data'))->getValue($screen);
$typKnoten = (new ReflectionMethod($plugin, 'typeNodes'))->invoke($plugin)->nodeId(SimpleType::Summary);

check('der Typknoten «Zusammenfassung» ist gesät', $typKnoten !== null);

if ($typKnoten === null) {
    exit(1);
}

// *Ein Satz mit geschriebener Zusammenfassung, dessen Teil eine Zahl trägt, **die auch im Text steht** — sonst prüfte der
// Lauf eine Änderung, die den Text zu Recht nicht anfasst (gemessen am Spannungsregler: seine Zusammenfassung ist Typ und
// Beschreibung, nicht die Spannung).*
$kandidaten = $wpdb->get_results($wpdb->prepare(
    "SELECT v.node_record_id AS satz, v.relation_id AS kante, v.value_text AS text
       FROM {$p}relation_records v
       JOIN {$p}relations k ON k.id = v.relation_id AND k.to_node_id = %d
      WHERE v.value_text IS NOT NULL AND v.value_text <> ''
      ORDER BY v.node_record_id LIMIT 50",
    $typKnoten
));

check('es gibt Sätze mit geschriebener Zusammenfassung', $kandidaten !== []);

$fund = null;
$teil = null;

foreach ($kandidaten as $eines) {
    foreach ($wpdb->get_results($wpdb->prepare(
        "SELECT v.value_ref AS teil, w.relation_id AS feld, w.value_decimal AS zahl
           FROM {$p}relation_records v
           JOIN {$p}relation_records w ON w.node_record_id = v.value_ref AND w.value_decimal IS NOT NULL
          WHERE v.node_record_id = %d AND v.value_ref_kind = 'record'",
        (int) $eines->satz
    )) as $moeglich) {
        $zahl = rtrim(rtrim((string) $moeglich->zahl, '0'), '.');

        if ($zahl !== '' && str_contains((string) $eines->text, $zahl)) {
            [$fund, $teil] = [$eines, $moeglich];

            break 2;
        }
    }
}

check('einer davon fasst die Zahl eines Teils zusammen', $teil !== null, $teil === null ? 'keiner gefunden' : "Satz #{$fund->satz}, Teil #{$teil->teil}");

if ($teil === null || $fund === null) {
    exit($failed > 0 ? 1 : 0);
}

$vorher = (string) $fund->text;
$data->put((int) $teil->teil, (int) $teil->feld, TypedValue::ofDecimal((string) ((float) $teil->zahl + 7)));

$nachher = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
    (int) $fund->satz,
    (int) $fund->kante
));

check('nach der Änderung am Teil steht ein anderer Text', $nachher !== $vorher, "«{$vorher}» → «{$nachher}»");
check('der neue Text ist nicht leer', trim($nachher) !== '');

echo $failed === 0 ? "\nalles in Ordnung.\n" : "\n{$failed} Befund(e).\n";

exit($failed > 0 ? 1 : 0);
