<?php declare(strict_types=1);

/**
 * Die Labels gehen mit dem Seitenspeichern mit.
 *
 * ⚠️ **Der Eigentümer, 2026-08-28:** *«Labels sollten auch mit der Seite gespeichert werden.»* Bis
 * dahin hatte der Labels-Bereich einen **eigenen** Speicherknopf und ein eigenes `<form>`; die Texte
 * kamen nur mit, wenn jemand genau diesen Knopf drückte.
 *
 * ⚠️ **Gegen die echte Datenbank, weil die Naht HTML ist und kein Kernverhalten.** *`form="…"` bindet
 * ein Feld an ein Formular, das woanders auf der Seite steht — ob das **eine** Formular dort auch
 * wirklich gezeichnet wird, weiss nur die fertige Seite. Ein Feld, das ein Formular nennt, das es
 * nicht gibt, sendet nichts und sagt nichts darüber.*
 *
 * ⚠️ **Und der POST wird wirklich abgeschickt, in einem Kindprozess.** *`handlePost()` endet in
 * `wp_safe_redirect()` und `exit` — deshalb sagt `cleanup-screen-check.php` zurecht, es liesse sich
 * nicht aufrufen. In einem eigenen Prozess lässt es sich: **damit ist der `match`-Zweig mitgeprüft**,
 * und nicht nur die Methode, die er aufruft.*
 *
 * ⚠️ **Gemessen wird «keine Schreiboperation» und nicht «kein Changelog-Eintrag».** *Weil ein Label
 * **überhaupt** keine Changelog-Zeile schreibt — gemessen: 0 von 10247 Zeilen sprechen von einem
 * Label. Eine Zusage «ein unverändertes Label schreibt kein Changelog» wäre grün, ohne irgendetwas zu
 * zeigen. Also zählt der Kindprozess mit `SAVEQUERIES` die **Schreibabfragen auf die Labels-Tabelle**:
 * einmal für den geänderten Text, null für den unveränderten.*
 *
 * Usage: php scripts/dev/labels-page-save-check.php
 *
 * @see docs/NewConcept/40-i18n.md
 * @see docs/NewConcept/20-interaction.md
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

// ⚠️ *Vor `wp-load.php`, sonst sammelt `$wpdb` die Abfragen nicht — und die Zählung der
// Schreibvorgänge ist die eine Messung, die dieser Prüfung ihren Wert gibt.*
define('SAVEQUERIES', true);

require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Renderer\SettingsRenderer;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

$plugin = static function (): object {
    $r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
    $plugin = $r->newInstanceWithoutConstructor();
    $r->getProperty('file')->setValue($plugin, 'C:/Devel/Wordpress/source/wp-taxonomy-tree/wp-taxonomy-modeler.php');

    return $plugin;
};

// ── Der Kindprozess: ein echter POST, und er zählt seine eigenen Schreibvorgänge ──
if (($argv[1] ?? '') === 'post') {
    $payload = json_decode((string) file_get_contents((string) $argv[2]), true);

    if (! is_array($payload)) {
        fwrite(STDERR, "Kindprozess ohne Nutzlast\n");

        exit(2);
    }

    $_POST = $payload;
    // `check_admin_referer()` liest die Nonce aus `$_REQUEST`.
    $_REQUEST = $_POST;

    $gemeldet = ['redirect' => '', 'writes' => 0, 'sql' => []];

    // ⚠️ *Der Filter ist der Beweis, dass der Akt **durchgelaufen** ist. Ohne ihn wäre ein an der
    // Nonce gestorbener Lauf von einem erfolgreichen nicht zu unterscheiden — beide melden nachher
    // null Schreibvorgänge, und «null» ist genau die Zusage weiter unten.*
    add_filter('wp_redirect', static function (string $location) use (&$gemeldet): string {
        $gemeldet['redirect'] = $location;

        return $location;
    });

    register_shutdown_function(static function () use (&$gemeldet, $p): void {
        global $wpdb;

        foreach ((array) ($wpdb->queries ?? []) as $eine) {
            $sql = (string) ($eine[0] ?? '');

            if (preg_match('/^\s*(REPLACE|INSERT|UPDATE|DELETE)\b/i', $sql) && str_contains($sql, $p . 'labels')) {
                ++$gemeldet['writes'];
                $gemeldet['sql'][] = preg_replace('/\s+/', ' ', substr($sql, 0, 120));
            }
        }

        echo "\nGEMESSEN:" . json_encode($gemeldet) . "\n";
    });

    $plugin()->screen()->handlePost();

    exit(0);
}

// ── Der Elternprozess ─────────────────────────────────────────────────────
$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

$roh = static function (string $sql) use ($wpdb): void {
    $wpdb->query($sql);

    if ($wpdb->last_error !== '') {
        fwrite(STDERR, "Abfrage kaputt: {$wpdb->last_error}\n  ({$sql})\n");

        exit(2);
    }
};

$dieses  = $plugin();
$editor  = $dieses->editor();
$fw      = (new ReflectionMethod($dieses, 'frameworkNodes'))->invoke($dieses);
$labels  = new WpdbLabelRepository();
$aktion  = (new ReflectionClass(NodesScreen::class))->getConstant('ACTION');

if (! is_string($aktion) || $aktion === '') {
    fwrite(STDERR, "NodesScreen::ACTION nicht lesbar\n");

    exit(2);
}

$knoten = $editor->createNode('__lb Knoten', $fw->rootOf(Branch::Model)->id);
$rolle  = $fw->roleId(SeededRole::Form);

// ⚠️ *Ein Text, der schon dasteht — sonst wäre «unverändert» nicht prüfbar: ein leeres Feld gegen
// eine leere Zeile ist derselbe Vergleich und beweist nichts.*
$labels->put(new Label($knoten->id, '', $rolle, Label::BASE_NUMBER, '', '__lb Stückliste'));

// ⚠️ **Das Formular der Seite, nicht das eines Blocks darin** ([D-517](../../docs/NewConcept/90-decision-log.md)).
// *Bis zum 2026-08-29 stand hier `SettingsRenderer::formFor()`, und als der Einstellungsblock ging,
// zeigten die Labelfelder auf ein Formular, das es nicht mehr gab. **Diese Prüfung hat es gefangen** —
// die einzige, die es konnte, weil sie das Markup liest statt den Code.*
$form = 'taxmod-page-' . $knoten->id;

// ⚠️ *Der Kindprozess bekommt seine Nutzlast über eine Datei und nicht über die Kommandozeile —
// verschachtelte Felder als JSON durch eine Windows-Shell zu bringen ist eine Quelle von Fehlern,
// die wie ein Testergebnis aussehen.*
$nutzlast = static function (array $post) use ($aktion): string {
    $post['action']        = $aktion;
    $post['_taxmod_nonce'] = wp_create_nonce($aktion . '_' . $post['id']);

    $datei = sys_get_temp_dir() . '/taxmod-labels-check-' . getmypid() . '.json';

    file_put_contents($datei, (string) json_encode($post));

    return $datei;
};

$abschicken = static function (array $post) use ($nutzlast): array {
    $datei = $nutzlast($post);

    $befehl = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' post ' . escapeshellarg($datei);

    $ausgabe = (string) shell_exec($befehl . ' 2>&1');

    unlink($datei);

    if (! preg_match('/GEMESSEN:(\{.*\})/', $ausgabe, $treffer)) {
        fwrite(STDERR, "Kindprozess hat nichts gemeldet:\n" . $ausgabe . "\n");

        exit(2);
    }

    return (array) json_decode($treffer[1], true);
};

echo "\n== 1. die Seite verdrahtet die Texte in ihr eigenes Formular ==\n";

$_GET['page']        = 'taxmod';
$_GET['taxmod_node'] = (string) $knoten->id;

$markup = $dieses->screen()->render();

// ⚠️ **Erst: das genannte Formular muss es geben.** *`form="…"` findet das Element mit dieser Id —
// gibt es keins, sendet das Feld nichts, und zwar lautlos. Der Settings-Bereich zeichnet sein
// `<form>` nur, wenn er überhaupt Zeilen hat.*
$say(str_contains($markup, 'id="' . $form . '"'), 'das Formular ' . $form . ' steht auf der Seite');

preg_match_all('/<(?:input|textarea)[^>]*name="taxmod_label\[([a-z]+)\]"[^>]*>/', $markup, $felder, PREG_SET_ORDER);

printf("       %d Textfelder gefunden: %s\n", count($felder), implode(', ', array_column($felder, 1)));

$say(count($felder) >= 5, 'jede Rolle hat ihr Feld');

$fremd = array_values(array_filter(
    $felder,
    static fn (array $f): bool => ! str_contains($f[0], 'form="' . $form . '"')
));

$say($fremd === [], sprintf('jedes Textfeld nennt das Seitenformular (%d ohne)', count($fremd)));

// ⚠️ *Die Locale muss mitfahren, und zwar **in demselben** Formular: sie wird per `GET` gewählt und
// steht in der URL, die ein POST auf `admin-post.php` nie sieht.*
$say(
    (bool) preg_match('/<input[^>]*name="label_locale"[^>]*form="' . preg_quote($form, '/') . '"/', $markup)
        || (bool) preg_match('/<input[^>]*form="' . preg_quote($form, '/') . '"[^>]*name="label_locale"/', $markup),
    'die Locale fährt als verstecktes Feld in demselben Formular mit'
);

// ⚠️ *Und der Knopf im Seitenkopf ist der, der dieses Formular abschickt (D-392).*
$say(
    (bool) preg_match('/<button[^>]*form="' . preg_quote($form, '/') . '"[^>]*value="put_setting"/', $markup),
    'der Speicherknopf im Seitenkopf schickt genau dieses Formular ab'
);

echo "\n== 2. ein Seitenspeichern schreibt den Text — und der Akt bleibt eine Änderung ==\n";

$marke = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$p}changelog");

if ($wpdb->last_error !== '') {
    fwrite(STDERR, "Abfrage kaputt: {$wpdb->last_error}\n");

    exit(2);
}

// ⚠️ *Der POST trägt **das, was die Seite trägt**: den Namen, ein Setting und alle fünf Texte. Der
// Name wird geändert, damit der Akt überhaupt eine Changelog-Zeile hat — ein Label schreibt keine.*
$seite = [
    'do'           => 'put_setting',
    'id'           => (string) $knoten->id,
    'edge'         => '0',
    'name'         => '__lb Knoten neu',
    'label_locale' => '',
    'taxmod_setting' => ['read_only' => '1'],
    'taxmod_label' => [
        'form'   => '__lb Formname',
        'table'  => '',
        'select' => '',
        'symbol' => '',
        'help'   => '',
    ],
];

$erster = $abschicken($seite);

printf("       Kindprozess: %d Schreibabfrage(n) auf %slabels\n", (int) $erster['writes'], $p);

$say($erster['redirect'] !== '', 'der Akt lief durch (Weiterleitung: ' . (string) $erster['redirect'] . ')');

// ⚠️ *Die Nachricht wird mitgeprüft, weil eine Verweigerung ebenfalls weiterleitet: `saveSettings()`
// läuft **vor** den Texten, und eine abgelehnte Einstellung würde den Akt abbrechen, bevor ein Label
// geschrieben ist. Dann wäre «keine Schreiboperation» grün aus dem falschen Grund.*
$say(
    str_contains(rawurldecode((string) $erster['redirect']), 'taxmod_message=ok'),
    'und er meldet «ok» statt einer Verweigerung'
);

$gelesen = array_values(array_filter(
    $labels->forOwners([$knoten->id]),
    static fn (Label $l): bool => $l->roleId === $rolle && $l->locale === '' && $l->path === ''
));

$say(count($gelesen) === 1 && $gelesen[0]->text === '__lb Formname', 'der Text steht in der Tabelle: ' . ($gelesen[0]->text ?? '—'));
$say((int) $erster['writes'] === 1, sprintf('genau eine Schreibabfrage, für das eine geänderte Feld (%d)', (int) $erster['writes']));

$zeilen = $wpdb->get_results($wpdb->prepare("SELECT change_group_id, owner_kind, what FROM {$p}changelog WHERE id > %d", $marke));

if ($wpdb->last_error !== '') {
    fwrite(STDERR, "Abfrage kaputt: {$wpdb->last_error}\n");

    exit(2);
}

$gruppen = array_unique(array_map(static fn (object $z): int => (int) $z->change_group_id, $zeilen));

printf("       %d Changelog-Zeile(n): %s\n", count($zeilen), implode(', ', array_map(static fn (object $z): string => $z->what, $zeilen)));

$say(count($zeilen) >= 1, 'der Akt hat geschrieben, was zu protokollieren war');
$say(count($gruppen) === 1, sprintf('ein POST, EINE Änderungsnummer (gefunden: %d)', count($gruppen)));
$say(! in_array(0, $gruppen, true), 'und nicht Gruppe null');

echo "\n== 3. dasselbe noch einmal: ein unveränderter Text schreibt nicht ==\n";

$marke2  = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$p}changelog");
$zweite  = $seite;
// ⚠️ *Genau die Seite, wie sie jetzt dasteht — Name und Text unverändert. Das ist der Fall, den ein
// Seitenspeichern ständig erzeugt: fünf Textfelder senden immer, ob jemand getippt hat oder nicht.*
$zweite['name']                 = '__lb Knoten neu';
$zweite['taxmod_label']['form'] = '__lb Formname';

$zweiter = $abschicken($zweite);

printf("       Kindprozess: %d Schreibabfrage(n) auf %slabels\n", (int) $zweiter['writes'], $p);

$say($zweiter['redirect'] !== '', 'der zweite Akt lief auch durch');
$say((int) $zweiter['writes'] === 0, sprintf('keine Schreiboperation für ein Label, das gleich geblieben ist (%d)', (int) $zweiter['writes']));

$neu = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}changelog WHERE id > {$marke2}");

$say($neu === 0, sprintf('und keine Changelog-Zeile (%d)', $neu));

// ⚠️ **Die Gegenprobe zur Messung selbst.** *Erst eine, dann null — damit ist gezeigt, dass die
// Zählung einen Schreibvorgang überhaupt **sehen** kann. Eine Null allein wäre auch grün, wenn
// `SAVEQUERIES` nicht griffe.*
$say((int) $erster['writes'] > (int) $zweiter['writes'], 'die Zählung sieht Schreibvorgänge, wenn es welche gibt');

echo "\n== 4. ein geleertes Feld vergisst die Zeile, statt eine leere zu hinterlassen (D-384) ==\n";

$dritte                         = $seite;
$dritte['name']                 = '__lb Knoten neu';
$dritte['taxmod_label']['form'] = '';

$dritter = $abschicken($dritte);

$danach = array_values(array_filter(
    $labels->forOwners([$knoten->id]),
    static fn (Label $l): bool => $l->roleId === $rolle && $l->locale === '' && $l->path === ''
));

$say($dritter['redirect'] !== '', 'der dritte Akt lief durch');
$say($danach === [], sprintf('die Zeile ist weg statt leer (%d Zeile(n) übrig)', count($danach)));
$say((int) $dritter['writes'] === 1, sprintf('und das war eine Schreiboperation, kein stilles Nichts (%d)', (int) $dritter['writes']));

echo "\n== 5. der eigene Knopf des Labels-Bereichs speichert dieselbe Seite ==\n";

// ⚠️ **Der zweite Knopf ist der Grund, dass `put_labels` und `put_setting` denselben Akt nennen.**
// *Er steht im Labels-Bereich, schickt aber dasselbe Formular ab — ein Akt, der nur die Texte
// schriebe, würde die Einstellungen daneben lesen und wegwerfen. Und dass beide Knöpfe **etwas** tun,
// ist genau die Zusage, die niemand prüft, weil man sie sieht statt sie zu messen.*
$vierte                         = $seite;
$vierte['do']                   = 'put_setting';
$vierte['name']                 = '__lb Knoten neu';
$vierte['taxmod_label']['form'] = '__lb Über den eigenen Knopf';

$vierter = $abschicken($vierte);

$ueber = array_values(array_filter(
    $labels->forOwners([$knoten->id]),
    static fn (Label $l): bool => $l->roleId === $rolle && $l->locale === '' && $l->path === ''
));

$say($vierter['redirect'] !== '', 'der Akt des eigenen Knopfes lief durch');
$say(
    str_contains(rawurldecode((string) $vierter['redirect']), 'taxmod_message=ok'),
    'und er ist kein «Unknown action»'
);
$say(count($ueber) === 1 && $ueber[0]->text === '__lb Über den eigenen Knopf', 'der Text steht: ' . ($ueber[0]->text ?? '—'));

// aufraeumen
$e   = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}relations WHERE from_id = {$knoten->id} OR to_id = {$knoten->id}"));
$own = $e === [] ? (string) $knoten->id : $knoten->id . ',' . implode(',', $e);

$roh("DELETE FROM {$p}settings WHERE owner_id IN ({$own})");
$roh("DELETE FROM {$p}labels WHERE owner_id IN ({$own})");
$roh("DELETE FROM {$p}changelog WHERE owner_id IN ({$own})");

if ($e !== []) {
    $roh('DELETE FROM ' . $p . 'relations WHERE id IN (' . implode(',', $e) . ')');
}

$roh("DELETE FROM {$p}nodes WHERE id = {$knoten->id}");

echo "\n";

$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE name LIKE '__lb %'") === 0, 'die Wiese ist wieder weg');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
