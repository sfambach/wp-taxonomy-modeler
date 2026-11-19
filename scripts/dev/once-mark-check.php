<?php declare(strict_types=1);

/**
 * Ein Klick wirkt einmal (D-1434): eine zweite Anfrage mit derselben Einmal-Marke legt nichts mehr an und geht, wohin die erste ging.
 *
 * ⚠️ *Sein Wort 2026-11-18 07:53: «wenn man dann zweimal Buttons drückt, dass die Aktion zum Beispiel zweimal Platz anlegt».* Geprüft
 * wird der Weg jeder admin-post-Anfrage (`OnceMark::enter` vor dem Handler, `leave` an der Umleitung, `giveBack` bei `ListPage::fail`),
 * mit einer Arbeit, die einen Knoten anlegt.
 *
 * Gegenprobe: ohne Marke und mit zwei verschiedenen Marken legt dieselbe Arbeit zweimal an — die Prüfung sieht also, was sie verhindert.
 * Gleichzeitige Anfragen prüft sie nicht (ein Prozess); dafür steht der Anspruch als ein `INSERT IGNORE` auf den Namen.
 *
 * Usage: php scripts/dev/once-mark-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1434
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Admin\OnceMark;
use Taxmod\WordPress\Persistence\{SeededFrameworkNodes, WpdbChangelog, WpdbNodeRepository, WpdbRelationRepository};
use Taxmod\WordPress\SystemClock;

register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$fw        = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $fw, $log);
$wiese     = $editor->createNode('__om Wiese', $fw->rootOf(Branch::Model)->id);

$failed = 0;
$say    = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
    $failed += $ok ? 0 : 1;
};

$kinder = static fn (): int => count($editor->childrenOf($wiese->id));
$anlegen = static function () use ($editor, $wiese): string {
    $platz = $editor->createNode('__om Platz', $wiese->id);

    return admin_url('admin.php?page=taxmod&taxmod_node=' . $platz->id);
};
// Eine admin-post-Anfrage, wie `OnceMark::guard` sie führt: erst die Marke, dann der Handler, dann die Umleitung.
$anfrage = static function (?string $marke, callable $arbeit): string {
    $_POST   = $marke === null ? [] : [OnceMark::FIELD => $marke];
    $frueher = OnceMark::enter();

    if ($frueher !== null) {
        $_POST = [];

        return $frueher;
    }

    $wohin = OnceMark::leave($arbeit());
    $_POST = [];

    return $wohin;
};
$zweimal = static fn (?string $erste, ?string $zweite): array => [$anfrage($erste, $anlegen), $anfrage($zweite, $anlegen)];

echo "\n== zweimal dieselbe Marke: einmal angelegt ==\n";
$vorher = $kinder();
$marke  = wp_generate_uuid4();
[$a, $b] = $zweimal($marke, $marke);
$say($kinder() === $vorher + 1, sprintf('ein Platz, nicht zwei (%d)', $kinder() - $vorher));
$say($a !== '' && $b === $a, 'die zweite Anfrage geht, wohin die erste ging');

echo "\n== Gegenprobe ==\n";
$vorher = $kinder();
$zweimal(null, null);
$say($kinder() === $vorher + 2, 'ohne Marke legt dieselbe Arbeit zweimal an (alter Weg, eine Anfrage ohne Skript)');
$vorher = $kinder();
$zweimal(wp_generate_uuid4(), wp_generate_uuid4());
$say($kinder() === $vorher + 2, 'zwei Formulare, zwei Marken: zweimal');

echo "\n== ein gescheiterter Lauf gibt die Marke zurück ==\n";
$vorher = $kinder();
$marke  = wp_generate_uuid4();
$_POST  = [OnceMark::FIELD => $marke];
$say(OnceMark::enter() === null, 'der erste Versuch darf');
OnceMark::giveBack(); // was ListPage::fail tut, bevor es zurückgeht
$anfrage($marke, $anlegen);
$say($kinder() === $vorher + 1, 'derselbe Versuch noch einmal wirkt');
$say(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/ListPage.php'), 'OnceMark::giveBack();'), 'ListPage::fail gibt die Marke zurück');

echo "\n== wer auf einen gescheiterten Lauf wartet, wartet nicht weiter ==\n";
// Zwei Anfragen im selben Augenblick gibt es in einem Prozess nicht: geprüft wird die Stelle, an der die zweite wartet.
$warten = new ReflectionMethod(OnceMark::class, 'awaitFirst');
$vorher = $kinder();
$marke  = wp_generate_uuid4();
$_POST  = [OnceMark::FIELD => $marke];
OnceMark::enter();
OnceMark::giveBack();
$_POST  = [];
$start  = microtime(true);
$wohin  = $warten->invoke(null, $marke);
$say($wohin === '' && microtime(true) - $start < 1.0, sprintf('die Marke ist zurückgegeben: sofort zurück zur Seite (%.2f s)', microtime(true) - $start));
$say($kinder() === $vorher, 'und ohne zweiten Akt');
$marke  = wp_generate_uuid4();
$ziel   = $anfrage($marke, $anlegen);
$say($warten->invoke(null, $marke) === $ziel, 'Gegenprobe: eine erledigte Marke führt, wohin die erste ging');

echo "\n== ein Download gibt die Marke zurück, eine Seite nicht ==\n";
$datei = new ReflectionMethod(OnceMark::class, 'isDownload');
$say($datei->invoke(null, ['Content-Type: application/pdf', 'Content-Disposition: inline; filename="label.pdf"']) === true, 'eine PDF-Antwort ist ein Download');
$say($datei->invoke(null, ['Content-Type: application/json; charset=utf-8', 'content-disposition: attachment; filename="x.json"']) === true, 'ein Anhang ist ein Download');
$say($datei->invoke(null, ['Content-Type: application/pdf']) === true, 'eine PDF-Antwort ohne weitere Angabe auch');
$say($datei->invoke(null, ['Content-Type: text/html; charset=UTF-8', 'Cache-Control: no-cache']) === false, 'eine Seite ist keiner');
$say($datei->invoke(null, ['Content-Type: application/json; charset=utf-8']) === false, 'eine Antwort an das Skript ist keiner');
$say($datei->invoke(null, []) === false, 'eine Antwort ohne Angabe ist keiner');
$quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/OnceMark.php');
$say(preg_match('/if \(self::isDownload\(headers_list\(\)\)\) \{\s+self::giveBack\(\);/', $quelle) === 1, 'am Ende der Anfrage gibt ein Download die Marke zurück');

echo "\n== eine Marke gehört dem, der sie schickt ==\n";
$marke = wp_generate_uuid4();
$anfrage($marke, $anlegen);
$vorher = $kinder();
$andere = get_users(['exclude' => [1], 'number' => 1, 'fields' => 'ID']);

if ($andere === []) {
    $andere = [wp_insert_user(['user_login' => '__om_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password()])];
}

wp_set_current_user((int) $andere[0]);
$anfrage($marke, $anlegen);
wp_set_current_user(1);
$say($kinder() === $vorher + 1, 'dieselbe Marke eines anderen Menschen wirkt für ihn');

echo "\n== eine fremde Marke wird nicht genommen ==\n";
$_POST = [OnceMark::FIELD => "x'; DROP"];
$say(OnceMark::ofRequest() === null, 'nur Buchstaben, Ziffern und Striche');
$_POST = [];

echo "\n== jede admin-post-Anfrage geht durch die Marke ==\n";
$say(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Plugin.php'), "add_action('admin_init', [\\Taxmod\\WordPress\\Admin\\OnceMark::class, 'guard'], 0)"), 'vor jedem Handler, auch dem der Knotenseite');

echo "\n== das Skript sendet nicht noch einmal und sperrt den Knopf ==\n";
$skript = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/live.js');
$say(str_contains($skript, "form.getAttribute( 'aria-busy' ) === 'true'") && str_contains($skript, 'event.stopImmediatePropagation()'), 'ein Formular, das gerade geht, geht nicht noch einmal');
$say(str_contains($skript, 'submitter.disabled = true'), 'sein Knopf ist gesperrt, solange es geht');
$say(str_contains($skript, "field.name = '_taxmod_once'") && str_contains($skript, "OnceMark::FIELD") === false, 'jedes gesendete Formular trägt die Marke');
$say(OnceMark::FIELD === '_taxmod_once', 'Skript und Server nennen dasselbe Feld');
// Ein Server, der länger braucht als die Sperre, fängt den zweiten Klick an derselben Marke: eine neue gibt es nur im anderen Fenster.
$say(str_contains($skript, 'unlock( form, elsewhere );') && ! str_contains($skript, "unlock( form, true );\n\t\t\t\t}, 8000 );"), 'nach der Sperre behält das Formular seine Marke, außer es ging in ein anderes Fenster');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
