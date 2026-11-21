<?php declare(strict_types=1);

/**
 * The admin draws names in the user's language when no language is chosen; outside the admin it stays the default language.
 *
 *     php scripts/dev/user-locale-check.php [path/to/wordpress]
 *
 * ⚠️ *INF-146, Live-Check 13 on 2026-11-21: «Wert / Präfix / Einheit» stood under de_DE, the form showed «Value / Prefix / Unit»,
 * because without `taxmod_locale` the admin read the default language en_US. His word: «Ja, bitte in meiner Sprache.»*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\UnitScaffold;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was, string $detail = '') use (&$fehler): void {
    printf("  %s %s%s\n", $gut ? 'ok  ' : 'FAIL', $was, $gut || $detail === '' ? '' : ' — ' . $detail);
    $fehler += $gut ? 0 : 1;
};

$pruefe(SettingsScreen::pickLocale('', 'de_DE', 'en_US') === 'de_DE', 'no language chosen: the user\'s language');
$pruefe(SettingsScreen::pickLocale('fr_FR', 'de_DE', 'en_US') === 'fr_FR', 'a chosen language wins');
$pruefe(SettingsScreen::pickLocale('', '', 'en_US') === 'en_US', 'outside the admin (no user language passed): the default language');

update_option(SettingsScreen::NEUTRAL_LOCALE, 'en_US', true);
update_user_meta(1, 'locale', 'de_DE');
unset($_GET['taxmod_locale']);

$pruefe(SettingsScreen::requestedLocale() === 'de_DE', 'in the admin the read language is the user\'s', SettingsScreen::requestedLocale());

$einheitenwert = UnitScaffold::unitValueId();
$feld          = null;

foreach ((new WpdbRelationRepository())->fieldRelationsOf([(int) $einheitenwert]) as $kante) {
    if ($kante->fromNodeId === $einheitenwert && ! $kante->isSetting()) {
        $feld = $kante;
        break;
    }
}

$pruefe($feld !== null, 'the unit value has an own field to label');

if ($feld !== null) {
    (new WpdbLabelRepository())->put(new Label($feld->id, IdentitySpace::Relation, SeededRole::Name, Label::BASE_NUMBER, 'de_DE', '__Wert-de'));
    (new WpdbLabelRepository())->put(new Label($feld->id, IdentitySpace::Relation, SeededRole::Name, Label::BASE_NUMBER, 'en_US', '__Value-en'));

    $gelesen = (new WpdbRelationRepository())->byId($feld->id);
    $pruefe($gelesen?->name === '__Wert-de', 'a field name reads in the user\'s language', (string) $gelesen?->name);

    $neutral = (new WpdbRelationRepository('en_US'))->byId($feld->id);
    $pruefe($neutral?->name === '__Value-en', 'asked for the default language, it reads that', (string) $neutral?->name);
}

delete_user_meta(1, 'locale');

exit($fehler === 0 ? 0 : 1);
