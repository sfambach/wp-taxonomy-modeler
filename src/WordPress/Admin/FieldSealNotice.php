<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\Privacy\FieldSeal;

/**
 * The warning an administrator sees while fields are confidential and no data key is set (D-1514): such fields are not
 * written until the key is there, and the warning links to the tab where it is entered.
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class FieldSealNotice
{
    public static function show(): void
    {
        echo self::markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in markup()
    }

    public static function markup(): string
    {
        if (! current_user_can(\Taxmod\WordPress\Rights::CONFIGURE) || FieldSeal::confidentialRelations() === [] || FieldSeal::cipher() !== null) {
            return '';
        }

        $tab = add_query_arg(['page' => 'taxmod-settings', 'tab' => \Taxmod\WordPress\Privacy\DataKeyTab::SLUG], admin_url('admin.php'));

        return sprintf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('Taxonomy Modeller:', 'taxmod'),
            esc_html__('Some fields are confidential, but no data key is set, so they cannot be saved.', 'taxmod'),
            esc_url($tab),
            esc_html__('Set the data key', 'taxmod')
        );
    }
}
