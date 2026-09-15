<?php declare(strict_types=1);

/**
 * Der Baum startet eingeklappt — und «alles offen» bleibt davon unterscheidbar.
 *
 * ⚠️ **Der Eigentümer, 2026-08-28:** *«wenn ich die Seite neu aufmach, dann sollte Kolleps sein — das
 * bitte die beste Übersicht.»*
 *
 * ⚠️ **Gegen die echte Seite und nicht nur gegen den Kern, weil die Vorgabe an der Naht sitzt.**
 * *`Tree::collapsedByDefault()` ist Kern und hat eigene Tests; was hier geprüft wird, ist die
 * Verdrahtung: `NodesScreen` muss «kein Parameter» als **frische Seite** lesen und
 * `taxmod_collapsed=none` als **absichtlich alles offen**. Ein Kerntest sieht diesen Unterschied nie,
 * weil er kein `$_GET` hat.*
 *
 * ⚠️ **Auf Zeilen-Ids geprüft und nicht auf Namen** — ein abgestürzter Lauf hinterlässt Knoten mit
 * denselben Namen, und ein Namensvergleich findet dann den Rest statt die eigene Wiese.
 *
 * ⚠️ *Und die Gegenprobe ist mitgeprüft: dieselbe Seite mit `taxmod_collapsed=none` muss den Enkel
 * **zeigen**. Ohne sie wäre eine Abfrage, die versehentlich nichts findet, genauso grün.*
 *
 * Usage: php scripts/dev/collapsed-default-check.php
 *
 * @see docs/NewConcept/97-implementation-plan.md
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Tree;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Persistence\{SeededFrameworkNodes, WpdbChangelog, WpdbNodeRepository, WpdbRelationRepository};
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

$nodes = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$log   = new WpdbChangelog(new SystemClock());
$fw    = new SeededFrameworkNodes($nodes, $relations, $log);

$editor = new ModelEditor($nodes, $relations, $fw, $log);
$tree   = new Tree($nodes);

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

$ast   = $editor->createNode('__cd Ast', $fw->rootOf(Branch::Model)->id);
$kind  = $editor->createNode('__cd Kind', $ast->id);
$enkel = $editor->createNode('__cd Enkel', $kind->id);

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, 'C:/Devel/Wordpress/source/wp-taxonomy-tree/wp-taxonomy-modeler.php');

/** Welche der drei Zeilen die gezeichnete Seite enthält — an der Id, nicht am Namen. */
$sichtbar = static function (NodesScreen $screen, array $ids): array {
    $markup = $screen->render();
    $da     = [];

    foreach ($ids as $name => $id) {
        $da[$name] = str_contains($markup, 'id="taxmod-node-' . $id . '"');
    }

    return $da;
};

$wiese = ['Ast' => $ast->id, 'Kind' => $kind->id, 'Enkel' => $enkel->id];

// ── Die frische Seite: kein Parameter, alles zu ────────────────────────────
unset($_GET['taxmod_collapsed'], $_GET['taxmod_node'], $_GET['taxmod_hidden']);

echo "== die frische Seite zeigt nur die oberste Ebene ==\n";

$frisch = $sichtbar($plugin->screen(), $wiese);

// ⚠️ **Gemessen und es ist mehr als erwartet weg: auch der Ast.** *Er hängt unter `Model`, und
// `Model` ist selbst ein Zweig unter der Wurzel — also ist die frische Seite genau die Liste der
// Zweige (Model, Kompositionen, Einheiten, Datentypen …) und sonst nichts. **Das ist «die beste
// Übersicht», die der Eigentümer nannte**, und es war beim Schreiben dieser Prüfung nicht
// selbstverständlich: die erste Fassung behauptete, der Ast stünde noch da, und wurde widerlegt.
$say(! $frisch['Ast'], 'der Ast ist eingeklappt — Model ist selbst ein Zweig und zu');
$say(! $frisch['Kind'], 'das Kind ist eingeklappt');
$say(! $frisch['Enkel'], 'der Enkel ist eingeklappt');

// ── Die Gegenprobe: absichtlich alles offen ───────────────────────────────
echo "\n== und mit dem Merker «alles offen» stehen alle drei da ==\n";

$_GET['taxmod_collapsed'] = 'none';

