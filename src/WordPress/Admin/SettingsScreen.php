<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Renderer\ToggleMarkup;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Plugin;

/**
 * The installation's own settings — **the screen three decisions had been waiting for**.
 *
 * The owner: *that would be a setting on the admin page — we should tackle those next, otherwise some
 * information may get lost.* He is right that it was overdue: three things had been deferred to a
 * screen that did not exist, each with its own interim.
 *
 * ```mermaid
 * flowchart LR
 *   I["the installation · one answer for the whole site"] --> S[this screen]
 *   N["a node · one answer per node"] --> C["the settings chain"]
 * ```
 *
 * | What | Where it had been living |
 * |---|---|
 * | **developer mode** | a WordPress option nobody could reach ([D-389](../../../docs/NewConcept/90-decision-log.md)), and before that on the **root node**, where it could differ per branch |
 * | **the neutral locale** | falling back to the site language ([D-387](../../../docs/NewConcept/90-decision-log.md)), because there was nowhere to declare it |
 * | **the tree's scale** | nowhere at all — the owner asked for the icon size and the font *in the settings* and I had no honest place to put them |
 *
 * ⚠️ **This closes [OQ-039](../../../docs/NewConcept/91-open-questions.md)**, open since Package 4:
 * *the installation link is where a posture belongs and it does not appear in the modeller.* It does
 * now, as a screen of its own rather than as a node.
 *
 * ⚠️ **A WordPress option and not a setting on a node, and the reason is not convenience.** A setting
 * resolves along the chain — *installation → model root → ancestors → node → use site* — so anything
 * put there **can differ per node**, and *developer mode, but only under Compositions* is not a thing.
 * **A fact about the installation has to live somewhere that cannot vary** ([D-389](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **`CD-5` in order, every time**: capability → nonce → validate → sanitise → act → escape. *An
 * options screen is exactly where that gets skipped «because only an administrator sees it».*
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class SettingsScreen
{
    public const ACTION = 'taxmod_settings';

    /** Which language stands for *everywhere* — the row stored without a locale. */
    public const NEUTRAL_LOCALE = 'taxmod_neutral_locale';

    /** How big the tree's glyphs are, in pixels. */
    public const ICON_SIZE = 'taxmod_icon_size';

    /** How big the tree's text is, in pixels. */
    public const FONT_SIZE = 'taxmod_font_size';

    /**
     * Ob der Papierkorb unter dem Baum steht.
     *
     * ⚠️ **Eine Option und kein Ansichtsschalter** ([D-693](../../../docs/NewConcept/90-decision-log.md)).
     * *`show hidden` und `show the root` reisen in der Adresse mit, weil sie **kurzzeitig** gemeint
     * sind. Einen Papierkorb, den man nicht sehen will, will man dauerhaft nicht sehen.*
     *
     * ⚠️ **Und nicht hinter dem Entwicklermodus:** *der Papierkorb ist ein **Ort**
     * ([D-123](../../../docs/NewConcept/90-decision-log.md): parken, dann endgültig entfernen), keine
     * Diagnose. Der Entwicklermodus zeigt, was sonst niemanden angeht.*
     */
    public const SHOW_TRASH = 'taxmod_show_trash';

    /** Wie viele Datensätze die Knotenseite je Seite zeichnet ([D-763](../../../docs/NewConcept/90-decision-log.md)). */
    public const RECORDS_PER_PAGE = 'taxmod_records_per_page';

    /** Ob ein Klick im Baum die Seite stehen lässt oder nach oben springt ([D-807](../../../docs/NewConcept/90-decision-log.md)). */
    public const TREE_CLICK = 'taxmod_tree_click';

    /** Automatisch speichern ([D-854](../../../docs/NewConcept/90-decision-log.md)) — sein Wort: «autosave und ihn anschalten». */
    public const AUTOSAVE = 'taxmod_autosave';

    /**
     * Die wählbaren Seitengrössen — sein Wort: «5,10,20,50» ([D-763](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @var list<int>
     */
    private const PAGE_SIZES = [5, 10, 20, 50];

    /**
     * Die vier Gerüste und ihre Optionen.
     *
     * ⚠️ *Die Namen stehen hier und nicht als `::OPTION` je Klasse abgefragt: **dieser Bildschirm
     * zeigt sie nur an und soll nicht vier Persistenzklassen laden müssen, um eine Zahl zu
     * drucken.** Fällt eines der Gerüste weg, fällt seine Zeile hier auf einen Strich — nicht
     * auf einen Fehler.
     *
     * @var array<string, string>
     */
    private const SCAFFOLDS = [
        'taxmod_base_scaffold'        => 'base',
        'taxmod_unit_scaffold'        => 'units',
        'taxmod_composition_scaffold' => 'compositions',
        'taxmod_rendering_scaffold'   => 'rendering',
    ];

    /**
     * Sizes the owner may pick, and why it is a list rather than a number field.
     *
     * ⚠️ *He walked the numbers himself — «one pixel bigger», «make 20», «25px», then back to 17 —
     * which is what a **list of tried sizes** is for. R28's rule applies to this like anything else: a
     * control offers real choices, and a free number field offers 4px and 400px too.*
     *
     * @var list<int>
     */
    private const SIZES = [13, 15, 17, 20, 25];

    /**
     * What developer mode is, said once.
     *
     * ⚠️ *[D-248](../../../docs/NewConcept/90-decision-log.md) folded test mode into it — **one mode,
     * not two** — so the same switch lifts deletion protection and shows the diagnostics. Two modes
     * that overlap are two things to explain and two ways to be in a surprising state.*
     */
    public function render(): string
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            return '';
        }

        $html = '<div class="wrap">'
            . '<h1>' . esc_html__('Taxonomy Modeller — configuration', 'taxmod') . '</h1>'
            . $this->notice()
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . wp_nonce_field(self::ACTION, '_taxmod_nonce', true, false)
            // ⚠️ **Drei Spalten auf sein Wort** ([D-695](../../../docs/NewConcept/90-decision-log.md)):
            // *«tablle links label, schalter/wert, recht erklärung».* **Und nicht `form-table`**,
            // *deren zwei Spalten es gar nicht anders könnten.*
            // ⚠️ **Der Merker steht **vor** der Tabelle und nicht darin**
            // ([D-706](../../../docs/NewConcept/90-decision-log.md)). *Zwischen `</tr>` und `<tr>`
            // gehört kein Element; der Browser holt es dort heraus und stellt es vor die Tabelle —
            // **also stellt man es gleich dorthin, statt sich auf eine Reparatur zu verlassen.***
            . (self::inDeveloperMode() ? '<input type="hidden" name="dev_details" value="1">' : '')
            . '<table class="taxmod-config"><thead><tr>'
            . '<th scope="col">' . esc_html__('Setting', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Value', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('What it does', 'taxmod') . '</th>'
            . '</tr></thead><tbody>'
            . $this->developerRow()
            . $this->developerDetailRows()
            . $this->localeRow()
            . $this->trashRow()
            . $this->recordsPerPageRow()
            . $this->treeClickRow()
            . $this->autosaveRow()
            . $this->sizeRow(self::ICON_SIZE, __('Icon size', 'taxmod'), self::defaultIconSize(), __('The glyphs in the tree and on its buttons.', 'taxmod'))
            . $this->sizeRow(self::FONT_SIZE, __('Text size', 'taxmod'), self::defaultFontSize(), __('The names in the tree. The owner asked for these two together, because a 17px glyph beside 13px text reads as a mistake.', 'taxmod'))
            . '</tbody></table>'
            . get_submit_button(__('Save', 'taxmod'))
            . '</form>'
            // ⚠️ *Ausserhalb des Formulars, weil nichts davon abgeschickt wird.
            //  Ein Anzeigeblock innerhalb eines Formulars lädt dazu ein, ihn für einstellbar zu halten.*
            . $this->facts()
            . '</div>';

        return $html;
    }

    /**
     * The posture, as a switch.
     *
     * ⚠️ **Off by default, because a switch nobody set is off** — and because the diagnostics it shows
     * are for whoever is building, not for whoever is modelling.
     */
    private function developerRow(): string
    {
        return $this->row(
            __('Developer mode', 'taxmod'),
            ToggleMarkup::input('developer', self::inDeveloperMode()),
            __('Shows the diagnostics and lifts the deletion guards. One mode, not two: the same switch that shows which renderer drew what also lets a protected node be parked.', 'taxmod')
        );
    }

    /**
     * Was der Entwicklermodus einzeln zeigt — **vier Haken unter einem Modus**.
     *
     * ⚠️ **[D-705](../../../docs/NewConcept/90-decision-log.md), und es widerruft
     * [D-248](../../../docs/NewConcept/90-decision-log.md) nicht.** *D-248 verbot einen **zweiten
     * Modus** («two ways to be in a surprising state»); diese vier sind ein **Sichtfilter** innerhalb
     * des einen Modus und können in keine Lage führen, in die er nicht schon geführt hat.*
     *
     * ⚠️ *Vorgabe **an**, jede einzeln: der Modus soll beim Einschalten das tun, was er bisher tat.
     * Deshalb wird beim Speichern ausdrücklich `0` geschrieben statt gelöscht — «aus» und «nie
     * gesetzt» sähen sonst gleich aus und bedeuteten das Gegenteil.*
     *
     * @var array<string, string> Option => Feldname im Formular.
     */
    private const DEVELOPER_DETAILS = [
        'taxmod_dev_writes'          => 'dev_writes',
        'taxmod_dev_settings_record' => 'dev_settings_record',
        'taxmod_dev_root_toggle'     => 'dev_root_toggle',
    ];

    /** Ob ein einzelner Haken zieht — **und er zieht nur, während der Modus an ist**. */
    public static function developerShows(string $option): bool
    {
        return self::inDeveloperMode() && get_option($option, '1') !== '0';
    }

    /**
     * Die vier Zeilen — **nur, wenn der Modus an ist**.
     *
     * ⚠️ *Sein Wort: «wenn developer mode aktiv ist». **Vier Haken über einem ausgeschalteten Modus
     * wären vier Fragen ohne Wirkung.***
     *
     * ⚠️ *Das verborgene Feld ist der Merker: **ohne es könnte das Speichern nicht unterscheiden,
     * ob ein Haken abgewählt wurde oder ob die Zeile gar nicht dastand** — und ein Formular, das die
     * Zeilen nicht trug, würde sie alle löschen.*
     */
    private function developerDetailRows(): string
    {
        if (! self::inDeveloperMode()) {
            return '';
        }

        $was = [
            'taxmod_dev_writes'          => [
                __('· Write counts', 'taxmod'),
                __('The number beside each name in the tree. It counts writes and is not a version: it only says that nobody has changed that row since you read it.', 'taxmod'),
            ],
            'taxmod_dev_settings_record' => [
                __('· The settings record', 'taxmod'),
                __('The record that holds a node’s settings, shown in the record block with its values in words. It is not data anybody entered, which is why it is out of the way by default.', 'taxmod'),
            ],
            'taxmod_dev_root_toggle'     => [
                __('· «Show the root»', 'taxmod'),
                __('The view switch in the tree that reveals the root itself. It is needed to give the root a field that every branch inherits, and it is meant for a moment, not for every day.', 'taxmod'),
            ],
        ];

        $rows = '';

        foreach (self::DEVELOPER_DETAILS as $option => $field) {
            $rows .= $this->row(
                $was[$option][0],
                ToggleMarkup::input($field, self::developerShows($option)),
                $was[$option][1]
            );
        }

        return $rows;
    }

    /**
     * Eine Zeile: Name, Schalter oder Wert, Erklärung.
     *
     * ⚠️ **Die Erklärung steht sichtbar und nicht hinter dem Fragezeichen, und das ist eine
     * ausdrückliche Ausnahme von [D-661](../../../docs/NewConcept/90-decision-log.md)**
     * ([D-695](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «tablle links label,
     * schalter/wert, recht erklärung». D-661s Grund — «eine Erklärung, die immer sichtbar ist,
     * wird nach dem dritten Mal nicht mehr gelesen und kostet trotzdem jedes Mal Platz» — trägt
     * hier nicht: **diese Seite besucht man selten und jede Zeile ist eine Entscheidung**, und die
     * Spalte ist der Platz, nicht sein Verbrauch.*
     */
    private function row(string $label, string $control, string $why): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th>'
            . '<td class="taxmod-config-value">' . $control . '</td>'
            . '<td class="taxmod-config-why description">' . esc_html($why) . '</td></tr>';
    }

    /**
     * Ob der Papierkorb unter dem Baum steht.
     *
     * ⚠️ **Vorgabe an**, weil ein Schalter, den niemand gesetzt hat, nichts wegnehmen darf, was
     * vorher dastand — und weil mit dem Abschnitt auch `Clear` verschwindet
     * ([D-693](../../../docs/NewConcept/90-decision-log.md)).
     */
    private function trashRow(): string
    {
        return $this->row(
            __('Trash', 'taxmod'),
            ToggleMarkup::input('show_trash', self::showsTrash()),
            __('Shows the trash under the tree. Parked nodes stay parked either way — this only decides whether the list is on the screen. With it off, «Clear» goes with it and the cleanup page is where things are removed for good.', 'taxmod')
        );
    }

    /**
     * Which language is *the* one.
     *
     * ⚠️ **This is what [D-387](../../../docs/NewConcept/90-decision-log.md) was waiting for.** The
     * owner: *neutral is probably too much — we declare `en_US` as neutral in the admin config.* The
     * storage does not change: a label written in this language goes into the row with **no** locale,
     * which is what *valid everywhere* has always meant ([D-317](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Offered from what WordPress has installed plus the site's own language, because that is a
     * boundary fact and the core could not list them (`CD-1`).*
     */
    private function localeRow(): string
    {
        $offered = [get_locale() => get_locale()];

        foreach (get_available_languages() as $one) {
            $offered[$one] = $one;
        }

        ksort($offered);

        return $this->row(
            __('Default language', 'taxmod'),
            \Taxmod\Core\Renderer\SelectMarkup::of('neutral_locale', $offered, self::neutralLocale(), false),
            __('A text written in this language counts as valid everywhere, and is stored without a language of its own. Other languages are stored beside it and win where they exist.', 'taxmod')
        );
    }

    /** Wie viele Datensätze eine Seite zeigt — eine Liste, kein Zahlenfeld, wie bei den Grössen darunter. */
    private function recordsPerPageRow(): string
    {
        $options = [];

        foreach (self::PAGE_SIZES as $size) {
            $options[(int) $size] = (string) $size;
        }

        return $this->row(
            __('Records per page', 'taxmod'),
            \Taxmod\Core\Renderer\SelectMarkup::of('records_per_page', $options, (string) self::recordsPerPage(), false),
            __('How many records a node page draws at once. Drawing a record costs time; the rest are a page away.', 'taxmod')
        );
    }

    /** Ein Klick im Baum: stehen bleiben oder nach oben springen ([D-807](../../../docs/NewConcept/90-decision-log.md)). */
    private function treeClickRow(): string
    {
        $jetzt = self::treeClickJumps() ? 'jump' : 'stay';
        $wahl  = ['stay' => __('Stay where the page is', 'taxmod'), 'jump' => __('Jump to the top', 'taxmod')];
        return $this->row(
            __('Clicking a node in the tree', 'taxmod'),
            \Taxmod\Core\Renderer\SelectMarkup::of('tree_click', $wahl, $jetzt, false),
            __('Whether the node page keeps its scroll position or starts at the top when a node in the tree is chosen. Opening a record always lands at its input.', 'taxmod')
        );
    }

    /**
     * Automatisch speichern ([D-854](../../../docs/NewConcept/90-decision-log.md)) — *sein Wort: «wir sollten dafür einen umschalter in der
     * config machen, autosave und ihn anschalten. es ist aber nicht immer das feld bei checkboxen kann es auch beim verlassen der gruppe
     * sein».*
     */
    private function autosaveRow(): string
    {
        $wahl = ['on' => __('Save on leaving a field', 'taxmod'), 'off' => __('Save only with the save button', 'taxmod')];

        return $this->row(
            __('Saving', 'taxmod'),
            \Taxmod\Core\Renderer\SelectMarkup::of('autosave', $wahl, self::autosaves() ? 'on' : 'off', false),
            __('With «save on leaving a field» a change is written when the field loses focus — for switches and choices when the group around them is left, so several ticks travel together.', 'taxmod')
        );
    }

    /** One size, chosen from what has been tried rather than typed. */
    private function sizeRow(string $option, string $label, int $now, string $why): string
    {
        $options = [];

        foreach (self::SIZES as $size) {
            $options[(int) $size] = $size . 'px';
        }

        return $this->row(
            $label,
            \Taxmod\Core\Renderer\SelectMarkup::of(str_replace('taxmod_', '', $option), $options, (string) $now, false),
            $why
        );
    }

    /**
     * Was der Bildschirm **nur zeigt** — Schemafassung, Gerüste, Rahmen-Ids.
     *
     * ⚠️ **Drei Zeilen aus der Bauanleitung, und keine davon ist einstellbar**
     * ([D-703](../../../docs/NewConcept/90-decision-log.md)). *Sie standen bisher **nur** in
     * WordPress-Optionen: lesbar über die Datenbank, sonst nirgends. `node-binding-check` prüft die
     * Rahmen-Ids seit langem — **ein Mensch konnte sie nicht sehen**.*
     *
     * ⚠️ *Deshalb ein eigener Block unter der Tabelle und nicht in ihr: **was man ändern kann und was
     * man nur wissen kann, sind zwei Sorten**, und eine Tabelle, in der die halben Zeilen nichts
     * tun, lädt zum Klicken ein, wo es nichts zu klicken gibt.*
     */
    private function facts(): string
    {
        return '<h2>' . esc_html__('What this installation is made of', 'taxmod') . '</h2>'
            . '<table class="taxmod-config"><tbody>'
            . $this->schemaRow()
            . $this->scaffoldRow()
            . $this->frameworkRow()
            . '</tbody></table>';
    }

    /**
     * Welche Schemafassung liegt, und welche der Kode erwartet.
     *
     * ⚠️ **Die Fassung ist der Wächter des Aufstiegs** (`CD-6`): *`Schema::install()` läuft nur,
     * wenn beide Zahlen auseinandergehen. **Gehen sie auseinander und es passiert nichts, ist das
     * genau die Lage, in der niemand nachsieht** — weil man sie nicht sehen konnte.*
     */
    private function schemaRow(): string
    {
        $liegt   = (int) get_option(Schema::VERSION_OPTION, 0);
        $erwartet = Schema::VERSION;

        return $this->row(
            __('Schema version', 'taxmod'),
            '<code>' . (int) $liegt . '</code>'
                . ($liegt === $erwartet
                    ? ''
                    : ' <strong>' . esc_html(sprintf(
                        /* translators: %d: the schema version the code expects. */
                        __('— the code expects %d', 'taxmod'),
                        $erwartet
                    )) . '</strong>'),
            $liegt === $erwartet
                ? __('The database matches the code. An upgrade runs when these two differ.', 'taxmod')
                : __('These two differ, which means an upgrade has not run. Deactivating and activating the plugin runs it.', 'taxmod')
        );
    }

    /**
     * Welche Gerüste liefen, in welcher Fassung.
     *
     * ⚠️ *Nach dem Import ist Gesätes gewöhnlicher Inhalt ([D-119](../../../docs/NewConcept/90-decision-log.md))
     * — diese Zeile sagt also nicht, was im Baum steht, sondern **welcher Lauf ihn einmal angelegt
     * hat**. Das ist der Unterschied zwischen einer Herkunft und einem Besitz.*
     */
    private function scaffoldRow(): string
    {
        $liste = [];

        foreach (self::SCAFFOLDS as $option => $name) {
            $fassung  = (int) get_option($option, 0);
            $liste[] = esc_html($name) . ' <code>' . ($fassung === 0 ? '—' : (int) $fassung) . '</code>';
        }

        return $this->row(
            __('Seeded scaffolds', 'taxmod'),
            implode('<br>', $liste),
            __('Which scaffolding runs have built this tree, and at which version. A dash means the run has never happened here — on an installation seeded from the image that is the normal case, because the image already brought everything.', 'taxmod')
        );
    }

    /**
     * Die Rahmen-Ids, die alles zusammenhalten.
     *
     * ⚠️ **Gebunden wird über die Id und nicht über den Namen**
     * ([D-510](../../../docs/NewConcept/90-decision-log.md)) — *das ist der Grund, warum es diese
     * Optionen gibt, und warum eine Umbenennung im Baum nichts zerbricht.*
     *
     * ⚠️ *Nur lesbar, ausdrücklich: **wer eine davon von Hand verstellt, hängt den Baum aus.** Der
     * Wächter bleibt `node-binding-check`; diese Zeile macht ihn nur sichtbar.*
     */
    private function frameworkRow(): string
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}"
            . " WHERE option_name LIKE 'taxmod\_%\_id' ORDER BY option_name",
            ARRAY_A
        );

        $liste = [];

        foreach ((array) $rows as $eine) {
            $liste[] = '<code>' . esc_html((string) $eine['option_name']) . '</code> '
                . (int) $eine['option_value'];
        }

        return $this->row(
            __('Framework ids', 'taxmod'),
            $liste === []
                ? '<em>' . esc_html__('none', 'taxmod') . '</em>'
                : implode('<br>', $liste),
            __('The nodes everything else is bound to: the root, the trash, the branches and the roles. They are bound by id and not by name, which is why renaming any of them in the tree breaks nothing. Reading only — changing one by hand unhooks the tree.', 'taxmod')
        );
    }

    /**
     * Ob ein Schiebeschalter angehakt zurückkam.
     *
     * ⚠️ **Nach dem Wert und nicht nach dem Dasein**
     * ([D-706](../../../docs/NewConcept/90-decision-log.md)). *Der Schalter schickt eine verborgene
     * `0` vor sich her, damit «aus» **falsch** heisst und nicht «nicht beantwortet»
     * ([D-315](../../../docs/NewConcept/90-decision-log.md), [D-232](../../../docs/NewConcept/90-decision-log.md)).
     * **Damit ist das Feld immer gesetzt**, und ein `isset()` liest jedes Ausschalten als
     * Einschalten. PHP behält bei zwei gleichen Namen den letzten — steht das Kästchen an, kommt
     * `1`, sonst bleibt die `0` stehen.*
     *
     * ⚠️ *Die einzige Stelle, die diese Frage stellt: **eine zweite könnte sie anders stellen**, und
     * genau das war der Fehler — der Umbau auf den Schalter ging durch drei Aufrufe und die Frage
     * wanderte in keinem mit.*
     */
    private static function angehakt(string $field): bool
    {
        return isset($_POST[$field]) && sanitize_text_field(wp_unslash((string) $_POST[$field])) === '1';
    }

    /**
     * Take the form and store it.
     *
     * ⚠️ **`CD-5` in order and no exception for an admin-only screen**: capability, nonce, validate,
     * sanitise, act. *A size arrives as characters and is checked against the offered list rather than
     * cast — `(int) 'huge'` is `0`, and a zero that arrived that way would silently make the tree
     * invisible.*
     */
    public function handlePost(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot change these.', 'taxmod'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION, '_taxmod_nonce');

        // ⚠️ **Nach dem *Wert* gefragt und nicht nach dem *Dasein***
        // ([D-706](../../../docs/NewConcept/90-decision-log.md)). *Hier stand `isset()`, und mit dem
        // Schiebeschalter aus [D-695](../../../docs/NewConcept/90-decision-log.md) war das **immer
        // wahr**: der Schalter schickt eine verborgene `0` vor sich her, damit «aus» auch «aus»
        // heisst — also ist das Feld **immer** gesetzt, und `isset()` las jedes Ausschalten als
        // Einschalten.* **Sein Befund:** *«kann write counts zwar ausschalten aber nach save wieder
        // alter wert».*
        //
        // ⚠️ **Es traf alle drei Schalter, nicht nur seinen** — *auch der Entwicklermodus selbst und
        // der Papierkorb liessen sich nicht mehr ausschalten. **Ein Kästchen fragt man nach dem
        // Dasein, einen Schiebeschalter nach dem Wert**, und beim Umbau auf den Schalter ist die
        // Frage nicht mitgewandert.*
        update_option(NodesScreen::DEVELOPER_OPTION, self::angehakt('developer') ? '1' : '0', true);
        update_option(self::SHOW_TRASH, self::angehakt('show_trash') ? '1' : '0', true);

        // ⚠️ **Nur, wenn das Formular die Zeilen auch trug** ([D-705](../../../docs/NewConcept/90-decision-log.md)).
        // *Sie stehen nur da, während der Modus an ist — **ohne diesen Merker könnte das Speichern
        // nicht unterscheiden, ob ein Haken abgewählt wurde oder ob die Zeile gar nicht dastand**,
        // und ein Formular ohne sie löschte alle vier.*
        if (isset($_POST['dev_details'])) {
            foreach (self::DEVELOPER_DETAILS as $option => $field) {
                update_option($option, self::angehakt($field) ? '1' : '0', true);
            }
        }

        $locale = isset($_POST['neutral_locale'])
            ? sanitize_text_field(wp_unslash($_POST['neutral_locale']))
            : '';

        // ⚠️ Checked against what is installed, not merely sanitised: a locale nobody has would make
        // every label fall through to the node name with no way to see why.
        if ($locale !== '' && ($locale === get_locale() || in_array($locale, get_available_languages(), true))) {
            update_option(self::NEUTRAL_LOCALE, $locale, true);
        }

        foreach ([self::ICON_SIZE => 'icon_size', self::FONT_SIZE => 'font_size'] as $option => $field) {
            $asked = isset($_POST[$field]) ? absint($_POST[$field]) : 0;

            if (in_array($asked, self::SIZES, true)) {
                update_option($option, $asked, true);
            }
        }

        $jeSeite = isset($_POST['records_per_page']) ? absint($_POST['records_per_page']) : 0;

        if (in_array($jeSeite, self::PAGE_SIZES, true)) {
            update_option(self::RECORDS_PER_PAGE, $jeSeite, true);
        }

        $baumKlick = isset($_POST['tree_click']) ? sanitize_key(wp_unslash((string) $_POST['tree_click'])) : '';

        if (in_array($baumKlick, ['stay', 'jump'], true)) {
            update_option(self::TREE_CLICK, $baumKlick, true);
        }

        $selbst = isset($_POST['autosave']) ? sanitize_key(wp_unslash((string) $_POST['autosave'])) : '';

        if (in_array($selbst, ['on', 'off'], true)) {
            update_option(self::AUTOSAVE, $selbst, true);
        }

        wp_safe_redirect(add_query_arg(
            ['page' => 'taxmod-settings', 'taxmod_saved' => '1'],
            admin_url('admin.php')
        ));

        exit;
    }

    private function notice(): string
    {
        if (! isset($_GET['taxmod_saved'])) {
            return '';
        }

        return '<div class="notice notice-success is-dismissible"><p>'
            . esc_html__('Saved.', 'taxmod') . '</p></div>';
    }

    /**
     * Which language stands for *everywhere*.
     *
     * ⚠️ **The site's own language until somebody declares one**, which is what the interim in
     * `NodesScreen` did — so nothing has to be migrated: an installation that never opens this screen
     * behaves exactly as it did.
     */
    public static function neutralLocale(): string
    {
        $declared = (string) get_option(self::NEUTRAL_LOCALE, '');

        if ($declared !== '') {
            return $declared;
        }

        $site = get_locale();

        return $site === '' ? 'en_US' : $site;
    }

    /**
     * Welche Sprache gerade **gewählt** ist — und die Standardsprache, wo keine gewählt ist.
     *
     * ⚠️ **Ein Umstand und keine Einstellung** ([D-389](../../../docs/NewConcept/90-decision-log.md)):
     * *er reist in der Adresse (`taxmod_locale`), so wie der Faltzustand. Er wird nirgends gespeichert
     * — wer die Seite ohne ihn aufruft, arbeitet in der Standardsprache.*
     *
     * ⚠️ **An **einer** Stelle gelesen, aus demselben Grund, aus dem
     * {@see self::neutralLocale()} an einer steht** ([D-387](../../../docs/NewConcept/90-decision-log.md)):
     * *zwei Leser desselben Umstands beantworten ihn irgendwann verschieden — und dann liest der
     * Bildschirm eine Sprache und der Speicher schreibt eine andere. Genau das war TASK-061.*
     */
    public static function requestedLocale(): string
    {
        $asked = isset($_GET['taxmod_locale'])
            ? sanitize_text_field(wp_unslash((string) $_GET['taxmod_locale']))
            : '';

        return $asked === '' ? self::neutralLocale() : $asked;
    }

    public static function inDeveloperMode(): bool
    {
        return (bool) get_option(NodesScreen::DEVELOPER_OPTION, false);
    }

    /**
     * ⚠️ **`'1'` als Vorgabe und nicht `false`** — eine Option, die es noch nie gab, muss
     * *sichtbar* heissen ([D-693](../../../docs/NewConcept/90-decision-log.md)). *Deshalb wird beim
     * Speichern ausdrücklich `'0'` geschrieben statt der Wert gelöscht: «aus» und «nie gesetzt»
     * sähen sonst gleich aus und bedeuteten das Gegenteil.*
     */
    /** Die gewählte Seitengrösse; was keine der vier ist, zählt als fünf (D-763). */
    public static function recordsPerPage(): int
    {
        $size = (int) get_option(self::RECORDS_PER_PAGE, 5);

        return in_array($size, self::PAGE_SIZES, true) ? $size : 5;
    }

    /** Ob beim Verlassen eines Feldes gespeichert wird; Vorgabe ist «ja» ([D-854](../../../docs/NewConcept/90-decision-log.md)). */
    public static function autosaves(): bool
    {
        return get_option(self::AUTOSAVE, 'on') !== 'off';
    }

    /** Ob ein Klick im Baum nach oben springt; Vorgabe ist «bleiben» ([D-807](../../../docs/NewConcept/90-decision-log.md)). */
    public static function treeClickJumps(): bool
    {
        return get_option(self::TREE_CLICK, 'stay') === 'jump';
    }

    public static function showsTrash(): bool
    {
        return get_option(self::SHOW_TRASH, '1') !== '0';
    }

    public static function defaultIconSize(): int
    {
        $size = (int) get_option(self::ICON_SIZE, 17);

        return in_array($size, self::SIZES, true) ? $size : 17;
    }

    public static function defaultFontSize(): int
    {
        $size = (int) get_option(self::FONT_SIZE, 15);

        return in_array($size, self::SIZES, true) ? $size : 15;
    }
}
