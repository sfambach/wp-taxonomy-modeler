<?php declare(strict_types=1);

/**
 * Geht die Renderer-Wahl den Weg **ueber die Maske** — waehlen, speichern, neu lesen, nachsehen?
 *
 * TASK-052, [D-617](../../docs/NewConcept/90-decision-log.md). **Sein Befund:** *«der wird irgendwie
 * aktuell nicht beruecksichtigt und auch nicht gespeichert».*
 *
 * ⚠️ **Diese Datei entstand, weil vier gruene Waechter ihm widersprachen und er recht hatte.**
 * *`renderer-choice-check`, `setting-write-check`, `page-blocks-check` und `multiplicity-check`
 * schreiben alle ueber den **Kern**. Gemessen am 2026-09-05 stand auf der Seite von `Integer` **kein
 * einziges Steuerelement mit `renderer` im Namen** — es gab nichts zu speichern, weil es nichts zu
 * bedienen gab, und keine der vier konnte das sehen. **Diese hier geht deshalb den ganzen Weg:
 * Markup lesen, `handlePost()` rufen, frisch aufloesen.***
 *
 * ⚠️ **Eigene Knoten, kein Name aus seinem Modell** ([D-613](../../docs/NewConcept/90-decision-log.md),
 * [D-614](../../docs/NewConcept/90-decision-log.md)): angelegt, geprueft, weggeraeumt — und
 * weggeraeumt nach dem eigenen Namensmuster `__rcm `, nie ueber `clearTrash()`.
 *
 * ⚠️ *`handlePost()` endet mit `exit`. Der Lauf haengt sich deshalb in `wp_redirect` und wirft dort —
 * **die Weiterleitung ist das Zeichen, dass der Akt durch ist**, und der Wurf holt den Lauf zurueck,
 * ohne dass die Methode dafuer umgebaut werden muesste.*
 *
 * Usage: php scripts/dev/renderer-choice-mask-check.php C:/Devel/Wordpress
 *
 * @see docs/pakete/modelltabellen/tasks.md
 */

$wordpress = $argv[1] ?? 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Renderer\FormRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;

        echo "  ok   {$what}\n";

        return;
    }

    $failed++;

    echo "  FAIL {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $rows);
$types     = new SeededTypeNodes($nodes, $framework);

/**
 * Die Seite als Markup, so wie ein Browser sie bekommt.
 *
 * ⚠️ *`$offeneZeilen` ist der Umstand aus [D-666](../../docs/NewConcept/90-decision-log.md) —
 * `null` heisst «alles zugeklappt», und **zugeklappt heisst nicht aufgeloest**.*
 */
function seite(int $nodeId, ?string $offeneZeilen = null): string
{
    $_GET['page']        = 'taxmod-nodes';
    $_GET['taxmod_node'] = (string) $nodeId;

    if ($offeneZeilen === null) {
        unset($_GET['taxmod_open_rows']);
    } else {
        $_GET['taxmod_open_rows'] = $offeneZeilen;
    }

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    $markup = $plugin->screen()->render();

    unset($_GET['taxmod_open_rows']);

    return $markup;
}

/**
 * Den Akt abschicken, wie das Seitenformular ihn abschickt — und die Weiterleitung abfangen.
 *
 * @param array<string, mixed> $post
 */
function abschicken(array $post): bool
{
    // ⚠️ *`$_REQUEST` mit, weil `check_admin_referer()` dort nachsieht und nicht in `$_POST` — sonst
    // stirbt der Akt mit «the link you followed has expired», und der Waechter meldete einen Fehler,
    // den nur er selbst gemacht hat.*
    $_POST    = $post;
    $_REQUEST = $post;

    $gewandert = false;

    // ⚠️ *Die Adresse der Weiterleitung wird mitgeschrieben: **manche Akte schreiben nichts und
    // ändern nur einen Umstand** ([D-666](../../docs/NewConcept/90-decision-log.md)), und dann ist
    // die Adresse das Einzige, woran ihr Ergebnis zu sehen ist.*
    $GLOBALS['taxmod_letzte_adresse'] = '';

    $fang = static function (string $ort) use (&$gewandert): string {
        $gewandert = true;

        $GLOBALS['taxmod_letzte_adresse'] = $ort;

        throw new RuntimeException('redirect');
    };

    add_filter('wp_redirect', $fang, 1);

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    try {
        $plugin->screen()->handlePost();
    } catch (RuntimeException) {
        // ⚠️ *Erwartet: der Akt ist durch und wollte weiterleiten.*
    } finally {
        remove_filter('wp_redirect', $fang, 1);
        $_POST = [];
        $_REQUEST = [];
    }

    return $gewandert;
}

wp_set_current_user(1);

// ⚠️ **Die Wahl steht in der Zeile `renderer` des Einstellungsblocks und nirgends sonst**
// ([D-644](../../docs/NewConcept/90-decision-log.md)). *Adressiert wird sie wie jeder andere Wert —
// ueber die **Id der Einstellungskante**, `taxmod_value[<kante>]`, und nicht ueber einen Schluessel.*
$kante = $framework->settingRelationId(SettingKey::Renderer);

if ($kante === 0) {
    echo "  FAIL die Einstellungskante `renderer` ist nicht aufgeschrieben\n";

    exit(1);
}

/**
 * Was die Zeile `renderer` auf dieser Seite anbietet — Knoten-Id => Name.
 *
 * @return array<int, string>
 */
function angebotDerZeile(string $markup, int $kante): array
{
    global $wpdb;

    $hinter = preg_split('/name="taxmod_value\[' . $kante . '\]"/', $markup)[1] ?? '';

    preg_match_all('/<option value="([^"]*)"/', explode('</select>', $hinter)[0], $treffer);

    $aus = [];

    foreach ($treffer[1] ?? [] as $roh) {
        if ($roh === '') {
            continue;
        }

        $id  = (int) $roh;
        $p   = $wpdb->prefix . 'taxmod_';
        $aus[$id] = (string) $wpdb->get_var("SELECT name FROM {$p}nodes_named WHERE id = {$id}");
    }

    return $aus;
}

/**
 * Was in der Liste **steht** — Knoten-Id => angezeigter Text.
 *
 * ⚠️ *Das Gegenstueck zu {@see angebotDerZeile()}, und der Unterschied ist die ganze Zusage:
 * dort steht der **Name aus der Datenbank**, hier der **Text aus dem Markup**. Faellt die
 * `select`-Rolle weg, sind beide wieder gleich — und nur dieser Weg sieht es.*
 *
 * @return array<int, string>
 */
function beschriftungenDerZeile(string $markup, int $kante): array
{
    $hinter = preg_split('/name="taxmod_value\[' . $kante . '\]"/', $markup)[1] ?? '';

    preg_match_all(
        '/<option value="([^"]*)"[^>]*>([^<]*)</',
        explode('</select>', $hinter)[0],
        $treffer,
        PREG_SET_ORDER
    );

    $aus = [];

    foreach ($treffer as $eins) {
        if ($eins[1] === '') {
            continue;
        }

        $aus[(int) $eins[1]] = html_entity_decode($eins[2], ENT_QUOTES, 'UTF-8');
    }

    return $aus;
}

// ============================================================================
// Umgezogen am 2026-09-06, weil drei Laeufe gestrichen wurden
// ============================================================================
//
// ⚠️ **Die Zusagen hierunter standen bis zum 2026-09-06 in `renderer-choice-check.php`,
// `rename-survives-check.php` und `settings-record-carrier-check.php`.** *Alle drei sind an diesem
// Tag gestrichen worden ([`waechter-bestand.md`](../../docs/pakete/modelltabellen/waechter-bestand.md)),
// auf sein Wort «checks mein ja». **Was hier steht, ist der Teil, den dieser Lauf noch nicht sagte** —
// er ist umgezogen und nicht weggefallen (`PR-9`: das Aendern eines Waechters ist ein sichtbarer Teil
// der Aenderung, nie etwas, das nebenbei geschieht).*
//
// ⚠️ *Sie stehen **vor** allen eigenen Akten dieses Laufs, und das ist Absicht — es hat einen
// Fehlalarm gekostet, es andersherum zu versuchen.* **Gemessen am 2026-09-06:** *ans Ende gestellt,
// meldete «keine gespeicherte Wahl steht ausserhalb der zulaessigen Menge» ein `Root → field`, das
// **dieser Lauf selbst** kurz zuvor an die Wurzel geschrieben hatte (die Zusage zu
// [D-617](../../docs/NewConcept/90-decision-log.md) setzt dort eine Wahl aus dem Angebot eines
// **anderen** Knotens). Diese Zusagen lesen den Bestand des Eigentuemers als Ganzes, also muessen sie
// ihn sehen, bevor der Lauf ihn anfasst.*

echo "\n== umgezogen: die Wahl haengt am Knoten, nicht an einer Verwendungsstelle ==\n";

$renderKante = $framework->settingRelationId(SettingKey::Renderer);

check('die Einstellungskante `renderer` ist aufgeschrieben (umgezogen)', $renderKante !== 0, (string) $renderKante);

// ⚠️ **Aus `renderer-choice-check`.** *Vier Altlasten aus einem Umzugslauf vom 2026-08-30 trugen eine
// Renderer-Wahl an einer **Kante** statt am Knoten, und sie wirkten: `Passiv.Tolerance` zeichnete mit
// `field`, waehrend sein Zielknoten `slider` sagte. Sein Auftrag: «entferne mal die Altlasten.»
// **Seither haelt diese Zusage fest, dass keine da sind.** Seit [D-611](../../docs/NewConcept/90-decision-log.md)
// darf eine Verwendungsstelle andere Einstellungen ueberschreiben — der Renderer ist ausgenommen
// ([D-643](../../docs/NewConcept/90-decision-log.md)), darum zaehlt sie nur diese eine Kante.*
$anStelle = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . " WHERE path LIKE '%.%' AND relation_id = %d",
    $renderKante
));

check('keine Renderer-Wahl an einer Verwendungsstelle', $anStelle === 0, (string) $anStelle);

// ⚠️ *Der Gegenfall: es gibt ueberhaupt Kanten-Datensaetze. Sonst waere «keine zweistufigen» auch bei
// leerer Tabelle gruen. **«Ueberhaupt» ist die Aussage, nicht eine Zahl aus seinem Bestand.***
$alleWertzeilen = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('relation_records'));

check('und es gibt Kanten-Datensaetze', $alleWertzeilen >= 1, (string) $alleWertzeilen);

echo "\n== umgezogen: jeder Traeger der Wahl haelt, worauf er zeigt ==\n";