$offen = $sichtbar($plugin->screen(), $wiese);

foreach (array_keys($wiese) as $name) {
    $say($offen[$name], sprintf('«%s» ist da', $name));
}

// ⚠️ *Der eigentliche Punkt des Merkers: **ohne** ihn wäre diese URL «kein Parameter» und damit
// wieder die frische, eingeklappte Seite. Die beiden Läufe oben sind genau derselbe Aufruf mit
// genau einem Unterschied — deshalb ist die Zusage geprüft und nicht nur behauptet.*
$say($frisch['Enkel'] !== $offen['Enkel'], 'die zwei Zustände sind unterscheidbar — sonst wäre die Prüfung wertlos');

// ── Der ausgewählte Knoten ist immer sichtbar ─────────────────────────────
echo "\n== der Pfad zum ausgewählten Knoten ist offen ==\n";

unset($_GET['taxmod_collapsed']);

$_GET['taxmod_node'] = (string) $enkel->id;

$gewaehlt = $sichtbar($plugin->screen(), $wiese);

foreach (array_keys($wiese) as $name) {
    $say($gewaehlt[$name], sprintf('«%s» liegt auf dem Pfad und ist da', $name));
}

unset($_GET['taxmod_node']);

// ── Der Zustand wird fortgeschrieben ──────────────────────────────────────
//
// ⚠️ **Die Bitte des Eigentümers, nachdem das Einklappen gebaut war:** *«ich hätte lieber, dass der
// Status — welcher Knoten offen ist und welcher nicht — **fortgeschrieben** wird … wenn ich zwischen
// zwei Knoten arbeite und dauernd die Äste zugehen, das ist ziemlich nervig.»*
//
// ⚠️ *Und es war kaputt, obwohl der Zustand «in der URL lag»: eine **frische** Seite trug ihn in
// keinem einzigen Link, also berechnete der nächste Klick die Vorgabe neu. **Der Zustand lag in der
// URL und niemand schrieb ihn hinein.** Diese Prüfung sieht genau da hin.*
echo "\n== der Faltzustand wird in die Links geschrieben, auch auf einer frischen Seite ==\n";

$_GET['taxmod_node'] = (string) $enkel->id;

$markup = $plugin->screen()->render();

$mitZustand = preg_match_all('/taxmod_collapsed=([^&"]*)/', $markup, $treffer);

printf("       %d Links tragen den Faltzustand\n", $mitZustand);

$say($mitZustand > 0, 'die frische Seite schreibt den Zustand in ihre Links');

$menge = array_map('intval', explode(',', urldecode($treffer[1][0] ?? '')));

// ⚠️ *Der Kern der Zusage: die Vorfahren des **anderen** Knotens bleiben in der mitgeführten Menge
// frei, wenn man auf ihn springt — sonst geht sein Ast zu, was er «ziemlich nervig» nannte.*
$say(
    array_intersect($enkel->ancestorIds(), $menge) === [],
    'die Vorfahren des gewählten Knotens stehen nicht in der mitgeführten Menge'
);

$_GET['taxmod_collapsed'] = urldecode($treffer[1][0] ?? '');
$_GET['taxmod_node']      = (string) $ast->id;

$zweiter = $plugin->screen()->render();

preg_match('/taxmod_collapsed=([^&"]*)/', $zweiter, $zweiteMenge);

$menge2 = array_map('intval', explode(',', urldecode($zweiteMenge[1] ?? '')));

$say(
    array_intersect($enkel->ancestorIds(), $menge2) === [],
    'nach dem Sprung auf einen anderen Knoten ist der erste Ast NOCH offen — fortgeschrieben'
);

$say(
    array_intersect($ast->ancestorIds(), $menge2) === [],
    'und der Ast des neuen Knotens ist auch offen'
);

unset($_GET['taxmod_node'], $_GET['taxmod_collapsed'], $_GET['taxmod_open_for']);

