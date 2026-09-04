<?php declare(strict_types=1);
/**
 * `nodes.kind` — was Felder halten, die auf einen Knoten zeigen, und die zwei Blöcke daraus.
 *
 *     php scripts/dev/node-kind-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-518](../../docs/NewConcept/90-decision-log.md), sein Entwurf:** *«eine Option erfinden, die
 * sagt: ist Field oder ist Setting? … und dass wir praktisch den Renderer zweimal aufrufen, einmal für
 * Fields und einmal für Settings, und dann jeweils eine andere Überschrift setzen.»*
 *
 * ⚠️ **Die Zusage, die diese Prüfung wirklich trägt, ist die Vererbung über den Vorfahrenlauf.** *Sie
 * ist der Grund, dass diese Angabe eine **Spalte** sein darf, wo `multiplicity` eine Setting-Zeile
 * bleiben musste — «because it inherits and can be narrowed»
 * ([50 Persistence](../../docs/NewConcept/50-wordpress-persistence.md)). **Fällt der Lauf, fällt die
 * Begründung der ganzen Bauform**, und die Prüfung muss das melden.*
 *
 * ⚠️ **Sie legt keinen Knoten an, und das ist Absicht.** *[Zeile 81](../../docs/NewConcept/97-implementation-plan.md#the-working-list):
 * es gibt kein «diesen einen Knoten endgültig löschen», nur «den ganzen Müll leeren». **Ein Knoten, den
 * man nicht anlegt, ist der einzige, den man nicht loswerden muss** — also markiert sie
 * **bestehende** Knoten kurz und nimmt es zurück, samt `register_shutdown_function`, damit auch ein
 * Absturz zurücknimmt.*
 *
 * @see docs/NewConcept/02-field-and-setting.md
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
require __DIR__ . '/geruest.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\NodeKind;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
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
$editor    = new ModelEditor($nodes, $edges, new TableIdentityAllocator(), $framework, $log);

echo "\n== 1. Die Spalte ist da und die Fassung sagt es ==\n";

$spalten = array_column($wpdb->get_results('SHOW COLUMNS FROM ' . Schema::table('nodes'), ARRAY_A), 'Field');

check('nodes hat die Spalte kind', in_array('kind', $spalten, true), implode(', ', $spalten));
check('Schemafassung ist mindestens 14', Schema::VERSION >= 14, (string) Schema::VERSION);
check(
    'und die Installation ist auf dieser Fassung',
    (int) get_option(Schema::VERSION_OPTION, 0) === Schema::VERSION,
    (string) get_option(Schema::VERSION_OPTION, 0)
);

echo "\n== 2. Was gesetzt ist, liest sich zurück ==\n";

// WICHTIG: Der Waechter baut seinen eigenen Einstellungsknoten, statt min, max und Validator im
// Modell zu suchen -- TASK-025. Sein Satz: "warum haben wir einen Check auf Adresse, ich hatte
// das mal so angelegt, aber das war kein Vertrag". Er hat min und max inzwischen verschoben, und
// die Zusage wurde rot, ohne dass etwas kaputt war.
$geruest = new Geruest('__nk');
$gebaut  = $geruest->einstellung('Schwelle', 'schwelle');

$markiert = [$gebaut['einstellung'] => '__nk Schwelle'];

check('der gebaute Knoten ist als Einstellung markiert', count($markiert) === 1, (string) count($markiert));

foreach ($markiert as $id => $name) {
    check($name . ' trägt kind = setting', $nodes->find($id)?->kind === NodeKind::Setting);
}

// ⚠️ **Zwei Darstellungen desselben Zustands sind eine Doppelung, und diese ist eingetreten.** *`%s`
// mit `null` schreibt in `$wpdb->prepare()` eine **leere Zeichenkette**; `fromStorage()` liest beide
// als «niemand hat etwas gesagt», aber `WHERE kind IS NOT NULL` findet nur eine. **Ein Knoten war so
// gleichzeitig markiert und nicht markiert**, je nachdem wer fragt.
$leer = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('nodes') . " WHERE kind = ''"
);

check('kein Knoten trägt eine leere Sorte statt NULL', $leer === 0, $leer . ' Zeile(n)');

$fremd = array_values(array_filter(
    $wpdb->get_col('SELECT DISTINCT kind FROM ' . Schema::table('nodes') . ' WHERE kind IS NOT NULL'),
    static fn ($v): bool => NodeKind::tryFrom((string) $v) === null
));

check('und keine Sorte, die der Code nicht kennt', $fremd === [], implode(', ', $fremd));

echo "\n== 3. Der Vorfahrenlauf — die Zusage, die die Spalte rechtfertigt ==\n";

$dataTypes = $framework->rootOf(Branch::DataTypes);
$text      = null;

foreach ($nodes->childrenOf($dataTypes) as $kind) {
    if ($kind->name === 'Text') {
        $text = $kind;
    }
}

if ($text === null) {
    check('ein Knoten Text unter Data Types', false);
} else {
    // ⚠️ **Der rohe Spaltenwert, nicht der über den Speicher gelesene — und das ist die Lehre eines
    // eigenen Fehlers vom 2026-08-29.** *Die Rücknahme lief zuerst über `ModelEditor::setKind()` und
    // las den Ausgangswert über `hydrate()`. In der Gegenprüfung «die Spalte wird nicht mehr gelesen»
    // gab `hydrate()` immer `null`, also hielt `withKind(null)` das Exemplar für unverändert,
    // **schrieb nichts, und `Data Types` blieb markiert stehen**. **Eine Prüfung, deren Aufräumung am
    // geprüften Code hängt, räumt genau dann nicht auf, wenn es nötig wäre.***
    $vorherRoh = $wpdb->get_var($wpdb->prepare(
        'SELECT kind FROM ' . Schema::table('nodes') . ' WHERE id = %d',
        $dataTypes->id
    ));

    // ⚠️ *`update()` und nicht `prepare()`: `$wpdb->prepare('%s', null)` ergibt eine **leere
    // Zeichenkette** und nicht NULL — gemessen, und es hat genau so eine Zeile hinterlassen.*
    register_shutdown_function(static function () use ($dataTypes, $vorherRoh): void {
        global $wpdb;

        $wpdb->update(
            Schema::table('nodes'),
            ['kind' => $vorherRoh],
            ['id' => $dataTypes->id],
            ['%s'],
            ['%d']
        );
    });

    $vorher = NodeKind::fromStorage($vorherRoh === null ? null : (string) $vorherRoh);

    check('Text hat keine eigene Sorte', $nodes->find($text->id)?->kind === null);
    check('und löst darum auf field auf', $nodes->resolvedKinds([$text->id])[$text->id] === NodeKind::Field);

    $editor->setKind($dataTypes->id, NodeKind::Setting);

    check(
        'markiert man Data Types, erbt Text die Sorte',
        $nodes->resolvedKinds([$text->id])[$text->id] === NodeKind::Setting,
        $nodes->resolvedKinds([$text->id])[$text->id]->value
    );
    check('ohne eine eigene bekommen zu haben', $nodes->find($text->id)?->kind === null);

    $editor->setKind($dataTypes->id, $vorher);

    check(
        'und zurückgenommen erbt es wieder field',
        $nodes->resolvedKinds([$text->id])[$text->id] === NodeKind::Field
    );
}

echo "\n== 4. In einer festen Zahl Abfragen, nicht einer je Ebene ==\n";

$viele = array_map(static fn ($n): int => $n->id, $nodes->childrenOf($dataTypes));
$viele[] = $framework->root()->id;

$vor = $wpdb->num_queries;
$nodes->resolvedKinds($viele);
$gebraucht = $wpdb->num_queries - $vor;

check(
    'höchstens zwei Abfragen für ' . count($viele) . ' Knoten',
    $gebraucht <= 2,
    $gebraucht . ' Abfragen'
);

echo "\n== 5. Zwei Blöcke auf dem Schirm, und die richtige Zeile im richtigen ==\n";

$_GET['page']        = 'taxmod';
$_GET['taxmod_node'] = (string) (int) get_option('taxmod_type_int_id', 0);

wp_set_current_user(1);

$rc   = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$ctor = $rc->getConstructor();
$ctor->setAccessible(true);
$plugin = $rc->newInstanceWithoutConstructor();
$ctor->invoke($plugin, __FILE__);

// WICHTIG: Die Seite des gebauten Traegers, nicht irgendeine. Vorher wurde ohne ausgewaehlten
// Knoten gezeichnet und darauf gehofft, dass «min» irgendwo steht.
$_GET['page']       = 'taxmod';
$_GET['taxmod_node'] = (string) $gebaut['traeger'];

$markup = $plugin->screen()->render();

preg_match_all('#<h3[^>]*>(Fields|Settings)<#', $markup, $treffer, PREG_OFFSET_CAPTURE);

check('beide Überschriften stehen genau einmal', count($treffer[0]) === 2, implode(', ', array_column($treffer[1], 0)));

if (count($treffer[0]) === 2) {
    $bloecke = [];

    for ($i = 0; $i < 2; $i++) {
        $von  = $treffer[0][$i][1];
        $bis  = $i === 0
            ? $treffer[0][1][1]
            : (strpos($markup, '<h3', $von + 5) ?: strlen($markup));
        $bloecke[$treffer[1][$i][0]] = substr($markup, $von, $bis - $von);
    }

    check('Fields und Settings in dieser Reihenfolge', array_keys($bloecke) === ['Fields', 'Settings'], implode(', ', array_keys($bloecke)));

    // ⚠️ **Die eigentliche Zusage: `min` steht bei den Einstellungen und nicht bei den Feldern.**
    // *Sie ist der Grund, dass diese Prüfung mehr misst als «zwei Überschriften erschienen».*
    check(
        'die gebaute Einstellung steht im Settings-Block',
        str_contains($bloecke['Settings'] ?? '', $gebaut['feld']),
        substr(strip_tags($bloecke['Settings'] ?? ''), 0, 80)
    );

    // Der Gegenfall: sie steht *nicht* bei den Feldern.
    check(
        'und nicht im Fields-Block',
        ! str_contains($bloecke['Fields'] ?? '', $gebaut['feld']),
        substr(strip_tags($bloecke['Fields'] ?? ''), 0, 80)
    );
    check('und nicht im Fields-Block', ! str_contains($bloecke['Fields'] ?? '', 'value="min"'));
    check(
        'Integer hat keine Benutzerfelder, und der Block sagt es',
        str_contains($bloecke['Fields'] ?? '', 'None yet'),
    );
}

// ⚠️ **Das Formular «Feld anlegen» steht unter den Feldern, nicht hinter beiden Blöcken.** *Der
// Eigentümer hat es gemeldet: «aktuell ist das Feld, um ein Field hinzuzufügen, unter Settings — dort
// ist es falsch». **Der Fehler entstand beim Bau der zwei Blöcke**: was hinter der Schleife stand,
// fiel hinter den letzten Block. Eine Stellungsfrage lässt sich nur an der Reihenfolge im Markup
// messen, nicht am Code.*
$stelleFormular = strpos($markup, 'value="add_field"');
$stelleSettings = $treffer[0][1][1] ?? null;
$stelleFields   = $treffer[0][0][1] ?? null;

check(
    'das Formular «Feld anlegen» steht zwischen Fields und Settings',
    $stelleFormular !== false && $stelleFields !== null && $stelleSettings !== null
        && $stelleFormular > $stelleFields && $stelleFormular < $stelleSettings,
    'Formular bei ' . var_export($stelleFormular, true)
        . ', Fields bei ' . var_export($stelleFields, true)
        . ', Settings bei ' . var_export($stelleSettings, true)
);

echo "\n== 6. Der Wähler zeigt drei Zustände, und «erbt» nennt die Antwort ==\n";

check('der Wähler steht auf der Seite', (bool) preg_match('#<select name="node_kind"#', $markup));
check('er nennt das Formular der Seite', (bool) preg_match('#<select name="node_kind"[^>]*form="taxmod-page-\d+"#', $markup));

preg_match_all('#<option value="([a-z]*)"#', (string) (preg_match('#<select name="node_kind".*?</select>#s', $markup, $s) ? $s[0] : ''), $optionen);

check('drei Einträge: erbt, field, setting', $optionen[1] === ['', 'field', 'setting'], implode('|', $optionen[1]));

// ⚠️ *«erbt» ohne die geerbte Antwort daneben wäre eine Wahl, die nichts sagt — [R14b](../../docs/NewConcept/30-renderer.md)s
// Regel, dass «nichts» eine Entscheidung sein muss und kein stiller Boden.*
check('und «erbt» nennt, was dabei herauskäme', (bool) preg_match('#<option value="" selected>inherited — (field|setting)</option>#', $markup));

echo "\n" . ($bad === 0 ? "Alles grün: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

$geruest->abbauen();

exit($bad === 0 ? 0 : 1);