// ⚠️ **Aus `settings-record-carrier-check`.** *Die vier bekannten Reste sind benannt und gedeckelt,
// nicht weggeschaut: zwei Neuform-Zeilen, die `displayoption-migrate.php` bei TASK-024 ausdruecklich
// hat stehenlassen, und der Halter der **Wurzel** (`INF-014`). **Die Zahl darf fallen und nie
// steigen** — steigt sie, hat wieder etwas seinen Halter verloren.*
$bekannteReste = 4;

$tote = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v
       LEFT JOIN ' . Schema::table('relations') . ' r ON r.id = v.relation_id
      WHERE r.id IS NULL'
);

check(
    "hoechstens die {$bekannteReste} benannten Reste stehen an toten Kanten",
    $tote <= $bekannteReste,
    $tote . ' statt hoechstens ' . $bekannteReste
);

$traegerZahl = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . " WHERE relation_id = %d AND value_ref_kind = 'record'",
    $renderKante
));

check('die Einstellungskante ist ueberhaupt in Gebrauch', $traegerZahl > 0, (string) $traegerZahl);

$insLeere = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v
       LEFT JOIN ' . Schema::table('node_records') . ' s ON s.id = v.value_ref
      WHERE v.relation_id = %d AND v.value_ref_kind = \'record\' AND s.id IS NULL',
    $renderKante
));

check('kein Traeger zeigt ins Leere', $insLeere === 0, (string) $insLeere);

// ⚠️ *Ein Satz ohne Knoten sagt nicht, welcher Renderer er ist — der Traeger fuehrt dann formal
// irgendwohin und inhaltlich nirgends. **Genau der Zustand, in dem die Wahlen nach dem Loeschen des
// Huellknotens waren** ([D-604](../../docs/NewConcept/90-decision-log.md)).*
$ohneKnoten = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v
       JOIN ' . Schema::table('node_records') . ' s ON s.id = v.value_ref
       LEFT JOIN ' . Schema::table('nodes') . ' z ON z.id = s.node_id
      WHERE v.relation_id = %d AND v.value_ref_kind = \'record\' AND z.id IS NULL',
    $renderKante
));

check('jeder Einstellungssatz gehoert einem Knoten', $ohneKnoten === 0, (string) $ohneKnoten);

// ⚠️ **Und die drei Spalten duerfen nicht zurueckkommen** (Fassung 32). *`dbDelta` legt eine fehlende
// Spalte klaglos wieder an; kaeme eine der drei zurueck, haette der Renderer wieder zwei Orte — und
// das war der Zustand, aus dem TASK-052 und dieser Lauf entstanden sind.*
foreach ([['nodes', 'settings_record_id'], ['relations', 'settings_record_id'], ['relations', 'target_settings_record_id']] as [$tabelle, $spalte]) {
    $tabellenName = Schema::table($tabelle);

    check(
        "die Spalte «{$tabelle}.{$spalte}» ist weg und bleibt weg",
        $wpdb->get_var("SHOW COLUMNS FROM {$tabellenName} LIKE '{$spalte}'") === null
    );
}

echo "\n== umgezogen: jede gespeicherte Wahl ist eine erlaubte, und sie loest auf ==\n";

// ⚠️ **Ein eigener Zeichenweg, weil diese Zusagen den ganzen Bestand lesen und nicht die Wiese dieses
// Laufs.** *Frisch gebaut: {@see \Taxmod\Core\Service\ModelValues} merkt sich seine Funde je Instanz
// (`CD-7`), und ein wiederverwendeter Leser gaebe die alte Antwort zurueck.*
$bestandWerte = new ModelValues($rows, $relations, $nodes, $framework);

$bestandZeichnen = new Rendering(
    $nodes,
    $framework,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale()),
    null,
    $bestandWerte,
    $relations
);

$merkmal = [
    'slider'   => 'type="range"',
    'toggle'   => 'taxmod-toggle',
    'spinner'  => 'type="number"',
    'field'    => 'type="text"',
    'checkbox' => 'type="checkbox"',
];

$traeger    = 0;
$mitNamen   = 0;
$gezeichnetZahl = 0;
$daneben    = [];
$unerlaubt  = [];

foreach ($wpdb->get_results(
    $wpdb->prepare(
        'SELECT DISTINCT halter.node_id
           FROM ' . Schema::table('node_records') . ' halter
           JOIN ' . Schema::table('relation_records') . " wert ON wert.node_record_id = halter.id
          WHERE wert.relation_id = %d AND wert.value_ref_kind = 'record'",
        $renderKante
    ),
    ARRAY_A
) ?: [] as $zeile) {
    $knoten = $nodes->find((int) $zeile['node_id']);

    if ($knoten === null) {
        continue;
    }

    ++$traeger;

    $name = $bestandZeichnen->rendererNameFor($knoten);

    if ($name !== null) {
        ++$mitNamen;
    }

    // ⚠️ **Aus `renderer-choice-check`, seine Anweisung am 2026-09-06:** *«dann bei den mal ueberall
    // den renderer ueberpruefen dass ein erlaubter gesetzte ist».* **Gemessen war es an drei von neun
    // nicht so** — `Base units` trug `table`, `Dimension` trug `node`, `Volt` trug `chooser-dialog`,
    // alle drei ausserhalb der Menge, die ihr eigener Knoten zulaesst, **und niemand hat es
    // gemeldet**. *Eine Invariante und keine Zahl: was gespeichert ist, muss auch angeboten sein.*
    if ($name !== null) {
        $erlaubt = [];

        foreach ($bestandZeichnen->choicesForNode($knoten) as $einer) {
            $erlaubt[] = $einer->name();
        }

        if (! in_array($name, $erlaubt, true)) {
            $unerlaubt[] = $knoten->name . ' → ' . $name;
        }
    }

    if ($name === null || ! isset($merkmal[$name])) {
        continue;
    }

    $zeichnung = $bestandZeichnen->valueOfType($knoten, Purpose::Edit);

    if ($zeichnung === null) {
        continue;
    }

    ++$gezeichnetZahl;

    if (! str_contains($zeichnung->markup, $merkmal[$name])) {
        $daneben[] = "{$knoten->name}: sagt «{$name}», zeichnet ohne «{$merkmal[$name]}»";
    }
}

// ⚠️ **Die Zusage, auf die es ankommt, ist eine Invariante:** *jeder Traeger, den es **gibt**, loest zu
// einem Renderer auf. Faellt eine Wahl bei einem Umzug weg, bleibt der Traeger stehen und zeigt ins
// Leere — genau das faellt hier auf, unabhaengig davon, wie viele es sind. **Und null Traeger waere
// keine gruene Antwort, sondern eine leere Wiese.***
check(
    'jeder Traeger an der Kante loest zu einem Renderer auf',
    $traeger > 0 && $traeger - $mitNamen === 0,
    $traeger === 0 ? 'kein einziger Traeger' : ($traeger - $mitNamen) . ' von ' . $traeger . ' loesen ins Leere'
);

check(
    'keine gespeicherte Wahl steht ausserhalb der zulaessigen Menge',
    $unerlaubt === [],
    implode(' · ', array_slice($unerlaubt, 0, 6))
);

check('die Zeichnung traegt das Merkmal des gesetzten Renderers', $daneben === [], implode(' · ', array_slice($daneben, 0, 4)));

check('und mindestens eine Zeichnung war darunter', $gezeichnetZahl >= 1, (string) $gezeichnetZahl);

echo "\n== und die Wanderung raeumt weg, was unzulaessig geworden ist (Fassung 38) ==\n";

// ⚠️ **Die Gegenprobe zu der Zusage darueber, und ohne sie ist die Wanderung ungeprueft**
// ([D-672](../../docs/NewConcept/90-decision-log.md), sein dritter Punkt: *«die heutigen
// Handreparaturen gehoeren in genau so eine Wanderung»*).
//
// ⚠️ **Der Grund, dass sie sich ihren Fall selbst bauen muss:** *auf einer Installation, auf der
// nichts unzulaessig ist, tut die Wanderung **nichts** — gemessen am 2026-09-06 sind alle zwoelf
// gespeicherten Wahlen zulaessig, und die Zusage darueber sagt genau das. **Am Bestand gemessen
// waere sie also gruen, ohne je gelaufen zu sein.** Also: eine eigene Wiese, Praefix `__mg `, ein
// gepflanzter Verstoss, und weggeraeumt danach ([D-613](../../docs/NewConcept/90-decision-log.md)).*
//
// ⚠️ *Und der Weg hinein ist der des Menschen — `put_setting` ueber die Maske. **Die Maske nimmt
// eine unzulaessige Wahl gemessen an**, und das ist kein Fehler dieses Laufs, sondern genau der
// Zustand, den D-672 beschreibt: die Wahl war zulaessig, als sie gesetzt wurde, und die Regel hat
// sich danach geaendert.*
$mgIntId = $types->nodeId(SimpleType::Int);
$mgForm  = $editor->nodeImplementing(FormRenderer::class);

