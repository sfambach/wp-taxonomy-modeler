<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\DutyRole;
use Taxmod\Core\Import\Mail\KindFilter;
use Taxmod\Core\Import\Mail\OrderMailParser;
use Taxmod\Core\Import\ProposalKind;

/**
 * The part «Mail» of a shop module's tab (D-1008): whether its mails are read at all, how often the mailbox is fetched,
 * and — only where the module also has an API — whether a mail brings only updates (the API is the truth, D-965) or
 * the whole truth, new orders included. One option for all modules; the modules themselves stay unchanged.
 *
 * @see docs/pakete/import/module-schnitt.md §2a
 */
final class MailReading
{
    public const OPTION = 'taxmod_mail_reading';
    public const FIELD = 'taxmod_mail_reading';

    /** Every kind of mail a shop sends that the inbox knows, in the order of an order's life (D-1016). */
    public const KINDS = [ProposalKind::Ordered, ProposalKind::Shipped, ProposalKind::Delivered, ProposalKind::Cancelled,
        ProposalKind::Refunded, ProposalKind::Invoice, ProposalKind::Sold];

    public const TRUTH_UPDATES = 'updates';
    public const TRUTH_ALL = 'all';

    public static function boot(): void
    {
        // Late, so a switched-off reader is gone whatever registered it, and the choice of truth wins over a module's row.
        add_filter('taxmail_parsers', self::withoutSwitchedOff(...), 1000);
        add_filter(Duties::FILTER, self::withTruth(...), 1000);
    }

