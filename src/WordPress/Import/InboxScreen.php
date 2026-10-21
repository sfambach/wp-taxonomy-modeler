<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Exception\DomainError;
use Taxmod\Core\Import\ChangeListBuilder;
use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\FetchingSource;
use Taxmod\Core\Import\InboxStatus;
use Taxmod\Core\Import\Intake;
use Taxmod\Core\Import\IntakeVerdict;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalGroup;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ResolvingSource;
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
    public const FETCH = 'taxmod_inbox_fetch';
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

        foreach ($groups as $rows) {
            $html .= $this->group($rows, $model, $intake);
        }

        return $html . '</div>';
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
            $button = $source instanceof FetchingSource
                ? '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::FETCH) . '">'
                    . '<input type="hidden" name="source" value="' . esc_attr($key) . '">' . wp_nonce_field(self::FETCH, '_wpnonce', true, false)
                    . get_submit_button(__('Fetch now', 'taxmod'), 'secondary small', 'submit', false) . '</form>'
                : '';
            $html .= '<tr><td>' . esc_html($source->label()) . '</td><td>' . esc_html($last) . '</td><td>' . $button . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** @param non-empty-list<array<string, mixed>> $rows */
    private function group(array $rows, ?ModelGateway $model, Intake $intake): string
    {
        $proposal = self::merged($rows);
        $existing = $model?->findOrder($proposal->platform, $proposal->orderNumber);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $sources = Sources::all();
        $labels = array_unique(array_map(static fn (array $row): string => isset($sources[$row['source']]) ? $sources[$row['source']]->label() : (string) $row['source'], $rows));

        $html = '<div class="postbox" style="padding:0 12px 12px;max-width:60em"><h2>' . esc_html(trim($proposal->platform . ' ' . $proposal->orderNumber))
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
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot do this.', 'taxmod'), '', ['response' => 403]);
        }

        check_admin_referer(self::FETCH);
        $source = Sources::find(isset($_POST['source']) ? sanitize_key(wp_unslash((string) $_POST['source'])) : '');

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

    public function handleDiscard(): void
    {
        $rows = $this->postedRows();

        foreach ($rows as $row) {
            $this->table->close((int) $row['id'], InboxStatus::Discarded);
            self::tellSource($row, InboxStatus::Discarded);
        }

        $this->finish(__('Discarded.', 'taxmod'));
    }

    public function handleTake(): void
    {
        $rows = $this->postedRows();

        if ($rows === [] || ! ModelGateway::available()) {
            $this->finish(__('Nothing taken.', 'taxmod'));
        }

        $targets = Targets::load();
        $model = new ModelGateway($targets);
        $proposal = self::merged($rows);
        $existing = $model->findOrder($proposal->platform, $proposal->orderNumber);

        if ($existing instanceof WP_Error) {
            $this->finish($existing->get_error_message());
        }

        $verdict = (new Intake(Duties::load(), array_keys(Sources::all())))->decide((string) $rows[0]['source'], $proposal, $existing);
        $builder = new ChangeListBuilder($targets);
        $label = Sources::find((string) $rows[0]['source'])?->label() ?? (string) $rows[0]['source'];
        /* translators: 1: source, 2: date */
        $originNote = sprintf(__('Taken from the inbox (%1$s) on %2$s.', 'taxmod'), $label, wp_date('Y-m-d'));

        try {
            if ($verdict === IntakeVerdict::NewOrder && $proposal->kind !== ProposalKind::Sold) {
                $picked = array_map('absint', (array) ($_POST['lines'] ?? []));
                $delivered = $proposal->kind === ProposalKind::Delivered ? wp_date('Y-m-d') : null;
                $changes = $builder->newOrder($proposal, array_values(array_intersect($picked, array_keys($proposal->lines))), $originNote, $delivered);
            } elseif ($verdict === IntakeVerdict::Update && $existing instanceof ExistingOrder) {
                $changes = $builder->shipped($existing, $proposal->trackingNumbers);

                if ($proposal->kind === ProposalKind::Delivered && ($existing->receivedAt === null || $existing->receivedAt === '')) {
                    $changes = [...$changes, ...$builder->delivered($existing, wp_date('Y-m-d'))];
                }
            } else {
                $this->finish(self::verdictLabel($verdict));
            }
        } catch (DomainError $refused) {
            $this->finish($refused->getMessage());
        }

        $written = $changes === [] ? [] : $model->write($changes);

        if ($written instanceof WP_Error) {
            $this->finish($written->get_error_message());
        }

        foreach ($rows as $row) {
            $this->table->close((int) $row['id'], InboxStatus::Imported, $written);
            self::tellSource($row, InboxStatus::Imported);
        }

        $this->finish(__('Taken into the model.', 'taxmod'));
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