if ($mgIntId === null || $mgForm === null) {
    check('der Int-Typ und der Form-Renderer stehen als Knoten da', false, 'einer von beiden fehlt');
} else {
    $mgProbe = $editor->createNode('__mg probe', $mgIntId);

    $mgErlaubt = [];

    foreach ($bestandZeichnen->choicesForNode($mgProbe) as $einer) {
        $mgErlaubt[] = $einer->name();
    }

    // ⚠️ *Der Verstoss muss einer **sein**, sonst pflanzt der Lauf eine zulaessige Wahl und die
    // Wanderung tut zu Recht nichts — und die Gegenprobe waere gruen aus dem falschen Grund.*
    check(
        '`form` zeichnet keinen Int-Knoten, ist also eine unzulaessige Wahl',
        ! in_array($mgForm->name, $mgErlaubt, true),
        'erlaubt: ' . implode(',', $mgErlaubt)
    );

    abschicken([
        'do'            => 'put_setting',
        'id'            => (string) $mgProbe->id,
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $mgProbe->id),
        'taxmod_value'  => [(string) $renderKante => (string) $mgForm->id],
    ]);

    $mgZeile = (int) $wpdb->get_var(
        "SELECT v.id
           FROM {$p}relation_records v
           JOIN {$p}node_records r ON r.id = v.node_record_id
          WHERE r.node_id = {$mgProbe->id} AND v.path = '{$renderKante}'"
    );

    check('die unzulaessige Wahl steht als Wertzeile da', $mgZeile !== 0, (string) $mgZeile);

    // ⚠️ *Und sie **wirkt** — der Knoten zeichnet mit ihr. Ohne diese Zeile pruefte die Gegenprobe
    // das Wegraeumen einer Zeile, die ohnehin nichts bewirkt hat.*
    check(
        'und sie wirkt: der Knoten zeichnet mit `form`',
        gespeicherterRenderer($mgProbe->id) === $mgForm->name,
        'gelesen «' . gespeicherterRenderer($mgProbe->id) . '»'
    );

    $mgGefallen = Schema::dropRendererChoicesOutsideTheEligibleSet();

    check(
        'die Wanderung meldet den Knoten und den Renderer',
        in_array($mgProbe->name . ' → ' . $mgForm->name, $mgGefallen, true),
        'gemeldet: ' . (implode(' · ', $mgGefallen) ?: '—')
    );

    $mgOption = get_option('taxmod_renderer_choice_drop');

    check(
        'und dieselbe Meldung steht in der Option',
        is_array($mgOption)
            && in_array($mgProbe->name . ' → ' . $mgForm->name, (array) ($mgOption['gefallen'] ?? []), true),
        (string) wp_json_encode($mgOption)
    );

    check(
        'die Wertzeile ist weg',
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records WHERE id = {$mgZeile}") === 0
    );

    // ⚠️ **Und sie liegt im Schatten, nicht im Nichts** ([D-536](../../docs/NewConcept/90-decision-log.md)).
    // *Das ist der halbe Beschluss: eine Wanderung, die nicht umkehrbar ist, ist ein Datenverlust mit
    // einer Fassungsnummer davor. **Genau daran ist am 2026-09-06 schon einmal etwas
    // verlorengegangen.***
    check(
        'und sie liegt im Schatten, also ist der Schritt umkehrbar',
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records_history WHERE id = {$mgZeile}") >= 1
    );

    check(
        'das Aenderungsbuch nennt Knoten und Renderer',
        (string) $wpdb->get_var(
            "SELECT before_state FROM {$p}changelog
              WHERE owner_kind = 'record_value' AND owner_id = {$mgZeile}
                AND what = 'renderer choice not eligible dropped'
              ORDER BY id DESC LIMIT 1"
        ) === $mgProbe->name . ' → ' . $mgForm->name
    );

    // ⚠️ **Der Zweck des Ganzen:** *die Wahl faellt, der Rueckfall greift, **der Knoten zeichnet
    // wieder** — und was stattdessen gelten soll, hat die Wanderung nicht entschieden (`PR-4`).*
    check(
        'und der Knoten zeichnet wieder, mit einer zulaessigen Wahl',
        in_array(gespeicherterRenderer($mgProbe->id), $mgErlaubt, true),
        'gelesen «' . gespeicherterRenderer($mgProbe->id) . '», erlaubt: ' . implode(',', $mgErlaubt)
    );

    // ⚠️ *Zweimal ausfuehrbar, und das ist keine Kleinigkeit: eine Wanderung laeuft auf jeder
    // Aktivierung wieder los, sobald die Fassungsnummer noch einmal steigt.*
    check('ein zweiter Lauf findet nichts mehr', Schema::dropRendererChoicesOutsideTheEligibleSet() === []);

    // ⚠️ *Die Wiese wieder abraeumen, nach dem eigenen Namensmuster und nie ueber `clearTrash()` —
    // dieselbe Regel wie am Fuss dieses Laufs. Was ein Abbruch stehen laesst, dreht die Klammer aus
    // `lib/no-write.php` zurueck.*
    $mgMeine = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}nodes_named WHERE name LIKE '\\_\\_mg %'"));
    $mgIn    = $mgMeine === [] ? (string) $mgProbe->id : implode(',', $mgMeine);

    $wpdb->query("DELETE FROM {$p}relation_records WHERE node_record_id IN (SELECT id FROM {$p}node_records WHERE node_id IN ({$mgIn}))");
    $wpdb->query("DELETE FROM {$p}node_records WHERE node_id IN ({$mgIn})");
    $wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$mgIn})");
    $wpdb->query("DELETE FROM {$p}relations WHERE from_node_id IN ({$mgIn}) OR to_node_id IN ({$mgIn})");
    $wpdb->query("DELETE FROM {$p}nodes WHERE id IN ({$mgIn})");

    check(
        'und die Wiese ist wieder abgeraeumt',
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes_named WHERE name LIKE '\\_\\_mg %'") === 0
    );
}

echo "\n== umgezogen: eine Umbenennung aendert nichts an dem, was gezeichnet wird ==\n";

// ⚠️ **Aus `rename-survives-check`, und das ist die Zusage, um derer willen der Lauf ueberhaupt
// bestand.** *Der Eigentuemer hat die Kante `renderer` in «Display Options» umbenannt — sein Recht,
// ein Name ist eine Beschriftung. **Damit fiel die Renderer-Aufloesung im ganzen Schirm aus**, ohne
// eine Zeile Fehler ([D-543](../../docs/NewConcept/90-decision-log.md): «ja, Id — Name war nie
// erlaubt»). Diese Zusage benennt selbst um und verlangt, dass danach dasselbe herauskommt.*
//
// ⚠️ *Beobachtet wird jeder Knoten unter der Wurzel ausser dem Muell — **keine Liste seiner Namen**
// ([D-613](../../docs/NewConcept/90-decision-log.md)). Und der Gegenfall gehoert dazu: die Aufloesung
// muss vorher ueberhaupt etwas liefern, sonst waere «vorher wie nachher» auch dann wahr, wenn beide
// Male nichts herauskaeme.*
$beschriftungen = new WpdbLabelRepository();

$benenne = static function (IdentitySpace $raum, int $id, string $name) use ($beschriftungen): void {
    $beschriftungen->put(new Label($id, $raum, SeededRole::Name, Label::BASE_NUMBER, SettingsScreen::neutralLocale(), $name));
};

/**
 * Was jeder Knoten zeichnet — mit einem **frischen** Leser, damit nichts aus dem Gedaechtnis kommt.
 *
 * @param list<int> $ids
 * @return array<int, string>
 */
$zeichnetJetzt = static function (array $ids) use ($nodes, $relations, $framework, $rows): array {
    $werte   = new ModelValues($rows, $relations, $nodes, $framework);
    $antwort = [];
    $reg     = ShippedRenderers::registry();

    foreach ($ids as $id) {
        $knoten       = $nodes->find($id);
        $antwort[$id] = $knoten === null
            ? '(kein Knoten)'
            : ($reg->chosenFor($knoten, $werte->forNode($knoten), Purpose::Edit)?->name() ?? 'nichts');
    }

    return $antwort;
};

$beobachtet = array_values(array_diff(
    $nodes->subtreeIds($framework->root()->id),
    $nodes->subtreeIds($framework->trash()->id),
    [$framework->root()->id]
));

sort($beobachtet);

$vorher = $zeichnetJetzt($beobachtet);

$etwas = count(array_filter($vorher, static fn (string $w): bool => $w !== 'nichts' && $w !== 'plain'));

check('die Aufloesung liefert vorher ueberhaupt etwas', $etwas >= 1, "{$etwas} von " . count($beobachtet));

$aussenId = $framework->settingRelationId(SettingKey::Renderer);
$innenId  = $framework->settingValueRelationId(SettingKey::Renderer);

$r = Schema::table('relations_named');
$n = Schema::table('nodes_named');

$traegerIds = array_map(intval(...), $wpdb->get_col($wpdb->prepare(
    'SELECT DISTINCT r.node_id FROM ' . Schema::table('relation_records') . ' v'
        . ' JOIN ' . Schema::table('node_records') . ' r ON r.id = v.node_record_id'
        . " WHERE v.relation_id = %d AND v.value_ref_kind = 'record' ORDER BY r.node_id",
    $renderKante
)));

$knotenNamenVorher = [];

foreach ($traegerIds as $id) {
    $knotenNamenVorher[$id] = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$n} WHERE id = %d", $id));
}

$kantenNamenVorher = [];

foreach ([$aussenId, $innenId] as $id) {
    if ($id === 0) {
        continue;
    }

    $kantenNamenVorher[$id] = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$r} WHERE id = %d", $id));
}

// ⚠️ **Vor der ersten Aenderung angemeldet, nicht danach.** *Ein Absturz zwischen Umbenennen und
// Zurueckbenennen liesse den Schirm kaputt zurueck — und der naechste Lauf hielte den Schaden fuer
// den Zustand. Die Klammer aus `lib/no-write.php` dreht ohnehin alles zurueck; dies ist der Guertel
// zum Hosentraeger.*
register_shutdown_function(static function () use ($knotenNamenVorher, $kantenNamenVorher, $benenne): void {
    foreach ($knotenNamenVorher as $id => $name) {
        $benenne(IdentitySpace::Node, (int) $id, $name);
    }

    foreach ($kantenNamenVorher as $id => $name) {
        $benenne(IdentitySpace::Relation, (int) $id, $name);
    }
});

foreach ($knotenNamenVorher as $id => $name) {
    $benenne(IdentitySpace::Node, (int) $id, 'Etwas ganz anderes');
}

foreach ($kantenNamenVorher as $id => $name) {
    $benenne(IdentitySpace::Relation, (int) $id, 'Etwas ganz anderes');
}

$umbenannt = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$r} WHERE id IN (%d, %d) AND name = 'Etwas ganz anderes'",
    $aussenId,
    $innenId
));

$vorhanden = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$r} WHERE id IN (%d, %d)",
    $aussenId,
    $innenId
));

check(
    "die noch vorhandenen der beiden Kanten heissen jetzt anders ({$vorhanden} von 2 gibt es)",
    $umbenannt === $vorhanden,
    "{$umbenannt} von {$vorhanden}"
);

$nachher = $zeichnetJetzt($beobachtet);

$abweichend = [];

foreach ($beobachtet as $id) {
    if (($nachher[$id] ?? null) !== $vorher[$id]) {
        $abweichend[] = "#{$id}: " . ($nachher[$id] ?? 'nichts') . ' statt ' . $vorher[$id];
    }
}

check(
    'kein Knoten zeichnet nach der Umbenennung anders (' . count($beobachtet) . ' beobachtet)',
    $abweichend === [],
    implode(', ', array_slice($abweichend, 0, 10))
);

foreach ($kantenNamenVorher as $id => $name) {
    $benenne(IdentitySpace::Relation, (int) $id, $name);
}

foreach ($knotenNamenVorher as $id => $name) {
    $benenne(IdentitySpace::Node, (int) $id, $name);
}

$knotenZurueck = 0;

foreach ($knotenNamenVorher as $id => $name) {
    $knotenZurueck += (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$n} WHERE id = %d", $id)) === $name ? 1 : 0;
}

check(
    'die Namen der Traegerknoten stehen wieder da',
    $knotenZurueck === count($knotenNamenVorher),
    "{$knotenZurueck} von " . count($knotenNamenVorher)
);

$kantenZurueck = 0;

foreach ($kantenNamenVorher as $id => $name) {
    $kantenZurueck += (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$r} WHERE id = %d", $id)) === $name ? 1 : 0;
}

