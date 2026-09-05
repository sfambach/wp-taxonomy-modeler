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

require __DIR__ . "/geruest.php";

use Taxmod\Core\Model\Relation;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\Submission;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
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

        // ⚠️ **Draussen ist gut, wenn es überhaupt ein Formular nennt.**
        //
        // ⚠️ *Hier stand `=== $formId`, also «das Formular der Zeile» — und das war zu streng. Die
        // Wertbedienungen der Einstellungen nennen absichtlich das Formular der **Seite**: der
        // Eigentümer wollte ein Speichern oben und keines je Wert. **Die Prüfung hätte richtige Arbeit
        // als Fehler gemeldet** — dasselbe, was `multiplicity-check.php` heute mit einem Namen tat.*
        if (preg_match('/\bform="([^"]+)"/', $tag)) {
            continue;
        }

        $stumm[] = $name;
    }

    return ['stumm' => $stumm, 'gesamt' => $gesamt, 'form' => $formId];
}

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, new WpdbChangelog(new SystemClock()));
$records   = new WpdbRecordRepository();
$rendering = new Rendering(
    $nodes,
    $framework,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), $framework),
    null,
    new ModelValues($records, $edges, $nodes, $framework)
);

$geruest = new Geruest('__fm');

echo "\n== Kein stummes Bedienelement in einer Feldzeile ==\n";

// ⚠️ *Drei Knoten und nicht einer: einer erklärt eigene Felder, einer erbt nur, einer trägt beides.
// Ein Fehler, der nur die eigene Deklaration trifft, fällt sonst nicht auf.*
//
// ⚠️ **Und die drei werden gebaut, nicht gesucht** ([D-613](../../docs/NewConcept/90-decision-log.md),
// vollzieht [D-022](../../docs/NewConcept/90-decision-log.md)). *Hier standen `Passiv`, `Dimension`
// und `Einheitenwert` — sein Modellinhalt, den er jederzeit umbenennen darf, und ein
// `WHERE name = … LIMIT 1` greift bei einem doppelt vergebenen Namen die falsche Zeile.*
$erklaerend = $geruest->feldMit('Erklaerend', 'eigen', '1')['von'];
$erbend     = $geruest->kindVon($erklaerend, 'Erbend');
$beides     = $geruest->feldMit('Beides', 'auch eigen', '1')['von'];

