<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Exception\DomainError;
use Taxmod\Core\Import\BacklogSource;
use Taxmod\Core\Import\ChangeListBuilder;
use Taxmod\Core\Import\DutyRole;
use Taxmod\Core\Import\ExistingLine;
use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\FetchingSource;
use Taxmod\Core\Import\ImportSource;
use Taxmod\Core\Import\InboxBundles;
use Taxmod\Core\Import\InboxStatus;
use Taxmod\Core\Import\Intake;
use Taxmod\Core\Import\IntakeVerdict;
use Taxmod\Core\Import\Money;
use Taxmod\Core\Import\OrderComparison;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalGroup;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ResolvingSource;
use Taxmod\Core\Import\SearchingSource;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Currency\EcbRates;
use Taxmod\WordPress\Plugin;
use WP_Error;

/**
 * «Inbox»: every open proposal of every source, one order once, its items ticked or not; only «Take» writes, through
 * `taxmod/write-data` (D-936, D-953 S3). Returns its HTML (CD-8); the handlers check capability and nonce first (CD-5).
 *
 * @see docs/pakete/import/konzept.md §3.5
 */
final class InboxScreen
{
    public const PAGE = 'taxmod-inbox';
    public const TAKE = 'taxmod_inbox_take';
    public const TAKE_BOX = 'taxmod_inbox_take_box';
    public const DISCARD = 'taxmod_inbox_discard';
    public const DISCARD_SELECTED = 'taxmod_inbox_discard_selected';
    public const TAKE_SELECTED = 'taxmod_inbox_take_selected';
    private const BULK_FORM = 'taxmod-inbox-bulk';
    public const FETCH = 'taxmod_inbox_fetch';
    public const FETCH_ALL = 'taxmod_inbox_fetch_all';
    public const SEARCH = 'taxmod_inbox_search';
    public const BACKLOG = 'taxmod_inbox_backlog';
    public const RESTART = 'taxmod_inbox_restart';
    private const BACKLOG_BUDGET = 20;
    private const NOTICE = 'taxmod_inbox_notice';

    public function __construct(private readonly InboxTable $table = new InboxTable())
    {
    }

