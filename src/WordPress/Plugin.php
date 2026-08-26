<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\Core\Service\Tree;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\BaseScaffold;
use Taxmod\WordPress\Persistence\CompositionScaffold;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
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
    }

    public function activate(): void
    {
        Schema::install();
        update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

        $this->frameworkNodes()->seed();
        $this->baseScaffold()->importOnce();
        $this->unitScaffold()->importOnce();
        $this->compositionScaffold()->importOnce();
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
            ['dashicons'],
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

    public function editor(): ModelEditor
    {
        return new ModelEditor(
            new WpdbNodeRepository(),
            new WpdbRelationRepository(),
            new TableIdentityAllocator(),
            $this->frameworkNodes(),
            new WpdbChangelog(new SystemClock()),
            // ⚠️ **Only `duplicate()` reads these** — a copy has to resolve exactly like its
            // original, so its own settings and labels travel with it.
            new WpdbSettingRepository(),
            new WpdbLabelRepository()
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
            new Settings(new WpdbSettingRepository(), new WpdbNodeRepository(), $this->frameworkNodes(), new WpdbChangelog(new SystemClock())),
            new Labels(new WpdbLabelRepository(), $this->frameworkNodes())
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
            new Settings(new WpdbSettingRepository(), new WpdbNodeRepository(), $this->frameworkNodes(), new WpdbChangelog(new SystemClock()))
        );
    }

    private function baseScaffold(): BaseScaffold
    {
        return new BaseScaffold(
            $this->editor(),
            $this->frameworkNodes(),
            // ⚠️ **Handed in so the scaffold can say what a number type permits.** The owner:
            // *`range_min` and `range_max` on `int` should be int's min and max.* The bounds come
            // from the column it is stored in, because storage is what refuses.
            new Settings(new WpdbSettingRepository(), new WpdbNodeRepository(), $this->frameworkNodes(), new WpdbChangelog(new SystemClock()))
        );
    }

    private function frameworkNodes(): SeededFrameworkNodes
    {
        return new SeededFrameworkNodes(
            new WpdbNodeRepository(),
            new WpdbRelationRepository(),
            new TableIdentityAllocator(),
            new WpdbChangelog(new SystemClock()),
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
        $settings = new Settings(new WpdbSettingRepository(), new WpdbNodeRepository(), $this->frameworkNodes(), new WpdbChangelog(new SystemClock()));
        $labels   = new Labels(new WpdbLabelRepository(), $this->frameworkNodes());

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
                // ⚠️ Without this a reference has no name to draw, and every constant on the
                // screen falls back to its id (D-105, D-159).
                $labels
            )
        );
    }
}