check(
    'die Namen der noch vorhandenen Kanten stehen wieder da',
    $kantenZurueck === count($kantenNamenVorher),
    "{$kantenZurueck} von " . count($kantenNamenVorher)
);
echo "\n== ein eigener Knoten, der einen Renderer haben kann ==\n";

$intId = $types->nodeId(SimpleType::Int);

if ($intId === null) {
    echo "  FAIL der Int-Typ ist nicht aufgeschrieben — ohne ihn gibt es keine Renderer zur Wahl\n";

    exit(1);
}

$probe = $editor->createNode('__rcm probe', $intId);

$markup = seite($probe->id);

check(
    'die Zeile `renderer` zeichnet einen Waehler',
    str_contains($markup, 'name="taxmod_value[' . $kante . ']"'),
    'kein Steuerelement mit diesem Namen im Markup'
);

// ⚠️ **Der eigene Renderer-Block ist gefallen** ([D-644](../../docs/NewConcept/90-decision-log.md),
// sein Wort: «Renderer-Box ist uebrigens immer noch da, die muss weg!»). *Zwei Orte fuer eine Sache
// waeren zwei Gelegenheiten, verschieden zu antworten — und der Block war der einzige Ort, an dem die
// Menge aus der Registratur kam. **Jetzt kommt sie dort, wo die Zeile steht.***
check(
    'und der eigene Renderer-Block kommt im Markup nicht mehr vor',
    ! str_contains($markup, 'taxmod_setting[renderer]'),
    'der Block zeichnet noch ein zweites Steuerelement'
);

// ⚠️ **Ohne `form="…"` schickt das Steuerelement lautlos nichts** — genau der Regress, den
// `labels-page-save-check` einmal gefangen hat. *Ein Waehler, der dasteht und nichts abschickt, sieht
// aus wie einer, der gespeichert hat.*
check(
    'er haengt am Seitenformular',
    (bool) preg_match(
        '/<select name="taxmod_value\[' . $kante . '\]" form="taxmod-page-' . $probe->id . '"/',
        $markup
    ),
    'kein form="taxmod-page-' . $probe->id . '" am Waehler'
);

// ⚠️ **Eine Ebene heisst Liste** ([R63](../../docs/NewConcept/30-renderer.md),
// [D-109](../../docs/NewConcept/90-decision-log.md)): *«one level → list, several levels → tree
// view»*, und die Menge aus der Registratur ist flach — **ein Baumdialog waere hier Moebiliar ohne
// Aufgabe**.
//
// ⚠️ *Und ehrlich gesagt: **der zweite Fall der Regel ist nirgends gebaut**. Jede Menge, die dieser
// Bildschirm anbietet, ist heute flach, also hat noch nie etwas einen Baum gebraucht. Steht als
// Befund im Eingang und wird hier nicht erfunden (`PR-4`).*
check(
    'und er ist eine Liste, weil die Menge eine Ebene hat',
    (bool) preg_match('/<select name="taxmod_value\[' . $kante . '\]"/', $markup),
    'die Zeile zeichnet kein `<select>`'
);

$angebot = angebotDerZeile($markup, $kante);

check('er bietet mindestens zwei Renderer an', count($angebot) >= 2, implode(',', $angebot));

// ⚠️ **Die Menge kommt aus der Registratur und nicht aus den Kindern des Kantenziels**
// ([D-603](../../docs/NewConcept/90-decision-log.md)): *`eligibleFor()` verengt auf den Typ — fuer
// `Integer` genau `field`, `spinner`, `slider`.*
check(
    'und nur, was diesen Knoten auch zeichnen kann',
    array_values(array_diff(array_values($angebot), ['field', 'spinner', 'slider'])) === [],
    implode(',', $angebot)
);

echo "\n== die sechs unter dem Zwischenknoten sind wieder zu erreichen ==\n";

// ⚠️ **Der Anlass** (`INF-043`): *`form`, `table`, `compact`, `reference`, `chooser-dialog`,
// `chooser-inline` haengen unter `render with label`. Solange die Menge aus den **Kindern** des
// Kantenziels kam, fielen sie heraus, sobald der Zwischenknoten seine Marke verlor — und nur der
// eigene Block holte sie noch. **Aus der Registratur kommen sie ohne Zwischenknoten.***
//
// ⚠️ *Welche fuenf wo erscheinen, sagt die Registratur selbst: ein Knoten **ohne** eigenen Typ
// bekommt die Behaelter, ein Knotenverweis die Waehler. `reference` ist nicht darunter, und das ist
// keine Luecke dieses Umbaus — **er unterstuetzt nur `Purpose::Display`**, wird also beim Bearbeiten
// nirgends angeboten und wurde es auch vom eigenen Block nie.*
// ⚠️ **Seit dem 2026-09-06 haengt es auch daran, ob es etwas zu waehlen gibt** — *sein Befund an
// `Ampere`: «das kann aber nicht richig sein weil der knoten keine kinder hat». Ein Blatt bekommt
// keine Auswahlliste angeboten, ein Knoten mit Kindern schon. Und `reference` ist jetzt dabei: die
// Frage ist «was kann diesen Knoten zeichnen», nicht «was kann ihn bearbeiten».*
$faelle = [
    'model'     => ['form', 'table', 'compact'],
    'constants' => ['reference'],
];

foreach ($faelle as $ast => $erwartet) {
    $wurzel = $framework->rootOf(Branch::from($ast));
    $unter  = $editor->createNode('__rcm unter ' . $ast, $wurzel->id);
    $namen  = array_values(angebotDerZeile(seite($unter->id), $kante));

    check(
        'unter `' . $ast . '` stehen ' . implode(', ', $erwartet) . ' zur Wahl',
        array_values(array_diff($erwartet, $namen)) === [],
        'angeboten: ' . (implode(',', $namen) ?: '—')
    );
}

// ⚠️ **Die Gegenprobe, und sie ist der eigentliche Beleg:** *derselbe Knoten, sobald er ein Kind hat,
// bekommt die Waehler — die Regel haengt an der Menge und nicht am Ast.*
$mitKind = $editor->createNode('__rcm mit Kind', $framework->rootOf(Branch::from('constants'))->id);
$editor->createNode('__rcm ein Kind', $mitKind->id);

$namenMitKind = array_values(angebotDerZeile(seite($mitKind->id), $kante));

check(
    'mit einem Kind stehen die Waehler wieder zur Wahl',
    array_values(array_diff(['chooser-dialog', 'chooser-inline'], $namenMitKind)) === [],
    'angeboten: ' . (implode(',', $namenMitKind) ?: '—')
);

echo "\n== was die Registratur nicht kennt, ist keine Moeglichkeit ==\n";

// ⚠️ **Die Zusage, die den Weg festhaelt und nicht nur sein Ergebnis.** *Ein Knoten unter `Renderer`,
// den keine Klasse umsetzt, waere nach der Kinderregel eine Wahl — nach
// [D-603](../../docs/NewConcept/90-decision-log.md) ist er keine. **Genau das trennt die beiden
// Quellen**, und genau daran haengt, dass `render with label` von selbst herausfaellt, ohne dass ihn
// jemand loeschen oder verschieben muss.*
$rendererKnoten = $editor->nodeImplementing(\Taxmod\Core\Renderer\FormRenderer::class);

if ($rendererKnoten === null) {
    check('ein Knoten setzt den Form-Renderer um', false, 'keiner gefunden');
} else {
    $eltern = $rendererKnoten->parentId() ?? 0;
    $blind  = $editor->createNode('__rcm ohne registratur', $eltern);
    $unter  = $editor->createNode('__rcm modellknoten', $framework->rootOf(Branch::Model)->id);
    $namen  = angebotDerZeile(seite($unter->id), $kante);

    check(
        'ein Knoten neben den Renderern, den keine Klasse umsetzt, wird nicht angeboten',
        ! isset($namen[$blind->id]),
        'er steht in der Wahl'
    );

    check(
        'und der Zwischenknoten `render with label` steht auch nicht darin',
        ! in_array('render with label', array_values($namen), true),
        implode(',', $namen)
    );
}

echo "\n== die Bruecke Kennung -> Klasse -> Knoten ==\n";

// ⚠️ **Zwei Zusagen, eine Regel** ([D-648](../../docs/NewConcept/90-decision-log.md)): *«jeder
// **waehlbare** Renderer-Knoten traegt seine Klasse — und ein **interner** hat keinen Knoten».*
//
// ⚠️ **Warum sie hier stehen und nicht im Kern:** *die Bruecke ist `nodes.implemented_by`
// ([D-620](../../docs/NewConcept/90-decision-log.md)), also eine Spalte — sie faellt nur an einer
// echten Datenbank auf. **Ohne sie waere der Waehler still leer**: die Registratur wuesste weiter
// Bescheid, und die Liste haette nichts zu speichern.*
$registratur = \Taxmod\Core\Renderer\ShippedRenderers::registry();

$mitKlasse = $nodes->byImplementations($registratur->classesForNodes());

$ohneKnoten = [];

foreach ($registratur->namesForNodes() as $kennung) {
    $klasse = $registratur->classFor($kennung);

    if ($klasse === null || ! isset($mitKlasse[$klasse])) {
        $ohneKnoten[] = $kennung;
    }
}

check(
    'jeder waehlbare Renderer hat einen Knoten, der seine Klasse traegt',
    $ohneKnoten === [],
    'ohne Knoten: ' . implode(',', $ohneKnoten)
);

// ⚠️ **Die Kehrseite** ([D-648](../../docs/NewConcept/90-decision-log.md), sein Wort zum
// Renderer-Waehler: *«bin mir unsicher, wuerde eher nein sagen, ist was Internes»*). *Ein Knoten
// machte ihn **waehlbar** — dann stuende «Renderer-Waehler» in der Renderer-Liste eines Textfeldes,
// und man muesste hinterher mit einer Regel verbieten, was der Knoten erst moeglich gemacht hat.*
$interneKlassen = [];

foreach ($registratur->namesForSurfaces() as $kennung) {
    $klasse = $registratur->classFor($kennung);

    if ($klasse !== null) {
        $interneKlassen[$klasse] = $kennung;
    }
}

$internMitKnoten = [];

foreach ($nodes->byImplementations(array_keys($interneKlassen)) as $klasse => $einer) {
    $internMitKnoten[] = ($interneKlassen[$klasse] ?? $klasse) . ' => ' . $einer->name;
}

check(
    'und kein interner Renderer hat einen — auch der Renderer-Waehler nicht',
    $internMitKnoten === [],
    implode(', ', $internMitKnoten)
);

