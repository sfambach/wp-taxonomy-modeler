<?php declare(strict_types=1);
/**
 * Ein Bedienelement mit einem Namen gehört zu einem Formular.
 *
 *     php scripts/dev/form-membership-check.php [path/to/wordpress]
 *
 * ⚠️ **Der Eigentümer hat es zum zweiten Mal gefunden, und beide Male dasselbe.** *Zuerst am
 * Auswahlkasten «wie oft» auf `Bauteilliste`: «`Position` von `0..1` auf `0..*` gestellt, gespeichert,
 * nichts passiert.» Und am 2026-08-30 am Namen: «Änderungen in Namen, zum Beispiel bei Fields und bei
 * Settings, werden nicht mehr übernommen.»*
 *
 * ⚠️ **Die Ursache ist eine Regel von HTML, keine des Modells.** *Eine Zeile ist ein `<tr>`, und ein
 * Formular darf keine Tabellenzellen umschließen — also baut die Aktionsspalte ihr Formular in **ihrer
 * eigenen** `<td>`, und jedes Bedienelement in einer anderen Zelle steht draußen. Ein Element draußen
 * ohne `form`-Attribut wird nie mitgeschickt: **der Klick sieht wie ein Speichern aus und schickt
 * nichts.***
 *
 * ⚠️ **Warum eine Prüfung und nicht ein zweiter Einzelfix.** *Als der Auswahlkasten geheilt wurde, hat
 * das Namensfeld denselben Fehler weiter getragen, eine Zelle daneben — und `attribute renamed` stand
 * **0 mal** im ganzen Changelog, während «wie oft» längst ankam. Ein Fehler, der zweimal auftritt, ist
 * eine Klasse. **Diese Prüfung fragt nicht nach dem Namensfeld, sondern nach jedem Element mit einem
 * `name`**: steht es im Formular oder nennt es eines? Sonst ist es stumm.*
 *
 * ⚠️ *Und der Gegenfall gehört dazu: die Zeile muss überhaupt Bedienelemente haben. Eine Prüfung, die
 * nur stumme Elemente sucht, wäre auch grün, wenn gar nichts mehr gezeichnet würde.*
 *
 * @see docs/NewConcept/30-renderer.md
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

use Taxmod\Core\Model\Relation;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\Submission;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
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

/**
 * Jedes benannte Bedienelement einer Zeile, das nichts abschickt.
 *
 * ⚠️ *Auf **unserer eigenen** Ausgabe, deshalb reicht ein Ausdruck: die Zeile ist eine `<tr>` mit
 * genau einem `<form>` darin, und alles davor oder danach steht draußen.*
 *
 * @return array{stumm: list<string>, gesamt: int, form: string}
 */
function stummeElemente(string $markup): array
{
    if (! preg_match('/<form\b[^>]*\bid="([^"]*)"[^>]*>/', $markup, $treffer, PREG_OFFSET_CAPTURE)) {
        return ['stumm' => [], 'gesamt' => 0, 'form' => ''];
    }

    $formId  = $treffer[1][0];
    $beginnt = (int) $treffer[0][1];
    $endet   = strpos($markup, '</form>', $beginnt);
    $endet   = $endet === false ? strlen($markup) : $endet;

    $stumm  = [];
    $gesamt = 0;

    preg_match_all(
        '/<(?:input|select|textarea|button)\b[^>]*\bname="([^"]+)"[^>]*>/',
        $markup,
        $alle,
        PREG_OFFSET_CAPTURE | PREG_SET_ORDER
    );

    foreach ($alle as $eines) {
        ++$gesamt;

        $tag  = $eines[0][0];
        $wo   = (int) $eines[0][1];
        $name = $eines[1][0];

        // Drinnen ist gut.
        if ($wo > $beginnt && $wo < $endet) {
            continue;
        }

        // Draußen ist gut, wenn es sein Formular nennt — und zwar dieses.
        if (preg_match('/\bform="([^"]*)"/', $tag, $gesagt) && $gesagt[1] === $formId) {
            continue;
        }

        $stumm[] = $name;
    }

    return ['stumm' => $stumm, 'gesamt' => $gesamt, 'form' => $formId];
}

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), new WpdbChangelog(new SystemClock()));
$records   = new WpdbRecordRepository();
$rendering = new Rendering(
    $nodes,
    $framework,
    new Settings(new WpdbSettingRepository(), $nodes, $framework),
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), $framework),
    null,
    new ModelValues($records, $edges, $nodes, $framework)
);

echo "\n== Kein stummes Bedienelement in einer Feldzeile ==\n";

