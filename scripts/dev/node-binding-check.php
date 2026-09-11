<?php declare(strict_types=1);
/**
 * Welche WordPress-Option sich noch eine Knoten-Id merkt — und dass es keine mehr wird (TASK-009).
 *
 *     php scripts/dev/node-binding-check.php [path/to/wordpress]
 *
 * ⚠️ **`AR-1` will die Bindung «welcher Knoten ist X» im Modell, nicht in einer WordPress-Option.**
 * *TASK-008 hat dafür `nodes.implemented_by` gelegt, TASK-009 hat damit die Renderer-, Konverter-
 * und Validatoroptionen abgelöst. **Dieser Wächter hält beides fest**: dass das Abgelöste abgelöst
 * bleibt, und dass die Liste dessen, was noch dasteht, nicht heimlich wächst.*
 *
 * ⚠️ **Eine Zahl in einem Wächter ist kein Ziel, sondern eine Messung mit Datum.** *Die erlaubte
 * Menge steht unten namentlich, nicht als Anzahl — eine neue Option fällt auf, weil sie in keiner
 * der sechs Familien vorkommt, und nicht, weil eine Summe nicht mehr stimmt.*
 *
 * Geprüft wird viererlei:
 *
 * 1. **Keine unbekannte Option merkt sich eine Knoten-Id.** *Jede `taxmod_…`-Option, deren Wert die
 *    Id eines lebenden Knotens ist, gehört zu einer der aufgezählten Familien.*
 * 2. **Das Abgelöste bleibt abgelöst.** *Für keinen registrierten Renderer, Konverter oder
 *    Validator steht noch eine `taxmod_render_<behaelter>_<name>_id` da. **Das ist die Zusage von
 *    TASK-009**, und sie ist die einzige hier, die rot wird, wenn jemand die Saat zurückdreht.*
 * 3. **Wer eine Klasse nennt, wird über die Klasse gefunden.** *Jede registrierte Klasse steht an
 *    genau einem Knoten — sonst wäre die Ablösung eine ins Leere. (Dieselbe Zusage misst
 *    {@see implemented-by-check.php} §4 von der anderen Seite; hier steht sie, weil ohne sie die
 *    Zusage aus §2 nur bedeutet «die Option ist weg».)*
 * 4. **Die neun Oberflächenoptionen zeigen weiterhin auf nichts** — gemeldet als `INF-020`, nicht
 *    aufgeräumt. *Ihre Zahl darf nicht steigen: eine zehnte hiesse, dass eine Saat wieder eine Id
 *    in eine Option schreibt, statt sie an den Knoten zu binden.*
 *
 * ⚠️ *Dieser Lauf schreibt nichts und legt nichts an — er liest nur, also gibt es nichts wegzuräumen
 * (TASK-025, TASK-039, TASK-047, `INF-021`).*
 *
 * @see docs/pakete/modelltabellen/package.md
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Validator\ShippedValidators;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededTypeNodes;

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

$nodes = Schema::table('nodes');

/**
 * Die Familien, die sich heute noch eine Knoten-Id merken — namentlich, nicht als Anzahl.
 *
 * ⚠️ *Gemessen am 2026-09-05. **Jede einzelne davon steht offen**, weil sie einen Aufzählungsfall
 * oder einen einzelnen Ort nennt und keine PHP-Klasse; `INF-020` und `INF-025` sagen warum.*
 *
 * @var array<string,string> Optionsname → wofür sie steht
 */
$erlaubt = [];

// ⚠️ **Die elf Typoptionen stehen hier nicht mehr, und das ist der Punkt**
// ([D-484](../../docs/NewConcept/90-decision-log.md), TASK-009): *seit jeder einfache Typ eine Klasse
// ist, trägt sein Knoten den Klassennamen, und die Option ist gefallen. Käme eine zurück, meldete
// Abschnitt 1 sie als **unbekannt** — genau das ist die Zusage.*

foreach (Branch::cases() as $ast) {
    $erlaubt['taxmod_branch_' . str_replace('-', '_', $ast->value) . '_id'] = 'Ast';
}

foreach (SeededRole::cases() as $rolle) {
    $erlaubt['taxmod_role_' . $rolle->value] = 'Beschriftungsrolle';
}

foreach (['root', 'trash', 'primitives', 'roles'] as $einzeln) {
    $erlaubt['taxmod_' . $einzeln . '_id'] = 'gesäter Einzelknoten';
}

// ⚠️ **Der Einheitenwert, seit dem 2026-09-07 über seine Id gebunden**
// ([D-510](../../docs/NewConcept/90-decision-log.md), `INF-070`) — *sein Wort: «köntest aber über id
// gehen 😉». **Er ist kein Rahmenknoten**, sondern gesäter Inhalt, den er verschieben und umbenennen
// darf; genau deshalb steht hier eine Id und kein Name.*
$erlaubt[\Taxmod\WordPress\Persistence\UnitScaffold::UNIT_VALUE_OPTION] = 'gesäter Inhalt, den zwei Wächter brauchen';