// ⚠️ *Damit die beiden Zahlen nicht stillschweigend zusammenfallen: **der Waehler ist einer der
// internen** und steht in der Registratur wie die anderen zehn.*
check(
    'der Renderer-Waehler steht in der Registratur, aber nicht in der Wahl',
    in_array(\Taxmod\Core\Renderer\RendererChoiceRenderer::NAME, $registratur->namesForSurfaces(), true)
        && ! in_array(\Taxmod\Core\Renderer\RendererChoiceRenderer::NAME, $registratur->namesForNodes(), true)
);

echo "\n== beschriftet mit der `select`-Rolle, Rueckfall auf den Namen ==\n";

// ⚠️ **Seine Schaerfung** ([D-647](../../docs/NewConcept/90-decision-log.md)): *«vielleicht sogar
// eher select label»* — *in einem Auswahlfeld ist das die Rolle, fuer die es die Rollen gibt.*
//
// ⚠️ **Gemessen am 2026-09-05: 3 `select`-Beschriftungen im ganzen Bestand, 195 Namen — und
// **keine der drei sitzt auf einem Renderer-Knoten**. Der Rueckfall traegt heute also alle 17.**
// *Genau darum vergleicht diese Zusage nicht mit den Namen, sondern mit dem, was die
// Beschriftungsaufloesung fuer die Rolle `select` sagt: sie bleibt gruen, wenn jemand eine setzt,
// und rot, wenn die Rolle wieder aus dem Weg faellt.*
$gezeichnet = beschriftungenDerZeile(seite($probe->id), $kante);

$erwarteteTexte = (new \Taxmod\Core\Service\Labels(
    new WpdbLabelRepository(),
    \Taxmod\WordPress\Admin\SettingsScreen::neutralLocale()
))->forNodes(
    array_values($nodes->byIds(array_keys($gezeichnet))),
    \Taxmod\Core\Model\SeededRole::Select,
    // ⚠️ *Dieselbe Sprache, die der Bildschirm nimmt, wenn niemand eine waehlt — sonst pruefte der
    // Waechter einen anderen Weg als den, den der Benutzer geht.*
    \Taxmod\WordPress\Admin\SettingsScreen::neutralLocale()
);

$abweichend = [];

foreach ($gezeichnet as $id => $text) {
    if (($erwarteteTexte[$id] ?? null) !== $text) {
        $abweichend[] = $id . ': «' . $text . '» statt «' . ($erwarteteTexte[$id] ?? '—') . '»';
    }
}

check(
    'jeder Eintrag zeigt seine `select`-Beschriftung',
    $gezeichnet !== [] && $abweichend === [],
    implode(', ', $abweichend) ?: 'die Liste ist leer'
);

echo "\n== waehlen, speichern, frisch lesen ==\n";

/** Der Renderer, den die Aufloesung nach einem frischen Lesen nennt. */
function gespeicherterRenderer(int $nodeId): string
{
    $nodes = new WpdbNodeRepository();
    $relations = new WpdbRelationRepository();
    $fw    = new SeededFrameworkNodes($nodes, $relations, new WpdbChangelog(new SystemClock()));
    $model = new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $fw);

    $node = $nodes->find($nodeId);

    if ($node === null) {
        return '';
    }

    return (string) (($model->forNode($node)[SettingKey::Renderer->value] ?? null)?->value->text ?? '');
}

$vorher = gespeicherterRenderer($probe->id);

// ⚠️ *Angeboten wird Knoten-Id => Name; gewaehlt wird die Id, gelesen der Name.*
$ids    = array_keys($angebot);
$wahlId = $angebot[$ids[0]] === $vorher ? $ids[1] : $ids[0];
$wahl   = $angebot[$wahlId];

$gewandert = abschicken([
    'do'            => 'put_setting',
    'id'            => (string) $probe->id,
    '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $probe->id),
    // ⚠️ **Ueber die Zeile und nicht mehr ueber einen eigenen Schluessel**
    // ([D-644](../../docs/NewConcept/90-decision-log.md)): *`taxmod_value[<kante>]` ist derselbe Weg,
    // den jede andere Einstellung nimmt.*
    'taxmod_value'  => [(string) $kante => (string) $wahlId],
]);

check('der Akt ist durchgelaufen', $gewandert);

$nachher = gespeicherterRenderer($probe->id);

check(
    'die Wahl steht nach dem Speichern da',
    $nachher === $wahl,
    "gewaehlt {$wahl}, gelesen «{$nachher}», vorher «{$vorher}»"
);

// ⚠️ **Die Einstellungskante ist der Ort, und nicht mehr eine Spalte** (TASK-057,
// [D-642](../../docs/NewConcept/90-decision-log.md)). *Hier stand «sie steht in
// `nodes.settings_record_id`». **Die Zusage ist nicht entschaerft, sie ist umgezogen**: sie prueft
// jetzt dieselbe Sache an der Form, die der Eigentuemer gemeint hat — «ich meinte einfach eine
// Multiplizitaet von 1», am Knoten, an einer gewoehnlichen Kante.*
//
// ⚠️ *Ohne sie waere «gelesen» auch dann gruen, wenn der Wert irgendwo laege, wo ihn niemand
// wiederfindet — genau der Zustand, aus dem TASK-052 entstanden ist.*
check('die Einstellungskante `renderer` ist aufgeschrieben', $kante !== 0, (string) $kante);

$satz = (int) $wpdb->get_var(
    "SELECT v.value_ref
       FROM {$p}relation_records v
       JOIN {$p}node_records r ON r.id = v.node_record_id
      WHERE r.node_id = {$probe->id} AND r.record_type = 'default'
        AND v.relation_id = {$kante} AND v.value_ref_kind = 'record'"
);

check('die Wahl haengt als Datensatz an dieser Kante', $satz !== 0, (string) $satz);

// ⚠️ *Und der Satz **ist** der gewaehlte Renderer — seine `node_id` sagt es
// ([D-583](../../docs/NewConcept/90-decision-log.md)). Eine Zeile, die auf irgendeinen Satz zeigt,
// waere keine Zusage.*
$gewaehlterKnoten = $satz === 0 ? '' : (string) $wpdb->get_var(
    "SELECT z.name FROM {$p}node_records s JOIN {$p}nodes_named z ON z.id = s.node_id WHERE s.id = {$satz}"
);

check('und der Satz ist ein Satz des gewaehlten Renderers', $gewaehlterKnoten === $wahl, "«{$gewaehlterKnoten}» statt «{$wahl}»");

// ⚠️ **Die Spalte ist weg und darf nicht wiederkommen** (Fassung 32). *`dbDelta` legt eine fehlende
// Spalte wieder an; kaeme sie zurueck, haette der Renderer wieder zwei Orte.*
check(
    'und `nodes.settings_record_id` gibt es nicht mehr',
    $wpdb->get_var("SHOW COLUMNS FROM {$p}nodes LIKE 'settings_record_id'") === null
);

echo "\n== und die Maske zeigt danach, was dasteht ==\n";

$markup = seite($probe->id);

check(
    'der Waehler steht auf der Wahl',
    (bool) preg_match('/<option value="' . $wahlId . '" selected/', $markup),
    "«{$wahl}» ist im Markup nicht als gewaehlt markiert"
);

echo "\n== eine zweite Wahl gewinnt gegen die erste ==\n";

$zweiteId = $ids[0] === $wahlId ? ($ids[1] ?? $wahlId) : $ids[0];
$zweite   = $angebot[$zweiteId];

abschicken([
    'do'            => 'put_setting',
    'id'            => (string) $probe->id,
    '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $probe->id),
    'taxmod_value'  => [(string) $kante => (string) $zweiteId],
]);

check(
    'die zweite Wahl steht da',
    gespeicherterRenderer($probe->id) === $zweite,
    'gelesen «' . gespeicherterRenderer($probe->id) . "», gewaehlt {$zweite}"
);

echo "\n== und der gewaehlte Renderer zeichnet auch (TASK-058) ==\n";

// ⚠️ **Die zweite Haelfte, und bis heute prueft sie niemand.** *Gespeichert wird oben geprueft;
// **dass der gespeicherte Renderer auch zeichnet**, stand nirgends. **Dazwischen liegt die
// Aufloesungskette**, und dort ist schon zweimal etwas verlorengegangen: die Umbenennung der
// Traegerkante ([D-543](../../docs/NewConcept/90-decision-log.md), sechs Knoten zeichneten `plain`)
// und der Wegfall des Huellknotens ([D-604](../../docs/NewConcept/90-decision-log.md)). **Beide
// Male blieb die Wahl gespeichert und trotzdem zeichnete etwas anderes.***
//
// ⚠️ *Der Weg geht durch ein **Feld**, nicht durch den Knoten selbst: so zeichnet die Oberflaeche.
// Der Traeger zeigt auf die Probe, die Probe traegt die Wahl — die Kette laeuft von der Kante ueber
// das Ziel zu dessen Vorfahren ([D-079](../../docs/NewConcept/90-decision-log.md)).*

/** Ein frischer Zeichenlauf — nichts aus diesem Prozess wird wiederverwendet. */
function zeichner(): \Taxmod\Core\Service\Rendering
{
    $nodes     = new WpdbNodeRepository();
    $relations = new WpdbRelationRepository();
    $fw        = new SeededFrameworkNodes($nodes, $relations, new WpdbChangelog(new SystemClock()));

    return new \Taxmod\Core\Service\Rendering(
        $nodes,
        $fw,
        \Taxmod\Core\Renderer\ShippedRenderers::registry(),
        new SeededTypeNodes($nodes, $fw),
        new \Taxmod\Core\Service\Labels(
            new WpdbLabelRepository(),
            \Taxmod\WordPress\Admin\SettingsScreen::neutralLocale()
        ),
        null,
        new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $fw),
        $relations
    );
}

$traeger = $editor->createNode('__rcm traeger', $framework->rootOf(Branch::Model)->id);
$feld    = $editor->addField($traeger->id, $probe->id, '__rcm feld');

/**
 * Was beim Zeichnen dieses Feldes herauskommt — Renderername und Markup.
 *
 * @return array{0: string, 1: string}
 */
function gezeichnet(int $relationId): array
{
    $relation = (new WpdbRelationRepository())->byId($relationId);

    if ($relation === null) {
        return ['', ''];
    }

    $felder = zeichner()->fieldsFor([$relation], [], \Taxmod\Core\Renderer\Purpose::Edit, 'taxmod_value');

    if ($felder === []) {
        return ['', ''];
    }

    return [$felder[0]->rendererName, $felder[0]->result->markup];
}

$markups = [];
$fehler  = [];

foreach ($angebot as $id => $name) {
    abschicken([
        'do'            => 'put_setting',
        'id'            => (string) $probe->id,
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $probe->id),
        'taxmod_value'  => [(string) $kante => (string) $id],
    ]);

    [$gezeichneterName, $markup] = gezeichnet($feld->id);

    if ($gezeichneterName !== $name) {
        $fehler[] = "gewaehlt «{$name}», gezeichnet «{$gezeichneterName}»";
    }

    $markups[$name] = $markup;
}

