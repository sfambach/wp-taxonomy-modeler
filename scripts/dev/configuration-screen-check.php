<?php declare(strict_types=1);

/**
 * Die Konfigurationsseite: drei Spalten, Schiebeschalter, und die Erklärung sichtbar.
 *
 * ⚠️ **Diesen Bildschirm hat bis zum 2026-09-09 kein einziger Wächter je gezeichnet** — und `PR-9`
 * sagt, ein Paket, das nichts bewacht, ist eines, das das nächste still kaputtmacht. *Er trägt
 * inzwischen fünf Entscheidungen ([D-387](../../docs/NewConcept/90-decision-log.md),
 * [D-389](../../docs/NewConcept/90-decision-log.md), [D-397](../../docs/NewConcept/90-decision-log.md),
 * [D-693](../../docs/NewConcept/90-decision-log.md), [D-695](../../docs/NewConcept/90-decision-log.md)),
 * und keine davon hätte gemerkt, wenn sie ausgefallen wäre.*
 *
 * ⚠️ **Gemessen wird die Form, nicht der Zustand.** *Ob der Entwicklermodus an ist, entscheidet er;
 * dass sein Schalter ein **Schiebeschalter** ist und in der mittleren Spalte steht, entscheidet
 * [D-695](../../docs/NewConcept/90-decision-log.md). **Eine Zusage, die den Zustand mitprüft, fällt,
 * sobald er einen Haken umlegt** — der Fehler, den `package7-check` an der Schreibzahl schon gemacht
 * hat.*
 *
 * ⚠️ *Und die Gegenprobe gehört dazu: **das Fragezeichen darf hier nicht mehr stehen**. Ohne sie wäre
 * eine Seite, die beides zeigt — Spalte **und** Versteck —, genauso grün, und
 * [D-695](../../docs/NewConcept/90-decision-log.md) sagt ausdrücklich «braucht kein Versteck».*
 *
 * Usage: php scripts/dev/configuration-screen-check.php
 *
 * @see docs/NewConcept/20-interaction.md
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';

wp_set_current_user(1);

// ⚠️ *`get_submit_button()` wohnt in den Admin-Teilen, die ein Frontlauf nicht laedt — der
// Bildschirm selbst laeuft nur im Adminbereich, also holt der Waechter, was der Adminbereich haette.*
require_once ABSPATH . 'wp-admin/includes/template.php';

use Taxmod\WordPress\Admin\SettingsScreen;

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

$markup = (new SettingsScreen())->render();

$say($markup !== '', 'render() gibt Markup zurueck, statt zu sterben');
$say(str_contains($markup, '<table class="taxmod-config">'), 'die Seite traegt ihre eigene Tabelle und nicht form-table');
$say(! str_contains($markup, 'class="form-table"'), 'und die zweispaltige von WordPress ist weg');

// ⚠️ *Drei Kopfzellen, nicht «mindestens drei»: eine vierte waere so falsch wie zwei.*
$say(substr_count($markup, '<th scope="col">') === 3, 'drei Spalten, wie er sie aufgezaehlt hat');

// ⚠️ *Bei Namen genannt statt gezählt: eine feste Zahl fiel hier schon einmal, als Zeilen dazukamen.*
foreach (['developer', 'show_trash'] as $einer) {
    $say(
        (bool) preg_match('/name="' . $einer . '" value="1"/', $markup),
        sprintf('«%s» ist ein Schiebeschalter', $einer)
    );
}

// ⚠️ **Die verborgene Null ist der Unterschied zwischen «aus» und «nie gesagt»** (D-315, D-232).
$say(
    substr_count($markup, 'name="developer" value="0"') === 1
        && substr_count($markup, 'name="show_trash" value="0"') === 1,
    'und jeder schickt seine verborgene Null mit, damit aus auch aus heisst'
);

// ⚠️ **Die vier Einzelhaken stehen genau dann da, wenn der Modus an ist**
// ([D-705](../../docs/NewConcept/90-decision-log.md)) — *gegen den Schalter gemessen, nicht gegen
// einen angenommenen Zustand. Sein Wort: «wenn developer mode aktiv ist».*
$entwickler = Taxmod\WordPress\Admin\SettingsScreen::inDeveloperMode();

// ⚠️ *Drei und nicht vier: die **Renderer-Diagnose** bekam keinen Haken, weil sie niemand füllt —
//  gemessen am 2026-09-09, `Rendering::recordsAsTable()` nimmt einen Diagnosetext entgegen und **kein
//  Aufrufer übergibt ihn**. Ein Schalter für etwas, das nie erscheint, ist Möbel (D-429).*
foreach (['dev_writes', 'dev_settings_record', 'dev_root_toggle'] as $einer) {
    $say(
        str_contains($markup, 'name="' . $einer . '" value="1"') === $entwickler,
        sprintf('  · «%s» steht da, wenn der Modus an ist', $einer)
    );
}

$say(
    str_contains($markup, 'name="dev_details"') === $entwickler,
    'und der Merker reist mit, damit ein Speichern ohne die Zeilen sie nicht loescht'
);

// ⚠️ **Gegen sich selbst gezählt und nicht gegen eine feste Zahl.** *Hier standen zweimal «=== 5»,
// und die fiel in dem Moment, als [D-703](../../docs/NewConcept/90-decision-log.md) drei
// Anzeigezeilen dazugab — obwohl an jeder Zeile alles dran war. **Eine feste Zahl misst den
// Bestand, die Zusage meint die Form**: jede Zeile hat ihre drei Zellen, wie viele es auch seien.*
$zeilen = substr_count($markup, '<tr><th scope="row">');

$say($zeilen >= 5, sprintf('die Seite traegt Zeilen (%d)', $zeilen));
$say(substr_count($markup, 'class="taxmod-config-value"') === $zeilen, 'jede Zeile hat ihre mittlere Zelle');
$say(substr_count($markup, 'class="taxmod-config-why description"') === $zeilen, 'und ihre Erklaerung rechts');

// ⚠️ *Die Gegenprobe zu D-695: die Erklaerung steht in der Spalte **statt** hinter dem Fragezeichen.*
$say(! str_contains($markup, 'taxmod-hint'), 'und keine davon versteckt sich mehr hinter einem Fragezeichen');


// ⚠️ **Und das Stilblatt muss diese Seite überhaupt erreichen**
// ([D-696](../../docs/NewConcept/90-decision-log.md)). *Sein Befund war «sehe aber nix»: die drei
// Spalten standen im Markup, aber `admin_print_styles` hing nur am Haken der **Hauptseite** — die
// beiden Unterseiten bekamen es nie. **Ein Markup-Wächter allein hätte das nie gesehen**, weil am
// Markup nichts fehlte.*
//
// ⚠️ *Gemessen wird die Verdrahtung und nicht die Datei: die Menüseiten werden angemeldet, und dann
// wird gefragt, ob an jedem Haken ein Stilblatt hängt.*

// ⚠️ **Die drei Anzeigezeilen** ([D-703](../../docs/NewConcept/90-decision-log.md)). *Sie standen
// bisher nur in WordPress-Optionen — `node-binding-check` prüfte die Rahmen-Ids seit langem, ein
// Mensch konnte sie nicht sehen.*
//
// ⚠️ *Gemessen wird, dass die Anzeige **dieselbe Quelle** nennt wie der Kode, und nicht, welche Zahl
// dasteht: eine Zusage auf «41» wäre beim nächsten Schemaschritt rot, ohne dass etwas kaputt ist.*
$say(str_contains($markup, '>Schema version<'), 'die Schemafassung steht auf der Seite');
$say(
    str_contains($markup, '<code>' . (int) get_option(Taxmod\WordPress\Persistence\Schema::VERSION_OPTION, 0) . '</code>'),
    'und nennt die Fassung, die wirklich in der Datenbank liegt'
);

$say(str_contains($markup, '>Seeded scaffolds<'), 'die Geruestfassungen stehen da');
foreach (['base', 'units', 'compositions', 'rendering'] as $eines) {
    $say(str_contains($markup, $eines . ' <code>'), sprintf('  · das Geruest «%s» ist genannt', $eines));
}

// ⚠️ *Die Rahmen-Ids gegen die Datenbank gezählt und nicht gegen eine Zahl von heute: kommt ein Ast
// dazu, wächst beides zusammen. **Eine feste Zahl hier wäre ein Changelog**, genau wie in `CLAUDE.md`.*
global $wpdb;
$rahmen = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'taxmod\_%\_id'"
);

$say(str_contains($markup, '>Framework ids<'), 'die Rahmen-Ids stehen da');
$say(
    substr_count($markup, '<code>taxmod_') === $rahmen,
    sprintf('und es sind alle, die es gibt (%d)', $rahmen)
);


// ⚠️ **Was der Browser bei einem *ausgeschalteten* Schalter schickt, und dass es ankommt**
// ([D-706](../../docs/NewConcept/90-decision-log.md)). *Sein Befund: «kann write counts zwar
// ausschalten aber nach save wieder alter wert». **Der Schalter schickt eine verborgene `0` vor sich
// her** — damit ist das Feld **immer** gesetzt, und das `isset()`, das für ein Kästchen richtig war,
// las jedes Ausschalten als Einschalten. **Es traf alle drei Schalter**, auch den Modus selbst.*
//
// ⚠️ *Gemessen an der entscheidenden Stelle und ohne zu schreiben: `handlePost()` würde umleiten und
// den Lauf beenden, und ein Wächter, der die Einstellungen des Eigentümers verstellt, ist keiner.*
$angehakt = new ReflectionMethod(Taxmod\WordPress\Admin\SettingsScreen::class, 'angehakt');
$angehakt->setAccessible(true);

$_POST = ['aus' => '0', 'an' => '1'];

$say($angehakt->invoke(null, 'an') === true, 'ein angehakter Schalter kommt als «an» an');
$say($angehakt->invoke(null, 'aus') === false, 'und ein ausgeschalteter als «aus» — nicht als «gesetzt, also an»');
$say($angehakt->invoke(null, 'gibt_es_nicht') === false, 'und ein Feld, das gar nicht kam, ist aus');

$_POST = [];

// ⚠️ *Und der Merker steht **vor** der Tabelle: zwischen `</tr>` und `<tr>` gehört kein Element, und
// worauf der Browser es verschiebt, ist seine Entscheidung und nicht unsere.*
$vorTabelle = strpos($markup, 'name="dev_details"');
$tbody      = strpos($markup, '<tbody>');

$say(
    $vorTabelle === false || $tbody === false || $vorTabelle < $tbody,
    'der Merker steht vor der Tabelle und nicht zwischen zwei Zeilen'
);

// ⚠️ *Das Zusatzstueck ist schon hochgefahren — `wp-load` hat es geladen, weil es aktiv ist. Also
//  wird sein eigener Menueaufbau ausgeloest statt ein zweiter gebaut: ein zweites Exemplar
//  meldete andere Haken an als die, die der Bildschirm spaeter wirklich hat.*
require_once ABSPATH . 'wp-admin/includes/plugin.php';
do_action('admin_menu');

foreach (['toplevel_page_taxmod', 'taxonomy-modeller_page_taxmod-settings'] as $einer) {
    $say(
        has_action('admin_print_styles-' . $einer) !== false,
        sprintf('das Stilblatt haengt am Haken «%s»', $einer)
    );
}

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
