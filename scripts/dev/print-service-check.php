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
// D-1296: the modeller draws no label; a job arrives as the labels module queues it, with its finished PDF (D-1294).
\Taxmod\WordPress\Printing\PrintJobSchema::upgrade();
$probePdf = "%PDF-1.4\n%print-service-check\n";
$id = $jobs->queuePdf($probePdf, 'holder62', 'O-4711', 'Prüffach', 1, ['roll' => 62, 'queue' => 'QL-810W_take', 'pause' => 5], true);
$pruefe((int) @file_get_contents($datei) === $zaehler + 1, 'a new job raises the counter in the wake file');
$pruefe(str_starts_with(PrintService::wakeUrl(), wp_upload_dir()['baseurl']), 'the wake file is a static file under uploads');

$antwort = $rufe('/print/pull', ['agent' => 'check 1.0', 'printers' => ['62 mm: QL-810W_take']], $token);
$geholt = array_values(array_filter((array) ($antwort->get_data()['jobs'] ?? []), static fn (array $j): bool => $j['id'] === $id));
$pruefe($antwort->get_status() === 200 && count($geholt) === 1, 'the pull hands over the waiting job');
$pruefe(count($geholt) === 1 && base64_decode($geholt[0]['pdf']) === $probePdf && (float) $geholt[0]['roll'] === 62.0 && $geholt[0]['test'] === true, 'with its PDF, roll width and test mark');
$pruefe(count($geholt) === 1 && ($geholt[0]['pause'] ?? null) === 5 && ($geholt[0]['queue'] ?? '') === 'QL-810W_take', 'D-1333: the printer\'s pause and queue travel with the job');
$pruefe(PrintService::settings()['agent'] === 'check 1.0' && PrintService::settings()['printers'] === ['62 mm: QL-810W_take'], 'the pull tells WordPress the service is alive and which printers it serves');

$zweiter = $rufe('/print/pull', [], $token)->get_data()['jobs'] ?? [];
$pruefe(array_filter($zweiter, static fn (array $j): bool => $j['id'] === $id) === [], 'a claimed job is not handed over twice');

$pruefe(($jobs->statusOf($id)[0] ?? null) === \Taxmod\Core\Printing\PrintJobStatus::Claimed, 'D-1221: the dialog sees a fetched job as not yet printed');
// D-1234: CUPS took it — the job is at the printer, not printed, and what CUPS says while it waits is kept.
$pruefe($rufe('/print/jobs/' . $id . '/progress', ['message' => 'CUPS QL-1'], $token)->get_status() === 200, 'D-1234: the service says CUPS took the job');
$pruefe(($jobs->statusOf($id) ?? []) === [\Taxmod\Core\Printing\PrintJobStatus::Sent, 'CUPS QL-1'], 'D-1234: … and the dialog sees it at the printer, not printed');
$pruefe($rufe('/print/jobs/' . PHP_INT_MAX . '/progress', ['message' => 'x'], $token)->get_status() === 409, 'D-1234: progress for a job not with the service is refused');
$pruefe($rufe('/print/jobs/' . $id . '/result', ['ok' => true, 'message' => 'request id is QL-1'], $token)->get_status() === 200, 'the service answers the job');
$pruefe(($jobs->statusOf($id) ?? []) === [\Taxmod\Core\Printing\PrintJobStatus::Printed, 'request id is QL-1'] && $jobs->statusOf(PHP_INT_MAX) === null, 'D-1221: … and as printed once the service has answered; an unknown job has no state');
$zeile = $jobs->latest(1)[0];
$pruefe($zeile['status'] === 'printed' && $zeile['message'] === 'request id is QL-1', 'the job is printed, with the message of CUPS');
$pruefe($rufe('/print/jobs/' . $id . '/result', ['ok' => false], $token)->get_status() === 409, 'a second answer is refused');

$pruefe($jobs->queueAgain([$id]) === 1 && $jobs->latest(1)[0]['status'] === 'queued', '«Print again» puts a printed job back into the queue');
$pruefe($jobs->cancel([$id]) === 1 && $jobs->latest(1)[0]['status'] === 'cancelled', '«Cancel» takes a waiting job out');
$pruefe($jobs->queueAgain([$id]) === 1, '… and «Print again» undoes the cancel');
$pruefe($jobs->cancel([$id]) === 1 && $jobs->cancel([$id]) === 0, 'a cancelled job is not cancelled twice');