// ⚠️ **«Der gewaehlte», nicht «ein Renderer».** *Jede angebotene Wahl wird gewaehlt, gespeichert,
// frisch aufgeloest und gezeichnet — und im Ergebnis muss ihr eigener Name stehen.*
check(
    'jede Wahl zeichnet danach mit dem gewaehlten Renderer',
    $fehler === [] && $markups !== [],
    implode('; ', $fehler) ?: 'nichts gezeichnet'
);

// ⚠️ **Der Rueckfall ist ein Fehler und kein Boden** ([R14b](../../docs/NewConcept/30-renderer.md)).
// *Genau er kam bei beiden Verlusten heraus: die Wahl stand da und `plain` zeichnete. **Ohne diese
// Zusage waere die obige gruen zu bekommen, indem `plain` selbst mit angeboten wird.***
check(
    'und keine davon faellt auf den Rueckfall zurueck',
    ! in_array(\Taxmod\Core\Renderer\PlainRenderer::NAME, array_keys($markups), true)
        && ! array_filter($markups, static fn (string $m): bool => str_contains($m, 'taxmod-no-renderer')),
    implode(',', array_keys(array_filter($markups, static fn (string $m): bool => str_contains($m, 'taxmod-no-renderer'))))
);

// ⚠️ **Der Name allein waere zu wenig.** *Er koennte richtig aus der Aufloesung kommen und das
// Markup trotzdem von woanders — dann saehen drei Wahlen gleich aus. **Verschiedene Wahlen muessen
// verschieden zeichnen**, sonst zeichnet nicht die Wahl, sondern etwas hinter ihr.*
check(
    'und verschiedene Wahlen zeichnen verschieden',
    count(array_unique(array_values($markups))) === count($markups),
    implode(',', array_keys($markups))
);

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// Der Datensatz: anlegen mit gewaehlter Art, Wert eintippen, speichern, nachsehen, loeschen
// ═══════════════════════════════════════════════════════════════════════════════════════════════

// ⚠️ **Dieser Weg ist bisher von keinem Waechter gegangen worden, und genau dort lag der Fehler vom
// 2026-09-06.** *Der Datensatzblock schickt `taxmod_value[<Satz>][<Kante>]`, der Leser hielt die
// erste Ebene fuer die Kante — **kein Wert kam an**. Anlegen ging, weil das ein anderer Akt ist;
// schreiben nie. Behoben in `7edf1ab` und bis hierher ungewacht.*
//
// ⚠️ **Und die Gegenprobe zum Anlegen** ([D-651](../../docs/NewConcept/90-decision-log.md),
// [D-653](../../docs/NewConcept/90-decision-log.md)): *die **gewaehlte** Art muss ankommen. Der Kern
// nahm sie bisher als Vorgabewert des Parameters — eine Zusage «es entsteht ein Satz» waere gruen
// gewesen, waehrend jede Wahl still zu `user` wurde.*

echo "\n== ein Datensatz: anlegen mit gewaehlter Art, schreiben, nachsehen ==\n";

$satzKnoten = $editor->createNode('__rcm satzknoten', $framework->rootOf(Branch::Model)->id);
$satzFeld   = $editor->addField($satzKnoten->id, $intId, '__rcm zahl');

$vorherSaetze = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records WHERE node_id = {$satzKnoten->id}");

abschicken([
    'do'            => 'add_record',
    'id'            => (string) $satzKnoten->id,
    'record_type'   => \Taxmod\Core\Model\RecordType::Example->value,
    '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $satzKnoten->id),
]);

$satzId = (int) $wpdb->get_var(
    "SELECT id FROM {$p}node_records WHERE node_id = {$satzKnoten->id} ORDER BY id DESC LIMIT 1"
);

check('«New record» legt einen Satz an', $satzId > 0 && $vorherSaetze === 0);

$art = (string) $wpdb->get_var("SELECT record_type FROM {$p}node_records WHERE id = {$satzId}");

// ⚠️ *Nicht `user`, und das ist die ganze Zusage: die Art, die im Formular stand, steht in der Spalte.*
check(
    'und die gewaehlte Art kommt an',
    $art === \Taxmod\Core\Model\RecordType::Example->value,
    "gespeichert «{$art}», gewaehlt «" . \Taxmod\Core\Model\RecordType::Example->value . '»'
);

// ⚠️ **Geschachtelt, so wie der Block es schickt** ({@see \Taxmod\Core\Service\Rendering::recordsAsTable()}).
// *Genau diese Form hat der Leser missverstanden; eine flache Zusage haette den Fehler nicht gesehen.*
abschicken([
    'do'             => 'save_record',
    'id'             => (string) $satzKnoten->id,
    'node_record_id' => (string) $satzId,
    'taxmod_value'   => [(string) $satzId => [(string) $satzFeld->id => '42']],
    '_taxmod_nonce'  => wp_create_nonce('taxmod_node_' . $satzKnoten->id),
]);

$gespeichert = $wpdb->get_var(
    "SELECT value_int FROM {$p}relation_records WHERE node_record_id = {$satzId} AND relation_id = {$satzFeld->id}"
);

check(
    'ein eingetippter Wert steht danach im Satz',
    (string) $gespeichert === '42',
    'gelesen ' . var_export($gespeichert, true)
);

// ⚠️ *Und er steht auch auf der Seite — geschrieben heisst nichts, wenn der Block ihn nicht zeigt.*
check(
    'und die Seite zeigt ihn wieder an',
    str_contains(seite($satzKnoten->id), 'value="42"'),
    'nicht im Markup'
);

echo "\n== und die Art laesst sich an der Zeile umstellen ==\n";

// ⚠️ **Sein Wort:** *«default / user / example muss einstellbar sein.»* *Gewaehlt wurde sie bisher
// nur beim Anlegen ([D-651](../../docs/NewConcept/90-decision-log.md),
// [D-653](../../docs/NewConcept/90-decision-log.md)) — danach stand sie als Spalte da.*
//
// ⚠️ **Und es ist keine Anzeige, sondern eine Wirkung** ([D-654](../../docs/NewConcept/90-decision-log.md)):
// *ein `default` ist eine **Vorbelegung** und greift in jede kuenftige Eingabe ein. Darum geht dieser
// Weg ueber die Maske und nicht ueber den Kern — **die Zeile muss die Auswahl auch zeichnen**, sonst
// gaebe es nichts umzustellen, und genau daran ist die Renderer-Wahl schon einmal gescheitert.*
$seite = seite($satzKnoten->id);

check(
    'die Zeile zeichnet einen Waehler fuer die Art',
    (bool) preg_match(
        '/<select name="record_type" form="taxmod-record-' . $satzId . '"/',
        $seite
    ),
    'kein Waehler mit form="taxmod-record-' . $satzId . '" im Markup'
);

// ⚠️ *Und er steht auf dem, was der Satz **ist** — ein Waehler, der immer die Vorgabe zeigt, meldet
// beim naechsten Speichern eine Aenderung, die niemand gemacht hat.*
$hinterWaehler = preg_split('/name="record_type" form="taxmod-record-' . $satzId . '"/', $seite)[1] ?? '';

check(
    'und er steht auf der Art, die der Satz hat',
    (bool) preg_match(
        '/<option value="' . \Taxmod\Core\Model\RecordType::Example->value . '" selected/',
        explode('</select>', $hinterWaehler)[0]
    ),
    substr(explode('</select>', $hinterWaehler)[0], 0, 120)
);

$versionVorher = (int) $wpdb->get_var("SELECT version FROM {$p}node_records WHERE id = {$satzId}");
$schattenVorDem = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records_history WHERE id = {$satzId}");

abschicken([
    'do'             => 'save_record',
    'id'             => (string) $satzKnoten->id,
    'node_record_id' => (string) $satzId,
    'record_type'    => \Taxmod\Core\Model\RecordType::Default->value,
    'taxmod_value'   => [(string) $satzId => [(string) $satzFeld->id => '42']],
    '_taxmod_nonce'  => wp_create_nonce('taxmod_node_' . $satzKnoten->id),
]);

$art = (string) $wpdb->get_var("SELECT record_type FROM {$p}node_records WHERE id = {$satzId}");

check(
    'nach dem Speichern steht die neue Art da',
    $art === \Taxmod\Core\Model\RecordType::Default->value,
    "gelesen «{$art}»"
);

// ⚠️ *«Wie jede Aenderung» heisst: Version hoch und der Zustand davor im Schatten
// ([D-536](../../docs/NewConcept/90-decision-log.md)). **Ohne das waere die alte Art fort**, und
// ein Rueckgaengig haette nichts, worauf es zurueckginge.*
check(
    'die Version des Satzes ist hochgezaehlt',
    (int) $wpdb->get_var("SELECT version FROM {$p}node_records WHERE id = {$satzId}") > $versionVorher,
    'vorher ' . $versionVorher
);

check(
    'und der Zustand davor liegt im Schatten',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records_history WHERE id = {$satzId}") > $schattenVorDem,
    'vorher ' . $schattenVorDem
);

check(
    'und das Aenderungsbuch kennt das Umstellen',
    (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$p}changelog WHERE owner_id = {$satzId} AND owner_kind = 'record' AND what = 'record retyped'"
    ) > 0
);

// ⚠️ **Die Gegenprobe, und sie ist die wichtigere Haelfte.** *Ein Formular, das keine Art mitschickt
// — ein alter Reiter —, darf **nichts** umstellen. Mit `fromStorage()` waere die fehlende Angabe
// `user` gewesen, und jedes Speichern haette einen `default`-Satz still zu einer Benutzereingabe
// gemacht: nach [D-654](../../docs/NewConcept/90-decision-log.md) der Wegfall einer Vorbelegung.*
$versionNach = (int) $wpdb->get_var("SELECT version FROM {$p}node_records WHERE id = {$satzId}");

abschicken([
    'do'             => 'save_record',
    'id'             => (string) $satzKnoten->id,
    'node_record_id' => (string) $satzId,
    'taxmod_value'   => [(string) $satzId => [(string) $satzFeld->id => '42']],
    '_taxmod_nonce'  => wp_create_nonce('taxmod_node_' . $satzKnoten->id),
]);

check(
    'ohne Angabe bleibt die Art, wie sie ist',
    (string) $wpdb->get_var("SELECT record_type FROM {$p}node_records WHERE id = {$satzId}")
        === \Taxmod\Core\Model\RecordType::Default->value
);

