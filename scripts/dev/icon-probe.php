<?php declare(strict_types=1);

/**
 * Probeseite: sitzen die Icons auf einer Linie?
 *
 * ⚠️ **Sie bleibt, weil derselbe Fehler dreimal wiederkam.** *Eine Pruefung im Quelltext kann sagen,
 * dass es nur eine Stelle gibt, an der es schiefgehen kann. Ob es am Ende auf einer Linie sitzt,
 * sagt nur ein Bild — und der Eigentümer meldet einen **optischen** Fehler.*
 *
 * So laeuft sie, ohne Login und ohne das Admin anzufassen:
 *
 *     php -S localhost:8099 -t C:/Devel/Wordpress
 *     http://localhost:8099/scripts/../source/wp-taxonomy-tree/scripts/dev/icon-probe.php
 *
 * Einfacher: die Datei neben `wp-load.php` legen und dort aufrufen. Sie schreibt nichts.
 *
 * ⚠️ *Gemessen wird nicht mit dem Auge. Der eigentliche Wert steckt im JavaScript daneben:
 * Kastengroesse je Icon, Abweichung zur Knopfmitte, und — das entscheidende Kriterium — Abweichung
 * zur **optischen Mitte des Textes**, also Grundlinie minus halbe x-Hoehe, aus `measureText()`.
 * Nach [D-495](../../docs/NewConcept/90-decision-log.md): 17x17 ueberall, 20x20 im Detailbereich,
 * 0,00 px im Knopf, +0,25 px im Textfluss und fuer jeden Fall dieselben.*
 *
 * Kein Login. Sie laedt das echte Stylesheet und das echt erzeugte Markup und stellt die Faelle
 * nebeneinander, damit man sieht statt behauptet. Nach dem Blick wieder loeschen.
 */

require dirname(__DIR__, 4) . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;
use Taxmod\Core\Renderer\IconMarkup;

$css = file_get_contents(dirname(__DIR__, 2) . '/assets/admin.css');

$knopf = static function (string $icon, string $label): string {
    return ControlMarkup::button(new Control('do', $icon, $label, $label, true, false, $icon));
};

