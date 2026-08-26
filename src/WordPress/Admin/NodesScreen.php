<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Exception\DomainError;
use Taxmod\Core\Exception\NodeNotFound;
use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Exception\SettingDoesNotApply;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;
use Taxmod\Core\Renderer\DialogChooserRenderer;
use Taxmod\Core\Renderer\HeadRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\PageSlot;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\Section;
use Taxmod\Core\Renderer\LabelSlot;
use Taxmod\Core\Renderer\LabelsRenderer;
use Taxmod\Core\Renderer\SettingsRenderer;
use Taxmod\Core\Renderer\Submission;
use Taxmod\Core\Renderer\RenderedField;
use Taxmod\Core\Renderer\RenderedSetting;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\RestoreResult;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\Core\Service\Tree;
use Taxmod\WordPress\Plugin;

/**
 * The modelling screen: the tree on the left, the selected node on the right.
 *
 * ⚠️ **The split is [D-343](../../../docs/NewConcept/90-decision-log.md)**, and the layout table
 * holding it is scaffolding — a borderless table because that is the cheapest thing that will be
 * thrown away ([D-344](../../../docs/NewConcept/90-decision-log.md)). What survives is the split
 * itself, which the tree renderer will express properly.
 *
 * ⚠️ **It renders by returning a string** (`CD-8`). Nothing here echoes, which is what keeps the
 * output testable and stops half a page from being sent before an error is noticed.
 *
 * The order in {@see handlePost()} is the one the code standard prescribes and never varies:
 * **capability → nonce → validate → sanitize → act → escape on output** (`CD-5`).
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class NodesScreen
{
    private const ACTION = 'taxmod_node';

    /**
     * The form field the record's values arrive under, as `taxmod_value[<edge id>]`.
     *
     * ⚠️ **Keyed by the edge, never by position.** A checkbox does not submit when it is unticked,
     * so parallel `edge_id[]` / `value[]` arrays would shift every later value onto the wrong
     * attribute — silently, and only in the rows somebody unticked.
     */
    private const VALUE_FIELD = 'taxmod_value';

    /** A setting's own control, as `taxmod_setting[<key>]` — one row, one form, one key. */
    private const SETTING_FIELD = 'taxmod_setting';

    /**
     * Where an attribute's new name is submitted, keyed by edge id.
     *
     * ⚠️ **Its own prefix rather than sharing the settings one**, because two different kinds of
     * thing under one name — `taxmod_setting[multiplicity]` beside `taxmod_setting[418]` — is how a
     * numeric key ends up read as a setting key by whoever maintains this next.
     */
    private const NAME_FIELD = 'taxmod_attribute_name';

    /** Where the labels panel submits its texts, keyed by role. */
    private const LABEL_FIELD = 'taxmod_label';

    /** Stands in for the chosen locale until the browser puts the real one in its place. */
    private const LOCALE_MARKER = '__taxmod_locale__';

    /**
     * The icons a node may be given — **Dashicon keys, without the `dashicons-` prefix**.
     *
     * ⚠️ **An icon is picked from an allow-list, never typed** — D-251, and
     * [harvest 02](../../../docs/NewConcept/_harvest/02-settings-page.md): *Settings — Tree icons.
     * An allow-list of the icons that may be assigned on a node. 39 icons, most enabled.*
     *
     * ⚠️ **The owner: *for now this should simply be the stock WordPress offers.*** So the list is
     * WordPress's Dashicons and it lives **here**, at the boundary, because Dashicons are a
     * WordPress fact — the core knows only that an icon is a key. **Curating it in Settings**
     * (*unchecking an icon hides it from pickers*) is the next step, not this one.
     */
    private const ICONS = [
        'marker', 'location', 'flag', 'star-filled', 'yes-alt', 'warning', 'info',
        'category', 'tag', 'archive', 'portfolio', 'index-card', 'id', 'clipboard',
        'admin-generic', 'admin-tools', 'admin-settings', 'screenoptions', 'forms',
        'list-view', 'editor-ul', 'editor-ol', 'editor-table', 'chart-bar', 'chart-pie',
        'media-default', 'media-document', 'media-spreadsheet', 'images-alt2',
        'cart', 'products', 'tickets-alt', 'money-alt', 'building', 'store',
        'groups', 'businessman', 'hammer', 'art', 'lightbulb', 'palmtree', 'shield',
    ];

    public function __construct(
        private readonly ModelEditor $editor,
        private readonly Tree $tree,
        private readonly Settings $settings,
        private readonly Labels $labels,
        private readonly DataEntry $data,
        private readonly FrameworkNodes $framework,
        private readonly Rendering $rendering,
    ) {
    }

    public function render(): string
    {
        $root  = $this->framework->root();
        $trash = $this->framework->trash();

        // Two queries for the whole tree, whatever its depth — the traversal is solved once,
        // in Tree, and this screen only draws what comes back (`CD-7`).
        $collapsed = $this->collapsedFromRequest();
        $rows      = $this->tree->rowsUnder($root, [$trash->id], $collapsed);
        $parked    = $this->tree->rowsUnder($trash, [], $collapsed);
        $selected  = $this->selectedFromRequest();

        // ⚠️ **`hide` finally does something** ([D-396](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *I would have a use for `hide` on a node for the first time — I would like to hide
        // some prefixes. For that we need a switch on the tree view, «show hidden nodes», default off.*
        //
        // ⚠️ **He also reported it as broken, and it was not: it stored and nothing read it.** A
        // setting nothing consumes looks exactly like a setting that will not save — *which is why a
        // flag and its effect belong in the same package.*
        $showHidden = $this->showsHidden();
        $rows       = $showHidden ? $rows : $this->withoutHidden($rows);
        $parked     = $showHidden ? $parked : $this->withoutHidden($parked);

        $left  = $this->heading(
            __('The tree', 'taxmod'),
            __('Everything you have modelled. A node set to «hide» is left out unless you ask for it.', 'taxmod'),
            'h2'
        );
        $left .= $this->hiddenToggle($showHidden);

        // ⚠️ **Under Model, not under the root.** A node hung directly on the root sits in no
        // branch at all: it can hold no records and no attribute may point at it — a dead end
        // the screen used to invite people into. D-273 also says what this level *is*: the top
        // level of Model is the list of subject areas.
        $left .= $this->addForm(
            $this->framework->rootOf(Branch::Model),
            __('Add a subject area under Model', 'taxmod')
        );
        $left .= $this->table($rows, 'tree', $collapsed, $selected);
        $left .= $this->heading(
            __('Trash', 'taxmod'),
            __('Parked, not deleted. Anything that pointed at one of these still points at something, so nothing breaks while it sits here.', 'taxmod'),
            'h2'
        );
        $left .= $this->table($parked, 'trash', $collapsed, $selected);

        // ⚠️ **The chosen sizes reach the stylesheet as custom properties** — the file stays static
        // and cacheable, and the two numbers a person picked ride on the page ([D-397](../../../docs/NewConcept/90-decision-log.md)).
        // *The alternative was a `<style>` block again, which is what the stylesheet was extracted from.*
        $html  = '<div class="wrap" style="'
            . '--taxmod-icon:' . SettingsScreen::defaultIconSize() . 'px;'
            . '--taxmod-font:' . SettingsScreen::defaultFontSize() . 'px">';
        $html .= '<h1>' . esc_html__('Taxonomy Modeller', 'taxmod') . '</h1>';
        $html .= $this->notice();
        // The owner's proportions: a third for the tree, two thirds for the detail.
        //
        // ⚠️ **Each half scrolls on its own and the page itself does not.** The owner asked for the
        // settings half first and then saw the consequence: *the tree is so big that it needs a
        // scrollbar too, and then the general one should hopefully go away.* It does — a page
        // scrollbar exists only because something overflows the page, so once **both** columns are
        // bounded there is nothing left to scroll. *With only one of them bounded the tree slides
        // away while one reads the settings, which is the worse of the two halves to lose: the tree
        // is what one navigates by.*
        //
        // ⚠️ *`calc` rather than a fixed height, because the admin bar, the heading and the notice
        // are all variable — guessing a number would leave a gap on one screen and clip on another.*
        $pane = 'max-height:calc(100vh - 12em);overflow-y:auto;overflow-x:hidden';

        $html .= '<table style="width:100%;border:0"><tr style="vertical-align:top">'
            . '<td style="width:33%;padding:0 1.5em 0 0">'
            . '<div class="taxmod-tree-pane" style="' . $pane . ';padding-right:.6em">' . $left . '</div>'
            . '</td>'
            . '<td style="width:67%;padding:0">'
            . '<div class="taxmod-detail-pane" style="' . $pane . ';padding-right:.6em">'
            . $this->detail($selected, $rows, $root)
            . '</div></td>'
            . '</tr></table>';

        return $html . '</div>';
    }

    // ---------------------------------------------------------------- the tree

    /**
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     * @param list<int>                                                                                           $collapsed
     */
    private function table(array $rows, string $mode, array $collapsed, ?Node $selected): string
    {
        if ($rows === []) {
            return '<p><em>' . esc_html__('Nothing here yet.', 'taxmod') . '</em></p>';
        }

        // ⚠️ **The whole row is the cell's now** (D-367). The boundary supplies only what the core
        // cannot make — a **URL** and the **buttons**, both of which carry WordPress facts and
        // depend on what is allowed for that node — and the renderer decides the shape: link around
        // icon and name, controls after it.
        $actions = [];
        $hrefs   = [];
        $submits = [];
        $toggles = [];

        foreach ($rows as $row) {
            $node               = $row['node'];
            $actions[$node->id] = $this->rowActions($row, $mode);
            $submits[$node->id] = $this->submissionFor($node->id);
            // ⚠️ **The fragment is what keeps the tree where it was.** Selecting a node is a full page
            // load, so a fresh document starts at the top and the row a person just clicked is off
            // screen again. *`#taxmod-node-<id>` makes the browser scroll that row into view — inside
            // the tree's own scroll pane — with no script and nothing stored.*
            $hrefs[$node->id] = add_query_arg(
                ['page' => 'taxmod', 'taxmod_node' => $node->id],
                admin_url('admin.php')
            ) . '#taxmod-node-' . $node->id;

            if ($row['hasChildren']) {
                $toggles[$node->id] = $this->toggleUrl($node->id, $row['collapsed'], $collapsed);
            }
        }

        // ⚠️ **Nothing here builds markup any more.** The walker nests, the cell draws, and the
        // screen supplies only what the core cannot make: URLs, nonces and the words on the
        // controls. That is `R1` for the left-hand side.
        return $this->rendering->treeFor(
            $rows,
            $actions,
            $hrefs,
            $submits,
            $toggles,
            $selected?->id,
            \Taxmod\Core\Renderer\TreeNodeRenderer::NAME,
            '',
            \Taxmod\Core\Renderer\Level::Admin,
            // ⚠️ **A circumstance, read from the option** (D-389) — the write count is a diagnostic
            // and waits for developer mode, which is now a fact about the installation.
            $this->inDeveloperMode()
        )->markup;
    }

    /**
     * ⚠️ **Only what is used constantly stays in the row** ([U1](../../../docs/NewConcept/20-interaction.md)).
     * Renaming and moving moved to the right, where there is room for a field — the owner's own
     * call, and it is also what stops the row from carrying seven controls again.
     *
     * @param array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool} $row
     */
    private function rowActions(array $row, string $mode): array
    {
        if ($mode === 'trash') {
            return [new Control('do', 'restore', __('Restore', 'taxmod'), __('Put it back where it came from', 'taxmod'))];
        }

        // ⚠️ **Always the same four, in the same order** ([D-370](../../../docs/NewConcept/90-decision-log.md)).
        // What cannot be done now is **greyed**, not left out — the owner's reversal of
        // [U8](../../../docs/NewConcept/20-interaction.md), so that a position can be learnt instead
        // of shifting whenever a row happens to be first or last.
        //
        // ⚠️ **The bin parks the node only; the whole branch stays on the right.** That split is the
        // legacy's and it is documented: *Trash = node only (children move up); networking icon =
        // whole branch* ([harvest 02](../../../docs/NewConcept/_harvest/02-settings-page.md)). U1
        // keeps the row to what is used constantly, and the variant that takes a subtree with it
        // deserves the explanation it has over there.
        return [
            // ⚠️ Icons rather than characters for the same reason as the bin (D-380): `+`, `↑` and `↓`
            // are text glyphs whose weight follows the body font, so they read as hairlines beside a
            // 17px icon. A Dashicon takes `font-size` and `color` and comes out solid.
            new Control('do', 'add_child_here', __('Add child', 'taxmod'), __('Add a child under this node', 'taxmod'), icon: 'plus-alt2'),
            // ⚠️ **Duplicate, asked for three times** — the owner, in the end plainly: *duplicating
            // `my_int` does not work, no button in the tree nor in the head.* The title says what does
            // **not** come along, because a copy that silently dropped forty children would be a
            // surprise and a copy that silently brought them would be a different one.
            new Control(
                'do',
                'duplicate',
                __('Duplicate', 'taxmod'),
                __('Copy this node beside itself, with its own settings and attributes — not its children and not its records', 'taxmod'),
                // A protected node cannot be copied: a second Trash would give the framework two
                // places to look and one of them would be wrong (D-194).
                ! $this->framework->isProtected($row['node']),
                icon: 'admin-page'
            ),
            new Control('do', 'up', __('Up', 'taxmod'), __('Move up among its siblings', 'taxmod'), ! $row['isFirst'], icon: 'arrow-up-alt2'),
            new Control('do', 'down', __('Down', 'taxmod'), __('Move down among its siblings', 'taxmod'), ! $row['isLast'], icon: 'arrow-down-alt2'),
            new Control(
                'do',
                'trash_node',
                // ⚠️ The **label** stays a word even though an icon is drawn — it is the accessible
                // name, and `🗑` was replaced because an emoji outline cannot be thickened (D-380).
                __('Park', 'taxmod'),
                __('Park this node; its children move up to its parent', 'taxmod'),
                // A protected node cannot be parked (D-194) — the core refuses it anyway.
                ! $this->framework->isProtected($row['node']),
                // ⚠️ It takes something away, and the renderer paints that. *Parking is not
                // destroying — the trash is a place (D-123) — but it is the one act in this row
                // that removes a node from where it was.*
                destroys: true,
                icon: 'trash'
            ),
        ];
    }

    /**
     * Where a row's controls submit to, and what rides with them.
     *
     * ⚠️ **The two values a renderer cannot invent, and only those** — the URL and the nonce
     * (`CD-1`, `CD-5`). Everything else about the form is built by the renderer.
     */
    private function submissionFor(int $id): Submission
    {
        return new Submission(
            admin_url('admin-post.php'),
            [
                'action'         => self::ACTION,
                'id'             => (string) $id,
                '_taxmod_nonce'  => wp_create_nonce(self::ACTION . '_' . $id),
            ]
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $buttons value, label, title
     */
    /**
     * A little form of acts.
     *
     * ⚠️ **Composed through {@see ControlMarkup} like every other button on the screen**, so an
     * icon-only act is marked as one and no surface has to guess from its contents. *Four renderers
     * already went through that door; this was the last place still writing `<button>` by hand, and it
     * is why the toolbar's icons came back with boxes round them.*
     *
     * @param list<array{0: string, 1: string, 2: string, 3?: string, 4?: bool}> $buttons
     *        value · label · title · optional Dashicon key · optional *destroys*
     */
    private function form(int $id, array $buttons, string $extra = ''): string
    {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="taxmod-acts">'
            . $this->hidden($id) . $extra;

        foreach ($buttons as $button) {
            $html .= ControlMarkup::button(new Control(
                'do',
                $button[0],
                $button[1] === '' ? $button[2] : $button[1],
                $button[2],
                true,
                $button[4] ?? false,
                $button[3] ?? ''
            ));
        }

        return $html . '</form>';
    }

    /**
     * @param array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool} $row
     * @param list<int>                                                                                     $collapsed
     */
    /**
     * Where folding one row leads.
     *
     * ⚠️ **Only the address is the boundary's; the triangle is the walker's** (D-367, D-368). The
     * set lives in the URL and not in a stored preference — whether it should be remembered is
     * [OQ-082](../../../docs/NewConcept/91-open-questions.md) and a scaffolding screen does not
     * answer it.
     *
     * @param list<int> $collapsed
     */
    private function toggleUrl(int $id, bool $isCollapsed, array $collapsed): string
    {
        $next = $isCollapsed
            ? array_values(array_diff($collapsed, [$id]))
            : array_values(array_unique([...$collapsed, $id]));

        return add_query_arg(
            array_filter([
                'page'             => 'taxmod',
                'taxmod_collapsed' => implode(',', $next),
                'taxmod_node'      => isset($_GET['taxmod_node']) ? absint($_GET['taxmod_node']) : null,
            ]),
            admin_url('admin.php')
        );
    }

    private function addForm(Node $parent, string $label): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1em 0;display:flex;gap:.5em">'
            . $this->hidden($parent->id)
            . '<input type="text" name="name" placeholder="' . esc_attr($label) . '" required style="flex:1">'
            . '<button class="button button-primary" name="do" value="create">' . esc_html__('Add', 'taxmod') . '</button>'
            . '</form>';
    }

    // ------------------------------------------------------------- the details

    /**
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    private function detail(?Node $selected, array $rows, Node $root): string
    {
        if ($selected === null) {
            return '<h2>' . esc_html__('Nothing selected', 'taxmod') . '</h2>'
                . '<p class="description">'
                . esc_html__('Click a name in the tree and it appears here.', 'taxmod')
                . '</p>';
        }

        // ⚠️ **The frame is the renderer's; this only says what goes in which slot** (R20a, and
        // [PageSlot](../../../src/Core/Renderer/PageSlot.php) holds the order). The screen decides
        // **what**, never **where** — that is the whole point of the order being decided.
        $sections = [
            // ⚠️ **No heading, because this band becomes a toolbar.** The owner: *«what can be
            // done» can go too — we put a toolbar there with save, delete, move in it.* A heading over
            // a row of buttons names what the buttons already say; a toolbar names itself by being
            // one. *Only the heading goes today — the toolbar itself is on the roadmap beside
            // page-level saving, because what «save» means at page scope is not decided yet.*
            // ⚠️ **One row, and the acts carry icons** — the owner: *the toolbar is not quite perfect
            // yet, it should be one line, icons for move and trash … the select would have to be the
            // tree chooser.* It was three lines because it was three separate `<form>`s stacked; they
            // are still three forms (three different acts, three different fields) but laid out as one
            // strip, which is what a toolbar is.
            //
            // ⚠️ **`outdent` for *trash node only*, and that is not decoration.** The act promotes the
            // children one level up — *outdent* means exactly that, so the glyph says what happens
            // rather than merely looking destructive. The **branch** act gets the bin, because the
            // whole subtree goes.
            // ⚠️ **One head over three rows now, drawn by {@see HeadRenderer}** — the owner's own
            // sketch, and his question that settled where it belongs: *the head as we discussed it is
            // still not there, that is a renderer, right?* `R1`, so yes.
            //
            // ⚠️ *`PageSlot::Fixed` and `PageSlot::Name` hold nothing of their own any more: their
            // meanings became the head's second and third rows — `Fixed` is «what cannot be changed»,
            // which is exactly the constants row. **So this is a layout change and not a new concept**,
            // and the path stops being a chip behind the name because it now has a row that is
            // honestly about derived facts.*
            PageSlot::Acts->value => new Section(
                '',
                $this->head($selected, $rows, $root)
            ),

            // ⚠️ **Labels sit in `display` and R20a names no slot for them** — an assumption, and
            // it is written down in `PageSlot` rather than hidden here.
            PageSlot::Display->value => new Section(
                '',
                // ⚠️ **The icon leads the band, and it is not a setting row** (D-382). The owner:
                // *the icon looks out of place in the settings, it is only a property or mark of the
                // node.* He is right, and the code had already said so — its control had to be lifted
                // out of the panel because a form cannot sit inside a form, which I had dismissed as
                // an HTML limitation. `PageSlot::Display`'s own docblock places labels here because *a
                // label is what a thing is called*; an icon is how it is **marked**, the same band.
                $this->labelsPanel($selected) . $this->settingsPanel($selected)
            ),

            // ⚠️ **No band heading, because the panel carries its own** — with the `?` on it. Two
            // identical headings, one above the other, was the duplication the owner's *leave out a
            // few headings* was pointing at even where he had not counted them.
            PageSlot::Attributes->value => new Section(
                '',
                $this->attributes($selected, $rows)
            ),

            // ⚠️ **The slot has been empty since Package 4 and it was not idle, it was blocked —
            // on the wrong thing.** I had it waiting for the test-data column; [D-160](../../../docs/NewConcept/90-decision-log.md)
            // says *defaults remain the fallback*, so it was buildable all along. *The owner asked
            // for it because it is the only surface on which `hide` and `read_only` show an effect.*
            PageSlot::Preview->value => new Section(
                '',
                $this->previewPanel($selected)
            ),
        ];

        // ⚠️ **Records are outside the frame, deliberately.** R20a's order is about the **model** —
        // what a node *is* — and says nothing about a panel of entered data. Putting them in a slot
        // would be inventing one.
        // ⚠️ *The same box as a band, though it is not one* — it sits outside the frame by the note
            // above, and looking like the odd one out would suggest that was an accident (D-392).
        // ⚠️ **No heading with the node name.** The owner: *the heading with the node name goes.* *The
        // head's first row already holds that name as an editable field — printing it again above in
        // larger type was the duplication, and he saw it the moment the head existed.*
        return $this->rendering->nodeAsPage($selected, $sections)->markup
            . '<div class="taxmod-page-block">' . $this->recordsPanel($selected) . '</div>';
    }

    /**
     * How this node reads when it is filled in — and the only place the flags show their effect.
     *
     * The owner, 2026-08-26: *we need the preview to fix the flag and renderer concept errors.* That
     * is the argument for building it now rather than after the test-data column: **`hide` and
     * `read_only` were stored, resolved and invisible.** A settings panel draws the *switch*; nothing
     * drew what the switch *does*, so a concept fault in either could not be seen at all.
     *
     * ```mermaid
     * flowchart TB
     *   V["values: a record · else the defaults"] --> D["as a reader sees it — Purpose::Display"]
     *   V --> E["as an editor sees it — Purpose::Edit"]
     *   H["hide = true"] --> N["named below, drawn in neither"]
     * ```
     *
     * ⚠️ **Two renderings of one descent, not two features.** [D-160](../../../docs/NewConcept/90-decision-log.md)
     * says the preview *is the same single mode, fed with realistic values* — so this pulls the same
     * renderers through `Purpose::Display` and `Purpose::Edit` and lets the difference be the point.
     * *`read_only` is exactly the setting whose whole meaning is that those two differ.*
     *
     * ⚠️ **Defaults are the decided fallback, not a shortcut** — [D-160](../../../docs/NewConcept/90-decision-log.md)
     * in as many words: *defaults remain the fallback where no pack covers the model.* Which is why
     * this row was buildable today and I had listed it as blocked: the test-data column
     * ([C28](../../../docs/NewConcept/10-domain-core.md), list row 17) improves the middle rung of
     * *real data → marked rows → sample value*, and the first and third already exist.
     *
     * ⚠️ **A hidden row is named rather than silently absent.** *A preview that quietly drops a field
     * is indistinguishable from a preview that forgot it* — and the owner is using this screen to
     * judge whether `hide` is right, so what it removed has to be legible.
     */
    private function previewPanel(Node $selected): string
    {
        $branch = $this->framework->branchOf($selected);

        if ($branch === null || ! $branch->holdsData()) {
            return $this->heading(
                __('Preview', 'taxmod'),
                __('Only a node that can hold records has something to preview. A data type or a constant describes something rather than being one.', 'taxmod')
            ) . '<p><em>' . esc_html__('Nothing to preview here.', 'taxmod') . '</em></p>';
        }

        $edges = $this->editor->attributesOf($selected->id);

        $html = $this->heading(
            __('Preview', 'taxmod'),
            __('How this node reads when it is filled in. The left column is what a reader sees, the right what an editor sees — a field that differs between them is read-only, and that is the point of looking.', 'taxmod')
        );

        if ($edges === []) {
            return $html . '<p><em>' . esc_html__('No attributes yet, so there is nothing to fill in.', 'taxmod') . '</em></p>';
        }

        $resolved = $this->settings->resolveForUseSites($edges);
        $seen     = $this->previewSource($selected);

        $values     = $this->rendering->previewValuesFor($edges, $resolved, $seen['held']);
        $visibility = $this->rendering->previewVisibilityFor($edges, $resolved);
        $locale     = $this->localeFromRequest();

        $html .= '<p class="description taxmod-preview-source">' . esc_html($seen['says']) . '</p>';

        $html .= '<div class="taxmod-preview">';

        foreach ([
            [__('As a reader sees it', 'taxmod'), Purpose::Display, false],
            [__('As an editor sees it', 'taxmod'), Purpose::Edit, true],
        ] as [$title, $purpose, $editable]) {
            $html .= '<div class="taxmod-preview-side">'
                . '<h4>' . esc_html($title) . '</h4>'
                // ⚠️ **No field prefix.** A preview must not be submittable: two forms with the same
                // field names on one page is how a person saves the thing they were only looking at.
                . $this->rendering->nodeAsForm(
                    $selected,
                    $visibility['shown'],
                    $values,
                    $purpose,
                    '',
                    $locale,
                    Level::Admin,
                    $editable
                )->markup
                . '</div>';
        }

        $html .= '</div>';

        if ($visibility['hidden'] !== []) {
            $names = [];

            foreach ($visibility['hidden'] as $edge) {
                $names[] = $edge->name;
            }

            $html .= '<p class="description taxmod-preview-hidden">'
                . esc_html(sprintf(
                    /* translators: %s: comma-separated attribute names. */
                    __('Left out by hide: %s', 'taxmod'),
                    implode(', ', $names)
                ))
                . '</p>';
        }

        if ($visibility['fixed'] !== []) {
            $html .= '<p class="description">'
                . esc_html(sprintf(
                    /* translators: %d: how many attributes are read-only. */
                    _n('%d attribute is read-only, so it is drawn on both sides and editable on neither.', '%d attributes are read-only, so they are drawn on both sides and editable on neither.', count($visibility['fixed']), 'taxmod'),
                    count($visibility['fixed'])
                ))
                . '</p>';
        }

        return $html;
    }

    /**
     * What the preview is filled with, and a sentence saying so.
     *
     * ⚠️ **The provenance is shown, not implied.** *A filled preview is worth looking at only if a
     * person knows whether they are seeing real data or the defaults* — otherwise a good-looking
     * preview over sample values reads as proof that the model holds real ones.
     *
     * @return array{held: array<int, \Taxmod\Core\Model\TypedValue>, says: string}
     */
    private function previewSource(Node $selected): array
    {
        $records = $this->data->recordsOf($selected->id);

        if ($records === []) {
            return [
                'held' => [],
                'says' => __('Filled from the defaults — nothing has been entered against this node yet.', 'taxmod'),
            ];
        }

        // ⚠️ **The first record and not a chosen one.** Which record to preview is a question nobody
        // has asked yet, and inventing an answer would be `PR-4`'s failure — so it takes the first
        // and says which.
        $first = $records[0];
        $held  = [];

        foreach ($this->data->valuesOf($first->id) as $value) {
            $held[$value->edgeId] = $value->value;
        }

        return [
            'held' => $held,
            'says' => sprintf(
                /* translators: %d: the record's id. */
                __('Filled from record #%d, with the defaults where it says nothing.', 'taxmod'),
                $first->id
            ),
        ];
    }
    /**
     * The detail head, as the owner drew it: three labelled rows, two columns.
     *
     * His sketch: *form, 3 rows, 2 columns — left column Action, System, Name; right column the tool
     * buttons and parts, then constants like path, id and version, creation, last change, change
     * owner; the name field's Rename goes and is saved by the page save.*
     *
     * ⚠️ **The labels live here and not in the renderer**, because *Action*, *System* and *Name* are
     * user-visible software strings and go through the text domain (`AR-2`) — the core has no `__()`
     * and must not grow one (`CD-1`). *The renderer is handed three titled sections and decides only
     * the shape.*
     *
     * ⚠️ **Rename is gone.** [D-392](../../../docs/NewConcept/90-decision-log.md): *what is actually
     * saved is the page, not the single value.* The name field names the settings form with
     * `form="…"`, so the page save writes it.
     */
    private function head(Node $selected, array $rows, Node $root): string
    {
        // ⚠️ **The name field first, and the acts behind it** — the owner, correcting his own sketch
        // after seeing it: *name into the first row, actions not their own row but behind the name
        // field.* It names the settings form with `form="…"`, so the page save writes it
        // ([D-392](../../../docs/NewConcept/90-decision-log.md)) and `Rename` is gone.
        $node = '<input type="text" name="name" value="' . esc_attr($selected->name) . '" required'
            . ' form="' . esc_attr(SettingsRenderer::formFor($selected)) . '">'
            . $this->form(
                $selected->id,
                [['add_child', '', __('Add a child under this node', 'taxmod'), 'plus-alt2']],
                '<input type="text" name="name" placeholder="' . esc_attr__('Name of the new child', 'taxmod') . '" required class="taxmod-toolbar-name">'
            )
            // ⚠️ **The save button submits the settings panel from outside it.** `form="…"` is plain
            // HTML — a button may name the form it belongs to — so nothing needs scripting.
            . '<button class="button button-primary taxmod-icon-button" form="' . esc_attr(SettingsRenderer::formFor($selected)) . '"'
            . ' name="do" value="' . esc_attr(SettingsRenderer::WRITE) . '"'
            . ' title="' . esc_attr__('Save every setting on this page', 'taxmod') . '">'
            // ⚠️ The diskette, not `dashicons-saved` — that one is a **tick**, and the owner spotted it.
            . '<span aria-label="' . esc_attr__('Save', 'taxmod') . '">💾</span></button>'
            . $this->form(
                $selected->id,
                [
                    ['duplicate', '', __('Copy this node beside itself, with its own settings and attributes — not its children and not its records', 'taxmod'), 'admin-page'],
                    ['trash', '', __('Trash this node and everything under it', 'taxmod'), 'trash', true],
                    ['trash_node', '', __('Its children move up to its parent, and lose what they inherited from it', 'taxmod'), 'editor-outdent', true],
                ],
                // ⚠️ **The move button *is* the dialog's opener** — the owner: *button move with dialog
                // tree chooser*, and then *nicht inline*. So the chooser no longer sits beside a move
                // button; it is behind it, and its own confirm lives inside the overlay.
                $this->parentChooser($selected, $rows, $root)
            );

        return $this->rendering->headFor($selected, [
            HeadRenderer::NODE   => new Section(__('Node', 'taxmod'), $node),
            HeadRenderer::SYSTEM => new Section(__('System', 'taxmod'), $this->constants($selected)),
        ])->markup;
    }

    /**
     * What cannot be changed — `PageSlot::Fixed`'s meaning, as a row of chips.
     *
     * ⚠️ **Every one of these is derived**, which is what makes them belong together: `path` comes off
     * the edges ([D-014](../../../docs/NewConcept/90-decision-log.md)), `id` is handed out once and
     * never reissued ([D-340](../../../docs/NewConcept/90-decision-log.md)), `version` rises so a
     * record can say what it was written against ([D-060](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Creation, last change and who changed it are not here yet, and that is a wiring gap rather
     * than a missing decision.** `Changelog::summaryOf()` and {@see \Taxmod\Core\Model\ChangeSummary}
     * are built and checked; this screen has no changelog collaborator to ask. *Saying so is worth
     * more than drawing three empty chips — and it is the same fault shape as `hide` storing
     * correctly while nothing read it ([D-396](../../../docs/NewConcept/90-decision-log.md)).*
     */
    private function constants(Node $selected): string
    {
        $chips = [
            [__('Path', 'taxmod'), $selected->path, __('Where it hangs in the tree. Derived from the edges and never edited.', 'taxmod')],
            [__('Id', 'taxmod'), (string) $selected->id, __('Handed out once and never reissued.', 'taxmod')],
            [__('Version', 'taxmod'), (string) $selected->version, __('Rises when the model changes, so a record can say what it was written against.', 'taxmod')],
        ];

        $html = '';

        foreach ($chips as [$label, $value, $why]) {
            $html .= '<span class="taxmod-chip" title="' . esc_attr($why) . '">'
                . '<span class="taxmod-chip-label">' . esc_html($label) . '</span> '
                . '<code>' . esc_html($value) . '</code></span>';
        }

        return $html;
    }

    /**
     * Where this node could go — everywhere except itself and its own subtree.
     *
     * ⚠️ **The impossible targets are left out rather than refused.** The core still refuses
     * them, because a screen is not a guarantee; but offering a choice that always fails is a
     * trap laid for the person using it.
     *
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    private function parentChooser(Node $node, array $rows, Node $root): string
    {
        // ⚠️ **A tree chooser and no longer a flat `<select>`** ([D-395](../../../docs/NewConcept/90-decision-log.md)).
        // The owner, looking at eighty entries prefixed with middle dots: *the select would have to be
        // the tree chooser.* **It also could not say no:** every branch root was in the list, and
        // `Model`, `Primitives` and `Label roles` are not sensible parents for a subject area.
        //
        // ⚠️ *Its own subtree is now **barred** rather than **omitted** — the cell draws such a row as
        // text with no radio. Leaving it out tore a hole in the hierarchy, because a child of an
        // impossible parent can be a perfectly good one.*
        $barred = [];

        foreach ($rows as $row) {
            $candidate = $row['node'];

            if ($candidate->id === $node->id || $candidate->isDescendantOf($node)) {
                $barred[] = $candidate->id;
            }
        }

        // ⚠️ **The move button opens the dialog and a second one inside it confirms.** The owner:
        // *button move with dialog tree chooser*, then *nicht inline*. **A `<label>` is the opener**,
        // because a `<button>` inside a form would submit it — so the trigger looks like a button and
        // is not one, and the only real button is the confirm inside the overlay.
        // ⚠️ **The classes go on the `<label>` itself and not on a `<span>` inside it.** A `<span
        // class="button">` is styled like a button and laid out like a span, which is why the owner saw
        // the move button *«leicht versetzt»* beside the others. *The label is the control; nesting a
        // fake one inside it gave the box model two owners.*
        $trigger = '<span class="dashicons dashicons-move" aria-hidden="true"></span>'
            . '<span class="screen-reader-text">' . esc_html__('Move', 'taxmod') . '</span>';

        $confirm = '<button class="button button-primary" name="do" value="move">'
            . esc_html__('Move here', 'taxmod') . '</button>';

        return $this->rendering->chooserFor(
            $rows,
            'target',
            $node->parentId(),
            $barred,
            $this->labels->of($node, SeededRole::Form, $this->localeFromRequest()),
            __('Nothing here can be a parent.', 'taxmod'),
            DialogChooserRenderer::NAME,
            $this->localeFromRequest(),
            Level::Admin,
            $trigger,
            $confirm
        )->markup;
    }

    /**
     * What the selected node has — its own attributes and the ones it inherits.
     *
     * ⚠️ **It draws no value**: names, targets and the kind, nothing rendered. The moment a
     * value has to appear it goes through a renderer
     * ([R20a](../../../docs/NewConcept/30-renderer.md)), and this panel is deleted rather than
     * grown ([D-344](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    private function attributes(Node $selected, array $rows): string
    {
        $edges = $this->editor->attributesOf($selected->id);
        $body  = '';

        // ⚠️ **Through the renderer, and the acts arrive as **facts** rather than as markup**
        // ([D-376](../../../docs/NewConcept/90-decision-log.md)). The boundary knows the nonce, the
        // URL, the words and what is allowed; the renderer decides the shape of the row. That is the
        // difference between `R1` being followed and a renderer that concatenates somebody's HTML.
        $actions = [];
        $submits = [];

        foreach ($edges as $edge) {
            $own = $edge->fromId === $selected->id;

            $actions[$edge->id] = [
                // ⚠️ The two words the core cannot make ([OQ-087](../../../docs/NewConcept/91-open-questions.md)):
                // the text domain is the boundary's (`AR-2`), so they travel with the controls.
                new Control('word:own', '', __('own', 'taxmod')),
                new Control('word:inherited', '', __('inherited', 'taxmod')),
                new Control('word:settings', '', __('Settings of this use site', 'taxmod')),
                // ⚠️ **One button for the whole row, not one per value** — the owner's ask for the
                // settings side applies here for the same reason: a row with two independent submits
                // has no answer to *what does Enter do*. It writes the name and the multiplicity
                // together, and each only where it actually changed.
                new Control(
                    'do',
                    'save_attribute',
                    // The owner likes the diskette and it stays the sign for saving everywhere.
                    '💾',
                    __('Save this attribute — its name and how often it may occur', 'taxmod'),
                    $own
                ),
                // ⚠️ **Duplicate, and only for an own attribute** — the owner: *duplicate for the
                // attribute is missing too.* An inherited one belongs to the ancestor that declared
                // it, so copying it from here would put a second declaration where the first never was.
                //
                // ⚠️ *The copy needs a **different name**: [D-281](../../../docs/NewConcept/90-decision-log.md)
                // refuses an edge with the same `from`, `kind`, `to` **and name**, because the name is
                // part of what makes an edge itself. So the boundary supplies «(copy)» — a translatable
                // word the core has no business inventing (`AR-2`).*
                new Control(
                    'do',
                    'duplicate_attribute',
                    __('Duplicate', 'taxmod'),
                    __('Copy this attribute with its settings — under a new name, because an edge is partly its name', 'taxmod'),
                    $own,
                    icon: 'admin-page'
                ),
                // ⚠️ **Only an own attribute can be removed here.** An inherited one belongs to the
                // ancestor that declared it; removing it from a descendant would be
                // [D-155](../../../docs/NewConcept/90-decision-log.md)'s *moved down* by another
                // route, which is a different act and not this button. **Greyed rather than absent**
                // (D-370), so the row keeps its shape.
                new Control(
                    'do',
                    'remove_attribute',
                    __('Remove', 'taxmod'),
                    __('Remove this attribute — parked, not purged', 'taxmod'),
                    $own,
                    true,
                    'trash'
                ),
            ];

            $submits[$edge->id] = new Submission(
                admin_url('admin-post.php'),
                [
                    'action'         => self::ACTION,
                    'id'             => (string) $selected->id,
                    'edge'           => (string) $edge->id,
                    'setting_key'    => SettingKey::Multiplicity->value,
                    '_taxmod_nonce'  => wp_create_nonce(self::ACTION . '_' . $selected->id),
                ]
            );
        }

        $settingSubmits = [];

        foreach ($edges as $edge) {
            $settingSubmits[$edge->id] = $this->settingSubmission($edge->id, $selected->id);
        }

        $attributeRows = $this->rendering->attributesFor(
            $edges,
            $selected->id,
            $actions,
            $submits,
            self::NAME_FIELD,
            self::SETTING_FIELD,
            '',
            \Taxmod\Core\Renderer\Level::Admin,
            $this->settingActs(),
            $settingSubmits,
            __('Settings of this use site', 'taxmod')
        );

        foreach ($attributeRows as $row) {
            $body .= $row->result->markup;
        }

        $html = $this->heading(
            __('Attributes', 'taxmod'),
            __('What this node has. «Kind» is not a choice — it follows from where the target sits in the tree. «own» means declared here; «inherited» means it belongs to a node further up and can only be changed there.', 'taxmod')
        );

        $html .= $body === ''
            ? '<p><em>' . esc_html__('None yet.', 'taxmod') . '</em></p>'
            : '<table class="wp-list-table widefat striped"><thead><tr>'
                . '<th>' . esc_html__('Name', 'taxmod') . '</th>'
                . '<th>' . esc_html__('Points at', 'taxmod') . '</th>'
                . '<th style="width:8em">' . esc_html__('Kind', 'taxmod') . '</th>'
                . '<th style="width:5em">' . esc_html__('From', 'taxmod') . '</th>'
                . '<th style="width:11em">' . esc_html__('How many', 'taxmod') . '</th>'
                . '<th style="width:3em"></th>'
                . '</tr></thead><tbody>' . $body . '</tbody></table>';

        return $html . $this->removedAttributes($selected) . $this->attributeForm($selected, $rows);
    }

    /**
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    /**
     * The removed attributes, behind a disclosure — D-128's *show deleted*.
     *
     * ⚠️ **Hidden by default and one click away, because that is what [D-128](../../../docs/NewConcept/90-decision-log.md)
     * decided:** *a model full of ghost attributes is unreadable — one «show deleted» toggle away,
     * greyed, labelled «deleted with X», with a restore action.* The label names the **act** that
     * removed it, which is what `parked_by_group_id` carries ([D-371](../../../docs/NewConcept/90-decision-log.md)).
     */
    private function removedAttributes(Node $selected): string
    {
        $parked = $this->editor->removedAttributesOf($selected->id);

        if ($parked === []) {
            return '';
        }

        $rows = '';

        foreach ($parked as $edge) {
            $rows .= '<div style="display:flex;gap:.5em;align-items:center;opacity:.6;margin:.2em 0">'
                . '<span style="flex:1"><s>' . esc_html($edge->name) . '</s> '
                . '<span class="description">' . esc_html(sprintf(
                    /* translators: %d is the id of the act that removed it. */
                    __('removed by act #%d', 'taxmod'),
                    (int) $edge->parkedByGroup
                )) . '</span></span>'
                . $this->form(
                    $selected->id,
                    [['restore_attribute', esc_html__('Restore', 'taxmod'), __('Put it back', 'taxmod')]],
                    '<input type="hidden" name="edge" value="' . (int) $edge->id . '">'
                )
                . '</div>';
        }

        return '<details style="margin:.6em 0"><summary style="cursor:pointer">'
            . esc_html(sprintf(
                /* translators: %d is how many attributes were removed. */
                _n('%d removed attribute', '%d removed attributes', count($parked), 'taxmod'),
                count($parked)
            ))
            . '</summary>' . $rows . '</details>';
    }

    private function attributeForm(Node $selected, array $rows): string
    {
        $options = '';

        foreach ($rows as $row) {
            $candidate = $row['node'];
            $branch    = $this->framework->branchOf($candidate);

            // Only what could actually be a target: inside a branch, and not the branch root
            // itself (D-238).
            if ($branch === null || $candidate->id === $this->framework->rootOf($branch)->id) {
                continue;
            }

            $options .= '<option value="' . (int) $candidate->id . '">'
                . esc_html($candidate->name . ' — ' . $branch->value)
                . '</option>';
        }

        if ($options === '') {
            return '<p><em>'
                . esc_html__('Nothing to point at yet: put a node under Model, Compositions, Data Types or Constants first.', 'taxmod')
                . '</em></p>';
        }

        return $this->form(
            $selected->id,
            [['add_attribute', esc_html__('Add attribute', 'taxmod'), __('Point at a target; the kind follows', 'taxmod')]],
            '<input type="text" name="name" placeholder="' . esc_attr__('Name of the attribute', 'taxmod') . '" required style="flex:1">'
            . '<select name="target" style="flex:1">' . $options . '</select>'
        );
    }


    /**
     * The node's settings — **the same panel the attribute row shows**.
     *
     * ⚠️ **One panel, and the owner said it twice.** First *the settings under the attribute have to
     * look exactly like the settings in the node*, then, having compared them: *take the attribute
     * view, it looks better.* So the hand-built table that stood here is gone and
     * {@see \Taxmod\Core\Renderer\SettingsRenderer} draws both. **What was wrong was not the look but
     * that there were two of them** — `R1` allows one way to draw a thing, and the last time two
     * existed the multiplicity control quietly posted to a field nobody read ([D-376](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *The icon row keeps its own control, because every tile has to submit the icon it stands
     * for and a form may not sit inside another form. It is placed **after** the panel rather than
     * inside it — one exception, visible, instead of a special case threaded through the renderer.*
     */
    private function settingsPanel(Node $selected): string
    {
        $resolved = $this->settings->resolve($this->settings->chainFor($selected));

        ksort($resolved);

        $panel = $this->rendering->settingsPanelFor(
            $selected,
            $resolved,
            $this->settingActs(),
            $this->settingSubmission($selected->id, $selected->id),
            Purpose::Edit,
            self::SETTING_FIELD,
            '',
            \Taxmod\Core\Renderer\Level::Admin,
            [],
            // ⚠️ **Which icons exist is a boundary fact** (`CD-1`) — the core cannot list Dashicons —
            // so the set is handed in and the chooser places it (D-390). *The owner: `with the
            // settings simply do a group by category and write it above`; the icon then needs no row
            // of its own at all, which is what it had been given as a workaround.*
            [SettingKey::Icon->value => $this->iconChoices()]
        );

        $html = $this->heading(
            __('Settings', 'taxmod'),
            __('Everything that can be set here, whether or not anybody has. A value not set here is inherited from further up — the column on the right says where it came from.', 'taxmod')
        );

        $html .= $panel->markup === ''
            ? '<p><em>' . esc_html__('Nothing set anywhere along the chain.', 'taxmod') . '</em></p>'
            : $panel->markup;

        return $html;
    }


    /**
     * Every Dashicon key with its glyph, read out of the stylesheet WordPress ships.
     *
     * ⚠️ **Read, not listed.** The file states `.dashicons-marker:before { content: "\f159" }` for
     * each one; parsing it means the glyphs are always the installed version's, and an icon that
     * moves cannot leave a stale copy behind. **If the file is unreadable the map is empty** and the
     * list shows names only — a missing picture rather than a wrong one.
     *
     * @return array<string, string> Key without the `dashicons-` prefix, to the character.
     */
    private function dashiconGlyphs(): array
    {
        $file = ABSPATH . WPINC . '/css/dashicons.css';

        if (! is_readable($file)) {
            return [];
        }

        $css = (string) file_get_contents($file);

        preg_match_all(
            '/\.dashicons-([a-z0-9-]+):before\s*\{\s*content:\s*"\\\\([0-9a-f]{4})"/i',
            $css,
            $found,
            PREG_SET_ORDER
        );

        $glyphs = [];

        foreach ($found as $one) {
            $glyphs[$one[1]] = mb_chr((int) hexdec($one[2]), 'UTF-8');
        }

        return $glyphs;
    }

    /**
     * The renderer, **chosen** — never typed.
     *
     * ⚠️ **The owner's own words, and they settle it:** *of course the renderer should be picked —
     * there are only certain ones for the current purpose, and how would the user know the name?*
     * ([D-358](../../../docs/NewConcept/90-decision-log.md)). The generic box above writes a
     * setting by typing its value, which for this key means knowing a token that lives in the
     * code. **So this key gets the one control it always needed**, and the eligible set comes from
     * {@see \Taxmod\Core\Service\Rendering::choicesForNode()} — the same core method the real
     * panel will ask, narrowed by type **and** by purpose.
     *
     * ⚠️ **Not a rendered setting, and therefore not the line [R20a](../../../docs/NewConcept/30-renderer.md)
     * draws.** It draws no value: it offers a set the core computed. *A picker that consumes
     * `eligibleFor` cannot drift from the renderers, because it has no opinion of its own about
     * what fits.*
     */
    private function rendererChoice(Node $selected): string
    {
        $eligible = $this->rendering->choicesForNode($selected);

        if ($eligible === []) {
            // ⚠️ Honest rather than empty-but-open: a node with no simple type wants a
            // **structural** renderer — a form, a table — and none is built. Offering the typed
            // ones here would be offering a spinner for a supplier.
            return '<p class="description">'
                . esc_html__('No renderer can be chosen here yet — this node is not a simple data type, and the structural renderers are not built.', 'taxmod')
                . '</p>';
        }

        $options = '';

        foreach ($eligible as $renderer) {
            $options .= '<option value="' . esc_attr($renderer->name()) . '">'
                . esc_html($renderer->name())
                . '</option>';
        }

        return $this->form(
            $selected->id,
            [['put_renderer', esc_html__('Use this renderer', 'taxmod'), __('Only the renderers that can draw this type are offered', 'taxmod')]],
            '<select name="renderer_name" style="flex:1">' . $options . '</select>'
        );
    }


    /**
     * What the node is called — **through the renderer**, and enterable.
     *
     * ⚠️ **The third hand-built panel to go** ([D-384](../../../docs/NewConcept/90-decision-log.md)),
     * after the attribute table and the settings panel. It printed `esc_html` into a table and had no
     * way to **enter** anything: every text went through a role-plus-locale form at the bottom, which
     * is the *select something at the bottom* the owner had already had removed once from the settings
     * side.
     *
     * ⚠️ **His layout, and each part of it earns its place:** the locale is chosen **once** at the top,
     * because a person works in a language and not in a language per field; the short roles share one
     * line because they hold words; `help` gets its own row because it holds a sentence *that doubles
     * as the tooltip and ends the chain* ([D-209](../../../docs/NewConcept/90-decision-log.md)); and a
     * blank line marks the seam, which is the one distinction this panel makes.
     */
    private function labelsPanel(Node $selected): string
    {
        $locale = $this->localeFromRequest();
        $stored = [];

        foreach ($this->labels->storedFor($selected->id) as $label) {
            if ($label->path === '') {
                $stored[$label->roleId . "\0" . $label->locale] = $label->text;
            }
        }

        $slots = [];

        foreach (SeededRole::cases() as $role) {
            $roleId = $this->framework->roleId($role);

            $slots[] = new LabelSlot(
                $role->value,
                // ⚠️ What the chain answers — another locale, `help`, and in the end the node's own
                // name (D-020). It becomes the field's placeholder, so an empty box reads as *nothing
                // is stored here* rather than as *this has no name*.
                $this->labels->of($selected, $role, $locale),
                $stored[$roleId . "\0" . $locale] ?? null,
                self::LABEL_FIELD . '[' . $role->value . ']',
                // ⚠️ `help` is the long one, and that is read off the role rather than off a list of
                // names in this method — a sixth role lands somewhere sensible by itself (`CD-9`).
                $role === SeededRole::Help,
                $role->translatableByDefault(),
                $role->translatableByDefault()
                    ? ''
                    : __('the same in every language', 'taxmod')
            );
        }

        $html = $this->heading(
            __('Labels', 'taxmod'),
            __('What this node is called, in the language chosen on the left. Leave a field empty and the grey text is what will be shown instead — in the end, the node\'s own name.', 'taxmod')
        );

        return $html . $this->rendering->labelsPanelFor(
            $selected,
            $slots,
            [new Control('do', LabelsRenderer::WRITE, '💾', __('Write every text for this locale', 'taxmod'))],
            new Submission(
                admin_url('admin-post.php'),
                [
                    'action'        => self::ACTION,
                    'id'            => (string) $selected->id,
                    'label_locale'  => $locale,
                    '_taxmod_nonce' => wp_create_nonce(self::ACTION . '_' . $selected->id),
                ]
            ),
            ['locale' => new Section(__('Locale', 'taxmod'), $this->localePicker($locale, $selected->id))],
            Purpose::Edit,
            $locale
        )->markup;
    }

    /**
     * The locale, switched without carrying anything along.
     *
     * ⚠️ **A `GET` and not a form of its own inside the panel**, because switching language is
     * **navigation**: it must not save half-typed entries, and the chosen locale then survives in the
     * URL the way the folded set does ([OQ-082](../../../docs/NewConcept/91-open-questions.md)).
     *
     * ⚠️ *The offered set is the neutral row plus whatever WordPress has installed — a boundary fact
     * (`CD-1`), which is why it is composed here and handed in as a finished control.*
     */
    private function localePicker(string $current, int $nodeId): string
    {
        // ⚠️ **One locale *is* the neutral one, rather than «neutral» being a choice of its own**
        // ([D-387](../../../docs/NewConcept/90-decision-log.md)). The owner: *neutral is probably too
        // much — we declare `en_US` as neutral in the admin config.* The empty `locale` column stays
        // exactly what it was — **a row that is valid everywhere** — but a person no longer picks it
        // as a language, because it is not one.
        //
        // ⚠️ *Which locale plays the part belongs on an installation screen that does not exist yet —
        // the same hole `developer` sits in ([OQ-039](../../../docs/NewConcept/91-open-questions.md)).
        // Until it does, the **site's own language** plays it, which is admin configuration in
        // WordPress's own terms and needs nothing invented.*
        $offered = [];

        foreach (get_available_languages() as $one) {
            $offered[$one] = $one;
        }

        // ⚠️ **«default» and not «everywhere», on the owner's word** — *everywhere is odd, default
        // would be nicer.* He is right: *everywhere* describes the **row**, which is stored without a
        // locale and therefore valid anywhere; a person choosing a language wants to know which one is
        // **the** one. *And he is right to be unsure it belongs in the list at all — see the working
        // list: once the admin page can declare it, this is a fact about the installation and the
        // picker can simply show the language.*
        $offered[self::neutralLocale()] = sprintf(
            /* translators: %s is a locale code, e.g. en_US. */
            __('%s — default', 'taxmod'),
            self::neutralLocale()
        );

        ksort($offered);

        $options = '';

        foreach ($offered as $value => $shown) {
            $options .= '<option value="' . esc_attr((string) $value) . '"'
                // ⚠️ The screen works in `''` where the neutral locale was picked, so the option to
                // mark is the neutral one — otherwise the picker would show nothing selected on the
                // very language it is working in.
                . selected($current === '' ? self::neutralLocale() : $current, (string) $value, false)
                . '>' . esc_html((string) $shown) . '</option>';
        }

        // ⚠️ **A bare `select` that navigates, and no form of its own** — because the owner wants it
        // on the same line as the short fields (*then we save space*), and those live **inside** the
        // entry form, where HTML forbids a second one. *It carries no `name`, so it submits nothing
        // when the entry form is saved: it is a control that moves, not a value that is written.*
        //
        // ⚠️ *The cost is honest and small: without scripting it does nothing, so the locale is
        // reachable only through the URL. The admin screen already depends on scripting for the
        // Dashicon glyphs and the fold state; a hidden submit button beside it would be a second way
        // to do one thing, which is what `R1` argues against everywhere else.*
        $base = add_query_arg(['page' => 'taxmod', 'taxmod_node' => (int) $nodeId], admin_url('admin.php'));

        // ⚠️ The address is built with a marker and the marker is replaced in the browser, so the
        // locale never has to be spliced into a URL by string arithmetic on either side.
        $pattern = esc_url_raw(add_query_arg('taxmod_locale', self::LOCALE_MARKER, $base));

        return '<select class="taxmod-locale" style="max-width:100%"'
            . ' onchange="location.href=' . esc_attr(wp_json_encode($pattern))
            . '.replace(' . esc_attr(wp_json_encode(self::LOCALE_MARKER))
            . ',encodeURIComponent(this.value))">'
            . $options . '</select>';
    }

    /**
     * Write every field of one record.
     *
     * ⚠️ **Nothing is guessed any more, and that is [D-350](../../../docs/NewConcept/90-decision-log.md)
     * closing.** The raw surface read a number as a number and everything else as text, which was
     * defensible only for as long as no renderer existed. Each attribute now has a **type**, and
     * the type reads its own characters back ({@see \Taxmod\Core\Model\SimpleType::valueFrom()}) —
     * refusing what cannot have been meant instead of storing a zero nobody typed.
     *
     * ⚠️ **Only submitted attributes are touched.** A hidden field is not in the form, and a
     * hidden field is not a cleared one.
     */
    private function saveRecord(int $nodeId): void
    {
        $recordId  = isset($_POST['record_id']) ? absint($_POST['record_id']) : 0;
        $submitted = isset($_POST[self::VALUE_FIELD]) && is_array($_POST[self::VALUE_FIELD])
            ? $_POST[self::VALUE_FIELD]
            : [];

        if ($submitted === []) {
            return;
        }

        $attributes = [];

        foreach ($this->editor->attributesOf($nodeId) as $edge) {
            $attributes[$edge->id] = $edge;
        }

        // One resolution for the whole form rather than one per field (`CD-7`).
        $types = $this->rendering->typesFor(array_values($attributes));

        foreach ($submitted as $rawEdge => $rawValue) {
            $edgeId = absint($rawEdge);
            $edge   = $attributes[$edgeId] ?? null;

            if ($edge === null) {
                // An edge id from a form is input. DataEntry refuses it as well; refusing twice
                // costs nothing and this one keeps a stale form from reaching the core at all.
                continue;
            }

            $characters = trim(sanitize_text_field(wp_unslash((string) $rawValue)));

            if ($characters === '') {
                // Empty means unanswered — the row goes, which is a third state beside a value
                // and an explicit nothing.
                $this->data->clear($recordId, $edgeId);

                continue;
            }

            $type = $types[$edgeId] ?? null;

            if ($type === null) {
                throw NotYetStorable::thatAttributeHasNoTypeYet($edge->name);
            }

            $this->data->put($recordId, $edgeId, $type->valueFrom($characters));
        }
    }

    /**
     * Which locale the screen is working in, as the **storage** spells it.
     *
     * ⚠️ **The neutral locale maps to the empty column, and that mapping lives here alone**
     * ([D-387](../../../docs/NewConcept/90-decision-log.md)). The owner: *we declare `en_US` as
     * neutral in the admin config.* So a person picks a real language, and the one declared neutral
     * is stored as the **row that is valid everywhere** — which is what an empty `locale` has always
     * meant ([D-317](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *One place, because a mapping done in two would eventually store `en_US` in one and `''` in
     * the other, and the two rows would then disagree with nobody able to say which is right.*
     */
    private function localeFromRequest(): string
    {
        $asked = isset($_GET['taxmod_locale'])
            ? sanitize_text_field(wp_unslash($_GET['taxmod_locale']))
            : self::neutralLocale();

        return $asked === self::neutralLocale() ? '' : $asked;
    }

    /**
     * Which language stands for *everywhere*.
     *
     * ⚠️ **The site's own, until there is a screen to declare it on.** An installation posture
     * belongs on an installation screen and there is none — the same hole `developer` sits in
     * ([OQ-039](../../../docs/NewConcept/91-open-questions.md)). *Reading `get_locale()` is admin
     * configuration in WordPress's own terms, so nothing is invented and nothing has to be migrated
     * when the screen arrives: one function changes.*
     */
    private static function neutralLocale(): string
    {
        // ⚠️ **Declared on the installation screen now** ([D-397](../../../docs/NewConcept/90-decision-log.md)),
        // and it still falls back to the site language where nobody has declared one — so an
        // installation that never opens that screen behaves exactly as it did.
        return SettingsScreen::neutralLocale();
    }


    /**
     * Records entered against this node, and a way to enter one.
     *
     * ⚠️ **[D-350](../../../docs/NewConcept/90-decision-log.md) is closed here, on its own
     * terms.** That decision bent [D-344](../../../docs/NewConcept/90-decision-log.md) once to
     * allow *one raw text field per attribute*, and said in as many words: **deleted, not
     * evolved, the moment the renderers arrive.** They have arrived. The field is gone and every
     * value on this screen now goes through {@see \Taxmod\Core\Service\Rendering} — the same
     * descent the real surface will use, so there is no second way to draw a field
     * ([R20a](../../../docs/NewConcept/30-renderer.md)).
     *
     * ⚠️ **What is still scaffolding is the frame, not the fields.** The row, the box and the
     * diagnostics below are markup that will be thrown away with the rest
     * ([D-344](../../../docs/NewConcept/90-decision-log.md)). What will not be thrown away is the
     * rendering, because it is not drawn here at all.
     */
    private function recordsPanel(Node $selected): string
    {
        $branch = $this->framework->branchOf($selected);

        if ($branch === null || ! $branch->holdsData()) {
            return $this->heading(
                __('Records', 'taxmod'),
                __('Nothing can be entered here. Only nodes under Model and Compositions hold records; a data type or a constant describes something rather than being one.', 'taxmod')
            );
        }

        $attributes = $this->editor->attributesOf($selected->id);
        $records    = $this->data->recordsOf($selected->id);

        $html = $this->heading(
            __('Records', 'taxmod'),
            __('Things entered against this node. Each field looks the way its type says it should; a field marked «no renderer» is missing something, not styled oddly.', 'taxmod')
        );

        $html .= $this->form(
            $selected->id,
            [['add_record', esc_html__('New record', 'taxmod'), __('Start a record against this node', 'taxmod')]]
        );

        if ($records === []) {
            return $html . '<p><em>' . esc_html__('None yet.', 'taxmod') . '</em></p>';
        }

        foreach ($records as $record) {
            $held = [];

            foreach ($this->data->valuesOf($record->id) as $value) {
                $held[$value->edgeId] = $value->value;
            }

            // ⚠️ **Through the renderer** (D-393). The screen states the facts — what this record
            // is called, where a save goes, the nonce — and {@see RecordRenderer} decides the shape.
            $html .= $this->rendering->recordAsBlock(
                $selected,
                $attributes,
                $held,
                sprintf(
                    /* translators: 1: record id, 2: the model version it was written against. */
                    __('Record #%1$d · written against version %2$d', 'taxmod'),
                    $record->id,
                    $record->modelVersion
                ),
                [new Control('do', 'save_record', '💾', __('Write these values', 'taxmod'))],
                new Submission(
                    admin_url('admin-post.php'),
                    [
                        'action'        => self::ACTION,
                        'id'            => (string) $selected->id,
                        'record_id'     => (string) $record->id,
                        '_taxmod_nonce' => wp_create_nonce(self::ACTION . '_' . $selected->id),
                    ]
                ),
                self::VALUE_FIELD,
                $this->drawnBy($this->rendering->fieldsFor($attributes, $held, Purpose::Edit)),
                $this->inDeveloperMode()
            )->markup;
        }

        return $html;
    }

    /**
     * One rendered attribute in the record box.
     *
     * ⚠️ **The renderer's markup goes out as it is, and that is deliberate.** `CD-5` ends with
     * *escape on output*, and it has already happened: a renderer escapes with
     * {@see \Taxmod\Core\Renderer\RenderResult::escape()} before returning, because markup that
     * left unescaped would have no second chance (`CD-1` bars it from `esc_html()`). **Escaping it
     * again here would print the tags instead of the field.** Everything this method adds around
     * it — names, types, words — is escaped in the ordinary way.
     *
     * ⚠️ **`no renderer` is shown, not hidden.** It means the chain named none and the type has no
     * default, which [R14b](../../../docs/NewConcept/30-renderer.md) says must look like the fault
     * it is rather than like a plain field.
     */
    /**
     * Which renderer drew what — a diagnostic beside the form, never inside it.
     *
     * ⚠️ **It earns its place by what it has caught.** In one afternoon: a `field` where a
     * `reference` belonged, every constant reading *no renderer*, a spinner offered for a supplier,
     * and eight settings rows drawn empty. **None of those was visible in the markup itself** —
     * only in the answer to *which renderer drew this*.
     *
     * @param list<RenderedField> $fields
     */
    private function drawnBy(array $fields): string
    {
        $lines = '';

        foreach ($fields as $field) {
            $what = $field->hasNoRenderer()
                ? '<strong style="color:#b32d2e">' . esc_html__('no renderer', 'taxmod') . '</strong>'
                : esc_html(($field->type?->value ?? '—') . ' · ' . $field->rendererName);

            $lines .= '<li><code>' . esc_html($field->edge->name) . '</code> — ' . $what
                . ($field->isHidden() ? ' · ' . esc_html__('hidden by a setting', 'taxmod') : '')
                . '</li>';
        }

        return $lines === ''
            ? ''
            : '<ul class="description" style="margin:.4em 0 0;opacity:.75">' . $lines . '</ul>';
    }

    // ------------------------------------------------------------------ acting


    /**
     * The submitted renderer name, only if a renderer of that name exists.
     *
     * ⚠️ **A `<select>` is input, so it is checked** (`CD-5`) — but against what **exists**, not
     * against what is **eligible**. [D-360](../../../docs/NewConcept/90-decision-log.md): the
     * eligible set is what the screen **offers** ([R14](../../../docs/NewConcept/30-renderer.md#r12r17):
     * *so the settings UI can offer a choice*), and the owner drew the line where R14 leaves it —
     * *you cannot turn a text into a binary number; well, you can, it just makes no sense, **unless
     * you have a special use case***.
     *
     * ⚠️ *The first version of this refused anything off the list, which is a fence R14 does not
     * build.* What is still caught is the thing that is genuinely broken: a name **no renderer
     * answers to** resolves to the fallback and shows as *no renderer* on a node that has one — a
     * fault two steps from its cause.
     */
    private function registeredRendererName(int $nodeId, string $submitted): string
    {
        $node = $this->editor->find($nodeId) ?? throw NodeNotFound::withId($nodeId);

        if (! $this->rendering->knowsRenderer($submitted)) {
            throw SettingDoesNotApply::thatRendererCannotDrawThis($submitted, $node->name);
        }

        return $submitted;
    }

    /** The chain a setting written **at this node** belongs to. */
    /**
     * The chain a setting is written to — the **node's**, or the **use site's** when an edge is named.
     *
     * ⚠️ **The edge case had no way in until the panels were unified** ([D-381](../../../docs/NewConcept/90-decision-log.md)).
     * The attribute row now shows every setting that applies to a use site, and `persistent`
     * ([D-378](../../../docs/NewConcept/90-decision-log.md)) is one of them — so a write that landed
     * on the **node** instead would set it for the type and every other user of it, quietly. *The
     * form states which owner it means; a zero means the node, which is what an absent edge has always
     * meant on this screen.*
     */
    private function settingChain(int $nodeId, int $edgeId = 0): array
    {
        if ($edgeId !== 0) {
            return $this->settings->chainForUseSite($this->editor->ownAttribute($nodeId, $edgeId));
        }

        return $this->settings->chainFor($this->editor->find($nodeId) ?? $this->framework->root());
    }

    /**
     * Turn what somebody typed into a typed value.
     *
     * ⚠️ **A scaffolding guess, and deliberately a crude one.** The real editor knows the type
     * of the setting and offers the right control; here a number is a number, everything else
     * is text. Nothing about this survives the renderers.
     */
    /**
     * A submitted setting value, read as the type its **key** declares.
     *
     * ⚠️ **The last guesser in the codebase, and it is gone.** It used to read a number as a
     * number and everything else as text — the same regex guessing [D-354](../../../docs/NewConcept/90-decision-log.md)
     * removed from record values, left behind here because nothing said what type a setting has.
     * {@see SettingKey::typeFor()} says it now, so `mandatory` reads as a boolean and `range_min`
     * on a decimal node as an exact decimal.
     *
     * ⚠️ **Two cases genuinely have no declared type, and they keep characters — named rather
     * than hidden:** a **free key**, which belongs to whoever made it and about which the engine
     * knows nothing, and a *borrowing* key on a node that is not a simple data type, which has no
     * shape to borrow. *Neither is a guess about a value; both are the absence of a claim.*
     */
    private function settingValue(int $nodeId, string $key, string $raw): TypedValue
    {
        $raw = trim($raw);

        if ($raw === '') {
            return TypedValue::nothing();
        }

        $node = $this->editor->find($nodeId);
        $type = $node === null
            ? null
            : SettingKey::tryFrom($key)?->typeFor($this->rendering->typeOfNode($node));

        return $type?->valueFrom($raw) ?? TypedValue::ofText($raw);
    }

    private function selectedFromRequest(): ?Node
    {
        if (! isset($_GET['taxmod_node'])) {
            return null;
        }

        return $this->editor->find(absint($_GET['taxmod_node']));
    }

    /** @return list<int> */
    private function collapsedFromRequest(): array
    {
        if (! isset($_GET['taxmod_collapsed'])) {
            return [];
        }

        $raw = sanitize_text_field(wp_unslash($_GET['taxmod_collapsed']));

        return array_values(array_filter(array_map('absint', explode(',', $raw))));
    }

    /**
     * One row of the attribute table, saved — its name and how often it may occur.
     *
     * ⚠️ **Two acts behind one button, and each runs only where something changed.** The owner asked
     * for this shape on the settings side — *first the buttons at the top as the concept describes,
     * not every value on its own* — and a row with two independent submits has no answer to *what
     * does Enter do*. **Writing unconditionally would be worse than not offering the button**: every
     * click would land a changelog entry and a version bump for a row nobody touched, and
     * [D-349](../../../docs/NewConcept/90-decision-log.md)'s write count would stop meaning
     * *somebody changed this*.
     *
     * ⚠️ *The multiplicity is written through the ordinary settings path, so `D-312`'s narrowing rule
     * still applies and a widening is still refused by the core rather than here.*
     */
    private function saveAttribute(int $id, int $edge, string $name, string $multiplicity): void
    {
        $existing = $this->editor->ownAttribute($id, $edge);

        if ($name !== '' && $name !== $existing->name) {
            $this->editor->renameAttribute($id, $edge, $name);
        }

        if ($multiplicity === '') {
            return;
        }

        $chain  = $this->settings->chainForUseSite($existing);
        $before = $this->settings->resolve($chain)[SettingKey::Multiplicity->value] ?? null;

        if ($before?->value->text === $multiplicity) {
            return;
        }

        $this->settings->put($chain, SettingKey::Multiplicity->value, TypedValue::ofText($multiplicity));
    }

    /**
     * The three acts every settings row offers, and the words it needs.
     *
     * ⚠️ **Handed in once and greyed per row by the renderer**, which is where that decision belongs:
     * `Nothing` means nothing for a choice — its empty option already is nothing — and `Reset` only
     * means something where the value was written here. Both follow from what the drawn row already
     * carries, so the boundary would have to re-derive them.
     *
     * @return list<Control>
     */
    private function settingActs(): array
    {
        return [
            // ⚠️ **The write act stays in the list although a row no longer draws it** — the renderer
            // skips it, and the page-head button submits the same form. *Keeping one list means the
            // words and the nonce are declared once.*
            new Control('do', SettingsRenderer::WRITE, '💾', __('Save every setting on this page', 'taxmod')),
            // ⚠️ **The bin again, on the owner's ask** — *the Nothing button could be the bin again.*
            // It **is** a removal: *deliberately nothing here* stops the chain, so a later change
            // further up will not arrive. `destroys` paints it red for the same reason.
            new Control(
                'do',
                SettingsRenderer::EMPTY,
                __('Nothing', 'taxmod'),
                __('Deliberately nothing here — later changes above will not arrive', 'taxmod'),
                true,
                true,
                'trash'
            ),
            new Control(
                'do',
                SettingsRenderer::RESET,
                __('Reset', 'taxmod'),
                __('Make it inherited again — not the same as setting it to nothing', 'taxmod'),
                true,
                false,
                'undo'
            ),
            new Control('word:inherited', '', __('inherited from further up', 'taxmod')),
            new Control('word:here', '', __('here', 'taxmod')),
            new Control('word:undefined', '', __('not defined', 'taxmod')),
            // ⚠️ The category headings (D-385). Words, so they travel like every other word the core
            // cannot make itself (`AR-2`, OQ-087).
            // ⚠️ *Back to «Display» on the owner's word. I had renamed it to «How it is handled» because the
            // group holds the validator and the converter as well as the renderer — true, and he prefers
            // the shorter one. His screen, his word.*
            new Control('word:display', '', __('Display', 'taxmod')),
            new Control('word:rules', '', __('Rules', 'taxmod')),
            // ⚠️ **One word per simple type**, because a group can be named after the type being
            // configured (D-390) and the core cannot make a word (`AR-2`, OQ-087). *Generated from
            // the enum rather than listed, so a twelfth type gets a heading without anybody
            // remembering — the word is its own name until somebody translates it.*
            ...array_map(
                static fn (SimpleType $type): Control => new Control('word:' . $type->value, '', $type->humanName()),
                SimpleType::cases()
            ),
        ];
    }

    private function settingSubmission(int $ownerId, int $nodeId): Submission
    {
        return new Submission(
            admin_url('admin-post.php'),
            [
                'action'        => self::ACTION,
                'id'            => (string) $nodeId,
                // ⚠️ Present only for an **edge**, and the handler reads it to know which owner the
                // setting belongs to. Zero for a node, which is what *no edge* has always meant here.
                'edge'          => (string) ($ownerId === $nodeId ? 0 : $ownerId),
                '_taxmod_nonce' => wp_create_nonce(self::ACTION . '_' . $nodeId),
            ]
        );
    }

    /**
     * Every label of one node, for one locale, in one act.
     *
     * ⚠️ **An empty field means *forget it here*, not *store an empty text*.** The two are different
     * ([D-020](../../../docs/NewConcept/90-decision-log.md)): a stored empty string would **end** the
     * fallback chain and the node would read as nameless, while an absent row lets the chain answer.
     * *So a cleared box removes the row, which is what clearing a box means.*
     *
     * ⚠️ *Written only where something changed, so a save on an untouched panel is not five writes and
     * five changelog entries — the same reasoning as the attribute row's.*
     */
    private function saveLabels(int $nodeId, string $locale): void
    {
        $submitted = isset($_POST[self::LABEL_FIELD]) && is_array($_POST[self::LABEL_FIELD])
            ? wp_unslash($_POST[self::LABEL_FIELD])
            : [];

        $stored = [];

        foreach ($this->labels->storedFor($nodeId) as $label) {
            if ($label->path === '' && $label->locale === $locale) {
                $stored[$label->roleId] = $label->text;
            }
        }

        foreach (SeededRole::cases() as $role) {
            if (! array_key_exists($role->value, $submitted)) {
                continue;
            }

            $roleId = $this->framework->roleId($role);
            $text   = sanitize_textarea_field((string) $submitted[$role->value]);
            $before = $stored[$roleId] ?? null;

            if ($text === (string) $before) {
                continue;
            }

            $this->labels->put(new Label($nodeId, '', $roleId, Label::BASE_NUMBER, $locale, $text));
        }
    }

    /**
     * A heading with its explanation folded into a question mark.
     *
     * The owner: *banish the text into a question-mark icon and make it user-friendly.* ⚠️ **Two
     * asks, and the second is the one that matters.** Hiding a sentence saves a line; the sentences
     * themselves were written for somebody who had read the concept — *each value is drawn by the
     * renderer its key asks for; the chain runs installation → model root → ancestors → node* is
     * true and it is not an explanation. **So they were rewritten as well as tucked away.**
     *
     * ⚠️ **A `title`, not a panel that opens.** The text is a hint and nothing depends on reading
     * it; a disclosure would add a thing to click on every heading of the screen. *And the icon is a
     * Dashicon for the same reason the bin is ([D-380](../../../docs/NewConcept/90-decision-log.md)):
     * `?` as a character is a hairline beside a 17px glyph.*
     *
     * ⚠️ *Not a renderer, and `R1` is not bent: this shows no model data. It is a **software string**
     * belonging to the boundary (`AR-2`) — the same class of thing as the word on a button.*
     */
    private function heading(string $text, string $hint, string $level = 'h3'): string
    {
        return '<' . $level . ' style="display:flex;align-items:center;gap:.3em">'
            . esc_html($text)
            . '<span class="dashicons dashicons-editor-help" title="' . esc_attr($hint) . '"'
            . ' style="font-size:17px;width:17px;height:17px;line-height:1;opacity:.5;cursor:help"'
            . ' aria-label="' . esc_attr($hint) . '"></span>'
            . '</' . $level . '>';
    }

    /**
     * The icons an installation offers — **the glyph beside its key**, so a person sees what they pick.
     *
     * ⚠️ **Which icons exist is a boundary fact** (`CD-1`): the core cannot list Dashicons, so the
     * set is handed to the chooser and the chooser places it ([D-390](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **The glyph is a *character*, which is what makes this a plain `<select>` at all.** Dashicons
     * is a **font**, so the icon renders inside an `<option>` once the control asks for that font —
     * something I had claimed was impossible until the owner said flatly *Cursor could do that*.
     *
     * @return array<string, string> Dashicon key ⇒ what a person reads.
     */
    private function iconChoices(): array
    {
        $glyphs  = $this->dashiconGlyphs();
        $offered = [];

        foreach (self::ICONS as $key) {
            $glyph = $glyphs[$key] ?? '';

            $offered[$key] = ($glyph === '' ? '' : $glyph . '  ') . $key;
        }

        return $offered;
    }

    /**
     * Which act was clicked, whether it named a key or not.
     *
     * ⚠️ **A settings row's act submits as `do[<key>]`** and everything else as plain `do`
     * ([D-392](../../../docs/NewConcept/90-decision-log.md)). One form holds the whole panel now, so
     * the key cannot ride in a hidden field — a single field could only say one row.
     */
    private function submittedAct(): string
    {
        $raw = $_POST['do'] ?? '';

        if (is_array($raw)) {
            $raw = reset($raw);
        }

        return sanitize_key(wp_unslash((string) $raw));
    }

    /**
     * Which setting a row's act meant.
     *
     * ⚠️ **Read out of the button's own name**, and sanitised like any key. *Returning `''` where
     * nothing was named is deliberate: the core then refuses it rather than this method guessing at a
     * key, and a refusal names the problem where a guess would write to the wrong row.*
     */
    private function keyOfRowAct(): string
    {
        $raw = $_POST['do'] ?? '';

        if (! is_array($raw) || $raw === []) {
            return '';
        }

        return sanitize_text_field(wp_unslash((string) array_key_first($raw)));
    }

    /**
     * Every setting on the panel, written in one act.
     *
     * The owner: *the save button goes in the page header* — so there is no single key to write, and
     * the panel submits every control it drew.
     *
     * ⚠️ **Only what changed is written.** A save on an untouched panel would otherwise land a
     * changelog entry per key, and [D-349](../../../docs/NewConcept/90-decision-log.md)'s write count
     * would stop meaning *somebody changed this*.
     *
     * ⚠️ **A field that is absent is not the same as one that is empty.** A `select` that is disabled
     * — R28–R32's greyed control — submits **nothing**, and treating that as *set it to nothing* would
     * wipe a value by drawing the row. *So only keys actually present are considered.*
     *
     * ⚠️ **A refusal on one key does not silently pass.** A bounding setting may only be narrowed
     * ([D-312](../../../docs/NewConcept/90-decision-log.md)), so the core can refuse one of thirty —
     * the first refusal is reported and the rest of the batch is abandoned. *What a page-level save
     * should do with a partial batch is genuinely undecided and is on the roadmap; failing loudly is
     * the honest interim rather than writing twenty-nine and mentioning none.*
     */
    private function saveSettings(int $nodeId, int $edgeId, string $name = ''): void
    {
        // ⚠️ **The page save writes the name too, and forgetting that was a regression I shipped.**
        // [D-392](../../../docs/NewConcept/90-decision-log.md) put the name field inside this form and
        // dropped `Rename`; this method only ever read `taxmod_setting[…]`, so **the name arrived and
        // was thrown away**. The owner found it in one try: *changing and saving a node name does not
        // work at the moment — name not in the form?* **It was in the form; nothing read it.**
        //
        // ⚠️ *Only for a node. An **edge**'s name is `save_attribute`'s business, and only where the
        // attribute is declared ([D-376](../../../docs/NewConcept/90-decision-log.md)) — renaming an
        // inherited one from a descendant would rename it for everybody, silently.*
        if ($edgeId === 0 && $name !== '') {
            $node = $this->editor->find($nodeId);

            // ⚠️ *Only when it actually differs.* A page save posts the name every time, and renaming
            // a node to what it already is would write a changelog entry per save — «renamed» twenty
            // times with nothing renamed.
            if ($node !== null && $node->name !== $name) {
                $this->editor->rename($nodeId, $name);
            }
        }

        $submitted = isset($_POST[self::SETTING_FIELD]) && is_array($_POST[self::SETTING_FIELD])
            ? wp_unslash($_POST[self::SETTING_FIELD])
            : [];

        if ($submitted === []) {
            return;
        }

        $chain    = $this->settingChain($nodeId, $edgeId);
        $resolved = $this->settings->resolve($chain);

        foreach ($submitted as $key => $raw) {
            $key = sanitize_text_field((string) $key);

            if ($key === '') {
                continue;
            }

            $value  = $this->settingValue($nodeId, $key, sanitize_text_field((string) $raw));
            $before = $resolved[$key] ?? null;

            // ⚠️ **Compared against what the chain *answers*, not against what was written here — and
            // the difference was a real defect.** A switch always submits `0` or `1`, never nothing
            // ([D-315](../../../docs/NewConcept/90-decision-log.md)'s hidden field is what makes *off*
            // mean false), so on the old test every unset switch counted as changed and a page save
            // wrote `hide=false`, `mandatory=false` and — worst — **`persistent=false`** onto every
            // node somebody looked at. *`persistent=false` silently stops an attribute from storing
            // anything (D-378): a save that touched nothing would have broken data entry.*
            //
            // ⚠️ *The owner asked for exactly this: **check whether the default values are right.**
            // They were not, and the cause is that «unset» and «false» look identical on a switch.*
            //
            // ⚠️ **And the inherited case still writes**, which is why the comparison is against the
            // resolved value: an ancestor saying `hide = true` shows the switch **on**, so turning it
            // off differs from what the chain answers and must be kept.
            if ($before !== null && $before->value->equals($value)) {
                continue;
            }

            $this->settings->put($chain, $key, $value);
        }
    }

    /**
     * Whether the installation is in developer mode.
     *
     * ⚠️ **A WordPress option and no longer a setting on a node**
     * ([D-389](../../../docs/NewConcept/90-decision-log.md)). The owner: *develop is not a setting on
     * the node but a setting in the WordPress admin settings menu.* A posture is a fact about the
     * installation, and on the settings chain it could differ per branch — which is meaningless.
     *
     * ⚠️ *It has no screen to be set on yet, so it is off unless somebody sets the option by hand.
     * That is the same interim `developer` always had, one layer out: the option exists, the screen
     * for it does not, and one function changes when it arrives.*
     */
    public const DEVELOPER_OPTION = 'taxmod_developer';

    private function inDeveloperMode(): bool
    {
        // ⚠️ *Read in one place so the screen and its checks cannot disagree — the option name still
        // lives here because this screen owns the tree's diagnostics.*
        return SettingsScreen::inDeveloperMode();
    }

    /**
     * Whether hidden nodes are being shown.
     *
     * ⚠️ **Off unless asked, which is the owner's word and also the only safe default.** *A switch
     * nobody set is off* — and a tree that showed hidden nodes by default would make `hide` look
     * broken all over again.
     *
     * ⚠️ *In the URL, like the folded set: a posture of **this view** rather than of the installation,
     * so two tabs can differ and nothing is stored. Whether it should be remembered is
     * [OQ-082](../../../docs/NewConcept/91-open-questions.md)'s neighbourhood and not decided.*
     */
    private function showsHidden(): bool
    {
        return isset($_GET['taxmod_hidden']) && $_GET['taxmod_hidden'] === '1';
    }

    /**
     * Rows whose resolved `hide` is true, left out.
     *
     * ⚠️ **A subtree disappears for free, and it is the *resolution chain* doing it — not attribute
     * inheritance.** The owner sharpened this and he was right: *your argument for `hide` was
     * inheritance, but a setting on a node has nothing to do with attribute inheritance.* The glossary
     * keeps them apart — **inheritance** is the relation kind that forms the tree and says what a node
     * **has**; the **resolution chain** is *installation → model root → ancestors → node → use site,
     * walked key by key* ([D-079](../../../docs/NewConcept/90-decision-log.md), [D-093](../../../docs/NewConcept/90-decision-log.md)),
     * and says what a **key** answers here.
     *
     * ⚠️ *They are confusable because the chain **walks the inheritance edges**: the same ancestors,
     * two different questions. `hide` moves down the **chain** ([D-311](../../../docs/NewConcept/90-decision-log.md),
     * [D-312](../../../docs/NewConcept/90-decision-log.md): once hidden, never revealed further down),
     * so a child of a hidden node resolves to hidden and nothing here has to walk or remember. A
     * filter tracking ancestors itself would be a second implementation of **the chain**.*
     *
     * ⚠️ **One query for the whole tree** (`CD-7`): every row's chain is resolved in one batch, not
     * one per row.
     *
     * @param  list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     * @return list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}>
     */
    private function withoutHidden(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $resolved = $this->settings->resolveForNodes(array_map(
            static fn (array $row): Node => $row['node'],
            $rows
        ));

        $kept = [];

        foreach ($rows as $row) {
            $hide = ($resolved[$row['node']->id][SettingKey::Hide->value] ?? null)?->value->asBool() ?? false;

            if (! $hide) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * The switch that shows them anyway.
     *
     * ⚠️ **A link and not a form**, because it changes what this view shows and nothing else — the
     * same shape as folding a branch. *It carries the folded set and the selected node along, or
     * asking to see hidden nodes would silently unfold the tree and lose the page.*
     */
    private function hiddenToggle(bool $showing): string
    {
        $to = add_query_arg(
            array_filter([
                'page'            => 'taxmod',
                'taxmod_node'     => $this->selectedFromRequest()?->id,
                'taxmod_collapsed' => isset($_GET['taxmod_collapsed'])
                    ? sanitize_text_field(wp_unslash($_GET['taxmod_collapsed']))
                    : null,
                'taxmod_hidden'   => $showing ? null : '1',
            ]),
            admin_url('admin.php')
        );

        return '<a class="taxmod-show-hidden" href="' . esc_url($to) . '">'
            . '<span class="dashicons dashicons-' . ($showing ? 'visibility' : 'hidden') . '"></span> '
            . esc_html($showing ? __('hiding hidden nodes again', 'taxmod') : __('show hidden nodes', 'taxmod'))
            . '</a>';
    }

    private function hidden(int $id): string
    {
        return '<input type="hidden" name="action" value="' . self::ACTION . '">'
            . '<input type="hidden" name="id" value="' . (int) $id . '">'
            . wp_nonce_field(self::ACTION . '_' . $id, '_taxmod_nonce', true, false);
    }

    /**
     * ⚠️ **The order below is `CD-5` and does not vary**, not even on a screen only an
     * administrator can reach: capability, nonce, validate, sanitize, act.
     */
    public function handlePost(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to shape the model.', 'taxmod'), '', ['response' => 403]);
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        check_admin_referer(self::ACTION . '_' . $id, '_taxmod_nonce');

        // ⚠️ **`do` may arrive as an array**, because a settings row's act names its key in the
        // button — `do[range_min]` ([D-392](../../../docs/NewConcept/90-decision-log.md)). One form
        // now holds every row, so a hidden `setting_key` could only ever say one of them. *Reading it
        // as a string would have warned and then acted on nothing, which is the worst of the three
        // outcomes.*
        $do = $this->submittedAct();
        $name   = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $target       = isset($_POST['target']) ? absint($_POST['target']) : 0;
        $edge         = isset($_POST['edge']) ? absint($_POST['edge']) : 0;
        $settingKey   = isset($_POST['setting_key']) ? sanitize_text_field(wp_unslash($_POST['setting_key'])) : '';
        // Each setting is edited where it sits, under `taxmod_setting[<key>]`.
        $settingValue = isset($_POST[self::SETTING_FIELD][$settingKey])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::SETTING_FIELD][$settingKey]))
            : '';
        // ⚠️ **Read by edge id, because that is what the row's field is keyed on.** Sanitised like
        // any other name and then handed to the act, which trims it and refuses an empty one — the
        // rule lives in the model (`Relation::renamedTo()`) and not a second, softer copy here.
        $attributeName = isset($_POST[self::NAME_FIELD][$edge])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::NAME_FIELD][$edge]))
            : '';
        $labelLocale  = isset($_POST['label_locale']) ? sanitize_text_field(wp_unslash($_POST['label_locale'])) : '';
        $rendererName = isset($_POST['renderer_name']) ? sanitize_text_field(wp_unslash($_POST['renderer_name'])) : '';
        $stay   = $id;

        try {
            $outcome = match ($do) {
                'create'         => $stay = $this->editor->createNode($name, $id)->id,
                'add_child'      => $stay = $this->editor->createNode($name, $id)->id,
                // ⚠️ **The new node becomes the selected one.** The `+` in a row is the one act
                // whose whole point is *and now I want to work on that* — it makes a node with a
                // placeholder name, so leaving the parent selected means the very next thing a
                // person does is hunt for what they just made.
                'add_child_here' => $stay = $this->editor->createNode(__('New node', 'taxmod'), $id)->id,
                // ⚠️ **The copy becomes the selected node**, for the same reason `add_child_here`
                // does: the point of duplicating is *and now I want to work on that one*, and it
                // carries the original's name, so leaving the original selected would show two
                // identical rows and no way to tell which is which.
                'duplicate'      => $stay = $this->editor->duplicate($id)->id,
                'rename'         => $this->editor->rename($id, $name),
                'move'           => $this->editor->move($id, $target),
                'up'             => $this->editor->moveUp($id),
                'down'           => $this->editor->moveDown($id),
                'restore'        => $this->editor->restore($id),
                'trash'          => $this->editor->moveToTrash($id),
                'trash_node'     => $this->editor->moveToTrashPromotingChildren($id),
                'add_attribute'  => $this->editor->addAttribute($id, $target, $name),
                // Parked, not purged — D-123's two stages, so it can come back.
                'remove_attribute'  => $this->editor->removeAttribute($id, $edge),
                'restore_attribute' => $this->editor->restoreAttribute($id, $edge),
                // ⚠️ **Renamed only where it is declared** (D-376) — the act refuses it otherwise,
                // because an inherited attribute belongs to the ancestor and renaming it from a
                // descendant would rename it for every other user, silently.
                'save_attribute'    => $this->saveAttribute($id, $edge, $attributeName, $settingValue),
                // ⚠️ **The «(copy)» comes from here, not from the core.** [D-281] refuses an edge
                // with the same name, and inventing a suffix is writing user-visible text — which
                // goes through the text domain at the boundary (`AR-2`) and never in `Taxmod\Core`.
                'duplicate_attribute' => $this->editor->duplicateAttribute(
                    $id,
                    $edge,
                    sprintf(
                        /* translators: %s: the name of the attribute being copied. */
                        __('%s (copy)', 'taxmod'),
                        $this->editor->ownAttribute($id, $edge)->name
                    )
                )->id,
                // ⚠️ **`$edge` decides the owner** (D-381): the same three acts serve a node and a use site,
                // and a write meant for one attribute must not land on the type it points at.
                // ⚠️ **The whole panel at once** (D-392): the button sits in the page head and the
                // panel is one form, so there is no single key to write — every changed value is.
                'put_setting'    => $this->saveSettings($id, $edge, $name),
                // ⚠️ Checked against what **exists**, not against what is eligible (D-360): the
                // eligible set is what the screen offers, and an unusual choice is a special case
                // rather than an error. A name no renderer answers to is the error.
                'put_renderer'   => $this->settings->put(
                    $this->settingChain($id),
                    SettingKey::Renderer->value,
                    TypedValue::ofText($this->registeredRendererName($id, $rendererName))
                ),
                // ⚠️ **These two name their key in the button** (`do[<key>]`), because one form now
                // holds every row and a hidden `setting_key` could only ever say one of them.
                'empty_setting'  => $this->settings->put($this->settingChain($id, $edge), $this->keyOfRowAct(), TypedValue::nothing()),
                'reset_setting'  => $this->settings->reset($edge === 0 ? $id : $edge, $this->keyOfRowAct()),
                'put_multiplicity' => $this->settings->put(
                    $this->settings->chainForUseSite($this->editor->ownAttribute($id, $edge)),
                    SettingKey::Multiplicity->value,
                    TypedValue::ofText($settingValue)
                ),
                // ⚠️ **One act for the whole panel** (D-384): the owner's rule for saving, and the
                // only shape that answers *what does Enter do* with five fields on screen.
                'put_labels'     => $this->saveLabels($id, $labelLocale),
                'add_record'     => $this->data->create($id),
                'save_record'    => $this->saveRecord($id),
                default          => throw new \InvalidArgumentException('Unknown action.'),
            };

            // ⚠️ A restore that leaves children behind must say so — it is the one outcome
            // where *done* would be a lie (D-347).
            $message = $outcome instanceof RestoreResult && ! $outcome->everythingCameBack()
                ? sprintf(
                    /* translators: %s is a comma-separated list of node names. */
                    __('Restored — but these were left where they are, because they were moved since: %s', 'taxmod'),
                    implode(', ', $outcome->leftBehind)
                )
                : 'ok';
        } catch (DomainError $error) {
            // Exceptions inside the core, translated at the boundary (`CD-10`). The message is
            // the domain's own words, so it survives the redirect rather than being replaced by
            // a generic failure the person cannot act on.
            $message = $error->getMessage();
        } catch (\InvalidArgumentException) {
            $message = __('Unknown action.', 'taxmod');
        }

        // Everything now happens **at** a node, so the person stays there rather than being
        // sent back to a screen with nothing selected.
        wp_safe_redirect(add_query_arg(
            ['page' => 'taxmod', 'taxmod_message' => rawurlencode($message), 'taxmod_node' => $stay],
            admin_url('admin.php')
        ));
        exit;
    }

    private function notice(): string
    {
        if (! isset($_GET['taxmod_message'])) {
            return '';
        }

        $message = sanitize_text_field(wp_unslash($_GET['taxmod_message']));

        if ($message === 'ok') {
            return '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Done.', 'taxmod') . '</p></div>';
        }

        return '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
