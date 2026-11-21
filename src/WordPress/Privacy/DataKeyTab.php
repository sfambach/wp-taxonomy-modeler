<?php declare(strict_types=1);

namespace Taxmod\WordPress\Privacy;

use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Exception\FieldDoesNotOpen;
use Taxmod\Core\Privacy\FieldCipher;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\SettingsScreen;

/**
 * The Configuration tab «Datenschlüssel» (D-1514): the key of the confidential fields is entered here and stored with the
 * vault of the module secrets (D-951), a constant in wp-config.php going first.
 *
 * ```mermaid
 * flowchart LR
 *   V["Vorschlag: neuer Schlüssel (nur angezeigt)"] --> P["Stefan kopiert ihn in den Passwortmanager"]
 *   P --> E["einfügen + Haken «Kopie liegt sicher» + Speichern"]
 *   E --> T[("Option, versiegelt mit dem Tresor")]
 * ```
 *
 * ⚠️ *The proposed key is drawn, never stored: it reaches the server only when the owner saves it. A key that does not open
 * the data already sealed is refused, so a typo cannot lock the contacts away; a working key is not replaced here — a
 * change of key goes through wp-config.php (`TAXMOD_DATA_KEYS_OLD`).*
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class DataKeyTab
{
    public const SLUG = 'data-key';

    private const FIELD = 'taxmod_data_key';

    private const KEPT = 'taxmod_data_key_kept';

    /** @return array<string, mixed> the tab as SettingsScreen::moduleTabs() takes it */
    public static function tab(): array
    {
        return [
            'title'       => __('Data key', 'taxmod'),
            'credentials' => self::credentials(...),
            'render'      => static fn (): string => '',
            'save'        => self::save(...),
        ];
    }

    public static function credentials(): string
    {
        $html = '<p>' . esc_html__('Confidential fields (the add-on «Confidential», e.g. names and addresses of contacts) are stored encrypted with this key. It is stored encrypted itself, like the access data of the modules.', 'taxmod') . '</p>';

        if (FieldSeal::keyFromConstant()) {
            return $html . '<p>' . esc_html(sprintf(
                /* translators: %s: name of the constant */
                __('Set in wp-config.php (%s). This takes precedence over this page.', 'taxmod'),
                FieldSeal::KEY_CONSTANT
            )) . '</p>';
        }

        if (FieldSeal::storedKey()->slot()->broken()) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html__('The stored key no longer opens — the salts in wp-config.php changed. Enter the key from your password manager again.', 'taxmod') . '</p></div>';
        } elseif (FieldSeal::cipher() !== null) {
            return $html . '<p><strong>' . esc_html__('A key is stored. Keep your copy in the password manager — without it the confidential data cannot be read again, not even from a backup.', 'taxmod') . '</strong></p>';
        }

        $proposal = FieldSealJob::sealedSample(FieldSeal::keyId()) === null
            ? '<tr><th scope="row">' . esc_html__('New key', 'taxmod') . '</th><td><code>' . esc_html(FieldCipher::newKeyText()) . '</code><p class="description">'
                . esc_html__('A fresh key, shown only here. Copy it into your password manager, then paste it into the field below.', 'taxmod') . '</p></td></tr>'
            : '';

        return $html . '<table class="form-table" role="presentation"><tbody>' . $proposal
            . '<tr><th scope="row"><label for="taxmod-data-key">' . esc_html__('Key', 'taxmod') . '</label></th>'
            . '<td>' . ListPage::field(InputKind::Secret, self::FIELD, '', ['id' => 'taxmod-data-key', 'size' => 70]) . '</td></tr>'
            . '<tr><th scope="row">' . esc_html__('Copy kept', 'taxmod') . '</th>'
            . '<td><label>' . ListPage::field(InputKind::Flag, self::KEPT, '0') . ' ' . esc_html__('I keep a copy of this key outside this server.', 'taxmod') . '</label></td></tr>'
            . '</tbody></table>';
    }

    /**
     * Capability and nonce were checked by the Configuration page; the key is validated here.
     *
     * @param array<string, mixed> $post
     */
    public static function save(array $post): void
    {
        $text = trim(sanitize_text_field((string) ($post[self::FIELD] ?? '')));

        if ($text === '' || FieldSeal::keyFromConstant()) {
            return;
        }

        $say = static fn (string $message): bool => set_transient(SettingsScreen::TEST . get_current_user_id(), $message, 60);

        if ((string) ($post[self::KEPT] ?? '0') !== '1') {
            $say(__('Not stored: tick that you keep a copy of the key outside this server.', 'taxmod'));

            return;
        }

        if (FieldSeal::cipher() !== null) {
            $say(__('Not stored: a key is in use. A change of key goes through wp-config.php.', 'taxmod'));

            return;
        }

        try {
            $cipher = new FieldCipher([FieldSeal::keyId() => FieldCipher::keyFromText($text)]);
            $sample = FieldSealJob::sealedSample(FieldSeal::keyId());

            if ($sample !== null) {
                $cipher->open($sample);
            }
        } catch (FieldDoesNotOpen $e) {
            $say(__('Not stored: this key does not fit the data already encrypted, or it is not 64 hex digits.', 'taxmod'));

            return;
        }

        FieldSeal::storedKey()->save($text);
        FieldSeal::forgetKey();
        $say(__('The key is stored.', 'taxmod'));
    }
}
