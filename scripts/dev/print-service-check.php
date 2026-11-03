<?php declare(strict_types=1);

/**
 * The print service at home fetches jobs with its token, answers them, and is woken without polling WordPress.
 *
 *     php scripts/dev/print-service-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-1113](../../docs/NewConcept/90-decision-log.md)** stage 2 of [D-1100](../../docs/NewConcept/90-decision-log.md):
 * *«lass uns mit stufe 2 weitermachen»* — pull, claim, PDF, result ([D-1032](../../docs/NewConcept/90-decision-log.md)),
 * cancel and print again undo each other.
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
\Taxmod\WordPress\Printing\PrintJobSchema::upgrade();
// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Printing\LabelContent;
use Taxmod\Core\Printing\LabelFormat;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Printing\PrintJobs;
use Taxmod\WordPress\Printing\PrintJobsScreen;
use Taxmod\WordPress\Printing\PrintRest;
use Taxmod\WordPress\Printing\PrintService;
use Taxmod\WordPress\Printing\PrintTab;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$vorher = get_option(PrintService::OPTION, null);
$wakeVorher = PrintService::settings()['wake'];
do_action('rest_api_init');
$rufe = static function (string $route, array $body, string $token): WP_REST_Response {
    $request = new WP_REST_Request('POST', '/' . PrintRest::ROUTES . $route);
    $request->set_header(PrintRest::TOKEN_HEADER, $token);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body((string) wp_json_encode($body));

    return rest_do_request($request);
};

$pruefe($rufe('/print/pull', [], 'irgendwas')->get_status() === 401, 'without access a pull is refused');

$token = PrintService::renewAccess();
$pruefe(PrintService::isSetUp() && PrintService::settings()['token_hash'] !== $token, 'a new access stores the hash, not the token');
$pruefe($rufe('/print/pull', [], 'falsch')->get_status() === 401, 'a wrong token is refused');

$jobs = new PrintJobs();
$datei = trailingslashit(wp_upload_dir()['basedir']) . 'taxmod-print/' . PrintService::settings()['wake'] . '.txt';
$zaehler = (int) @file_get_contents($datei);
$id = $jobs->queue(LabelFormat::byKey('holder62'), new LabelContent('O-4711', 'Prüffach', 'print-service-check'), true);
$pruefe((int) @file_get_contents($datei) === $zaehler + 1, 'a new job raises the counter in the wake file');
$pruefe(str_starts_with(PrintService::wakeUrl(), wp_upload_dir()['baseurl']), 'the wake file is a static file under uploads');

$antwort = $rufe('/print/pull', ['agent' => 'check 1.0', 'printers' => ['62 mm: QL-810W_take']], $token);
$geholt = array_values(array_filter((array) ($antwort->get_data()['jobs'] ?? []), static fn (array $j): bool => $j['id'] === $id));
$pruefe($antwort->get_status() === 200 && count($geholt) === 1, 'the pull hands over the waiting job');
$pruefe(count($geholt) === 1 && str_starts_with((string) base64_decode($geholt[0]['pdf']), '%PDF-') && $geholt[0]['roll'] === 62.0 && $geholt[0]['test'] === true, 'with its PDF, roll width and test mark');
$pruefe(PrintService::settings()['agent'] === 'check 1.0' && PrintService::settings()['printers'] === ['62 mm: QL-810W_take'], 'the pull tells WordPress the service is alive and which printers it serves');

$zweiter = $rufe('/print/pull', [], $token)->get_data()['jobs'] ?? [];
$pruefe(array_filter($zweiter, static fn (array $j): bool => $j['id'] === $id) === [], 'a claimed job is not handed over twice');

$pruefe($rufe('/print/jobs/' . $id . '/result', ['ok' => true, 'message' => 'request id is QL-1'], $token)->get_status() === 200, 'the service answers the job');
$zeile = $jobs->latest(1)[0];
$pruefe($zeile['status'] === 'printed' && $zeile['message'] === 'request id is QL-1', 'the job is printed, with the message of CUPS');
$pruefe($rufe('/print/jobs/' . $id . '/result', ['ok' => false], $token)->get_status() === 409, 'a second answer is refused');

$pruefe($jobs->queueAgain([$id]) === 1 && $jobs->latest(1)[0]['status'] === 'queued', '«Print again» puts a printed job back into the queue');
$pruefe($jobs->cancel([$id]) === 1 && $jobs->latest(1)[0]['status'] === 'cancelled', '«Cancel» takes a waiting job out');
$pruefe($jobs->queueAgain([$id]) === 1, '… and «Print again» undoes the cancel');
$pruefe($jobs->cancel([$id]) === 1 && $jobs->cancel([$id]) === 0, 'a cancelled job is not cancelled twice');

$etiketten = array_values(array_filter((array) apply_filters(PrintTab::LOGISTICS_FILTER, []), static fn (array $p): bool => ($p['slug'] ?? '') === PrintTab::LOGISTICS_SLUG));
$pruefe(str_contains((string) ($etiketten[0]['render'])(), 'value="test:holder62"'), 'with access set up, every format under Logistik › Druck › Etiketten has «Test via print service»');
$_GET['tab'] = PrintTab::SLUG;
$seite = (new SettingsScreen())->render();
$pruefe(str_contains($seite, 'How to connect') && str_contains($seite, 'taxmod_print_agent.py') && str_contains($seite, 'enable-linger'), 'the tab has the setup guide in its box (D-1116)');
$pruefe(str_contains($seite, 'Renew access') && str_contains($seite, 'Delete access data') && str_contains($seite, 'Test connection'), 'the Configuration tab has Access like the API modules: renew in a dialog, «Test connection», «Delete access data» (D-1117)');
$pruefe(str_contains(PrintTab::status(), 'heard of'), '«Test connection» says when the service was last heard of');
PrintTab::act([PrintTab::DO => 'test:roll62']);
$pruefe($jobs->latest(1)[0]['format'] === 'roll62' && $jobs->latest(1)[0]['is_test'] === '1', 'the test button queues a test job');

$liste = (new PrintJobsScreen($jobs))->render();
$pruefe(str_contains($liste, 'taxmod_print_jobs_cancel') && str_contains($liste, 'taxmod_print_jobs_again'), 'the job list has the bulk actions «Cancel» and «Print again»');
$pruefe(has_action('admin_post_taxmod_print_jobs_cancel') !== false && has_action('admin_post_' . PrintTab::ACTION) !== false, 'the list and the page under Logistik have their admin-post actions');
do_action(\Taxmod\WordPress\Plugin::STYLE_ACTION);
$pruefe(wp_style_is('taxmod-admin', 'enqueued'), 'a page in the logistics frame can ask for the modeller\'s stylesheet, so its dialogs open as dialogs');
$logistik = array_values(array_filter((array) apply_filters(PrintTab::LOGISTICS_FILTER, []), static fn (array $p): bool => ($p['area'] ?? '') === 'print'));
$pruefe(array_column($logistik, 'slug') === [PrintJobsScreen::PAGE, PrintTab::LOGISTICS_SLUG], 'Logistik › Druck holds «Aufträge», then «Etiketten» (D-1115)');

PrintService::revokeAccess();
$pruefe($rufe('/print/pull', [], $token)->get_status() === 401 && ! is_file($datei), 'a removed access stops the service and its wake file');

// The option and the wake file are not in the bracket: put them back as they were.
if ($vorher === null) {
    delete_option(PrintService::OPTION);
} else {
    update_option(PrintService::OPTION, $vorher, false);
    if ($wakeVorher !== '') {
        PrintService::wake();
    }
}

echo $fehler === 0 ? "print-service: all good\n" : "print-service: {$fehler} failure(s)\n";
exit($fehler === 0 ? 0 : 1);
