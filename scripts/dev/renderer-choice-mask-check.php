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
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Renderer\FormRenderer;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
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

/** Die Seite als Markup, so wie ein Browser sie bekommt. */
function seite(int $nodeId): string
{
    $_GET['page']        = 'taxmod-nodes';
    $_GET['taxmod_node'] = (string) $nodeId;

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    return $plugin->screen()->render();
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

    $fang = static function () use (&$gewandert): string {
        $gewandert = true;

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
$faelle = [
    'model'     => ['form', 'table', 'compact'],
    'constants' => ['chooser-dialog', 'chooser-inline'],
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

echo "\n== aufraeumen ==\n";

// ⚠️ *Nach dem eigenen Namensmuster und nie ueber `clearTrash()` — dort liegt seine geparkte Arbeit
// (TASK-039). Nach Namen und nicht nur nach den Ids dieses Laufs: ein abgestuerzter Lauf laesst sonst
// Reste stehen, die der naechste als eigenen Fehlschlag meldet.*
$meine = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}nodes_named WHERE name LIKE '\\_\\_rcm %'"));
$in    = $meine === [] ? (string) $probe->id : implode(',', $meine);

$wpdb->query("DELETE FROM {$p}relation_records WHERE node_record_id IN (SELECT id FROM {$p}node_records WHERE node_id IN ({$in}))");
$wpdb->query("DELETE FROM {$p}node_records WHERE node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}relations WHERE from_node_id IN ({$in}) OR to_node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}nodes WHERE id IN ({$in})");

check(
    'der Waechter laesst nichts zurueck',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes_named WHERE name LIKE '\\_\\_rcm %'") === 0
);

printf("\n%d ok, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
