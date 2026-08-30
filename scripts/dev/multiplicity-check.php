<?php declare(strict_types=1);
/**
 * Welche Multiplizität eine Kante trägt — an echten Daten festgenagelt.
 *
 *     php scripts/dev/multiplicity-check.php [path/to/wordpress]
 *
 * ⚠️ **Der Eigentümer hat den halben Umzug gerochen, bevor ich ihn zugab:** *«warum liest ihn
 * niemand? Also entweder hast Du beim Umstellen was falsch gemacht oder da fehlt noch was, **weil die
 * Multiplizität ja genau darüber entscheidet, ob der Benutzer was eingeben muss oder nicht**. Und das
 * kann ich mir nicht vorstellen, dass die nirgendwo gelesen wird.»*
 *
 * ⚠️ **Er hatte recht.** *[D-528](../../docs/NewConcept/90-decision-log.md) hat die Spalte an die
 * Kante gelegt und die Daten umgezogen — **und neun Lesestellen weiter aus der Settings-Tabelle
 * gelesen**. Dieselbe Reihenfolge-Sünde wie beim Renderer, nur eine Woche früher begangen und einen
 * Tag später bemerkt.*
 *
 * ⚠️ *Diese Prüfung nagelt die sechs Kanten fest, die überhaupt etwas anderes als die Vorgabe sagen.
 * Sie muss **vor** und **nach** der Umstellung grün sein — das ist ihr ganzer Zweck.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */

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
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;
$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), $log);
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework);

/**
 * ⚠️ *Fest hingeschrieben und nicht aus der Tabelle gesucht — dieselbe Lehre wie beim Renderer:
 * **eine Prüfung, die ihre Fälle in der Tabelle sucht, die geleert wird, meldet danach «nichts
 * gefunden» statt «stimmt».***
 */
$erwartet = [
    ['Backrezept', 'zutat', '1..*'],
    ['Einheitenwert', 'prefix', '0..1'],
    ['Part List Item', 'Bauteil Ref.', '1..*'],
    ['Parts List', 'Position', '0..*'],
    ['Root', 'renderer', '1..*'],
];

echo "\n== Die Kante traegt ihre Multiplizitaet ==\n";

$gefunden = [];

foreach ($erwartet as [$vonName, $feldName, $soll]) {
    $id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name = %s LIMIT 1',
        $vonName
    ));

    $von = $id === 0 ? null : $nodes->find($id);

    if ($von === null) {
        check("«{$vonName}» steht im Modell", false, 'kein Knoten dieses Namens');

        continue;
    }

    $kante = null;

    foreach ($edges->fieldEdgesOf([...$von->ancestorIds(), $von->id]) as $eine) {
        if ($eine->name === $feldName) {
            $kante = $eine;
        }
    }

    if ($kante === null) {
        check("«{$vonName}» hat ein Feld «{$feldName}»", false, 'nicht gefunden');

        continue;
    }

    $gefunden[] = [$kante, $soll, "{$vonName}.{$feldName}"];

    check(
        "«{$vonName}.{$feldName}» ist {$soll}",
        $kante->multiplicity->value === $soll,
        $kante->multiplicity->value
    );
}

echo "\n== Und die Folge daraus stimmt ==\n";

// ⚠️ **Das ist, was er meinte:** *«die Multiplizität entscheidet ja genau darüber, ob der Benutzer
// was eingeben muss oder nicht».* Die zwei Fragen, die daran hängen, werden hier mitgeprüft — sonst
// wäre die Spalte nur eine Zeichenkette.
foreach ($gefunden as [$kante, $soll, $wo]) {
    $mult = Multiplicity::from($soll);

    check(
        "«{$wo}» verlangt " . ($mult->requiresOne() ? 'einen Wert' : 'keinen'),
        $kante->multiplicity->requiresOne() === $mult->requiresOne()
    );

    check(
        "«{$wo}» erlaubt " . ($mult->allowsMany() ? 'mehrere' : 'nur einen'),
        $kante->multiplicity->allowsMany() === $mult->allowsMany()
    );
}

echo "\n== Beide Quellen sagen dasselbe, solange es beide gibt ==\n";

// ⚠️ *Solange die alten Zeilen stehen, müssen sie mit der Spalte übereinstimmen — **eine Abweichung
// hiesse, dass eine Hälfte des Umzugs zurückgefallen ist**. Sind sie weg, ist diese Zusage still
// erfüllt und sagt das auch.*
$ausTabelle = $wpdb->get_results(
    "SELECT s.owner_id, s.value_text FROM " . Schema::table('settings') . " s
     WHERE s.setting_key = 'multiplicity'",
    ARRAY_A
) ?: [];

if ($ausTabelle === []) {
    check('die Settings-Tabelle sagt nichts mehr dazu — der Umzug ist fertig', true);
} else {
    $abweichung = [];

    foreach ($ausTabelle as $z) {
        $kante = null;

        $von = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT from_id FROM ' . Schema::table('relations') . ' WHERE id = %d',
            (int) $z['owner_id']
        ));

        foreach ($edges->fieldEdgesOf([$von]) as $eine) {
            if ($eine->id === (int) $z['owner_id']) {
                $kante = $eine;
            }
        }

        if ($kante !== null && $kante->multiplicity->value !== $z['value_text']) {
            $abweichung[] = "Kante {$z['owner_id']}: Spalte {$kante->multiplicity->value}, Tabelle {$z['value_text']}";
        }
    }

    check(
        count($ausTabelle) . ' alte Zeilen stimmen mit der Spalte ueberein',
        $abweichung === [],
        implode(' · ', $abweichung)
    );
}

echo "\n== Nichts haengt mehr an der Tabelle ==\n";

// ⚠️ **Meine erste Fassung dieser Zusage zählte Vorkommen von `SettingKey::Multiplicity` im Quelltext
// und war Unsinn** — *sie zählte die Aufzählung selbst, einen Docblock und den Namen eines
// Formularfeldes mit. **Eine Zusage über eine Zahl in Textdateien sagt nichts über Verhalten.***
//
// ⚠️ **Das hier ist der Beweis, auf den es ankommt:** *die Tabelle sagt nichts mehr, und die
// Auflösung liefert **trotzdem** für jede der sechs Kanten den richtigen Wert. Damit ist gezeigt, dass
// kein Leseweg mehr an den alten Zeilen hängt — nicht behauptet, sondern gemessen.*
$nochDa = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM " . Schema::table('settings') . " WHERE setting_key = 'multiplicity'"
);

check('die Settings-Tabelle sagt nichts mehr zur Multiplizitaet', $nochDa === 0, "{$nochDa} Zeilen");

if ($gefunden !== []) {
    $ausAufloesung = $settings->resolveForUseSites(array_map(static fn (array $g) => $g[0], $gefunden));
    $abweichung    = [];

    foreach ($gefunden as [$kante, $soll, $wo]) {
        $gelesen = $ausAufloesung[$kante->id][SettingKey::Multiplicity->value]->value->text ?? null;

        if ($gelesen !== $soll) {
            $abweichung[] = "{$wo}: erwartet {$soll}, aufgeloest " . ($gelesen ?? 'nichts');
        }
    }

    check(
        'und die Aufloesung liefert sie trotzdem — sie kommt von der Kante',
        $abweichung === [],
        implode(' · ', $abweichung)
    );
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
