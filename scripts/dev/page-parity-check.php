<?php declare(strict_types=1);

/**
 * Seitenparität (A13 → A22, D-636): was die Seite zeigt, steht in der Beschreibung — und was die Beschreibung sagt, zeigt die Seite.
 *
 * ```mermaid
 * flowchart LR
 *   S["Modeller-Seite im Prozess"] --> R["Aufnahme: jedes Stück, das Markup::of zeichnet"]
 *   R --> D["Folge aus toArray (Wanderung)"]
 *   R --> H["Folge aus dem gezeichneten HTML"]
 *   D <-->|beide Richtungen, Reihenfolge| H
 * ```
 *
 * ⚠️ **Der zweite Zeichner ist generisch** (Architektur 18.11.): *eine Wanderung über `toArray` gibt Texte, Namen und Werte in
 * Reihenfolge aus — kein Mini-Zeichner je Beschreibungsart.* Verglichen wird je gezeichnetem Stück, denn die Seite selbst ist
 * Rand (`CD-1`): Kopf, Baumtabelle, Formularrahmen schreibt der Rand, nicht der Kern.
 *
 * `toArray` schreibt in **Lesereihenfolge** (A22): die Köpfe vor den Zeilen, der Hinweis neben seinem Namen. Was der Zeichner
 * abschaltet, weil die Beschreibung es sagt, steht bei {@see switchedOff()}. Die übrigen benannten Ausnahmen — jede mit ihrem Grund:
 *
 * ```text
 * CONTRACT
 * Zeichen        ein Text ohne Buchstaben und Ziffern (× ▸ ▾ 🔍) ist Gestalt des Zeichners wie ein Symbol
 * Codes          ein Wert aus Kleinbuchstaben-Bezeichnern (line, locked, candidate) ist ein Fakt, den der Zeichner in Gestalt übersetzt
 * Wiederholung   ein Wort, das der Zeichner ein zweites Mal zeigt (Ast im Baum und als Gruppe), ist kein neues Wissen
 * Markup-Wert    ein Feldwert, der wie Markup aussieht, steht als Ganzes da
 * Vorlagenrahmen ListPage::page — Überschrift, Version, Meldungen, Abschnitte; er ist kein Stück, geprüft wird nur: genau einer
 * ```
 *
 * ⚠️ *Gegenprobe am 18.11. (drei Eingriffe in den Rand-Zeichner, danach zurückgesetzt): ein selbst gewusstes Wort im Tabellenkopf,
 * umgekehrte Zeilen, ein weggelassener Hinweis — alle drei rot.*
 *
 * Usage: php scripts/dev/page-parity-check.php
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

wp_set_current_user(1);

use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\Drawer;
use Taxmod\WordPress\Draw\EdgeDrawer;
use Taxmod\WordPress\Draw\Markup;

/** Zeichnet wie der Rand und merkt sich jedes Stück mit seinem Markup. */
final class RecordingDrawer implements Drawer
{
    /** @var list<array{0: Describes, 1: string}> */
    public array $seen = [];

    private readonly EdgeDrawer $edge;

    public function __construct()
    {
        $this->edge = new EdgeDrawer();
    }

    public function draw(Describes $piece): string
    {
        $html         = $this->edge->draw($piece);
        $this->seen[] = [$piece, $html];

        return $html;
    }
}

/** Texte und Attributwerte des Markups, in Reihenfolge; `t` ein sichtbarer Text, `a` ein Attributwert. @return list<array{0: string, 1: string}> */
function drawnSequence(string $html): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
    libxml_clear_errors();
    $out  = [];
    $walk = static function (DOMNode $node) use (&$walk, &$out): void {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = trim($child->textContent);

                if ($text !== '') {
                    $out[] = ['t', $text];
                }
            } elseif ($child instanceof DOMElement) {
                foreach ($child->attributes as $attribute) {
                    if ($attribute->value !== '') {
                        $out[] = ['a', $attribute->value];
                    }
                }

                $walk($child);
            }
        }
    };
    $walk($dom->getElementsByTagName('body')->item(0));

    return $out;
}

/**
 * Ein Wort der Beschreibung: Text, Pfad, die umgebenden Maps (innen zuletzt) und die Listen, in denen es steht, mit seiner Stelle.
 */
final class Said
{
    /**
     * @param list<array<array-key, mixed>>  $around
     * @param list<array{0: int, 1: int, 2: string}> $lists Liste (laufende Nummer) → Stelle darin, Pfad der Liste
     * @param int                            $table die Tabelle, in der es steht (laufende Nummer; 0: keine)
     * @param bool                           $whole ein Markup-Text als Ganzes: nur für den Abgleich der Seite, gezeichnet wird er zerlegt
     */
    public function __construct(public readonly string $text, public readonly string $path, public readonly array $around, public readonly array $lists, public readonly bool $whole = false, public readonly int $table = 0)
    {
    }
}

