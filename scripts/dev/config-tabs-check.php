<?php declare(strict_types=1);

/**
 * A module shows up as its own tab on the Configuration page.
 *
 *     php scripts/dev/config-tabs-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-945](../../docs/NewConcept/90-decision-log.md), his choice:** *«Tabs in Configuration»*. *A test module registers
 * through `taxmod_config_tabs`; an entry without a save callable is left out.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Admin\SettingsScreen;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

add_filter(SettingsScreen::TABS_FILTER, static fn (array $tabs): array => $tabs + [
    'stock'   => ['title' => 'Stock', 'render' => static fn (): string => '<p id="__ct-stock">stock settings</p>', 'save' => static function (array $post): void {}, 'guide' => static fn (): string => '<ol id="__ct-guide"><li>get a key</li></ol>', 'version' => '9.8.7'],
    'kaputt'  => ['title' => 'Broken', 'render' => static fn (): string => ''],
    'general' => ['title' => 'Clash', 'render' => static fn (): string => '', 'save' => static function (array $post): void {}],
]);

$reiter = SettingsScreen::moduleTabs();
$pruefe(array_key_first(SettingsScreen::moduleTabs()) === 'import', 'the Import tab stands before the module tabs');
// ⚠️ *Seit dem Eingang der Import-Module meldet der Modeller selbst den Reiter «import» an (D-953); er steht vor dem Testmodul.*
$pruefe(array_keys($reiter) === ['import', 'stock'], 'only a complete tab with its own slug is taken');

$_GET['tab'] = 'stock';
$seite = (new SettingsScreen())->render();
$pruefe(str_contains($seite, 'nav-tab-active') && str_contains($seite, '__ct-stock') && str_contains($seite, 'name="tab" value="stock"'), 'the module tab renders its form with the tab marker');
// ⚠️ *D-963: der Kasten rechts, «wie man an die daten kommt und was man tun muss».*
// ⚠️ *D-968: «warum haben die andern module nicht die version im reiter ?»*
$entwickler = get_option(\Taxmod\WordPress\Admin\NodesScreen::DEVELOPER_OPTION, false);
update_option(\Taxmod\WordPress\Admin\NodesScreen::DEVELOPER_OPTION, '0', true);
$pruefe(! str_contains((new SettingsScreen())->render(), 'Stock, version 9.8.7'), 'without developer mode no version is shown');
update_option(\Taxmod\WordPress\Admin\NodesScreen::DEVELOPER_OPTION, '1', true);
$pruefe(str_contains((new SettingsScreen())->render(), 'Stock, version 9.8.7'), 'in developer mode the module version stands under the tab bar');
update_option(\Taxmod\WordPress\Admin\NodesScreen::DEVELOPER_OPTION, $entwickler, true);
$pruefe(str_contains($seite, '<aside class="postbox taxmod-guide"') && str_contains($seite, '__ct-guide') && strpos($seite, '__ct-stock') < strpos($seite, '__ct-guide'), 'a module guide stands as a box beside the form');

// ⚠️ *D-981: «als standard und vielleicht an der gleichen stelle überall … also zugangsdaten, intervalle und ander einstellungen».*
$abruf = new class implements \Taxmod\Core\Import\FetchingSource {
    public function key(): string { return '__ct_src'; }
    public function label(): string { return 'CT'; }
    public function interval(): int { return 6 * HOUR_IN_SECONDS; }
    public function fetch(?string $cursor): \Taxmod\Core\Import\FetchBatch { return new \Taxmod\Core\Import\FetchBatch([], null); }
};
add_filter(\Taxmod\WordPress\Import\Sources::FILTER, static fn (array $sources): array => [...$sources, $abruf]);
add_filter(SettingsScreen::TABS_FILTER, static fn (array $tabs): array => $tabs + [
    'ctsrc' => ['title' => 'CT', 'source' => '__ct_src', 'credentials' => static fn (): string => '<p id="__ct-keys">keys</p>', 'test' => static fn (): string => 'connected', 'forget' => static function (): void {}, 'render' => static fn (): string => '<p id="__ct-rest">rest</p>', 'save' => static function (array $post): void {}],
]);
$_GET['tab'] = 'ctsrc';
$seite = (new SettingsScreen())->render();
$pruefe(strpos($seite, '__ct-keys') < strpos($seite, 'name="' . \Taxmod\WordPress\Import\FetchInterval::FIELD . '"') && strpos($seite, 'name="' . \Taxmod\WordPress\Import\FetchInterval::FIELD . '"') < strpos($seite, '__ct-rest'), 'a module tab shows access, fetch interval and settings in that order');
// ⚠️ *«button test connection unter die einlogdaten und die mit einem save button versehen».*
$feld = strpos($seite, 'name="' . \Taxmod\WordPress\Import\FetchInterval::FIELD . '"');
$pruefe(strpos($seite, 'name="' . SettingsScreen::TEST . '"') > strpos($seite, '__ct-keys') && strpos($seite, 'name="' . SettingsScreen::TEST . '"') < $feld && substr_count(substr($seite, 0, $feld), 'type="submit"') === 3, 'the access data have their own Save and «Test connection» right below');
$pruefe(str_contains($seite, 'name="' . SettingsScreen::FORGET . '"') && str_contains($seite, 'return confirm('), '«Delete access data» asks before it deletes');
$vorherTakt = get_option(\Taxmod\WordPress\Import\FetchInterval::OPTION, null);
\Taxmod\WordPress\Import\FetchInterval::save('__ct_src', 'daily');
$pruefe(\Taxmod\WordPress\Import\FetchInterval::of($abruf) === DAY_IN_SECONDS, 'the chosen interval wins over the module\'s own');
\Taxmod\WordPress\Import\FetchInterval::save('__ct_src', '');
$pruefe(\Taxmod\WordPress\Import\FetchInterval::of($abruf) === 6 * HOUR_IN_SECONDS, 'without a choice the module\'s own interval counts');
$vorherTakt === null ? delete_option(\Taxmod\WordPress\Import\FetchInterval::OPTION) : update_option(\Taxmod\WordPress\Import\FetchInterval::OPTION, $vorherTakt, false);
// ⚠️ *D-1007: «das müsste man dann auch auf die die module verteieln» — «Start over» of a module stands only in its own tab.*
$pruefe(str_contains($seite, 'value="__ct_src"'), 'a module tab shows the maintenance of its own source');
add_filter(SettingsScreen::TABS_FILTER, static fn (array $tabs): array => $tabs + [
    'ctbare' => ['title' => 'Bare', 'source' => '__ct_bare', 'render' => static fn (): string => '', 'save' => static function (array $post): void {}],
]);
$_GET['tab'] = 'ctbare';
$karg = (new SettingsScreen())->render();
$pruefe(preg_match('#<h2>Access</h2>.*<h2>Fetching</h2>.*<h2>Mail</h2>.*<h2>Settings</h2>.*<h2>Maintenance</h2>#s', $karg) === 1 && substr_count($karg, 'Nothing to set here for this module.') === 5, 'every module tab has the same five parts, an empty one says so');
// ⚠️ *D-1007: «Mail modul implementation (vielleicht noch welche mails )» — the part «Mail» names what the module's reader reads.*
$leser = new class implements \Taxmod\Core\Import\Mail\OrderMailParser {
    public function key(): string { return '__ct_shop'; }
    public function shopName(): string { return 'Shop'; }
    public function recognises(\Taxmod\Core\Import\Mail\MailMessage $mail): bool { return false; }
    public function parse(\Taxmod\Core\Import\Mail\MailMessage $mail): \Taxmod\Core\Import\OrderProposal { throw new \Taxmod\Core\Import\Mail\ParserException('never'); }
    public function kinds(): array { return [\Taxmod\Core\Import\ProposalKind::Ordered, \Taxmod\Core\Import\ProposalKind::Shipped]; }
    public function version(): string { return '1.0.0'; }
};
add_filter(SettingsScreen::TABS_FILTER, static fn (array $tabs): array => $tabs + [
    'ctshop' => ['title' => 'Shop', 'reader' => $leser, 'render' => static fn (): string => '', 'save' => static function (array $post): void {}],
]);
$_GET['tab'] = 'ctshop';
$laden = (new SettingsScreen())->render();
$pruefe(str_contains($laden, 'Reads these mails from the mailbox of the Mail module: order, shipped.') && substr_count($laden, 'Nothing to set here for this module.') === 4, 'a shop module without a source still has all parts, and «Mail» lists what its reader reads');
// ⚠️ *D-1008: «bei mail sollten wir noch aktivieren, wie oft und wahrheit, nur update wahrheit aus api oder mail ganze wahrheit
// (natürlich nur wenn api verfügbar ist)».*
$pruefe(str_contains($laden, 'name="' . \Taxmod\WordPress\Import\MailReading::FIELD . '[on]"') && ! str_contains($laden, '[truth]'), 'a mail-only module can switch its mails off, and has no choice of truth');
add_filter(SettingsScreen::TABS_FILTER, static fn (array $tabs): array => $tabs + [
    'ctapi' => ['title' => 'Api', 'source' => '__ct_src', 'reader' => $leser, 'render' => static fn (): string => '', 'save' => static function (array $post): void {}],
]);
$_GET['tab'] = 'ctapi';
$pruefe(str_contains((new SettingsScreen())->render(), 'value="' . \Taxmod\WordPress\Import\MailReading::TRUTH_ALL . '"'), 'a module with an API offers «only updates» or «the whole truth»');
$vorherLesen = get_option(\Taxmod\WordPress\Import\MailReading::OPTION, null);
\Taxmod\WordPress\Import\MailReading::save($leser, true, [\Taxmod\WordPress\Import\MailReading::FIELD => ['on' => '1', 'truth' => 'all']]);
$pruefe(\Taxmod\WordPress\Import\Duties::load()->responsible('Shop', \Taxmod\Core\Import\DutyRole::Orders) === 'mail', '«the whole truth» makes the mail responsible for new orders');
\Taxmod\WordPress\Import\MailReading::save($leser, false, [\Taxmod\WordPress\Import\MailReading::FIELD => ['on' => '0', 'truth' => 'all']]);
$pruefe(apply_filters('taxmail_parsers', [$leser]) === [] && \Taxmod\WordPress\Import\MailReading::truth('__ct_shop') === \Taxmod\WordPress\Import\MailReading::TRUTH_UPDATES, 'a switched-off reader is not handed to the Mail module; without an API there is no «whole truth» to choose');
$vorherLesen === null ? delete_option(\Taxmod\WordPress\Import\MailReading::OPTION) : update_option(\Taxmod\WordPress\Import\MailReading::OPTION, $vorherLesen, false);
$_GET['tab'] = \Taxmod\WordPress\Import\ImportTab::SLUG;
$pruefe(! str_contains((new SettingsScreen())->render(), 'value="__ct_src"'), 'the Import tab leaves a claimed source to its module');

unset($_GET['tab']);
$seite = (new SettingsScreen())->render();
$pruefe(str_contains($seite, 'nav-tab') && ! str_contains($seite, '__ct-stock') && str_contains($seite, 'neutral_locale'), 'General still shows the installation settings');

exit($fehler === 0 ? 0 : 1);
