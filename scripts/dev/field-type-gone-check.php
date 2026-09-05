<?php declare(strict_types=1);
/**
 * Dass `nodes.field_type` weg bleibt — und dass nichts verloren ging, als sie fiel.
 *
 *     php scripts/dev/field-type-gone-check.php [path/to/wordpress]
 *
 * ⚠️ **Sie ist der Nachfolger von `field-type-check.php`, das die Spalte bewacht hat** (`PR-9`:
 * *«ein Wächter bewacht den **aktuellen** Zielzustand, nie einen vergangenen»*). *Die Spalte ist mit
 * [D-621](../../docs/NewConcept/90-decision-log.md) gefallen — «die Kante sagt, was etwas hier ist —
 * nicht der Knoten und nicht der Ast» —, also bewacht diese Prüfung das Gegenteil dessen, was die
 * alte bewacht hat, und die alte ist mit dieser Änderung gelöscht.*
 *
 * ⚠️ **Drei Zusagen, und die dritte ist die, die dem Eigentümer auffallen würde:**
 *
 * 1. *Die Spalte kommt nicht zurück — lebend und im Schatten.*
 * 2. *Jede Einstellungskante wird weiter als solche erkannt — **dieselbe Menge wie vorher, gezählt**.*
 * 3. *Die Renderer-Zeile im Einstellungsblock **bietet Möglichkeiten an**.*
 *
 * ⚠️ **Die dritte ist keine Zugabe, sondern der Grund, warum der Rückbau sichtbar ist**
 * (`INF-042`, Antwort 1): *ein Wähler entsteht in der Wertspalte aus den **unmarkierten** Kindern des
 * Kantenziels ([D-540](../../docs/NewConcept/90-decision-log.md)). **Alle neunzehn Knoten unter
 * `Renderer` trugen die Marke**, die Auswahl sah durch jeden hindurch und bot **null** Möglichkeiten
 * an. Ohne Spalte tragen sie keine mehr — **käme die Marke auf irgendeinem Weg zurück, stünde hier
 * wieder null**, und genau das soll auffallen.*
 *
 * @see docs/NewConcept/90-decision-log.md
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

use Taxmod\Core\Model\FieldType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

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

echo "\n== 1. Die Spalte ist weg und bleibt weg ==\n";

foreach (['nodes', 'nodes_history'] as $tabelle) {
    $name = Schema::table($tabelle);

    check(
        "{$tabelle} hat keine Spalte field_type",
        $wpdb->get_var("SHOW COLUMNS FROM {$name} LIKE 'field_type'") === null
    );
}

// ⚠️ *Auch im Quelltext, und nicht nur in der Tabelle: **eine Spalte, die niemand mehr anlegt, kann
// über `dbDelta` zurückkommen**, wenn ihre Zeile im `CREATE TABLE` stehen bleibt.*
$schema = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Persistence/Schema.php');

check(
    'und kein CREATE TABLE legt sie wieder an',
    ! preg_match('/^\s*field_type varchar/m', $schema)
);

check('die Schemafassung ist mindestens 33', Schema::VERSION >= 33, (string) Schema::VERSION);

echo "\n== 2. Was gefallen ist, liegt im Schatten und im Journal ==\n";

$journal = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('changelog') . " WHERE what = 'field type dropped'"
);

check('jede gefallene Marke hat eine Journalzeile', $journal > 0, (string) $journal);

check(
    'und alle unter genau einer Aenderungsgruppe',
    (int) $wpdb->get_var(
        'SELECT COUNT(DISTINCT change_group_id) FROM ' . Schema::table('changelog')
            . " WHERE what = 'field type dropped'"
    ) === 1
);

// ⚠️ *[D-634](../../docs/NewConcept/90-decision-log.md): die Version ist ein Pflichtwert. Eine
// Journalzeile ohne sie kann nicht sagen, auf welchen Stand sie sich bezieht.*
check(
    'und jede nennt ihre Version',
    (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . Schema::table('changelog')
            . " WHERE what = 'field type dropped' AND version IS NULL"
    ) === 0
);

echo "\n== 3. Jede Einstellungskante wird weiter als solche erkannt ==\n";

$relations = Schema::table('relations');

// ⚠️ **Die Zahl steht hier nicht fest, und das ist Absicht** *(`PR-9`: der Wächter bewacht den
// Zielzustand, und der Eigentümer darf Einstellungskanten anlegen). **Was fest steht, ist die
// Gleichheit**: was die Spalte `kind` sagt, muss die Klasse hinter der Kante auch sagen.*
$ausDerSpalte = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$relations} WHERE kind = %s",
    RelationKind::Setting->value
));

$ausDerKlasse = 0;

foreach ((new \Taxmod\WordPress\Persistence\WpdbRelationRepository())
    ->fieldRelationsTo(array_map('intval', $wpdb->get_col('SELECT id FROM ' . Schema::table('nodes')))) as $relation) {
    $ausDerKlasse += $relation->isSetting() ? 1 : 0;
}

check(
    'so viele Einstellungskanten wie die Spalte sagt',
    $ausDerSpalte === $ausDerKlasse,
    "Spalte {$ausDerSpalte}, Klasse {$ausDerKlasse}"
);

check('und es sind ueberhaupt welche da', $ausDerSpalte > 0, (string) $ausDerSpalte);

// ⚠️ *Nur die drei Werte aus [D-639](../../docs/NewConcept/90-decision-log.md) — «ein Mittel, das
// bestimmt, was fuer eine Verbindung es ist, und nicht noch einen Schalter».*
$fremd = array_values(array_filter(
    $wpdb->get_col("SELECT DISTINCT kind FROM {$relations}"),
    static fn ($v): bool => RelationKind::tryFrom((string) $v) === null
));

check('und keine Kantenart, die der Code nicht kennt', $fremd === [], implode(', ', $fremd));

echo "\n== 4. Der Waehler unter `Renderer` bietet wieder Moeglichkeiten an ==\n";

$nodes      = new WpdbNodeRepository();
$rendererId = (int) $wpdb->get_var(
    $wpdb->prepare('SELECT id FROM ' . Schema::table('nodes_named') . ' WHERE name = %s LIMIT 1', 'Renderer')
);

if ($rendererId === 0) {
    check('ein Knoten `Renderer` steht im Modell', false);
} else {
    // Derselbe Lauf wie {@see \Taxmod\Core\Service\Rendering::offeredUnder()}: durch markierte
    // Knoten hindurch, unmarkierte sind die Moeglichkeiten.
    $moeglich = 0;
    $offen    = [$rendererId];

    for ($stufe = 0; $stufe < 3 && $offen !== []; $stufe++) {
        $alle = [];

        foreach ($nodes->visibleChildrenOf($offen) as $reihe) {
            foreach ($reihe as $kind) {
                $alle[$kind->id] = true;
            }
        }

        $eigene = $nodes->ownFieldTypes(array_keys($alle));
        $weiter = [];

        foreach ($eigene as $id => $sorte) {
            if ($sorte === FieldType::Setting) {
                $weiter[] = $id;

                continue;
            }

            ++$moeglich;
        }

        $offen = $weiter;
    }

    check(
        'die Renderer-Zeile bietet Moeglichkeiten an, nicht null',
        $moeglich > 0,
        $moeglich . ' Moeglichkeiten'
    );

    // ⚠️ *Kein Knoten unter `Renderer` traegt noch eine eigene Sorte — auf keinen zeigt eine Kante.
    // **Das ist die Berichtigung aus [D-621](../../docs/NewConcept/90-decision-log.md)**: «sie sind
    // Werte, die man in einer Einstellung waehlt, keine Einstellungen».*
    // ⚠️ *«Alles unter `Renderer`» fragt seit Fassung 35 der Speicher — der Weg ist keine Spalte
    // mehr, und ein `LIKE` darauf laege still leer und machte diesen Satz gruen und blind
    // (TASK-001).*
    $unter = array_values(array_diff($nodes->subtreeIds($rendererId), [$rendererId]));

    $markiert = count(array_filter($nodes->ownFieldTypes($unter)));

    check(
        'und keiner der Renderer traegt noch eine eigene Sorte',
        $markiert === 0,
        $markiert . ' von ' . count($unter)
    );
}

printf("\n%d ok, %d fehlgeschlagen\n", $ok, $bad);

exit($bad === 0 ? 0 : 1);