/**
 * Die Wanderung über die Beschreibung: jede Zeichenkette und Zahl in Reihenfolge.
 *
 * ⚠️ *Was der Rand noch fertig hereinreicht (A21), ist Markup in der Beschreibung — es wird gelesen wie gezeichnetes, und als Ganzes
 * dazu, denn ein Feldwert darf selbst wie Markup aussehen (ein Gutenberg-Inhalt).*
 *
 * @param list<Said>                    $out
 * @param list<array<array-key, mixed>> $around
 * @param list<array{0: int, 1: int}>   $lists
 */
function describedSequence(mixed $value, array &$out, string $path = '', array $around = [], array $lists = [], int $table = 0): void
{
    static $listNumber = 0;
    static $tableNumber = 0;

    if (is_array($value)) {
        $first  = array_key_first($value);
        $isList = array_is_list($value);
        $head   = is_string($first) ? $first : '';
        $here   = $isList ? $around : [...$around, $value];
        $number = $isList ? ++$listNumber : 0;
        $table  = $head === 'table' ? ++$tableNumber : $table;

        foreach ($value as $key => $inner) {
            describedSequence(
                $inner,
                $out,
                $path . '/' . $head . (is_string($key) && $key !== $head ? '.' . $key : ''),
                $here,
                $isList ? [...$lists, [$number, (int) $key, $path]] : $lists,
                $table
            );
        }

        return;
    }

    if (is_int($value) || is_float($value)) {
        $out[] = new Said((string) $value, $path, $around, $lists, false, $table);

        return;
    }

    if (! is_string($value) || trim($value) === '') {
        return;
    }

    $out[] = new Said(trim($value), $path, $around, $lists, str_contains($value, '<'), $table);

    if (str_contains($value, '<')) {
        foreach (drawnSequence($value) as [, $text]) {
            $out[] = new Said($text, $path . '<markup>', $around, $lists, false, $table);
        }
    }
}

function isSign(string $text): bool
{
    return preg_match('/[\p{L}\p{N}]/u', $text) !== 1;
}

function isCode(string $text): bool
{
    return preg_match('/^[a-z][a-z0-9_-]*$/', $text) === 1;
}

/** Die Worte eines Texts ohne Zeichen: «(296)» ist die Zahl 296. */
function words(string $text): string
{
    return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
}

/**
 * Die Schalter der Beschreibung: ein Fakt, der einen Teil abschaltet — jeder Zeichner, auch ein zweiter, lässt ihn dann weg.
 *
 * ```text
 * CONTRACT
 * control.title      available = false              ein gesperrter Knopf zeigt keinen Titel (D-869)
 * lead/column_heads  with_heads = false             die Tabelle zeichnet ohne Köpfe
 * column_heads[i]    drop_empty, Spalte i ungezeigt eine leere Spalte fällt weg (D-954) — geprüft: auch keine ihrer Zellen zeigt etwas
 * setting.undrawn    control gesetzt                das Ersatzwort steht nur, wo kein Steuerelement gezeichnet werden konnte
 * inherited_word     inherited = []                 das Wort steht an jedem geerbten Eintrag
 * open_word          nicht taken oder ohne href     «öffnen» nur für einen gewählten Satz mit Adresse
 * labels.action      form_id gesetzt                das Formular steht anderswo, die Felder nennen es
 * search_word        browser = null                 das Wort beschriftet den Öffner der Suche
 * compact.gathered   empty, kein Teil zeigt etwas   die Zeile steht als leerer Wert da, ihre Hinweise mit ihr
 * shared_body.kept   —                              ein gemeinsamer Körper steht einmal am Seitenende; geprüft gegen die ganze Seite
 * ```
 *
 * @param array<array-key, mixed> $own die innerste Map
 */