// ⚠️ *Und dasselbe noch einmal zaehlt nicht als Aenderung: gleiche Art heisst kein Akt, keine
// Version, keine Schattenzeile. **Eine Chronik voll unveraenderter Zeilen ist keine Chronik.***
abschicken([
    'do'             => 'save_record',
    'id'             => (string) $satzKnoten->id,
    'node_record_id' => (string) $satzId,
    'record_type'    => \Taxmod\Core\Model\RecordType::Default->value,
    'taxmod_value'   => [(string) $satzId => [(string) $satzFeld->id => '42']],
    '_taxmod_nonce'  => wp_create_nonce('taxmod_node_' . $satzKnoten->id),
]);

check(
    'dieselbe Art noch einmal zaehlt keine Version hoch',
    (int) $wpdb->get_var("SELECT version FROM {$p}node_records WHERE id = {$satzId}") === $versionNach,
    'vorher ' . $versionNach
);

echo "\n== und er laesst sich loeschen, umkehrbar ==\n";

$schattenVorher = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records_history WHERE id = {$satzId}");

abschicken([
    'do'             => 'delete_record',
    'id'             => (string) $satzKnoten->id,
    'node_record_id' => (string) $satzId,
    '_taxmod_nonce'  => wp_create_nonce('taxmod_node_' . $satzKnoten->id),
]);

check(
    'der Satz ist aus der lebenden Tabelle weg',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records WHERE id = {$satzId}") === 0
);

check(
    'und seine Werte mit ihm',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = {$satzId}") === 0
);

// ⚠️ **Das ist die Umkehrbarkeit, und sie ist der Grund, warum das Loeschen ueberhaupt angeboten
// werden darf** ([D-536](../../docs/NewConcept/90-decision-log.md), [D-537](../../docs/NewConcept/90-decision-log.md)):
// *was verschwindet, verschwindet in den Schatten und nicht aus der Welt.*
check(
    'der Satz liegt im Schatten',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records_history WHERE id = {$satzId}") > $schattenVorher
);

check(
    'und seine Werte auch',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records_history WHERE node_record_id = {$satzId}") > 0
);

// ⚠️ *Eine Aenderungsgruppe ([D-348](../../docs/NewConcept/90-decision-log.md)): ein Rueckgaengig,
// das nur die Haelfte zurueckholt, waere keines.*
check(
    'und das Aenderungsbuch kennt den Akt',
    (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$p}changelog WHERE owner_id = {$satzId} AND owner_kind = 'record' AND what = 'record removed'"
    ) > 0
);

echo "\n== die Einstellungen einer Feldzeile, aufklappbar (D-666) ==\n";

// ⚠️ **Der Weg ueber die Maske und nicht durch den Kern** — *genau das hat den Fehler verdeckt, den
// [D-666](../../docs/NewConcept/90-decision-log.md) behebt: `Rendering::settingsFor()` lieferte fuer
// die Kante dreizehn Zeilen, und **die Feldzeile zeichnete keine davon**. Ein Waechter am Kern waere
// gruen geblieben.*
//
// ⚠️ *Gemessen wird an `__rcm feld` — der Kante, die `__rcm traeger` auf `__rcm probe` (ein `int`)
// legt. `min` gilt fuer eine Kante auf einen ganzzahligen Typ, also gibt es dort etwas zu bedienen.*
$feldKante  = $relations->byId($feld->id);
$schluessel = SettingKey::Min->value;
$feldName   = 'taxmod_field_setting[' . $feld->id . '][' . $schluessel . ']';

$zu  = seite($traeger->id);
$auf = seite($traeger->id, (string) $feld->id);

// ⚠️ **Die Zusage, die den Beschluss traegt** (*«Standard ist nicht ausgeklappt — das heisst auch
// nicht gelesen»*): *ein `display:none` haette den Bereich **trotzdem** aufgeloest und mitgeschickt.
// **Fehlt er im Markup, ist er nicht gezeichnet worden** — und jede Einstellungszeile kostet eine
// Kette ueber Kante, Zielknoten und dessen Vorfahren ([D-602](../../docs/NewConcept/90-decision-log.md)).*
check(
    'eine zugeklappte Zeile zeichnet keine ihrer Einstellungen',
    ! str_contains($zu, $feldName) && ! str_contains($zu, 'taxmod-field-settings-row'),
    'der Bereich steht im Markup, obwohl niemand ihn aufgeklappt hat'
);

check(
    'und die aufgeklappte zeichnet sie unter ihrer Zeile',
    str_contains($auf, 'taxmod-field-settings-row') && str_contains($auf, $feldName),
    'kein Bereich oder kein Steuerelement fuer `' . $schluessel . '`'
);

// ⚠️ *Ohne `form="…"` schickt das Steuerelement lautlos nichts ab — derselbe stille Mangel, den
// dieser Lauf beim Renderer-Waehler schon einmal gefangen hat.*
check(
    'und die Steuerelemente haengen am Seitenformular',
    (bool) preg_match(
        '/name="' . preg_quote($feldName, '/') . '"[^>]*form="taxmod-page-' . $traeger->id . '"/',
        $auf
    ),
    'kein form="taxmod-page-' . $traeger->id . '" am Steuerelement'
);

// ⚠️ **Der skriptfreie Weg, ganz** ([D-666](../../docs/NewConcept/90-decision-log.md)): *ein
// gewoehnlicher Knopf im Formular der Zeile, eine Weiterleitung, und die Seite zeichnet die Zeile
// aufgeklappt neu. **Ohne ihn waere das Aufklappen eine Einstellung, die es nur mit Skript gibt.***
check(
    'die Zeile traegt einen Knopf zum Aufklappen',
    str_contains($zu, 'value="toggle_field_settings"'),
    'kein solcher Knopf im Markup'
);

$gewandert = abschicken([
    'do'            => 'toggle_field_settings',
    'id'            => (string) $traeger->id,
    'relation'      => (string) $feld->id,
    '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $traeger->id),
]);

check(
    'und er fuehrt auf dieselbe Seite mit der Zeile offen',
    $gewandert && str_contains(
        urldecode((string) $GLOBALS['taxmod_letzte_adresse']),
        'taxmod_open_rows=' . $feld->id
    ),
    (string) $GLOBALS['taxmod_letzte_adresse']
);

// ⚠️ *Und zurueck: derselbe Knopf schliesst wieder. **Ein Oeffner, der nicht schliesst, laesst den
// Umstand fuer immer in der Adresse stehen.***
$_GET['taxmod_open_rows'] = (string) $feld->id;

abschicken([
    'do'                => 'toggle_field_settings',
    'id'                => (string) $traeger->id,
    'relation'          => (string) $feld->id,
    'taxmod_open_rows'  => (string) $feld->id,
    '_taxmod_nonce'     => wp_create_nonce('taxmod_node_' . $traeger->id),
]);

unset($_GET['taxmod_open_rows']);

check(
    'und derselbe Knopf klappt sie wieder zu',
    ! str_contains(urldecode((string) $GLOBALS['taxmod_letzte_adresse']), 'taxmod_open_rows='),
    (string) $GLOBALS['taxmod_letzte_adresse']
);

// ⚠️ **Der Rueckweg vom Rand in den Kern** ([D-627](../../docs/NewConcept/90-decision-log.md)): *der
// Weg **mit** Skript holt genau diesen Bereich nach. **Er muss derselbe sein wie der auf der Seite**,
// sonst gaebe es zwei Macharten ([D-665](../../docs/NewConcept/90-decision-log.md), `R1`) — und
// welche man saehe, haenge daran, ob ein Skript laeuft.*
$rc        = new ReflectionClass(Plugin::class);
$pluginObj = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($pluginObj, __FILE__);

$nachgeholt = $feldKante === null
    ? ''
    : $pluginObj->screen()->fieldSettingsFragment($editor->find($traeger->id), $feldKante);

check(
    'der nachgeholte Bereich ist derselbe wie der auf der Seite',
    $nachgeholt !== '' && str_contains($auf, $nachgeholt),
    'der Nachschlag zeichnet etwas anderes als die Seite'
);

echo "\n== eine Einstellung setzen, absenden, frisch nachlesen ==\n";

$gewandert = abschicken([
    'do'                   => 'put_setting',
    'id'                   => (string) $traeger->id,
    '_taxmod_nonce'        => wp_create_nonce('taxmod_node_' . $traeger->id),
    'taxmod_field_setting' => [(string) $feld->id => [$schluessel => '7']],
]);

check('der Akt ist durchgelaufen', $gewandert);

/** Was die Aufloesung an **dieser Kante** sagt, frisch gelesen. */
function anDerKante(int $relationId, string $key): string
{
    $nodes     = new WpdbNodeRepository();
    $relations = new WpdbRelationRepository();
    $fw        = new SeededFrameworkNodes($nodes, $relations, new WpdbChangelog(new SystemClock()));
    $model     = new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $fw);

    $relation = $relations->byId($relationId);

    if ($relation === null) {
        return '';
    }

    $angabe = $model->forUseSite($relation)[$key] ?? null;

    // ⚠️ *`setHere` ist die halbe Zusage und die wichtigere: **an der Kante** und nicht aus der Kette
    // des Zielknotens ([D-611](../../docs/NewConcept/90-decision-log.md)). Ohne sie waere der Lauf
    // auch dann gruen, wenn die Angabe am Typ gelandet waere — und dann gaelte sie fuer jedes Feld,
    // das auf ihn zeigt.*
    //
    // ⚠️ *`min` ist eine **Zahl**, also steht sie in `int` und nicht in `text` — ein `->text` hier
    // las `null` und meldete «nichts gespeichert», obwohl die Zeile den Wert anzeigte.*
    if ($angabe === null || ! $angabe->setHere) {
        return '';
    }

    return $angabe->value->int === null ? (string) $angabe->value->text : (string) $angabe->value->int;
}

// ⚠️ **An der **Kante** und nicht am Zielknoten** ([D-611](../../docs/NewConcept/90-decision-log.md)):
// *eine Angabe, die am Typ landet, gilt fuer jedes Feld, das auf ihn zeigt — genau das soll die
// Verwendungsstelle ueberschreiben koennen und nicht ueberschreiben lassen.*
check(
    'der Wert steht an der Kante, nicht am Zielknoten',
    anDerKante($feld->id, $schluessel) === '7',
    'gelesen «' . anDerKante($feld->id, $schluessel) . '» statt «7»'
);

check(
    'und die aufgeklappte Zeile zeigt ihn wieder',
    (bool) preg_match(
        '/name="' . preg_quote($feldName, '/') . '"[^>]*value="7"/',
        seite($traeger->id, (string) $feld->id)
    ),
    'der Wert steht nicht im Steuerelement'
);

echo "\n== die Tafel bietet nur an, was die Kette erklaert (D-529, D-668) ==\n";

