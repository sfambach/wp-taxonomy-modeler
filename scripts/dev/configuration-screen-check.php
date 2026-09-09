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

$schalter = substr_count($markup, 'taxmod-toggle-track');
$say($schalter === 2, sprintf('beide Optionen sind Schiebeschalter (%d gefunden)', $schalter));
// ⚠️ **Die verborgene Null ist der Unterschied zwischen «aus» und «nie gesagt»** (D-315, D-232).
$say(
    substr_count($markup, 'name="developer" value="0"') === 1
        && substr_count($markup, 'name="show_trash" value="0"') === 1,
    'und jeder schickt seine verborgene Null mit, damit aus auch aus heisst'
);

$say(substr_count($markup, 'class="taxmod-config-value"') === 5, 'jede Zeile hat ihre mittlere Zelle');
$say(substr_count($markup, 'class="taxmod-config-why description"') === 5, 'und ihre Erklaerung rechts');

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
