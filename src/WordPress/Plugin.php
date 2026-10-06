<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Addon\ShippedAddons;
use Taxmod\Core\Renderer\ResidueRenderer;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Tree;
use Taxmod\WordPress\Admin\BackupScreen;
use Taxmod\WordPress\Admin\CleanupScreen;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\BaseScaffold;
use Taxmod\WordPress\Persistence\CompositionScaffold;
use Taxmod\WordPress\Persistence\Residue;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeedImage;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\UnitScaffold;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbSettingsRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;

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
    public const VERSION     = '0.1.2';
    public const TEXT_DOMAIN = 'taxmod';

    /** What a person must be able to do before they may shape the model. */
    public const CAPABILITY = 'manage_options';

    /** Was die Aktivierung abbrach — eine Option, weil der Hinweis erst im nächsten Aufruf erscheint. */
    private const ACTIVATION_FAILURE = 'taxmod_activation_failure';

    /** Was den letzten Umbau der Tabellen abbrach — `null`, solange keiner scheiterte. */
    private ?string $upgradeFailure = null;

    private function __construct(private readonly string $file)
    {
    }

    public static function boot(string $file): void
    {
        $plugin = new self($file);

        register_activation_hook($file, $plugin->activate(...));

        add_action('admin_menu', $plugin->registerMenu(...));
        add_action('admin_post_taxmod_node', $plugin->handleNodeAction(...));

        // ⚠️ **Der erste Rückweg vom Rand in den Kern** ([D-666](../../docs/NewConcept/90-decision-log.md),
        // [D-627](../../docs/NewConcept/90-decision-log.md)) — *gemessen 0 REST-Routen und 0 AJAX,
        // bevor dies hier stand. Er liefert den Einstellungsbereich **einer** Feldzeile nach, damit
        // eine zugeklappte Zeile gar nicht erst aufgelöst wird. **Über `admin-post.php` und nicht über
        // eine eigene Registratur**: dieselbe Nonce und dieselbe Fähigkeitsprüfung wie jeder andere
        // Akt dieser Seite.*
        add_action(
            'admin_post_' . NodesScreen::FRAGMENT_ACTION,
            static fn () => $plugin->screen()->handleFieldSettings()
        );

        // ⚠️ *Grosse Satzdialog-Körper, beim ersten Öffnen nachgeladen (D-898).*
        add_action(
            'admin_post_' . NodesScreen::SHARED_BODY_ACTION,
            static fn () => $plugin->screen()->handleSharedBody()
        );

        // ⚠️ *Die Zeilen der geerbten Felder, auf Wunsch nachgeladen (D-871) — derselbe Rückweg.*
        add_action(
            'admin_post_' . NodesScreen::INHERITED_ACTION,
            static fn () => $plugin->screen()->handleInheritedFields()
        );

        // ⚠️ **The installation's own screen** (D-397) — three decisions had been deferred to it and
        // each had its own interim: developer mode, the neutral locale, the tree's scale.
        add_action('admin_post_' . SettingsScreen::ACTION, $plugin->handleSettings(...));

        // ⚠️ **The repair surface** (D-247, U24) — decided on 2026-08-23 and never built, while the
        // three sources it names went on collecting.
        add_action('admin_post_' . CleanupScreen::ACTION, $plugin->handleCleanup(...));

        // ⚠️ **Sichern und Einspielen** ([D-908](../../docs/NewConcept/90-decision-log.md)).
        add_action('admin_post_' . BackupScreen::DOWNLOAD_ACTION, static fn () => (new BackupScreen())->handleDownload());
        add_action('admin_post_' . BackupScreen::RESTORE_ACTION, static fn () => (new BackupScreen())->handleRestore());

        // An upgrade must not depend on somebody remembering to deactivate and activate
        // again (`CD-6`). One option read per admin request, and the work happens only when
        // the stored version is behind.
        add_action('admin_init', $plugin->ensureUpToDate(...));

        // ⚠️ **Seitenvorlagen als Startmuster** ([D-870](../../docs/NewConcept/90-decision-log.md)) — *nur in der Verwaltung und für die
        // REST-Schnittstelle, über die der Block-Editor die Muster holt; der öffentliche Aufruf liest nichts.*
        add_action('admin_init', static fn () => $plugin->upgradeFailure === null && $plugin->starterPatterns()->register());
        add_action('rest_api_init', static fn () => $plugin->starterPatterns()->register());
    }

    public function starterPatterns(): WpStarterPatterns
    {
        return new WpStarterPatterns($this->editor(), new WpdbRecordRepository());
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
        // ⚠️ **Ein Umbau, der scheitert, legt nicht die ganze Verwaltung lahm** ([D-909](../../docs/NewConcept/90-decision-log.md)).
        // *Ohne diesen Fang war ein Fehler hier ein weisser Bildschirm in jedem wp-admin-Aufruf — und auf einer Website ohne
        // Dateizugang kein Rückweg. So bleibt die Verwaltung bedienbar, der Hinweis nennt den Fehler, und die Seite «Backup»
        // kann einen funktionierenden Stand einspielen.*
        try {
            $this->bringUpToDate();
        } catch (\Throwable $e) {
            $this->upgradeFailure = $e->getMessage();
        }

        $this->upgradeFailure ??= ((string) get_option(self::ACTIVATION_FAILURE, '')) ?: null;

        if ($this->upgradeFailure !== null) {
            add_action('admin_notices', $this->reportUpgradeFailure(...));
        }
    }

    public function reportUpgradeFailure(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><code>%s</code></p></div>',
            esc_html__('Taxonomy Modeller:', 'taxmod'),
            esc_html__('Updating the tables failed. The model may be incomplete until a backup is restored (Taxonomy Modeller → Backup).', 'taxmod'),
            esc_html((string) $this->upgradeFailure)
        );
    }

    private function bringUpToDate(): void
    {
        $before = (int) get_option(Schema::VERSION_OPTION, 0);

        Schema::ensureCurrent();

        // ⚠️ *Auch hier vor der Saat, aus demselben Grund — und auch hier ohne Wirkung, sobald ein
        // Knoten steht: {@see SeedImage::importOnce()} geht an einem gewachsenen Baum vorbei.*
        (new SeedImage())->importOnce();

        if ($before !== Schema::VERSION) {
            $this->frameworkNodes()->seed();
        }

        // Its own version, because the scaffold is content and the schema is machinery — they
        // move for different reasons and must not drag each other along.
        $this->baseScaffold()->importOnce();
        $this->unitScaffold()->importOnce();
        $this->compositionScaffold()->importOnce();
    }

    /**
     * ⚠️ **Auch die Aktivierung fängt ihren Fehler** ([D-909](../../docs/NewConcept/90-decision-log.md)). *Gemessen am
     * 2026-10-06 auf fambach.net: ein Rest von 0.0.1 liess `install()` werfen, und WordPress verweigerte die Aktivierung
     * ganz — damit war auch die Seite «Backup» nicht erreichbar, die den Stand reparieren soll. Jetzt bleibt das Plugin
     * aktiv, der Fehler steht in einer Option und als Hinweis, und das Einspielen einer Sicherung räumt beide weg.*
     */
    public function activate(): void
    {
        try {
            $this->installFresh();
            delete_option(self::ACTIVATION_FAILURE);
        } catch (\Throwable $e) {
            update_option(self::ACTIVATION_FAILURE, $e->getMessage(), false);
        }
    }

    private function installFresh(): void
    {
        Schema::install();
        update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

        // ⚠️ **Der Abzug zuerst** ([D-600](../../docs/NewConcept/90-decision-log.md)): *«eine
        // Neuinstallation entsteht künftig aus einem Abbild des gewachsenen Baums».* Er bringt die
        // **Nummern** mit — danach finden die Saat und die vier Gerüste alles vor und legen nichts
        // an. *Auf einem gewachsenen Baum tut er nichts; er ist der Anfangszustand und keine
        // Wanderung.*
        (new SeedImage())->importOnce();

        // ⚠️ **Die Standardsprache wird beim Anlegen **geschrieben**, nicht nur hergeleitet** — und
        // dass sie es nicht wurde, war mein Versäumnis. *Der Eigentümer, am 2026-09-05, auf meinen
        // Befund «die Option ist nicht gesetzt»: «aber das ist doch dein Fehler, dass du sie beim
        // Anlegen nicht gesetzt hast.» **Der Bildschirm zeigte `en_US`, gespeichert war nichts** —
        // die Anzeige fiel auf die Sprache der Website zurück, und «gewählt» war von «hergeleitet»
        // nicht zu unterscheiden.*
        //
        // ⚠️ **Die Folge, die es zu verhindern gilt:** *stellt jemand die Sprache der Website um,
        // wanderte die Standardsprache **stillschweigend mit** — und damit änderte sich, welche
        // Beschriftungen als überall gültig zählen, ohne dass jemand das Modell angefasst hätte
        // ([D-387](../../docs/NewConcept/90-decision-log.md)).*
        //
        // ⚠️ *Nur wenn nichts dasteht: eine getroffene Wahl wird nie überschrieben.*
        if (get_option(SettingsScreen::NEUTRAL_LOCALE, '') === '') {
            $site = get_locale();

            update_option(SettingsScreen::NEUTRAL_LOCALE, $site === '' ? 'en_US' : $site, true);
        }

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
        $unterseiten = [];

        $unterseiten[] = add_submenu_page(
            'taxmod',
            // ⚠️ **`Configuration`, nicht `Installation`** ([D-703](../../docs/NewConcept/90-decision-log.md)).
            // *Sein Wort: «ich würd es lieber in configutation umbennen». **Und der zweite Grund ist,
            // dass «Installation» im Modell schon etwas anderes heisst**: die reservierte Identität am
            // Kopf der Auflösungskette ([D-079](../../docs/NewConcept/90-decision-log.md)). Ein Wort
            // für zwei Dinge, und die Seite trug das falsche davon.*
            __('Configuration', 'taxmod'),
            __('Configuration', 'taxmod'),
            self::CAPABILITY,
            // ⚠️ *Der Schlüssel bleibt: **er ist eine Adresse, kein Name.** Ein Lesezeichen auf
            //  `page=taxmod-settings` bricht, wenn er sich ändert, und niemand liest ihn.
            //  D-703 nennt das ausdrücklich als Teil der Entscheidung.*
            'taxmod-settings',
            fn () => print (new SettingsScreen())->render()
        );

        // ⚠️ **`Cleanup` at last** ([D-247](../../docs/NewConcept/90-decision-log.md), decided
        // 2026-08-23): *«nodes that have no connections any more, or settings that broke because
        // something was deleted»*. It is **not a feature but a repair surface**
        // ([U24](../../docs/NewConcept/20-interaction.md)), which is why it is a page of its own and
        // not a button on the modelling screen — nothing here happens in passing.
        $unterseiten[] = add_submenu_page(
            'taxmod',
            __('Cleanup', 'taxmod'),
            __('Cleanup', 'taxmod'),
            self::CAPABILITY,
            CleanupScreen::PAGE,
            fn () => print $this->cleanupScreen()->render()
        );

        $unterseiten[] = add_submenu_page(
            'taxmod',
            __('Backup', 'taxmod'),
            __('Backup', 'taxmod'),
            self::CAPABILITY,
            BackupScreen::PAGE,
            static fn () => print (new BackupScreen())->render()
        );

        // ⚠️ **An den eigenen Haken jeder Seite**, damit das Stilblatt nicht auf jeder Seite in
        // `wp-admin` landet. *`add_menu_page()` und `add_submenu_page()` geben den Haken zurück,
        // weshalb die Verdrahtung hier steht und nicht neben den anderen Haken in `boot()`.*
        //
        // ⚠️ **Hier stand nur `$hook`, und damit bekamen die beiden Unterseiten das Stilblatt nie**
        // ([D-696](../../docs/NewConcept/90-decision-log.md)). *Sein Befund an der
        // Konfigurationsseite: «sehe aber nix» — drei Spalten standen da, aber als gewöhnliche
        // Kästchen ohne Breiten und ohne Schiebeschalter. **Nicht der Zwischenspeicher des Browsers,
        // sondern eine Datei, die nie angefordert wurde.** Und es galt für die Aufräumseite genauso,
        // seit es sie gibt.*
        foreach ([$hook, ...$unterseiten] as $seite) {
            if ($seite !== false) {
                add_action('admin_print_styles-' . $seite, $this->enqueueStyle(...));
            }
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
        // ⚠️ **Der Linkdialog von WordPress** ([D-857](../../docs/NewConcept/90-decision-log.md)) — sein Wort: *«ja bau den wp linkdialog
        // ein»*. Derselbe Dialog wie im Editor: Adresse, Linktext, Suche in den eigenen Inhalten. Er braucht sein Skript, sein Stilblatt und
        // sein Markup im Fuss der Seite.
        wp_enqueue_script('wplink');
        wp_enqueue_style('editor-buttons');
        // ⚠️ *Und die Mediathek (D-858) — sein Wort: «für datei sollte die mediathek geöffnet werden». Sie lädt auch selbst hoch.*
        wp_enqueue_media();

        if (! has_action('admin_footer', [$this, 'printLinkDialog'])) {
            add_action('admin_footer', [$this, 'printLinkDialog']);
        }

        wp_enqueue_script(
            'taxmod-admin',
            plugins_url('assets/admin.js', WP_PLUGIN_DIR . '/' . basename(dirname($this->file)) . '/' . basename($this->file)),
            [],
            (string) (@filemtime($this->path('assets/admin.js')) ?: self::VERSION),
            true
        );
    }

    /**
     * Das Markup des Linkdialogs und das verborgene Textfeld, in das er seinen Link schreibt ([D-857](../../docs/NewConcept/90-decision-log.md)).
     * *Der Dialog schreibt `<a href="…">Text</a>` in ein Textfeld; das Skript liest daraus Adresse und Beschriftung.*
     */
    public function printLinkDialog(): void
    {
        if (! class_exists('_WP_Editors', false)) {
            require_once ABSPATH . WPINC . '/class-wp-editor.php';
        }

        echo '<textarea id="taxmod-wplink-target" hidden aria-hidden="true"></textarea>';
        \_WP_Editors::wp_link_dialog();
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
            $this->frameworkNodes(),
            $this->changelog(),
            // ⚠️ **Only `duplicate()` reads this** — a copy has to resolve exactly like its
            // original, so its own labels travel with it.
            new WpdbLabelRepository(),
            // ⚠️ **Damit `clearTrash()` die Daten mitnimmt** — [C102](../../docs/NewConcept/10-domain-core.md):
            // *ein Record ohne seinen Knoten ist undenkbar.* Ohne dieses Argument überlebten die Records
            // ihren Knoten, während der Docblock der Methode behauptete, sie gingen mit.
            new WpdbRecordRepository(),
            new WpdbSettingsRepository()
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
            // ⚠️ *Mit Changelog und Knoten-Repository, damit eine Labelaenderung in der Geschichte
            // steht ([D-489]) und `owner_kind` nicht geraten wird.*
            new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale(), $this->changelog()),
            $this->typeNodes(),
            $this->settingsEditor()
        );
    }

    private ?\Taxmod\Core\Service\SettingsEditor $settingsEditor = null;

    /** Der eine Schreiber in das Einstellungsmodell — für die Maske wie für das Einheitengerüst. */
    private function settingsEditor(): \Taxmod\Core\Service\SettingsEditor
    {
        return $this->settingsEditor ??= new \Taxmod\Core\Service\SettingsEditor(
            new WpdbSettingsRepository(),
            new WpdbNodeRepository(),
            $this->settingsResolver(),
            ShippedRenderers::registry(),
            ShippedConverters::registry(),
            $this->changelog(),
            // ⚠️ *Damit eine gewählte Zusatzfunktion ihren Namen findet (D-845).*
            ShippedAddons::registry()
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
            $this->typeNodes()
        );
    }

    private function baseScaffold(): BaseScaffold
    {
        return new BaseScaffold(
            $this->editor(),
            $this->frameworkNodes(),
            // ⚠️ **The seed writes down which node each type became** ([D-510](../../docs/NewConcept/90-decision-log.md)),
            // and everything afterwards reads that id instead of a name.
            $this->typeNodes()
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
            // ⚠️ *Hier standen zwei Argumente mehr, als {@see SeededFrameworkNodes} Parameter hat —
            // PHP nimmt überzählige Argumente wortlos an. Beim Streichen des Id-Vergebers (TASK-004)
            // fielen sie auf und gehen mit.*
            $this->changelog()
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
        // ⚠️ *Dasselbe Exemplar des Changelogs wie ueberall ([D-470](../../docs/NewConcept/90-decision-log.md)),
        // damit eine Labelaenderung in derselben Aenderungsgruppe landet wie der Akt, der sie ausloeste.*
        $labels   = new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale(), $this->changelog());

        // ⚠️ **Der Zeichenlauf wird zuerst gebaut, weil der Schreibweg ihn braucht**
        // ([D-649](../../docs/NewConcept/90-decision-log.md)): *{@see DataEntry} fragt ihn nach der
        // Vorbelegung eines Feldes ({@see \Taxmod\Core\Port\Presets}) — «beim Anlegen gibt es noch
        // keinen Datensatz, dann muss hier automatisch die Benutzer-Id hinterlegt werden». **Dasselbe
        // Exemplar**, damit nicht zwei Auflösungen nebeneinander stehen.*
        // ⚠️ *Jeder Dialog bekommt «OK» und «Abbrechen» — die Worte kommen von hier, weil der Kern keine macht (D-804, `AR-2`).*
        // ⚠️ *Und ganze Knotenbäume öffnen den einen Auswahlbaum, den diese Seite zeichnet (D-815) — nur hier, weil nur hier einer steht.*
        $rendering = $this->rendering($labels)->withDialogWords(__('OK', 'taxmod'), __('Cancel', 'taxmod'), __('Show the tree', 'taxmod'), __('Choose a file from the media library', 'taxmod'), __('Remove this choice', 'taxmod'), __('Choose a link — also to your own pages and posts', 'taxmod'))->withSharedPicker()
            // ⚠️ *Und gleiche Satzdialoge stehen einmal als Vorlage (D-866) — die Seite gibt sie am Ende aus.*
            ->withSharedRecordBodies(new \Taxmod\Core\Renderer\SharedBodies())
            // ⚠️ *Die Worte der Zusatzfunktionen (D-845) — der Kern kennt nur ihre Schlüssel (`AR-2`).*
            ->withAddonWords([
                'add'                   => __('Add this function', 'taxmod'),
                'inherited'             => __('inherited from the node — adding one here replaces them at this place', 'taxmod'),
                'addon:preset'          => __('Preset', 'taxmod'),
                'addon:pick_rows'       => __('Add several', 'taxmod'),
                'addon:range'           => __('Check range', 'taxmod'),
                'addon:shape'           => __('Check shape', 'taxmod'),
                'field:source_field'    => __('field here', 'taxmod'),
                'field:offered_field'   => __('field on the offered record', 'taxmod'),
                'field:mode'            => __('then', 'taxmod'),
                'field:pick_field'      => __('chosen by', 'taxmod'),
                'enum:filter'           => __('filter', 'taxmod'),
                'enum:sort'             => __('sort', 'taxmod'),
            ]);

        // ⚠️ *Ein Exemplar, weil der Nachlauf es selbst wieder braucht: geschriebene Zusammenfassungen gehen denselben Weg
        // wie jeder andere Wert ([D-885](../../docs/NewConcept/90-decision-log.md)).*
        $data = new DataEntry(
            new WpdbRecordRepository(),
            new WpdbRelationRepository(),
            new WpdbNodeRepository(),
            $this->frameworkNodes(),
            new SystemClock(),
            $this->changelog(),
            // ⚠️ *Die Vorbelegung eines Feldes, gefragt beim **ersten Schreiben**
            // ([D-609](../../docs/NewConcept/90-decision-log.md), [D-649](../../docs/NewConcept/90-decision-log.md)).
            // Derselbe Zeichenlauf, damit Typ und Kette nicht zweimal aufgelöst werden.*
            $rendering
        );

        // ⚠️ **Die Zusammenfassung wird bei jeder Änderung neu geschrieben** ([D-885](../../docs/NewConcept/90-decision-log.md),
        // sein Wort) — *auch, wenn ein Teil sich ändert: der Nachlauf sucht die Halter.*
        $data->afterWrite(function (array $saetze) use ($data, $rendering): void {
            (new \Taxmod\Core\Service\SummaryWriter(
                new WpdbRecordRepository(),
                new WpdbRelationRepository(),
                new WpdbNodeRepository(),
                $this->frameworkNodes(),
                $this->typeNodes(),
                $rendering,
                $data
            ))->refresh($saetze);
        });

        return new NodesScreen(
            $this->editor(),
            new Tree(new WpdbNodeRepository()),
            $labels,
            // ⚠️ *Dasselbe Exemplar des Aenderungsbuchs wie oben, seit auch Wertaenderungen melden
            // ([D-634](../../docs/NewConcept/90-decision-log.md)): sonst faende ein Wert, der in
            // derselben Handlung geschrieben wird wie ein Label, eine andere Aenderungsgruppe vor.*
            $data,
            $this->frameworkNodes(),
            $rendering,
            // ⚠️ *The same object the editor and the settings hold — that is the whole point of it
            // being memoised. A second one would open a bracket nobody writes into.*
            $this->changelog(),
            // ⚠️ *Dasselbe Exemplar wie der Zeichenlauf: die elf Typknoten werden einmal je
            // Anfrage gelesen, und der Feldziel-Dialog braucht daraus nur einen — den Text.*
            $this->typeNodes(),
            // ⚠️ **Schritt 5 des Bauplans** ([D-712](../../docs/NewConcept/90-decision-log.md)): *die
            // Maske schreibt Attribute in `settings_value` — mit derselben Auflösung, die der Zeichner
            // liest, damit «nur Gesetztes» gegen dasselbe Bild geprüft wird.*
            $this->settingsEditor()
        );
    }

    private ?\Taxmod\Core\Service\SettingsResolver $settingsResolver = null;

    /**
     * Die Auflösung Kante → Knoten → Vertrag — **eine** je Anfrage, damit Zeichner und Schreiber
     * dasselbe Gehaltene sehen.
     */
    private function settingsResolver(): \Taxmod\Core\Service\SettingsResolver
    {
        return $this->settingsResolver ??= new \Taxmod\Core\Service\SettingsResolver(
            new WpdbSettingsRepository(),
            new WpdbNodeRepository(),
            ShippedRenderers::registry(),
            ShippedConverters::registry(),
            // ⚠️ *Die Zusatzfunktionen und die Kanten: was eine gewählte Vorbelegung an einer Konstante bedingt, hängt am Ziel ihres Feldes (D-845).*
            ShippedAddons::registry(),
            new WpdbRelationRepository()
        );
    }

    /**
     * Der Zeichenlauf, an einer Stelle gebaut.
     *
     * ⚠️ **The renderers are wired in one place.** *Nothing on a surface may construct its own
     * registry — two registries would mean two answers to «what draws an integer», which is the
     * drift R20a warns about, arrived at through the back door.*
     *
     * ⚠️ *Herausgezogen, weil ihn seit [D-649](../../docs/NewConcept/90-decision-log.md) zwei
     * bekommen: der Bildschirm zum Zeichnen und {@see DataEntry} für die Vorbelegung.*
     */
    private function rendering(Labels $labels): Rendering
    {
        return new Rendering(
            new WpdbNodeRepository(),
            $this->frameworkNodes(),
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
            ShippedConverters::registry(),
            // ⚠️ **Und die Kanten, damit der Abstieg durch die Knoten gehen kann.** *Seine
            // Diagnose: «heisst wohl Renderkette ist unterbrochen». Ohne dies endete der Gang am
            // ersten zusammengesetzten Feld — `Kontakt.Address` zeigte ein Kästchen, `Adresse` hat
            // fünf Felder.*
            relations: new WpdbRelationRepository(),
            // ⚠️ **Die einzige Stelle, an der wegen `user_ref` nach WordPress gefragt wird**
            // ([D-649](../../docs/NewConcept/90-decision-log.md), `CD-1`): *der Name zum Zeichnen und
            // die Id des Angemeldeten zum Anlegen. **Der Kern nimmt beides entgegen und beschafft
            // keines.***
            users: new WpUsers(),
            records: new WpdbRecordRepository(),
            // ⚠️ *Die Mediathek (D-865): der Kern speichert die Id einer Datei, der Rand sagt Adresse, Titel und Vorschaubild.*
            media: new WpMediaLibrary(),
            addons: ShippedAddons::registry(),
            // ⚠️ **Schritt 4 des Bauplans** ([D-712](../../docs/NewConcept/90-decision-log.md)): *die
            // Renderer zeichnen aus `settings_value` und dem Vertrag; `ModelValues` bleibt für das,
            // was noch nicht umgezogen ist — die erlaubten Kinder und die Vorgaben der Datensätze.*
            resolver: $this->settingsResolver()
        );
    }
}
