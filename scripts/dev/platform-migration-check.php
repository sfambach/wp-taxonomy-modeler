<?php declare(strict_types=1);

/**
 * Die Datenumstellung von [D-1487](../../docs/NewConcept/90-decision-log.md) an einem Abzug: die Op-Liste für fambach.net läuft hier gegen
 * eine Nachbildung mit derselben Zahl Bestellungen und denselben Texten, und Undo legt alles zurück.
 *
 * ```text
 * CONTRACT
 * Eingabe   ein Ordner mit ops-NN.json (die Stapel für WP-Upload) und orders.json (Bestell-Id → Plattform-Text, live gelesen)
 * Umstellen jeder Stapel ist ein Akt; danach zeigt jede Bestellung mit Text auf die Firma, die PlatformFirms nennt, die leeren bleiben leer
 * Import    ein zweiter Lauf findet «Mouser», «PayPal», «AliExpress» mit ihrer Nummer wieder
 * Undo      die Akte rückwärts: Feld wieder Text und Komposition, jede Bestellung trägt wieder genau ihren Text
 * ```
 *
 * Usage: php scripts/dev/platform-migration-check.php /mnt/project-files/taxmod-lager/d1487
 *
 * @see docs/NewConcept/90-decision-log.md D-1487
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/lib/beispiel.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Import\TargetModel;
use Taxmod\Core\Model\Branch;
use Taxmod\WordPress\Import\ModelGateway;

wp_set_current_user(1);

$ordner = rtrim($argv[1] ?? '', '/');

if ($ordner === '' || ! is_file($ordner . '/orders.json')) {
    echo "  skip kein Ordner mit orders.json und ops-NN.json angegeben — dieser Wächter läuft nur für eine Umstellung\n";
    exit(0);
}

$failed = 0;
$say    = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
    $failed += $ok ? 0 : 1;
};

$texte  = (array) json_decode((string) file_get_contents($ordner . '/orders.json'), true);
$dateien = glob($ordner . '/ops-*.json') ?: [];
sort($dateien);
$stapel = array_map(static fn (string $datei): array => (array) json_decode((string) file_get_contents($datei), true)['changes'], $dateien);

// *Die Ids von fambach.net, die die Op-Liste nennt — hier stehen dafür die der Nachbildung.*
const LIVE = ['orders' => 149000110051, 'platform' => 149000110056, 'number' => 149000110055, 'supplier' => 149000109086, 'name' => 149000102666, 'text' => 1175];

$text  = taxmodSaatKnoten(Branch::DataTypes, 'Text');
$firmen = [];

foreach ($stapel as $ops) {
    foreach ($ops as $op) {
        if ($op['op'] === 'set_values' && ! str_starts_with((string) $op['record'], '@')) {
            $wert = (string) reset($op['values']);

            if (ctype_digit($wert)) {
                $firmen[$wert] = true;
            }
        }
    }
}

$aufbau = [
    ['op' => 'create_node', 'ref' => 'lief', 'name' => '__pm Lieferant', 'parent' => 402],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@lief', 'target' => $text, 'name' => '__pm Name', 'kind' => 'composition', 'multiplicity' => '0..1'],
    ['op' => 'create_node', 'ref' => 'best', 'name' => '__pm Bestellungen', 'parent' => 402],
    ['op' => 'add_field', 'ref' => 'nr', 'node' => '@best', 'target' => $text, 'name' => '__pm Bestellnummer', 'kind' => 'composition', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'plat', 'node' => '@best', 'target' => $text, 'name' => '__pm Plattform', 'kind' => 'composition', 'multiplicity' => '0..1'],
];

// *Die Firmen tragen ihr Wort von fambach.net, damit die Auflösung dieselbe ist — gelesen aus der Op-Liste ist nur die Id.*
$worte = (array) json_decode((string) @file_get_contents($ordner . '/firms.json'), true);

foreach (array_keys($firmen) as $id) {
    $aufbau[] = ['op' => 'create_record', 'ref' => 'f' . $id, 'node' => '@lief'];
    $aufbau[] = ['op' => 'set_values', 'record' => '@f' . $id, 'values' => ['@name' => (string) ($worte[$id] ?? 'Firma ' . $id)]];
}

$refs = taxmodBeispiel($aufbau);
$bestellungen = [];

foreach (array_chunk(array_keys($texte), 250, true) as $teil) {
    $ops = [];

    foreach ($teil as $id) {
        $ops[] = ['op' => 'create_record', 'ref' => 'o' . $id, 'node' => $refs['best']];
        $ops[] = ['op' => 'set_values', 'record' => '@o' . $id, 'values' => ['@n' => 'N-' . $id] + ((string) $texte[$id] === '' ? [] : ['@p' => (string) $texte[$id]])];
    }

    $ops = array_map(static fn (array $op): array => isset($op['values']) ? ['values' => array_combine(
        array_map(static fn (string $k): string => $k === '@n' ? (string) $refs['nr'] : (string) $refs['plat'], array_keys($op['values'])),
        array_values($op['values'])
    )] + $op : $op, $ops);
    $bestellungen += array_map('intval', taxmodBeispiel($ops));
}

$say(count($texte) > 0 && count(array_filter(array_keys($bestellungen), static fn (string $r): bool => str_starts_with($r, 'o'))) === count($texte), sprintf('Nachbildung: %d Bestellungen mit ihrem Text', count($texte)));

$ids = [
    LIVE['orders'] => $refs['best'], LIVE['platform'] => $refs['plat'], LIVE['number'] => $refs['nr'], LIVE['supplier'] => $refs['lief'],
    LIVE['name'] => $refs['name'], LIVE['text'] => $text,
];

foreach (array_keys($firmen) as $id) {
    $ids[(int) $id] = $refs['f' . $id];
}

foreach (array_keys($texte) as $id) {
    $ids[(int) $id] = $bestellungen['o' . $id];
}

$umschreiben = static function (mixed $wert) use (&$umschreiben, $ids): mixed {
    if (is_array($wert)) {
        $neu = [];

        foreach ($wert as $k => $v) {
            // ⚠️ *PHP turns a key of digits into an integer — a relation id as key is one.*
            $neu[(is_int($k) || ctype_digit($k)) && $k > 1000 && isset($ids[(int) $k]) ? (string) $ids[(int) $k] : $k] = $umschreiben($v);
        }

        return $neu;
    }

    if (is_int($wert) || (is_string($wert) && ctype_digit($wert))) {
        return isset($ids[(int) $wert]) ? (is_int($wert) ? $ids[$wert] : (string) $ids[(int) $wert]) : $wert;
    }

    return $wert;
};

$akte  = [];
$dauer = microtime(true);

foreach ($stapel as $nr => $ops) {
    // *Die Einstellung «Link» hängt live an der Bestellnummer; die Nachbildung hat keine — sie wird hier nicht geprüft.*
    $ops     = array_values(array_filter($ops, static fn (array $op): bool => $op['op'] !== 'set_list'));
    $antwort = wp_get_ability('taxmod/apply')->execute(['changes' => $umschreiben($ops)]);

    if (is_wp_error($antwort)) {
        $say(false, sprintf('Stapel %d: %s', $nr + 1, $antwort->get_error_message()));
        break;
    }

    $akte[] = (int) $antwort['act'];
}

$say(count($akte) === count($stapel), sprintf('Umstellung: %d Stapel als %d Akte (%.1f s)', count($stapel), count($akte), microtime(true) - $dauer));

global $wpdb;
$p     = $wpdb->prefix . 'taxmod_';
$zeilen = static fn (): array => $wpdb->get_results($wpdb->prepare(
    "SELECT node_record_id AS id, value_text AS t, value_ref AS r, value_ref_kind AS k FROM {$p}relation_records WHERE relation_id = %d",
    $refs['plat']
), OBJECT_K);

$nachher  = $zeilen();
$firmaVon = array_flip(array_map(static fn (int $id): int => $ids[$id], array_map('intval', array_keys($firmen))));
$falsch   = 0;
$verweise = 0;

foreach ($texte as $id => $alt) {
    $zeile = $nachher[$ids[(int) $id]] ?? null;

    if ((string) $alt === '') {
        $falsch += $zeile === null ? 0 : 1;
        continue;
    }

    $verweise += $zeile !== null && $zeile->k === 'record' ? 1 : 0;
    $falsch   += $zeile !== null && $zeile->k === 'record' ? 0 : 1;
}

$say($falsch === 0, sprintf('Umstellung: %d Bestellungen zeigen auf eine Firma, die leeren bleiben leer (%d falsch)', $verweise, $falsch));

$ziel = new TargetModel($refs['best'], $refs['best'], 0, 0, 0, ['title' => $refs['nr'], 'order_number' => $refs['nr'], 'platform' => $refs['plat'], 'merchant' => $refs['nr'], 'ordered_at' => $refs['nr']], [], ['EUR' => 8571]);

foreach (['Mouser', 'PayPal', 'AliExpress'] as $name) {
    $eine = array_search($name, $texte, true);
    $say($eine !== false && (new ModelGateway($ziel))->findOrder($name, 'N-' . $eine)?->recordId === $ids[(int) $eine], sprintf('Import danach: «%s» N-%s wird wiedergefunden', $name, (string) $eine));
}

foreach (array_reverse($akte) as $akt) {
    $zurueck = wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'undo_act', 'act' => $akt]]]);
    $say(! is_wp_error($zurueck), 'Undo Akt ' . $akt . (is_wp_error($zurueck) ? ': ' . $zurueck->get_error_message() : ''));
}

$feld   = $wpdb->get_row($wpdb->prepare("SELECT to_node_id, kind FROM {$p}relations WHERE id = %d", $refs['plat']));
$say((int) $feld->to_node_id === $text && $feld->kind === 'composition', 'Undo: «Plattform» ist wieder Text und Komposition');

$wieder = $zeilen();
$anders = 0;

foreach ($texte as $id => $alt) {
    $anders += (string) ($wieder[$ids[(int) $id]]->t ?? '') === (string) $alt ? 0 : 1;
}

$say($anders === 0, sprintf('Undo: jede der %d Bestellungen trägt wieder genau ihren Text (%d anders)', count($texte), $anders));

echo $failed === 0 ? "platform-migration-check: ok\n" : "platform-migration-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