    public function render(): string
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            return '';
        }

        $html = '<div class="wrap">' . ListPage::heading(__('Inbox', 'taxmod')) . $this->notice() . $this->sourcesBlock();
        $groups = $this->table->openGroups();

        if ($groups === []) {
            return $html . '<p>' . esc_html__('Nothing to review.', 'taxmod') . '</p></div>';
        }

        $model = ModelGateway::available() ? new ModelGateway(Targets::load()) : null;

        if ($model === null) {
            $html .= '<div class="notice notice-warning"><p>' . esc_html__('The abilities of the Taxonomy Modeller are not available — nothing can be taken over.', 'taxmod') . '</p></div>';
        }

        $intake = new Intake(Duties::load(), array_keys(Sources::all()));

        // ⚠️ *Sein Wunsch vom 2026-10-21: «ne massen änderung wäre noch schön selectierte einträge verwerfen», and on 2026-10-25:
        // «in der inbox soll die darstellung auch wie in ebay shipping sein sammelaktion wie sie in wp überlich sien» — one
        // WordPress list table, a row per order, bulk actions above it. The bulk form stands alone, because each row's details
        // hold a form of their own and forms do not nest; each row's checkbox joins it through `form=`.*
        $html .= ListPage::bulkBar(self::BULK_FORM, self::DISCARD_SELECTED, [self::TAKE_SELECTED => __('Take', 'taxmod'), self::DISCARD_SELECTED => __('Discard', 'taxmod')], sprintf(
            /* translators: %d: number of orders */
            _n('%d order', '%d orders', count($groups), 'taxmod'),
            count($groups)
        ));
        $body = '';

        // ⚠️ *Sein Wunsch vom 2026-10-22: «das sind alles nachrichten zur gleichen bestellung hier ist die reihenfolge wichtig
        // und wäre schön wenn das nur ein kasten wäre» (D-970).*
        $groups = array_values(array_map(static function (array $rows): array {
            usort($rows, static fn (array $a, array $b): int => [self::when($a), (int) $a['id']] <=> [self::when($b), (int) $b['id']]);

            return $rows;
        }, $groups));
        $described = [];
        $verdicts = [];

        foreach ($groups as $index => $rows) {
            $proposal = self::merged($rows);
            $existing = $model === null ? null : self::existing($model, $proposal);
            $bundle = '';

            foreach ($rows as $row) {
                $bundle = $bundle !== '' ? $bundle : (string) (((array) json_decode((string) ($row['evidence'] ?? ''), true))['bundle'] ?? '');
            }

            $described[$index] = [
                'platform'  => $proposal->platform,
                'bundle'    => $bundle === '' ? null : $bundle,
                'orderDate' => $existing instanceof ExistingOrder ? $existing->orderDate : null,
                'first'     => self::when($rows[0]),
            ];
            $verdicts[$index] = $intake->decide((string) $rows[0]['source'], $proposal, $existing instanceof ExistingOrder ? $existing : null);
        }

        foreach (InboxBundles::of($described) as $box) {
            if (count($box) === 1) {
                $rows = $groups[$box[0]];
                [$details, $buttons] = $this->group($rows, $model, $intake, true);
                $body .= $this->row(implode(',', self::ids($rows)), $rows, $verdicts[$box[0]], $details, $buttons);

                continue;
            }

            $head = $described[$box[0]];
            $boxRows = array_merge(...array_map(static fn (int $index): array => $groups[$index], $box));
            $boxIds = self::ids($boxRows);
            $details = '<h3>' . esc_html(sprintf(
                /* translators: 1: platform, 2: number of orders, 3: order date */
                __('%1$s · %2$d orders of one purchase%3$s', 'taxmod'),
                $head['platform'],
                count($box),
                $head['orderDate'] === null ? '' : ' · ' . substr($head['orderDate'], 0, 10)
            )) . '</h3>' . self::timeline($boxRows, true);

            $buttons = '';

            if ($model !== null) {
                $boxForm = 'taxmod-inbox-box-' . $boxIds[0];
                $details .= '<form method="post" id="' . esc_attr($boxForm) . '" action="' . esc_url(admin_url('admin-post.php')) . '">'
                    . '<input type="hidden" name="proposals" value="' . esc_attr(implode(',', $boxIds)) . '">'
                    . '<input type="hidden" name="groups" value="' . esc_attr(implode(';', array_map(static fn (int $index): string => implode(',', self::ids($groups[$index])), $box))) . '">'
                    . wp_nonce_field(self::nonceAction($boxIds), '_wpnonce', true, false) . '</form>';
                $buttons = '<button class="button button-primary" form="' . esc_attr($boxForm) . '" name="action" value="' . esc_attr(self::TAKE_BOX) . '">' . esc_html__('Take all in order', 'taxmod') . '</button>';
            }

            foreach ($box as $index) {
                [$groupDetails, $groupButtons] = $this->group($groups[$index], $model, $intake, true);
                $details .= $groupDetails . '<p style="text-align:right">' . $groupButtons . '</p>';
            }

            // ⚠️ *A box is taken in order of its messages (D-980): its checkbox carries each order's ids apart, «;» between orders.*
            $body .= $this->row(
                implode(';', array_map(static fn (int $index): string => implode(',', self::ids($groups[$index])), $box)),
                $boxRows,
                $verdicts[$box[0]],
                $details,
                $buttons
            );
        }

        return $html . ListPage::table([[__('Order', 'taxmod'), '16em'], [__('Received', 'taxmod'), '9em'], [__('Result', 'taxmod')]], $body) . '</div>';
    }

    /**
     * One order (or one box of orders) as a list-table row (D-989): its buttons on the right, its details below.
     *
     * @param non-empty-list<array<string, mixed>> $rows
     */
    private function row(string $selected, array $rows, IntakeVerdict $verdict, string $details, string $buttons): string
    {
        $proposal = self::merged($rows);
        $sources = Sources::all();
        $labels = array_unique(array_map(static fn (array $row): string => isset($sources[$row['source']]) ? $sources[$row['source']]->label() : (string) $row['source'], $rows));

        return ListPage::row(self::BULK_FORM, $selected, [
            '<strong>' . esc_html(trim($proposal->platform . ' ' . $proposal->orderNumber)) . '</strong><br><small>' . esc_html(implode(', ', $labels) . ' · ' . self::kindLabel($proposal->kind)) . '</small>',
            esc_html(substr(self::when($rows[0]), 0, 16)),
            esc_html(self::verdictLabel($verdict)),
        ], $buttons, $details);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * The sources as tiles side by side (D-990): «All» first with the counts and «Fetch all», then each module with its
     * last run and «Fetch now». Starting over and the backlog moved to the module's configuration tab.
     */
    private function sourcesBlock(): string
    {
        $sources = Sources::all();

        if ($sources === []) {
            return '<p>' . esc_html__('No import module is active.', 'taxmod') . '</p>';
        }

        $counts = $this->table->counts();
        $runs = Inbox::lastRuns();
        $latest = array_reduce(array_keys($sources), static fn (?array $carry, string $key): ?array => isset($runs[$key]) && ($carry === null || (int) $runs[$key]['at'] > (int) $carry['at']) ? $runs[$key] : $carry);
        $html = '<div class="taxmod-source-tiles" style="display:flex;flex-wrap:wrap;gap:12px;margin:1em 0">'
            . self::tile(__('All', 'taxmod'), $latest === null ? '–' : wp_date('Y-m-d H:i', (int) $latest['at']), esc_html(sprintf(
                /* translators: 1: open, 2: taken, 3: discarded */
                __('Open %1$d · taken %2$d · discarded %3$d', 'taxmod'),
                $counts[InboxStatus::Open->value] ?? 0,
                $counts[InboxStatus::Imported->value] ?? 0,
                $counts[InboxStatus::Discarded->value] ?? 0
            )), self::button(self::FETCH_ALL, 'all', __('Fetch all', 'taxmod')));

        foreach ($sources as $key => $source) {
            $run = $runs[$key] ?? null;
            $last = $run === null ? '–' : sprintf(
                /* translators: 1: date and time, 2: number of proposals */
                __('%1$s, %2$d new', 'taxmod'),
                wp_date('Y-m-d H:i', (int) $run['at']),
                (int) $run['filed']
            );
            // ⚠️ *«Fetch order» was his open question of 2026-10-25 («was dieses Fetch-Order genauer ist»): it looks for one
            // order by its number, also from before the starting point (D-948) — so it says so now.*
            $html .= self::tile($source->label(), $last, $run !== null && $run['error'] !== null ? '<span style="color:#b32d2e">' . esc_html((string) $run['error']) . '</span>' : '',
                ($source instanceof FetchingSource ? self::button(self::FETCH, $key, __('Fetch now', 'taxmod')) : '')
                . ($source instanceof SearchingSource ? '<br>' . self::button(self::SEARCH, $key, __('Fetch this order', 'taxmod'),
                    '<input type="text" name="text" style="width:10em" placeholder="' . esc_attr__('Order number', 'taxmod') . '" aria-label="' . esc_attr__('Order number', 'taxmod') . '"> ') : ''));
        }

        return $html . '</div>';
    }

    private static function tile(string $name, string $lastRun, string $extra, string $buttons): string
    {
        return '<div class="postbox" style="flex:0 1 15em;margin:0;padding:8px 12px"><h3 style="margin:.2em 0 .4em;font-size:1.1em">' . esc_html($name) . '</h3>'
            . '<p style="margin:0">' . esc_html__('Last run', 'taxmod') . ': ' . esc_html($lastRun) . '</p>'
            . ($extra === '' ? '' : '<p style="margin:.2em 0">' . $extra . '</p>') . '<div style="margin-top:.5em">' . $buttons . '</div></div>';
    }

    /**
     * What one rarely needs, for the module's configuration tab (D-990): sorting the backlog and starting over. His word:
     * «diese Start-Over-Buttons … ist jetzt eher für die Entwicklung. Vielleicht könnten wir das einfach in die Konfiguration … mit reinstecken».
     */
    public static function maintenance(ImportSource $source): string
    {
        $key = $source->key();
        $html = '';

        if ($source instanceof BacklogSource) {
            $html .= '<p>' . self::button(self::BACKLOG, $key, __('Sort the backlog', 'taxmod')) . '<br><span class="description">'
                . esc_html__('Reads the messages from before the starting point: orders already in the model are put away, readable ones become proposals, the rest stays.', 'taxmod') . '</span></p>';
        }

        return $html . '<p>' . self::button(self::RESTART, $key, __('Start over from now', 'taxmod'), '', __('Forget the open proposals of this module and start over?', 'taxmod'))
            . '<br><span class="description">' . esc_html__('Forgets the open proposals of this module and fetches only what comes after now.', 'taxmod') . '</span></p>';
    }

    /** The message of the last inbox action, once; the configuration tab shows it too when the action started there. */
    public static function pendingNotice(): string
    {
        $message = get_transient(self::NOTICE . get_current_user_id());

        if (! is_string($message) || $message === '') {
            return '';
        }

        delete_transient(self::NOTICE . get_current_user_id());

        return '<div class="notice notice-info is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private static function button(string $action, string $source, string $label, string $before = '', string $confirm = ''): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 .5em .25em 0"'
            . ($confirm === '' ? '' : ' onsubmit="return confirm(' . esc_attr((string) wp_json_encode($confirm)) . ')"') . '>'
            . '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="source" value="' . esc_attr($source) . '">'
            . wp_nonce_field($action, '_wpnonce', true, false) . $before
            . get_submit_button($label, 'secondary small', 'submit', false) . '</form>';
    }

    /**
     * @param non-empty-list<array<string, mixed>> $rows
     * @return array{0: string, 1: string} the details, and the buttons that submit them from wherever they stand (D-989)
     */
    private function group(array $rows, ?ModelGateway $model, Intake $intake, bool $inBox = false): array
    {
        $proposal = self::merged($rows);
        $existing = $model === null ? null : self::existing($model, $proposal);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $sources = Sources::all();
        $labels = array_unique(array_map(static fn (array $row): string => isset($sources[$row['source']]) ? $sources[$row['source']]->label() : (string) $row['source'], $rows));

        $html = '<div style="border-top:1px solid #dcdcde;padding-top:4px;min-width:0"><h4 style="margin:.5em 0">' . esc_html(trim($proposal->platform . ' ' . $proposal->orderNumber))
            . ' <small>· ' . esc_html(implode(', ', $labels)) . ' · ' . esc_html(self::kindLabel($proposal->kind)) . '</small></h4>'
            . ($inBox && count($rows) === 1 ? '' : self::timeline($rows, false));

        if ($existing instanceof WP_Error) {
            $html .= '<p class="notice notice-error inline">' . esc_html($existing->get_error_message()) . '</p>';
            $existing = null;
        } elseif ($existing instanceof ExistingOrder) {
            /* translators: %d: record id */
            $html .= '<p><strong>' . esc_html(sprintf(__('Already in the model: record %d.', 'taxmod'), $existing->recordId)) . '</strong></p>';
        }

        $verdict = $intake->decide((string) $rows[0]['source'], $proposal, $existing);
        $canTake = $model?->side($proposal) !== null && in_array($verdict, [IntakeVerdict::Update, IntakeVerdict::NewOrder], true);

        $form = 'taxmod-inbox-group-' . $ids[0];
        $html .= '<form method="post" id="' . esc_attr($form) . '" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="proposals" value="' . esc_attr(implode(',', $ids)) . '">'
            . wp_nonce_field(self::nonceAction($ids), '_wpnonce', true, false);

        if ($verdict === IntakeVerdict::NewOrder) {
            $html .= $this->lines($proposal, $canTake) . '<p>' . esc_html(sprintf(
                /* translators: 1: shipping, 2: total, 3: currency */
                __('Shipping %1$s · total %2$s %3$s', 'taxmod'),
                $proposal->shippingCost ?? '–',
                $proposal->total ?? '–',
                $proposal->currency
            )) . '</p>';

            if ($model !== null && $model->side($proposal) === null) {
                $html .= '<p>' . esc_html__('Sales cannot be taken over — set the sale targets in the «Import» tab.', 'taxmod') . '</p>';
            }
        } else {
            $html .= '<p>' . esc_html(self::verdictLabel($verdict)) . '</p>';

            if ($verdict === IntakeVerdict::Update && $existing instanceof ExistingOrder) {
                $html .= $this->differences($proposal, $existing, $canTake);
            }
        }

        if ($proposal->trackingNumbers !== []) {
            /* translators: %s: tracking numbers */
            $html .= '<p>' . esc_html(sprintf(__('Tracking: %s', 'taxmod'), implode(', ', $proposal->trackingNumbers))) . '</p>';
        }

        // ⚠️ *His word of 2026-10-25: «Die Button möglichst immer rechts, Annehmen in der Tabelle» — the buttons stand in the
        // row and reach this form through `form=`, so the ticks in the details still go with them.*
        $buttons = ($canTake ? '<button class="button button-primary" form="' . esc_attr($form) . '" name="action" value="' . esc_attr(self::TAKE) . '">' . esc_html__('Take', 'taxmod') . '</button> ' : '')
            . '<button class="button" form="' . esc_attr($form) . '" name="action" value="' . esc_attr(self::DISCARD) . '">' . esc_html__('Discard', 'taxmod') . '</button>';

        return [$html . '</form></div>', $buttons];
    }

    /**
     * Model against source, field by field (D-964): an empty field is ticked, a conflict waits for his tick.
     */
    private function differences(OrderProposal $proposal, ExistingOrder $existing, bool $enabled): string
    {
        $differences = OrderComparison::between($proposal, $existing);
        // ⚠️ *Every item the source names, with what it knows of it — his wish of 2026-10-25: «im Grunde ist mir wichtig zu sehen,
        // welche Informationen für die Position zur Verfügung stehen». Empty item prices are filled, never chosen (D-986).*
        $fills = OrderComparison::lineFills($proposal, $existing);
        $titles = array_column(array_map(static fn (ExistingLine $line): array => [$line->recordId, $line->title], $existing->lines), 1, 0);
        $matched = [];

        foreach ($fills as $recordId => $index) {
            $matched[$index] = $titles[$recordId] ?? (string) $recordId;
        }

        $note = $proposal->lines === [] ? '' : $this->positions($proposal, $matched);

        if ($differences === []) {
            return $note;
        }

        $html = '<table class="widefat striped" style="margin-bottom:.5em"><caption class="screen-reader-text">' . esc_html__('Model against source', 'taxmod') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('Take', 'taxmod') . '</th><th scope="col">' . esc_html__('Field', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Model', 'taxmod') . '</th><th scope="col">' . esc_html__('Source', 'taxmod') . '</th></tr></thead><tbody>';

        foreach ($differences as $role => $side) {
            $html .= '<tr><td><input type="checkbox" name="fields[]" value="' . esc_attr($role) . '"' . checked(! $side['conflict'], true, false) . disabled($enabled, false, false)
                . ' aria-label="' . esc_attr(self::fieldLabel($role)) . '"></td><td>' . esc_html(self::fieldLabel($role)) . ($side['conflict'] ? ' <strong>≠</strong>' : '') . '</td>'
                . '<td>' . esc_html($side['model'] ?? '–') . '</td><td>' . esc_html($side['source']) . '</td></tr>';
        }

        return $html . '</tbody></table>' . $note;
    }

    /**
     * The source's items as a table: price each, line total and the landed price with shipping and fees spread over the
     * items (D-933), each and in total. `$matched` names the purchase in the model whose empty prices an item fills.
     *
     * @param array<int, string> $matched line index => purchase title in the model
     */
    private function positions(OrderProposal $proposal, array $matched, ?bool $pick = null): string
    {
        $landed = ChangeListBuilder::landedUnitPrices($proposal);
        $currency = ' ' . $proposal->currency;
        $amount = static fn (?string $value): string => $value === null ? '–' : $value . $currency;
        $times = static function (?string $each, ?float $quantity): ?string {
            $units = Money::toUnits($each);

            return $units === null || $quantity === null ? null : Money::format((int) round($units * $quantity));
        };

        $html = '<table class="widefat striped" style="margin:.5em 0"><caption class="screen-reader-text">' . esc_html__('Items', 'taxmod') . '</caption><thead><tr>'
            . ($pick === null ? '' : '<th scope="col">' . esc_html__('Take', 'taxmod') . '</th>')
            . '<th scope="col">' . esc_html__('Item', 'taxmod') . '</th>'
            . ($matched === [] ? '' : '<th scope="col">' . esc_html__('Fills in the model', 'taxmod') . '</th>')
            . '<th scope="col">' . esc_html__('Quantity', 'taxmod') . '</th><th scope="col">' . esc_html__('Price each', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Line total', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Landed each (with shipping and fees)', 'taxmod') . '</th><th scope="col">' . esc_html__('Landed total', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Article no.', 'taxmod') . '</th></tr></thead><tbody>';

        foreach ($proposal->lines as $index => $line) {
            $html .= '<tr>'
                . ($pick === null ? '' : '<td><input type="checkbox" name="lines[]" value="' . esc_attr((string) $index) . '" checked' . disabled($pick, false, false) . ' aria-label="' . esc_attr($line->title) . '"></td>')
                . '<td>' . esc_html($line->title) . '</td>'
                . ($matched === [] ? '' : '<td>' . esc_html($matched[$index] ?? '–') . '</td>')
                . '<td>' . esc_html($line->quantity === null ? '–' : (string) $line->quantity) . '</td>'
                . '<td>' . esc_html($amount($line->unitPrice)) . '</td>'
                . '<td>' . esc_html($amount($times($line->unitPrice, $line->quantity))) . '</td>'
                . '<td>' . esc_html($amount($landed[$index] ?? null)) . '</td>'
                . '<td>' . esc_html($amount($times($landed[$index] ?? null, $line->quantity))) . '</td>'
                . '<td>' . esc_html((string) $line->articleNumber) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** When a message came: as the source received it, else when the inbox filed it. */
    /**
     * Every message, oldest first, as one numbered list: when, order, what it says (D-970, D-980).
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function timeline(array $rows, bool $withOrder): string
    {
        usort($rows, static fn (array $a, array $b): int => [self::when($a), (int) $a['id']] <=> [self::when($b), (int) $b['id']]);
        $items = '';

        foreach ($rows as $row) {
            $evidence = (array) json_decode((string) ($row['evidence'] ?? ''), true);
            $step = OrderProposal::fromArray((array) json_decode((string) $row['proposal'], true));
            $parts = [substr(self::when($row), 0, 16)];

            if ($withOrder) {
                $parts[] = $step->orderNumber;
            }

            $parts[] = self::kindLabel($step->kind);

            if ($step->trackingNumbers !== []) {
                /* translators: %s: tracking numbers */
                $parts[] = sprintf(__('tracking %s', 'taxmod'), implode(', ', $step->trackingNumbers));
            }

            $parts = [...$parts, ...array_filter(array_map('strval', array_intersect_key($evidence, array_flip(['subject', 'sender']))))];
            $items .= '<li><small>' . esc_html(implode(' · ', array_filter($parts, static fn (string $part): bool => $part !== ''))) . '</small></li>';
        }

        return '<ol style="margin:.25em 0 .5em 1.5em">' . $items . '</ol>';
    }

    /** The proposal as the order it creates: tracking and delivery follow as steps of their own (D-980). */
    private static function withoutStatus(OrderProposal $proposal): OrderProposal
    {
        return OrderProposal::fromArray(['tracking' => []] + $proposal->toArray());
    }

    private static function when(array $row): string
    {
        return (string) (($row['received_at'] ?? '') !== '' && $row['received_at'] !== null ? $row['received_at'] : $row['created_at']);
    }

    private static function fieldLabel(string $role): string
    {
        return match ($role) {
            'merchant'      => __('Merchant', 'taxmod'),
            'ordered_at'    => __('Ordered on', 'taxmod'),
            'shipping_cost' => __('Shipping', 'taxmod'),
            'fees'          => __('Fees', 'taxmod'),
            'total'         => __('Total', 'taxmod'),
            default         => $role,
        };
    }

    private function lines(OrderProposal $proposal, bool $enabled): string
    {
        return $this->positions($proposal, [], $enabled);
    }

    public function handleFetch(): void
    {
        $source = $this->postedSource(self::FETCH);

        if (! $source instanceof FetchingSource) {
            $this->finish(__('This source cannot fetch.', 'taxmod'));
        }

        try {
            /* translators: %d: number of proposals */
            $this->finish(sprintf(__('%d new proposals.', 'taxmod'), (new Inbox($this->table))->run($source)));
        } catch (\Throwable $failure) {
            $this->finish($failure->getMessage());
        }
    }

    public function handleFetchAll(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot do this.', 'taxmod'), '', ['response' => 403]);
        }

        check_admin_referer(self::FETCH_ALL);

        /* translators: %d: number of proposals */
        $this->finish(sprintf(__('%d new proposals.', 'taxmod'), (new Inbox($this->table))->runAll()));
    }

    public function handleSearch(): void
    {
        $source = $this->postedSource(self::SEARCH);
        $text = isset($_POST['text']) ? sanitize_text_field(wp_unslash((string) $_POST['text'])) : '';

        if (! $source instanceof SearchingSource || $text === '') {
            $this->finish(__('Nothing to look for.', 'taxmod'));
        }

        try {
            /* translators: %d: number of proposals */
            $this->finish(sprintf(__('%d new proposals.', 'taxmod'), (new Inbox($this->table))->search($source, $text)));
        } catch (\Throwable $failure) {
            $this->finish($failure->getMessage());
        }
    }

    public function handleBacklog(): void
    {
        $source = $this->postedSource(self::BACKLOG);

        if (! $source instanceof BacklogSource || ! ModelGateway::available()) {
            $this->finish(__('This module has no backlog to sort.', 'taxmod'));
        }

        try {
            $result = (new Inbox($this->table))->runBacklog($source, new GatewayLookup(new ModelGateway(Targets::load())), self::BACKLOG_BUDGET);
            $this->finish(sprintf(
                /* translators: %d: number of proposals */
                __('Backlog: %d new proposals.', 'taxmod'),
                $result['filed']
            ) . ' ' . ($result['done'] ? __('The backlog is done.', 'taxmod') : __('More to sort — press the button again.', 'taxmod')));
        } catch (\Throwable $failure) {
            $this->finish($failure->getMessage());
        }
    }

    public function handleRestart(): void
    {
        $source = $this->postedSource(self::RESTART);

        if ($source === null) {
            $this->finish(__('No such module.', 'taxmod'));
        }

        try {
            /* translators: %d: number of proposals */
            $this->finish(sprintf(__('Started over; %d open proposals forgotten.', 'taxmod'), (new Inbox($this->table))->restart($source)));
        } catch (\Throwable $failure) {
            $this->finish($failure->getMessage());
        }
    }

    private function postedSource(string $action): ?ImportSource
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot do this.', 'taxmod'), '', ['response' => 403]);
        }

        check_admin_referer($action);

        return Sources::find(isset($_POST['source']) ? sanitize_key(wp_unslash((string) $_POST['source'])) : '');
    }

    public function handleDiscard(): void
    {
        $rows = $this->postedRows();

        foreach ($rows as $row) {
            $this->table->close((int) $row['id'], InboxStatus::Discarded);
            self::tellSource($row, InboxStatus::Discarded);
        }

        $this->finish(__('Discarded.', 'taxmod'));
    }

    public function handleDiscardSelected(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot do this.', 'taxmod'), '', ['response' => 403]);
        }

        check_admin_referer(self::DISCARD_SELECTED);
        $ids = [];

        foreach ((array) ($_POST['selected'] ?? []) as $group) {
            foreach (explode(',', sanitize_text_field(wp_unslash((string) $group))) as $id) {
                $ids[] = absint($id);
            }
        }

        $rows = $this->table->openRows(array_values(array_unique(array_filter($ids))));

        foreach ($rows as $row) {
            $this->table->close((int) $row['id'], InboxStatus::Discarded);
            self::tellSource($row, InboxStatus::Discarded);
        }

        /* translators: %d: number of proposals */
        $this->finish(sprintf(__('%d discarded.', 'taxmod'), count($rows)));
    }

    /**
     * Takes the selected orders, each with every line and only what the model leaves empty — as {@see AutoTake} does. A
     * box («;» between its orders) is taken in the order of its messages (D-980).
     */
    public function handleTakeSelected(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot do this.', 'taxmod'), '', ['response' => 403]);
        }

        check_admin_referer(self::DISCARD_SELECTED);
        $taken = 0;
        $failed = [];

        foreach ((array) ($_POST['selected'] ?? []) as $selected) {
            foreach (explode(';', sanitize_text_field(wp_unslash((string) $selected))) as $group) {
                $rows = $this->table->openRows(array_values(array_unique(array_filter(array_map('absint', explode(',', $group))))));

                if ($rows === []) {
                    continue;
                }

                $result = $this->take($rows, null);
                $result === true ? $taken++ : $failed[] = $result;
            }
        }

        /* translators: %d: number of orders */
        $this->finish(trim(sprintf(__('%d taken.', 'taxmod'), $taken) . ' ' . implode(' ', array_unique($failed))));
    }

    public function handleTake(): void
    {
        $rows = $this->postedRows();
        $fields = array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['fields'] ?? [])), OrderComparison::ROLES));
        $result = $this->take($rows, array_map('absint', (array) ($_POST['lines'] ?? [])), $fields);

        $this->finish($result === true ? __('Taken into the model.', 'taxmod') : $result);
    }

    /** Takes every order of one box, in the order their first messages came, every line of each (D-980). */
    public function handleTakeBox(): void
    {
        $rows = $this->postedRows();
        $byId = array_column($rows, null, 'id');
        $failed = [];

        foreach (explode(';', isset($_POST['groups']) ? sanitize_text_field(wp_unslash((string) $_POST['groups'])) : '') as $group) {
            $groupRows = array_values(array_filter(array_map(static fn (string $id): ?array => $byId[absint($id)] ?? null, explode(',', $group))));

            if ($groupRows === []) {
                continue;
            }

            $result = $this->take($groupRows, null);

            if ($result !== true) {
                $failed[] = $result;
            }
        }

        $this->finish($failed === [] ? __('Taken into the model.', 'taxmod') : implode(' ', array_unique($failed)));
    }

    /**
     * Takes one group into the model — by hand from the page, or by {@see AutoTake} with every line.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<int>|null $picked line indexes; `null` takes every line
     * @param list<string>|null $fields order fields taken from the source (D-964); `null` takes only what the model leaves empty
     * @return true|string true when written, otherwise why not
     */
    public function take(array $rows, ?array $picked, ?array $fields = null): true|string
    {
        if ($rows === [] || ! ModelGateway::available()) {
            return __('Nothing taken.', 'taxmod');
        }

        $proposal = self::merged($rows);
        $root = new ModelGateway(Targets::load());
        $model = $root->side($proposal);

        if ($model === null) {
            return __('Sales cannot be taken over — set the sale targets in the «Import» tab.', 'taxmod');
        }

        $targets = $model->target;
        $existing = self::existing($root, $proposal);

        if ($existing instanceof WP_Error) {
            return $existing->get_error_message();
        }

        $verdict = (new Intake(Duties::load(), array_keys(Sources::all())))->decide((string) $rows[0]['source'], $proposal, $existing);
        $builder = new ChangeListBuilder($targets, new EcbRates());
        $label = Sources::find((string) $rows[0]['source'])?->label() ?? (string) $rows[0]['source'];
        $origins = array_filter(array_map(static fn (array $row): string => (string) (((array) json_decode((string) ($row['evidence'] ?? ''), true))['origin'] ?? ''), $rows));
        /* translators: 1: source, 2: date */
        $originNote = $origins !== [] ? implode(' ', array_unique($origins)) : sprintf(__('Taken from the inbox (%1$s) on %2$s.', 'taxmod'), $label, wp_date('Y-m-d'));
        // ⚠️ *Sein Wunsch vom 2026-10-23: «eine box mit allen updates in der reihenfolge wie sie aufgetreten sind … und dann
        // werden alle nacheinander eingespielt um das tracking zu haben» (D-980). The order itself comes from the merged
        // proposal; every status message after it is its own write, in the order it arrived, so the record's history shows
        // each step.*
        usort($rows, static fn (array $a, array $b): int => [self::when($a), (int) $a['id']] <=> [self::when($b), (int) $b['id']]);
        $written = [];

        try {
            if ($verdict === IntakeVerdict::NewOrder) {
                $lines = $picked === null ? array_keys($proposal->lines) : array_values(array_intersect($picked, array_keys($proposal->lines)));
                $created = $model->write($builder->newOrder(self::withoutStatus($proposal), $lines, $originNote));

                if ($created instanceof WP_Error) {
                    return $created->get_error_message();
                }

                $written = $created;
                $existing = new ExistingOrder((int) ($created['o'] ?? 0));
            } elseif ($verdict === IntakeVerdict::Update && $existing instanceof ExistingOrder) {
                if (Duties::load()->allows((string) $rows[0]['source'], $proposal->platform, DutyRole::Orders, array_keys(Sources::all()))) {
                    $completed = $builder->completed($existing, $proposal, $fields ?? OrderComparison::fills($proposal, $existing));
                    $result = $completed === [] ? [] : $model->write($completed);

                    if ($result instanceof WP_Error) {
                        return $result->get_error_message();
                    }
                }
            } else {
                return self::verdictLabel($verdict);
            }

            $shippedDone = $verdict === IntakeVerdict::NewOrder && $proposal->shippedDate !== null;

            foreach ($rows as $row) {
                $step = OrderProposal::fromArray((array) json_decode((string) $row['proposal'], true));
                $changes = $builder->shipped($existing, $step->trackingNumbers);

                // The date the message names, else the day it came (his word: «datum wird aus der nachricht genommen»).
                if (! $shippedDone && $step->kind === ProposalKind::Shipped) {
                    $changes = [...$changes, ...$builder->shippedOn($existing, substr($step->shippedDate ?? self::when($row), 0, 10))];
                    $shippedDone = true;
                }

                $deliveredOn = $step->kind === ProposalKind::Delivered && ($existing->receivedAt === null || $existing->receivedAt === '')
                    ? substr(self::when($row), 0, 10)
                    : null;

                if ($deliveredOn !== null) {
                    $changes = [...$changes, ...$builder->delivered($existing, $deliveredOn)];
                }

                if ($changes === []) {
                    continue;
                }

                $result = $model->write($changes);

                if ($result instanceof WP_Error) {
                    return $result->get_error_message();
                }

                $existing = new ExistingOrder(
                    $existing->recordId,
                    array_values(array_unique([...$existing->trackingNumbers, ...$step->trackingNumbers])),
                    $deliveredOn ?? $existing->receivedAt,
                    $existing->counterparty,
                    $existing->orderDate,
                    $existing->title,
                    $existing->values,
                );
            }
        } catch (DomainError $refused) {
            return $refused->getMessage();
        }

        foreach ($rows as $row) {
            $this->table->close((int) $row['id'], InboxStatus::Imported, $written);
            self::tellSource($row, InboxStatus::Imported);
        }

        return true;
    }

    /**
     * Whether a group would be taken as it stands — what {@see AutoTake} asks before it takes.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function takeable(array $rows): bool
    {
        if ($rows === [] || ! ModelGateway::available()) {
            return false;
        }

        $proposal = self::merged($rows);
        $root = new ModelGateway(Targets::load());
        $model = $root->side($proposal);
        $existing = $model === null ? null : self::existing($root, $proposal);

        if ($model === null || $existing instanceof WP_Error) {
            return false;
        }

        $verdict = (new Intake(Duties::load(), array_keys(Sources::all())))->decide((string) $rows[0]['source'], $proposal, $existing);

        // ⚠️ *A conflict is his choice (D-964); the run leaves that group for the page.*
        if ($verdict === IntakeVerdict::Update && $existing instanceof ExistingOrder && OrderComparison::hasConflict($proposal, $existing)) {
            return false;
        }

        return in_array($verdict, [IntakeVerdict::Update, IntakeVerdict::NewOrder], true);
    }

    /** @return list<array<string, mixed>> */
    private function postedRows(): array
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot do this.', 'taxmod'), '', ['response' => 403]);
        }

        $ids = array_values(array_filter(array_map('absint', explode(',', isset($_POST['proposals']) ? sanitize_text_field(wp_unslash((string) $_POST['proposals'])) : ''))));
        check_admin_referer(self::nonceAction($ids));

        return $this->table->openRows($ids);
    }

    /** @param array<string, mixed> $row */
    private static function tellSource(array $row, InboxStatus $status): void
    {
        $source = Sources::find((string) $row['source']);

        if ($source instanceof ResolvingSource) {
            $source->resolved((array) json_decode((string) ($row['evidence'] ?? ''), true), $status);
        }
    }

    /** @param ModelGateway $model the gateway of the orders; a sale is looked up on its own side */
    private static function existing(ModelGateway $model, OrderProposal $proposal): ExistingOrder|WP_Error|null
    {
        $side = $model->side($proposal);

        if ($side === null) {
            return null;
        }

        return $proposal->numberMissing ? $side->findLoose($proposal) : $side->findOrder($proposal->platform, $proposal->orderNumber);
    }

    /** @param non-empty-list<array<string, mixed>> $rows */
    private static function merged(array $rows): OrderProposal
    {
        return ProposalGroup::merge(array_map(
            static fn (array $row): OrderProposal => OrderProposal::fromArray((array) json_decode((string) $row['proposal'], true)),
            $rows
        ));
    }

    /** @param list<int> $ids */
    private static function nonceAction(array $ids): string
    {
        sort($ids);

        return 'taxmod_inbox_' . implode('-', $ids);
    }

    private function finish(string $message): never
    {
        set_transient(self::NOTICE . get_current_user_id(), $message, 60);
        // ⚠️ *Back to where the button stood: the inbox, or the module's configuration tab (D-990).*
        wp_safe_redirect(wp_get_referer() ?: add_query_arg(['page' => self::PAGE], admin_url('admin.php')));
        exit;
    }

    private function notice(): string
    {
        return self::pendingNotice();
    }

    private static function verdictLabel(IntakeVerdict $verdict): string
    {
        return match ($verdict) {
            IntakeVerdict::NewOrder       => __('A new order.', 'taxmod'),
            IntakeVerdict::Update         => __('Updates the order in the model: tracking or delivery.', 'taxmod'),
            IntakeVerdict::Nothing        => __('Nothing to take: the order is in the model already, or there is no order to add this to.', 'taxmod'),
            IntakeVerdict::NotResponsible => __('Another module is responsible for this platform (see the duty table in the Import tab).', 'taxmod'),
        };
    }

    public static function kindLabel(ProposalKind $kind): string
    {
        return match ($kind) {
            ProposalKind::Ordered   => __('order', 'taxmod'),
            ProposalKind::Shipped   => __('shipped', 'taxmod'),
            ProposalKind::Delivered => __('delivered', 'taxmod'),
            ProposalKind::Cancelled => __('cancelled', 'taxmod'),
            ProposalKind::Refunded  => __('refunded', 'taxmod'),
            ProposalKind::Invoice   => __('invoice', 'taxmod'),
            ProposalKind::Sold      => __('sold', 'taxmod'),
            ProposalKind::NoReceipt => __('no receipt', 'taxmod'),
            ProposalKind::Other     => __('other', 'taxmod'),
        };
    }
}
