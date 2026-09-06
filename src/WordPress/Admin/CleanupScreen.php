<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\HintMarkup;
use Taxmod\Core\Renderer\ResidueEntry;
use Taxmod\Core\Renderer\ResidueGroup;
use Taxmod\Core\Renderer\ResidueRenderer;
use Taxmod\Core\Renderer\Submission;
use Taxmod\Core\Repository\Changelog;
use Taxmod\WordPress\Persistence\Residue;
use Taxmod\WordPress\Plugin;

/**
 * `Cleanup` — **the repair surface for what deliberate non-tidying leaves behind**.
 *
 * The owner, by way of [D-247](../../../docs/NewConcept/90-decision-log.md): *Cleanup was meant for
 * tidying — nodes that have no connections any more, or settings that broke because something was
 * deleted.* [U24](../../../docs/NewConcept/20-interaction.md): it is **not a feature but a repair
 * surface**, and *«Cleanup is where the residue is shown and removed deliberately — never
 * automatically.»*
 *
 * ```mermaid
 * flowchart LR
 *   A["orphaned overrides · D-156"] --> R["Residue · measures"]
 *   B["values whose relation is gone · D-159"] --> R
 *   C["nodes with no connections"] --> R
 *   R --> S["this screen"] --> V["ResidueRenderer · the shape"]
 * ```
 *
 * ⚠️ **Three sources and no fourth, because D-247 names three.** *A repair surface that grew a
 * source nobody decided would be tidying by invention, which is the opposite of what the decision
 * asks for.*
 *
 * ⚠️ **The one act on this page is irreversible and says so.** *Every button is marked `destroys`,
 * which is what makes it red on any surface without a colour being written here — and each removal is
 * **one change group** ([D-470](../../../docs/NewConcept/90-decision-log.md)), because one POST is
 * one thing a person did.*
 *
 * ⚠️ **Not built here, and the reason changed while this page was being written: tidying the changelog
 * is now *decided*.** *[D-473](../../../docs/NewConcept/90-decision-log.md) (2026-08-28) settles it —
 * **the date is the filter and the gate is «no unresolved conflicts from that period»**, whole groups
 * only, every row at or before the cut-off. So the obstacle is no longer
 * [D-061](../../../docs/NewConcept/90-decision-log.md) or
 * [D-081](../../../docs/NewConcept/90-decision-log.md); it is that the gate's reference point — the
 * **conflict resolver**, its own screen ([M6](../../../docs/NewConcept/70-migration.md)) — does not
 * exist, and a removal that cannot ask whether a conflict is resolved would be exactly the automatic
 * tidying [D-247](../../../docs/NewConcept/90-decision-log.md) forbids.* **The place for it is this
 * page, the room is left, and there is no button** — see
 * [list row 65](../../../docs/NewConcept/97-implementation-plan.md#the-working-list).
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class CleanupScreen
{
    public const ACTION = 'taxmod_cleanup';

    /** The menu slug, so the redirect and the menu cannot disagree. */
    public const PAGE = 'taxmod-cleanup';

    /** Which act a button asks for. */
    private const FORGET_VALUES   = 'forget_values';
    private const PURGE_NODE      = 'purge_node';
    private const FORGET_RECORDS  = 'forget_records';

    public function __construct(
        private readonly Residue $residue,
        private readonly ResidueRenderer $renderer,
        // ⚠️ **The same object everything else writes history through** — a second one would open a
        // bracket nobody writes into ([D-470](../../../docs/NewConcept/90-decision-log.md)).
        private readonly Changelog $changelog,
    ) {
    }

    public function render(): string
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            return '';
        }

        return '<div class="wrap">'
            // ⚠️ *Die Erklärung stand als Absatz unter der Überschrift und wurde nach dem dritten
            // Besuch nicht mehr gelesen. Seit [D-661](../../../docs/NewConcept/90-decision-log.md)
            // steht sie hinter dem Fragezeichen — für den, der sie sucht.*
            . '<h1 style="display:flex;align-items:center;gap:.3em">'
            . HintMarkup::behind(
                esc_html__('Taxonomy Modeller — cleanup', 'taxmod'),
                __('What deletion left behind. Nothing here is tidied on its own: each of these was deliberately left alone at the moment of the change, because tidying silently would have been worse. Removing one is a decision, and it cannot be undone.', 'taxmod')
            )
            . '</h1>'
            . $this->notice()
            . $this->renderer->render([
                $this->valuesWithoutRelation(),
                $this->nodesWithoutConnections(),
                $this->recordsWithoutNode(),
            ])
            . '</div>';
    }

    /*
     * Hier stand die Gruppe «Settings, deren Eigentuemer weg ist» ([D-156]). Die `settings`-Tabelle
     * ist mit [D-579](../../../docs/NewConcept/90-decision-log.md) gestrichen — **eine Quelle, die es
     * nicht mehr gibt, hinterlaesst keinen Rest.** *Die drei anderen Gruppen bleiben.*
     */

    /** [D-159](../../../docs/NewConcept/90-decision-log.md) — a value whose relation went. */
    private function valuesWithoutRelation(): ResidueGroup
    {
        $entries = [];

        foreach ($this->residue->valuesWithoutRelation() as $relation => $rows) {
            $entries[] = new ResidueEntry(
                sprintf(
                    /* translators: 1: relation id, 2: how many recorded values still name it. */
                    _n(
                        'Attribute %1$d is gone and %2$d recorded value still names it.',
                        'Attribute %1$d is gone and %2$d recorded values still name it.',
                        $rows,
                        'taxmod'
                    ),
                    $relation,
                    $rows
                ),
                $this->act(self::FORGET_VALUES, __('Remove these values for good', 'taxmod')),
                $this->submits(self::FORGET_VALUES, $relation)
            );
        }

        return new ResidueGroup(
            __('Recorded values whose attribute is gone', 'taxmod'),
            __('The model lost an attribute and the data kept what had been entered there. Drawing never touches these — a renderer never writes, not even to tidy up — so this is the only place they can go.', 'taxmod'),
            __('Nothing to tidy up here.', 'taxmod'),
            $entries
        );
    }

    /** The third source: a node in no tree at all. */
    private function nodesWithoutConnections(): ResidueGroup
    {
        $entries = [];

        foreach ($this->residue->nodesWithoutConnections() as $node) {
            $entries[] = new ResidueEntry(
                $this->describe($node),
                $this->act(self::PURGE_NODE, __('Remove this node for good — its id and its history stay', 'taxmod')),
                $this->submits(self::PURGE_NODE, $node->id)
            );
        }

        return new ResidueGroup(
            __('Nodes with no connections', 'taxmod'),
            __('No relation points at them and none leaves them, so they are in no tree, hold no attribute and are pointed at by nothing. Removing one keeps its id and its changelog: an id is never handed out twice, and the history still says what was there.', 'taxmod'),
            __('Nothing to tidy up here.', 'taxmod'),
            $entries
        );
    }

    /**
     * Die vierte Quelle: ein Datensatz, dessen Knoten es nicht mehr gibt.
     *
     * ⚠️ **Der Eigentümer wollte hier zwei Knöpfe** — *«entweder Daten löschen oder Knoten
     * wiederherstellen»* — **und es steht einer da.** *Der zweite ist nicht vergessen: wer hier
     * auftaucht, ist **endgültig** gelöscht, denn ein geparkter Knoten steht noch in `nodes` und seine
     * Datensätze sind dann gar kein Rückstand. Zurückholen ginge nur aus dem Changelog, und ob das
     * geht, ist [OQ-128](../../../docs/NewConcept/91-open-questions.md).*
     *
     * ⚠️ *Darum sagt die Beschreibung, was **nicht** angeboten wird. Ein Schirm, der eine Wahl
     * verschweigt, die der Eigentümer verlangt hat, sieht fertig aus.*
     */
    private function recordsWithoutNode(): ResidueGroup
    {
        $entries = [];

        foreach ($this->residue->recordsWithoutNode() as $node => $held) {
            $entries[] = new ResidueEntry(
                sprintf(
                    /* translators: 1: node id, 2: how many records, 3: how many values in them. */
                    _n(
                        'Node %1$d is gone and %2$d record with %3$d values still names it.',
                        'Node %1$d is gone and %2$d records with %3$d values still name it.',
                        $held['records'],
                        'taxmod'
                    ),
                    $node,
                    $held['records'],
                    $held['values']
                ),
                $this->act(self::FORGET_RECORDS, __('Remove this data for good — the node cannot be brought back', 'taxmod')),
                $this->submits(self::FORGET_RECORDS, $node)
            );
        }

        return new ResidueGroup(
            __('Data whose node is gone', 'taxmod'),
            __('A record says which node it is a record of, and that node no longer exists — so nothing can say what the values in it mean. This must not happen and is forbidden; what is listed here is what was left behind before the rule was enforced. Bringing the node back is not offered: it is gone for good, not parked.', 'taxmod'),
            __('Nothing to tidy up here.', 'taxmod'),
            $entries
        );
    }
    /**
     * ⚠️ **The name is shown beside the id and never instead of it.** *A node's name is deliberately
     * not unique ([D-022](../../../docs/NewConcept/90-decision-log.md)), so a page that offers to
     * delete «Resistor» offers to delete something a person cannot identify.*
     */
    private function describe(Node $node): string
    {
        return sprintf(
            /* translators: 1: node name, 2: node id. */
            __('«%1$s» — node %2$d', 'taxmod'),
            $node->name,
            $node->id
        );
    }

    private function act(string $value, string $title): Control
    {
        return new Control(
            'do',
            $value,
            __('Remove', 'taxmod'),
            $title,
            true,
            // ⚠️ *`destroys` is a fact about the act and the red is the renderer's — the same division
            // the trash's «clear» makes. **Every act on this page is one of these**, which is exactly
            // why the page had to be built before anybody clicked one by accident on the command line.*
            true,
            'trash'
        );
    }

    /**
     * ⚠️ **A nonce per target and not one per page** — the same shape the modelling screen uses. *A
     * page-wide nonce would let a stale page remove a different leftover than the one a person read.*
     */
    private function submits(string $act, int $id): Submission
    {
        return new Submission(
            admin_url('admin-post.php'),
            [
                'action'        => self::ACTION,
                'taxmod_target' => (string) $id,
                '_taxmod_nonce' => wp_create_nonce(self::ACTION . '_' . $act . '_' . $id),
            ]
        );
    }

    /**
     * One removal.
     *
     * ⚠️ **`CD-5` in order and no exception for an admin-only screen**: capability → nonce → validate →
     * act. *The validation is not «is this a number» but «is this still residue» — asked again by
     * {@see Residue}, because what a form says about the database was true when the page was drawn and
     * need not be now.*
     *
     * ⚠️ **One act, one change group** ([D-470](../../../docs/NewConcept/90-decision-log.md)), and the
     * bracket closes in a `finally`. *A refusal still has to close it: an act that throws has written
     * whatever it wrote before throwing, and an open bracket would swallow the **next** person's act.*
     */
    public function handlePost(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot tidy this up.', 'taxmod'), '', ['response' => 403]);
        }

        // ⚠️ *Two values are read **before** the nonce because the nonce is built out of them — the
        // same shape {@see NodesScreen::handlePost()} has with its `id`. **Neither is trusted for
        // anything yet**: they only name which nonce has to hold, and a wrong pair simply fails it.*
        $act    = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $target = isset($_POST['taxmod_target']) ? absint($_POST['taxmod_target']) : 0;

        check_admin_referer(self::ACTION . '_' . $act . '_' . $target, '_taxmod_nonce');

        $this->changelog->beginAct();

        try {
            $message = $this->perform($act, $target);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        } finally {
            $this->changelog->endAct();
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE, 'taxmod_message' => rawurlencode($message)],
            admin_url('admin.php')
        ));

        exit;
    }

    /**
     * ⚠️ **What went is reported and not swallowed**, because these are the acts that cannot be
     * undone. *A silent «done» leaves a person checking whether it ran, and the way to check is to look
     * — which is exactly when it is too late.*
     */
    private function perform(string $act, int $target): string
    {
        if ($target === 0) {
            return __('Nothing was named, so nothing was removed.', 'taxmod');
        }

        return match ($act) {
            self::FORGET_VALUES   => $this->removed($this->residue->forgetValuesOfRelation($target)),
            self::PURGE_NODE      => $this->purged($target),
            self::FORGET_RECORDS  => $this->forgotRecords($target),
            default              => __('That is not something this page can do.', 'taxmod'),
        };
    }

    private function removed(int $rows): string
    {
        if ($rows === 0) {
            // ⚠️ *Not an error: somebody reloaded, or two people tidied the same line. The honest
            // report is that there was nothing left, not that the act failed.*
            return __('There was nothing left to remove.', 'taxmod');
        }

        return sprintf(
            /* translators: %d: how many rows were removed. */
            _n('%d row removed for good.', '%d rows removed for good.', $rows, 'taxmod'),
            $rows
        );
    }

    private function purged(int $id): string
    {
        $gone = $this->residue->purgeNodeWithoutConnections($id);

        if ($gone === null) {
            return __('That node is not standing on its own, so nothing was removed.', 'taxmod');
        }

        return sprintf(
            /* translators: %d: labels. */
            __('The node is gone, with %d labels. Its id and its changelog entries stay.', 'taxmod'),
            $gone['labels']
        );
    }

    /**
     * ⚠️ *Getrennt von {@see self::removed()}, weil hier **zwei** Zahlen berichtet werden — und
     * getrennt von {@see self::purged()}, weil dort ein Knoten verschwindet und hier keiner mehr da
     * war, der verschwinden könnte.*
     */
    private function forgotRecords(int $nodeId): string
    {
        $gone = $this->residue->forgetRecordsOfGoneNode($nodeId);

        if ($gone === null) {
            return __('That node still exists, so its data is not left over and nothing was removed.', 'taxmod');
        }

        return sprintf(
            /* translators: 1: how many records, 2: how many values. */
            __('%1$d records with %2$d values removed for good. The node was already gone; its changelog entries stay.', 'taxmod'),
            $gone['records'],
            $gone['values']
        );
    }
    private function notice(): string
    {
        if (! isset($_GET['taxmod_message'])) {
            return '';
        }

        return '<div class="notice notice-success is-dismissible"><p>'
            . esc_html(sanitize_text_field(wp_unslash($_GET['taxmod_message'])))
            . '</p></div>';
    }
}
