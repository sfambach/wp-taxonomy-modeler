<?php declare(strict_types=1);

/**
 * Trifft das Skript noch die Auszeichnung, die der Renderer erzeugt?
 *
 * ⚠️ **Was diese Prüfung leisten kann und was nicht — zuerst, damit sie nicht überschätzt wird.**
 * *Sie prüft **nicht**, dass Escape schliesst oder dass der Fokus gefangen bleibt: das ist
 * Browserverhalten, und hier gibt es kein `node` und keinen Testläufer für JavaScript. **Es wurde von
 * Hand im Browser gefahren** — echte Auszeichnung, echtes Stylesheet, echtes Skript, zwölf Zusagen,
 * und mit dem vorigen Skript nachweisbar rot ([D-491](../../docs/NewConcept/90-decision-log.md)).*
 *
 * ⚠️ **Was sie fängt, ist der Rückfall, der wirklich passiert: eine umbenannte Klasse.** *Das Skript
 * findet den Dialog über `.taxmod-dialog-switch`, `.taxmod-chooser` und `.taxmod-dialog-panel`. Benennt
 * jemand eine davon im Renderer um, **hört das Skript lautlos auf zu greifen** — kein Fehler in der
 * Konsole, keine rote Prüfung, nur ein Dialog, der Escape wieder ignoriert. Genau diese Sorte
 * lautloser Bruch hat in diesem Projekt schon `hide`, `label_role` und den Container-Renderer
 * getroffen.*
 *
 * ⚠️ *Und sie prüft, dass im Panel überhaupt etwas erreichbar ist: eine Fokusfalle ohne Halt ist eine
 * Falle, die den Fokus **verliert** statt ihn zu halten.*
 *
 * Usage: php scripts/dev/dialog-script-check.php
 *
 * @see docs/NewConcept/97-implementation-plan.md
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;
use Taxmod\Core\Renderer\DialogChooserRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\Core\Service\Tree;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

$root = 'C:/Devel/Wordpress/source/wp-taxonomy-tree/';
$js   = file_get_contents($root . 'assets/admin.js');

if ($js === false) {
    fwrite(STDERR, "assets/admin.js ist nicht lesbar\n");

    exit(2);
}

// ── Die Auszeichnung, wie der Renderer sie wirklich erzeugt ────────────────
$nodes = new WpdbNodeRepository();
$edges = new WpdbRelationRepository();
$fw    = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), new WpdbChangelog(new SystemClock()));

$rendering = new Rendering(
    $nodes,
    $fw,
    new Settings(new WpdbSettingRepository(), $nodes, $fw),
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $fw),
    new Labels(new WpdbLabelRepository(), $fw)
);

$rows = (new Tree($nodes, $edges))->rowsUnder($fw->rootOf(Branch::Model), [], [], false);

if ($rows === []) {
    fwrite(STDERR, "Keine Knoten unter Model — nichts zu waehlen.\n");

    exit(2);
}

$markup = $rendering->chooserFor(
    $rows,
    'target',
    null,
    [],
    null,
    'Nichts zu waehlen',
    DialogChooserRenderer::NAME,
    '',
    Level::Admin,
    ControlMarkup::button(new Control('open', 'open', 'waehlen', 'waehlen')),
    ControlMarkup::button(new Control('do', 'move', 'hierhin', 'hierhin', true, false, '', '', true))
)->markup;

echo "== das Skript und die Auszeichnung sprechen dieselben Klassen ==\n";

// ⚠️ *Beide Seiten werden geprueft: die Klasse muss im Skript stehen UND im Markup vorkommen. Nur eine
// Seite zu pruefen laesst genau den Fall durch, um den es geht — eine Umbenennung.*
foreach (['taxmod-dialog-switch', 'taxmod-chooser', 'taxmod-dialog-panel'] as $klasse) {
    $imSkript = str_contains($js, $klasse);
    $imMarkup = str_contains($markup, $klasse);

    $say($imSkript && $imMarkup, sprintf(
        '«%s» — im Skript: %s, im Markup: %s',
        $klasse,
        $imSkript ? 'ja' : 'NEIN',
        $imMarkup ? 'ja' : 'NEIN'
    ));
}

echo "\n== die zwei Tasten sind behandelt ==\n";

$say(str_contains($js, "'Escape'"), 'Escape kommt im Skript vor');
$say(str_contains($js, "'Tab'"), 'Tab kommt im Skript vor');
$say(str_contains($js, 'preventDefault'), 'und es hält die Voreinstellung an, statt sie zu dulden');

echo "\n== im Panel ist etwas erreichbar, sonst faengt die Falle nichts ==\n";

// ⚠️ *Ein Radio je waehlbarem Knoten plus der Bestaetigen-Knopf. Ohne mindestens einen Halt wuerde die
// Fokusfalle den Fokus **verlieren** statt ihn zu halten — sie greift dann gar nicht.*
$radios = preg_match_all('/<input[^>]+type="radio"/', $markup);
$knopf  = preg_match_all('/<button[^>]*>/', $markup);

printf("       %d Radios, %d Knöpfe in der Auszeichnung\n", $radios, $knopf);

$say($radios > 0, 'es gibt mindestens einen waehlbaren Halt');
$say($knopf > 0, 'und mindestens einen Knopf');

// ⚠️ *Der Schalter darf nicht `display:none` sein, sonst ist der Dialog per Tastatur gar nicht zu
// oeffnen — dann waere die Fokusfalle die Loesung fuer ein Problem, das niemand erreicht.*
$css = file_get_contents($root . 'assets/admin.css');

if ($css !== false && preg_match('/\.taxmod-dialog-switch\s*\{([^}]*)\}/', $css, $m) === 1) {
    echo "\n== der Schalter bleibt fokussierbar ==\n";

    $say(
        ! str_contains($m[1], 'display: none') && ! str_contains($m[1], 'display:none'),
        'sichtbar versteckt und nicht `display:none` — sonst gibt es keinen Tastaturweg hinein'
    );
}

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
