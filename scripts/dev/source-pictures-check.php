<?php declare(strict_types=1);
/**
 * Produktbilder einer Quelle gehen beim Übernehmen in die Mediathek, nicht als Link zum Händler (D-1079).
 *
 *     php scripts/dev/source-pictures-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   S["kleiner Server · bild.png"] --> L["intoLibrary"] --> M["media:<Id>"]
 *   M --> Z["zweimal: dieselbe Id"]
 *   F["Adresse ohne Bild"] --> B["bleibt stehen"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

// ⚠️ *Kein Lauf schreibt ins Modell (lib/no-write.php); die Dateien der Mediathek räumt er selbst ab.*
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;
use Taxmod\WordPress\Import\SourcePictures;

$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

$dir = sys_get_temp_dir() . '/taxmod-pictures-' . getmypid();
wp_mkdir_p($dir);
$image = imagecreatetruecolor(4, 4);
imagepng($image, $dir . '/bild.png');
$port = 18000 + getmypid() % 1000;
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
usleep(400000);
// The little server is on this machine; WordPress refuses such addresses unless told otherwise.
add_filter('http_request_host_is_external', '__return_true');
add_filter('http_allowed_safe_ports', static fn (array $ports): array => [...$ports, $port]);

$address = 'http://127.0.0.1:' . $port . '/bild.png?' . getmypid();
$proposal = new OrderProposal('AliExpress', '1', ProposalKind::Ordered, '2026-11-01', 'EUR', [
    new ProposalLine('JST XH', 1, '2.20', null, $address),
    new ProposalLine('Ohne Bild', 1, '1.00'),
    new ProposalLine('Verschwunden', 1, '1.00', null, 'http://127.0.0.1:' . $port . '/weg.png'),
]);
$first = SourcePictures::intoLibrary($proposal);
$id = (int) substr((string) $first->lines[0]->imageUrl, strlen('media:'));
check('das Bild steht als media:<Id> da', str_starts_with((string) $first->lines[0]->imageUrl, 'media:') && $id > 0, (string) $first->lines[0]->imageUrl);
check('die Datei liegt in der Mediathek, mit dem Titel der Position', get_post_type($id) === 'attachment' && get_the_title($id) === 'JST XH');
check('eine Position ohne Bild bleibt ohne', $first->lines[1]->imageUrl === null);
check('ein Bild, das der Händler nicht mehr hat, bleibt als Adresse', str_ends_with((string) $first->lines[2]->imageUrl, '/weg.png'));
$second = SourcePictures::intoLibrary($proposal);
check('dieselbe Adresse wird nicht zweimal geladen', $second->lines[0]->imageUrl === 'media:' . $id);

wp_delete_attachment($id, true);
proc_terminate($server);
@unlink($dir . '/bild.png');
@rmdir($dir);

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
