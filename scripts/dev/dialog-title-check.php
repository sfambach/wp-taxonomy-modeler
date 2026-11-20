<?php declare(strict_types=1);

/**
 * Jeder Dialog sagt, was er tut (D-1436): eine Titelleiste mit Worten, in Modeller und Modulen.
 *
 * ⚠️ *Sein Wort 2026-11-18 08:04: «auch jeder Dialog sollte eine Überschrift haben, damit der Benutzer genau weiß, was er jetzt
 * macht».* Geprüft wird:
 *
 * ```text
 * CONTRACT
 * Modeller-Seiten   jeder gezeichnete Dialog hat einen Titel mit Text; keiner trägt DialogMarkup::UNTITLED          (0)
 * Modeller-Quelle   kein Dialog von Hand gebaut, der am Titel vorbeigeht (taxmod-dialog-head ohne taxmod-dialog-title) (0)
 * Module            kein Dialog ohne Titel (leerer Titel an DialogMarkup::of, ListPage::dialogAction/fillAction/viewDialog) (0)
 * Module, Ratsche   Titel aus einem einzigen Wort — Verb ohne Gegenstand («Umbuchen»): nie mehr als heute, nur sinkend
 * ```
 *
 * Gegenprobe: ein Dialog ohne Titel trägt die Klasse, einer mit Titel nicht. Die Module werden gelesen, wo ihr Ordner neben diesem
 * liegt; ein fehlender Ordner wird genannt, nicht still übergangen.
 *
 * Usage: php scripts/dev/dialog-title-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1436
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

wp_set_current_user(1);

use Taxmod\WordPress\Draw\DialogMarkup;

/** Titel aus einem Wort, Stand 18.11.2026 — nur sinkend. */
const ONE_WORD_CEILING = ['wp_tax_stock' => 0, 'wp_tax_manufacturing' => 0, 'wp_tax_log_sales' => 0, 'wp_tax_label' => 0, 'wp_tax_ebay' => 0, 'wp_tax_api_ebay' => 0];

$failed = 0;
$say    = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
    $failed += $ok ? 0 : 1;
};

echo "\n== Gegenprobe ==\n";
$ohne = DialogMarkup::of('dt-a', 'x', '', '', '');
$mit  = DialogMarkup::of('dt-b', 'x', 'Umbuchen: Widerstand', '', '');
$wahl = DialogMarkup::of('dt-c', 'x', '—', '', '', title: 'Choose: Lagerort');
$say(str_contains($ohne, DialogMarkup::UNTITLED), 'ein Dialog ohne Titel trägt die Klasse');
$say(! str_contains($mit, DialogMarkup::UNTITLED) && str_contains($mit, 'taxmod-dialog-title" role="heading" aria-level="2"><span class="taxmod-chooser-current">Umbuchen: Widerstand'), 'einer mit Titel nicht, und der Titel steht in der Leiste');
$say(str_contains($wahl, '>Choose: Lagerort</span>') && str_contains($wahl, 'taxmod-dialog-current"><span class="taxmod-chooser-current">—'), 'ein Wähler: Titel in der Leiste, die Wahl darunter');
$weit = \Taxmod\WordPress\Admin\ListPage::dialogAction('dt-d', 'Ersatz', 'Ersatz wählen: Sicherung', '', 'do', 'x', 'OK', wide: true);
$say(str_contains($weit, 'taxmod-dialog ' . DialogMarkup::WIDE) && ! str_contains($mit, DialogMarkup::WIDE), 'ein breiter Dialog trägt die eine breite Grösse, ein gewöhnlicher nicht (D-1539)');