// ── Ein Klick auf den Klapp-Pfeil gewinnt gegen den Vorrang der Auswahl ────
//
// ⚠️ **[D-612](../../docs/NewConcept/90-decision-log.md), und heute tat der Klick sichtbar nichts:**
// *lag der gewählte Knoten in dem Ast, den man zuklappte, machte derselbe Aufruf ihn wieder auf.*
//
// ⚠️ *Die Zeile, die ihn aufmacht, bleibt richtig — sie greift nur noch beim **Wechsel** der
// Auswahl. Deshalb prüft dieser Abschnitt beides: das Zuklappen hält, und der Sprung auf einen
// anderen Knoten öffnet dessen Weg trotzdem.*
echo "\n== der Klapp-Pfeil gewinnt gegen den Vorrang der Auswahl ==\n";

$_GET['taxmod_node']      = (string) $enkel->id;
$_GET['taxmod_collapsed'] = (string) $kind->id;
$_GET['taxmod_open_for']  = (string) $enkel->id;

$zugeklappt = $sichtbar($plugin->screen(), $wiese);

$say($zugeklappt['Kind'], 'das Kind steht noch da — nur sein Ast ist zu');
$say(! $zugeklappt['Enkel'], 'der Enkel ist weg: das Zuklappen hat gehalten, obwohl er der gewählte Knoten ist');

// ⚠️ *Die Gegenprobe, und ohne sie wäre die Zusage oben auch dann grün, wenn der Weg **nie** mehr
// aufginge: derselbe Aufruf ohne den Merker ist ein frisch gewählter Knoten, und dort ist das
// Aufmachen richtig ({@see D-480}, {@see D-615}).*
unset($_GET['taxmod_open_for']);

$neuGewaehlt = $sichtbar($plugin->screen(), $wiese);

$say($neuGewaehlt['Enkel'], 'frisch gewählt öffnet sein Weg weiterhin — die Vorgabe ist nicht abgeschafft');

// ⚠️ *Und der Merker muss in den Links stehen, sonst hielte das Zuklappen genau einen Klick lang —
// derselbe Fehler, den [D-480](../../docs/NewConcept/90-decision-log.md) am Faltzustand hatte.*
$_GET['taxmod_open_for'] = (string) $enkel->id;

$markupZu = $plugin->screen()->render();

$say(
    substr_count($markupZu, 'taxmod_open_for=' . $enkel->id) > 0,
    'die Seite schreibt den Merker in ihre Links'
);

unset($_GET['taxmod_node'], $_GET['taxmod_collapsed'], $_GET['taxmod_open_for']);

// ── Kein Formular und kein Link verliert den Faltzustand ──────────────────
//
// ⚠️ **«Alles zu» gilt beim ersten Aufruf, nicht bei jedem Link, der ihn vergessen hat**
// ([D-480](../../docs/NewConcept/90-decision-log.md), [D-615](../../docs/NewConcept/90-decision-log.md)).
// *Gemessen vor der Reparatur: **10 von 17 Formularen** der Seite trugen den Zustand nicht — jeder
// Akt aus einem von ihnen fiel auf «alles zu» zurück, ohne dass der Benutzer etwas zugeklappt hätte.*
//
// ⚠️ *Der Fehler war nicht «keiner trägt ihn», sondern «fünf Stellen bauten ihn verschieden»: die
// verborgenen Felder hatten den Rückfall auf den gemerkten Zustand, die Formulare der Zeilen lasen
// nur `$_GET` — und auf einer frischen Seite steht dort nichts.*
echo "\n== kein Formular auf der Seite verliert den Faltzustand ==\n";

$_GET['taxmod_node'] = (string) $enkel->id;

$seite = $plugin->screen()->render();

preg_match_all('#<form\b.*?</form>#s', $seite, $formulare);

$ohne = 0;

foreach ($formulare[0] as $eines) {
    if (! str_contains($eines, 'taxmod_collapsed')) {
        ++$ohne;
    }
}

printf("       %d Formulare, davon %d ohne Faltzustand\n", count($formulare[0]), $ohne);

$say(count($formulare[0]) > 5, 'die Seite hat ueberhaupt Formulare — sonst waere die Zaehlung leer und gruen');
$say($ohne === 0, 'jedes Formular schickt den Faltzustand mit');

// ⚠️ *Und die Sprachwahl war die letzte Stelle, die sich ihre Adresse selbst baute — ein
// Sprachwechsel klappte den Baum zu.*
$say(
    ! str_contains($seite, 'taxmod-locale')
        || preg_match('/class="taxmod-locale".{0,600}?taxmod_collapsed=/s', $seite) === 1,
    'auch die Sprachwahl traegt ihn in ihrer Adresse'
);