// D-1162: copies travel in one job, so the labels come out together. *The template job, the pages of the PDF and the dialog's
// template choice were drawn by the modeller until D-1296; they are the labels module's now and checked there (PR-9).*
$mehrere = $jobs->queuePdf($probePdf, 'material62', 'O-4712', 'Vorlagenfach', 3, ['roll' => 62], true);
$geholtM = array_values(array_filter((array) ($rufe('/print/pull', [], $token)->get_data()['jobs'] ?? []), static fn (array $j): bool => $j['id'] === $mehrere));
$pruefe(count($geholtM) === 1 && $geholtM[0]['copies'] === 3 && $geholtM[0]['format'] === 'material62', 'three copies are one job, with its format key');
$jobs->finish($mehrere, true, 'check');

$_GET['tab'] = PrintTab::SLUG;
$seite = (new SettingsScreen())->render();
// ⚠️ *Nachgezogen mit [D-1332](../../docs/NewConcept/90-decision-log.md) und [D-1336](../../docs/NewConcept/90-decision-log.md), sichtbar nach `PR-9`
// (Grenzlauf 2026-11-17, D-1427): der Dienst ist seither ein `.deb`-Paket, das sich mit einem Kopplungscode verbindet und mit
// `taxmod-printservice setup` ohne Anmeldung weiterläuft — das Python-Skript und `enable-linger` der Anleitung von D-1116 gibt es nicht
// mehr. Und jeder Rechner hat seinen eigenen Dienst: «Add print service» statt des einen «Renew access» von D-1117.*
$pruefe(str_contains($seite, 'How to connect') && str_contains($seite, '.deb') && str_contains($seite, 'taxmod-printservice setup'), 'the tab has the setup guide in its box (D-1116, the package of D-1332)');
$pruefe(str_contains($seite, 'Add print service') && str_contains($seite, 'Delete access data') && str_contains($seite, 'Test connection'), 'the Configuration tab has Access like the API modules: a service per PC added in a dialog, «Test connection», «Delete access data» (D-1117, D-1336)');
$pruefe(str_contains(PrintTab::status(), 'heard of'), '«Test connection» says when the service was last heard of');
// D-1439: the test prints of the fixed formats moved to the labels module's printer page; the tab offers none (PR-9, moved).
$pruefe(! str_contains($seite, 'test:roll62'), 'D-1439: the Configuration tab has no test print of a fixed format');

// D-1198: the counts per status are kept until a write, and every write forgets them before it returns.
$gezaehlt = $jobs->counts();
// *Seit 0.2.131 (review DB7) ein Eintrag je Bereich und Quelle, die Generation neben den Zahlen — gelesen wie `TileCounts` es schreibt
// (Grenzlauf 2026-11-17, D-1427; vorher der Schlüssel mit der Generation darin).*
$gemerkt = get_transient('taxmod_tiles_' . md5('print_jobs|status'));
$pruefe(is_array($gemerkt) && ($gemerkt['counts'] ?? null) === $gezaehlt && ($gemerkt['generation'] ?? null) === (((array) get_option('taxmod_tile_generation', []))['print_jobs'] ?? 0), 'the counts per status are kept after the first count');
$neu = $jobs->queuePdf($probePdf, 'holder62', 'O-4713', 'Zählfach', 1, ['roll' => 62], true);
$pruefe(($jobs->counts()['queued'] ?? 0) === ($gezaehlt['queued'] ?? 0) + 1, 'a new job is counted at once: queueing forgot the kept counts');
$jobs->cancel([$neu]);
$pruefe(($jobs->counts()['queued'] ?? 0) === ($gezaehlt['queued'] ?? 0) && ($jobs->counts()['cancelled'] ?? 0) === ($gezaehlt['cancelled'] ?? 0) + 1, 'a status change is counted at once: cancelling forgot them too');

$liste = (new PrintJobsScreen($jobs))->render();
$pruefe(str_contains($liste, 'class="taxmod-tiles"'), 'the job list has tiles on top (D-1198)');
$pruefe(str_contains($liste, 'Active') && str_contains($liste, 'Printed') && str_contains($liste, 'Failed') && str_contains($liste, 'status=active'), 'the tiles Active, Printed and Failed filter the list');
$pruefe(str_contains($liste, 'taxmod-page-section'), 'the list stands in the standard page (D-1196)');
$_GET['status'] = 'active';
$aktiv = (new PrintJobsScreen($jobs))->render();
unset($_GET['status']);
$pruefe(! str_contains($aktiv, '>O-4713<'), '«Active» shows only waiting and fetched jobs');
$pruefe(str_contains($liste, 'taxmod_print_jobs_cancel') && str_contains($liste, 'taxmod_print_jobs_again'), 'the job list has the bulk actions «Cancel» and «Print again»');
$pruefe(has_action('admin_post_taxmod_print_jobs_cancel') !== false && has_action('admin_post_' . PrintTab::ACTION) !== false, 'the list and the print settings have their admin-post actions');
do_action(\Taxmod\WordPress\Plugin::STYLE_ACTION);
$pruefe(wp_style_is('taxmod-admin', 'enqueued'), 'a page in the logistics frame can ask for the modeller\'s stylesheet, so its dialogs open as dialogs');
$logistik = array_values(array_filter((array) apply_filters(PrintTab::LOGISTICS_FILTER, []), static fn (array $p): bool => ($p['area'] ?? '') === 'print'));
$eigene  = array_values(array_filter(array_column($logistik, 'slug'), static fn (string $slug): bool => ! str_starts_with($slug, 'taxmod-label-')));
$pruefe($eigene === [PrintJobsScreen::PAGE], 'Logistik › Druck holds «Aufträge» from the modeller; «Etiketten» is the module\'s (D-1115, D-1165)');