function switchedOff(Said $said, string $drawnBlob, array $described): bool
{
    $own  = $said->around[count($said->around) - 1] ?? [];
    $path = $said->path;
    $map  = static function (string $key) use ($said): ?array {
        for ($i = count($said->around) - 1; $i >= 0; $i--) {
            if (array_key_first($said->around[$i]) === $key) {
                return $said->around[$i];
            }
        }

        return null;
    };

    if (str_ends_with($path, 'control.title') && ($own['available'] ?? true) === false) {
        return true;
    }

    if (preg_match('#/table/lead_heads(\.column_heads)?/#', $path) === 1) {
        $table = $map('table');

        if ($table !== null && ($table['with_heads'] ?? true) === false) {
            return true;
        }

        if ($table !== null && ($table['drop_empty'] ?? false) && str_contains($path, '.column_heads')) {
            $column = columnOf($said, '.column_heads');
            $cells  = array_filter($described, static fn (Said $d): bool => $d->table === $said->table && columnOf($d, '.cells') === $column
                && ! isCode($d->text) && ! isSign($d->text) && ! str_ends_with($d->path, 'hint'));

            return array_filter($cells, static fn (Said $d): bool => str_contains($drawnBlob, $d->text)) === [];
        }
    }

    return match (true) {
        str_ends_with($path, 'setting.undrawn')    => ($own['control'] ?? null) !== null,
        str_ends_with($path, '.inherited_word')    => ($own['inherited'] ?? []) === [],
        str_ends_with($path, '.open_word')         => ! (($own['taken'] ?? false) && ($own['href'] ?? '') !== ''),
        str_ends_with($path, 'labels.action')      => ($own['form'] ?? '') !== '',
        str_ends_with($path, '.search_word')       => array_key_exists('browser', $own) && $own['browser'] === null,
        str_ends_with($path, 'compact.gathered/hint') => ($map('compact')['empty'] ?? false) === true && ! showsAnything(array_column($map('compact')['compact'] ?? [], 'field')),
        default                                     => false,
    };
}

/** Whether any word stands in these parts — a code is a fact, not a shown word. @param array<array-key, mixed> $parts */
function showsAnything(array $parts): bool
{
    $found = false;
    array_walk_recursive($parts, static function (mixed $v) use (&$found): void {
        $found = $found || (is_string($v) && trim($v) !== '' && ! isCode(trim($v)));
    });

    return $found;
}

/** The position in the innermost list whose path ends so; -1: none. */
function columnOf(Said $said, string $ending): int
{
    foreach (array_reverse($said->lists) as [, $position, $path]) {
        if (str_ends_with($path, $ending)) {
            return $position;
        }
    }

    return -1;
}

/** @return list<string> */
function parityOf(Describes $piece, string $html, string $page): array
{
    $described = [];
    describedSequence($piece->toArray(), $described);
    $drawn     = drawnSequence($html);
    $drawnBlob = implode("\x1f", array_column($drawn, 1));
    $pageBlob  = html_entity_decode($page);
    $faults    = [];

    // Beschreibung ⊆ Seite: nichts geht verloren.
    foreach ($described as $said) {
        if ($said->whole || isCode($said->text) || isSign($said->text) || str_contains($drawnBlob, $said->text) || switchedOff($said, $drawnBlob, $described)) {
            continue;
        }

        // Ein Feldwert, der wie Markup aussieht, steht als Ganzes da, nicht zerlegt.
        if (str_ends_with($said->path, '<markup>') && array_filter($described, static fn (Said $d): bool => $d->whole && str_contains(html_entity_decode($d->text), $said->text) && str_contains($drawnBlob, $d->text)) !== []) {
            continue;
        }

        if (str_contains($said->path, 'shared_body.kept') && str_contains($pageBlob, $said->text)) {
            continue;
        }

        $faults[] = 'beschrieben, nicht gezeichnet: «' . $said->text . '» @' . $said->path;
    }

    // Seite ⊆ Beschreibung, in Reihenfolge: der Zeichner weiss nichts selbst. Die längste gemeinsame Folge ordnet die gezeichneten Texte
    // den Worten der Beschreibung zu; was ausserhalb bleibt und doch beschrieben ist, steht an anderer Stelle. `toArray` schreibt in
    // Lesereihenfolge (A22).
    $kept  = array_values($described);
    $words = array_map(static fn (Said $d): string => words($d->text), $kept);
    $texts = [];

    foreach ($drawn as [$kind, $text]) {
        if ($kind === 't' && ! isSign($text)) {
            $texts[] = $text;
        }
    }

    $inOrder = alignedOrder(array_map('words', $texts), $words);

    $shown = [];

    foreach ($inOrder as $i => $_) {
        $shown[words($texts[$i])] = true;
    }

    foreach ($texts as $i => $text) {
        // Ein Wort, das der Zeichner wiederholt (ein Ast im Baum und noch einmal als Gruppe), ist kein neues Wissen.
        if (isset($inOrder[$i]) || isset($shown[words($text)])) {
            continue;
        }

        $wanted = words($text);
        $where  = array_key_first(array_filter($words, static fn (string $w): bool => fits($w, $wanted)));
        $faults[] = $where === null
            ? 'gezeichnet, nicht beschrieben: «' . $text . '»'
            : 'gezeichnet in anderer Reihenfolge: «' . $text . '» @' . $kept[$where]->path;
    }

    return $faults;
}

