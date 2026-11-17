<?php declare(strict_types=1);
/**
 * Ein Fassungssprung auf einer gefüllten Datenbank lässt die Daten stehen: jeder Schritt von `Schema::install()` ist auf einem
 * Stand, der schon gebaut ist, leer.
 *
 *     php scripts/dev/fassungssprung-check.php [path/to/wordpress]
 *
 * ⚠️ **Nur auf einer Prüf-Datenbank.** *Der Lauf führt die Schritte wirklich aus — so, wie es jede neue Fassung auf der Seite des
 * Eigentümers tut; eine Tabellenänderung bestätigt MySQL sofort, eine Klammer mit Rücknahme gibt es dafür nicht.*
 *
 * ⚠️ *Anlass, gemessen im Grenzlauf vom 2026-11-17: main mit nur `VERSION = 60` auf einer Sicherung der Fassung 59 machte
 * `undo-check` («no row is left with a placeholder») und `field-hide-at-node-check` («hidden an der Wurzel») rot. Jeder Sprung lässt
 * `install()` mit allen Schritten laufen, und einer davon ist auf gefüllten Daten nicht leer. Diese Prüfung nennt ihn: sie misst
 * jede Tabelle vor und nach jedem Schritt ([D-1426](../../docs/NewConcept/90-decision-log.md)).*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */

use Taxmod\WordPress\Persistence\Schema;

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
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
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

// On a database one version behind this is the jump itself, step by step; on a current one, a second run. Either way a step that
// only adds an index or a column leaves every row as it was — a changed row count or checksum names a step that is not empty.
echo 'Datenbank auf Fassung ' . (int) get_option(Schema::VERSION_OPTION, 0) . ', Kode auf ' . Schema::VERSION . "\n\n";

/** @return array<string, string> table → row count and checksum */
$snapshot = static function () use ($wpdb): array {
    $out = [];

    foreach (Schema::tableNames() as $name) {
        $table = Schema::table($name);

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            $out[$name] = 'fehlt';
            continue;
        }

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $sum   = (string) ($wpdb->get_row("CHECKSUM TABLE {$table}", ARRAY_A)['Checksum'] ?? '');
        $out[$name] = $count . ' Zeilen / ' . $sum;
    }

    return $out;
};

// The steps in the order install() calls them, read from its own source so a new step is checked without touching this file.
$method = new ReflectionMethod(Schema::class, 'install');
$source = implode('', array_slice(file((string) $method->getFileName()) ?: [], $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
preg_match_all('/self::([a-zA-Z]+)\(\)/', $source, $found);
$steps = array_values(array_filter($found[1], static fn (string $step): bool => $step !== 'table'));
// And what Plugin::bringUpToDate() does on every jump after install(): the framework nodes are seeded again.
$steps[] = 'seed';

$changed = [];
$before  = $snapshot();

foreach ($steps as $step) {
    if ($step === 'seed') {
        (new \Taxmod\WordPress\Persistence\SeededFrameworkNodes(
            new \Taxmod\WordPress\Persistence\WpdbNodeRepository(),
            new \Taxmod\WordPress\Persistence\WpdbRelationRepository(),
            new \Taxmod\WordPress\Persistence\WpdbChangelog(new \Taxmod\WordPress\SystemClock())
        ))->seed();
    } elseif ($step === 'statements') {
        foreach ((new ReflectionMethod(Schema::class, 'statements'))->invoke(null) as $sql) {
            dbDelta($sql);
        }
    } else {
        $call = new ReflectionMethod(Schema::class, $step);
        $call->invoke(null);
    }

    $after = $snapshot();
    $diff  = [];

    foreach ($after as $name => $state) {
        if (($before[$name] ?? '') !== $state) {
            $diff[] = "{$name}: " . ($before[$name] ?? '—') . ' → ' . $state;
        }
    }

    echo ($diff === [] ? '  OK   ' : '  FAIL ') . $step . ($diff === [] ? '' : "\n         " . implode("\n         ", $diff)) . "\n";

    if ($diff !== []) {
        $changed[] = $step;
    }

    $before = $after;
}

echo "\n" . ($changed === [] ? 'Alles gruen: ' . count($steps) . " Schritte leer\n" : count($changed) . ' Schritte nicht leer: ' . implode(', ', $changed) . "\n");

exit($changed === [] ? 0 : 1);