echo "\n== Modeller-Seiten ==\n";
$plugin = (new ReflectionClass(\Taxmod\WordPress\Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\Taxmod\WordPress\Plugin::class, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

global $wpdb;
$nodes = $wpdb->get_col("SELECT from_node_id FROM {$wpdb->prefix}taxmod_relations GROUP BY from_node_id ORDER BY COUNT(*) DESC, from_node_id LIMIT 3");
$pages = [['list', ['page' => 'taxmod']]];

foreach ($nodes as $node) {
    $pages[] = ['node ' . $node, ['page' => 'taxmod', 'taxmod_node' => (string) $node]];
}

$alle = 0;

foreach ($pages as [$name, $query]) {
    $_GET = $query;
    $html = $plugin->screen()->render();
    preg_match_all('/<span class="taxmod-dialog-title"[^>]*>(.*?)<\/span>\s*<label class="taxmod-dialog-close/s', $html, $titel);
    $leer  = count(array_filter($titel[1], static fn (string $t): bool => trim(html_entity_decode(strip_tags($t))) === ''));
    $koepfe = substr_count($html, 'class="taxmod-dialog-head"');
    $alle += $koepfe;
    $say($koepfe === count($titel[1]) && $leer === 0 && ! str_contains($html, DialogMarkup::UNTITLED), sprintf('%s: %d Dialoge, jeder mit Titel', $name, $koepfe));
}

$say($alle > 0, 'die Seiten zeichnen Dialoge (sonst prüfte das nichts)');

echo "\n== Modeller-Quelle: kein Dialog am Titel vorbei ==\n";
$vorbei = [];

foreach (['src', 'assets'] as $ordner) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/' . $ordner, FilesystemIterator::SKIP_DOTS)) as $datei) {
        if (! in_array($datei->getExtension(), ['php', 'js'], true)) {
            continue;
        }

        $text = (string) file_get_contents((string) $datei);

        if (preg_match('/[\'"]taxmod-dialog-head[\'"]|class="taxmod-dialog-head"/', $text) === 1 && ! str_contains($text, 'taxmod-dialog-title')) {
            $vorbei[] = basename((string) $datei);
        }
    }
}

$say($vorbei === [], 'jeder von Hand gebaute Kopf hat seinen Titel' . ($vorbei === [] ? '' : ' — ' . implode(', ', $vorbei)));

echo "\n== Module ==\n";

/**
 * The title argument of every dialog call in a module's sources.
 *
 * @return list<array{at: string, title: string}>
 */
function dialogTitles(string $repo): array
{
    $calls = ['of' => 2, 'dialogAction' => 2, 'fillAction' => 1, 'viewDialog' => 2];
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/src', FilesystemIterator::SKIP_DOTS)) as $datei) {
        if ($datei->getExtension() !== 'php') {
            continue;
        }

        $t = token_get_all((string) file_get_contents((string) $datei));
        $n = count($t);

        for ($i = 2; $i < $n; $i++) {
            if (! is_array($t[$i]) || $t[$i][0] !== T_STRING || ! isset($calls[$t[$i][1]]) || ! is_array($t[$i - 1]) || $t[$i - 1][0] !== T_DOUBLE_COLON) {
                continue;
            }

            if ($t[$i][1] === 'of' && ! str_ends_with((string) ($t[$i - 2][1] ?? ''), 'DialogMarkup')) {
                continue;
            }

            $args  = [''];
            $depth = 0;

            for ($k = $i + 1; $k < $n; $k++) {
                $text = is_array($t[$k]) ? $t[$k][1] : $t[$k];

                if (in_array($text, ['(', '[', '{'], true) && ++$depth === 1) {
                    continue;
                }

                if (in_array($text, [')', ']', '}'], true) && --$depth === 0) {
                    break;
                }

                if ($depth === 1 && $text === ',') {
                    $args[] = '';
                    continue;
                }

                if ($depth >= 1 && (! is_array($t[$k]) || ! in_array($t[$k][0], [T_COMMENT, T_DOC_COMMENT], true))) {
                    $args[count($args) - 1] .= $text;
                }
            }

            $found[] = ['at' => basename((string) $datei) . ':' . $t[$i][2], 'title' => trim($args[$calls[$t[$i][1]]] ?? '')];
        }
    }

    return $found;
}

foreach (ONE_WORD_CEILING as $modul => $decke) {
    $repo = dirname(__DIR__, 3) . '/' . $modul;

    if (! is_dir($repo . '/src')) {
        echo "  --   {$modul}: nicht neben dem Modeller — nicht geprüft\n";
        continue;
    }

    $titel  = dialogTitles($repo);
    $ohne   = array_filter($titel, static fn (array $c): bool => preg_match('/^(\'\'|""|)$/', $c['title']) === 1);
    $einWort = array_filter($titel, static fn (array $c): bool => preg_match('/^(?:esc_html)?__\(\s*[\'"]([^\'"\s%]+)[\'"]/u', $c['title']) === 1);
    $say($ohne === [], sprintf('%s: %d Dialoge, keiner ohne Titel%s', $modul, count($titel), $ohne === [] ? '' : ' — ' . implode(', ', array_column($ohne, 'at'))));
    $say(count($einWort) <= $decke, sprintf('%s: %d Titel ohne Gegenstand (Decke %d)%s', $modul, count($einWort), $decke, $einWort === [] ? '' : ' — ' . implode(', ', array_column($einWort, 'at'))));

    if (count($einWort) < $decke) {
        echo "       ↓ gesunken: Decke in ONE_WORD_CEILING auf " . count($einWort) . " senken\n";
    }
}

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