/** Ein gezeichneter Text passt auf ein Wort der Beschreibung: gleich, ein Teil davon, oder der Zeichner setzt es in seinen Text («Name (3)»). */
function fits(string $described, string $drawn): bool
{
    return $described !== '' && ($described === $drawn || str_contains($described, $drawn) || (mb_strlen($described) > 1 && str_contains($drawn, $described)));
}

/**
 * Welche gezeichneten Texte in der Reihenfolge der Beschreibung stehen: die längste gemeinsame Folge.
 *
 * @param list<string> $drawn
 * @param list<string> $described
 * @return array<int, true> Index im Gezeichneten → in Reihenfolge
 */
function alignedOrder(array $drawn, array $described): array
{
    $n    = count($drawn);
    $m    = count($described);
    $rows = [array_fill(0, $m + 1, 0)];

    for ($i = 1; $i <= $n; $i++) {
        $row  = [0];
        $prev = $rows[$i - 1];

        for ($j = 1; $j <= $m; $j++) {
            $row[$j] = fits($described[$j - 1], $drawn[$i - 1]) ? $prev[$j - 1] + 1 : max($prev[$j], $row[$j - 1]);
        }

        $rows[$i] = $row;
    }

    $hit = [];

    for ($i = $n, $j = $m; $i > 0 && $j > 0;) {
        if (fits($described[$j - 1], $drawn[$i - 1]) && $rows[$i][$j] === $rows[$i - 1][$j - 1] + 1) {
            $hit[$i - 1] = true;
            $i--;
            $j--;
        } elseif ($rows[$i - 1][$j] >= $rows[$i][$j - 1]) {
            $i--;
        } else {
            $j--;
        }
    }

    return $hit;
}

/** @return list<array{0: string, 1: array<string, string>}> */
function pagesToVisit(): array
{
    global $wpdb;

    $pages = [['list', ['page' => 'taxmod']]];
    $byRecords = $wpdb->get_col("SELECT node_id FROM {$wpdb->prefix}taxmod_node_records GROUP BY node_id ORDER BY COUNT(*) DESC, node_id LIMIT 3");
    $byFields  = $wpdb->get_col("SELECT from_node_id FROM {$wpdb->prefix}taxmod_relations GROUP BY from_node_id ORDER BY COUNT(*) DESC, from_node_id LIMIT 3");

    foreach (array_unique([...$byRecords, ...$byFields]) as $node) {
        $pages[] = ['node ' . $node, ['page' => 'taxmod', 'taxmod_node' => (string) $node]];
    }

    return $pages;
}

$recorder = new RecordingDrawer();
(new ReflectionProperty(Markup::class, 'drawer'))->setValue(null, $recorder);

$plugin = (new ReflectionClass(\Taxmod\WordPress\Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\Taxmod\WordPress\Plugin::class, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$red = 0;

foreach (pagesToVisit() as [$name, $query]) {
    $_GET            = $query;
    $recorder->seen  = [];

    try {
        $html = $plugin->screen()->render();
    } catch (\Throwable $failure) {
        echo "RED  {$name}: zeichnet nicht — " . $failure->getMessage() . "\n";
        $red++;
        continue;
    }

    $frames = preg_match_all('/<div class="wrap taxmod-page"/', $html);
    $faults = $frames === 1 ? [] : ["{$frames} Vorlagenrahmen statt einem"];

    foreach ($recorder->seen as [$piece, $markup]) {
        foreach (parityOf($piece, $markup, $html) as $fault) {
            if (($probe = getenv('PARITY_PROBE')) && str_contains($fault, $probe)) {
                file_put_contents('/tmp/claude-0/pp/probe.html', $markup);
                file_put_contents('/tmp/claude-0/pp/probe.json', json_encode($piece->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            $faults[] = (new ReflectionClass($piece))->getShortName() . ': ' . $fault;
        }
    }

    $counted = array_count_values($faults);
    arsort($counted);

    if ($counted === []) {
        echo "ok   {$name}: " . count($recorder->seen) . " Stücke\n";
        continue;
    }

    $red++;
    echo "RED  {$name}: " . count($recorder->seen) . ' Stücke, ' . count($faults) . " Abweichungen\n";

    foreach (array_slice($counted, 0, 40, true) as $fault => $times) {
        echo "       {$times}× " . mb_substr($fault, 0, 220) . "\n";
    }
}

echo $red === 0 ? "\nAll pages agree with their descriptions.\n" : "\n{$red} page(s) differ.\n";
exit($red === 0 ? 0 : 1);
