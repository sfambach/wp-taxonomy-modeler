<?php declare(strict_types=1);

namespace Taxmod\WordPress\Privacy;

use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Exception\FieldDoesNotOpen;
use Taxmod\Core\Privacy\FieldCipher;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\FieldSealRows;

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
 * D-1562, the change of key: a new key with two ticks («Kopie aufbewahrt», «wechseln»); the key in use becomes a former
 * key, stored sealed and still read, and `FieldSealJob` reseals everything in batches. A former key is deleted by hand
 * only, with a tick, at the earliest eight days after nothing is sealed with it — the nightly copies still need it.
 *
 * ```mermaid
 * flowchart LR
 *   N["neuer Schlüssel + Haken «Kopie» + Haken «wechseln»"] --> A[("alter Schlüssel → alte Schlüssel, versiegelt")]
 *   N --> K[("neuer Schlüssel, Kennung k2 …")]
 *   K --> J["FieldSealJob: umschlüsseln"]
 *   J -->|nichts mehr am alten| W["8 Tage warten (Nachtsicherungen)"] --> L["von Hand löschen, mit Haken"]
 * ```
 *
 * ⚠️ *The proposed key is drawn, never stored: it reaches the server only when the owner saves it. A key that does not open
 * the data already sealed is refused, so a typo cannot lock the contacts away; a working key is never replaced without
 * both ticks.*
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class DataKeyTab
{
    public const SLUG = 'data-key';

    private const FIELD = 'taxmod_data_key';

    private const KEPT = 'taxmod_data_key_kept';

    private const NEW_FIELD = 'taxmod_data_key_new';

    private const CHANGE = 'taxmod_data_key_change';

    private const DROP = 'taxmod_data_key_drop_';

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
            return $html . '<p><strong>' . esc_html__('A key is stored. Keep your copy in the password manager — without it the confidential data cannot be read again, not even from a backup.', 'taxmod') . '</strong></p>'
                . self::changeForm() . self::oldKeysTable(time());
        }

        $proposal = FieldSealRows::sealedSample(FieldSeal::keyId()) === null
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

    /** D-1562: the form of a change of key, with a fresh proposal and both ticks. */
    private static function changeForm(): string
    {
        return '<h3>' . esc_html__('Change key', 'taxmod') . '</h3>'
            . '<p>' . esc_html__('The key in use stays stored as a former key, so everything sealed with it still opens; everything is then encrypted again with the new key in the background.', 'taxmod') . '</p>'
            . '<table class="form-table" role="presentation"><tbody>'
            . '<tr><th scope="row">' . esc_html__('New key', 'taxmod') . '</th><td><code>' . esc_html(FieldCipher::newKeyText()) . '</code><p class="description">'
            . esc_html__('A fresh key, shown only here. Copy it into your password manager, then paste it into the field below.', 'taxmod') . '</p></td></tr>'
            . '<tr><th scope="row"><label for="taxmod-data-key-new">' . esc_html__('Key', 'taxmod') . '</label></th>'
            . '<td>' . ListPage::field(InputKind::Secret, self::NEW_FIELD, '', ['id' => 'taxmod-data-key-new', 'size' => 70]) . '</td></tr>'
            . '<tr><th scope="row">' . esc_html__('Copy kept', 'taxmod') . '</th>'
            . '<td><label>' . ListPage::field(InputKind::Flag, self::KEPT, '0') . ' ' . esc_html__('I keep a copy of this key outside this server.', 'taxmod') . '</label></td></tr>'
            . '<tr><th scope="row">' . esc_html__('Change', 'taxmod') . '</th>'
            . '<td><label>' . ListPage::field(InputKind::Flag, self::CHANGE, '0') . ' ' . esc_html__('Yes, change the key now.', 'taxmod') . '</label></td></tr>'
            . '</tbody></table>';
    }

    /** D-1562: the former keys, what is still sealed with each, and from when it may be deleted by hand. */
    private static function oldKeysTable(int $now): string
    {
        $retired = FieldSeal::retired();

        if ($retired === []) {
            return '';
        }

        $left = FieldSealJob::sealedWithRetired();
        $rows = '';

        foreach ($retired as $keyId => $state) {
            $from = FieldSeal::dropAllowedFrom($keyId);

            if (($left[$keyId] ?? 0) > 0 || $from === null) {
                $when = esc_html__('not yet — values are still being encrypted again', 'taxmod');
            } elseif ($now < $from) {
                $when = esc_html(sprintf(
                    /* translators: %s: date */
                    __('from %s (the nightly backups still need it)', 'taxmod'),
                    wp_date(get_option('date_format'), $from)
                ));
            } else {
                $when = '<label>' . ListPage::field(InputKind::Flag, self::DROP . $keyId, '0') . ' ' . esc_html__('Delete this former key', 'taxmod') . '</label>';
            }

            $rows .= '<tr><td><code>' . esc_html($keyId) . '</code></td>'
                . '<td>' . esc_html(wp_date(get_option('date_format'), $state['retired'])) . '</td>'
                . '<td>' . esc_html(number_format_i18n($left[$keyId] ?? 0)) . '</td>'
                . '<td>' . $when . '</td></tr>';
        }

        return '<h3>' . esc_html__('Former keys', 'taxmod') . '</h3>'
            . '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Key id', 'taxmod') . '</th><th>' . esc_html__('Retired', 'taxmod') . '</th>'
            . '<th>' . esc_html__('Values still sealed with it', 'taxmod') . '</th><th>' . esc_html__('Delete', 'taxmod') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * Capability and nonce were checked by the Configuration page; the key is validated here.
     *
     * @param array<string, mixed> $post
     */
    public static function save(array $post): void
    {
        if (FieldSeal::keyFromConstant()) {
            return;
        }

        $say = static fn (string $message): bool => set_transient(SettingsScreen::TEST . get_current_user_id(), $message, 60);

        foreach (array_keys(FieldSeal::retired()) as $keyId) {
            if ((string) ($post[self::DROP . $keyId] ?? '0') === '1') {
                $say(FieldSeal::dropOldKey($keyId, time())
                    /* translators: %s: key id */
                    ? sprintf(__('The former key %s is deleted.', 'taxmod'), $keyId)
                    /* translators: %s: key id */
                    : sprintf(__('Not deleted: values are still sealed with %s, or the eight days are not over.', 'taxmod'), $keyId));
            }
        }

        $new = trim(sanitize_text_field((string) ($post[self::NEW_FIELD] ?? '')));

        if ($new !== '') {
            self::change($new, $post, $say);

            return;
        }

        $text = trim(sanitize_text_field((string) ($post[self::FIELD] ?? '')));

        if ($text === '') {
            return;
        }

        if ((string) ($post[self::KEPT] ?? '0') !== '1') {
            $say(__('Not stored: tick that you keep a copy of the key outside this server.', 'taxmod'));

            return;
        }

        if (FieldSeal::cipher() !== null) {
            $say(__('Not stored: a key is in use. Use «Change key» below.', 'taxmod'));

            return;
        }

        try {
            $cipher = new FieldCipher([FieldSeal::keyId() => FieldCipher::keyFromText($text)]);
            $sample = FieldSealRows::sealedSample(FieldSeal::keyId());

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

    /**
     * @param array<string, mixed>     $post
     * @param \Closure(string): bool $say
     */
    private static function change(#[\SensitiveParameter] string $new, array $post, \Closure $say): void
    {
        if ((string) ($post[self::KEPT] ?? '0') !== '1' || (string) ($post[self::CHANGE] ?? '0') !== '1') {
            $say(__('Not changed: tick that you keep a copy of the new key and that you want to change the key now.', 'taxmod'));

            return;
        }

        try {
            $keyId = FieldSeal::changeKey($new);
        } catch (FieldDoesNotOpen) {
            $say(__('Not changed: the new key is not 64 hex digits, or it is the key in use.', 'taxmod'));

            return;
        }

        delete_transient(FieldSealJob::THROTTLE);
        /* translators: %s: key id */
        $say(sprintf(__('The key is changed (%s). Everything is encrypted again with it in the background; the former key stays stored until you delete it.', 'taxmod'), $keyId));
    }
}