unset($_GET['taxmod_node']);

// ── Und der Kern sagt dasselbe über sich ──────────────────────────────────
echo "\n== der Kern: was gefaltet wird, sind die Knoten mit Kindern ==\n";

$gefaltet = $tree->collapsedByDefault();

printf("       %d Knoten mit Kindern im ganzen Modell\n", count($gefaltet));

$say(in_array($ast->id, $gefaltet, true), 'der Ast hat ein Kind und ist gefaltet');
$say(in_array($kind->id, $gefaltet, true), 'das Kind hat ein Kind und ist gefaltet');
$say(! in_array($enkel->id, $gefaltet, true), 'der Enkel hat keins und ist nicht gefaltet');

$mitPfad = $tree->collapsedByDefault($enkel);

$say(! in_array($ast->id, $mitPfad, true), 'mit dem Enkel als Ziel ist der Ast offen');
$say(! in_array($kind->id, $mitPfad, true), '… und das Kind auch');
// ⚠️ *Vier und nicht drei: **die Wurzel zählt mit.** Sie steht im Pfad jedes Knotens und hat Kinder,
// also ist sie gefaltet wie jeder andere Elternknoten — und dass ihre Faltung nie gelesen wird
// (`collect()` beginnt **unter** ihr), ändert nichts daran, dass sie in der Menge steht.*
$say(count($gefaltet) - count($mitPfad) === 4, sprintf('genau die vier Vorfahren fielen heraus (Wurzel, Model, Ast, Kind) — %d gegen %d', count($gefaltet), count($mitPfad)));

// ── Der Dialog hat seinen eigenen Faltzustand ─────────────────────────────
//
// ⚠️ **Der Eigentümer, 2026-09-05:** *«der baum im dialog … hat noch die einstellungen bezüglich
// elapsed und collapsed von der baumansicht auf der einstellungsseite, dies muss unabhängig
// voneinander sein»* — und *«im dialog muss alles collapsed sein bis auf den default ast … und das
// gilt nicht nur für die oberste ebene sondern auch für alle darunter.»*
//
// ⚠️ *Der Fehler war eine Übergabe: der Verschiebe-Dialog bekam **die Zeilen der Seitenansicht**,
// mitsamt deren Faltzustand und, bei gesetztem Filter, nur den Treffern. Deshalb sieht diese
// Prüfung nicht in den Kern, sondern in das Markup **des Dialogs auf der gezeichneten Seite**.*
echo "\n== der Auswahldialog faengt zu an, gleich wie die Seite steht ==\n";

/** Die Zeilen genau eines Dialogs, an seinem Feldnamen erkannt: Id => [sichtbar, Klappmarke]. */
$dialogZeilen = static function (string $markup, string $feld): array {
    $ab = strpos($markup, '-' . $feld . '"');

    if ($ab === false) {
        return [];
    }

    $baum = strpos($markup, '<span class="taxmod-chooser-tree">', $ab);

    if ($baum === false) {
        return [];
    }

    $bis    = strpos($markup, 'class="taxmod-dialog-switch"', $baum);
    $stueck = substr($markup, $baum, $bis === false ? null : $bis - $baum);
    $zeilen = [];

    foreach (explode('<div class="taxmod-tree-row"', $stueck) as $zeile) {
        if (! preg_match('/taxmod-choice-' . preg_quote($feld, '/') . '-(\d+)/', $zeile, $wer)) {
            continue;
        }

        // ⚠️ *Am **Anfang** der Zeile und nicht irgendwo darin: die innere Zelle traegt selbst ein
        // `display:flex`, und die erste Fassung dieser Prueferei las genau die — jede Zeile war
        // damit «sichtbar», auch die ausgeblendete.*
        // ⚠️ **Geändert am 2026-09-15 mit [D-816](../../docs/NewConcept/90-decision-log.md), sichtbar:** *die Zeile trägt ihr `display:flex`
        // seither in der Klasse; inline steht nur noch `display:none`, wo sie verborgen ist. Sichtbar heisst also: am Anfang kein `display:none`.*
        $zeilen[(int) $wer[1]] = [
            'sichtbar' => preg_match('/^ data-depth="\d+"(?: style="(?![^"]*display:none)[^"]*")?>/', $zeile) === 1,
            'klapp'    => preg_match('/data-fold="(auf|zu)"/', $zeile, $k) === 1 ? $k[1] : '',
        ];
    }

    return $zeilen;
};

