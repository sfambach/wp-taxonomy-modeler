<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Exception\DomainError;
use Taxmod\Core\Exception\NodeNotFound;
use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\FieldType;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\IdentitySpace;
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
use Taxmod\Core\Renderer\HintMarkup;
use Taxmod\Core\Renderer\IconMarkup;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\PageSlot;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\Section;
use Taxmod\Core\Renderer\LabelSlot;
use Taxmod\Core\Renderer\LabelsRenderer;
use Taxmod\Core\Renderer\SettingsRenderer;
use Taxmod\Core\Renderer\Submission;
use Taxmod\Core\Renderer\RenderedField;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\RestoreResult;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\Rendering;
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
     * The form field the record's values arrive under, as `taxmod_value[<relation id>]`.
     *
     * ⚠️ **Keyed by the relation, never by position.** A checkbox does not submit when it is unticked,
     * so parallel `relation_id[]` / `value[]` arrays would shift every later value onto the wrong
     * attribute — silently, and only in the rows somebody unticked.
     */
    private const VALUE_FIELD = 'taxmod_value';

    /** A setting's own control, as `taxmod_setting[<key>]` — one row, one form, one key. */
    private const SETTING_FIELD = 'taxmod_setting';

    /**
     * Die Angaben einer **Feldzeile**, als `taxmod_field_setting[<Kanten-Id>][<Schlüssel>]`.
     *
     * ⚠️ **Ein eigener Vorsatz und je Zeile ein eigener Name** — *sein Befund: «kann es aber nicht mit
     * dem Speichern-Knopf in der Seite speichern». **Gemessen:** jede der Feldzeilen schickte ihre
     * Multiplizität als `taxmod_setting[multiplicity]`, und in **einem** Seitenformular wäre das eine
     * Angabe für sechzig Zeilen — die letzte hätte gewonnen. Deshalb trägt der Vorsatz die Kanten-Id,
     * genau wie {@see self::VALUE_FIELD} es längst tut.*
     *
     * ⚠️ *Und nicht in {@see self::SETTING_FIELD} hineingeschachtelt: dort liegen die Angaben des
     * **Knotens**, und ein Zahlenschlüssel neben `range_min` ist genau die Verwechslung, die der
     * Kommentar an {@see self::NAME_FIELD} beschreibt.*
     */
    private const ROW_SETTING_FIELD = 'taxmod_field_setting';

    /**
     * Where an attribute's new name is submitted, keyed by relation id.
     *
     * ⚠️ **Its own prefix rather than sharing the settings one**, because two different kinds of
     * thing under one name — `taxmod_setting[multiplicity]` beside `taxmod_setting[418]` — is how a
     * numeric key ends up read as a setting key by whoever maintains this next.
     */
    private const NAME_FIELD = 'taxmod_field_name';

    /** Where the labels panel submits its texts, keyed by role. */
    private const LABEL_FIELD = 'taxmod_label';

    /**
     * What `taxmod_collapsed` says when somebody deliberately unfolded **everything**.
     *
     * ⚠️ **A word rather than an empty value, because an empty value cannot be told from an absent
     * one.** *The tree now starts folded ([list row 60](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)),
     * so «no parameter» has to mean «fresh page» — and `add_query_arg` drops an empty string, which
     * would turn *I opened all of it* back into *I have not touched it* on the very next click.*
     *
     * ⚠️ *Not a translated string and not user-visible: it is a token in a URL, so `AR-2` does not
     * reach it. It is read back through `absint()`, which makes it `0` and drops it — the marker
     * needs no case of its own.*
     */
    private const ALL_EXPANDED = 'none';

    /**
     * Der Faltzustand, wie ihn die Links dieser Seite tragen müssen.
     *
     * ⚠️ **Er steht hier, weil eine frische Seite ihn sonst in keinem Link trägt** und der nächste
     * Klick die Vorgabe neu berechnet — *«wenn ich zwischen zwei Knoten arbeite und dauernd die Äste
     * zugehen, das ist ziemlich nervig»*. {@see render()} füllt ihn, {@see backTo()} greift darauf
     * zurück, wenn die Anfrage selbst nichts mitbringt.
     *
     * ⚠️ *`null` heisst «`render()` lief nicht» — das ist der POST-Weg, und dort trägt das Formular den
     * Zustand ({@see submissionFor()}), weshalb er dort auch nicht von hier kommen darf.*
     */
    private ?string $foldStateForLinks = null;

    /**
     * Für welchen Knoten der Weg schon einmal geöffnet wurde.
     *
     * ⚠️ **Er trennt «ich habe den Knoten gerade gewählt» von «ich sehe ihn schon die ganze Zeit an»**
     * — und ohne diese Trennung gewinnt die Vorgabe gegen eine ausdrückliche Handlung: der Benutzer
     * klappt einen Ast zu, in dem der gewählte Knoten liegt, und {@see render()} macht ihn im selben
     * Aufruf wieder auf ([D-612](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Die Zeile, die ihn aufmacht, bleibt richtig ([D-480](../../../docs/NewConcept/90-decision-log.md),
     * [D-615](../../../docs/NewConcept/90-decision-log.md): die **Knoten**-Angabe öffnet einen Weg).
     * Sie greift nur noch beim **Wechsel** der Auswahl, denn genau dann ist der Weg neu.*
     *
     * ⚠️ *`null` heisst «keine Auswahl» — dann gibt es auch nichts zu öffnen.*
     */
    private ?string $openedPathForLinks = null;

    /** Der Name des Merkers in der Adresse. */
    private const OPENED_FOR = 'taxmod_open_for';

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
        private readonly Labels $labels,
        private readonly DataEntry $data,
        private readonly FrameworkNodes $framework,
        private readonly Rendering $rendering,
        // ⚠️ **Here so an act can have a number** ([list row 45](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
        // *The screen does not write history — the services do — but it is the only place that knows
        // where **one act** starts and ends, which is what a change number means. And it has to be
        // the **same** object the services hold: seven `new WpdbChangelog(…)` in `Plugin` are now one
        // ({@see \Taxmod\WordPress\Plugin::changelog()}).*
        private readonly Changelog $changelog,
    ) {
    }

    public function render(): string
    {
        $root  = $this->framework->root();
        $trash = $this->framework->trash();

        // Two queries for the whole tree, whatever its depth — the traversal is solved once,
        // in Tree, and this screen only draws what comes back (`CD-7`).
        $selected   = $this->selectedFromRequest();
        $showHidden = $this->showsHidden();

        // ⚠️ **A fresh page starts folded** ([list row 60](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
        // The owner: *«wenn ich die Seite neu aufmach, dann sollte Kolleps sein — das bitte die beste
        // Übersicht.»* *The mechanism is untouched — the set still lives in the URL and `Tree` still
        // answers what is drawn; only the **default** moved, which is why {@see collapsedFromRequest()}
        // now says `null` instead of `[]` for a request that carries nothing.*
        //
        // ⚠️ *`$selected` is read **before** the walks because the default needs it: the path to the
        // node whose detail is on the right is unfolded, or the page would show a node the tree beside
        // it does not contain.*
        // ⚠️ **Der Faltzustand wird fortgeschrieben, nicht bei jedem Klick neu berechnet.** Der
        // Eigentümer, nachdem das Einklappen gebaut war: *«ich hätte lieber, dass der Status — welcher
        // Knoten offen ist und welcher nicht — **fortgeschrieben** wird. Sobald ich auf Taxonomie
        // Modeller klicke, kriege ich den resetteten Baum, und wenn ich dann darin arbeite, dann
        // bestimme **ich**, welcher Ast eingeklappt oder aufgeklappt ist. Wenn ich zwischen zwei Knoten
        // arbeite und dauernd die Äste zugehen, das ist ziemlich nervig.»*
        //
        // ⚠️ **Und genau das tat es: eine frische Seite trug den Zustand in *keinem* Link.** *Ohne
        // Parameter gab `circumstance()` `null`, `backTo()` liess den Schlüssel weg, und der nächste
        // Klick berechnete die Vorgabe neu — **also ging jeder Ast wieder zu, den er eben geöffnet
        // hatte.** Der Zustand lag in der URL und niemand schrieb ihn hinein.*
        $carried   = $this->collapsedFromRequest();
        $collapsed = $carried ?? $this->tree->collapsedByDefault($selected);

        // ⚠️ **Der ausgewählte Ast bleibt offen, auch wenn der mitgeführte Zustand ihn zuklappt.** *Das
        // ist die zweite Hälfte seiner Bitte — «der aktuelle Ast bleibt offen, das finde ich auch gut»
        // — und ohne diese Zeile widerspricht sie der ersten: ein fortgeschriebener Zustand enthält den
        // Vorfahren des **vorher** gewählten Knotens und würde den neuen zuklappen.*
        //
        // ⚠️ *`collapsedByDefault()` tut dasselbe für den Fall ohne Parameter, deshalb steht es hier
        // nur für den mitgeführten.*
        //
        // ⚠️ **Und sie greift nur beim *Wechsel* der Auswahl** ([D-612](../../../docs/NewConcept/90-decision-log.md)).
        // *Sonst gewinnt die Vorgabe gegen die Hand: der Benutzer klappt einen Ast zu, in dem der
        // gewählte Knoten liegt, und derselbe Aufruf macht ihn wieder auf — der Klick tat sichtbar
        // nichts. **Was der Benutzer tut, schlägt, was die Seite vorschlägt.*** {@see $openedPathForLinks}
        $openedFor = $this->circumstance(self::OPENED_FOR);

        if ($carried !== null && $selected !== null && $openedFor !== (string) $selected->id) {
            $collapsed = array_values(array_diff($collapsed, $selected->ancestorIds()));
        }

        $this->openedPathForLinks = $selected === null ? null : (string) $selected->id;

        // ⚠️ **Ab hier trägt jeder Link den Zustand**, auch der einer frischen Seite — das ist das
        // «Fortschreiben». *Ein Klick auf den Menüpunkt hat keinen Parameter und setzt damit zurück,
        // was er ausdrücklich so wollte.*
        $this->foldStateForLinks = $collapsed === [] ? self::ALL_EXPANDED : implode(',', $collapsed);

        $rows      = $this->tree->rowsUnder($root, [$trash->id], $collapsed, $showHidden, $this->showsRoot());
        $parked    = $this->tree->rowsUnder($trash, [], $collapsed, $showHidden);

        // ⚠️ **`hide` finally does something** ([D-396](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *I would have a use for `hide` on a node for the first time — I would like to hide
        // some prefixes. For that we need a switch on the tree view, «show hidden nodes», default off.*
        //
        // ⚠️ **He also reported it as broken, and it was not: it stored and nothing read it.** A
        // setting nothing consumes looks exactly like a setting that will not save — *which is why a
        // flag and its effect belong in the same package.*
        //
        // ⚠️ **The filter that stood here is gone into the walk**
        // ([D-467](../../../docs/NewConcept/90-decision-log.md)). *`hide` sits on the **inheritance
        // relation**, which is the one thing that puts a node in the tree — so `Tree::rowsUnder()` already
        // has the answer in the relations it just loaded, and a hidden placement is simply not followed.
        // **That takes the subtree with it by construction**, where this screen had to reconstruct the
        // ancestry from `path` to get the same result.*

        $left  = $this->heading(
            __('The tree', 'taxmod'),
            __('Everything you have modelled. A node set to «hide» is left out unless you ask for it.', 'taxmod'),
            'h2'
        );
        $left .= $this->hiddenToggle($showHidden);
        // ⚠️ *Neben seinem Geschwister, weil es dieselbe Art Schalter ist: eine Ansicht, kein
        // gespeicherter Vorzug. Der Eigentuemer braucht die Wurzel, um ihr Felder zu geben.*
        $left .= ' ' . $this->rootToggle($this->showsRoot());

        // ⚠️ **Under Model, not under the root.** A node hung directly on the root sits in no
        // branch at all: it can hold no records and no attribute may point at it — a dead end
        // the screen used to invite people into. D-273 also says what this level *is*: the top
        // level of Model is the list of subject areas.
        $left .= $this->addForm(
            $this->framework->rootOf(Branch::Model),
            __('Add a subject area under Model', 'taxmod')
        );
        // WICHTIG: Beim Suchen wird voll aufgeklappt und auf Treffer plus deren Weg eingedampft.
        // Sein Befund: "knoten filter geht nicht" -- und der Grund war, dass die Seitenansicht
        // zugeklappt ist: 11 von 145 Zeilen stehen im Dokument, und was nicht dasteht, findet
        // kein Skript. Deshalb sucht hier der Server.
        $gesucht = $this->searchTerm();

        if ($gesucht !== '') {
            $rows = $this->matching($this->tree->rowsUnder($root, [$trash->id], [], $showHidden, $this->showsRoot()), $gesucht);
        }

        // WICHTIG: Ein Suchfeld, nicht zwei. Der Baum zeichnet es selbst (der Kern kennt keine
        // URL, CD-1), das Formular steht nur darum, damit die Eingabetaste sucht.
        //
        // WICHTIG: Der Klappzustand reist mit, sonst wirft jede Suche ihn weg (D-390-Fehler noch
        // einmal, diesmal am Suchfeld statt am Aktionsformular): ohne dieses Feld fehlte
        // `taxmod_collapsed` in der Adresse, {@see self::collapsedFromRequest()} las «nichts» und
        // die Vorgabe griff -- ein leeres Suchfeld liess den Baum dann so aussehen, als sei er
        // aufgeklappt geblieben.
        $left .= '<form method="get" class="taxmod-tree-searchform">'
            . '<input type="hidden" name="page" value="taxmod">'
            . '<input type="hidden" name="taxmod_node" value="'
            . esc_attr(isset($_GET['taxmod_node']) ? (string) absint($_GET['taxmod_node']) : '') . '">'
            . $this->circumstanceFields()
            . $this->table($rows, 'tree', $collapsed, $selected, $gesucht)
            . '</form>';
        $left .= $this->heading(
            __('Trash', 'taxmod'),
            __('Parked, not deleted. Anything that pointed at one of these still points at something, so nothing breaks while it sits here. «Clear» is the other half: it removes them for good and keeps their ids and their history, so nothing is ever handed out twice and the changelog still says what was there.', 'taxmod'),
            'h2'
        );
        // ⚠️ **The clear button, on the owner's ask** — *build a button behind the Trash label,
        // «clear», so we can tidy up.* **Only when there is something to clear**: a button that can
        // never act is furniture ([D-429](../../../docs/NewConcept/90-decision-log.md)), and an empty
        // trash needs no act. *Marked `destroys`, which is what makes it red on every surface without
        // a colour being written here.*
        if ($parked !== []) {
            $left .= $this->form(
                $trash->id,
                // ⚠️ *A word and not an icon: the tree's bin **parks**, and a second bin next to it
                // that **deletes** would be two pictures for two opposite acts. The word says which.*
                [['clear_trash', __('Clear', 'taxmod'), __('Remove everything in the trash for good — the ids and the changelog stay', 'taxmod'), '', true]]
            );
        }

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
    /**
     * Wonach gerade gesucht wird — leer, wenn nicht gesucht wird.
     */
    private function searchTerm(): string
    {
        return isset($_GET['taxmod_search'])
            ? trim(sanitize_text_field(wp_unslash($_GET['taxmod_search'])))
            : '';
    }

    /**
     * Nur die Zeilen, die passen — mitsamt dem Weg zu ihnen.
     *
     * ⚠️ **Der Weg gehoert dazu, sonst haengen die Treffer in der Luft.** *Ein Treffer drei Ebenen
     * tief ohne seine Vorfahren waere eine Zeile ohne Zusammenhang — und die Einrueckung wuerde
     * eine Hierarchie behaupten, die im Bild fehlt.*
     *
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool}> $rows
     * @return list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool}>
     */
    private function matching(array $rows, string $gesucht): array
    {
        $nadel   = mb_strtolower($gesucht);
        $behalten = [];

        foreach ($rows as $row) {
            if (! str_contains(mb_strtolower($row['node']->name), $nadel)) {
                continue;
            }

            $behalten[$row['node']->id] = true;

            foreach ($row['node']->ancestorIds() as $id) {
                $behalten[$id] = true;
            }
        }

        $aus = [];

        foreach ($rows as $row) {
            if (isset($behalten[$row['node']->id])) {
                $aus[] = $row;
            }
        }

        return $aus;
    }

    private function table(array $rows, string $mode, array $collapsed, ?Node $selected, string $gesucht = ''): string
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

        // ⚠️ **Resolved once for every row** (`CD-7`): the eye has to know whether it is offering to
        // hide or to reveal, and asking per row would be a walk per row.
        //
        // ⚠️ *`showsHidden()` is asked again here rather than threaded down as a parameter. It is a
        // fact about **this request** and it already has exactly one reader — passing it through two
        // methods would make a second place that could disagree with the first.*
        $hidden     = $this->hiddenAmong($rows);
        $showHidden = $this->showsHidden();

        foreach ($rows as $row) {
            $node               = $row['node'];
            $actions[$node->id] = $this->rowActions($row, $mode, $hidden[$node->id] ?? false, $showHidden);
            $submits[$node->id] = $this->submissionFor($node->id);
            // ⚠️ **The fragment is what keeps the tree where it was.** Selecting a node is a full page
            // load, so a fresh document starts at the top and the row a person just clicked is off
            // screen again. *`#taxmod-node-<id>` makes the browser scroll that row into view — inside
            // the tree's own scroll pane — with no script and nothing stored.*
            // ⚠️ **No `#fragment` any more, and dropping it is the third time the same lesson.** It was
            // the interim for a screen with no script; the script exists now
            // ([D-408](../../../docs/NewConcept/90-decision-log.md)) and restores the exact offset — so
            // the fragment was competing with it: the browser jumped to the row, then the script moved
            // the tree back, one visible flicker per click.
            //
            // ⚠️ *`scroll-margin-top` went for exactly this reason an hour earlier, and it is worth
            // stating as a habit rather than as three coincidences: **a workaround that outlives its
            // reason becomes a second answer to a question that already has one.***
            //
            // ⚠️ *The row still keeps its `id` — nothing points at it now, and it costs a few bytes to
            // leave an address in place for whatever wants to link to a node later.*
            $hrefs[$node->id] = $this->backTo($node->id);

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
            $this->inDeveloperMode(),
            // Nur der Modellbaum sucht ueber den Server -- der Auswahldialog hat alle Zeilen da
            // und filtert im Browser. Der Feldname entscheidet, welches von beidem gilt.
            $mode === 'tree' ? 'taxmod_search' : '',
            $gesucht
        )->markup;
    }

    /**
     * ⚠️ **Only what is used constantly stays in the row** ([U1](../../../docs/NewConcept/20-interaction.md)).
     * Renaming and moving moved to the right, where there is room for a field — the owner's own
     * call, and it is also what stops the row from carrying seven controls again.
     *
     * @param array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool} $row
     */
    private function rowActions(array $row, string $mode, bool $hidden = false, bool $showHidden = false): array
    {
        if ($mode === 'trash') {
            return [new Control('do', 'restore', __('Restore', 'taxmod'), __('Put it back where it came from', 'taxmod'))];
        }

        // ⚠️ **The eye, and it is the first thing in the row.** The owner: *before the plus, so nobody
        // hits delete by accident* — a button pressed twenty times in a row while tidying belongs as far
        // from the bin as the row is wide.
        //
        // ⚠️ **Only while *show hidden nodes* is on, and that is a different rule from
        // [D-370](../../../docs/NewConcept/90-decision-log.md)'s «greyed, never absent».** That rule is
        // about an act that is impossible *for this node* — the last child has no «down» — and greying
        // keeps the row's shape steady. **This is a mode, not a node**: with the toggle off, hiding a row
        // makes it vanish on the spot, so the eye would be a button whose result you cannot see. *Every
        // row loses it together, so the shape stays consistent either way.*
        //
        // ⚠️ *And it needed no renderer, which is the owner's own remark: **it is of course a renderer** —
        // and it already was. A row draws the `Control`s it is handed ([D-367](../../../docs/NewConcept/90-decision-log.md)),
        // so an act arrives as data and `R1` holds without a line being written for it.*
        $eye = [];

        if ($showHidden) {
            $eye[] = new Control(
                'do',
                'toggle_hide',
                $hidden ? __('Show', 'taxmod') : __('Hide', 'taxmod'),
                // ⚠️ The glyph says what the **click** does, not what the state is. A hidden row offers
                // an open eye — *make this visible* — and a visible row a crossed one. *Labelling the
                // state instead would make every eye in the tree a question about which it means.*
                $hidden
                    ? __('Show this node again — it is hidden from the tree and from every chooser', 'taxmod')
                    : __('Hide this node from the tree and from every chooser', 'taxmod'),
                // A protected node stays visible: the framework's own nodes are how a person finds
                // anything at all ([D-194](../../../docs/NewConcept/90-decision-log.md)).
                ! $this->framework->isProtected($row['node']),
                icon: $hidden ? 'visibility' : 'hidden'
            );
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
            ...$eye,
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
                __('Copy this node beside itself, with its own settings and fields — not its children and not its records', 'taxmod'),
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
        // ⚠️ **The circumstances travel in the form, and this is where my first fix was wrong.**
        // {@see backTo()} reads them out of the request — but an act is a **POST to
        // `admin-post.php`**, and a POST has no query string at all. So carrying them on the redirect
        // was carrying nothing: there was nothing left to read by then.
        //
        // ⚠️ *The owner reported it twice, and the second time was after I had «fixed» it: **the show
        // hidden setting still disappears when choosing a function at the node.** He was right both
        // times — the first report was the missing redirect, the second the missing hidden fields, and
        // only the second closes it.*
        return new Submission(
            admin_url('admin-post.php'),
            array_filter([
                'action'        => self::ACTION,
                'id'            => (string) $id,
                '_taxmod_nonce' => wp_create_nonce(self::ACTION . '_' . $id),
                ...$this->circumstances(),
            ])
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
    /**
     * Empty the trash and say what went — the boundary half of {@see ModelEditor::clearTrash()}.
     *
     * ⚠️ **The counts are reported and not swallowed**, because this is the one act on the screen that
     * cannot be undone. *A silent «done» after an irreversible act leaves a person guessing whether it
     * ran, and the guess is answered by looking at the tree — which is exactly when it is too late.*
     *
     * ⚠️ *And it names what stayed, not only what went: the ids and the changelog. Otherwise the
     * honest design ([D-340](../../../docs/NewConcept/90-decision-log.md), [D-065](../../../docs/NewConcept/90-decision-log.md))
     * looks like a purge that missed something.*
     */
    private function clearedTrash(): string
    {
        $gone = $this->editor->clearTrash();

        if ($gone['nodes'] === 0) {
            return __('The trash was already empty.', 'taxmod');
        }

        return sprintf(
            /* translators: 1: nodes, 2: relations, 3: settings, 4: labels. */
            __('Trash cleared: %1$d nodes, %2$d relations, %3$d settings and %4$d labels are gone. Their ids and their changelog entries stay.', 'taxmod'),
            $gone['nodes'],
            $gone['relations'],
            $gone['settings'],
            $gone['labels']
        );
    }

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
                'page' => 'taxmod',
                // ⚠️ **The marker, and it is why unfolding the last branch is not the same as arriving.**
                // *`implode(',', [])` is the empty string, which `array_filter` drops and
                // {@see collapsedFromRequest()} would then read as «no parameter» — the fresh page,
                // which is now folded. So the empty set says so in a word ({@see self::ALL_EXPANDED}).*
                'taxmod_collapsed' => $next === [] ? self::ALL_EXPANDED : implode(',', $next),
                'taxmod_node'      => isset($_GET['taxmod_node']) ? absint($_GET['taxmod_node']) : null,
                // ⚠️ *Der Merker reist mit, sonst wäre der nächste Aufruf wieder «neu gewählt» und
                // machte den eben zugeklappten Ast auf ({@see $openedPathForLinks}).*
                self::OPENED_FOR   => $this->openedPathForLinks,
            ]),
            admin_url('admin.php')
        );
    }

    private function addForm(Node $parent, string $label): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1em 0;display:flex;gap:.5em">'
            . $this->hidden($parent->id)
            . '<input type="text" name="name" placeholder="' . esc_attr($label) . '" required style="flex:1">'
            . ControlMarkup::button(new Control('do', 'create', __('Add', 'taxmod'), '', true, false, '', '', true))
            . '</form>';
    }

    // ------------------------------------------------------------- the details

    /**
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    private function detail(?Node $selected, array $rows, Node $root): string
    {
        if ($selected === null) {
            // ⚠️ *«Nothing selected» ist die **Meldung** und bleibt stehen; wie man daran etwas
            // ändert, ist die **Erklärung** und steht seit
            // [D-661](../../../docs/NewConcept/90-decision-log.md) hinter dem Fragezeichen.*
            return '<h2 style="display:flex;align-items:center;gap:.3em">'
                . HintMarkup::behind(
                    esc_html__('Nothing selected', 'taxmod'),
                    __('Click a name in the tree and it appears here.', 'taxmod')
                )
                . '</h2>';
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
                $this->head($selected, $root)
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
                // ⚠️ **Hier stand für eine Stunde ein eigener Renderer-Wähler, und er war falsch.**
                // *Der Eigentümer hat es gestellt: «ich verstehe nicht, warum es nicht im Setting
                // `Display Option` angezeigt wird, das ist genau dafür da, und das ist glaube ich das,
                // was du am Konzept vorbei machst.» **Er hatte recht, und das Konzept sagt es wörtlich:**
                // «der Eingabemechanismus existiert bereits: die Einstellungsseite»
                // ([02-field-and-setting.md](../../../docs/NewConcept/02-field-and-setting.md)).*
                //
                // ⚠️ *Ein zweites Steuerelement für dieselbe Angabe ist genau das, was `R1` verbietet —
                // und es hätte zwei Wege zum selben Wert gegeben, die auseinanderlaufen können. **Der
                // Renderer wird jetzt in der Wertspalte des Einstellungsblocks gesetzt**, wie jede
                // andere Angabe des Modells.*
                $this->labelsPanel($selected)
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

            // ⚠️ **Der Abschnitt hält **eine** Richtung, und das ist [D-199](../../../docs/NewConcept/90-decision-log.md)s
            // Entscheidung samt der Bedingung, unter der sie gilt.** *Die ausgehende Richtung steht
            // schon dreimal auf dieser Seite — Attribute, Elternteil im Kopf, Kinder im Baum. Der
            // Platz war seit Paket 4 leer, weil die eingehende Richtung nirgends abgefragt wurde.*
            PageSlot::Relations->value => new Section(
                '',
                $this->usedByPanel($selected)
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
    /**
     * A node that **is** a field, drawn both ways — or `''` where it is not one.
     *
     * ⚠️ **The two columns are the whole point** ([D-101](../../../docs/NewConcept/90-decision-log.md),
     * [D-160](../../../docs/NewConcept/90-decision-log.md)): `read_only` is the setting whose entire
     * meaning is that display and edit differ, and at a **type** there is no second row to compare
     * against — so the comparison has to be the two purposes.
     *
     * ⚠️ *Empty string rather than `null`, because every other panel on this screen returns markup and
     * a second convention would be one more thing to remember.*
     */
    private function typePreview(Node $selected): string
    {
        // ⚠️ **Data types only, and measuring found the reason.** `Rendering::typeOf()` answers
        // `node_ref` for anything under `Constants` ([D-232](../../../docs/NewConcept/90-decision-log.md)),
        // so a constant would preview as a **chooser with no candidates handed in** — which draws the
        // red *nothing draws this* marker. *Measured on `Prefixes`: `<input class="taxmod-no-renderer">`.
        // A preview that invents a fault where there is none is worse than no preview.*
        //
        // ⚠️ *And it is the scope the owner asked about: «why no preview on the simple **data
        // types**». A constant previewed as a reference needs its candidates walked and handed in,
        // which is a different job.*
        if ($this->framework->branchOf($selected) !== Branch::DataTypes) {
            return '';
        }

        $display = $this->rendering->valueOfType($selected, Purpose::Display, locale: $this->localeFromRequest());

        if ($display === null) {
            return '';
        }

        $edit = $this->rendering->valueOfType($selected, Purpose::Edit, locale: $this->localeFromRequest());

        $html = $this->heading(
            __('Preview', 'taxmod'),
            __('What a field of this type looks like with the settings above. If the two sides differ, that is «read only» doing its job. The value shown is this type\'s default; it needs no record.', 'taxmod')
        );

        // ⚠️ **The same two sides as the attribute preview, and the same words** — the owner: *the
        // render field and the output sit next to each other without separation; maybe put a heading
        // over them.* **He was looking at a table I had put inside `.taxmod-preview`**, a class that
        // exists to lay out two labelled columns. *So the fix is not a new heading but the shape that
        // was already there: two `taxmod-preview-side` blocks, each with its own `<h4>`.*
        //
        // ⚠️ *His own words are the headings — «As a reader sees it» / «As an editor sees it» — because
        // two previews on one screen saying the same thing differently is worse than either wording.*
        $html .= '<div class="taxmod-preview">';

        foreach ([
            [__('As a reader sees it', 'taxmod'), $display],
            [__('As an editor sees it', 'taxmod'), $edit],
        ] as [$title, $side]) {
            $html .= '<div class="taxmod-preview-side">'
                . '<h4>' . esc_html($title) . '</h4>'
                . '<p><strong>' . esc_html($selected->name) . '</strong> '
                . ($side?->markup ?? '')
                . '</p></div>';
        }

        return $html . '</div>';
    }

    private function previewPanel(Node $selected): string
    {
        $branch = $this->framework->branchOf($selected);

        // ⚠️ **A simple type previews *itself*** ([D-430](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *why no preview on the simple data types?* **Because the check below answers «does
        // this node hold records» when the useful question is «can it be drawn as a field»** — and for
        // a data type the first is no and the second is what it *is*. *`range_min`, `range_step`,
        // `renderer` and `read_only` are configured here, so this was the one screen that could show
        // what they do and the one screen that said there was nothing to show.*
        $asValue = $this->typePreview($selected);

        if ($asValue !== '') {
            return $asValue;
        }

        if ($branch === null || ! $branch->holdsData()) {
            return $this->heading(
                __('Preview', 'taxmod'),
                __('Only a node that can hold records has something to preview. A composed type or a model is drawn by a renderer that does not exist yet.', 'taxmod')
            ) . '<p><em>' . esc_html__('Nothing to preview here.', 'taxmod') . '</em></p>';
        }

        $relations = $this->editor->fieldsOf($selected->id);

        $html = $this->heading(
            __('Preview', 'taxmod'),
            __('How this node reads when it is filled in. The left column is what a reader sees, the right what an editor sees — a field that differs between them is read-only, and that is the point of looking.', 'taxmod')
        );

        if ($relations === []) {
            return $html . '<p><em>' . esc_html__('No fields yet, so there is nothing to fill in.', 'taxmod') . '</em></p>';
        }

        // ⚠️ **Über die eine Naht des Kerns und nicht direkt an die Settings-Tabelle** ({@see Rendering::settingsForUseSites()}).
        // *Hier stand `$this->settings->resolveForUseSites($relations)` — die alte Tabelle allein, und die
        // hat heute fünf Zeilen. **`read_only` lag im Datensatz und die Vorschau sah es nie**: der achte
        // Fall von «Daten umgezogen, Leser stehengeblieben» an derselben Naht.*
        //
        // ⚠️ *Der Eigentümer hat den Grund benannt: «hätte man diesen Settings-Mechanismus komplett
        // gekapselt und dann rausgenommen, hätte man sehen können, überall da, wo er knallt». **Solange
        // es zwei Wege gab, konnte ein Aufrufer den falschen nehmen — und der falsche antwortete
        // plausibel statt zu knallen.***
        $resolved = $this->rendering->settingsForUseSites($relations);
        $seen     = $this->previewSource($selected);

        $values     = $this->rendering->previewValuesFor($relations, $resolved, $seen['held']);
        $visibility = $this->rendering->previewVisibilityFor($relations, $resolved);
        $locale     = $this->localeFromRequest();

        $html .= '<p class="description taxmod-preview-source">' . esc_html($seen['says']) . '</p>';

        $html .= '<div class="taxmod-preview">';

        // ⚠️ **Zwei Seiten, und sie heissen jetzt, was sie sind** — *auf sein Wort: «was machen wir mit
        // der Preview, soll ja eigentlich nur **Anzeige** und **Admin** zeigen, also wird aktuell nix
        // gerendert».*
        //
        // ⚠️ *Vorher hiessen sie «as a reader sees it» und «as an editor sees it» und **beide liefen auf
        // `Level::Admin`** — zwei Frontend-Ansichten an einer Stelle, die keine ist. Jetzt zeigt die
        // eine das Frontend und die andere das Modell.*
        //
        // ⚠️ **Drei Seiten, und die dritte ist seine Entscheidung** ([D-547](../../../docs/NewConcept/90-decision-log.md)):
        // *«es gibt eine dritte Form neben Admin und Show, machen wir jetzt Settings.»*
        //
        // ⚠️ **Mein Fehler davor, und er hat ihn an der Seite gesehen:** *ich hatte die Einstellungen in
        // die **Admin**-Seite gelegt — und auf `Adresse` standen sie danach **zwischen** den Feldern:
        // Street, Display Option, No., validator, Post Code, City, Country, read_only. Er: «man sieht den
        // Render in Settings, aber auch in der Preview vom Modell, und das darf nicht sein.» **Zwei
        // Dinge in einer Liste, die nichts miteinander zu tun haben.***
        //
        // ⚠️ *Also trennt jetzt die Ansicht, was vorher eine Liste war: **Anzeige** und **Admin** zeigen
        // die Felder — die eine fürs Frontend, die andere fürs Modell —, und **Settings** zeigt die
        // Einstellungen. Jede Seite bekommt genau die Kanten, die zu ihr gehören.*
        $einstellungen = array_values(array_filter(
            $relations,
            static fn (Relation $relation): bool => $relation->isSetting() && ! $relation->hide
        ));

        // ⚠️ **`Settings` bekommt eine eigene Zeile über die ganze Breite** — *auf sein Wort: «ich würde
        // noch Settings-Preview in eine neue Zeile packen und alles in einer Linie, also wie eine
        // Tabellenzeile anzeigen — ergibt auch Sinn, weil es hier Table View ist. Wahrscheinlich ist das
        // schon so.»*
        //
        // ⚠️ **Halb war es schon so, und die andere Hälfte war der eigentliche Punkt.** *Gemessen: die
        // Hülle ist ein Raster `repeat(auto-fit, minmax(20em, 1fr))`, die drei Seiten stehen also längst
        // in einer Linie. Aber **Anzeige und Admin sind gestapelte Formularzeilen, `Settings` ist eine
        // echte Tabelle** ([D-546](../../../docs/NewConcept/90-decision-log.md)) — und eine Tabelle in
        // einem Drittel der Breite bricht um. Seine «Tabellenzeile» braucht die Zeile.*
        //
        // ⚠️ *Die Breite wird nicht hier gerechnet, sondern über eine Klasse gesagt: `grid-column: 1/-1`
        // steht im Stylesheet, wo der Rest des Rasters auch steht. **Ein Layout an zwei Orten ist ein
        // Layout, das auseinanderläuft.***
        foreach ([
            [__('Display', 'taxmod'), Purpose::Display, false, Level::FrontEnd, $visibility['shown'], ''],
            [__('Admin', 'taxmod'), Purpose::Edit, true, Level::Admin, $visibility['shown'], ''],
            [__('Settings', 'taxmod'), Purpose::Edit, true, Level::Settings, $einstellungen, ' taxmod-preview-wide'],
        ] as [$title, $purpose, $editable, $level, $gezeigte, $breite]) {
            $html .= '<div class="taxmod-preview-side' . $breite . '">'
                . '<h4>' . esc_html($title) . '</h4>'
                // ⚠️ **No field prefix.** A preview must not be submittable: two forms with the same
                // field names on one page is how a person saves the thing they were only looking at.
                . $this->rendering->nodeAsForm(
                    $selected,
                    $gezeigte,
                    $values,
                    $purpose,
                    '',
                    $locale,
                    $level,
                    $editable
                )->markup
                . '</div>';
        }

        $html .= '</div>';

        if ($visibility['hidden'] !== []) {
            $names = [];

            foreach ($visibility['hidden'] as $relation) {
                $names[] = $relation->name;
            }

            $html .= '<p class="description taxmod-preview-hidden">'
                . esc_html(sprintf(
                    /* translators: %s: comma-separated attribute names. */
                    __('Left out by hide: %s', 'taxmod'),
                    implode(', ', $names)
                ))
                . '</p>';
        }

        // ⚠️ **Ein eigener Satz, weil es ein anderer Grund ist.** *Eine Einstellung ist keine Daten und
        // gehört darum nicht in die Vorschau ([D-518](../../../docs/NewConcept/90-decision-log.md)) — das
        // ist keine Sichtbarkeitswahl, die jemand getroffen hat. Vorher standen beide unter «Left out by
        // hide», also las jeder Knoten so, als hätte jemand drei Zeilen versteckt.*
        if ($visibility['settings'] !== []) {
            $namen = [];

            foreach ($visibility['settings'] as $relation) {
                $namen[] = $relation->name;
            }

            // ⚠️ *Die Namen sind **Auskunft** und bleiben sichtbar; das «warum nicht hier» ist der
            // erklärende Nebensatz und steht seit
            // [D-661](../../../docs/NewConcept/90-decision-log.md) hinter dem Fragezeichen.*
            $html .= '<p class="description taxmod-preview-settings">'
                . HintMarkup::behind(
                    esc_html(sprintf(
                        /* translators: %s: comma-separated setting names. */
                        __('Not previewed: %s', 'taxmod'),
                        implode(', ', $namen)
                    )),
                    __('These are settings, not data. A setting is not shown in the preview, because the preview shows what a record holds.', 'taxmod')
                )
                . '</p>';
        }

        if ($visibility['fixed'] !== []) {
            // ⚠️ *Die Zahl ist **Auskunft** und bleibt stehen; was «read-only» für die Vorschau
            // bedeutet, ist die Erklärung und steht hinter dem Fragezeichen
            // ([D-661](../../../docs/NewConcept/90-decision-log.md)).*
            $html .= '<p class="description">'
                . HintMarkup::behind(
                    esc_html(sprintf(
                        /* translators: %d: how many attributes are read-only. */
                        _n('%d field is read-only.', '%d fields are read-only.', count($visibility['fixed']), 'taxmod'),
                        count($visibility['fixed'])
                    )),
                    __('A read-only field is drawn on both sides of the preview and editable on neither.', 'taxmod')
                )
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
        // ⚠️ **Reading only.** *A preview draws; it does not enter data.* The test record
        // [D-028](../../../docs/NewConcept/90-decision-log.md) speaks of is a **row somebody
        // marked**, not one this method would create — a preview that wrote a record in order to
        // have something to draw would put sample values into the data it is supposed to report on.
        $records = $this->data->recordsOf($selected->id);

        // ⚠️ **The rung between real data and the defaults, and the core owns the rule**
        // ({@see Rendering::previewRecordAmong()}): *real data → rows marked as test data → the
        // type's sample.* Before schema 13 there was no column to ask, so this took `records[0]`
        // and a marked row could outrank real data purely by having the lower id.
        // ⚠️ **Und welche davon überhaupt etwas tragen** ([D-653](../../../docs/NewConcept/90-decision-log.md)):
        // *ein leerer `default` zählt nicht als vorhanden. In **einer** Abfrage für alle (`CD-7`) —
        // je Satz nachzusehen wäre ein Lauf je Datensatz.*
        $chosen = $this->rendering->previewRecordAmong(
            $records,
            $this->data->filledAmong(array_map(
                static fn (\Taxmod\Core\Model\NodeRecord $satz): int => $satz->id,
                $records
            ))
        );

        if ($chosen === null) {
            return [
                'held' => [],
                'says' => __('Filled from the defaults — nothing has been entered against this node yet.', 'taxmod'),
            ];
        }

        // ⚠️ **Which record within a rung is still the first and not a chosen one.** *Which of
        // several real records to preview is a question nobody has asked yet, and inventing an
        // answer would be `PR-4`'s failure — so it takes the first and says which.*
        $held = [];

        foreach ($this->data->valuesOf($chosen->id) as $value) {
            $held[$value->relationId] = $value->value;
        }

        return [
            'held' => $held,
            // ⚠️ **The mark is named, because that is the whole point of a provenance line.** *A
            // preview filled from test data that reads exactly like one filled from real data is
            // the fault this sentence exists to prevent — and
            // [D-241](../../../docs/NewConcept/90-decision-log.md) says the mark governs what is
            // shown, so a surface that has it and stays silent about it is throwing it away.*
            'says' => $chosen->recordType === RecordType::Example
                ? sprintf(
                    /* translators: %d: the record's id. */
                    __('Filled from record #%d, which is marked as test data — no real data has been entered here yet.', 'taxmod'),
                    $chosen->id
                )
                : sprintf(
                    /* translators: %d: the record's id. */
                    __('Filled from record #%d, with the defaults where it says nothing.', 'taxmod'),
                    $chosen->id
                ),
        ];
    }
    /**
     * `Used by` — the attributes of other nodes that are typed by this one.
     *
     * ⚠️ **One direction, and [D-199](../../../docs/NewConcept/90-decision-log.md) says why.** The
     * owner: *«everything going out of the current node is in the attributes. As long as that stays
     * so, we do not need to show them in the relations.»* *Outgoing non-inheritance relations **are** the
     * attributes table, the parent relation is a chip in the head, the children are the tree.* **The
     * incoming direction appeared nowhere, which is what left this slot empty since Package 4.**
     *
     * ```mermaid
     * flowchart LR
     *   O["another node"] -->|its attribute| S["this node"]
     *   S -->|its attributes| T["their targets · the attributes table"]
     *   S -.->|children · the tree| C["…"]
     * ```
     *
     * ⚠️ **His condition is checked and not assumed.** *The section may hold one direction only
     * because every outgoing relation is visible elsewhere — «if an outgoing relation ever appears that is
     * neither an attribute nor inheritance, the section has to grow back or it quietly stops being
     * complete.» Measured on the model before building: 102 inheritance relations, 29 composition, 5
     * aggregation, and **every** composition and aggregation relation carries a name. There is no third
     * case, so the condition still holds.*
     *
     * ⚠️ **An impact estimate rather than a listing** ([D-199](../../../docs/NewConcept/90-decision-log.md)):
     * it is the same list a conflict resolver would pull — *who breaks on delete* — but voluntarily
     * and beforehand. **Model only.** *Which records point at a record is a different question and
     * belongs to a record's own screen; the same decision draws that line.*
     */
    private function usedByPanel(Node $selected): string
    {
        $relations = $this->editor->usedBy($selected->id);

        $html = $this->heading(
            __('Used by', 'taxmod'),
            __('Which attributes of other nodes are typed by this one. Everything going out of this node is in the attributes above; this is the direction that appears nowhere else, and it is what would break if this node were deleted.', 'taxmod')
        );

        if ($relations === []) {
            // ⚠️ *Gesagt statt weggelassen: «nichts verweist hierher» und «ich habe nicht
            // nachgesehen» sehen in einem leeren Kasten gleich aus, und nur das erste ist eine
            // Antwort auf «was bricht, wenn ich das lösche».*
            return $html . '<p><em>' . esc_html__('Nothing points at this node.', 'taxmod') . '</em></p>';
        }

        // ⚠️ **Die Besitzer in **einer** Abfrage** (`CD-7`) — pro Kante nachzuschlagen wäre genau die
        // Schleife, die der Kodierstandard verbietet.
        $owners = $this->editor->ownersOf($relations);

        $html .= '<ul class="taxmod-used-by">';

        foreach ($relations as $relation) {
            $owner = $owners[$relation->fromNodeId] ?? null;

            $html .= '<li>'
                . '<code>' . esc_html($relation->name) . '</code> '
                . esc_html__('on', 'taxmod') . ' '
                . ($owner === null
                    // ⚠️ *Ein Besitzer, den es nicht mehr gibt, wird **benannt** und nicht
                    // verschwiegen — eine Kante ohne ihren Knoten ist ein Befund
                    // ([D-485](../../../docs/NewConcept/90-decision-log.md)) und keine leere Zeile.*
                    ? '<span class="taxmod-nothing">#' . (int) $relation->fromNodeId . '</span>'
                    : '<a href="' . esc_url($this->backTo($owner->id)) . '" class="taxmod-used-by-link">'
                        . esc_html($owner->name) . '</a>')
                . '</li>';
        }

        return $html . '</ul>';
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
    /**
     * Die Id des Formulars, das **die Seite** abschickt — nicht die eines Blocks darin.
     *
     * ⚠️ **Es gab sie nicht, und das ist am 2026-08-29 aufgefallen, als der Einstellungsblock ging.**
     * *Bis dahin lieh sich die Seite `SettingsRenderer::formFor()` — das Formular **des Panels**. Als
     * das Panel entfiel ([D-517](../../../docs/NewConcept/90-decision-log.md)), zeigten die fünf
     * Labelfelder und der Speicherknopf im Kopf auf ein `<form>`, **das es nicht mehr gab**. `form="…"`
     * findet dann nichts und schickt lautlos nichts — genau der Fehler, vor dem
     * `labels-page-save-check` warnt, und **die Prüfung hat ihn gefangen**.*
     *
     * ⚠️ *Der Grund, dass es überhaupt so war: [D-392](../../../docs/NewConcept/90-decision-log.md)
     * machte das Seitenspeichern zum einen Akt, und das Panel war damals der einzige Block mit einem
     * `<form>`. **Ein Block, an dem die Seite hängt, ist eine Abhängigkeit in die falsche Richtung.***
     */
    private static function pageForm(Node $selected): string
    {
        return 'taxmod-page-' . $selected->id;
    }

    private function head(Node $selected, Node $root): string
    {
        // ⚠️ **Das Formular der Seite, mit nichts als seinen versteckten Feldern.** *Alles, was dazu
        // gehört — Name, Labels, der Speicherknopf — nennt es über `form="…"` und steht ausserhalb.
        // Das ist reines HTML und braucht kein Skript.*
        //
        // ⚠️ *`relation` ist `0`, weil ein Knoten keine Verwendungsstelle ist — dieselbe Bedeutung, die
        // das entfallene `settingSubmission` der Null immer gegeben hat.*
        $pageForm = '<form method="post" id="' . esc_attr(self::pageForm($selected)) . '"'
            . ' action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="id" value="' . esc_attr((string) $selected->id) . '">'
            . '<input type="hidden" name="relation" value="0">'
            . '<input type="hidden" name="_taxmod_nonce" value="'
            . esc_attr(wp_create_nonce(self::ACTION . '_' . $selected->id)) . '">'
            // ⚠️ *Auch hier, und es ist dasselbe Versäumnis: das Seitenformular ist der Knopf, den man
            // am häufigsten drückt — ohne die Umstände klappte der Baum bei jedem Speichern zu.*
            . $this->circumstanceFields()
            . '</form>';
        // ⚠️ **The name field first, and the acts behind it** — the owner, correcting his own sketch
        // after seeing it: *name into the first row, actions not their own row but behind the name
        // field.* It names the settings form with `form="…"`, so the page save writes it
        // ([D-392](../../../docs/NewConcept/90-decision-log.md)) and `Rename` is gone.
        // ⚠️ **Hier stand der Wähler für die Sorte, und er ist mit `nodes.field_type` gefallen**
        // ([D-621](../../../docs/NewConcept/90-decision-log.md)): *«die Kante sagt, was etwas hier ist
        // — nicht der Knoten und nicht der Ast.» **Wer eine Einstellung will, gibt der Kante die Art**
        // (TASK-053, [D-618](../../../docs/NewConcept/90-decision-log.md)); ein zweiter Ort für
        // dieselbe Aussage wäre die Doppelung, die diese Entscheidung beseitigt hat.*
        $node = $pageForm . '<input type="text" name="name" value="' . esc_attr($selected->name) . '" required'
            . ' form="' . esc_attr(self::pageForm($selected)) . '">'
            . $this->form(
                $selected->id,
                [['add_child', '', __('Add a child under this node', 'taxmod'), 'plus-alt2']],
                '<input type="text" name="name" placeholder="' . esc_attr__('Name of the new child', 'taxmod') . '" required class="taxmod-toolbar-name">'
            )
            // ⚠️ **The save button submits the settings panel from outside it.** `form="…"` is plain
            // HTML — a button may name the form it belongs to — so nothing needs scripting.
            // ⚠️ **No `button-primary`, and it was contradicting itself** ([row 38](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
            // The owner: *why a diskette with a blue background?* Because it carried
            // `button-primary` **and** `taxmod-icon-button`, and the second exists precisely to take
            // a button's background away. *Whichever the cascade favoured won, so the one button in
            // the row that is styled like the others was styled unlike them.*
            // ⚠️ **Through the one place, like every other button now** — the owner: *every time new
            // buttons appear they look odd again; is there a button renderer? Let us build it and use
            // it everywhere.* *This was the last of four written out by hand, and the one his blue
            // diskette came from.*
            // ⚠️ No Dashicon: the icon font has no diskette, and `dashicons-saved` is a **tick** — which
            // the owner spotted. So the character is handed in and the label stays the name.
            // ⚠️ *This one was the only one of five that got the slot right, and it says nothing about
            // care: it got it right because it was written out with all ten arguments. The other four
            // were short calls and counted wrong. {@see Control::saving()} removes the counting.*
            . ControlMarkup::button(Control::saving(
                'do',
                SettingsRenderer::WRITE,
                __('Save', 'taxmod'),
                __('Save every setting on this page', 'taxmod'),
                true,
                self::pageForm($selected),
            ))
            . $this->form(
                $selected->id,
                // ⚠️ **Der Papierkorbknopf steht hier nur, solange niemand den Knoten benutzt**
                // ([D-604](../../../docs/NewConcept/90-decision-log.md), TASK-037). *Wird er benutzt,
                // ist der Knopf der **Öffner eines Dialogs** und kein Akt mehr — sein Wort: «ein
                // Knoten, der verwendet wird, darf nicht einfach so gelöscht werden».*
                array_values(array_filter([
                    ['duplicate', '', __('Copy this node beside itself, with its own settings and fields — not its children and not its records', 'taxmod'), 'admin-page'],
                    $this->editor->usedBy($selected->id) === []
                        ? ['trash', '', __('Trash this node and everything under it', 'taxmod'), 'trash', true]
                        : null,
                    ['trash_node', '', __('Its children move up to its parent, and lose what they inherited from it', 'taxmod'), 'editor-outdent', true],
                ])),
                // ⚠️ **The move button *is* the dialog's opener** — the owner: *button move with dialog
                // tree chooser*, and then *nicht inline*. So the chooser no longer sits beside a move
                // button; it is behind it, and its own confirm lives inside the overlay.
                $this->parentChooser($selected, $root)
                . $this->trashDialog($selected)
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
     * the relations ([D-014](../../../docs/NewConcept/90-decision-log.md)), `id` is handed out once and
     * never reissued ([D-340](../../../docs/NewConcept/90-decision-log.md)), `version` rises so a
     * record can say what it was written against ([D-060](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Creation, last change and who changed it were «a wiring gap rather than a missing
     * decision» — and the wire arrived** with [list row 45](../../../docs/NewConcept/97-implementation-plan.md#the-working-list),
     * which had to give this screen a changelog for a different reason entirely. *`summaryOf()` and
     * {@see \Taxmod\Core\Model\ChangeSummary} had been built and checked and unreachable, which is the
     * same fault shape as `hide` storing correctly while nothing read it
     * ([D-396](../../../docs/NewConcept/90-decision-log.md)). **Three of those in a row is what made
     * that a habit worth naming.***
     *
     * ⚠️ *A chip only appears where something is known: a node seeded before the changelog existed has
     * no history, and «created: —» reads as a fault rather than as an honest absence
     * ({@see ChangeSummary::isKnown()}).*
     *
     * ⚠️ **The user id becomes a name here and only here.** *Who user 1 **is** belongs to WordPress and
     * the core has no idea (`CD-1`, [D-171](../../../docs/NewConcept/90-decision-log.md)) — so the
     * boundary turns the number into a name, exactly as it turns a nonce into a form.*
     */
    private function constants(Node $selected): string
    {
        $chips = [
            [__('Path', 'taxmod'), $selected->path, __('Where it hangs in the tree. Derived from the relations and never edited.', 'taxmod')],
            [__('Id', 'taxmod'), (string) $selected->id, __('Handed out once and never reissued.', 'taxmod')],
            [__('Version', 'taxmod'), (string) $selected->version, __('Rises when the model changes, so a record can say what it was written against.', 'taxmod')],
        ];

        $history = $this->changelog->summaryOf($selected->id);

        if ($history->createdAt !== null) {
            $chips[] = [
                __('Created', 'taxmod'),
                $this->when($history->createdAt, $history->createdBy),
                __('The first entry in the changelog. Read from there and stored nowhere else.', 'taxmod'),
            ];
        }

        if ($history->changedAt !== null) {
            $chips[] = [
                __('Changed', 'taxmod'),
                $this->when($history->changedAt, $history->changedBy)
                    . ($history->lastAct === null ? '' : ' · ' . $history->lastAct),
                __('The last entry in the changelog, and what it was.', 'taxmod'),
            ];
        }

        $html = '';

        foreach ($chips as [$label, $value, $why]) {
            $html .= '<span class="taxmod-chip" title="' . esc_attr($why) . '">'
                . '<span class="taxmod-chip-label">' . esc_html($label) . '</span> '
                . '<code>' . esc_html($value) . '</code></span>';
        }

        return $html;
    }

    /**
     * A moment, and who was behind it, in the reader's own settings.
     *
     * ⚠️ **`date_i18n()` and not `date()`** — the format and the timezone are the installation's
     * choice, and hardcoding either would show a German administrator an American date on his own
     * screen (`AR-2`'s argument, applied to a format rather than to a word).
     *
     * ⚠️ *A missing user is the **machine**, not an unknown person: a change made by cron, WP-CLI or an
     * import records no user on purpose ([D-296](../../../docs/NewConcept/90-decision-log.md)) — a
     * wrong name in the history is worse than no name.*
     */
    private function when(string $at, ?int $byUserId): string
    {
        $stamp = strtotime($at);

        $shown = $stamp === false
            ? $at
            : date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $stamp);

        if ($byUserId === null) {
            return $shown . ' · ' . __('the machine', 'taxmod');
        }

        $user = get_userdata($byUserId);

        return $shown . ' · ' . ($user === false ? '#' . $byUserId : $user->display_name);
    }

    /**
     * Where this node could go — everywhere except itself and its own subtree.
     *
     * ⚠️ **The impossible targets are left out rather than refused.** The core still refuses
     * them, because a screen is not a guarantee; but offering a choice that always fails is a
     * trap laid for the person using it.
     *
     * ⚠️ **Er holt seine Zeilen selbst, und das ist der Punkt von TASK-054.** *Vorher bekam er die
     * Zeilen der Seitenansicht — mit deren Faltzustand und, wenn gefiltert war, nur den Treffern.
     * **Damit war der Faltzustand der Seite der des Dialogs**, obwohl beide verschiedene Fragen
     * stellen. Jetzt geht er ueber {@see \Taxmod\Core\Service\Rendering::nodeChooser()} wie jeder
     * andere Dialog und beginnt jedes Mal an derselben Stelle: alles zu ausser dem Einstiegsast und
     * dem Weg zum heutigen Elternknoten ([D-615](../../../docs/NewConcept/90-decision-log.md)).*
     */
    private function parentChooser(Node $node, Node $root): string
    {
        $rows = $this->tree->rowsUnder($root, [$this->framework->trash()->id]);

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
        $trigger = IconMarkup::dashicon('move')
            . '<span class="screen-reader-text">' . esc_html__('Move', 'taxmod') . '</span>';

        $confirm = ControlMarkup::button(new Control(
            'do',
            'move',
            __('Move here', 'taxmod'),
            '',
            true,
            false,
            '',
            '',
            true
        ));

        $ast = $this->framework->branchOf($node);

        return $this->rendering->nodeChooser(
            $root,
            'target',
            // Der Einstiegsast: der, in dem der Knoten heute liegt — wer verschiebt, bleibt
            // meistens in der Naehe, und alles andere macht der Benutzer selbst auf.
            $ast === null ? null : $this->framework->rootOf($ast),
            $node->parentId(),
            [$this->framework->trash()->id],
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
     * Löschen fragt nach den Verwendungen — der Dialog aus [D-604](../../../docs/NewConcept/90-decision-log.md).
     *
     * **Sein Wort:** *«ein Knoten, der verwendet wird, darf nicht einfach so gelöscht werden. Es muss
     * einen Dialog für den Benutzer geben, der fragt, ob die Verwendungen mitgelöscht werden sollen.
     * Bei ja müssen diese auch in den Papierkorb wandern, bei nein haben wir Leichen im Baum, die auf
     * nichts mehr zeigen — das muss sichtbar sein, also am Feld in der Kante.»*
     *
     * ⚠️ **Der Dialog **nennt** die Verwendungen, er zählt sie nicht.** *«3 Verwendungen» ist keine
     * Antwort auf «was bricht» — der Benutzer muss die Namen sehen, um sich zu entscheiden, und es
     * sind dieselben Zeilen wie unter {@see usedByPanel()}: die Kanten anderer Knoten, die diesen
     * als Typ haben.*
     *
     * ⚠️ **Zwei Knöpfe, beide sagen was sie tun, und keiner ist der Standard.** *«Ja» parkt die
     * Verwendungen mit, «nein» lässt sie stehen — beides ist nach D-604 erlaubt, und genau darum darf
     * die Maske keins von beidem vorwegnehmen. Geschlossen wird der Dialog über die Schattenfläche,
     * und dann ist nichts geschehen.*
     *
     * ⚠️ **Wo die Verwendung sitzt, gehört dazu.** *Eine Kante `menge` sagt nichts; `menge an
     * Bestellposition` sagt, wohin man sehen muss, wenn man «nein» wählt.*
     *
     * ⚠️ **Leer heisst: kein Dialog.** *Der gewöhnliche Papierkorbknopf steht dann wie bisher in der
     * Reihe — ein Dialog, der «nichts zeigt hierher, wirklich löschen?» fragt, wäre die Rückfrage, die
     * [D-123](../../../docs/NewConcept/90-decision-log.md) mit den zwei Stufen gerade abgeschafft hat.*
     *
     * ⚠️ **Die Hülle ist dieselbe wie beim Baumdialog** — verborgenes Kontrollkästchen, `<label>` als
     * Öffner, Schattenfläche zum Schliessen, alles ohne Skript ({@see DialogChooserRenderer}). *Sie
     * steht hier und nicht dort, weil dieser Dialog keinen Knoten **wählt**: er stellt eine Frage mit
     * zwei Antworten, und der Wähler-Renderer hätte einen Baum zeichnen müssen, den niemand braucht.*
     */
    private function trashDialog(Node $selected): string
    {
        $uses = $this->editor->usedBy($selected->id);

        if ($uses === []) {
            return '';
        }

        // ⚠️ **Die Besitzer in **einer** Abfrage** (`CD-7`) — dieselbe Regel wie unter {@see usedByPanel()}.
        $owners = $this->editor->ownersOf($uses);
        $switch = 'taxmod-trash-' . $selected->id;

        $liste = '';

        foreach ($uses as $use) {
            $owner = $owners[$use->fromNodeId] ?? null;

            $liste .= '<span class="taxmod-trash-use" style="display:block">'
                . '<code>' . esc_html($use->name) . '</code> '
                . esc_html__('on', 'taxmod') . ' '
                . ($owner === null
                    ? '<span class="taxmod-nothing">#' . (int) $use->fromNodeId . '</span>'
                    : esc_html($owner->name))
                . '</span>';
        }

        return '<span class="taxmod-chooser">'
            // Ohne `name`, damit es nie mitgeschickt wird — dasselbe wie beim Baumdialog.
            . '<input type="checkbox" class="taxmod-dialog-switch" id="' . esc_attr($switch) . '">'
            . '<label class="button taxmod-icon-button taxmod-dialog-open" for="' . esc_attr($switch) . '"'
            . ' title="' . esc_attr__('Trash this node and everything under it — it is used, so this asks first', 'taxmod') . '">'
            . IconMarkup::dashicon('trash')
            . '<span class="screen-reader-text">' . esc_html__('Trash', 'taxmod') . '</span>'
            . '</label>'
            . '<span class="taxmod-dialog">'
            . '<label class="taxmod-dialog-shade" for="' . esc_attr($switch) . '"></label>'
            . '<span class="taxmod-dialog-panel">'
            . '<span class="taxmod-dialog-head">'
            . '<span class="taxmod-chooser-current">' . esc_html(sprintf(
                /* translators: %s: the name of the node being trashed. */
                __('«%s» is used', 'taxmod'),
                $selected->name
            )) . '</span>'
            . '<label class="taxmod-dialog-close" for="' . esc_attr($switch) . '">&times;</label>'
            . '</span>'
            . '<span class="taxmod-trash-uses" style="display:block">'
            . '<span style="display:block">' . esc_html__('These fields are typed by it. Should they go into the trash as well?', 'taxmod') . '</span>'
            . $liste
            . '<span style="display:block"><em>' . esc_html__('If they stay, they point at nothing — which is allowed, and the field says so where it sits.', 'taxmod') . '</em></span>'
            . '</span>'
            . '<span class="taxmod-dialog-foot">'
            . ControlMarkup::button(new Control(
                'do',
                'trash_with_uses',
                __('Trash it and its uses', 'taxmod'),
                __('The node and every field typed by it go into the trash together', 'taxmod'),
                true,
                true,
                '',
                '',
                true
            ))
            . ControlMarkup::button(new Control(
                'do',
                'trash',
                __('Trash it only', 'taxmod'),
                __('The fields stay and point at nothing', 'taxmod'),
                true,
                true
            ))
            . '</span>'
            . '</span></span></span>';
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
        $relations = $this->editor->fieldsOf($selected->id);
        $body  = '';

        // ⚠️ **Through the renderer, and the acts arrive as **facts** rather than as markup**
        // ([D-376](../../../docs/NewConcept/90-decision-log.md)). The boundary knows the nonce, the
        // URL, the words and what is allowed; the renderer decides the shape of the row. That is the
        // difference between `R1` being followed and a renderer that concatenates somebody's HTML.
        $actions = [];
        $submits = [];

        // ⚠️ **Which of its own attributes is first and which is last** — the owner: *the attribute row
        // should have up and down buttons like the nodes in the tree.* *A button that cannot act is left
        // out ([D-429](../../../docs/NewConcept/90-decision-log.md)), so the ends of the list have to be
        // known before the row is drawn — exactly as the tree already passes `isFirst` and `isLast`.*
        //
        // ⚠️ *Only its **own**: an inherited attribute is ordered where it was declared
        // ([D-376](../../../docs/NewConcept/90-decision-log.md)), and reordering it from a descendant
        // would reorder it for everybody.*
        $ownOrder = [];

        foreach ($relations as $relation) {
            if ($relation->fromNodeId === $selected->id) {
                $ownOrder[] = $relation->id;
            }
        }

        // ⚠️ *Einmal für die ganze Tabelle, nicht je Zeile (`CD-7`) — die Werte der Teile werden weiter
        // unten noch einmal gebraucht, und der Knopf «Zeile hinzufügen» will vorher wissen, ob es
        // überhaupt Teile gibt.*
        $teile = $this->data->settingPartsOf(
            $selected->id,
            array_map(static fn (Relation $relation): int => $relation->id, $relations)
        );

        $firstOwn = $ownOrder[0] ?? 0;
        $lastOwn  = $ownOrder === [] ? 0 : $ownOrder[count($ownOrder) - 1];

        foreach ($relations as $relation) {
            $own = $relation->fromNodeId === $selected->id;

            $actions[$relation->id] = [
                // ⚠️ The two words the core cannot make ([OQ-087](../../../docs/NewConcept/91-open-questions.md)):
                // the text domain is the boundary's (`AR-2`), so they travel with the controls.
                new Control('word:own', '', __('own', 'taxmod')),
                new Control('word:inherited', '', __('inherited', 'taxmod')),
                new Control('word:settings', '', __('Settings of this use site', 'taxmod')),
                // ⚠️ **Der Verstecken-Schalter am Feld** — der Fall, den
                // [D-467](../../../docs/NewConcept/90-decision-log.md) als Grund nannte und für den es
                // nie einen Knopf gab. *Gemessen am 2026-08-30: sieben versteckte Kanten, alle sieben
                // Vererbungskanten, kein einziges Feld.*
                //
                // ⚠️ *Das Auge sagt, was der **Klick** tut, nicht was der Zustand ist — dieselbe Regel
                // wie in der Baumzeile, damit nicht jedes Auge im Schirm eine Rückfrage ist.*
                //
                // ⚠️ **Nur an der eigenen Deklaration.** *Ein geerbtes Feld ist dieselbe Kante; es hier
                // zu verstecken hiesse, es überall zu verstecken. Wer das will, sagt es dort, wo das
                // Feld erklärt ist.*
                new Control(
                    'do',
                    'toggle_field_hide',
                    $relation->hide ? __('Show', 'taxmod') : __('Hide', 'taxmod'),
                    $relation->hide
                        ? __('Show this field again — it is hidden from forms and from the preview', 'taxmod')
                        : __('Hide this field — it stays in the model and is not drawn', 'taxmod'),
                    $own,
                    icon: $relation->hide ? 'visibility' : 'hidden'
                ),
                // ⚠️ **Die Diskette der Zeile ist weg, und das ist sein Wunsch von zweimal:** *«biete
                // keinen Button an» und «Save in Fields sollte eigentlich auch über die Seite gehen».*
                // *Sie schrieb Name und «wie oft» — beide hängen jetzt am Seitenformular
                // ({@see self::saveFieldRows()}), also hätte dieser Knopf «Speichern» gesagt und
                // **nichts** abgeschickt. **Ein Knopf, der nichts mehr trägt, ist schlimmer als keiner.***
                //
                // ⚠️ *Sein Befund war der Anlass: «kann es aber nicht mit dem Speichern-Knopf in der
                // Seite speichern» — zwei Speicherwege für eine Angabe, und der offensichtliche war der
                // stumme.*
                // ⚠️ **Eine Zeile mehr, wo die Multiplizität mehrere zulässt.** *Der Eigentümer bestand
                // darauf, dass `Display Option` `1..*` ist und nicht `0..*`: «somit muss ich Zeilen
                // hinzufügen können». Und der Grund ist seiner —
                // [D-548](../../../docs/NewConcept/90-decision-log.md): mehrere Renderer entstehen über
                // mehrere `DisplayOption`s, für das Farbschema.*
                //
                // ⚠️ **Angeboten nur, wo wirklich ein Teil entstehen kann.** *`allowsMany()` allein
                // genügt nicht: zeigt die Kante auf ein Ziel ohne eigene Felder, gibt es keinen Teil, und
                // der Kern verweigert. **Ein Knopf, der verlässlich absagt, ist schlimmer als keiner** —
                // also wird gefragt, ob diese Kante heute schon Teile hat.*
                new Control(
                    'do',
                    'add_part',
                    __('Add row', 'taxmod'),
                    __('Add another one of these — the model allows several', 'taxmod'),
                    $relation->multiplicity->allowsMany() && isset($teile[$relation->id]),
                    icon: 'plus-alt2'
                ),
                // ⚠️ **Up and down, the same two the tree row has** — the owner: *the attribute row
                // should have up and down buttons like the nodes in the tree; `position` is part of node
                // and also part of relation.* **Measured: it is part of the relation only** — a node's order is
                // its *inheritance* relation's position, so this is the same column reached through a
                // different sibling list, not a fact waiting for a shared base class.
                //
                // ⚠️ *And it is the missing half of [row 30](../../../docs/NewConcept/97-implementation-plan.md#the-working-list):
                // [D-407](../../../docs/NewConcept/90-decision-log.md) made `position` the single home
                // for order and removed the `order` setting — **and nobody built the gesture**, so until
                // now the order of attributes was the order they happened to be created in.*
                new Control(
                    'do',
                    'field_up',
                    __('Up', 'taxmod'),
                    __('Move this field up among the ones declared here', 'taxmod'),
                    $own && $relation->id !== $firstOwn,
                    false,
                    'arrow-up-alt2'
                ),
                new Control(
                    'do',
                    'field_down',
                    __('Down', 'taxmod'),
                    __('Move this field down among the ones declared here', 'taxmod'),
                    $own && $relation->id !== $lastOwn,
                    false,
                    'arrow-down-alt2'
                ),
                // ⚠️ **Duplicate, and only for an own attribute** — the owner: *duplicate for the
                // attribute is missing too.* An inherited one belongs to the ancestor that declared
                // it, so copying it from here would put a second declaration where the first never was.
                //
                // ⚠️ *The copy needs a **different name**: [D-281](../../../docs/NewConcept/90-decision-log.md)
                // refuses an relation with the same `from`, `kind`, `to` **and name**, because the name is
                // part of what makes an relation itself. So the boundary supplies «(copy)» — a translatable
                // word the core has no business inventing (`AR-2`).*
                new Control(
                    'do',
                    'duplicate_field',
                    __('Duplicate', 'taxmod'),
                    __('Copy this field with its settings — under a new name, because an relation is partly its name', 'taxmod'),
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
                    'remove_field',
                    __('Remove', 'taxmod'),
                    __('Remove this field — parked, not purged', 'taxmod'),
                    $own,
                    true,
                    'trash'
                ),
            ];

            $submits[$relation->id] = new Submission(
                admin_url('admin-post.php'),
                [
                    'action'         => self::ACTION,
                    'id'             => (string) $selected->id,
                    'relation'           => (string) $relation->id,
                    'setting_key'    => SettingKey::Multiplicity->value,
                    '_taxmod_nonce'  => wp_create_nonce(self::ACTION . '_' . $selected->id),
                    ...array_filter($this->circumstances()),
                ]
            );
        }


        // ⚠️ **One address per target, built from the same method the tree rows use.** *The owner
        // asked for a jump link on the attribute's target, and `backTo()` is a pure URL builder — no
        // query, no id check — so the map costs nothing even when two attributes point at one node.*
        //
        // ⚠️ *No `#fragment` on purpose. It was taken off the tree links because it fought the
        // scroll-restore script — «the browser jumped to the row, then the script moved the tree
        // back, one visible flicker per click» — and re-adding it here would buy back that fight.
        // **Selecting the target is the jump; where the tree then sits is the script's business.***
        $targetHrefs = [];

        foreach ($relations as $relation) {
            $targetHrefs[$relation->toNodeId] ??= $this->backTo($relation->toNodeId);
        }

        // ⚠️ **Zwei Blöcke, ein Renderer** ([D-518](../../../docs/NewConcept/90-decision-log.md)). Der
        // Eigentümer: *«dass wir praktisch den Renderer zweimal aufrufen, einmal für Fields und einmal
        // für Settings, und dann jeweils eine andere Überschrift setzen.»*
        //
        // ⚠️ **Damit ist [D-506](../../../docs/NewConcept/90-decision-log.md) zum ersten Mal auch auf
        // dem Schirm wahr** — *«somit ist im Grunde alles ein Feld». Ein eigener Einstellungs-Renderer
        // wäre die zweite Art, dasselbe zu zeichnen, die `R1` verbietet; **dieselbe Tabelle, anders
        // gefiltert**, ist keine.*
        //
        // ⚠️ **Die Einteilung kommt von der **Kante**, nicht vom Zielknoten** ([D-526](../../../docs/NewConcept/90-decision-log.md)).
        //
        // ⚠️ *Vorher fragte diese Stelle die Sorte des **Ziels** — und der Eigentümer hat den Widerspruch
        // in einer einzigen Zeile gesehen: auf dem Knoten `form` stand `with_label` **in der Tabelle
        // Fields**, und in ihrer eigenen Spalte «Kind» stand `setting`. **Gemessen: fünf von sieben
        // Einstellungskanten lagen unter Fields** — `orientation`, `exponent`, `label_role`,
        // `with_label` und die vier Ziele, deren Knotensorte `field` ist.*
        //
        // ⚠️ **Seine Frage war «falscher Relationstyp gewählt?» — nein, die Art war richtig.** *Die
        // Kante sagt es seit [D-526](../../../docs/NewConcept/90-decision-log.md) selbst, und die Sorte
        // des Zielknotens ist eine zweite Abschrift derselben Aussage. **Zwei Angaben, die dasselbe
        // sagen sollen, und eine sagt es falsch** — dieselbe Auflösung wie bei `mandatory`
        // ([D-405](../../../docs/NewConcept/90-decision-log.md)) und `persistent`
        // ([D-538](../../../docs/NewConcept/90-decision-log.md)), und beide Male kam der Satz von ihm.*
        $html = '';

        foreach ([false, true] as $istEinstellung) {
            $dieser = array_values(array_filter(
                $relations,
                static fn (Relation $relation): bool => $relation->isSetting() === $istEinstellung
            ));

            $sorte = $istEinstellung ? FieldType::Setting : FieldType::Model;

            $body = '';

            foreach ($this->rendering->fieldRowsFor(
                $dieser,
                $selected->id,
                $actions,
                $submits,
                self::NAME_FIELD,
                self::ROW_SETTING_FIELD,
                '',
                \Taxmod\Core\Renderer\Level::Admin,
                $targetHrefs,
                // ⚠️ **Der Wert je Angabe, damit die Zeile ihn zeigen und annehmen kann.** *Auf sein
                // Wort — «ich verstehe nicht, warum es nicht im Setting `Display Option` angezeigt
                // wird, das ist genau dafür da» — und das Konzept sagt dasselbe: «der
                // Eingabemechanismus existiert bereits: die Einstellungsseite».*
                //
                // ⚠️ *In **einem** Zug für alle Zeilen (`CD-7`), aus dem `default`-Satz des Knotens.*
                $this->data->settingValuesOf(
                    $selected->id,
                    array_map(static fn (Relation $relation): int => $relation->id, $dieser)
                ),
                self::VALUE_FIELD,
                // ⚠️ *Ein Speichern oben und keines je Wert — sein Wunsch: «Save in Fields sollte
                // eigentlich auch über die Seite gehen».*
                self::pageForm($selected),
                // ⚠️ **Die Teile, damit die Wertspalte zeigt, was gespeichert ist.** *Er hat den Mangel
                // gefunden: «auch bezweifle ich, dass dies Datensätze sind, die wir hier sehen». Bei
                // `1..*` sind es mehrere, und jeder wird eine Zeile
                // ([D-548](../../../docs/NewConcept/90-decision-log.md)).*
                // ⚠️ *Einmal oben geholt und hier nur benutzt — zwei Abfragen für dieselbe Auskunft
                // wären zwei Gelegenheiten, verschieden zu antworten.*
                $teile,
                // WICHTIG: Ein Auswahldialog je Feldzeile -- TASK-029, auf sein Wort: "Points at
                // ist der type und type muss aenderbar sein. Auswahl ist Mussfeld, also leere
                // Auswahl nicht moeglich". Nur fuer eigene Felder: ein geerbtes gehoert dem
                // Vorfahren und wird dort geaendert (D-376, dieselbe Regel wie beim Umbenennen).
                $this->targetChoosersFor($dieser, $selected),
                // WICHTIG: Nur die Einstellungen haben eine Wertspalte -- auf sein Wort: "die
                // ganze Spalte Value muss weg". Ein Feld ist Benutzerdaten fuer einen Datensatz,
                // nicht fuer das Modell; die Zeile hier definiert nur seinen Typ.
                $istEinstellung
            ) as $row) {
                $body .= $row->result->markup;
            }

            $html .= $this->heading(...$this->fieldBlockHeading($sorte));

            $html .= $body === ''
                ? '<p><em>' . esc_html__('None yet.', 'taxmod') . '</em></p>'
                : '<table class="wp-list-table widefat striped"><thead><tr>'
                    . '<th>' . esc_html__('Name', 'taxmod') . '</th>'
                    // WICHTIG: "Type", nicht "Points at" -- auf sein Wort: "points at in type
                    // umbenennen". Die Spalte zeigt das Ziel des Feldes, und das ist sein Typ.
                    . '<th>' . esc_html__('Type', 'taxmod') . '</th>'
                    . '<th style="width:8em">' . esc_html__('Kind', 'taxmod') . '</th>'
                    . '<th style="width:5em">' . esc_html__('From', 'taxmod') . '</th>'
                    . '<th style="width:11em">' . esc_html__('How many', 'taxmod') . '</th>'
                    // ⚠️ *Die Spalte, ohne die eine Einstellung nicht einzustellen war.* Nur bei
                    // den Einstellungen -- Felder haben seit seinem Wort keine mehr.
                    . ($istEinstellung ? '<th>' . esc_html__('Value', 'taxmod') . '</th>' : '')
                    . '<th style="width:3em"></th>'
                    . '</tr></thead><tbody>' . $body . '</tbody></table>';

            // ⚠️ **Das Formular «Feld anlegen» und die geparkten Felder gehören unter die Felder, nicht
            // hinter beide Blöcke.** *Der Eigentümer: «aktuell ist das Feld, um ein Field hinzuzufügen,
            // unter Settings — dort ist es falsch, müsste unter Fields sein.» Er hat recht, und der
            // Fehler war meiner: die zwei Blöcke wurden eine Schleife, und was danach stand, fiel
            // hinter den **letzten**.*
            //
            // ⚠️ *Unter «Fields» und nicht doppelt, weil es der **allgemeine** Akt ist: nach
            // [D-506](../../../docs/NewConcept/90-decision-log.md) ist alles ein Feld. **Was daraus
            // wird, entscheidet das Ziel** — zeigt das neue Feld auf einen Knoten, der eine Einstellung
            // ist, erscheint die Zeile danach im Settings-Block.*
            if ($sorte === FieldType::Model) {
                $html .= $this->removedFields($selected) . $this->fieldForm($selected);
            }
        }

        // ⚠️ **Hier stand der eigene Renderer-Block, und er ist gefallen**
        // ([D-644](../../../docs/NewConcept/90-decision-log.md)). *Antwort 1 lautet vollständig «dann
        // zeichnet die Wertspalte den Wähler von selbst, **und der eigene Block fällt weg**»; sein Wort
        // dazu: «Renderer-Box ist übrigens immer noch da, die muss weg!».*
        //
        // ⚠️ **Was ihn stehen liess, war eine Lücke in der Menge und nicht der Block**: *die Zeile
        // `renderer` sammelte ihre Möglichkeiten aus den Kindern des Kantenziels
        // ([D-540](../../../docs/NewConcept/90-decision-log.md)) und verlor mit dem Fall der Marke die
        // sechs unter `render with label` (`INF-043`). **Die Menge kommt jetzt aus den Renderer-Knoten,
        // gesiebt durch die Registratur** ({@see \Taxmod\Core\Renderer\RendererChoiceRenderer},
        // [D-603](../../../docs/NewConcept/90-decision-log.md), [D-647](../../../docs/NewConcept/90-decision-log.md)),
        // also gibt es nichts mehr, was nur dieser Block konnte — und die Wahl steht dort, wo jede
        // andere Einstellung steht.*
        return $html;
    }

    /**
     * Ein Auswahldialog je eigener Feldzeile, damit ihr Ziel geändert werden kann.
     *
     * ⚠️ **Nur für **eigene** Felder.** *Ein geerbtes gehört dem Vorfahren; es von hier zu ändern
     * änderte es für jeden anderen Benutzer mit — dieselbe Regel, die
     * [D-376](../../../docs/NewConcept/90-decision-log.md) fürs Umbenennen aufgestellt hat.*
     *
     * ⚠️ *Der Einstiegsast ist der Ast, in dem das heutige Ziel liegt: wer einen Typ ändert, will
     * meistens einen anderen aus derselben Familie.*
     *
     * @param list<Relation> $relations
     * @return array<int, string>
     */
    private function targetChoosersFor(array $relations, Node $selected): array
    {
        $aus = [];

        foreach ($relations as $relation) {
            if ($relation->fromNodeId !== $selected->id) {
                continue;
            }

            $ziel = $this->editor->find($relation->toNodeId);
            $ast  = $ziel === null ? null : $this->framework->branchOf($ziel);

            $aus[$relation->id] = $this->rendering->nodeChooser(
                $this->framework->root(),
                'retarget_' . $relation->id,
                $ast === null ? null : $this->framework->rootOf($ast),
                $relation->toNodeId,
                [$this->framework->trash()->id],
                $this->barredTargets(),
                $ziel?->name,
                __('Nothing here can be a target.', 'taxmod'),
                DialogChooserRenderer::NAME,
                $this->localeFromRequest(),
                Level::Admin,
                '<span class="button taxmod-icon-button" title="'
                . esc_attr__('Change what this field points at', 'taxmod') . '">'
                . IconMarkup::dashicon('networking')
                . '<span class="screen-reader-text">' . esc_html__('Change type', 'taxmod') . '</span>'
                . '</span>',
                ControlMarkup::button(new Control(
                    'do',
                    'retarget_field',
                    __('Change type', 'taxmod'),
                    '',
                    true,
                    false,
                    '',
                    '',
                    true
                ))
            )->markup;
        }

        return $aus;
    }

    /**
     * Welche Knoten kein Ziel sein können — Zweigwurzeln und alles ausserhalb eines Zweiges.
     *
     * @return list<int>
     */
    private function barredTargets(): array
    {
        $aus = [];

        foreach ($this->tree->rowsUnder($this->framework->root(), [$this->framework->trash()->id]) as $row) {
            $ast = $this->framework->branchOf($row['node']);

            if ($ast === null || $row['node']->id === $this->framework->rootOf($ast)->id) {
                $aus[] = $row['node']->id;
            }
        }

        return $aus;
    }

    /**
     * Überschrift und Hinweis eines der zwei Blöcke.
     *
     * ⚠️ *An **einer** Stelle, weil die zwei Aufrufe sonst zwei Orte wären, an denen dasselbe über
     * dieselbe Tabelle gesagt wird — und der eine würde beim Ändern vergessen.*
     *
     * @return array{0:string,1:string}
     */
    private function fieldBlockHeading(FieldType $kind): array
    {
        // ⚠️ *Der Satz über «Kind» und «own/inherited» gilt für beide Blöcke — es ist dieselbe Tabelle.*
        //
        // ⚠️ **Hier stand «‹Kind› is not a choice — it follows from where the target sits in the
        // tree», und der Satz war nie wahr** ([D-618](../../../docs/NewConcept/90-decision-log.md),
        // TASK-053). *Der Code legte `Renderer --converter--> Converter` als Aggregation an, obwohl
        // das Ziel im Einstellungsast liegt — es fiel nur seinem Blick auf. **Und `setting` konnte
        // gar nicht aus einem Ast folgen**, weil kein Ast sie hergibt. Sein Wort: «der benutzer legt
        // fest, automation machen wir später aber auch nur vielleicht».*
        $gemeinsam = __('«Kind» says what the relation is: composition, aggregation or setting. It is chosen when the field is added, not read off the tree. «own» means declared here; «inherited» means it belongs to a node further up and can only be changed there.', 'taxmod');

        return match ($kind) {
            FieldType::Model => [
                __('Fields', 'taxmod'),
                __('What this node has, and what a person enters.', 'taxmod') . ' ' . $gemeinsam,
            ],
            FieldType::Setting => [
                __('Settings', 'taxmod'),
                // ⚠️ *«der Autor» und nicht «hier stehen Einstellungen»: nach
                // [D-508](../../../docs/NewConcept/90-decision-log.md) ist der Unterschied genau, **wo
                // der Wert liegt** — im Datensatz oder am Modell.*
                __('The same thing, for values that belong to the model rather than to an entry — what used to be called settings.', 'taxmod') . ' ' . $gemeinsam,
            ],
        };
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
    private function removedFields(Node $selected): string
    {
        $parked = $this->editor->removedFieldsOf($selected->id);

        if ($parked === []) {
            return '';
        }

        $rows = '';

        foreach ($parked as $relation) {
            $rows .= '<div style="display:flex;gap:.5em;align-items:center;opacity:.6;margin:.2em 0">'
                . '<span style="flex:1"><s>' . esc_html($relation->name) . '</s> '
                . '<span class="description">' . esc_html(sprintf(
                    /* translators: %d is the id of the act that removed it. */
                    __('removed by act #%d', 'taxmod'),
                    (int) $relation->parkedByGroup
                )) . '</span></span>'
                . $this->form(
                    $selected->id,
                    [['restore_field', esc_html__('Restore', 'taxmod'), __('Put it back', 'taxmod')]],
                    '<input type="hidden" name="relation" value="' . (int) $relation->id . '">'
                )
                . '</div>';
        }

        return '<details style="margin:.6em 0"><summary style="cursor:pointer">'
            . esc_html(sprintf(
                /* translators: %d is how many attributes were removed. */
                _n('%d removed field', '%d removed fields', count($parked), 'taxmod'),
                count($parked)
            ))
            . '</summary>' . $rows . '</details>';
    }

    private function fieldForm(Node $selected): string
    {
        // ⚠️ **Der Zielbaum ist nicht die Ansicht, und das war ein Fehler.** *Diese Methode bekam
        // die Zeilen des Schirms — samt seinem **Faltzustand** — und baute daraus beides: die
        // Sperrliste und den Wähler. **Wer den Baum zuklappte, konnte kein Feld mehr anlegen**,
        // weil dann nur noch Zweigwurzeln und zweiglose Knoten sichtbar waren und die Bedingung
        // «alle gesperrt» zutraf. Der Eigentümer hat es gemeldet: «nach dem Speichern kann ich
        // keine Felder mehr eingeben, bis ich auf einem Knoten war, der schon Felder hatte» —
        // ein solcher Knoten klappt seinen Ast auf, und damit kam der Wähler zurück.*
        //
        // ⚠️ *Gemessen war es eindeutig: eingeklappt **8 Zeilen, 8 gesperrt**; aufgeklappt
        // **94 Zeilen, 16 gesperrt**. Was wählbar ist, ist eine Tatsache über das Modell und
        // nicht darüber, was jemand gerade aufgeklappt hat.*
        //
        // ⚠️ *Versteckte bleiben draussen — ein verstecktes Vorkommen ist kein Ziel — und die
        // Wurzel ebenso, denn sie ist ohnehin gesperrt.*
        // WICHTIG: Nur der Einstiegsast steht offen, alles andere zugeklappt -- auf sein Wort:
        // "aufgeklappt werden soll nur der einstiegs ast ... bekommt optionaler default knoten,
        // einstiegs ast und root knoten als parameter".
        //
        // WICHTIG: Das ersetzt eine Sortierung, die ich vorher gebaut hatte und die falsch war:
        // sie riss den Ast aus seinem Elternknoten und stellte ihn oben hin, wodurch der Dialog
        // eine andere Hierarchie zeigte als die Seitenansicht. Er hat es sofort gesehen.
        // WICHTIG: Der Rand rechnet den Klappzustand nicht mehr aus -- der Chooser traegt sein
        // eigenes Konzept (Wurzel, offener Ast, Vorauswahl). Sein Einwand: "ausserdem hast du
        // nicht das gemacht was besprochen war, wir haben ein Konzept fuer den Knoten-Chooser".
        // Die Zeilen werden hier nur noch geholt, um die unmoeglichen Ziele zu bestimmen.
        $rows = $this->tree->rowsUnder($this->framework->root(), [$this->framework->trash()->id]);
        // ⚠️ **The last flat `<select>` on this screen, and now it is a tree** ([D-395](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *the type selection in the attribute should be the tree chooser too.* It was the
        // same eighty entries with middle dots that the parent chooser had before — and worse here,
        // because a target may be any node in four branches, so the list was the whole model flattened.
        //
        // ⚠️ **An impossible target is barred rather than omitted**, the same way the move chooser does
        // it: a branch root cannot be a target ([D-238](../../../docs/NewConcept/90-decision-log.md)),
        // and neither can a node in no branch at all — but **leaving them out tears a hole in the
        // hierarchy**, because a child of an impossible target can be a perfectly good one. *The cell
        // draws such a row as text with no radio.*
        $barred = [];

        foreach ($rows as $row) {
            $candidate = $row['node'];
            $branch    = $this->framework->branchOf($candidate);

            if ($branch === null || $candidate->id === $this->framework->rootOf($branch)->id) {
                $barred[] = $candidate->id;
            }
        }

        if (count($barred) === count($rows)) {
            return '<p><em>'
                . esc_html__('Nothing to point at yet: put a node under Model, Compositions, Data Types or Constants first.', 'taxmod')
                . '</em></p>';
        }

        // ⚠️ *The dialog rather than the inline chooser, because that is the default
        // ([D-244](../../../docs/NewConcept/90-decision-log.md)) and because this sits inside a form
        // that already has a name field — a tree unfolding in place would push the button it belongs to
        // off the screen.*
        $chooser = $this->rendering->nodeChooser(
            $this->framework->root(),
            // ⚠️ **Its own field name, not `target` again.** The move chooser already uses `target`, and
            // two radio groups of one name on one page is a collision waiting for a second reader — *and
            // it is honest besides: «where does this node go» and «what does this attribute point at»
            // are two questions.*
            'field_target',
            // Der offene Ast: die einfachen Typen, weil sie am meisten gebraucht werden.
            $this->framework->rootOf(Branch::DataTypes),
            // Vorauswahl: keine -- welcher Typ gemeint ist, weiss nur er.
            null,
            [$this->framework->trash()->id],
            $barred,
            null,
            __('Nothing here can be a target.', 'taxmod'),
            DialogChooserRenderer::NAME,
            $this->localeFromRequest(),
            Level::Admin,
            '<span class="button taxmod-icon-button" title="' . esc_attr__('Choose what this field points at', 'taxmod') . '">'
            . IconMarkup::dashicon('networking')
            . '<span class="screen-reader-text">' . esc_html__('Choose a target', 'taxmod') . '</span>'
            . '</span>',
            // WICHTIG: Kein Knopf mehr im Dialog. Auf sein Wort: "tree chooser ist ein standard
            // dialog, sollte keine zusaetzliche Funktion haben, Benutzer waehlt Knoten aus und
            // bestaetigt, Knoten wird in Anlege-Zeile angezeigt und der Benutzer kann einen Knopf
            // add/anlegen druecken". Damit ist der Dialog ueberall dasselbe Werkzeug.
            ''
        )->markup;

        // WICHTIG: Der Anlegen-Knopf steht in der Zeile, nicht im Dialog -- das dreht D-392 fuer
        // diesen Fall um, und zwar auf sein Wort. Die Begruendung dort war "zwei Knoepfe fuer
        // einen Akt"; hier sind es nicht zwei Knoepfe fuer einen Akt, sondern zwei Akte: waehlen
        // und anlegen. Was gewaehlt wurde, steht dazwischen sichtbar im gesperrten Feld.
        return $this->form(
            $selected->id,
            [],
            // WICHTIG: Ohne Namen wird der Knotenname benutzt -- TASK-026, auf sein Wort: "wenn
            // kein name eingegeben wird, wird der knoten name verwendet". Der Schalter steht
            // auf «Knotennamen benutzen», weil das der haeufigere Fall ist; er hat es so
            // entschieden. Das Feld ist deshalb auch nicht mehr Pflicht.
            // WICHTIG: Voreinstellung aus, nicht an -- auf sein Wort: "der Haken fuer use nodename
            // sollte Standard aus sein, passt besser". Das Namensfeld ist deshalb auch nicht mehr
            // gesperrt: der Schalter selbst entscheidet das per Skript ({@see admin.js}).
            '<label class="taxmod-usename"><input type="checkbox" name="use_node_name" value="1"> '
            . esc_html__('Use the node name', 'taxmod') . '</label>'
            . '<input type="text" name="name" placeholder="' . esc_attr__('Name of the field', 'taxmod') . '" style="flex:1">'
            // WICHTIG: Erst das Anzeigefeld, dann der Baumknopf -- auf sein Wort: "tree knop bei
            // der erstellung von feldern rechts vom anzeige feld". Dieselbe Reihenfolge wie jetzt
            // bei bestehenden Feldern ({@see FieldRowRenderer::targetCell()}).
            . '<input type="text" class="taxmod-chosen" readonly tabindex="-1"'
            . ' placeholder="' . esc_attr__('Nothing chosen yet', 'taxmod') . '" style="flex:1">'
            . $chooser
            // ⚠️ **Die Art wird angegeben, nicht geraten** ([D-618](../../../docs/NewConcept/90-decision-log.md),
            // TASK-053). *Sein Wort: «der benutzer legt fest». **Sie stand vorher nirgends auf der
            // Seite** — der Akt las sie am Zielast ab, und die Tabelle behauptete darunter, das sei
            // keine Wahl. Seit [D-639](../../../docs/NewConcept/90-decision-log.md) sind es genau
            // drei Werte mit je einer Klasse, also drei Einträge und kein vierter.*
            //
            // ⚠️ **Vorbelegt mit «composition», und das ist eine Messung und keine Meinung:**
            // *gemessen am 2026-09-05 tragen **42** benannte Kanten `composition`, **12** `setting`,
            // **4** `aggregation`. Eine Vorbelegung, die auf dem Schirm steht, ist etwas anderes als
            // eine Ableitung, die niemand sieht — sie ist zu ändern, bevor der Knopf gedrückt wird.*
            // ⚠️ **Ein Wort je Eintrag, die Erklärung ans Fragezeichen** — *und das ist keine
            // Kosmetik, sondern sein Befund am 2026-09-06: «du machst wieder Sonderfälle. Ein
            // Select-Feld sollte immer gleich gerendert werden, nicht einmal so und einmal so …
            // das lässt darauf schliessen, dass du die Design-Regeln nicht befolgst.» **Er hat
            // recht:** die Satzart hatte ich eine Stunde vorher genau so gekürzt
            // ([D-661](../../../docs/NewConcept/90-decision-log.md)) und diese Liste dabei
            // übersehen — zwei Auswahlfelder auf einer Seite, zwei Macharten.*
            . '<select name="relation_kind">'
            . '<option value="composition" selected>' . esc_html__('composition', 'taxmod') . '</option>'
            . '<option value="aggregation">' . esc_html__('aggregation', 'taxmod') . '</option>'
            . '<option value="setting">' . esc_html__('setting', 'taxmod') . '</option>'
            . '</select>'
            . HintMarkup::icon(
                __('composition — the target belongs to this node. aggregation — the target stands on its own. setting — a value the model carries, not an entry.', 'taxmod')
            )
            . ControlMarkup::button(new Control(
                'do',
                'add_field',
                __('Add field', 'taxmod'),
                '',
                true,
                false,
                '',
                '',
                true
            ))
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
     *
     * ⚠️ **The texts go with the page now**, on the owner's word — *labels should be saved with the
     * page too.* So the panel names the settings form ({@see SettingsRenderer::formFor()}) instead of
     * drawing one, the same `form="…"` seam the page-head save button already uses
     * ([D-392](../../../docs/NewConcept/90-decision-log.md)) — **pointing the other way**: there a
     * button stands outside its form, here five fields do.
     *
     * ⚠️ **Only `label_locale` rides along, because the rest is already in that form.** *`action`,
     * `id`, `relation` and the nonce are the settings panel's hidden fields; sending them a second time
     * would put two `id` fields in one submission, where the last one silently wins.*
     *
     * ⚠️ *And the locale **must** ride along: it is chosen by a `GET` and lives in the URL, which a
     * `POST` to `admin-post.php` cannot see. Without the hidden field every page save would write the
     * right text against the neutral locale.*
     */
    private function labelsPanel(Node $selected): string
    {
        $locale   = $this->localeFromRequest();
        $pageForm = self::pageForm($selected);
        $stored = [];

        // ⚠️ *Die Maske hat einen **Knoten** offen — der Raum ist damit gesagt und nicht geraten
        // (Fassung 31, `INF-035`).*
        foreach ($this->labels->storedFor($selected->id, IdentitySpace::Node) as $label) {
            $stored[$label->role->value . "\0" . $label->locale] = $label->text;
        }

        $slots = [];

        foreach (SeededRole::cases() as $role) {
            if (self::nameBelongsToTheNodeField($role, $locale)) {
                continue;
            }

            $slots[] = new LabelSlot(
                $role->value,
                // ⚠️ What the chain answers — another locale, `help`, and in the end the node's own
                // name (D-020). It becomes the field's placeholder, so an empty box reads as *nothing
                // is stored here* rather than as *this has no name*.
                $this->labels->of($selected, $role, $locale),
                $stored[$role->value . "\0" . $locale] ?? null,
                self::LABEL_FIELD . '[' . $role->value . ']',
                // ⚠️ `help` is the long one, and that is read off the role rather than off a list of
                // names in this method — a sixth role lands somewhere sensible by itself (`CD-9`).
                $role === SeededRole::Help,
                // ⚠️ **Jede Rolle ist übersetzbar, seit [D-645](../../../docs/NewConcept/90-decision-log.md)
                // auch `symbol`.** *«Symbol wird sprachabhängig» — hier stand die Ausnahme, die es
                // sprachneutral zeichnete, samt dem Hinweis «the same in every language». Beides ist
                // fort, weil es nicht mehr stimmt.*
                true,
                ''
            );
        }

        $html = $this->heading(
            __('Labels', 'taxmod'),
            __('What this node is called, in the language chosen on the left. Leave a field empty and the grey text is what will be shown instead — in the end, the node\'s own name.', 'taxmod')
        );

        return $html . $this->rendering->labelsPanelFor(
            $selected,
            $slots,
            // ⚠️ **Kein eigener Knopf mehr — der Eigentümer hat entschieden.** *Er stand hier eine
            // Fassung lang und war ein **Duplikat** des Kopf-Knopfes: seit die Texte im Seitenformular
            // liegen ([D-488](../../../docs/NewConcept/90-decision-log.md)), schickte er dasselbe ab
            // wie der Kopf. Auf die Frage «soll er weg» — «zu 1: ja».*
            //
            // ⚠️ *Warum er nicht einfach «nur Texte» behalten konnte, und das ist HTML und keine
            // Geschmacksfrage: **ein Feld gehört zu genau einem Formular**, `form="…"` überschreibt die
            // Verschachtelung. Ein Knopf, der ein anderes Formular abschickt, hätte die Einstellungen
            // daneben **stillschweigend weggeworfen**.*
            [],
            new Submission(admin_url('admin-post.php'), ['label_locale' => $locale, ...array_filter($this->circumstances())]),
            ['locale' => new Section(__('Locale', 'taxmod'), $this->localePicker($locale, $selected->id))],
            Purpose::Edit,
            $locale,
            Level::Admin,
            $pageForm
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
        // ⚠️ **The suffix is gone, on the owner's word** ([row 39](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)):
        // *«default» is too wide, not fully readable — maybe just leave it out, we have the setting
        // now.* **The interesting half is his reason**: the neutral locale is moving off a WordPress
        // option onto the installation identity ([D-397](../../../docs/NewConcept/90-decision-log.md)),
        // so a select that announces which locale is *the* default repeats a fact that is about to
        // have its own screen. *A narrow control saying half a word is worse than one saying the
        // language, and the language is all a person is choosing here.*
        $offered[self::neutralLocale()] = self::neutralLocale();

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
        // ⚠️ *Über {@see backTo()} und nicht mit einer eigenen Adresse: die eigene liess den
        // Faltzustand fallen, und ein Sprachwechsel klappte den Baum zu — genau der Fall, den
        // [D-480](../../../docs/NewConcept/90-decision-log.md) beschreibt, an der letzten Stelle, die
        // ihre Adresse noch selbst baute.*
        $base = $this->backTo((int) $nodeId);

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
        $recordId  = isset($_POST['node_record_id']) ? absint($_POST['node_record_id']) : 0;
        $submitted = isset($_POST[self::VALUE_FIELD]) && is_array($_POST[self::VALUE_FIELD])
            ? $_POST[self::VALUE_FIELD]
            : [];

        // ⚠️ **Der Datensatzblock schickt seine Felder unter der Satz-Id, und dieser Leser hat die
        // erste Ebene fuer die Kante gehalten.** *Gemessen am 2026-09-06, auf seinen Befund: «einen
        // neuen Datensatz anlegen funktioniert, aber keine Werte». Die Zeile zeichnet mit dem
        // Praefix `taxmod_value[<Satz-Id>]` ({@see \Taxmod\Core\Service\Rendering::recordsAsTable()}),
        // also kommt `taxmod_value[4756][75475]` an. **Hier stand `foreach ($submitted as
        // $rawRelation => …)`** — das las `4756` als Kanten-Id, fand dazu keine Kante des Knotens
        // und uebersprang **jeden** Wert. Anlegen ging, weil das ein anderer Akt ist; schreiben nie.*
        //
        // ⚠️ *Beide Formen werden gelesen: die geschachtelte, die der Block schickt, und die flache,
        // falls ein anderer Aufrufer sie noch benutzt. **Die geschachtelte gewinnt**, weil sie sagt,
        // zu welchem Satz die Werte gehoeren — und der Satz steht ohnehin schon in `node_record_id`.*
        if ($recordId !== 0 && isset($submitted[$recordId]) && is_array($submitted[$recordId])) {
            $submitted = $submitted[$recordId];
        }

        // ⚠️ **Die Art wird an der Zeile umgestellt** — *sein Wort: «default / user / example muss
        // einstellbar sein.»* *Sie kommt mit demselben Speichern wie die Werte: eine Handlung, eine
        // Aenderungsgruppe ([D-348](../../../docs/NewConcept/90-decision-log.md)) — der Akt ist
        // draussen schon geoeffnet.*
        //
        // ⚠️ **`tryFrom` und nicht `fromStorage()`, und der Unterschied ist der ganze Schutz:**
        // *`fromStorage()` antwortet auf eine fehlende Angabe mit `user`. **Ein alter Reiter ohne die
        // Auswahl haette damit jeden `default`-Satz beim Speichern still zu einer Benutzereingabe
        // gemacht** — und nach [D-654](../../../docs/NewConcept/90-decision-log.md) waere das keine
        // Beschriftung, sondern der Wegfall einer Vorbelegung. **Keine Angabe heisst hier: nichts
        // umstellen.***
        $gewaehlteArt = RecordType::tryFrom(
            isset($_POST['record_type']) ? sanitize_key(wp_unslash((string) $_POST['record_type'])) : ''
        );

        if ($recordId !== 0 && $gewaehlteArt !== null) {
            $this->data->retypeRecord($recordId, $gewaehlteArt);
        }

        if ($submitted === []) {
            return;
        }

        $attributes = [];

        foreach ($this->editor->fieldsOf($nodeId) as $relation) {
            $attributes[$relation->id] = $relation;
        }

        // One resolution for the whole form rather than one per field (`CD-7`).
        $types = $this->rendering->typesFor(array_values($attributes));

        // ⚠️ **Gathered first, then read in one pass** — because the converter in effect has to run on
        // the way **in** as well ([R36](../../../docs/NewConcept/30-renderer.md), list row 7). *A field
        // that draws `XII` and saves `XII` as text is a field that lost its value.* Reading per field
        // would resolve the converter setting per field, which is `CD-7`'s loop.
        $typedIn = [];

        foreach ($submitted as $rawRelation => $rawValue) {
            $relationId = absint($rawRelation);

            if (isset($attributes[$relationId])) {
                $typedIn[$relationId] = trim(sanitize_text_field(wp_unslash((string) $rawValue)));
            }
        }

        $values = $this->rendering->valuesFrom(
            array_values($attributes),
            array_filter($typedIn, static fn (string $one): bool => $one !== '')
        );

        foreach ($submitted as $rawRelation => $rawValue) {
            $relationId = absint($rawRelation);
            $relation   = $attributes[$relationId] ?? null;

            if ($relation === null) {
                // An relation id from a form is input. DataEntry refuses it as well; refusing twice
                // costs nothing and this one keeps a stale form from reaching the core at all.
                continue;
            }

            $characters = trim(sanitize_text_field(wp_unslash((string) $rawValue)));

            if ($characters === '') {
                // Empty means unanswered — the row goes, which is a third state beside a value
                // and an explicit nothing.
                $this->data->clear($recordId, $relationId);

                continue;
            }

            $type = $types[$relationId] ?? null;

            if ($type === null) {
                throw NotYetStorable::thatFieldHasNoTypeYet($relation->name);
            }

            // ⚠️ *Already read above, converter and all. The `??` is not a fallback for a missing
            // value — `valuesFrom()` answers for every relation that had a type, and this one does.*
            $this->data->put($recordId, $relationId, $values[$relationId] ?? $type->valueFrom($characters));
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

        // ⚠️ **Hier stand die Abbildung «Standardsprache → leere Spalte»** ([D-387](../../../docs/NewConcept/90-decision-log.md)).
        // *Sie ist mit [D-645](../../../docs/NewConcept/90-decision-log.md) gefallen: **es gibt keine
        // sprachneutrale Zeile mehr**, die Standardsprache steht als sie selbst da. Sein Wort: «das mit
        // der sprachneutralen Zeile hatten wir behoben.» **Solange die Abbildung stand, schrieb jedes
        // Speichern in der Standardsprache eine Zeile ohne Sprache** — gemessen am 2026-09-05.*
        return $asked === '' ? self::neutralLocale() : $asked;
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
        // ⚠️ **Ohne die Einstellungskanten, und das ist gemessen.** *Sie standen als Spalten in jedem
        // Benutzer-Datensatz — `Display Option`, `validator`, `read_only` — und waren **immer leer**:
        // gemessen am 2026-08-31 gibt es **null** Werte an Einstellungskanten in Benutzer-Datensätzen und
        // 148 in `default`-Sätzen. **Eine Einstellung wohnt im `default`-Satz** ([D-026](../../../docs/NewConcept/90-decision-log.md):
        // «at model level there are no values, only defaults»), also war die Spalte nicht bloss leer,
        // sondern das Angebot, eine Einstellung an die falsche Stelle zu schreiben.*
        //
        // ⚠️ *Dieselbe Trennung, die die Vorschau schon macht ([D-518](../../../docs/NewConcept/90-decision-log.md)):
        // eine Einstellung ist keine Daten. Und sie ist genau das, was seine «schmale Zeile» braucht.*
        $attributes = [];

        foreach ($this->editor->fieldsOf($selected->id) as $relation) {
            if (! $relation->isSetting()) {
                $attributes[] = $relation;
            }
        }

        // ⚠️ **Die Frage ist «hat er Felder», nicht «in welchem Zweig liegt er»**
        // ([D-522](../../../docs/NewConcept/90-decision-log.md)). *Der Eigentümer: «so ein Record, den
        // ich hier im Modell eingebe, ist auch einfach nur ein Record zur Kante — gehört er zu Field,
        // ist es ein Default-Wert; gehört er zu Settings, ist es eine Einstellung.»*
        //
        // ⚠️ **[D-183](../../../docs/NewConcept/90-decision-log.md) sagte das Gegenteil und war schon
        // falsch, bevor jemand daran rührte:** *gemessen hängen **232 Setting-Zeilen** an Knoten
        // ausserhalb von `Model` und `Compositions`, darunter `kilo`s Exponent 3. **Die Daten waren da
        // — sie lagen in einer anderen Tabelle und hiessen anders.***
        //
        // ⚠️ *Ein Knoten ohne Felder hat nichts aufzuzeichnen. Das schliesst aus, was auch vorher
        // nichts konnte — aber aus einem Grund, der am Knoten steht statt an seinem Zweig.*
        $branch = $this->framework->branchOf($selected);

        if ($attributes === [] && ($branch === null || ! $branch->holdsData())) {
            return $this->heading(
                __('Records', 'taxmod'),
                __('Nothing can be entered here, because this node has no fields. A node records values for its fields — under «Fields» that is what a person enters, under «Settings» what the author set.', 'taxmod')
            );
        }
        $records    = $this->data->recordsOf($selected->id);

        $html = $this->heading(
            __('Records', 'taxmod'),
            __('Things entered against this node. Each field looks the way its type says it should; a field marked «no renderer» is missing something, not styled oddly.', 'taxmod')
        );

        // ⚠️ **Die Art wird gewaehlt und nicht mehr angenommen** ([D-651](../../../docs/NewConcept/90-decision-log.md),
        // [D-653](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «bei neuem Record im
        // Backend wuerde ich gern waehlen koennen, ob default oder user» — und mit D-653 kam die
        // dritte dazu. **Der Kern nahm bisher `user` als Vorgabewert des Parameters**, also war die
        // Wahl nicht bloss unbedienbar, sondern unsichtbar.*
        //
        // ⚠️ *Was die drei bedeuten, steht in der Beschriftung und nicht in einem Hilfetext:
        // [D-654](../../../docs/NewConcept/90-decision-log.md) — «der `default` macht eine Vorgabe,
        // die auch bei der Eingabe verwendet werden soll; ein `example` wird nur gezeigt».*
        $html .= $this->form(
            $selected->id,
            [['add_record', esc_html__('New record', 'taxmod'), __('Start a record against this node', 'taxmod')]],
            $this->recordTypeChoice()
        );

        if ($records === []) {
            return $html . '<p><em>' . esc_html__('None yet.', 'taxmod') . '</em></p>';
        }

        // ⚠️ **Hier stand die Spalte «Belongs to» und sie ist gefallen** — *sein Wort: «die Spalte
        // belongs to kann weg.»* *Sie nannte den Knoten, der den Satz hält; **auf derselben Seite
        // steht dieser Knoten ohnehin schon**, denn es ist der ausgewählte. Was sie darüber hinaus
        // verriet — dass ein **Teil** ein Datensatz wie jeder andere ist und im Block seines
        // Zielknotens auftaucht —, bleibt wahr und braucht keine Spalte in jeder Zeile.*
        //
        // ⚠️ *Mit ihr geht die Abfrage nach den Haltern und die Methode, die sie las. **Toter Kode
        // wird nicht aufbewahrt** (`CLAUDE.md`); `DataEntry::holdersOf()` bleibt, wo es gebraucht
        // wird — das Löschen eines Teils hängt daran.*
        $zeilen = [];

        foreach ($records as $record) {
            $held = [];

            foreach ($this->data->valuesOf($record->id) as $value) {
                $held[$value->relationId] = $value->value;
            }

            $zeilen[] = [
                'id'     => $record->id,
                'values' => $held,
                'lead'   => [
                    __('Record', 'taxmod')     => '<code>#' . esc_html((string) $record->id) . '</code>',
                    // ⚠️ **Die Art wird hier auch **umgestellt** und nicht nur angezeigt** — *sein
                    // Wort: «default / user / example muss einstellbar sein.» Bisher stand hier der
                    // Wert in einem `<code>`: eine Angabe, die man beim Anlegen macht und danach nie
                    // wieder anfassen kann.*
                    //
                    // ⚠️ *Am Formular **dieser Zeile**, also wird sie mit ihrem «Speichern»
                    // geschrieben — zwei Datensaetze sind zwei Dinge, und ein Speichern darf nicht
                    // beide umstellen.*
                    // ⚠️ **Die Auskunft steht vorn, das Bedienbare bei den anderen Feldern** — *sein
                    // Wort am 2026-09-06: «schieb mal die Version vor die Eingabefelder; im Grunde
                    // brauchen wir die Informationen nicht, kann aber auch nichts schaden.» Nummer
                    // und Version sind zum **Lesen**, der Wähler ist zum **Tun**; er gehört neben
                    // die Werte und nicht zwischen zwei Angaben, die niemand anfasst.*
                    __('Version', 'taxmod')    => esc_html((string) $record->nodeVersion),
                    __('Kind', 'taxmod')       => $this->recordTypeChoice(
                        $record->recordType,
                        'taxmod-record-' . $record->id
                    ),
                ],
                'acts'   => [
                    Control::saving('do', 'save_record', __('Save', 'taxmod'), __('Write these values', 'taxmod')),
                    // ⚠️ **Das Loeschen aus [D-653](../../../docs/NewConcept/90-decision-log.md)**
                    // — *«Baue mal die Auswahl und das Loeschen».* Es ist umkehrbar
                    // ({@see \Taxmod\Core\Service\DataEntry::removeRecord()}), also traegt es die
                    // rote Marke, aber keine Warnung, die es nicht braucht.
                    new Control(
                        'do',
                        'delete_record',
                        __('Delete', 'taxmod'),
                        __('Take this record away — it stays in the shadow and can be brought back', 'taxmod'),
                        true,
                        true,
                        'trash'
                    ),
                ],
                'submits' => new Submission(
                    admin_url('admin-post.php'),
                    [
                        'action'        => self::ACTION,
                        'id'            => (string) $selected->id,
                        'node_record_id'     => (string) $record->id,
                        '_taxmod_nonce' => wp_create_nonce(self::ACTION . '_' . $selected->id),
                        ...array_filter($this->circumstances()),
                    ]
                ),
            ];
        }

        return $html . $this->rendering->recordsAsTable(
            $selected,
            $attributes,
            $zeilen,
            self::VALUE_FIELD,
            '',
            $this->inDeveloperMode()
        )->markup;
    }

    /**
     * Das Auswahlfeld fuer die **Satzart** beim Anlegen.
     *
     * ⚠️ **Die drei Arten kommen aus der Aufzaehlung und nicht aus einer Liste hier**
     * ({@see RecordType}). *Kaeme eine vierte dazu, stuende sie hier von selbst — eine
     * abgeschriebene Liste waere die zweite Fassung derselben Menge (`CD`).*
     *
     * ⚠️ *Die Beschriftungen gehen durch die Textdomaene (`AR-2`); der **Wert** ist die Kennung der
     * Aufzaehlung und wird nie uebersetzt.*
     */
    private function recordTypeChoice(?RecordType $gewaehlt = null, string $formId = ''): string
    {
        // ⚠️ **Ein Wort je Art, und die Erklaerung steht am Fragezeichen** — *sein Wort am
        // 2026-09-06: «hier nur noch Example / Default / Record, Feld ist sonst zu breit». Der
        // erklaerende Nachsatz machte das Auswahlfeld breiter als die Spalte, die es beschreibt,
        // und er stand dreimal fast gleich da. **Er ist nicht verschwunden, sondern umgezogen**
        // ([D-661](../../../docs/NewConcept/90-decision-log.md)): der Hinweis unter dem Feld sagt
        // weiter, was die Arten unterscheidet.*
        $worte = [
            RecordType::User->value    => __('Entry', 'taxmod'),
            RecordType::Default->value => __('Default', 'taxmod'),
            RecordType::Example->value => __('Example', 'taxmod'),
        ];

        $steht    = $gewaehlt ?? RecordType::standard();
        $optionen = '';

        foreach (RecordType::cases() as $art) {
            $optionen .= '<option value="' . esc_attr($art->value) . '"'
                . ($art === $steht ? ' selected' : '') . '>'
                . esc_html($worte[$art->value] ?? $art->value) . '</option>';
        }

        // ⚠️ **Der Hinweis steht an der Bedienung und nicht bloss im Kode**
        // ([D-654](../../../docs/NewConcept/90-decision-log.md)): *«der Unterschied ist, dass der
        // `default` eine Vorgabe macht, die auch bei der Eingabe verwendet werden soll — eine
        // Vorbelegung.»* **Umstellen aendert also, was mit kuenftigen Datensaetzen geschieht**, und
        // wer das an einer Zeile tut, muss es dort lesen koennen und nicht im Entscheidungsprotokoll.
        // ⚠️ *Der Satz steht nicht mehr **neben** der Auswahl, sondern hinter ihrem Fragezeichen
        // ([D-661](../../../docs/NewConcept/90-decision-log.md)) — er ist Erklärung und nicht
        // Auskunft, und in einer Tabellenzeile kostete er jede Zeile Platz.*
        $hinweis = $formId === ''
            ? ''
            : ' ' . HintMarkup::icon(
                __('Changing this changes what future records start with: a default presets them, an example does not.', 'taxmod')
            );

        // ⚠️ *Ohne `form="…"` schickt die Auswahl lautlos nichts, wenn sie ausserhalb ihres Formulars
        // steht — eine Tabellenzelle neben der Zelle mit dem `<form>`. Beim Anlegen steht sie **in**
        // ihrem Formular, dort bleibt das Attribut weg.*
        return '<label class="taxmod-record-type">'
            . '<span class="screen-reader-text">' . esc_html__('Kind of record', 'taxmod') . '</span>'
            . '<select name="record_type"' . ($formId === '' ? '' : ' form="' . esc_attr($formId) . '"') . '>'
            . $optionen . '</select></label>' . $hinweis . ' ';
    }

    // ⚠️ *Hier stand `belongsTo()`, der Zeichner der Spalte «Belongs to». Er ist mit ihr gegangen
    // (sein Wort: «die Spalte belongs to kann weg») — **eine Methode, die niemand ruft, ist toter
    // Kode**, und die Spalte selbst ist oben begründet.*

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

            $lines .= '<li><code>' . esc_html($field->relation->name) . '</code> — ' . $what
                . ($field->isHidden() ? ' · ' . esc_html__('hidden by a setting', 'taxmod') : '')
                . '</li>';
        }

        return $lines === ''
            ? ''
            : '<ul class="description" style="margin:.4em 0 0;opacity:.75">' . $lines . '</ul>';
    }

    // ------------------------------------------------------------------ acting




    /*
     * Hier stand `settingChain()` — die Kette, an deren letztes Glied eine Einstellung geschrieben
     * wurde ([D-381](../../../docs/NewConcept/90-decision-log.md)). **Mit der `settings`-Tabelle
     * ([D-579](../../../docs/NewConcept/90-decision-log.md)) ist auch die Kette gestrichen**: es
     * gibt keinen Schreiber mehr, der ein Ziel bräuchte.
     */

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

    /**
     * ⚠️ **Read once per request, not once per caller.** *Three places ask for the selected node and
     * measuring showed two of them hitting the database — the owner's ask was «simply make sure objects
     * are not loaded twice» ([D-455](../../../docs/NewConcept/90-decision-log.md)), and this is the
     * smaller half of it. The larger half was one shared {@see \Taxmod\WordPress\Plugin} store.*
     *
     * ⚠️ **Keyed on the requested id, and that is not belt-and-braces — the unkeyed version was a real
     * regression and the boundary run caught it in one minute.** *`preview-check` and `package7-check`
     * change `$_GET['taxmod_node']` between two renders of the same screen, so a memo that remembered
     * «already asked» handed back the **previous** node. One request has one query string in production;
     * a checker is a legitimate caller that does not, and **a memo must not outlive the thing it depends
     * on.***
     *
     * ⚠️ *The key is the raw id and not a null check on `$selected`, because **null is a real answer**:
     * no `taxmod_node`, or an id nothing answers to. Keying on the id keeps that answer cached too.*
     *
     * @var array<int, ?Node>
     */
    private array $selected = [];

    private function selectedFromRequest(): ?Node
    {
        if (! isset($_GET['taxmod_node'])) {
            return null;
        }

        $id = absint($_GET['taxmod_node']);

        return array_key_exists($id, $this->selected)
            ? $this->selected[$id]
            : $this->selected[$id] = $this->editor->find($id);
    }

    /**
     * The folded set this request carries — **`null` when it carries none**.
     *
     * ⚠️ **`null` and `[]` are two different states and the difference is the whole of
     * [list row 60](../../../docs/NewConcept/97-implementation-plan.md#the-working-list).** *`null` is
     * «nobody has folded anything on this page yet», which now means **everything folded**
     * ({@see \Taxmod\Core\Service\Tree::collapsedByDefault()}); `[]` is «somebody unfolded the last
     * branch», which has to stay unfolded. **Returning `[]` for a missing parameter would make the
     * two indistinguishable** — a person who deliberately opened the whole tree would find it shut
     * again on the next click.*
     *
     * ⚠️ **That is what {@see self::ALL_EXPANDED} is for.** *An empty list cannot be written into a
     * query string — `add_query_arg` and `array_filter` both drop an empty value, and one that
     * survived would still be read back as «absent» here. So the empty set travels as a word.*
     *
     * @return list<int>|null
     */
    private function collapsedFromRequest(): ?array
    {
        if (! isset($_GET['taxmod_collapsed'])) {
            return null;
        }

        $raw = sanitize_text_field(wp_unslash($_GET['taxmod_collapsed']));

        // `absint()` turns the marker into 0 and `array_filter` drops it, so the deliberate empty
        // set arrives as an empty list rather than as a missing parameter.
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
    private function saveField(int $id, int $relation, string $name, string $multiplicity): void
    {
        $existing = $this->editor->ownAttribute($id, $relation);

        if ($name !== '' && $name !== $existing->name) {
            $this->editor->renameField($id, $relation, $name);
        }

        if ($multiplicity === '') {
            return;
        }

        // ⚠️ **An die Kante geschrieben, nicht in die Settings-Tabelle** ([D-528](../../../docs/NewConcept/90-decision-log.md)).
        // *Der Vergleich davor braucht keine Auflösung mehr: die Kante trägt ihren Wert selbst, und es
        // gibt keine Kette, aus der er kommen könnte.*
        $gewuenscht = Multiplicity::tryFrom($multiplicity);

        // ⚠️ *Ein unbekannter Wert wird still verworfen und nicht geraten — die vier Konstanten sind
        // die ganze Liste ([D-351](../../../docs/NewConcept/90-decision-log.md)), und ein fünfter
        // Wert kommt nur aus einem manipulierten Formular.*
        if ($gewuenscht === null || $existing->multiplicity === $gewuenscht) {
            return;
        }

        $this->editor->setMultiplicity($id, $relation, $gewuenscht);
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
     *
     * ⚠️ **Since the texts travel with the page save, «unchanged» carries the whole weight of that
     * sentence.** *A text field always submits, empty or not — the same shape of trap the settings
     * panel had with a switch that always sends `0` or `1`
     * ([D-392](../../../docs/NewConcept/90-decision-log.md)), where every untouched switch counted as
     * changed and wrote `persistent=false` onto every node somebody opened. Here the comparison is
     * against what is **stored in this locale** and not against what the chain answers, which is the
     * one right yardstick: the chain's answer is the field's **placeholder**, so treating it as the
     * value would write the fallback into a row on the first save of any page.*
     *
     * ⚠️ **An empty field where a row exists **removes** it, and that is D-384 rather than a
     * refinement.** *It was written as `put(…, '')`, which stores a row that says nothing — and the
     * fallback reader only survives it because it happens to skip empty texts. Measured before the
     * change: 46 label rows, **0** of them empty, so nothing existing depends on the old shape.*
     */
    /**
     * Ob die Rolle `name` in dieser Sprache dem **Namensfeld** des Knotens gehört und nicht der
     * Beschriftungsmaske.
     *
     * ⚠️ **Zwei Felder für denselben Text sind ein Feld zu viel** (TASK-019, D-646). *Seit der Name
     * sprachabhängig ist, ist er eine Beschriftung wie jede andere — **in der Standardsprache steht er
     * aber schon im Namensfeld oben**. Stünde er zusätzlich hier, schriebe das eine das andere
     * stillschweigend zurück, weil beide Masken in einem POST gespeichert werden.*
     *
     * ⚠️ *In **jeder anderen** Sprache gehört er hierher, und das ist der ganze Zweck von D-646:
     * «sonst schaltet man die Sprache um und alle Knoten haben noch den gleichen Namen».*
     */
    private static function nameBelongsToTheNodeField(SeededRole $role, string $locale): bool
    {
        return $role === SeededRole::Name && $locale === SettingsScreen::neutralLocale();
    }

    private function saveLabels(int $nodeId, string $locale): void
    {
        $submitted = isset($_POST[self::LABEL_FIELD]) && is_array($_POST[self::LABEL_FIELD])
            ? wp_unslash($_POST[self::LABEL_FIELD])
            : [];

        $stored = [];

        foreach ($this->labels->storedFor($nodeId, IdentitySpace::Node) as $label) {
            if ($label->locale === $locale) {
                $stored[$label->role->value] = $label->text;
            }
        }

        foreach (SeededRole::cases() as $role) {
            if (! array_key_exists($role->value, $submitted) || self::nameBelongsToTheNodeField($role, $locale)) {
                continue;
            }

            $text   = sanitize_textarea_field((string) $submitted[$role->value]);
            $before = $stored[$role->value] ?? null;

            if ($text === (string) $before) {
                continue;
            }

            $label = new Label($nodeId, IdentitySpace::Node, $role, Label::BASE_NUMBER, $locale, $text);

            if ($text === '') {
                $this->labels->forget($label);

                continue;
            }

            $this->labels->put($label);
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
            // ⚠️ *Hier stand das Fragezeichen **von Hand hingeschrieben**, und es war die einzige
            // Stelle, die es hatte. Seit [D-661](../../../docs/NewConcept/90-decision-log.md) ist es
            // die allgemeine Lösung, also gehört das Wissen darüber, wie so ein Zeichen aussieht, in
            // {@see HintMarkup} und nicht in eine Überschriftenmethode.*
            . HintMarkup::behind(esc_html($text), $hint)
            . '</' . $level . '>';
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
     * Everything the node page holds — its name, its settings and its texts — in one act.
     *
     * The owner, 2026-08-28: *labels should be saved with the page too.*
     *
     * ⚠️ **One method because it is one act, not because the two writes are alike.** The bracket that
     * gives a change its number is around the whole POST ([D-470](../../../docs/NewConcept/90-decision-log.md)),
     * so a rename, a setting and a text saved together already share one `change_group_id` — *what
     * this method adds is that they are saved together at all.*
     *
     * ⚠️ **The order is settings first and it matters for exactly one thing:** a bounding setting may
     * only be narrowed and the core refuses ([D-312](../../../docs/NewConcept/90-decision-log.md)), so
     * a refusal stops the act before the texts are written. *That is the interim `saveSettings()`
     * already documents — failing loudly on a partial batch — and putting the labels after it keeps
     * one rule for the whole page instead of two.*
     *
     * ⚠️ *`$locale` comes from a hidden field and not from the URL: the page is saved by a `POST` to
     * `admin-post.php`, which never sees the `taxmod_locale` the panel was drawn with.*
     */
    private function saveNodePage(int $nodeId, int $relationId, string $name, string $locale): void
    {
        $this->saveSettings($nodeId, $relationId, $name);
        $this->saveFieldRows($nodeId);
        $this->saveSettingValues($nodeId);
        $this->savePartValues($nodeId);
        $this->saveLabels($nodeId, $locale);
    }

    /**
     * Die Feldzeilen des Knotens — Name und «wie oft» — mit der Seite gespeichert.
     *
     * ⚠️ **Auf seinen Befund, und es war kein Rechenfehler, sondern ein fehlender Leser:** *«in Display
     * Option hatte ich für Converter die `1..1`-Beziehung angegeben, das ist falsch, ich wollte es in
     * `0..1` ändern, kann es aber nicht mit dem Speichern-Knopf in der Seite speichern.»* **Die Angabe
     * lag im Formular der Zeile und wurde nur von der Diskette der Zeile abgeschickt** — der
     * Seiten-Knopf sah sie nie.
     *
     * ⚠️ **Und es ist sein alter Wunsch, jetzt fällig:** *«Save in Fields sollte eigentlich auch über die
     * Seite gehen».* Er lag zurückgestellt, weil ein `required` in einer von sechzig Zeilen das Speichern
     * der ganzen Seite gesperrt hätte — *diese Sperre ist weg
     * ([Zeile 94](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)), also geht es.*
     *
     * ⚠️ **Nur eigene Kanten** ([D-376](../../../docs/NewConcept/90-decision-log.md)): *eine geerbte
     * gehört dem Vorfahren, und {@see \Taxmod\Core\Service\ModelEditor::ownAttribute()} verweigert sie —
     * die Zeile zeichnet sie gar nicht bedienbar, aber ein verändertes Formular darf es auch nicht
     * (`CD-5`).*
     *
     * ⚠️ *Geschrieben wird je Zeile nur, was sich geändert hat — {@see self::saveField()} entscheidet
     * das, und damit landet ein Speichern auf einer unberührten Tabelle nicht als sechzig Einträge im
     * Änderungsbuch.*
     */
    private function saveFieldRows(int $nodeId): void
    {
        $namen = isset($_POST[self::NAME_FIELD]) && is_array($_POST[self::NAME_FIELD])
            ? wp_unslash($_POST[self::NAME_FIELD])
            : [];

        $angaben = isset($_POST[self::ROW_SETTING_FIELD]) && is_array($_POST[self::ROW_SETTING_FIELD])
            ? wp_unslash($_POST[self::ROW_SETTING_FIELD])
            : [];

        if ($namen === [] && $angaben === []) {
            return;
        }

        foreach ($this->editor->fieldsOf($nodeId) as $kante) {
            if ($kante->fromNodeId !== $nodeId) {
                continue;
            }

            $name = isset($namen[$kante->id]) && ! is_array($namen[$kante->id])
                ? sanitize_text_field((string) $namen[$kante->id])
                : '';

            $wieOft = isset($angaben[$kante->id][SettingKey::Multiplicity->value])
                && ! is_array($angaben[$kante->id][SettingKey::Multiplicity->value])
                ? sanitize_text_field((string) $angaben[$kante->id][SettingKey::Multiplicity->value])
                : '';

            // ⚠️ **Alles ausser «wie oft» ist eine Einstellung dieser Verwendungsstelle**
            // ([`INF-011`](../../../docs/pakete/modelltabellen/inbox.md)). *Gezeichnet wurden sie
            // längst — als **Feldzeilen im Settings-Block**, wie
            // [D-520](../../../docs/NewConcept/90-decision-log.md) es verlangt —, angenommen hat sie
            // niemand: diese Schleife las genau einen Schlüssel und liess die übrigen fallen. **Ein
            // Steuerelement, das man bedienen kann und das nichts bewirkt**, ist derselbe Mangel, den
            // der Eigentümer am Renderer gefunden hat.*
            //
            // ⚠️ *«wie oft» geht weiter seinen eigenen Weg: es ist eine **Spalte** der Kante
            // ([D-351](../../../docs/NewConcept/90-decision-log.md)) und keine Zeile in einem Satz.*
            $this->saveUseSiteSettings($kante, is_array($angaben[$kante->id] ?? null) ? $angaben[$kante->id] : []);

            if ($name === '' && $wieOft === '') {
                continue;
            }

            $this->saveField($nodeId, $kante->id, $name, $wieOft);
        }
    }

    /**
     * Die Angaben **einer Feldzeile** annehmen — die Einstellungen dieser einen Verwendungsstelle.
     *
     * ⚠️ **Das fehlende Gegenstück zum Leser** ({@see \Taxmod\Core\Service\ModelValues::forUseSite()}).
     * *`label_role` an `Einheitenwert.prefix`, `converter` an einem Feld: der Leser sucht sie im Satz
     * des Besitzers unter `<Verwendungsstelle>.<Einstellungskante>`, und geschrieben hat sie zuletzt
     * `Settings::put()` in die mit [D-579](../../../docs/NewConcept/90-decision-log.md) gestrichene
     * Tabelle.*
     *
     * ⚠️ **Keine zweite Tafel** ([D-520](../../../docs/NewConcept/90-decision-log.md)): *die Angaben
     * stehen als Feldzeilen im Settings-Block und kommen unter der Adresse an, die diese Zeilen schon
     * zeichnen. **Hier entsteht kein neues Steuerelement**, nur der Weg, den das gezeichnete nimmt.*
     *
     * ⚠️ **Der Schlüssel wird nachgeschlagen und nicht geglaubt** (`CD-5`): *nur eine
     * Einstellungskante, die das Ziel dieser Stelle wirklich trägt, darf geschrieben werden — sonst
     * schriebe ein verändertes Formular an eine Adresse, die niemand liest.*
     *
     * ⚠️ *Ein leerer Wert **löscht**, wie überall sonst auf dieser Seite: er kommt nur an, wenn ein
     * Steuerelement dastand, und «nichts» ist eine Wahl
     * ([D-232](../../../docs/NewConcept/90-decision-log.md)s dritter Zustand).*
     *
     * @param array<array-key, mixed> $angaben Schlüssel ⇒ eingereichter Text.
     */
    private function saveUseSiteSettings(Relation $useSite, array $angaben): void
    {
        foreach ($angaben as $schluessel => $roh) {
            $key = sanitize_key((string) $schluessel);

            // «wie oft» ist eine Spalte der Kante und wird von saveField() geschrieben.
            if ($key === SettingKey::Multiplicity->value || is_array($roh)) {
                continue;
            }

            $kante = $this->data->settingRelationAtUseSite($useSite, $key);

            if ($kante === null) {
                continue;
            }

            $zeichen = trim(sanitize_text_field((string) $roh));

            if ($zeichen === '') {
                $this->data->clearSettingAtUseSite($useSite->id, $kante->id);

                continue;
            }

            $wert = $this->rendering->valuesFrom([$kante], [$kante->id => $zeichen])[$kante->id] ?? null;

            if ($wert === null || $wert->isNothing()) {
                continue;
            }

            $this->data->putSettingAtUseSite($useSite->id, $kante->id, $wert);
        }
    }

    /**
     * Die Werte, die in einem **Teil** eingegeben wurden — adressiert über seine Satz-Id.
     *
     * ⚠️ **Die Satz-Id ist die Adresse, und das ist seine Lehre.** *Zweimal hat er mich gestossen —
     * «warum wieder path? verstehe ich nicht» und «arbeitest auf einmal mit Pfaden anstatt mit den Ids,
     * die wir haben» — und als es dastand: «siehst du, Satz-Id».*
     *
     * ⚠️ **Sie ist eindeutig, wo eine Kette es nicht wäre:** *bei mehreren Teilen
     * ([D-548](../../../docs/NewConcept/90-decision-log.md), für das Farbschema) tragen alle **dieselben**
     * Kanten — nur der Satz unterscheidet sie.*
     *
     * ⚠️ **Und die Id aus dem Formular wird nicht geglaubt** (`CD-5`). *Erlaubt ist nur ein Teil, den
     * dieser Knoten wirklich hat, und darin nur eine Kante, die dem Knoten des Teils gehört. Sonst
     * könnte ein verändertes Formular in einen fremden Datensatz schreiben.*
     */
    /**
     * Einen weiteren Teil an dieser Kante anlegen — eine Zeile mehr.
     *
     * ⚠️ **Auf sein Bestehen, dass die Multiplizität `1..*` ist und nicht `0..*`:** *«somit muss ich
     * Zeilen hinzufügen können».*
     *
     * ⚠️ *Ein Teil ist eine Zeile ([D-546](../../../docs/NewConcept/90-decision-log.md)), und mehrere
     * Teile sind mehrere Renderer — der Grund ist seiner
     * ([D-548](../../../docs/NewConcept/90-decision-log.md)): «hatten wir definiert für Farbschema».*
     *
     * ⚠️ **Die Kante wird geprüft und nicht geglaubt** (`CD-5`): *nur eine, die dieser Knoten wirklich
     * trägt, und nur eine, deren Multiplizität mehrere zulässt. Ob am Ziel überhaupt ein Teil entstehen
     * kann, entscheidet der Kern ({@see \Taxmod\Core\Service\DataEntry::createPart()}) — dort sitzt die
     * Regel aus [D-541](../../../docs/NewConcept/90-decision-log.md), und hier wird sie nicht kopiert.*
     */
    private function addPart(int $nodeId, int $relationId): void
    {
        $kante = null;

        foreach ($this->editor->fieldsOf($nodeId) as $eine) {
            if ($eine->id === $relationId) {
                $kante = $eine;
            }
        }

        if ($kante === null || ! $kante->multiplicity->allowsMany()) {
            return;
        }

        $this->data->addSettingPart($nodeId, $kante->id);
    }

    private function savePartValues(int $nodeId): void
    {
        $eingereicht = isset($_POST[Rendering::PART_FIELD]) && is_array($_POST[Rendering::PART_FIELD])
            ? wp_unslash($_POST[Rendering::PART_FIELD])
            : [];

        if ($eingereicht === []) {
            return;
        }

        // ⚠️ *Alle Feldkanten des Knotens, damit sowohl Einstellungen als auch zusammengesetzte Felder
        // erfasst sind — beide leben in einem Teil.*
        $kanten = array_map(
            static fn (Relation $relation): int => $relation->id,
            $this->editor->fieldsOf($nodeId)
        );

        $erlaubt = [];

        foreach ($this->data->settingPartsOf($nodeId, $kanten) as $liste) {
            foreach ($liste as $teil) {
                $erlaubt[$teil['id']] = (int) $teil['nodeId'];
            }
        }

        foreach ($eingereicht as $rohTeil => $werte) {
            $teilId = absint($rohTeil);
            $imTeil = $erlaubt[$teilId] ?? null;

            if ($imTeil === null || ! is_array($werte)) {
                continue;
            }

            $innen = [];

            foreach ($this->editor->fieldsOf($imTeil) as $feld) {
                $innen[$feld->id] = $feld;
            }

            foreach ($werte as $rohKante => $wert) {
                $kante = $innen[absint($rohKante)] ?? null;

                if ($kante === null || is_array($wert)) {
                    continue;
                }

                $this->putOneSettingValue($nodeId, $kante, 0, $kante, (string) $wert, $teilId);
            }
        }
    }

    /**
     * Die Werte der Angaben, die in der Wertspalte eingegeben wurden.
     *
     * ⚠️ **Das Konzept nennt diesen Weg als den einzigen:** *«der Eingabemechanismus existiert bereits:
     * **die Einstellungsseite**. Was sich ändert, ist nur, wie sie zu verstehen ist — hier schreibt der
     * Autor Feldwerte am Modell»* ([02-field-and-setting.md](../../../docs/NewConcept/02-field-and-setting.md)).
     * *Und der Eigentümer hat mich daran erinnert, als ich daneben einen eigenen Renderer-Wähler gebaut
     * hatte: «das ist genau dafür da, und das ist glaube ich das, was du am Konzept vorbei machst».*
     *
     * ⚠️ **Ein Speichern für die ganze Seite, keines je Wert** — *sein Wunsch, zweimal: «biete keinen
     * Button an» und «Save in Fields sollte eigentlich auch über die Seite gehen». Deshalb hängen die
     * Bedienelemente am Seitenformular und werden hier zusammen gelesen; ein Akt, eine Änderungsnummer
     * ([D-348](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ **Die Adresse ist zweistufig, weil der Wert zwei Stufen tief liegt** — `taxmod_value[aussen]`
     * für eine Angabe, die direkt an ihrer Kante steht, und `taxmod_value[aussen][innen]` für eine, die
     * in einem eigenen Teil wohnt ([D-541](../../../docs/NewConcept/90-decision-log.md)). *Beide Formen
     * kommen aus derselben Zeichnung; **was hier nicht geraten wird, ist welche** — `is_array()`
     * entscheidet es.*
     */
    private function saveSettingValues(int $nodeId): void
    {
        $eingereicht = isset($_POST[self::VALUE_FIELD]) && is_array($_POST[self::VALUE_FIELD])
            ? wp_unslash($_POST[self::VALUE_FIELD])
            : [];

        if ($eingereicht === []) {
            return;
        }

        // ⚠️ *Nur Kanten, die dieser Knoten wirklich trägt — was das Formular sonst noch mitbringt,
        // gehört nicht hierher (`CD-5`).*
        $aussen = [];

        foreach ($this->editor->fieldsOf($nodeId) as $relation) {
            $aussen[$relation->id] = $relation;
        }

        foreach ($eingereicht as $rohAussen => $roh) {
            $aussenId = absint($rohAussen);
            $kante    = $aussen[$aussenId] ?? null;

            if ($kante === null) {
                continue;
            }

            if (! is_array($roh)) {
                $this->putOneSettingValue($nodeId, $kante, 0, $kante, (string) $roh);

                continue;
            }

            // ⚠️ *Die Felder des Teils, einmal geholt — nicht je Wert (`CD-7`).*
            $innen = [];

            foreach ($this->editor->fieldsOf($kante->toNodeId) as $feld) {
                $innen[$feld->id] = $feld;
            }

            foreach ($roh as $rohInnen => $wert) {
                $innenId    = absint($rohInnen);
                $innenKante = $innen[$innenId] ?? null;

                if ($innenKante === null || is_array($wert)) {
                    continue;
                }

                $this->putOneSettingValue($nodeId, $kante, $innenId, $innenKante, (string) $wert);
            }
        }
    }

    /**
     * Einen einzelnen Wert schreiben — oder ihn herausnehmen, wenn «nichts» gewählt wurde.
     *
     * ⚠️ *Der Typ wird an der Kante bestimmt, deren Wert es **ist** — bei einem Teil also an der inneren.
     * `valuesFrom()` ist derselbe Weg, den der Datensatzblock nimmt; ein zweiter wäre eine zweite
     * Gelegenheit, anders zu deuten.*
     *
     * ⚠️ **Ein leeres Feld löscht — und die alte Begründung dagegen war ein Denkfehler.** *Hier stand
     * «ein leeres Feld löscht nicht, sonst räumte jedes Speichern der Seite alles ab, was auf ihr nicht
     * gezeichnet wurde». **Was nicht gezeichnet wurde, schickt auch nichts**, und diese Schleife läuft
     * über die **eingereichten** Schlüssel: ein Schlüssel, der ankommt, ist ein Bedienelement, das
     * dastand. Der Unterschied zwischen «abwesend» und «leer» ist genau der, den {@see saveSettings()}
     * mit `array_key_exists` schon macht — hier war er zugunsten des einen Falls aufgegeben, der
     * dadurch unerreichbar wurde.*
     *
     * ⚠️ **Und er ist genau darüber gestolpert:** *«wenn ich `0..1` wähle, müsste ich auch nichts im
     * Value wählen können — kann ich auch auswählen, wird aber nicht speichern, müsste eigentlich den
     * Datensatz dahinter löschen.» **«Nichts» war die einzige Wahl, die sich nicht speichern liess.***
     *
     * ⚠️ *Ein gesperrtes Steuerelement schickt gar nichts ([R28](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete)),
     * und bei `1..1` gibt es die leere Wahl nicht — **beide Fälle kommen hier also nie als leer an**.*
     */
    private function putOneSettingValue(
        int $nodeId,
        Relation $carrier,
        int $innerId,
        Relation $ofValue,
        string $submitted,
        /**
         * ⚠️ *Ist der Teil schon bekannt — weil das Formular seine **Satz-Id** mitgeschickt hat —, wird
         * direkt in ihn geschrieben. Dann muss niemand ihn über die Trägerkante suchen, und bei mehreren
         * Teilen ([D-548](../../../docs/NewConcept/90-decision-log.md)) trifft es den richtigen.*
         */
        int $partId = 0
    ): void {
        $characters = trim(sanitize_text_field($submitted));

        // ⚠️ *Erst löschen, dann deuten: «nichts» hat keinen Typ, an dem `valuesFrom()` es festmachen
        // könnte — es ist die Abwesenheit eines Wertes und nicht ein Wert der Sorte leer.*
        if ($characters === '') {
            if ($partId !== 0) {
                $this->data->clear($partId, $ofValue->id);

                return;
            }

            $this->data->clearSettingAt($nodeId, $carrier->id, $innerId);

            return;
        }

        $value = $this->rendering->valuesFrom([$ofValue], [$ofValue->id => $characters])[$ofValue->id] ?? null;

        if ($value === null || $value->isNothing()) {
            return;
        }

        if ($partId !== 0) {
            $this->data->put($partId, $ofValue->id, $value);

            return;
        }

        $this->data->putSettingAt($nodeId, $carrier->id, $innerId, $value);
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
    private function saveSettings(int $nodeId, int $relationId, string $name = ''): void
    {
        // ⚠️ **The page save writes the name too, and forgetting that was a regression I shipped.**
        // [D-392](../../../docs/NewConcept/90-decision-log.md) put the name field inside this form and
        // dropped `Rename`; this method only ever read `taxmod_setting[…]`, so **the name arrived and
        // was thrown away**. The owner found it in one try: *changing and saving a node name does not
        // work at the moment — name not in the form?* **It was in the form; nothing read it.**
        //
        // ⚠️ *Only for a node. An **relation**'s name is `save_attribute`'s business, and only where the
        // attribute is declared ([D-376](../../../docs/NewConcept/90-decision-log.md)) — renaming an
        // inherited one from a descendant would rename it for everybody, silently.*
        if ($relationId === 0 && $name !== '') {
            $node = $this->editor->find($nodeId);

            // ⚠️ *Only when it actually differs.* A page save posts the name every time, and renaming
            // a node to what it already is would write a changelog entry per save — «renamed» twenty
            // times with nothing renamed.
            if ($node !== null && $node->name !== $name) {
                $this->editor->rename($nodeId, $name);
            }
        }

        // ⚠️ **Hier stand der Schreiber der Renderer-Wahl über den Schlüssel** (TASK-052, TASK-057).
        // *Er gehörte zum eigenen Block, und beide sind mit [D-644](../../../docs/NewConcept/90-decision-log.md)
        // gefallen. **Geschrieben wird jetzt über die Kante**, wie bei jeder anderen Einstellung —
        // {@see self::saveSettingValues()} schlüsselt über `fieldsOf()`, und die Einstellungskante
        // `renderer` ist eine davon.*
        //
        // ⚠️ **Hier stand der Schreiber der `settings`-Tabelle**, der jeden geaenderten Wert der
        // Tafel in eine Zeile schrieb. *Die Tabelle ist mit [D-579](../../../docs/NewConcept/90-decision-log.md)
        // gestrichen. **Der Schreiber war schon vorher wirkungslos**: seit
        // [D-543](../../../docs/NewConcept/90-decision-log.md) liest {@see \Taxmod\Core\Service\ModelValues}
        // aus Datensaetzen und **gewinnt** — der Eigentuemer hat es an der Oberflaeche gesehen («den
        // Render kann ich noch nicht setzen»), und `setting-write-check.php` bewacht seither, dass
        // geschrieben wird, wo gelesen wird. **Was hier fiel, war ein Schreiber ohne Leser.***
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
     * Ob die Wurzel selbst als Zeile im Baum steht.
     *
     * ⚠️ **Der Eigentümer braucht sie, um ihr Felder zu geben** — und sie war bisher nicht
     * erreichbar: `Tree::collect()` gibt nur Kinder aus, also stand sie in keiner Zeile und
     * kein Link führte hin. *Fachlich ging ein Feld an ihr schon immer; `addField()` prüft nur
     * das **Ziel**, nie den Besitzer.*
     *
     * ⚠️ **Und es ist kein neuer Gedanke:** *der Vorgänger hatte den Schalter, mit Vorgabe
     * «verborgen», und [D-273](../../../docs/NewConcept/90-decision-log.md) nennt ihn
     * ausdrücklich «already the right answer». **Gefehlt hat er, nicht die Entscheidung.***
     *
     * ⚠️ *Ein Ansichts-Schalter und kein gespeicherter Vorzug — sein Wort: «wenn ich dem
     * Wurzelknoten Felder geben möchte, muss ich ihn **kurzzeitig** sehen können.» Damit gehört
     * er neben {@see self::showsHidden()} und nicht auf die Einstellungsseite.*
     */
    private function showsRoot(): bool
    {
        return isset($_GET['taxmod_root']) && $_GET['taxmod_root'] === '1';
    }

    /** Der Umschalter dafür, gebaut wie {@see self::hiddenToggle()}. */
    private function rootToggle(bool $showing): string
    {
        $to = $this->backTo(
            $this->selectedFromRequest()?->id,
            ['taxmod_root' => $showing ? null : '1']
        );

        return '<a class="taxmod-show-hidden" href="' . esc_url($to) . '">'
            . IconMarkup::dashicon($showing ? 'admin-home' : 'admin-home') . ' '
            . esc_html($showing ? __('hiding the root again', 'taxmod') : __('show the root', 'taxmod'))
            . '</a>';
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
     * ⚠️ *They are confusable because the chain **walks the inheritance relations**: the same ancestors,
     * two different questions. `hide` moves down the **chain**, so a child of a hidden node resolves to
     * hidden and nothing here has to walk or remember. A filter tracking ancestors itself would be a
     * second implementation of **the chain**.*
     *
     * ⚠️ **It no longer says «once hidden, never revealed further down».** That was
     * [D-312](../../../docs/NewConcept/90-decision-log.md)'s narrowing rule, and
     * [D-399](../../../docs/NewConcept/90-decision-log.md) took `hide` out of it —
     * [D-411](../../../docs/NewConcept/90-decision-log.md) then ended the rule altogether. *A descendant
     * may reveal what an ancestor hid, which is what the eye in the row depends on.*
     *
     * ⚠️ **One query for the whole tree** (`CD-7`): every row's chain is resolved in one batch, not
     * one per row.
     *
     * @param  list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     * @return array<int, bool>                                                                          Keyed by node id.
     */
    private function hiddenAmong(array $rows): array
    {
        $hidden = [];

        // ⚠️ **Off the row, which carries it since [D-467](../../../docs/NewConcept/90-decision-log.md).**
        // *`hide` lives on the **inheritance relation**, and `Tree::rowsUnder()` loads those relations anyway —
        // so the walk answers it and this method only rearranges. **What stood here before was a query**
        // resolving every visible node's settings, and its only consumer was this one flag.*
        //
        // ⚠️ *True only while «show hidden» is on: with it off a hidden row is not in `$rows` at all,
        // because the walk did not follow its relation.*
        foreach ($rows as $row) {
            $hidden[$row['node']->id] = $row['hidden'];
        }

        return $hidden;
    }

    /**
     * Turn `hide` on or off for one node, and leave it selected.
     *
     * ⚠️ **Written at the node, which is what makes it revocable** ([D-406](../../../docs/NewConcept/90-decision-log.md)).
     * Until that decision `hide` could only ever be turned **on** further down — so an eye in the row
     * would have been a one-way button, which is not a toggle. *The eye is only buildable because that
     * half of D-399 got built.*
     *
     * ⚠️ **Flipped against the resolved value, not against a stored one.** A node with no row of its
     * own inherits `false`, and a toggle that read the absent row as *unknown* would need a first click
     * that does nothing.
     */
    private function toggleHidden(int $nodeId): int
    {
        // ⚠️ **The eye writes the node's **inheritance relation**, not the node**
        // ([D-467](../../../docs/NewConcept/90-decision-log.md)). *That relation is what puts the node in
        // the tree ([D-014](../../../docs/NewConcept/90-decision-log.md)), so hiding it is hiding the
        // placement — which is what the owner meant: «I do not simply create a model node and then say
        // I will not draw it».*
        //
        // ⚠️ *The root has no inheritance relation and therefore cannot be hidden. **That is correct rather
        // than a gap**: it is machinery ([D-194](../../../docs/NewConcept/90-decision-log.md)), and the
        // editor answers with the id unchanged instead of failing.*
        $this->editor->hidePlacement($nodeId);

        return $nodeId;
    }

    // ⚠️ *`withoutHidden()` stood here and is gone into `Tree::rowsUnder()`
    // ([D-467](../../../docs/NewConcept/90-decision-log.md)). It filtered rows **after** the walk and
    // had to read every node's `path` to find a hidden ancestor. **The walk owns both facts**: it
    // loads the inheritance relations anyway, and not following one takes its subtree with it — so the
    // subtree disappears by construction rather than by a second pass.*

    /**
     * The switch that shows them anyway.
     *
     * ⚠️ **A link and not a form**, because it changes what this view shows and nothing else — the
     * same shape as folding a branch. *It carries the folded set and the selected node along, or
     * asking to see hidden nodes would silently unfold the tree and lose the page.*
     */
    private function hiddenToggle(bool $showing): string
    {
        // ⚠️ *One place assembles this address now ({@see backTo()}) — this method used to carry the
        // folded set itself, the row links carried it too, and the redirect after an act carried
        // neither. **Three careful copies that disagreed**, which is what the owner reported as «show
        // hidden flips when I press a button».*
        $to = $this->backTo(
            $this->selectedFromRequest()?->id,
            ['taxmod_hidden' => $showing ? null : '1']
        );

        return '<a class="taxmod-show-hidden" href="' . esc_url($to) . '">'
            . IconMarkup::dashicon($showing ? 'visibility' : 'hidden') . ' '
            . esc_html($showing ? __('hiding hidden nodes again', 'taxmod') : __('show hidden nodes', 'taxmod'))
            . '</a>';
    }

    private function hidden(int $id): string
    {
        return '<input type="hidden" name="action" value="' . self::ACTION . '">'
            . '<input type="hidden" name="id" value="' . (int) $id . '">'
            // ⚠️ **Von Hand und nicht über `wp_nonce_field()`, weil das eine **Id** aus dem Namen
            // macht.** *Eine Seite trägt mehrere dieser Formulare — gemessen vier —, also stand
            // `id="_taxmod_nonce"` viermal da. **Ungültiges HTML, und `form="…"` sowie
            // `getElementById` nehmen den ersten Treffer**: derselbe Fehler, an dem eine feste
            // Panel-Id schon einmal gescheitert ist ([D-381](../../../docs/NewConcept/90-decision-log.md)).
            // Gefunden von der Zusicherung, die `package7-check` beim Umbau bekam
            // ([D-520](../../../docs/NewConcept/90-decision-log.md)).*
            . '<input type="hidden" name="_taxmod_nonce" value="'
                . esc_attr(wp_create_nonce(self::ACTION . '_' . $id)) . '">'
            . '<input type="hidden" name="_wp_http_referer" value="'
                . esc_attr(wp_unslash($_SERVER['REQUEST_URI'] ?? '')) . '">'
            // ⚠️ **Der Zustand des Baums reist mit, sonst klappt jeder Akt ihn zu.** *Der Eigentümer:
            // «Clear von Trash sorgt immer dafür, dass der Tree collapsed». **Gemessen ist es nicht
            // «Clear»**: diese verborgenen Felder schickt **jedes** Aktionsformular, und der Zustand
            // fehlte in allen. {@see self::backTo()} sucht ihn in `$_POST` — und fand nichts, weil ihn
            // niemand hineinlegte. Ihm ist es am Papierkorb aufgefallen, weil man dort einen tief
            // geöffneten Baum vor sich hat.*
            . $this->circumstanceFields();
    }

    /** Dieselben Umstände wie {@see circumstances()}, nur als verborgene Felder statt als Paare. */
    private function circumstanceFields(): string
    {
        $felder = '';

        foreach ($this->circumstances() as $name => $wert) {
            $felder .= '<input type="hidden" name="' . esc_attr($name) . '" value="'
                . esc_attr((string) $wert) . '">';
        }

        return $felder;
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
        // ⚠️ *Its own name so the two choosers cannot share a radio group ({@see fieldForm()}).*
        $pointsAt     = isset($_POST['field_target']) ? absint($_POST['field_target']) : 0;
        // ⚠️ **Die Kantenart kommt vom Benutzer** ([D-618](../../../docs/NewConcept/90-decision-log.md),
        // TASK-053). *`tryFrom()` und nicht `from()`: was nicht eine der drei Arten ist, ist keine
        // Angabe — dann rät der Rand nicht, sondern reicht `null` durch, und der Kern nimmt seinen
        // benannten Rückfall. **Ein Ausnahmefehler wäre hier falsch**, weil ein alter Reiter ohne das
        // Feld sonst mit «Unknown action» abbräche, statt zu tun, was er bisher tat.*
        $relationKind = RelationKind::tryFrom(
            isset($_POST['relation_kind']) ? sanitize_key(wp_unslash((string) $_POST['relation_kind'])) : ''
        );
        // ⚠️ *Die Satzart, gelesen wie jede andere Eingabe (`CD-5`) — siehe `add_record` unten.*
        $recordType   = RecordType::fromStorage(
            isset($_POST['record_type']) ? sanitize_key(wp_unslash((string) $_POST['record_type'])) : ''
        );
        $relation         = isset($_POST['relation']) ? absint($_POST['relation']) : 0;
        $settingKey   = isset($_POST['setting_key']) ? sanitize_text_field(wp_unslash($_POST['setting_key'])) : '';
        // Each setting is edited where it sits, under `taxmod_setting[<key>]`.
        $settingValue = isset($_POST[self::SETTING_FIELD][$settingKey])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::SETTING_FIELD][$settingKey]))
            : '';
        // ⚠️ *`$attributeName` stand hier für **eine** Kante, weil die Diskette der Zeile nur ihre
        // eigene abschickte. Das Seitenformular bringt alle mit, also liest
        // {@see self::saveFieldRows()} sie dort — je Kante ihren eigenen Namen.*
        // ⚠️ *Leer heisst «die Standardsprache» und nicht «keine Sprache» — die neutrale Zeile ist mit
        // [D-645](../../../docs/NewConcept/90-decision-log.md) gefallen.*
        $labelLocale  = isset($_POST['label_locale']) ? sanitize_text_field(wp_unslash($_POST['label_locale'])) : '';
        $labelLocale  = $labelLocale === '' ? self::neutralLocale() : $labelLocale;
        // ⚠️ *`$rendererName` stand hier und ist mit seinem Wähler gegangen: der Renderer kommt jetzt
        // als **Wert** in der Spalte des Einstellungsblocks, unter `taxmod_value`, wie jede andere
        // Angabe des Modells.*
        $stay   = $id;

        // ⚠️ **One act, one change number** ([list row 45](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
        // The owner: *whatever was changed in one change — relation, node, setting — if they were changed
        // together they should have one change number.* **This `match` is where an act begins**: one
        // POST is one thing a person did, and everything it writes belongs together.
        //
        // ⚠️ *Measured before the bracket: a node renamed, its relation reordered and its setting written
        // in one act produced **three** groups. `change_group_id` existed, was decided
        // ([D-348](../../../docs/NewConcept/90-decision-log.md)) and grouped nothing — 1609 of 1945
        // groups held a single row.*
        //
        // ⚠️ **In `finally`, so a refusal still closes it.** *An act that throws has written whatever
        // it wrote before throwing, and leaving the bracket open would put the **next** person's act
        // in the same group. A half-written act is a fact; a group that swallows the following one is
        // a lie.*
        $this->changelog->beginAct();

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
                // ⚠️ *`$stay` keeps the node selected. Hiding it does not deselect it — with **show
                // hidden** off the row vanishes from the tree while its detail page stays open, and
                // that is the one arrangement in which the act can be undone by the same button.*
                'toggle_hide'    => $stay = $this->toggleHidden($id),
                'duplicate'      => $stay = $this->editor->duplicate($id)->id,
                'rename'         => $this->editor->rename($id, $name),
                'move'           => $this->editor->move($id, $target),
                // ⚠️ **Dieselbe Spalte, andere Geschwisterliste** ([D-435](../../../docs/NewConcept/90-decision-log.md)):
                // ein Knoten ordnet seine Vererbungskante, ein Attribut seine eigene.
                'field_up'   => $this->editor->moveField($id, $relation, -1),
                'field_down' => $this->editor->moveField($id, $relation, 1),
                'up'             => $this->editor->moveUp($id),
                'down'           => $this->editor->moveDown($id),
                'restore'        => $this->editor->restore($id),
                // ⚠️ **Der endgueltige Akt** ([Zeile 10](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)),
                // auf seine Bitte: *bau mal einen Button hinter Trash Label clear damit wir aufraeumen
                // koennen.* **Was bleibt, ist Absicht**: Identitaeten ([D-340](../../../docs/NewConcept/90-decision-log.md))
                // und Changelog ([D-065](../../../docs/NewConcept/90-decision-log.md)) ueberleben die Sache.
                'clear_trash'    => $this->clearedTrash(),
                'trash'          => $this->editor->moveToTrash($id),
                // ⚠️ **Der zweite Akt ist die Zusage aus dem Dialog** ([D-604](../../../docs/NewConcept/90-decision-log.md),
                // TASK-037). *Er ist ein **eigener** Akt und kein Häkchen an `trash`: was ein Benutzer
                // bestätigt hat, steht damit im Änderungsbuch unter eigenem Namen, und ein alter
                // Knopf, der nur `trash` kennt, kann die Verwendungen nicht versehentlich mitnehmen.*
                'trash_with_uses' => $this->editor->moveToTrash($id, true),
                'trash_node'     => $this->editor->moveToTrashPromotingChildren($id),
                // WICHTIG: Leerer Name heisst «nimm den des Zielknotens» (TASK-026). Entschieden
                // wird es hier und nicht im Kern: welcher Name gemeint ist, ist eine Frage der
                // Maske, und addField() soll weiter genau das anlegen, was man ihm sagt.
                'add_field'  => $this->editor->addField(
                    $id,
                    $pointsAt,
                    $name === '' ? ($this->editor->find($pointsAt)?->name ?? '') : $name,
                    // WICHTIG: Die Art kommt aus der Zeile und nicht aus dem Ast -- TASK-053,
                    // D-618: "der benutzer legt fest".
                    $relationKind
                ),
                // Parked, not purged — D-123's two stages, so it can come back.
                // WICHTIG: Den Typ eines eigenen Feldes aendern -- TASK-029. Sein Regel fuer die
                // Daten: "sollten keine Daten da sein einfach aendern, wenn Daten da sind neue
                // Version und Konflikt". Die Kante wird ueber ihre Version gespeichert, also
                // meldet ein gleichzeitiger Umbau sich als Konflikt statt still zu gewinnen.
                'retarget_field' => $this->editor->retargetField($id, $relation, $this->retargetTo($relation)),
                'remove_field'  => $this->editor->removeField($id, $relation),
                'restore_field' => $this->editor->restoreField($id, $relation),
                // ⚠️ **Renamed only where it is declared** (D-376) — the act refuses it otherwise,
                // because an inherited attribute belongs to the ancestor and renaming it from a
                // descendant would rename it for every other user, silently.
                'toggle_field_hide' => $this->editor->hideField($id, $relation),
                // ⚠️ *`save_field` stand hier und ist mit der Diskette der Zeile gegangen — Name und «wie
                // oft» kommen jetzt mit dem Seitenformular ({@see self::saveFieldRows()}).*
                // ⚠️ **Eine Zeile mehr** — *auf sein Bestehen, dass `Display Option` `1..*` ist: «somit
                // muss ich Zeilen hinzufügen können». Ein Teil ist eine Zeile
                // ([D-546](../../../docs/NewConcept/90-decision-log.md)), und mehrere Teile sind mehrere
                // Renderer, für das Farbschema ([D-548](../../../docs/NewConcept/90-decision-log.md)).*
                'add_part'      => $this->addPart($id, $relation),
                // ⚠️ **The «(copy)» comes from here, not from the core.** [D-281] refuses an relation
                // with the same name, and inventing a suffix is writing user-visible text — which
                // goes through the text domain at the boundary (`AR-2`) and never in `Taxmod\Core`.
                'duplicate_field' => $this->editor->duplicateField(
                    $id,
                    $relation,
                    sprintf(
                        /* translators: %s: the name of the attribute being copied. */
                        __('%s (copy)', 'taxmod'),
                        $this->editor->ownAttribute($id, $relation)->name
                    )
                )->id,
                // ⚠️ **`$relation` decides the owner** (D-381): the same three acts serve a node and a use site,
                // and a write meant for one attribute must not land on the type it points at.
                // ⚠️ **The whole panel at once** (D-392): the button sits in the page head and the
                // panel is one form, so there is no single key to write — every changed value is.
                // ⚠️ **And the labels come with it**, on the owner's word — *labels should be saved
                // with the page too* ([D-488](../../../docs/NewConcept/90-decision-log.md)).
                //
                // ⚠️ **Ein Name, nicht zwei.** *Es standen kurz `put_setting` und `put_labels` hier, weil
                // es zwei Knöpfe gab. Der eigene Knopf des Labels-Bereichs ist weg — der Eigentümer, auf
                // die Frage «soll er weg»: «zu 1: ja» — und damit schickte **niemand** mehr `put_labels`.
                // **Ein Akt, den kein Knopf abschickt, ist toter Code**, und `CLAUDE.md` verbietet ihn
                // ausdrücklich. Er ist mit seiner Konstante `LabelsRenderer::WRITE` verschwunden.*
                'put_setting'    => $this->saveNodePage($id, $relation, $name, $labelLocale),
                // ⚠️ Checked against what **exists**, not against what is eligible (D-360): the
                // eligible set is what the screen offers, and an unusual choice is a special case
                // rather than an error. A name no renderer answers to is the error.
                // ⚠️ **Geschrieben, wo es gelesen wird** ([D-543](../../../docs/NewConcept/90-decision-log.md)).
                // *Hier stand `settings->put()` — die alte Tabelle. Und {@see \Taxmod\Core\Service\ModelValues}
                // liest aus Datensätzen und **gewinnt**, also blieb jede Wahl an den 26 Knoten, die
                // ihren Renderer im Datensatz tragen, **spurlos**. Der Eigentümer hat es gesehen: «den
                // Render kann ich noch nicht setzen» — und es war kein fehlender Renderer, sondern ein
                // Schreiber an der alten Stelle.*
                // ⚠️ *Hier standen die Zeilen-Akte `empty_setting` und `reset_setting`. Ihre Knöpfe
                // sind mit der Tafel gegangen ([D-520](../../../docs/NewConcept/90-decision-log.md)) —
                // `package7-check.php` misst, dass kein `do[<key>]` mehr auf der Seite steht — und ihr
                // Ziel, die `settings`-Tabelle, mit [D-579](../../../docs/NewConcept/90-decision-log.md).*
                // ⚠️ *An die Kante, seit [D-528](../../../docs/NewConcept/90-decision-log.md). Ein
                // unbekannter Wert wird verworfen und nicht geraten — die vier Konstanten sind die
                // ganze Liste ([D-351](../../../docs/NewConcept/90-decision-log.md)).*
                'put_multiplicity' => $this->editor->setMultiplicity(
                    $id,
                    $relation,
                    Multiplicity::tryFrom($settingValue)
                        ?? throw new \InvalidArgumentException('Keine solche Multiplizitaet.')
                ),
                // ⚠️ **Die Art kommt vom Benutzer** ([D-651](../../../docs/NewConcept/90-decision-log.md),
                // [D-653](../../../docs/NewConcept/90-decision-log.md)). *`fromStorage()` und nicht
                // `from()`: was keine der drei Arten ist, ist keine Angabe — dann raet der Rand nicht,
                // sondern nimmt den benannten Rueckfall des Kerns. **Ein Ausnahmefehler waere hier
                // falsch**, weil ein alter Reiter ohne das Feld sonst mit «Unknown action» abbraeche,
                // statt zu tun, was er bisher tat. Dieselbe Form wie bei `relation_kind`.*
                'add_record'     => $this->data->create($id, $recordType),
                'save_record'    => $this->saveRecord($id),
                'delete_record'  => $this->data->removeRecord(
                    isset($_POST['node_record_id']) ? absint($_POST['node_record_id']) : 0
                ),
                default          => throw new \InvalidArgumentException('Unknown action.'),
            };

            // ⚠️ A restore that leaves children behind must say so — it is the one outcome
            // where *done* would be a lie (D-347).
            // ⚠️ **An act that returns a sentence of its own keeps it.** *Clearing the trash is the one
            // act on this screen that cannot be undone, and a silent «Done.» would leave a person
            // guessing whether it ran — the answer then sits in the tree, which is exactly too late.*
            $message = 'ok';

            if (is_string($outcome) && $outcome !== '') {
                $message = $outcome;
            } elseif ($outcome instanceof RestoreResult && ! $outcome->everythingCameBack()) {
                $message = sprintf(
                    /* translators: %s is a comma-separated list of node names. */
                    __('Restored — but these were left where they are, because they were moved since: %s', 'taxmod'),
                    implode(', ', $outcome->leftBehind)
                );
            }
        } catch (DomainError $error) {
            // Exceptions inside the core, translated at the boundary (`CD-10`). The message is
            // the domain's own words, so it survives the redirect rather than being replaced by
            // a generic failure the person cannot act on.
            $message = $error->getMessage();
        } catch (\InvalidArgumentException) {
            $message = __('Unknown action.', 'taxmod');
        } finally {
            $this->changelog->endAct();
        }

        // Everything now happens **at** a node, so the person stays there rather than being
        // sent back to a screen with nothing selected.
        wp_safe_redirect($this->backTo($stay, ['taxmod_message' => rawurlencode($message)]));
        exit;
    }

    /**
     * The address a person should land on after an act — **with every circumstance still on it**.
     *
     * ```mermaid
     * flowchart LR
     *   A["an act"] --> B["backTo()"]
     *   B --> N["the node"] & C["the folded branches"] & H["show hidden"]
     * ```
     *
     * ⚠️ **This exists because a circumstance was dropped three times in a row.** The owner reported
     * the third: *when I press delete, «show hidden nodes» flips.* It did — and so did the folded set,
     * which nobody had noticed: the redirect carried only `page`, the message and the node, while the
     * **links** in the tree carried `taxmod_collapsed` and the hidden toggle carried both. *Three
     * places assembling the same URL, each remembering a different subset.*
     *
     * ⚠️ **So there is one place now, and adding a fourth circumstance means changing one line.** That
     * is the whole reason it is a method rather than three careful copies — the copies were careful and
     * still disagreed.
     *
     * ⚠️ *A circumstance is not model state ([D-389](../../../docs/NewConcept/90-decision-log.md),
     * [D-396](../../../docs/NewConcept/90-decision-log.md)) — it is who is looking, right now. It rides
     * in the query string precisely so that it is **not** stored, and the price of that is having to
     * carry it, which is what this pays.*
     *
     * @param array<string, string> $extra
     */
    /**
     * One circumstance, from wherever this request happens to be carrying it.
     *
     * ⚠️ **A page load has it in the query string, an act has it in the posted form.** Those are the
     * only two ways it can arrive, and a reader that knows one of them works exactly half the time —
     * which is how «show hidden» kept disappearing after the first fix.
     *
     * ⚠️ *Never `$_REQUEST`: it folds cookies in too, and a circumstance that could come from a cookie
     * would be one that outlives the visit it belongs to.*
     */
    private function circumstance(string $key): ?string
    {
        $raw = $_POST[$key] ?? $_GET[$key] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        return sanitize_text_field(wp_unslash((string) $raw));
    }

    /**
     * Alle Umstände dieser Seite, wie ein Formular sie mitschicken muss.
     *
     * ⚠️ **Eine Stelle, weil es vorher fünf waren und sie sich unterschieden.** *{@see hidden()}
     * legte den Faltzustand samt Rückfall hinein, {@see submissionFor()} las nur `$_GET` — und drei
     * von Hand gebaute {@see Submission} legten gar nichts hinein. **Gemessen an der Seite von
     * `Adresse`: 10 von 17 Formularen trugen den Faltzustand nicht**, und jeder Akt aus einem von
     * ihnen liess die Seite auf «alles zu» zurückfallen.*
     *
     * ⚠️ **Der Rückfall auf den gemerkten Zustand ist der Kern** ({@see $foldStateForLinks}): eine
     * frische Seite bringt keinen Parameter mit, hat aber eine berechnete Vorgabe. Wer nur `$_GET`
     * liest, schickt dort nichts — und «nichts» heisst beim nächsten Aufruf «alles zu»
     * ([D-480](../../../docs/NewConcept/90-decision-log.md), [D-615](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @return array<string, string|null> leere Werte fallen im `array_filter` des Aufrufers heraus
     */
    private function circumstances(): array
    {
        return [
            'taxmod_collapsed' => $this->circumstance('taxmod_collapsed') ?? $this->foldStateForLinks,
            'taxmod_hidden'    => $this->circumstance('taxmod_hidden'),
            self::OPENED_FOR   => $this->openedPathForLinks,
        ];
    }

    private function backTo(?int $nodeId, array $extra = []): string
    {
        return add_query_arg(
            array_filter([
                'page'        => 'taxmod',
                'taxmod_node' => $nodeId,
                // ⚠️ **From the query string *or* the posted form**, and it has to be both. A page
                // load carries them in the URL; an **act** is a POST to `admin-post.php`, which has no
                // query string — so the form carries them instead ({@see submissionFor()}). *Reading
                // only `$_GET` was my first attempt at this and it fixed nothing, because by the time
                // the redirect is built there is no `$_GET` left.*
                // ⚠️ **Der gemerkte Zustand als Rückfall, und das ist das «Fortschreiben».** *Eine
                // frische Seite bringt keinen Parameter mit, hat aber eine berechnete Vorgabe — die
                // muss in die Links, sonst rechnet der nächste Klick neu und klappt zu, was gerade
                // aufgeklappt wurde. {@see $foldStateForLinks}*
                'taxmod_collapsed' => $this->circumstance('taxmod_collapsed') ?? $this->foldStateForLinks,
                'taxmod_hidden'    => $this->circumstance('taxmod_hidden'),
                // ⚠️ **Nur, wenn das Ziel dasselbe ist.** *Der Merker sagt «für diesen Knoten ist der
                // Weg schon offen» — führt der Link auf einen **anderen** Knoten, ist das nicht mehr
                // wahr, und ihn trotzdem mitzunehmen hiesse, dessen Weg zugeklappt zu lassen.*
                //
                // ⚠️ *Auf dem POST-Weg lief {@see render()} nicht — dort bringt ihn das Formular mit
                // ({@see submissionFor()}), genauso wie den Faltzustand.*
                self::OPENED_FOR   => $nodeId !== null
                    && (string) $nodeId === ($this->openedPathForLinks ?? $this->circumstance(self::OPENED_FOR))
                        ? (string) $nodeId
                        : null,
                // ⚠️ **`$extra` comes last so a caller can override a circumstance rather than only add
                // to it.** The hidden toggle is the caller that needs it: passing `null` for
                // `taxmod_hidden` replaces the ambient value and `array_filter` then drops the key —
                // which is what *stop showing hidden nodes* has to mean in a URL.
                ...$extra,
            ]),
            admin_url('admin.php')
        );
    }

    /**
     * The message after an act — **and it does not push the page down**.
     *
     * ⚠️ **The owner found the real cause of the jump here, after three attempts at the tree:** *I see
     * now why the whole page jumps — because of the message output at the top. Can that be prevented and
     * the messages still kept?* **Yes**, and it is the better diagnosis: the tree was being restored
     * correctly and then everything below a newly inserted notice moved down anyway.
     *
     * ⚠️ **So the notice leaves the flow.** `position: fixed` means it overlays instead of displacing —
     * the page after an act is laid out exactly as the page before it, which is what makes a restored
     * scroll offset land where it was measured.
     *
     * ⚠️ **Its own class rather than WordPress's `notice`, and that is deliberate.** `wp-admin` moves
     * every `.notice` to just under the `h1` with script of its own, which would put it back into the
     * flow and undo this. *Keeping the colours means writing them; keeping the position means not being
     * called `notice`.*
     *
     * ⚠️ *It fades on its own after a few seconds, because a fixed overlay that stays would sit on top
     * of the tree until the next reload. The animation is CSS, so nothing here has to know about time.*
     */
    private function notice(): string
    {
        if (! isset($_GET['taxmod_message'])) {
            return '';
        }

        $message = sanitize_text_field(wp_unslash($_GET['taxmod_message']));
        $ok      = $message === 'ok';

        return '<div class="taxmod-toast' . ($ok ? ' taxmod-toast-ok' : ' taxmod-toast-bad') . '" role="status">'
            . esc_html($ok ? __('Done.', 'taxmod') : $message)
            . '</div>';
    }
}
