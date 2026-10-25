<?php declare(strict_types=1);

/**
 * The import inbox files what a source brings, once, and only what the duty table lets it bring.
 *
 *     php scripts/dev/import-inbox-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-953](../../docs/NewConcept/90-decision-log.md), his word:** *«ok zu s3-7»* — one table and one review page for
 * every source, the duty table instead of filters. *A test source registers through `taxmod_import_sources`.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Import\FetchBatch;
use Taxmod\Core\Import\FetchingSource;
use Taxmod\Core\Import\IncomingProposal;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Import\AutoTake;
use Taxmod\WordPress\Import\Duties;
use Taxmod\WordPress\Import\Targets;
use Taxmod\WordPress\Import\Inbox;
use Taxmod\WordPress\Import\InboxSchema;
use Taxmod\WordPress\Import\InboxScreen;
use Taxmod\WordPress\Import\InboxTable;
use Taxmod\WordPress\Import\Sources;

wp_set_current_user(1);
InboxSchema::upgrade();

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$quelle = new class implements FetchingSource {
    public function key(): string { return '__ib'; }
    public function label(): string { return 'Test source'; }
    public function interval(): int { return 0; }
    public function fetch(?string $cursor): FetchBatch
    {
        $line = [new ProposalLine('LM317', 2.0, '0.50')];

        return new FetchBatch([
            new IncomingProposal(new OrderProposal('__Shop', 'A-1', ProposalKind::Ordered, lines: $line, source: '__ib', externalId: 'm1'), ['subject' => 'Ihre Bestellung']),
            new IncomingProposal(new OrderProposal('__Shop', 'A-1', ProposalKind::Shipped, trackingNumbers: ['T1'], source: '__ib', externalId: 'm2')),
            new IncomingProposal(new OrderProposal('__Other', 'B-1', ProposalKind::Ordered, lines: $line, source: '__ib', externalId: 'm3')),
        ], 'cursor-2');
    }
};
add_filter(Sources::FILTER, static fn (array $sources): array => [...$sources, $quelle, 'not a source']);
add_filter(Duties::FILTER, static fn (array $rows): array => $rows + ['__Other' => ['orders' => '__other', 'status' => '__other']]);
// The responsible source must be active for the row to block; register an empty one under that key.
add_filter(Sources::FILTER, static fn (array $sources): array => [...$sources, new class implements \Taxmod\Core\Import\ImportSource {
    public function key(): string { return '__other'; }
    public function label(): string { return 'Other'; }
}]);

$pruefe(array_keys(Sources::all()) === ['__ib', '__other'], 'only import sources are registered, each once');

$inbox = new Inbox();
$pruefe($inbox->run($quelle) === 2, 'two proposals filed, the one another active source is responsible for is not');
$pruefe($inbox->run($quelle) === 0, 'a second run files nothing again');
$pruefe(get_option('taxmod_import_cursor___ib') === 'cursor-2', 'the cursor of the batch is kept');

$gruppen = (new InboxTable())->openGroups();
$eigene = array_values(array_filter($gruppen, static fn (array $rows): bool => $rows[0]['source'] === '__ib'));
$pruefe(count($eigene) === 1 && count($eigene[0]) === 2, 'confirmation and shipping notice of one order are one group');

$seite = (new InboxScreen())->render();
$pruefe(str_contains($seite, '__Shop A-1') && str_contains($seite, 'Test source') && str_contains($seite, 'Ihre Bestellung'), 'the inbox page shows the order once, with its source and evidence');

$zweite = new class implements \Taxmod\Core\Import\SearchingSource, \Taxmod\Core\Import\RestartingSource {
    public bool $neu = false;
    public function key(): string { return '__ib2'; }
    public function label(): string { return 'Second'; }
    public function restart(): void { $this->neu = true; }
    public function search(string $text): FetchBatch
    {
        $kopie = fn (string $id): IncomingProposal => new IncomingProposal(
            new OrderProposal('__Mail', $text, ProposalKind::Ordered, lines: [new ProposalLine('X', 1.0)], source: '__ib2', externalId: $id),
            ['received_at' => '2026-10-21 10:00:00']
        );

        return new FetchBatch([$kopie('orig'), $kopie('forwarded')], null);
    }
};
add_filter(Sources::FILTER, static fn (array $sources): array => [...$sources, $zweite]);
$pruefe($inbox->search($zweite, 'C-9') === 1, 'a forwarded copy (same order, kind and moment, other id) is filed once');
$pruefe($inbox->restart($zweite) === 1 && $zweite->neu, 'a restart forgets the open proposal and lets the source undo its side');

// ⚠️ *Sein Wunsch vom 2026-10-22 (D-970): «das sind alles nachrichten zur gleichen bestellung … wäre schön wenn das nur ein kasten wäre».*
$tisch = new InboxTable();
$spaeter = $tisch->add('__ib', new IncomingProposal(new OrderProposal('__Cart', 'S-2', ProposalKind::Shipped, trackingNumbers: ['T2'], source: '__ib', externalId: 'k2'), ['bundle' => 'cart-1', 'subject' => '__second']), '2026-10-22 10:00:00');
$frueher = $tisch->add('__ib', new IncomingProposal(new OrderProposal('__Cart', 'S-1', ProposalKind::Shipped, trackingNumbers: ['T1'], source: '__ib', externalId: 'k1'), ['bundle' => 'cart-1', 'subject' => '__first']), '2026-10-22 09:00:00');
$seite = (new InboxScreen())->render();
$pruefe(substr_count($seite, '2 orders of one purchase') === 1 && strpos($seite, '__first') < strpos($seite, '__second'), 'sub-orders of one purchase share one box, in the order their mails came');
// ⚠️ *Sein Wunsch vom 2026-10-23 (D-980): «eine box mit allen updates in der reihenfolge … und dann werden alle nacheinander eingespielt».*
$pruefe(substr_count($seite, '__first') === 1 && str_contains($seite, 'S-1 · shipped · tracking T1') && str_contains($seite, 'value="' . InboxScreen::TAKE_BOX . '"') && has_action('admin_post_' . InboxScreen::TAKE_BOX) !== false, 'the box lists every message once, oldest first, and takes them all in order');
$tisch->close($spaeter, \Taxmod\Core\Import\InboxStatus::Discarded);
$tisch->close($frueher, \Taxmod\Core\Import\InboxStatus::Discarded);

// ⚠️ *D-986: ein schon behandelter Vorschlag kann wieder aufgehen, wenn die Quelle Leeres im Modell füllt (sein Wort: «ja bitte preis nachtragen»).*
$pruefe($tisch->closedId('__ib', 'k1') === $frueher && $tisch->closedId('__ib', 'nie') === null, 'the handled row of an external id is found');
$tisch->reopen($frueher, new IncomingProposal(new OrderProposal('__Cart', 'S-1', ProposalKind::Ordered, source: '__ib', externalId: 'k1')));
$pruefe($tisch->openRows([$frueher]) !== [] && $tisch->closedId('__ib', 'k1') === null, 'a reopened row is open again, with what the source says now');
$tisch->close($frueher, \Taxmod\Core\Import\InboxStatus::Discarded);

// ⚠️ *Sein Wunsch vom 2026-10-21: «ein button fetch all wäre auch nett» — every fetching source once, intervals aside.*
$pruefe(str_contains((new InboxScreen())->render(), 'value="' . InboxScreen::FETCH_ALL . '"'), 'the inbox page has a «Fetch all» button');
$laeufe = Inbox::lastRuns();
unset($laeufe['__ib']);
update_option('taxmod_import_last_run', $laeufe, false);
$inbox->runDue();
$pruefe(! isset(Inbox::lastRuns()['__ib']), 'the schedule skips a source without an interval');
$inbox->runAll();
$pruefe(isset(Inbox::lastRuns()['__ib']), '«Fetch all» runs it anyway');

// ⚠️ *Sein Wunsch vom 2026-10-21: «selectierte einträge verwerfen» — one checkbox per group, one button for all of them.*
$seite = (new InboxScreen())->render();
$ids = implode(',', array_map(static fn (array $row): int => (int) $row['id'], $eigene[0]));
$pruefe(str_contains($seite, 'name="selected[]" value="' . $ids . '" form="taxmod-inbox-bulk"') && str_contains($seite, 'value="' . InboxScreen::DISCARD_SELECTED . '"'), 'each group has a checkbox for the bulk actions');
// ⚠️ *Sein Wunsch vom 2026-10-25: «in der inbox soll die darstellung auch wie in ebay shipping sein sammelaktion wie sie in wp überlich sien».*
$pruefe(str_contains($seite, 'class="wp-list-table') && str_contains($seite, 'value="' . InboxScreen::TAKE_SELECTED . '"') && has_action('admin_post_' . InboxScreen::TAKE_SELECTED) !== false, 'the inbox is one list table with «Take» and «Discard» as bulk actions');
// ⚠️ *Sein Wunsch vom 2026-10-25 (D-989): «Die Button möglichst immer rechts, Annehmen in der Tabelle … eine große Überschrift»,
// «unten drunter zum Ausklappen überall … oben noch die Möglichkeit, alle auszuklappen».*
$pruefe(str_contains($seite, 'Taxonomy Modeller › Inbox') && str_contains($seite, 'Expand all') && str_contains($seite, 'class="taxmod-list-details" hidden')
    && preg_match('/<td style="text-align:right[^"]*">.*?form="taxmod-inbox-group-\d+" name="action" value="' . InboxScreen::DISCARD . '"/s', $seite) === 1, 'the inbox uses the shared list page: heading, expand all, buttons right in the row, details below');
// ⚠️ *Sein Wunsch vom 2026-10-25 (D-990): «die Felder nebeneinander … Name, last run … Button» und «Start-Over-Buttons … in die Konfiguration».*
$pruefe(str_contains($seite, 'class="taxmod-source-tiles"') && ! str_contains($seite, 'value="' . InboxScreen::RESTART . '"') && str_contains(InboxScreen::maintenance(Sources::find('__ib')), 'value="' . InboxScreen::RESTART . '"'), 'the inbox shows the sources as tiles; «Start over» sits in the configuration');
global $submenu;
$submenu['taxmod'] = [['Modeller', 'x', 'taxmod'], ['Configuration', 'x', 'taxmod-settings'], ['Inbox', 'x', InboxScreen::PAGE]];
\Taxmod\WordPress\Plugin::configurationLast();
$pruefe(array_column($submenu['taxmod'], 2) === ['taxmod', InboxScreen::PAGE, 'taxmod-settings'], '«Configuration» stands last in the menu');
$_POST = $_REQUEST = ['selected' => [$ids], '_wpnonce' => wp_create_nonce(InboxScreen::DISCARD_SELECTED)];
add_filter('wp_redirect', static function (): never { throw new RuntimeException('__redirect'); });
try {
    (new InboxScreen())->handleDiscardSelected();
} catch (RuntimeException $umgeleitet) {
}
$pruefe((new InboxTable())->openRows(array_map('intval', explode(',', $ids))) === [], 'the selected groups are discarded at once');
$_POST = $_REQUEST = [];



$reiter = SettingsScreen::moduleTabs();
// ⚠️ *Seit D-961 steht die Zuständigkeitstabelle im Code; der Reiter trägt nur noch die Ziel-Ids.*
$reiterSeite = isset($reiter['import']) ? ($reiter['import']['render'])() : '';
$pruefe(str_contains($reiterSeite, 'name="targets"') && ! str_contains($reiterSeite, 'duties['), 'the Import tab shows only the targets');
// ⚠️ *Sein Wunsch vom 2026-10-21: «die knoten die benötigt werden als felder … mit eingebbarer auswählbarerm knoten. jason kann genutzt werden um das ganze zu beschleunigen».*
$pruefe(str_contains($reiterSeite, 'name="target[orders_node]"') && str_contains($reiterSeite, 'list="taxmod-import-node"') && str_contains($reiterSeite, 'name="target[order_fields][order_number]"'), 'each target is its own field, nodes picked from a list');
$vorher = get_option(Targets::OPTION, null);
($reiter['import']['save'])(['target' => ['orders_node' => '777', 'required_parts' => '11, x, 12'], 'targets' => '']);
$pruefe(Targets::raw()['orders_node'] === 777 && Targets::raw()['required_parts'] === [11, 12], 'the fields are saved when no JSON is pasted');
($reiter['import']['save'])(['target' => ['orders_node' => '777'], 'targets' => '{"orders_node": 888}']);
$pruefe(Targets::raw()['orders_node'] === 888, 'pasted JSON wins over the fields');
$vorher === null ? delete_option(Targets::OPTION) : update_option(Targets::OPTION, $vorher, false);

// ⚠️ *D-979: a sale goes to «Verkäufe» through its own targets, and stays in the inbox while they are not set.*
$verkauf = new OrderProposal('eBay', 'S-1', ProposalKind::Sold, '2026-10-23', 'EUR', [new ProposalLine('Platine', 1, '10.00')]);
$tor = new \Taxmod\WordPress\Import\ModelGateway(Targets::load());
$pruefe($tor->side($verkauf)?->target->ordersNode === Targets::raw()['sales_node'] && $tor->side(new OrderProposal('eBay', 'K-1', ProposalKind::Ordered)) === $tor, 'a sale is looked up and written on the sales side, an order on the orders side');
$pruefe(str_contains($reiterSeite, 'name="target[sale_fields][merchant]"') && str_contains($reiterSeite, 'name="target[sold_items_node]"'), 'the Import tab shows the sale targets');
update_option(Targets::OPTION, ['sales_node' => 0], false);
$pruefe((new \Taxmod\WordPress\Import\ModelGateway(Targets::load()))->side($verkauf) === null, 'without sale targets a sale is not taken');
$vorher === null ? delete_option(Targets::OPTION) : update_option(Targets::OPTION, $vorher, false);

// ⚠️ *Sein Wunsch vom 2026-10-21 (D-962): «automatisch übernehmen», aber «mail warte auf eintrag» — off by default, and a
// run without a user acts as the one who switched it on.*
update_option(AutoTake::OPTION, 0, false);
$pruefe(AutoTake::run() === 0, 'automatic taking is off until he switches it on');
($reiter['import']['save'])(['auto_take' => '1', 'targets' => '']);
$pruefe(AutoTake::user() === get_current_user_id() && str_contains(($reiter['import']['render'])(), 'name="auto_take" value="1" checked'), 'the switch remembers who turned it on');
$alsWer = [];
add_action('set_current_user', static function () use (&$alsWer): void { $alsWer[] = get_current_user_id(); });
wp_set_current_user(0);
$alsWer = [];
AutoTake::run();
$pruefe($alsWer === [1, 0], 'a run without a user acts as the one who switched it on, and leaves no user behind');
wp_set_current_user(1);
update_option(AutoTake::OPTION, 0, false);

echo $fehler === 0 ? "all green\n" : "{$fehler} failed\n";
exit($fehler === 0 ? 0 : 1);
