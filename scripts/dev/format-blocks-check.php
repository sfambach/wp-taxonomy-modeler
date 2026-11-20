<?php declare(strict_types=1);

/**
 * D-1500: the shared building blocks of a page's look keep their promises — `ListPage::date`, `money`, `quantity`, `button`,
 * `iconButton`, `listBar`. Boundary run (PR-9): needs a WordPress (`WP_ROOT`), writes nothing but two options it restores.
 *
 *     WP_ROOT=/home/user/wordpress php scripts/dev/format-blocks-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1500
 */

define('WP_USE_THEMES', false);

require rtrim(getenv('WP_ROOT') ?: '/home/user/wordpress', '/') . '/wp-load.php';

use Taxmod\WordPress\Admin\ListPage;

$red  = 0;
$says = static function (string $what, bool $holds) use (&$red): void {
    echo ($holds ? 'ok   ' : 'RED  ') . $what . "\n";
    $red += $holds ? 0 : 1;
};

$keep = [get_option('date_format'), get_option('time_format')];
update_option('date_format', 'd.m.Y');
update_option('time_format', 'H:i');

try {
    $says('a calendar day keeps its day in any time zone', ListPage::date('2026-11-20') === '20.11.2026');
    $says('the site\'s date format is used, not a written-in one', (static function (): bool {
        update_option('date_format', 'Y/m/d');
        $shown = ListPage::date('2026-11-20');
        update_option('date_format', 'd.m.Y');

        return $shown === '2026/11/20';
    })());
    $stamp = (new DateTimeImmutable('2026-11-20 08:05:00', wp_timezone()))->getTimestamp();
    $says('a timestamp with time in the site\'s time zone', ListPage::date($stamp, true) === '20.11.2026 08:05');
    $says('a stored «Y-m-d H:i:s» with time', ListPage::date('2026-11-20 08:05:00', true) === '20.11.2026 08:05');
    $says('empty and unreadable values give nothing', ListPage::date('') === '' && ListPage::date(null) === '' && ListPage::date('kein Datum') === '');

    $says('a date cell sorts by its moment', ListPage::dateCell('2026-11-20') === '<span data-taxmod-sort="2026-11-20">20.11.2026</span>');

    $money = ListPage::money(1234.5, 'EUR');
    $says('money carries the amount and its currency (' . $money . ')', preg_match('/1.?234[,.]50/u', $money) === 1 && (str_contains($money, '€') || str_contains($money, 'EUR')));
    $says('money of another currency names it (' . ListPage::money('3.2', 'usd') . ')', preg_match('/\$|USD/', ListPage::money('3.2', 'usd')) === 1);

    $quantity = ListPage::quantity('qty', 'Stück', '3');
    $says('a quantity always shows its unit', str_contains($quantity, 'name="qty"') && str_contains($quantity, '>Stück</span>') && str_contains($quantity, 'inputmode="decimal"'));

    $says('a text button is a link or a submit, one class for its height',
        str_contains(ListPage::button('Anlegen', 'https://example.org/'), 'class="button taxmod-button"')
        && str_contains(ListPage::button('Speichern', '', true, 'do', 'save'), '<button type="submit" class="button taxmod-button button-primary" name="do" value="save"'));
    $says('a script-only button is no submit', str_contains(ListPage::button('Drucken', attributes: ['type' => 'button']), '<button type="button" class="button taxmod-button"'));
    $says('an icon button is a square with its word as tooltip',
        str_contains(ListPage::iconButton('edit', 'Bearbeiten', 'https://example.org/'), 'taxmod-square')
        && str_contains(ListPage::iconButton('edit', 'Bearbeiten', 'https://example.org/'), 'title="Bearbeiten"'));
    $says('a disabled icon button says why', str_contains(ListPage::iconButton('archive', 'Einlagern', disabled: 'Kein Lagerort'), 'disabled title="Kein Lagerort"'));

    $bar = ListPage::listBar('3 Einträge', '', '<p class="search-box">S</p>');
    $says('the list bar is the bulk bar\'s one row without actions', str_contains($bar, 'taxmod-list-bar') && ! str_contains($bar, '<select name="action"')
        && strpos($bar, 'search-box') < strpos($bar, 'displaying-num'));
} finally {
    update_option('date_format', $keep[0]);
    update_option('time_format', $keep[1]);
}

exit($red === 0 ? 0 : 1);