// Die Seite steht so weit offen, wie sie nur kann — und der Dialog darf davon nichts uebernehmen.
$_GET['taxmod_collapsed'] = 'none';
$_GET['taxmod_node']      = (string) $ast->id;

$weit = $dialogZeilen($plugin->screen()->render(), 'target');

printf("       %d Zeilen im Verschiebe-Dialog\n", count($weit));

$say($weit !== [], 'der Verschiebe-Dialog ist auf der Seite und seine Zeilen sind lesbar');

// ⚠️ *Alle Zeilen stehen im Dokument — der Dialog klappt im Browser und kann nichts nachladen.
// Geprueft wird also die **Anzeige**, nicht das Vorhandensein.*
$say(isset($weit[$enkel->id]), 'der Enkel steht im Dokument des Dialogs (er klappt ohne Neuaufbau)');
$say(($weit[$ast->id]['sichtbar'] ?? false) === true, 'der Ast ist sichtbar — er liegt im Einstiegsast «Model»');
$say(($weit[$kind->id]['sichtbar'] ?? true) === false, 'das Kind ist zu, obwohl die Seite alles offen hat');
$say(($weit[$enkel->id]['sichtbar'] ?? true) === false, 'der Enkel ebenso — «alle darunter», nicht nur die oberste Ebene');

// ⚠️ **Der Kern der Ast-Angabe ([D-615](../../docs/NewConcept/90-decision-log.md)): sie ist
// ausschliessend.** *Unterhalb des offenen Astes darf kein zweiter offenstehen — sonst waere «alles
// zu ausser einem» nur fuer die oberste Ebene wahr.*
$offeneAeste = [];

foreach ($weit as $id => $z) {
    if ($z['klapp'] === 'auf') {
        $offeneAeste[] = $id;
    }
}

printf("       %d offene Aeste im Dialog: %s\n", count($offeneAeste), implode(',', $offeneAeste));

$say(count($offeneAeste) === 1, 'genau ein Ast steht offen — kein zweiter unterhalb');
$say(($weit[$ast->id]['klapp'] ?? '') === 'zu', 'der Ast selbst ist zugeklappt, obwohl er sichtbar ist');

// ── Und dieselbe Seite ohne jeden Faltzustand gibt denselben Dialog ────────
//
// ⚠️ *Das ist die eigentliche Zusage: **zwei Seitenzustaende, ein Dialog.** Ohne diesen Vergleich
// waere «zu» oben auch dann gruen, wenn der Dialog schlicht immer der Seite folgt und die Seite
// zufaellig zu ist.*
unset($_GET['taxmod_collapsed']);

$frischerDialog = $dialogZeilen($plugin->screen()->render(), 'target');

$say($frischerDialog === $weit, 'der Dialog sieht gleich aus, ob die Seite offen oder zu ist');

unset($_GET['taxmod_node']);

// aufraeumen
global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

foreach ([$enkel->id, $kind->id, $ast->id] as $id) {
    $e   = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}relations WHERE from_node_id = {$id} OR to_node_id = {$id}"));
    $own = $e === [] ? (string) $id : $id . ',' . implode(',', $e);

    $wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$own})");
    $wpdb->query("DELETE FROM {$p}changelog WHERE owner_id IN ({$own})");

    if ($e !== []) {
        $wpdb->query('DELETE FROM ' . $p . 'relations WHERE id IN (' . implode(',', $e) . ')');
    }

    $wpdb->query("DELETE FROM {$p}nodes WHERE id = {$id}");
}

if ($wpdb->last_error !== '') {
    fwrite(STDERR, "Aufraeumen kaputt: {$wpdb->last_error}\n");

    exit(2);
}

echo "\n";
$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE id IN (" . implode(',', [$ast->id, $kind->id, $enkel->id]) . ')') === 0, 'die Wiese ist wieder weg');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