// D-1120, D-1230: «Etikett drucken» is the labels module's answer to «taxmod_label_button»; the modeller gives none (D-1296).
$pruefe(! has_filter('taxmod_label_button') || defined('TAXMOD_LABEL_VERSION'), 'D-1296: the modeller answers no «taxmod_label_button» of its own');

// D-1235: the print thread reads the jobs and prints again through abilities.
if (function_exists('wp_get_ability') && wp_get_ability('taxmod/print-jobs') !== null) {
    $gelesen = wp_get_ability('taxmod/print-jobs')->execute(['limit' => 5]);
    $pruefe(is_array($gelesen) && in_array($id, array_column($gelesen['jobs'] ?? [], 'id'), true) && isset($gelesen['service']['last_seen']), 'D-1235: print-jobs lists the newest jobs and when the service was seen');
    $nochmal = wp_get_ability('taxmod/print-job')->execute(['ids' => [$id], 'action' => 'again']);
    $pruefe(($nochmal['moved'] ?? 0) === 1 && ($jobs->statusOf($id)[0] ?? null) === \Taxmod\Core\Printing\PrintJobStatus::Queued, 'D-1235: print-job again puts a printed job back into the queue');
    $jobs->cancel([$id]);
    // D-1239: «print-label» with dry_run and pdf is the labels module's «taxmod-label/print» now (D-1296, PR-9, moved).
    $pruefe(wp_get_ability('taxmod/print-label') === null, 'D-1296: print-label left the modeller');
} else {
    echo "print-service: no Abilities API here, D-1235 not checked\n";
}

// D-1307: finished jobs older than the days set go, waiting ones stay however old; 0 keeps them all.
global $wpdb;
$tabelle = \Taxmod\WordPress\Printing\PrintJobSchema::table();
$alt = gmdate('Y-m-d H:i:s', time() - 9 * DAY_IN_SECONDS);
$jung = gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS);
$lege = static function (string $status, string $erstellt, ?string $ende) use ($wpdb, $tabelle): int {
    $wpdb->insert($tabelle, ['format' => 'x', 'code' => 'M-1', 'title' => '__purge', 'status' => $status, 'created_at' => $erstellt, 'finished_at' => $ende]);

    return (int) $wpdb->insert_id;
};
$altGedruckt = $lege('printed', $alt, $alt);
$altStorniert = $lege('cancelled', $alt, null);
$jungGedruckt = $lege('printed', $alt, $jung);
$altWartend = $lege('queued', $alt, null);
$altGeholt = $lege('claimed', $alt, null);
$da = static fn (int $id): bool => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tabelle} WHERE id = %d", $id)) === 1;
$pruefe($jobs->purge(0) === 0 && $da($altGedruckt), 'D-1307: 0 days keeps every job');
$jobs->purge(7);
$pruefe(! $da($altGedruckt) && ! $da($altStorniert), 'D-1307: a printed and a cancelled job older than 7 days are gone');
$pruefe($da($jungGedruckt) && $da($altWartend) && $da($altGeholt), 'D-1307: a younger finished job and old waiting or claimed jobs stay');
$wpdb->query($wpdb->prepare("DELETE FROM {$tabelle} WHERE id IN (%d, %d, %d)", $jungGedruckt, $altWartend, $altGeholt));
$tageVorher = get_option(\Taxmod\WordPress\Printing\PrintJobRetention::OPTION, null);
PrintTab::act([\Taxmod\WordPress\Printing\PrintJobRetention::OPTION => '12']);
$pruefe(\Taxmod\WordPress\Printing\PrintJobRetention::days() === 12 && str_contains(PrintTab::register([])[PrintTab::SLUG]['render'](), 'value="12"'), 'D-1307: the Printing tab stores and shows the days');
$tageVorher === null ? delete_option(\Taxmod\WordPress\Printing\PrintJobRetention::OPTION) : update_option(\Taxmod\WordPress\Printing\PrintJobRetention::OPTION, $tageVorher, false);
$pruefe(\Taxmod\WordPress\Printing\PrintJobRetention::days() === (int) ($tageVorher ?? 7), 'D-1307: without a setting it is 7 days');