// ⚠️ *Drei Knoten und nicht einer: `Passiv` erklärt eigene Felder, `Dimension` erbt, `Einheitenwert`
// trägt eine Kopie. Ein Fehler, der nur die eigene Deklaration trifft, fällt sonst nicht auf.*
foreach (['Passiv', 'Dimension', 'Einheitenwert'] as $name) {
    $id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name = %s LIMIT 1',
        $name
    ));

    if ($id === 0) {
        check("«{$name}» steht im Modell", false);

        continue;
    }

    $knoten = $nodes->byId($id);
    $kanten = $edges->fieldEdgesOf([...$knoten->ancestorIds(), $knoten->id]);

    // ⚠️ *Dieselben Zutaten, die der Schirm einsetzt — sonst prüft der Lauf eine Zeile, die es so
    // nirgends gibt. Der Speicherknopf ist der, an dem es hängt.*
    $actions = [];
    $submits = [];

    foreach ($kanten as $kante) {
        $eigen             = $kante->fromId === $id;
        $actions[$kante->id] = [Control::saving('do', 'save_field', 'Save', '', $eigen)];
        $submits[$kante->id] = new Submission('/wp-admin/admin-post.php', [
            'action'        => 'taxmod_nodes',
            'id'            => (string) $id,
            'edge'          => (string) $kante->id,
            'setting_key'   => 'multiplicity',
            '_taxmod_nonce' => 'pruefung',
        ]);
    }

    $zeilen = $rendering->fieldRowsFor(
        $kanten,
        $id,
        $actions,
        $submits,
        'taxmod_field_name',
        'taxmod_setting',
        '',
        Level::Admin,
        []
    );

    $stumm    = [];
    $benannte = 0;
    $mitForm  = 0;

    foreach ($zeilen as $zeile) {
        $befund = stummeElemente($zeile->result->markup);

        if ($befund['form'] === '') {
            continue;
        }

        ++$mitForm;
        $benannte += $befund['gesamt'];
        $stumm     = [...$stumm, ...$befund['stumm']];
    }

    check(
        "«{$name}»: jedes benannte Element schickt ab",
        $stumm === [],
        $stumm === [] ? '' : count($stumm) . ' stumm: ' . implode(', ', array_unique($stumm))
    );

    // ⚠️ **Der Gegenfall.** *Ohne ihn wäre eine Zeile ohne jedes Bedienelement grün — und genau das
    // ist der Zustand, den eine leere Ausgabe erzeugt.*
    check(
        "«{$name}»: die Zeilen tragen ueberhaupt Bedienelemente",
        $mitForm > 0 && $benannte >= $mitForm,
        "{$mitForm} Zeilen mit Formular, {$benannte} benannte Elemente"
    );
}

echo "\n== Und das Namensfeld ist eines davon ==\n";

// ⚠️ *Namentlich, weil dies der Fall ist, den er gemeldet hat. Die Prüfung oben ist die allgemeine;
// diese hier stirbt nicht, wenn jemand die Klasse umbenennt, sondern wenn das Feld wieder stumm wird.*
$id = (int) $wpdb->get_var(
    'SELECT id FROM ' . Schema::table('nodes') . " WHERE name = 'Passiv' LIMIT 1"
);

$knoten   = $nodes->byId($id);
$kanten   = $edges->fieldEdgesOf([...$knoten->ancestorIds(), $knoten->id]);
$actions  = [];
$submits  = [];

foreach ($kanten as $kante) {
    $eigen               = $kante->fromId === $id;
    $actions[$kante->id] = [Control::saving('do', 'save_field', 'Save', '', $eigen)];
    $submits[$kante->id] = new Submission('/wp-admin/admin-post.php', [
        'action'        => 'taxmod_nodes',
        'id'            => (string) $id,
        'edge'          => (string) $kante->id,
        '_taxmod_nonce' => 'pruefung',
    ]);
}

$gefunden = 0;
$stumm    = 0;

foreach ($rendering->fieldRowsFor($kanten, $id, $actions, $submits, 'taxmod_field_name', 'taxmod_setting', '', Level::Admin, []) as $zeile) {
    $markup = $zeile->result->markup;

    if (! preg_match('/<input\b[^>]*\btaxmod-field-rename\b[^>]*>/', $markup, $treffer)) {
        continue;
    }

    ++$gefunden;

    if (! preg_match('/\bform="taxmod-field-\d+"/', $treffer[0])) {
        ++$stumm;
    }
}

check('«Passiv» zeichnet Umbenennungsfelder', $gefunden > 0, (string) $gefunden);
check('und jedes nennt sein Formular', $stumm === 0, "{$stumm} von {$gefunden} ohne form-Attribut");

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
