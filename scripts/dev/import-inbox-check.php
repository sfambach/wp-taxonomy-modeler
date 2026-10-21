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

echo $fehler === 0 ? "all green\n" : "{$fehler} failed\n";
exit($fehler === 0 ? 0 : 1);
