<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Renderer\ResidueRenderer;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\Core\Service\Tree;
use Taxmod\WordPress\Admin\CleanupScreen;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\BaseScaffold;
use Taxmod\WordPress\Persistence\CompositionScaffold;
use Taxmod\WordPress\Persistence\RenderingScaffold;
use Taxmod\WordPress\Persistence\Residue;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\UnitScaffold;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;

/**
 * The boundary. It wires WordPress to the core and decides nothing (D-170).
 *
 * ```mermaid
 * flowchart LR
 *   H["hooks · admin screens"] -->|call inward| C["Taxmod\\Core"]
 *   C -->|declares what it needs| I["repository interfaces"]
 *   B["this package fulfils them"] --> I
 * ```
 *
 * Every arrow points inward: WordPress is not underneath the core but around it, which is what
 * lets a second boundary be placed beside this one later without the core noticing.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class Plugin
{
    public const VERSION     = '0.0.1';
    public const TEXT_DOMAIN = 'taxmod';

    /** What a person must be able to do before they may shape the model. */
    public const CAPABILITY = 'manage_options';

    private function __construct(private readonly string $file)
    {
    }

    public static function boot(string $file): void
    {
        $plugin = new self($file);

        register_activation_hook($file, $plugin->activate(...));

        add_action('admin_menu', $plugin->registerMenu(...));
        add_action('admin_post_taxmod_node', $plugin->handleNodeAction(...));

        // ⚠️ **The installation's own screen** (D-397) — three decisions had been deferred to it and
        // each had its own interim: developer mode, the neutral locale, the tree's scale.
        add_action('admin_post_' . SettingsScreen::ACTION, $plugin->handleSettings(...));

        // ⚠️ **The repair surface** (D-247, U24) — decided on 2026-08-23 and never built, while the
        // three sources it names went on collecting.
        add_action('admin_post_' . CleanupScreen::ACTION, $plugin->handleCleanup(...));

        // An upgrade must not depend on somebody remembering to deactivate and activate
        // again (`CD-6`). One option read per admin request, and the work happens only when
        // the stored version is behind.
        add_action('admin_init', $plugin->ensureUpToDate(...));
    }

    /**
     * Bring an installed copy up to date without anybody remembering to deactivate first.
     *
     * ⚠️ **Seeding runs when the schema version moved**, not on every request: a new version
     * may have brought new framework nodes — the branches did — and an installation that only
     * upgraded would otherwise never get them.
     */
    public function ensureUpToDate(): void
    {
        $before = (int) get_option(Schema::VERSION_OPTION, 0);

        Schema::ensureCurrent();

        if ($before !== Schema::VERSION) {
            $this->frameworkNodes()->seed();
        }

        // Its own version, because the scaffold is content and the schema is machinery — they
        // move for different reasons and must not drag each other along.
        $this->baseScaffold()->importOnce();
        $this->unitScaffold()->importOnce();
        $this->compositionScaffold()->importOnce();
        $this->renderingScaffold()->importOnce();
    }

    public function activate(): void
    {
        Schema::install();
        update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

        $this->frameworkNodes()->seed();
        $this->baseScaffold()->importOnce();
        $this->unitScaffold()->importOnce();
        $this->compositionScaffold()->importOnce();
        $this->renderingScaffold()->importOnce();
    }

    public function registerMenu(): void
    {
        $hook = add_menu_page(
            __('Taxonomy Modeller', 'taxmod'),
            __('Taxonomy Modeller', 'taxmod'),
            self::CAPABILITY,
            'taxmod',
            fn () => print $this->screen()->render(),
            'dashicons-networking',
            30
        );

        // ⚠️ **A sub-page and not a second top-level menu.** It configures the modeller, so it
        // belongs under it — and it is the screen [OQ-039](../../../docs/NewConcept/91-open-questions.md)
        // has been waiting for since Package 4.
        add_submenu_page(
            'taxmod',
            __('Installation', 'taxmod'),
            __('Installation', 'taxmod'),
            self::CAPABILITY,
            'taxmod-settings',
            fn () => print (new SettingsScreen())->render()
        );

        // ⚠️ **`Cleanup` at last** ([D-247](../../docs/NewConcept/90-decision-log.md), decided
        // 2026-08-23): *«nodes that have no connections any more, or settings that broke because
        // something was deleted»*. It is **not a feature but a repair surface**
        // ([U24](../../docs/NewConcept/20-interaction.md)), which is why it is a page of its own and
        // not a button on the modelling screen — nothing here happens in passing.
        add_submenu_page(
            'taxmod',
            __('Cleanup', 'taxmod'),
            __('Cleanup', 'taxmod'),
            self::CAPABILITY,
            CleanupScreen::PAGE,
            fn () => print $this->cleanupScreen()->render()
        );

        // ⚠️ **On this screen's own hook**, so the stylesheet is not loaded onto every page in
        // `wp-admin`. `add_menu_page()` returns the hook, which is why the wiring lives here rather
        // than beside the other hooks in `boot()`.
        if ($hook !== false) {
            add_action('admin_print_styles-' . $hook, $this->enqueueStyle(...));
        }
    }

    /**
     * The modelling screen's stylesheet — a real file, and only on that screen.
     *
     * ⚠️ **It used to be a `<style>` block inside the screen class**, and the owner asked the question
     * that settles it: *do you put that in a taxmod css or do you hard-code it?* Hard-coded, and fine
     * while it was three lines for the tree; at forty it was a stylesheet living in a PHP string —
     * every quote escaped, no editor help, re-sent on every page load rather than cached.
     *
     * ⚠️ **Hooked on the screen's own hook rather than on `admin_enqueue_scripts` broadly**, so it is
     * not loaded onto every page in `wp-admin`. *`add_menu_page()` returns that hook, which is why the
     * enqueue is wired here and not beside the other hooks in `boot()`.*
     *
     * ⚠️ **Versioned with the plugin** so a released change actually reaches a browser that has the
     * old file cached. **And `dashicons` is declared a dependency**, because the icon chooser draws
     * glyphs as *characters* in a `<select>` and needs the font present — on an admin page WordPress
     * loads it anyway, and depending on that would be depending on an accident.
     */
    public function enqueueStyle(): void
    {
        // ⚠️ **The URL is built from the *plugin folder*, not from `$this->file`, and that was a real
        // bug that shipped for an hour.** This repository is developed in `…/source/wp-taxonomy-tree`
        // and reaches `wp-content/plugins` through a **junction**, which is not a symlink — so
        // `plugins_url()` cannot relate the real path to the plugins directory and simply concatenates
        // it, producing
        // `…/wp-content/plugins/C:/Devel/Wordpress/source/wp-taxonomy-tree/assets/admin.css`.
        //
        // ⚠️ *The stylesheet therefore never loaded, and I told the owner to reload — twice — because
        // my own check had built a path by hand and so measured the wrong thing. He said the screen
        // still looked wrong and he was right. **A check that constructs what it is verifying verifies
        // nothing.***
        //
        // ⚠️ **The folder name is the one fact that holds on both sides**, because WordPress loads the
        // plugin as `<folder>/<file>` and the junction keeps the folder name — so this asks
        // `plugins_url()` about a path it can actually relate.
        wp_enqueue_style(
            'taxmod-admin',
            plugins_url('assets/admin.css', WP_PLUGIN_DIR . '/' . basename(dirname($this->file)) . '/' . basename($this->file)),
            // ⚠️ **`buttons` is a dependency because this stylesheet overrides it.** `.taxmod-icon-button`
            // and WordPress's `.button` are both a single class, so they have **the same specificity**
            // and the later one wins — and without declaring the dependency, «later» was not ours to
            // decide. *The owner reported the boxes around the icons twice; my first two explanations
            // were about the wrong element entirely.*
            ['dashicons', 'buttons'],
            // ⚠️ **The file's own change time, not the plugin version, while this is being built.**
            // The owner reloaded three times on a stylesheet that was already correct because the
            // browser held the previous one — `VERSION` moves once per release, and during a session
            // like today it moves never. *`filemtime` makes every save a new URL, which is what a
            // person clicking Reload expects. It becomes `self::VERSION` when the screen settles.*
            (string) (@filemtime($this->path('assets/admin.css')) ?: self::VERSION)
        );

        $this->enqueueScript();
    }

    /**
     * The one script this screen has, and the reason it is one line of work and one decision.
     *
     * ⚠️ **This screen was scriptless on purpose** — `form="…"` submits a panel from outside it, a
     * nameless checkbox opens the chooser dialog, `<details>` holds the fold state. The owner asked
     * three times for the tree to stay where it was, and the scriptless answer — a `#fragment` — can
     * only **place** the selected row, never **preserve** the offset. *Told that plainly, he chose the
     * script* ([D-408](../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **In the footer, and that is not a detail.** The tree has to exist before the offset can be
     * written to it, and `in_footer` is the version of that which needs no readiness handshake beyond
     * the one the script already makes.
     *
     * ⚠️ *Same junction-safe URL as the stylesheet, for the same reason: `plugins_url()` cannot relate
     * this repository's real path to the plugins directory, so the **folder name** is the one fact that
     * holds on both sides.*
     */
    public function enqueueScript(): void
    {
        wp_enqueue_script(
            'taxmod-admin',
            plugins_url('assets/admin.js', WP_PLUGIN_DIR . '/' . basename(dirname($this->file)) . '/' . basename($this->file)),
            [],
            (string) (@filemtime($this->path('assets/admin.js')) ?: self::VERSION),
            true
        );
    }

    /** A path inside the plugin folder, from the file `boot()` was given. */
    private function path(string $inside): string
    {
        return dirname($this->file) . '/' . $inside;
    }

    public function handleSettings(): void
    {
        (new SettingsScreen())->handlePost();
    }

    public function handleNodeAction(): void
    {
        $this->screen()->handlePost();
    }

    public function handleCleanup(): void
    {
        $this->cleanupScreen()->handlePost();
    }

    /**
     * The repair surface, wired.
     *
     * ⚠️ **Public for the same reason {@see self::screen()} is**: a boundary check has to be able to
     * render it. *A screen nothing renders in a check is a screen a fatal error reaches before anybody
     * else does, which is what happened on 2026-08-25.*
     *
     * ⚠️ *`Residue` gets **the** changelog and not a new one — the bracket in
     * {@see CleanupScreen::handlePost()} and the rows `Residue` writes have to be the same act
     * ([D-470](../../docs/NewConcept/90-decision-log.md)).*
     */
    public function cleanupScreen(): CleanupScreen
    {
        return new CleanupScreen(
            new Residue(
                $this->frameworkNodes(),
                new WpdbSettingRepository(),
                new WpdbLabelRepository(),
                $this->changelog(),
                // ⚠️ *Die vierte Quelle misst Datensätze, deren Knoten fort ist, und entfernt sie
                // über dieselbe Methode wie `clearTrash()` — nicht über ein eigenes `DELETE`.*
                new WpdbRecordRepository()
            ),
            new ResidueRenderer(),
            $this->changelog()
        );
    }

    public function editor(): ModelEditor
    {
        return new ModelEditor(
            new WpdbNodeRepository(),
            new WpdbRelationRepository(),
            new TableIdentityAllocator(),
            $this->frameworkNodes(),
            $this->changelog(),
            // ⚠️ **Only `duplicate()` reads these** — a copy has to resolve exactly like its
            // original, so its own settings and labels travel with it.
            new WpdbSettingRepository(),
            new WpdbLabelRepository(),
            // ⚠️ **The materialiser** ([D-423](../../../docs/NewConcept/90-decision-log.md)): a new
            // node gets its parent's settings written into it, and a new attribute its target's.
            // *Handed in rather than made required, because the core tests and the boundary checks
            // build this service to move nodes about and have nothing to furnish.*
            new Settings(new WpdbSettingRepository(), new WpdbNodeRepository(), $this->frameworkNodes(), $this->changelog()),
            // ⚠️ **Damit `clearTrash()` die Daten mitnimmt** — [C102](../../docs/NewConcept/10-domain-core.md):
            // *ein Record ohne seinen Knoten ist undenkbar.* Ohne dieses Argument überlebten die Records
            // ihren Knoten, während der Docblock der Methode behauptete, sie gingen mit.
            new WpdbRecordRepository()
        );
    }

    /**
     * Prefixes and base units, under `Constants`.
     *
     * ⚠️ **Its own version beside the data types', because they are different deliveries.** Raising
     * one must not re-enter the other; that is the same reason the scaffold's version is separate
     * from the schema's.
     */
    public function unitScaffold(): UnitScaffold
    {
        return new UnitScaffold(
            $this->editor(),
            $this->frameworkNodes(),
            new Settings(new WpdbSettingRepository(), new WpdbNodeRepository(), $this->frameworkNodes(), $this->changelog()),
            // ⚠️ *Mit Changelog und Knoten-Repository, damit eine Labelaenderung in der Geschichte
            // steht ([D-489]) und `owner_kind` nicht geraten wird.*
            new Labels(new WpdbLabelRepository(), $this->frameworkNodes(), $this->changelog(), new WpdbNodeRepository()),
            $this->typeNodes()
        );
    }

    /**
     * The composed types the concept names as its own test — an address, a dimension, a recipe.
     *
     * ⚠️ **After the unit scaffold, and that ordering is load-bearing.** `Dimension` and `Backrezept`
     * are built out of `Einheitenwert`, which the unit delivery owns; run the other way round and the
     * scaffold refuses rather than creating a second node of that name.
     */
    public function compositionScaffold(): CompositionScaffold
    {
        return new CompositionScaffold(
            $this->editor(),
            $this->frameworkNodes(),
            new Settings(new WpdbSettingRepository(), new WpdbNodeRepository(), $this->frameworkNodes(), $this->changelog()),
            $this->typeNodes()
        );
    }

    /**
     * Renderer, Konverter und Validatoren als Knoten unter `Constants` ([D-511](../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Nach den anderen Saaten, weil sie unter `Constants` hängen** — und `Constants` ist ein
     * Zweig, den `frameworkNodes()->seed()` legt. *Die Reihenfolge ist dieselbe Abhängigkeit wie
     * bei `compositionScaffold()` hinter `unitScaffold()`.*
     *
     * ⚠️ *Sie bekommt die **fertigen Registries** und keine eigene Namensliste: was der Code
     * kennt, ist genau das, was gesät wird.*
     */
    public function renderingScaffold(): RenderingScaffold
    {
        return new RenderingScaffold(
            $this->editor(),
            $this->frameworkNodes(),
            ShippedRenderers::registry(),
            ShippedConverters::registry()
        );
    }

    private function baseScaffold(): BaseScaffold
    {
        return new BaseScaffold(
            $this->editor(),
            $this->frameworkNodes(),
            // ⚠️ **The seed writes down which node each type became** ([D-510](../../docs/NewConcept/90-decision-log.md)),
            // and everything afterwards reads that id instead of a name.
            $this->typeNodes(),
            // ⚠️ **Handed in so the scaffold can say what a number type permits.** The owner:
            // *`range_min` and `range_max` on `int` should be int's min and max.* The bounds come
            // from the column it is stored in, because storage is what refuses.
            new Settings(new WpdbSettingRepository(), new WpdbNodeRepository(), $this->frameworkNodes(), $this->changelog())
        );
    }

    /**
     * ⚠️ **One per request, and the `??=` is the whole point.** *This was a factory that built a new
     * one on every call, and it is called **17 times** — so the store inside
     * {@see SeededFrameworkNodes} was memoising into up to seventeen separate empty caches. Measured
     * on one page: **13 single-node reads, 7 of them the four branch roots**, which is impossible with
     * one instance and inevitable with several.*
     *
     * ⚠️ *Safe for the reason that class already documents: a framework node cannot move
     * ([D-194](../../docs/NewConcept/90-decision-log.md)), and the one thing that creates them —
     * `seed()` — clears the caches itself. **Sharing the instance is what its own comment assumed all
     * along.***
     *
     * ⚠️ *The owner asked for exactly this and nothing more: «we should simply make sure objects are
     * not loaded twice» — not the whole-model store, which stays a thought model
     * ([D-455](../../docs/NewConcept/90-decision-log.md)).*
     */
    /**
     * The one changelog, shared by everything that writes to it.
     *
     * ⚠️ **This had to become one object before an act could have a number.** *`new WpdbChangelog(…)`
     * stood **seven** times in this file — the editor got one, each of the four `Settings` got one,
     * the framework seeder got one, the screen's settings got one. That cost nothing while a group id
     * was handed out per write, and it makes the bracket
     * ([list row 45](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)) impossible:
     * `beginAct()` on the screen's copy would say nothing to the editor's copy, and the act would
     * still fall into as many groups as it had writers.*
     *
     * ⚠️ *Same reason `frameworkNodes()` below is memoised, and the same shape. **A collaborator that
     * holds state has to be one collaborator** — which is a thing worth checking for the next one,
     * because seven copies of a stateless object were merely wasteful and seven copies of this one
     * were wrong.*
     */
    private ?WpdbChangelog $changelog = null;

    private function changelog(): WpdbChangelog
    {
        return $this->changelog ??= new WpdbChangelog(new SystemClock());
    }

    /**
     * The type bindings, one object for the same reason the framework nodes are one.
     *
     * ⚠️ *It caches the eleven option reads for the life of the request
     * ([D-510](../../docs/NewConcept/90-decision-log.md)), and the ancestor walk in
     * {@see Rendering} asks it once per level of every field on the page. **Several copies would
     * mean several empty caches**, which is exactly the fault `frameworkNodes()` was memoised for.*
     */
    private ?SeededTypeNodes $typeNodes = null;

    private function typeNodes(): SeededTypeNodes
    {
        return $this->typeNodes ??= new SeededTypeNodes(new WpdbNodeRepository(), $this->frameworkNodes());
    }

    private ?SeededFrameworkNodes $frameworkNodes = null;

    private function frameworkNodes(): SeededFrameworkNodes
    {
        return $this->frameworkNodes ??= new SeededFrameworkNodes(
            new WpdbNodeRepository(),
            new WpdbRelationRepository(),
            new TableIdentityAllocator(),
            $this->changelog(),
            // ⚠️ **Only `duplicate()` reads these** — a copy has to resolve exactly like its
            // original, so its own settings and labels travel with it.
            new WpdbSettingRepository(),
            new WpdbLabelRepository()
        );
    }

    /**
     * The modelling screen, wired.
     *
     * ⚠️ **Public so that a boundary check can render it.** A fatal error reached the screen on
     * 2026-08-25 — a control group handed in as a string where a list was expected — and **nothing
     * guarded it**: the boundary runs exercise services, and `render()` was the one thing with no
     * check at all. `PR-9` asks every package to add to the net, so it does.
     */
    public function screen(): NodesScreen
    {
        $settings = new Settings(new WpdbSettingRepository(), new WpdbNodeRepository(), $this->frameworkNodes(), $this->changelog());
        // ⚠️ *Dasselbe Exemplar des Changelogs wie ueberall ([D-470](../../docs/NewConcept/90-decision-log.md)),
        // damit eine Labelaenderung in derselben Aenderungsgruppe landet wie der Akt, der sie ausloeste.*
        $labels   = new Labels(new WpdbLabelRepository(), $this->frameworkNodes(), $this->changelog(), new WpdbNodeRepository());

        return new NodesScreen(
            $this->editor(),
            new Tree(new WpdbNodeRepository(), new WpdbRelationRepository()),
            $settings,
            $labels,
            new DataEntry(new WpdbRecordRepository(), new WpdbRelationRepository(), new WpdbNodeRepository(), $this->frameworkNodes(), new SystemClock()),
            $this->frameworkNodes(),
            // ⚠️ **The renderers are wired in one place.** Nothing on a surface may construct its
            // own registry — two registries would mean two answers to *what draws an integer*,
            // which is the drift R20a warns about, arrived at through the back door.
            new Rendering(
                new WpdbNodeRepository(),
                $this->frameworkNodes(),
                $settings,
                ShippedRenderers::registry(),
                // ⚠️ **Which node is which type, by id** ([D-510](../../docs/NewConcept/90-decision-log.md)).
                // *The same instance as the scaffolds', so the eleven options are read once a request.*
                $this->typeNodes(),
                // ⚠️ Without this a reference has no name to draw, and every constant on the
                // screen falls back to its id (D-105, D-159).
                $labels,
                // ⚠️ **Wired in the same one place, for the same reason.** *Two converter registries
                // would mean two answers to «which mappings may this type be given» — and the
                // `converter` setting drew as a **dead** control until there was one to ask
                // (D-219, list row 7).*
                ShippedConverters::registry()
            ),
            // ⚠️ *The same object the editor and the settings hold — that is the whole point of it
            // being memoised. A second one would open a bracket nobody writes into.*
            $this->changelog()
        );
    }
}