?><!doctype html>
<meta charset="utf-8">
<title>taxmod — sitzen die Icons?</title>
<link rel="stylesheet" href="<?php echo esc_url(includes_url('css/dashicons.min.css')); ?>">
<style>
body { font: 13px -apple-system, "Segoe UI", sans-serif; background: #f0f0f1; margin: 2em; --taxmod-icon: 17px; }
.probe { background: #fff; border: 1px solid #c3c4c7; padding: 1em; margin-bottom: 1em; }
.probe h2 { font-size: 13px; margin: 0 0 .6em; color: #646970; font-weight: 600; }
.rule { position: relative; }
/* Eine waagerechte Linie durch die optische Mitte: was darauf sitzt, sitzt auf einer Linie. */
.rule::after { content: ""; position: absolute; left: 0; right: 0; top: 50%; border-top: 1px solid #d63638; opacity: .55; }
.row { display: flex; align-items: center; gap: .8em; min-height: 34px; }
.button { border: 1px solid #c3c4c7; background: #f6f7f7; border-radius: 3px; padding: .2em .4em; cursor: pointer; }
<?php echo $css; ?>
</style>

<h1 style="font-size:16px">Sitzen die Icons auf einer Linie?</h1>
<p style="background:#fcf9e8;border-left:4px solid #dba617;padding:.6em .8em">
<strong>Die Abschnitte 1 bis 5 sind nachgebaut, Abschnitt 6 ist echt.</strong>
Am 2026-08-29 hat das genau einmal in die Irre gefuehrt: in Abschnitt 2 steht der Knopf
<em>direkt neben</em> dem Text und das Knoten-Icon sass sichtbar 1,88&nbsp;px tiefer als er.
Im echten Baum steht die Knopfgruppe in <code>.taxmod-tree-tail</code> mit
<code>margin-left:auto</code>, also <strong>ganz rechts am Zeilenende</strong> — derselbe
Unterschied, aber ueber mehrere Zentimeter hinweg und damit nicht sichtbar.
<strong>Eine Probe, die ihren Gegenstand falsch nachstellt, ist schlechter als keine:</strong>
was hier gemessen wird, gilt erst, wenn Abschnitt 6 es bestaetigt.
</p>
<p>Die rote Linie liegt in der Mitte der Zeile. Jedes Icon soll sie in seiner eigenen Mitte schneiden.</p>

<div class="probe">
    <h2>1 · Knöpfe — Icon-Schrift neben dem Speichern-Zeichen</h2>
    <div class="row rule">
        <?php
        echo $knopf('trash', 'Löschen'),
             $knopf('plus-alt2', 'Hinzufügen'),
             $knopf('arrow-up-alt2', 'Hoch'),
             $knopf('admin-page', 'Kopieren'),
             ControlMarkup::button(Control::saving('do', 'save', 'Speichern', 'Speichern'));
        ?>
    </div>
</div>

<div class="probe">
    <h2>2 · Der Baum — vorher <code>vertical-align: text-bottom</code></h2>
    <div class="row rule taxmod-tree">
        <span><?php echo IconMarkup::dashicon('category'); ?> Ein Knotenname</span>
        <span><?php echo IconMarkup::dashicon('admin-generic'); ?> Noch einer</span>
        <?php echo $knopf('trash', 'Löschen'); ?>
    </div>
</div>

<div class="probe">
    <h2>3 · Die Auswahlzelle — vorher ganz ohne Regel, also 20px</h2>
    <div class="row rule">
        <span><?php echo IconMarkup::dashicon('category'); ?> <span class="taxmod-chooser-name">Auswahl</span></span>
        <?php echo IconMarkup::dashicon('category'); ?>
        <?php echo $knopf('trash', 'Löschen'); ?>
    </div>
</div>

<div class="probe taxmod-detail-pane">
    <h2>4 · Der Detailbereich — hier sind Icons absichtlich 3px grösser</h2>
    <div class="row rule">
        <?php echo $knopf('trash', 'Löschen'), $knopf('admin-page', 'Kopieren'); ?>
        <?php echo IconMarkup::dashicon('category'); ?>
        <?php echo ControlMarkup::button(Control::saving('do', 'save', 'Speichern', 'Speichern')); ?>
    </div>
</div>

<div class="probe">
    <h2>6 · Der <strong>echte</strong> Baum — nicht nachgebaut, sondern gerendert</h2>
    <?php
    // ⚠️ *Die Zeilen oben sind von mir gebaut. Ob eine Abweichung dort auch im Erzeugnis steckt,
    // sagt nur das echte Markup — sonst misst man die eigene Probe.*
    $r = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
    $plugin = $r->newInstanceWithoutConstructor();
    $r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
    wp_set_current_user(1);
    global $wpdb;
    $p = $wpdb->prefix . 'taxmod_';
    $_GET['taxmod_node'] = (string) (int) $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name = 'Adresse' LIMIT 1");
    $echt = $plugin->screen()->render();
    // ⚠️ *Ganz ausgeben statt einen Teil herausschneiden — verschachteltes Markup mit einem
    // regulaeren Ausdruck zu zerlegen misst am Ende den Ausdruck und nicht die Seite.*
    echo '<div id="echterschirm">', $echt, '</div>';
    ?>
</div>

<div class="probe">
    <h2>5 · Alle nebeneinander — der eigentliche Vergleich</h2>
    <div class="row rule">
        <?php
        foreach (['trash', 'plus-alt2', 'category', 'move', 'networking', 'editor-help', 'visibility'] as $one) {
            echo IconMarkup::dashicon($one), ' ';
        }
        echo IconMarkup::glyph(Control::SAVE_GLYPH, 'Speichern');
        ?>
        <span>Text daneben</span>
    </div>
</div>