foreach ([$erklaerend, $erbend, $beides] as $id) {
    $name   = '#' . $id;
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
        'taxmod_field_setting',
        '',
        Level::Admin,
        [],
        // ⚠️ **Mit der Wertspalte, sonst prüft der Lauf eine Zeile, die es so nicht gibt.** *Seit dem
        // Einstellungsblock tragen die Zeilen Bedienelemente für den **Wert** — und die stehen in einer
        // eigenen Zelle, also **ausserhalb** des Formulars der Aktionsspalte. Genau die Lage, in der
        // schon zweimal ein Element stumm war.*
        [],
        'taxmod_value',
        'taxmod-page-' . $id
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

// ⚠️ *Die Prüfung oben ist die allgemeine; diese hier stirbt nicht, wenn jemand die Klasse
// umbenennt, sondern wenn das Feld wieder stumm wird.*
//
// ⚠️ *Sie hing an `Passiv`, weil das der Knoten war, an dem er es gemeldet hat. **Der Fall ist
// nicht der Knoten** ([D-613](../../docs/NewConcept/90-decision-log.md)): gebraucht wird einer, der
// eigene Felder erklärt, und einen davon hat der Lauf gerade selbst gebaut.*
$id       = $erklaerend;
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

foreach ($rendering->fieldRowsFor($kanten, $id, $actions, $submits, 'taxmod_field_name', 'taxmod_field_setting', '', Level::Admin, []) as $zeile) {
    $markup = $zeile->result->markup;

    if (! preg_match('/<input\b[^>]*\btaxmod-field-rename\b[^>]*>/', $markup, $treffer)) {
        continue;
    }

    ++$gefunden;

    if (! preg_match('/\bform="taxmod-field-\d+"/', $treffer[0])) {
        ++$stumm;
    }
}

check('der erklaerende Knoten zeichnet Umbenennungsfelder', $gefunden > 0, (string) $gefunden);
check('und jedes nennt sein Formular', $stumm === 0, "{$stumm} von {$gefunden} ohne form-Attribut");

echo "\n== Name und «wie oft» gehoeren ins Seitenformular, je Zeile eigen ==\n";

// ⚠️ **Der Wächter zu seinem Befund vom 2026-08-31:** *«in Display Option hatte ich für Converter die
// `1..1`-Beziehung angegeben … kann es aber nicht mit dem Speichern-Knopf in der Seite speichern.»*
//
// ⚠️ **Zwei Hälften, und `PR-12` verlangt, dass eine allein rot wird.** *Die Angabe muss (a) das
// Formular der **Seite** nennen — nicht das der Zeile, denn das schickt nur die Diskette ab, die es
// nicht mehr gibt — und (b) je Zeile einen **eigenen** Namen tragen. Ohne (b) wäre ein Formular voller
// `taxmod_setting[multiplicity]` genau eine Angabe für sechzig Zeilen, und die letzte gewinnt.*
$id = (int) $wpdb->get_var(
    'SELECT from_id FROM ' . Schema::table('relations') . " WHERE kind <> 'inheritance' GROUP BY from_id ORDER BY COUNT(*) DESC LIMIT 1"
);

$knoten     = $nodes->byId($id);
$kanten     = $edges->fieldEdgesOf([...$knoten->ancestorIds(), $knoten->id]);
$seitenForm = 'taxmod-page-' . $id;

$zeilen = $rendering->fieldRowsFor(
    $kanten,
    $id,
    [],
    [],
    'taxmod_field_name',
    'taxmod_field_setting',
    '',
    Level::Admin,
    [],
    [],
    'taxmod_value',
    $seitenForm
);

$namen       = [];
$wieOft      = [];
$falschesFor = [];

foreach ($zeilen as $zeile) {
    preg_match_all(
        '/<(?:input|select|textarea)\b[^>]*\bname="([^"]+)"[^>]*>/',
        $zeile->result->markup,
        $alle,
        PREG_SET_ORDER
    );

    foreach ($alle as $eines) {
        $name = $eines[1];

        $istName   = str_starts_with($name, 'taxmod_field_name[');
        $istWieOft = str_contains($name, 'taxmod_field_setting[') && str_contains($name, '[multiplicity]');

        if (! $istName && ! $istWieOft) {
            continue;
        }

        if ($istName) {
            $namen[] = $name;
        } else {
            $wieOft[] = $name;
        }

        if (! preg_match('/\bform="' . preg_quote($seitenForm, '/') . '"/', $eines[0])) {
            $falschesFor[] = $name;
        }
    }
}

check('die Zeilen zeichnen Namensfelder', $namen !== [], (string) count($namen));
check('die Zeilen zeichnen «wie oft»', $wieOft !== [], (string) count($wieOft));

check(
    'jedes nennt das Formular der Seite',
    $falschesFor === [],
    $falschesFor === [] ? '' : count($falschesFor) . ' nicht: ' . implode(', ', array_slice(array_unique($falschesFor), 0, 4))
);

// ⚠️ *Die Hälfte, die vorher fehlte: eindeutig je Zeile.*
check(
    'jeder Name kommt genau einmal vor',
    count(array_unique($namen)) === count($namen),
    (count($namen) - count(array_unique($namen))) . ' doppelt'
);

check(
    'und «wie oft» ebenso',
    count(array_unique($wieOft)) === count($wieOft),
    (count($wieOft) - count(array_unique($wieOft))) . ' doppelt'
);

// ⚠️ **Und der Leser, in derselben Prüfung** — *sonst ist genau das möglich, was `PR-12` beschreibt:
// die Bedienung zieht um, der Leser bleibt stehen, und alles bleibt grün.*
$schirm = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/NodesScreen.php');

check(
    'die Seite liest genau diesen Vorsatz',
    str_contains($schirm, "ROW_SETTING_FIELD = 'taxmod_field_setting'")
        && str_contains($schirm, 'private function saveFieldRows('),
    'ROW_SETTING_FIELD und saveFieldRows()'
);

check(
    'und niemand schickt die Angabe mehr an das Formular der Zeile',
    ! str_contains($schirm, "'save_field'"),
    'save_field steht noch im Schirm'
);

$geruest->abbauen();

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