// D-1336: one print service per computer, each with its own token, each fetching only the jobs for its own CUPS queues.
[$kellerKey, $kellerToken] = PrintService::addService('keller');
$pruefe(count(PrintService::settings()['services']) === 2 && $rufe('/print/pull', [], $kellerToken)->get_status() === 200, 'D-1336: a second print service has its own token');
$fuerEarth = $jobs->queuePdf('%PDF-1.4 earth', 'x', 'M-1', '__queue', 1, ['queue' => 'Phomemo_M220L']);
$fuerKeller = $jobs->queuePdf('%PDF-1.4 keller', 'x', 'M-2', '__queue', 1, ['queue' => 'QL-1100']);
$ohneQueue = $jobs->queuePdf('%PDF-1.4 any', 'x', 'M-3', '__queue', 1, []);
$ids = static fn (WP_REST_Response $r): array => array_map(static fn (array $j): int => (int) $j['id'], (array) ($r->get_data()['jobs'] ?? []));
$keller = $ids($rufe('/print/pull', ['agent' => 'taxmod-print-agent 0.5.0', 'host' => 'keller', 'queues' => ['QL-1100', 'QL-810W_take']], $kellerToken));
$pruefe(in_array($fuerKeller, $keller, true) && in_array($ohneQueue, $keller, true) && ! in_array($fuerEarth, $keller, true), 'D-1336: a service gets the jobs for its queues and those for none, not another computer\'s');
$pruefe(($jobs->statusOf($fuerEarth)[0] ?? null) === \Taxmod\Core\Printing\PrintJobStatus::Queued, 'D-1336: the other computer\'s job keeps waiting');
$earth = $ids($rufe('/print/pull', ['agent' => 'taxmod-print-agent 0.5.0', 'host' => 'earth', 'queues' => ['Phomemo_M220L']], $token));
$pruefe($earth === [$fuerEarth], 'D-1336: the computer with that queue fetches it');
$pruefe((string) $wpdb->get_var($wpdb->prepare("SELECT claimed_by FROM {$tabelle} WHERE id = %d", $fuerKeller)) === 'keller', 'D-1336: the job says which service fetched it');
$niemand = $jobs->queuePdf('%PDF-1.4 none', 'x', 'M-4', '__queue', 1, ['queue' => 'Nirgends']);
$liste = (new PrintJobsScreen($jobs))->render();
$pruefe(str_contains($liste, 'no print service for this queue'), 'D-1336: a job for a queue no service reports is marked in the list');
$pruefe(PrintService::removeService($kellerKey) && $rufe('/print/pull', [], $kellerToken)->get_status() === 401 && $rufe('/print/pull', [], $token)->get_status() === 200, 'D-1336: removing one service stops only its token');
$wpdb->query("DELETE FROM {$tabelle} WHERE title = '__queue'");

// D-1351: a print service couples itself with a one-time code instead of a copied token.
[$paarKey] = PrintService::addService('');
$code = (string) PrintService::pairingCode($paarKey);
$pruefe(preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code) === 1, 'D-1351: a pairing code is eight letters, easy to type');
$falsch = $rufe('/print/pair', ['code' => 'AAAA-AAAA'], '');
$pruefe($falsch->get_status() === 403, 'D-1351: a wrong code is refused');
$gepaart = $rufe('/print/pair', ['code' => strtolower($code), 'host' => 'kellerserver'], '');
$neuToken = (string) ($gepaart->get_data()['token'] ?? '');
$pruefe($gepaart->get_status() === 200 && $neuToken !== '' && ($gepaart->get_data()['name'] ?? '') === 'kellerserver', 'D-1351: the right code brings a token and names the service after its host');
$pruefe($rufe('/print/pull', [], $neuToken)->get_status() === 200, 'D-1351: the paired service can pull');
$pruefe($rufe('/print/pair', ['code' => $code], '')->get_status() === 403, 'D-1351: a code works once');
$pauseJob = $jobs->queuePdf('%PDF-1.4 pause', 'x', 'M-5', '__queue', 1, ['queue' => 'Phomemo_M220L', 'pause' => 20]);
$mitPause = array_values(array_filter((array) ($rufe('/print/pull', ['queues' => ['Phomemo_M220L']], $neuToken)->get_data()['jobs'] ?? []), static fn (array $j): bool => $j['id'] === $pauseJob));
$pruefe(($mitPause[0]['pause'] ?? null) === 20, 'D-1333: the printer\'s pause goes with the job to the service');
$wpdb->query("DELETE FROM {$tabelle} WHERE title = '__queue'");
PrintService::removeService($paarKey);
delete_transient('taxmod_print_pair_failed');

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
