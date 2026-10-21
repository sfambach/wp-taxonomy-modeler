<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Exception\DomainError;
use Taxmod\Core\Import\BacklogSource;
use Taxmod\Core\Import\ChangeListBuilder;
use Taxmod\Core\Import\DutyRole;
use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\FetchingSource;
use Taxmod\Core\Import\ImportSource;
use Taxmod\Core\Import\InboxStatus;
use Taxmod\Core\Import\Intake;
use Taxmod\Core\Import\IntakeVerdict;
use Taxmod\Core\Import\OrderComparison;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalGroup;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ResolvingSource;
use Taxmod\Core\Import\SearchingSource;
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
    public const DISCARD = 'taxmod_inbox_discard';
    public const DISCARD_SELECTED = 'taxmod_inbox_discard_selected';
    private const BULK_FORM = 'taxmod-inbox-bulk';
    public const FETCH = 'taxmod_inbox_fetch';
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

        $html = '<div class="wrap"><h1>' . esc_html__('Inbox', 'taxmod') . '</h1>' . $this->notice() . $this->sourcesBlock();
        $groups = $this->table->openGroups();

        if ($groups === []) {
            return $html . '<p>' . esc_html__('Nothing to review.', 'taxmod') . '</p></div>';
        }

        $model = ModelGateway::available() ? new ModelGateway(Targets::load()) : null;

        if ($model === null) {
            $html .= '<div class="notice notice-warning"><p>' . esc_html__('The abilities of the Taxonomy Modeller are not available — nothing can be taken over.', 'taxmod') . '</p></div>';
        }

        $intake = new Intake(Duties::load(), array_keys(Sources::all()));

        // ⚠️ *Sein Wunsch vom 2026-10-21: «ne massen änderung wäre noch schön selectierte einträge verwerfen, spalte kann ruhig
        // länger sein dafür mehr auf einer seite». The bulk form stands alone, because a group is a form of its own and forms
        // do not nest; each group's checkbox joins it through `form=`.*
        $html .= '<form method="post" id="' . self::BULK_FORM . '" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 .75em">'
            . wp_nonce_field(self::DISCARD_SELECTED, '_wpnonce', true, false)
            . '<label style="margin-right:1em"><input type="checkbox" onclick="document.querySelectorAll(\'input[form=' . self::BULK_FORM . ']\').forEach(function(c){c.checked=this.checked}.bind(this))"> '
            . esc_html__('Select all', 'taxmod') . '</label>'
            . '<button class="button" name="action" value="' . esc_attr(self::DISCARD_SELECTED) . '">' . esc_html__('Discard selected', 'taxmod') . '</button></form>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(36em,1fr));gap:0 12px;align-items:start">';

        foreach ($groups as $rows) {
            $html .= $this->group($rows, $model, $intake);
        }

        return $html . '</div></div>';
    }

    private function sourcesBlock(): string
    {
        $sources = Sources::all();
        $counts = $this->table->counts();
        $html = '<p>' . esc_html(sprintf(
            /* translators: 1: open, 2: taken, 3: discarded */
            __('Open: %1$d · taken: %2$d · discarded: %3$d', 'taxmod'),
            $counts[InboxStatus::Open->value] ?? 0,
            $counts[InboxStatus::Imported->value] ?? 0,
            $counts[InboxStatus::Discarded->value] ?? 0
        )) . '</p>';

        if ($sources === []) {
            return $html . '<p>' . esc_html__('No import module is active.', 'taxmod') . '</p>';
        }

        $runs = Inbox::lastRuns();
        $html .= '<table class="widefat striped" style="max-width:60em;margin-bottom:1em"><caption class="screen-reader-text">' . esc_html__('Sources', 'taxmod') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('Source', 'taxmod') . '</th><th scope="col">' . esc_html__('Last run', 'taxmod') . '</th><th scope="col"></th></tr></thead><tbody>';

        foreach ($sources as $key => $source) {
            $run = $runs[$key] ?? null;
            $last = $run === null ? '–' : sprintf(
                /* translators: 1: date and time, 2: number of proposals */
                __('%1$s, %2$d new', 'taxmod'),
                wp_date('Y-m-d H:i', (int) $run['at']),
                (int) $run['filed']
            ) . ($run['error'] !== null ? ' — ' . $run['error'] : '');
            $button = ($source instanceof FetchingSource ? self::button(self::FETCH, $key, __('Fetch now', 'taxmod')) : '')
                . ($source instanceof BacklogSource ? self::button(self::BACKLOG, $key, __('Sort the backlog', 'taxmod')) : '')
                . ($source instanceof SearchingSource ? self::button(self::SEARCH, $key, __('Fetch order', 'taxmod'),
                    '<input type="text" name="text" class="regular-text" style="width:12em" aria-label="' . esc_attr__('Order number or text', 'taxmod') . '"> ') : '')
                . self::button(self::RESTART, $key, __('Start over from now', 'taxmod'), '', __('Forget the open proposals of this module and start over?', 'taxmod'));
            $html .= '<tr><td>' . esc_html($source->label()) . '</td><td>' . esc_html($last) . '</td><td>' . $button . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private static function button(string $action, string $source, string $label, string $before = '', string $confirm = ''): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 .5em .25em 0"'
            . ($confirm === '' ? '' : ' onsubmit="return confirm(' . esc_attr((string) wp_json_encode($confirm)) . ')"') . '>'
            . '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="source" value="' . esc_attr($source) . '">'
            . wp_nonce_field($action, '_wpnonce', true, false) . $before
            . get_submit_button($label, 'secondary small', 'submit', false) . '</form>';
    }

    /** @param non-empty-list<array<string, mixed>> $rows */
    private function group(array $rows, ?ModelGateway $model, Intake $intake): string
    {
        $proposal = self::merged($rows);
        $existing = $model === null ? null : self::existing($model, $proposal);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $sources = Sources::all();
        $labels = array_unique(array_map(static fn (array $row): string => isset($sources[$row['source']]) ? $sources[$row['source']]->label() : (string) $row['source'], $rows));

        $html = '<div class="postbox" style="padding:0 12px 8px;margin-bottom:12px;min-width:0"><h2>'
            . '<input type="checkbox" name="selected[]" value="' . esc_attr(implode(',', $ids)) . '" form="' . self::BULK_FORM . '" aria-label="'
            . esc_attr__('Select', 'taxmod') . '" style="margin-right:.5em"> ' . esc_html(trim($proposal->platform . ' ' . $proposal->orderNumber))
            . ' <small>· ' . esc_html(implode(', ', $labels)) . ' · ' . esc_html(self::kindLabel($proposal->kind)) . '</small></h2>';

        foreach ($rows as $row) {
            $evidence = (array) json_decode((string) ($row['evidence'] ?? ''), true);
            $said = implode(' · ', array_filter(array_map('strval', array_intersect_key($evidence, array_flip(['subject', 'sender', 'received_at'])))));

            if ($said !== '') {
                $html .= '<p><small>' . esc_html($said) . '</small></p>';
            }
        }

        if ($existing instanceof WP_Error) {
            $html .= '<p class="notice notice-error inline">' . esc_html($existing->get_error_message()) . '</p>';
            $existing = null;
        } elseif ($existing instanceof ExistingOrder) {
            /* translators: %d: record id */
            $html .= '<p><strong>' . esc_html(sprintf(__('Already in the model: record %d.', 'taxmod'), $existing->recordId)) . '</strong></p>';
        }

        $verdict = $intake->decide((string) $rows[0]['source'], $proposal, $existing);
        $canTake = $model !== null && ($verdict === IntakeVerdict::Update || ($verdict === IntakeVerdict::NewOrder && $proposal->kind !== ProposalKind::Sold));

        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
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

            if ($proposal->kind === ProposalKind::Sold) {
                $html .= '<p>' . esc_html__('Sales cannot be taken over yet — the inbox has no target for them.', 'taxmod') . '</p>';
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

        if ($canTake) {
            $html .= '<button class="button button-primary" name="action" value="' . esc_attr(self::TAKE) . '">' . esc_html__('Take', 'taxmod') . '</button> ';
        }

        return $html . '<button class="button" name="action" value="' . esc_attr(self::DISCARD) . '">' . esc_html__('Discard', 'taxmod') . '</button></form></div>';
    }

    /**
     * Model against source, field by field (D-964): an empty field is ticked, a conflict waits for his tick.
     */
    private function differences(OrderProposal $proposal, ExistingOrder $existing, bool $enabled): string
    {
        $differences = OrderComparison::between($proposal, $existing);

        if ($differences === []) {
            return '';
        }

        $html = '<table class="widefat striped" style="margin-bottom:.5em"><caption class="screen-reader-text">' . esc_html__('Model against source', 'taxmod') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('Take', 'taxmod') . '</th><th scope="col">' . esc_html__('Field', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Model', 'taxmod') . '</th><th scope="col">' . esc_html__('Source', 'taxmod') . '</th></tr></thead><tbody>';

        foreach ($differences as $role => $side) {
            $html .= '<tr><td><input type="checkbox" name="fields[]" value="' . esc_attr($role) . '"' . checked(! $side['conflict'], true, false) . disabled($enabled, false, false)
                . ' aria-label="' . esc_attr(self::fieldLabel($role)) . '"></td><td>' . esc_html(self::fieldLabel($role)) . ($side['conflict'] ? ' <strong>≠</strong>' : '') . '</td>'
                . '<td>' . esc_html($side['model'] ?? '–') . '</td><td>' . esc_html($side['source']) . '</td></tr>';
        }

        return $html . '</tbody></table>';
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
        $html = '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__('Items', 'taxmod') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('Take', 'taxmod') . '</th><th scope="col">' . esc_html__('Item', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Quantity', 'taxmod') . '</th><th scope="col">' . esc_html__('Price each', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Article no.', 'taxmod') . '</th></tr></thead><tbody>';

        foreach ($proposal->lines as $index => $line) {
            $html .= '<tr><td><input type="checkbox" name="lines[]" value="' . esc_attr((string) $index) . '" checked' . disabled($enabled, false, false)
                . ' aria-label="' . esc_attr($line->title) . '"></td><td>' . esc_html($line->title) . '</td>'
                . '<td>' . esc_html($line->quantity === null ? '–' : (string) $line->quantity) . '</td>'
                . '<td>' . esc_html($line->unitPrice === null ? '–' : $line->unitPrice . ' ' . $proposal->currency) . '</td>'
                . '<td>' . esc_html((string) $line->articleNumber) . '</td></tr>';
        }

        return $html . '</tbody></table>';
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

    public function handleTake(): void
    {
        $rows = $this->postedRows();
        $fields = array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['fields'] ?? [])), OrderComparison::ROLES));
        $result = $this->take($rows, array_map('absint', (array) ($_POST['lines'] ?? [])), $fields);

        $this->finish($result === true ? __('Taken into the model.', 'taxmod') : $result);
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

        $targets = Targets::load();
        $model = new ModelGateway($targets);
        $proposal = self::merged($rows);
        $existing = self::existing($model, $proposal);

        if ($existing instanceof WP_Error) {
            return $existing->get_error_message();
        }

        $verdict = (new Intake(Duties::load(), array_keys(Sources::all())))->decide((string) $rows[0]['source'], $proposal, $existing);
        $builder = new ChangeListBuilder($targets);
        $label = Sources::find((string) $rows[0]['source'])?->label() ?? (string) $rows[0]['source'];
        $origins = array_filter(array_map(static fn (array $row): string => (string) (((array) json_decode((string) ($row['evidence'] ?? ''), true))['origin'] ?? ''), $rows));
        /* translators: 1: source, 2: date */
        $originNote = $origins !== [] ? implode(' ', array_unique($origins)) : sprintf(__('Taken from the inbox (%1$s) on %2$s.', 'taxmod'), $label, wp_date('Y-m-d'));
        $deliveredRows = array_values(array_filter($rows, static fn (array $row): bool => $row['kind'] === ProposalKind::Delivered->value));
        $deliveredOn = $deliveredRows === [] ? null : substr((string) ($deliveredRows[0]['received_at'] ?? current_time('mysql')), 0, 10);

        try {
            if ($verdict === IntakeVerdict::NewOrder && $proposal->kind !== ProposalKind::Sold) {
                $lines = $picked === null ? array_keys($proposal->lines) : array_values(array_intersect($picked, array_keys($proposal->lines)));
                $changes = $builder->newOrder($proposal, $lines, $originNote, $deliveredOn);
            } elseif ($verdict === IntakeVerdict::Update && $existing instanceof ExistingOrder) {
                $changes = $builder->shipped($existing, $proposal->trackingNumbers);

                if (Duties::load()->allows((string) $rows[0]['source'], $proposal->platform, DutyRole::Orders, array_keys(Sources::all()))) {
                    $changes = [...$changes, ...$builder->completed($existing, $proposal, $fields ?? OrderComparison::fills($proposal, $existing))];
                }

                if ($deliveredOn !== null && ($existing->receivedAt === null || $existing->receivedAt === '')) {
                    $changes = [...$changes, ...$builder->delivered($existing, $deliveredOn)];
                }
            } else {
                return self::verdictLabel($verdict);
            }
        } catch (DomainError $refused) {
            return $refused->getMessage();
        }

        $written = $changes === [] ? [] : $model->write($changes);

        if ($written instanceof WP_Error) {
            return $written->get_error_message();
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
        $existing = self::existing(new ModelGateway(Targets::load()), $proposal);

        if ($existing instanceof WP_Error) {
            return false;
        }

        $verdict = (new Intake(Duties::load(), array_keys(Sources::all())))->decide((string) $rows[0]['source'], $proposal, $existing);

        // ⚠️ *A conflict is his choice (D-964); the run leaves that group for the page.*
        if ($verdict === IntakeVerdict::Update && $existing instanceof ExistingOrder && OrderComparison::hasConflict($proposal, $existing)) {
            return false;
        }

        return $verdict === IntakeVerdict::Update || ($verdict === IntakeVerdict::NewOrder && $proposal->kind !== ProposalKind::Sold);
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

    private static function existing(ModelGateway $model, OrderProposal $proposal): ExistingOrder|WP_Error|null
    {
        return $proposal->numberMissing ? $model->findLoose($proposal) : $model->findOrder($proposal->platform, $proposal->orderNumber);
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
        wp_safe_redirect(add_query_arg(['page' => self::PAGE], admin_url('admin.php')));
        exit;
    }

    private function notice(): string
    {
        $message = get_transient(self::NOTICE . get_current_user_id());

        if (! is_string($message) || $message === '') {
            return '';
        }

        delete_transient(self::NOTICE . get_current_user_id());

        return '<div class="notice notice-info is-dismissible"><p>' . esc_html($message) . '</p></div>';
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