// ⚠️ *Das Gerüst der Wächter (`scripts/dev/geruest.php`) merkt sich seine Wegwerfäste ebenso. Kein
// Modellwissen — aber es sind Knoten-Ids in Optionen, und ungenannt wären sie hier ein Fehlalarm.*
foreach (Branch::cases() as $ast) {
    $erlaubt['taxmod_testast_' . str_replace('-', '_', $ast->value) . '_id'] = 'Wegwerfast eines Wächters';
}

echo "1 · Keine unbekannte Option merkt sich eine Knoten-Id\n";

/** @var list<array{option_name: string, option_value: string}> $optionen */
$optionen = $wpdb->get_results(
    "SELECT option_name, option_value FROM {$wpdb->options}
     WHERE option_name LIKE 'taxmod\\_%' ORDER BY option_name",
    ARRAY_A
);

$fremd     = [];
$dastehend = [];

foreach ($optionen as $option) {
    $name = (string) $option['option_name'];
    $wert = (string) $option['option_value'];

    if (! ctype_digit($wert) || (int) $wert <= 0) {
        continue;
    }

    // ⚠️ **Gefragt wird der Name, nicht der Wert.** *Ein Wertvergleich gegen `nodes.id` sieht
    // hübscher aus und ist falsch: `taxmod_composition_scaffold` trägt die Fassungsnummer `1`, und
    // `1` ist auch die Wurzel. **Eine Bindung erkennt man an ihrem Namen** — die Saaten benennen
    // sie seit [D-510](../../docs/NewConcept/90-decision-log.md) alle gleich.*
    if (! str_ends_with($name, '_id') && ! str_starts_with($name, 'taxmod_role_')) {
        continue;
    }

    // ⚠️ *Die eine Ausnahme, und sie steht so im Kern: die Installationsidentität ist **kein
    // Knoten** ({@see \Taxmod\WordPress\Persistence\SeededFrameworkNodes::installationId()}).
    // Wohin sie gehört, fragt `INF-008`.*
    if ($name === 'taxmod_installation_id') {
        continue;
    }

    if (isset($erlaubt[$name])) {
        $dastehend[$name] = (int) $wert;

        continue;
    }

    // ⚠️ *Die neun Rückstände gehören §4, nicht hier. Dort steht die Zusage, die etwas wert ist —
    // dass keiner von ihnen auf einen lebenden Knoten zeigt. Hier wären sie nur ein Fehlalarm mit
    // Datum.*
    if (preg_match('/^taxmod_render_renderer_.+_id$/', $name) === 1) {
        continue;
    }

    $fremd[] = $name . ' → ' . $wert;
}

check(
    'jede Option mit einer Knoten-Id ist eine der aufgezählten (' . count($dastehend) . ' stehen da)',
    $fremd === [],
    implode(' · ', array_slice($fremd, 0, 8))
);

// ⚠️ **Hier standen die Abschnitte 2 und 3 über die Renderer-, Konverter- und Validator-Knoten** *— seit Schritt 7 des Bauplans (2026-09-11): Renderer, Konverter und Validatoren sind
// Objekte programmierter Klassen, keine Knoten; die Einstellungskanten und der Ast `Settings` sind in den Schatten
// gewandert ([D-712](../../docs/NewConcept/90-decision-log.md), [D-718](../../docs/NewConcept/90-decision-log.md)).*
echo "\n2 · Die elf einfachen Typen (D-484, TASK-009)\n";

$typZurueck = [];
$typOhne    = [];

foreach (SimpleType::cases() as $typ) {
    if (get_option('taxmod_type_' . $typ->value . '_id', null) !== null) {
        $typZurueck[] = 'taxmod_type_' . $typ->value . '_id';
    }

    $treffer = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$nodes} WHERE implemented_by = %s", SeededTypeNodes::classFor($typ))
    );

    if ($treffer !== 1) {
        $typOhne[] = $typ->value . ' ×' . $treffer;
    }
}

check('keine der elf Typoptionen ist zurück', $typZurueck === [], implode(' · ', $typZurueck));

check('jede der elf Typklassen steht an genau einem Knoten', $typOhne === [], implode(' · ', $typOhne));

echo "\n4 · Die Oberflächenoptionen zeigen weiter auf nichts (INF-020)\n";

/** @var list<array{option_name: string, option_value: string}> $oberflaeche */
$oberflaeche = $wpdb->get_results(
    "SELECT option_name, option_value FROM {$wpdb->options}
     WHERE option_name LIKE 'taxmod\\_render\\_renderer\\_%\\_id' ORDER BY option_name",
    ARRAY_A
);

$mitKnoten = [];

foreach ($oberflaeche as $option) {
    $wert = (int) $option['option_value'];

    if ($wert <= 0) {
        continue;
    }

    $treffer = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$nodes} WHERE id = %d", $wert)
    );

    if ($treffer > 0) {
        $mitKnoten[] = (string) $option['option_name'];
    }
}

check(
    count($oberflaeche) . ' Oberflächenoptionen, keine davon zeigt auf einen lebenden Knoten',
    $mitKnoten === [],
    implode(' · ', array_slice($mitKnoten, 0, 8))
);

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