// ⚠️ **Sein Befund am 2026-09-06:** *«es scheinen einfach alle Einstellungen zu sein, nicht nur die
// vom Typ Text».* *Gemessen stimmte das: die Tafel ging eine **Aufzaehlung im Kode** durch und nahm
// jeden Schluessel, fuer den sich ein Steuerelement zeichnen laesst. Behoben in `688798e`, verschaerft
// durch [D-668](../../docs/NewConcept/90-decision-log.md) in `b32b491`.*
//
// ⚠️ **Und gemessen wird am Markup der aufgeklappten Zeile, nicht an `ModelValues`.** *Die vorhandene
// Zusage in `setting-relation-check` fragt den Kern — sie waere gruen geblieben, solange die Tafel
// ihre Schluessel woanders herholt. **Genau dieser Unterschied hat den Fehler verdeckt.***
preg_match_all(
    '/name="taxmod_field_setting\[' . $feld->id . '\]\[([a-z_]+)\]"/',
    $auf,
    $tafelTreffer
);

$angeboteneSchluessel = array_values(array_unique($tafelTreffer[1]));

// ⚠️ *«Erklaert» heisst: eine Einstellungs**kante** an einem Knoten der Kette des **Ziels**
// ([D-529](../../docs/NewConcept/90-decision-log.md)). Die Kette wird aus der Datenbank gelaufen und
// nicht bei einem Dienst erfragt — sonst pruefte der Waechter denselben Kode zweimal.*
$kette = [];
$lauf  = $probe->id;

while ($lauf !== 0) {
    $kette[] = $lauf;
    $lauf    = $nodes->find($lauf)?->parentId() ?? 0;
}

$erklaert = $wpdb->get_col(
    "SELECT DISTINCT name FROM {$p}relations_named
      WHERE kind = 'setting' AND name <> '' AND from_node_id IN (" . implode(',', $kette) . ')'
) ?: [];

// ⚠️ **Eine Ausnahme, und sie steht mit Grund da:** *`multiplicity` ist **eine Spalte an der Kante**
// ([D-351](../../docs/NewConcept/90-decision-log.md): *«one key with four constants»*) und keine
// Einstellungskante. Sie kann an keiner Kette erklaert sein — und sie gehoert trotzdem in die Tafel,
// weil sie zur Verwendungsstelle gehoert und nur dort zu aendern ist.*
$erklaert[] = SettingKey::Multiplicity->value;

$ueberzaehlig = array_values(array_diff($angeboteneSchluessel, $erklaert));

// ⚠️ *Der Gegenfall zuerst: **eine leere Tafel waere sonst gruen** — und «bietet nur Erklaertes an»
// ist am billigsten dadurch erfuellt, dass gar nichts angeboten wird.*
check(
    'die Tafel bietet ueberhaupt Schluessel an',
    count($angeboteneSchluessel) >= 3,
    implode(',', $angeboteneSchluessel) ?: '—'
);

check(
    'und jeder von ihnen ist an der Kette des Ziels erklaert',
    $ueberzaehlig === [],
    'nicht erklaert: ' . implode(',', $ueberzaehlig) . ' — erklaert: ' . implode(',', $erklaert)
);

// ⚠️ **Der benannte Gegenfall, und er ist der Fall, den er gemeldet hat.** *`display_size` ist ein
// zeichenbarer Schluessel ([D-659](../../docs/NewConcept/90-decision-log.md)) und steht **nicht** an
// der Kette dieses Ziels. Die alte Tafel bot ihn trotzdem an, weil sie fragte, was sich zeichnen
// laesst. **Ohne diese Zeile bliebe die Zusage oben abstrakt** — sie faellt weg, sobald `display_size`
// hier wirklich erklaert wird, und dann ist sie zu ersetzen und nicht zu entschaerfen.*
check(
    'und ein zeichenbarer, aber nicht erklaerter Schluessel fehlt in der Tafel',
    in_array(SettingKey::DisplaySize->value, $erklaert, true)
        || ! in_array(SettingKey::DisplaySize->value, $angeboteneSchluessel, true),
    'die Tafel bietet `' . SettingKey::DisplaySize->value . '` an, ohne dass die Kette ihn erklaert'
);

echo "\n== die Wurzel ist der letzte Halt, nicht die Regel fuer alles (D-617) ==\n";

// ⚠️ **[D-617](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«Am Modellwurzel sollte der
// Rueckfallknoten haengen eigentlich, ne. Und die anderen Knoten bestimmen selbst, was ihr Default
// Renderer ist.»* **Ein Wert an der Wurzel ist gewollt** ([D-616](../../docs/NewConcept/90-decision-log.md)),
// *er hat nur den **letzten** Rang.*
//
// ⚠️ **Der Fall, den es wirklich gab** ([D-616](../../docs/NewConcept/90-decision-log.md)): *der
// `default`-Satz der Wurzel trug `render = checkbox`, **womit jedes Feld des Modells als Ankreuzfeld
// gezeichnet worden waere**. Kein Journaleintrag, keine Schattenzeile — nie ueber die Oberflaeche
// geschrieben, und kein Waechter sah es. Gefunden hat es der Eigentuemer.*
//
// ⚠️ *Der Waechter setzt den Wert selbst, ueber die Maske, und die Klammer aus `lib/no-write.php`
// dreht ihn zurueck. **Nur so ist die Zusage nicht davon abhaengig, was heute zufaellig an seiner
// Wurzel steht.***
$wurzelId = $kette === [] ? 0 : (int) end($kette);

check('die Kette endet an einer Wurzel ohne Elternknoten', $wurzelId !== 0, (string) $wurzelId);

$wurzelWahlId = $ids[0];
$wurzelWahl   = $angebot[$wurzelWahlId];
$eigenId      = $ids[1] ?? $ids[0];
$eigen        = $angebot[$eigenId];

check('die Wahl der Wurzel und die eigene sind zwei verschiedene', $wurzelWahlId !== $eigenId);

abschicken([
    'do'            => 'put_setting',
    'id'            => (string) $wurzelId,
    '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $wurzelId),
    'taxmod_value'  => [(string) $kante => (string) $wurzelWahlId],
]);

// ⚠️ **Die Gegenprobe zuerst, und ohne sie ist der Rest wertlos:** *ein Knoten, der **nichts** sagt,
// bekommt die Wahl der Wurzel. Waere das nicht so, hiesse «die Wurzel zwingt nichts auf» bloss, dass
// der Wert nirgends angekommen ist — und die Zusage waere gruen, weil das Setzen misslang.*
//
// ⚠️ *Er haengt **unmittelbar** an der Wurzel, und das ist nicht willkuerlich: unter `Integer` steht
// der Waechter gemessen bei `slider` — **weil `Integer` selbst eine Wahl traegt und naeher ist**
// ([D-602](../../docs/NewConcept/90-decision-log.md)). Ein Zwischenhalt in der Kette macht die
// Gegenprobe zu einer Aussage ueber ihn statt ueber die Wurzel.*
$stumm = $editor->createNode('__rcm stumm', $wurzelId);

check(
    'ein Knoten ohne eigene Aussage bekommt die Wahl der Wurzel',
    gespeicherterRenderer($stumm->id) === $wurzelWahl,
    'gelesen «' . gespeicherterRenderer($stumm->id) . "», an der Wurzel «{$wurzelWahl}»"
);

abschicken([
    'do'            => 'put_setting',
    'id'            => (string) $probe->id,
    '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $probe->id),
    'taxmod_value'  => [(string) $kante => (string) $eigenId],
]);

// ⚠️ **Und jetzt die Zusage:** *derselbe Knoten mit eigener Aussage behaelt sie. **Naeher schlaegt
// ferner** ([D-602](../../docs/NewConcept/90-decision-log.md)), und die Wurzel ist der fernste Punkt.*
check(
    'ein Knoten mit eigener Aussage behaelt sie gegen die Wurzel',
    gespeicherterRenderer($probe->id) === $eigen,
    'gelesen «' . gespeicherterRenderer($probe->id) . "», eigen «{$eigen}», Wurzel «{$wurzelWahl}»"
);

// ⚠️ *Am Markup, nicht an der Aufloesung: **der Waehler steht auf der eigenen Wahl** und nicht auf
// der der Wurzel. Ein Schirm, der die geerbte Wahl vormarkiert, meldet beim naechsten Speichern eine
// Aenderung, die niemand gemacht hat.*
$markup = seite($probe->id);

check(
    'und die Maske markiert die eigene Wahl, nicht die der Wurzel',
    (bool) preg_match('/<option value="' . $eigenId . '" selected/', $markup)
        && ! preg_match('/<option value="' . $wurzelWahlId . '" selected/', $markup),
    "«{$eigen}» sollte markiert sein, «{$wurzelWahl}» nicht"
);

// ⚠️ **Und gezeichnet wird auch mit der eigenen.** *Gespeichert und gezeichnet sind zwei Dinge — die
// Aufloesungskette dazwischen hat schon zweimal etwas verloren
// ([D-543](../../docs/NewConcept/90-decision-log.md), [D-604](../../docs/NewConcept/90-decision-log.md)).*
check(
    'und gezeichnet wird der Knoten mit seiner eigenen Wahl',
    gezeichnet($feld->id)[0] === $eigen,
    'gezeichnet «' . gezeichnet($feld->id)[0] . "», eigen «{$eigen}»"
);

echo "\n== aufraeumen ==\n";

// ⚠️ *Nach dem eigenen Namensmuster und nie ueber `clearTrash()` — dort liegt seine geparkte Arbeit
// (TASK-039). Nach Namen und nicht nur nach den Ids dieses Laufs: ein abgestuerzter Lauf laesst sonst
// Reste stehen, die der naechste als eigenen Fehlschlag meldet.*
// ⚠️ *Beide Muster, weil die Gegenprobe zur Fassung 38 sich ihre eigene Wiese unter `__mg ` baut —
// sie raeumt selbst auf, und **hier steht der zweite Griff fuer den Fall, dass sie es nicht mehr
// konnte**.*
$meine = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}nodes_named WHERE name LIKE '\\_\\_rcm %' OR name LIKE '\\_\\_mg %'"));
$in    = $meine === [] ? (string) $probe->id : implode(',', $meine);

$wpdb->query("DELETE FROM {$p}relation_records WHERE node_record_id IN (SELECT id FROM {$p}node_records WHERE node_id IN ({$in}))");
$wpdb->query("DELETE FROM {$p}node_records WHERE node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}relations WHERE from_node_id IN ({$in}) OR to_node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}nodes WHERE id IN ({$in})");

check(
    'der Waechter laesst nichts zurueck',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes_named WHERE name LIKE '\\_\\_rcm %' OR name LIKE '\\_\\_mg %'") === 0
);


printf("\n%d ok, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