    /**
     * The kinds he unticked for this reader (D-1016); their mails go to «Verworfen».
     *
     * @return list<ProposalKind>
     */
    public static function unwanted(string $readerKey): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): ?ProposalKind => ProposalKind::tryFrom((string) $value),
            (array) (self::stored()[$readerKey]['unwanted'] ?? [])
        )));
    }

    public static function isOn(string $readerKey): bool
    {
        return (bool) (self::stored()[$readerKey]['on'] ?? true);
    }

    /** Without an API the mail is the whole truth anyway; the choice only counts where the module has a source of its own. */
    public static function truth(string $readerKey): string
    {
        return (self::stored()[$readerKey]['truth'] ?? '') === self::TRUTH_ALL ? self::TRUTH_ALL : self::TRUTH_UPDATES;
    }

    /** @param array<string, mixed> $post `mixed` because it is the posted form */
    public static function save(OrderMailParser $reader, bool $hasApi, array $post): void
    {
        $posted = is_array($post[self::FIELD] ?? null) ? $post[self::FIELD] : [];
        $all = self::stored();
        $all[$reader->key()] = [
            // The switch sends a hidden 0 before it; the value counts, not its presence (D-706).
            'on'       => ($posted['on'] ?? '1') === '1',
            'truth'    => $hasApi && ($posted['truth'] ?? '') === self::TRUTH_ALL ? self::TRUTH_ALL : self::TRUTH_UPDATES,
            'platform' => $reader->shopName(),
            // Only kinds the reader can read can be unticked; a ticked box is in the post, an unticked one is not.
            'unwanted' => array_values(array_map(
                static fn (ProposalKind $kind): string => $kind->value,
                array_filter($reader->kinds(), static fn (ProposalKind $kind): bool => ! isset(((array) ($posted['kinds'] ?? []))[$kind->value]))
            )),
        ];
        update_option(self::OPTION, $all, false);
    }

    /**
     * Every kind of mail as a box (D-1016): ticked what the reader reads and he wants, unticked what he does not want,
     * greyed out what the reader cannot read. Escaped HTML inside the module's form (CD-8).
     */
    public static function kindBoxes(OrderMailParser $reader): string
    {
        $unwanted = self::unwanted($reader->key());
        $items = '';

        foreach (self::KINDS as $kind) {
            $reads = in_array($kind, $reader->kinds(), true);
            $items .= '<li' . ($reads ? '' : ' style="opacity:.55"') . '><label><input type="checkbox" name="' . esc_attr(self::FIELD) . '[kinds][' . esc_attr($kind->value) . ']" value="1"'
                . ($reads ? checked(! in_array($kind, $unwanted, true), true, false) : ' disabled') . '> ' . esc_html(InboxScreen::kindLabel($kind))
                . ($reads ? '' : ' <span class="description">' . esc_html__('(not supported by this module)', 'taxmod') . '</span>') . '</label></li>';
        }

        return '<p>' . esc_html__('Mails this module reads from the mailbox of the Mail module. Untick what should not be processed; those mails go to «Verworfen».', 'taxmod')
            . '</p><ul class="taxmod-mail-kinds">' . $items . '</ul>';
    }

    /** The fields inside the module's form; escaped HTML (CD-8). */
    public static function fields(OrderMailParser $reader, bool $hasApi): string
    {
        $key = $reader->key();
        $html = '<p><input type="hidden" name="' . esc_attr(self::FIELD) . '[on]" value="0"><label><input type="checkbox" name="'
            . esc_attr(self::FIELD) . '[on]" value="1"' . checked(self::isOn($key), true, false) . '> '
            . esc_html__('Read this shop\'s mails', 'taxmod') . '</label></p>';

        $mail = Sources::find('mail');
        $html .= '<p class="description">' . ($mail === null
            ? esc_html__('The Mail module is not active, so no mails are read.', 'taxmod')
            : esc_html(sprintf(
                /* translators: %s: how often, e.g. «hourly» */
                __('How often: the Mail module fetches the mailbox for every shop at once — %s. Change it in its tab «Mail».', 'taxmod'),
                FetchInterval::describe($mail)
            ))) . '</p>';

        if (! $hasApi) {
            return $html;
        }

        $truth = self::truth($key);

        return $html . '<fieldset><legend>' . esc_html__('What a mail may bring', 'taxmod') . '</legend>'
            . '<p><label><input type="radio" name="' . esc_attr(self::FIELD) . '[truth]" value="' . esc_attr(self::TRUTH_UPDATES) . '"'
            . checked($truth, self::TRUTH_UPDATES, false) . '> ' . esc_html__('Only updates — new orders and their data come from the API', 'taxmod') . '</label><br>'
            . '<label><input type="radio" name="' . esc_attr(self::FIELD) . '[truth]" value="' . esc_attr(self::TRUTH_ALL) . '"'
            . checked($truth, self::TRUTH_ALL, false) . '> ' . esc_html__('The whole truth — mails bring new orders too', 'taxmod') . '</label></p></fieldset>';
    }

    /**
     * @param array<mixed> $parsers `mixed` because any plugin may add to the filter
     * @return array<mixed>
     */
    public static function withoutSwitchedOff(array $parsers): array
    {
        $kept = [];

        foreach ($parsers as $parser) {
            if (! $parser instanceof OrderMailParser) {
                $kept[] = $parser;
            } elseif (self::isOn($parser->key())) {
                $unwanted = self::unwanted($parser->key());
                $kept[] = $unwanted === [] ? $parser : new KindFilter($parser, $unwanted);
            }
        }

        return $kept;
    }

    /**
     * @param array<string, array<string, string>> $rows
     * @return array<string, array<string, string>>
     */
    public static function withTruth(array $rows): array
    {
        foreach (self::stored() as $choice) {
            if (($choice['truth'] ?? '') === self::TRUTH_ALL && ($choice['on'] ?? true) && (string) ($choice['platform'] ?? '') !== '') {
                $platform = mb_strtolower((string) $choice['platform']);
                $existing = array_change_key_case($rows)[$platform] ?? [];
                $rows = array_filter($rows, static fn (string $name): bool => mb_strtolower($name) !== $platform, ARRAY_FILTER_USE_KEY);
                $rows[$platform] = array_replace($existing, [DutyRole::Orders->value => 'mail']);
            }
        }

        return $rows;
    }

    /** @return array<string, array{on?: bool, truth?: string, platform?: string}> */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }
}
