<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\RelationKind;

/**
 * Die Tabellen des Modells (D-083), auf der Aktivierung angelegt und von einer gespeicherten
 * Schemafassung geführt.
 *
 * ⚠️ **Seven, and no table per model** — the model *is* the schema (D-066). A per-model
 * projection may exist later, but only as a rebuildable cache (D-228), never as a place where
 * anything is kept.
 *
 * ```mermaid
 * flowchart TD
 *   N[(nodes)] --> L[(labels)]
 *   N --> R[(relations)]
 *   N --> RC[(records)]
 *   R --> RV[(record_values)]
 *   RC --> RV
 * ```
 *
 * ⚠️ **Jede Tabelle vergibt ihre Ids selbst** (TASK-004,
 * [`package.md` §6](../../../docs/pakete/modelltabellen/package.md)). *`identities` ist gestrichen;
 * eine Nummer ist nur noch innerhalb ihrer Tabelle eindeutig, und wo eine Spalte auf mehr als eine
 * Tabelle zeigen kann, nennt eine zweite Spalte den Raum — `changelog.owner_kind`,
 * `record_values.value_ref_kind`.*
 *
 * `labels` und `changelog` hängen an einer **Nummer**, nicht an einem Knoten — das ist es, was
 * eine *Kante* eigene Labels tragen lässt (C8). Darum ist `owner_id` eine Spalte und nicht eine Art
 * plus eine Id.
 *
 * ⚠️ **Und genau darum trägt `changelog` die Spalte `owner_kind`.** *`labels.owner_id` zeigt
 * gemessen auf Knoten und nur auf Knoten (47 von 47), und [`id-space-check.php`](../../../scripts/dev/id-space-check.php)
 * hält das lesend fest.*
 *
 * ⚠️ *Die dritte solche Tabelle war `settings`, und sie **mischte** — gemessen 3 Knoten und 10
 * Kanten, ohne dass etwas sagte welches (`INF-009`). **Sie ist mit [D-579](../../../docs/NewConcept/90-decision-log.md)
 * gestrichen**, und der Behelf, der die beiden Räume auseinanderhielt, mit ihr.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class Schema
{
    /**
     * Raise this whenever a table definition below changes. The stored value is compared on
     * every load, so an upgrade is deterministic rather than a matter of when somebody last
     * deactivated the plugin (`CD-6`).
     *
     * 1 — the seven tables, ids from a counter in `wp_options`.
     * 2 — `identities` takes over id allocation and `owner_id` becomes a foreign key (D-339);
     *     `settings.key` becomes `setting_key`, because `KEY` is reserved and dbDelta cannot
     *     parse an index over a backticked column — the same reason `before` became
     *     `before_state`.
     * 3 — inheritance edges become the tree and `nodes.path` is derived from them (D-014);
     *     `relations.from_id` and `to_id` join the foreign keys.
     * 4 — the changelog gains `change_group_id`, the bracket around one act (D-348).
     * 5 — the three branches are seeded as framework nodes; no table changed, but the version
     *     moves so that an already-installed copy gets them (D-161).
     * 6 — the label roles are seeded as nodes under their own container (D-151); again no
     *     table changed, and again the version is what carries them to an installed copy.
     * 7 — `relations.parked_by_group_id`, so an attribute can be removed at all (D-371).
     * 8 — `settings.path`, the address a setting needs to say **which** place it answers for
     *     (OQ-092). The unique key becomes `(owner_id, setting_key, path)`; an empty path means
     *     the owner itself, so every existing row keeps its meaning untouched. **Four decisions
     *     had assumed this column existed** — D-236, D-158, C30 and D-378 — and the last of them
     *     was measured on 2026-08-26 to be written and not functioning because of it.
     * 9 — `records.model_id` becomes `node_id` and `model_version` becomes `node_version`
     *     (D-441). **A rename, not a change**: no row moves. The index comes with the column,
     *     because `dbDelta` has no notion of a rename and would build a second one beside it.
     *     *The old name was measured to mislead: it pointed into `Compositions` for 21 of 24
     *     records while `Model` is also the name of a branch in the tree.*
     * 10 — `nodes.hide` and `relations.hide`, and the `hide` **setting** goes away entirely
     *      (D-426, D-457). *A column is **not in the chain**, which is the whole point: as a
     *      setting, `hide` on a type blanked every field of that type, because an attribute's
     *      chain contains its target node. Measured twice — by experiment on 2026-08-26 and
     *      again on 2026-08-27.* **Both node and edge, because the owner asked for both**:
     *      «edge and node both having an attribute `hide`».
     * 11 — `range_min`, `range_max` and `range_step` become `min`, `max` and `step` (D-466),
     *      on the owner: «shorter, we do not need the range». **16 rows, a rename and nothing
     *      else.** *The new names were free — a collision would have hit the unique key and
     *      failed loudly, which is the good failure.*
     * 12 — `nodes.hide` goes; `hide` lives on the **edge** alone (D-467). *The owner narrowed
     *      schema 10 once the access he thought was missing turned out to exist: «then we only
     *      need it on the edge». **Hiding is about a placement**, and a node-level flag had no
     *      use case behind it — «I do not simply create a model node and then say I will not
     *      draw it, that would be nonsense».* All 7 values travel to their inheritance edge.
     * 13 — `records.is_test`, das Kennzeichen für Testdaten (D-028). ⚠️ **Es stand seit dem
     *      22.08. im Konzept und war nie gebaut**: «Testdaten sind gewöhnliche Daten,
     *      gekennzeichnet … kein eigener Testdaten-Speicher und keine dritte Art von Ding».
     *      *Die Tabelle hatte vier Spalten, die fünfte stand nur im Text — gefunden beim
     *      Nachziehen der Doku, nicht durch einen Test.* **Ein Zusatz und keine Wanderung:**
     *      `dbDelta` legt eine fehlende Spalte selbst an, und `DEFAULT 0` heisst, dass jeder
     *      vorhandene Datensatz **echte Daten** bleibt. *Ein Index darauf, weil die Vorschau
     *      genau danach filtert und sonst über alle Datensätze eines Knotens liefe.*
     */
    /**
     * Schema 14: `nodes.kind` — was Felder halten, die auf einen Knoten zeigen
     * ([D-518](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Kein Wanderungsschritt, und das ist kein Versehen.** *Die Spalte ist nullbar, `dbDelta`
     * fügt sie selbst hinzu, und `null` heisst «frag meine Vorfahren» — **für alle 124 bestehenden
     * Knoten ist das die richtige Antwort**, weil keiner heute eine Einstellung ist. Eine Vorgabe, die
     * für alles Bestehende stimmt, braucht nichts zu wandern.*
     *
     * ⚠️ *`nodes` hatte schon einmal eine solche Spalte — `hide`, in Schema 12 wieder entfernt
     * ([D-467](../../../docs/NewConcept/90-decision-log.md)), weil Verbergen **eine Stelle** meint und
     * keinen Knoten. **Hier ist es umgekehrt**: was ein Wert ist, hängt am Knoten und nicht daran, wo
     * er gerade benutzt wird.*
     */
    /**
     * Schema 15: `records.kind` löst `records.is_test` ab
     * ([D-521](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Ersetzen und nicht danebenstellen**, auf sein Wort. *Zwei Spalten, die beide «diese Zeile
     * ist besonders» sagen, laufen auseinander — und `is_test` war schon die richtige Form am zu
     * kleinen Platz: **ein `bool` hält drei Zustände nicht.***
     *
     * ⚠️ *Die Wanderung ist eine reine Umschrift: gemessen tragen **29 von 29** Zeilen `is_test = 0`,
     * werden also `user`. **Es gibt nichts zu verlieren, und der Schritt schreibt trotzdem
     * `is_test = 1` nach `test`**, weil eine Wanderung, die nur den gemessenen Fall kann, auf der
     * nächsten Installation falsch ist.*
     */
    /**
     * Schema 20: `record_values.value_ref_kind` — der Verweis nennt seinen Raum (TASK-005,
     * [D-164](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Der Grund ist TASK-004 und nicht ein Fehler von heute.** *Solange jede Id aus `identities`
     * kommt, ist eine Nummer für sich eindeutig — gemessen am 2026-09-04: **143 Verweise, davon 50 auf
     * Knoten und 93 auf Datensätze, keine einzige Überschneidung**. Sobald jede Tabelle ihren eigenen
     * Id-Raum bekommt, gibt es Knoten 5 und Datensatz 5, und dieselbe Spalte wäre nicht mehr lesbar.
     * **Deshalb steht die Spalte vor dem Umbau da, nicht danach.***
     *
     * ⚠️ *Das Muster ist `changelog.owner_kind`, wie [`package.md` §6](../../../docs/pakete/modelltabellen/package.md)
     * es verlangt: «Kann eine Spalte auf mehr als eine Tabelle zeigen, nennt eine zweite Spalte den Raum.»*
     *
     * ⚠️ **Der Schatten bekommt die Spalte, wird aber nur teilweise gefüllt, und das ist gemessen:**
     * *von 810 Verweisen der Schattentabelle lösen sich 393 eindeutig auf einen Knoten und 58 auf einen
     * Datensatz auf; **2 sind mehrdeutig und 357 zeigen auf nichts Lebendes mehr**. Zieht man die
     * Schattentabellen als Nachschlagewerk hinzu, wird es schlimmer statt besser — 126 mehrdeutig.
     * **Was sich nicht eindeutig ermitteln lässt, bleibt `null`**, statt geraten zu werden; der Wächter
     * verlangt die Angabe deshalb nur von den lebenden Zeilen.*
     */
    /**
     * Schema 21: **jede Tabelle hat ihren eigenen Id-Raum, `identities` ist gestrichen** (TASK-004,
     * [`package.md` §6](../../../docs/pakete/modelltabellen/package.md)).
     *
     * ⚠️ **Der Eigentümer wörtlich:** *«jede Tabelle bekommt ihren eigenen Id-Raum … Records hatten
     * dann einen zweiten Nummernraum, das eliminieren wir jetzt.»*
     *
     * ⚠️ **`AUTO_INCREMENT` und kein eigener Zähler, und der Grund ist der von
     * [D-339](../../../docs/NewConcept/90-decision-log.md):** *ein Zähler, der neben den Daten wohnt,
     * kann hinter sie zurückfallen und eine Nummer ein zweites Mal vergeben — das war der Fehler, den
     * `identities` damals geheilt hat. **Der Zähler einer Tabelle kann von ihren eigenen Zeilen nicht
     * abweichen**, und InnoDB senkt ihn beim Löschen nicht, womit [D-340](../../../docs/NewConcept/90-decision-log.md)s
     * «nie wieder dieselbe Nummer» je Raum weiter gilt.*
     *
     * ⚠️ **Es wird nichts umnummeriert.** *Die Räume beginnen dort, wo der gemeinsame aufgehört hat —
     * gemessen am 2026-09-04 bei `identities` 79 755, also `AUTO_INCREMENT = 79 756` für `nodes` und
     * `relations`. **Damit kann keine neue Zeile eine Nummer bekommen, die im gemeinsamen Raum schon
     * einmal vergeben war**, auch nicht die von etwas längst Gelöschtem, das nur noch im Schatten und
     * im Änderungsbuch steht.*
     *
     * ⚠️ **Die sieben Fremdschlüssel auf `identities.id` fallen ersatzlos, und das ist eine
     * Entscheidung und keine Nachlässigkeit** (`PR-9`): *`relations.from_id`/`to_id` bekommen ihre
     * Bedingung auf `nodes.id` in **TASK-010**, wo auch die Umbenennung steht; sie hier zu setzen
     * hiesse, `ON DELETE RESTRICT` gegen die bestehenden Aufräumwege laufen zu lassen, ohne dass
     * jemand deren Reihenfolge geprüft hat. **Bis dahin hält
     * [`id-space-check.php`](../../../scripts/dev/id-space-check.php) dieselbe Zusage lesend** —
     * gemessen vor dem Umbau: 0 Waisen in allen sieben Spalten.*
     *
     * ⚠️ *Die Installationsidentität hat keinen Raum mehr, aus dem sie ziehen könnte. Auf einer
     * bestehenden Installation bleibt ihre Nummer stehen; auf einer frischen ist `1` reserviert, und
     * `nodes` wie `relations` beginnen dort bei `2`. **Wo sie künftig wohnen soll, ist eine Frage an
     * den Eigentümer** ([`inbox.md`](../../../docs/pakete/modelltabellen/inbox.md) `INF-008`).*
     */
    public const VERSION = 22;

    public const VERSION_OPTION = 'taxmod_schema_version';

    /** The counter version 1 allocated from. Read once during the upgrade, then removed. */
    private const RETIRED_COUNTER_OPTION = 'taxmod_model_last_id';

    /**
     * Die sieben Spalten, die bis Fassung 20 einen Fremdschlüssel auf `identities.id` trugen.
     *
     * ⚠️ **Sie bleibt als Liste stehen, weil die Bedingungen abgeräumt werden müssen** (TASK-004).
     * *`dbDelta` kennt keine Fremdschlüssel und würde keinen davon entfernen; ohne diese Liste bliebe
     * eine Bedingung auf eine Tabelle zeigen, die es nicht mehr gibt, und `DROP TABLE identities`
     * schlüge fehl — still, wie `$wpdb` es tut.*
     */
    private const RETIRED_IDENTITY_REFERENCES = [
        ['nodes', 'id'],
        ['relations', 'id'],
        ['relations', 'from_id'],
        ['relations', 'to_id'],
        ['settings', 'owner_id'],
        ['labels', 'owner_id'],
        ['changelog', 'owner_id'],
    ];

    /**
     * Die Tabellen, die ihre Ids seit Fassung 21 aus ihrem eigenen `AUTO_INCREMENT` vergeben und
     * es bis dahin nicht taten.
     *
     * ⚠️ *`records`, `record_values`, `labels`, `settings` und `changelog` hatten immer schon ihr
     * eigenes; nur diese beiden zogen aus `identities`.*
     */
    private const OWN_ID_SPACE = ['nodes', 'relations'];

    /**
     * Die reservierte Nummer der Installationsidentität auf einer **frischen** Installation.
     *
     * ⚠️ *Sie ist weder Knoten noch Kante und hat seit TASK-004 keinen Raum mehr, aus dem sie ziehen
     * könnte. Damit sie mit keiner Knoten- oder Kanten-Id zusammenfällt, beginnen beide Räume auf
     * einer frischen Installation bei `2`. **Auf einer bestehenden bleibt die alte Nummer stehen** —
     * es wird nichts umnummeriert. Siehe `INF-008` in
     * [`inbox.md`](../../../docs/pakete/modelltabellen/inbox.md).*
     */
    private const RESERVED_INSTALLATION_ID = 1;

    /*
     * Hier stand `RELATION_SPACE_OFFSET` — der Kantenraum begann eine Milliarde ueber dem
     * Knotenraum, weil `settings.owner_id` ihren Raum nicht nannte und `Settings` deshalb eine
     * Kante fuer einen Knoten halten konnte (`INF-009`).
     *
     * **Der Behelf faellt mit der Tabelle** (D-579): es gibt keine Spalte mehr, die zwischen Knoten
     * und Kante nicht unterscheidet, und damit keinen Grund fuer den Abstand. *Beide Raeume beginnen
     * wieder dort, wo der gemeinsame aufgehoert hat — genau das, was der Eigentuemer verlangt hatte.
     * **Umnummeriert wird nichts**: eine bestehende Installation behaelt den Zaehlerstand, den sie
     * hat, denn `AUTO_INCREMENT` laesst sich nicht nach unten setzen (D-340).*
     */

    /**
     * Die Tabellen, deren Geschichte aufgehoben wird — und der Name ihres Schattens.
     *
     * ⚠️ **Eine Liste und nicht acht verstreute Namen** ([D-537](../../../docs/NewConcept/90-decision-log.md)):
     * *`scripts/dev/shadow-shape-check.php` läuft genau über sie und vergleicht die Spalten. **Wer eine
     * Spalte an einer lebenden Tabelle hinzufügt und den Schatten vergisst, wird beim nächsten Prüflauf
     * rot** — das war der Preis, den zwei Tabellen derselben Form kosten, und das ist sein Wächter.*
     */
    public const LIVE_TABLES = ['nodes', 'relations', 'records', 'record_values'];

    /** Ihre Schatten, **in derselben Reihenfolge** — darauf verlässt sich die Prüfung. */
    public const SHADOW_TABLES = ['nodes_history', 'relations_history', 'records_history', 'record_values_history'];

    /** Spalten, die **nur** der Schatten hat und die die Prüfung deshalb übergeht. */
    public const SHADOW_ONLY = ['deleted', 'archived_at'];

    /** @return list<string> The table names, without the WordPress prefix. */
    public static function tableNames(): array
    {
        return ['labels', 'changelog', ...self::LIVE_TABLES, ...self::SHADOW_TABLES];
    }

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'taxmod_' . $name;
    }

    /** Create or upgrade the tables, but only when the stored version is behind. */
    public static function ensureCurrent(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) === self::VERSION) {
            return;
        }

        self::install();
        update_option(self::VERSION_OPTION, self::VERSION, true);
    }

    public static function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ⚠️ **Before `dbDelta`, and that order is the whole trick.** *`dbDelta` compares a table
        // against a `CREATE TABLE` and **adds** what is missing — it has no notion of a rename. Run
        // afterwards, it would create `node_id` beside `model_id` and leave both, with the data in
        // the one nothing reads any more.*
        self::renameRecordColumns();

        // ⚠️ **Ebenfalls vor `dbDelta`, und aus demselben Grund wie die Umbenennung darüber**
        // (TASK-004): *solange die Bedingungen auf `identities` stehen, kann keine der beiden
        // Id-Spalten zu `AUTO_INCREMENT` werden — MySQL weist die Änderung an einer gebundenen
        // Spalte zurück, und `$wpdb` sagt darüber nichts.*
        self::dropIdentityForeignKeys();

        foreach (self::statements() as $sql) {
            dbDelta($sql);
        }

        self::giveEveryTableItsOwnIdSpace();
        self::backfillInheritanceEdges();
        self::dropRetiredColumns();
        self::widenSettingUniqueKey();
        self::moveHideOutOfSettings();
        self::shortenRangeKeys();
        self::moveHideOntoTheEdge();
        self::moveTestFlagIntoKind();
        self::dropTheOneValueKey();
        self::moveMultiplicityOntoTheEdge();
        self::nameTheReferenceSpace();
        self::dropIdentitiesTable();
        self::dropSettingsTable();
    }

    /**
     * Fassung 22: `settings` fällt — die zweite Ablage für eine Kantenart.
     *
     * ⚠️ **[D-579](../../../docs/NewConcept/90-decision-log.md), auf sein Wort «settings bitte
     * rausschmeissen» und, zur Abwägung Umzug gegen Neueingabe, «B».** *Gemessen vor dem Streichen:
     * **13 Zeilen** — dreimal `label_role = symbol` an den Kanten `prefix`, `prefix (Kopie)` und
     * `einheit`, dazu zehnmal `read_only`. **Sie gehen verloren und werden später neu eingegeben**;
     * die sichtbare Folge ist vorher benannt: bis `label_role` seinen neuen Ort hat (`OQ-134`) zeigt
     * `Einheitenwert` «Kiloohm» statt «kΩ».*
     *
     * ⚠️ *Zuletzt im Lauf, wie `identities`: die älteren Schritte darüber lesen die Tabelle noch
     * (`moveHideOutOfSettings()`, `moveMultiplicityOntoTheEdge()`), und jeder von ihnen prüft mit
     * `SHOW TABLES`, ob es sie gibt. **Eine Installation, die von Fassung 9 kommt, wandert also
     * vollständig, bevor hier gelöscht wird.***
     */
    private static function dropSettingsTable(): void
    {
        global $wpdb;

        $tabelle = self::table('settings');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tabelle)) !== $tabelle) {
            return;
        }

        $wpdb->query("DROP TABLE {$tabelle}");
    }

    /**
     * `nodes.hide` goes; `hide` lives on the **edge** alone — schema 12.
     *
     * ⚠️ **The owner narrowed it once the access he thought was missing turned out to exist.** *He had
     * said «we have no access to the inheritance edge at the moment, that is my problem here» — and
     * measured, {@see \Taxmod\Core\Repository\RelationRepository::inheritanceEdgeTo()} is there with an
     * implementation. On being shown that: **«then we only need it on the edge».***
     *
     * ⚠️ **And his reason is better than the mechanism.** *«I do not simply create a model node and then
     * say I will not draw it — that would be nonsense. Where I would say it is on the **fields** of a
     * model node, when I only want something in the background, to calculate with.» So hiding is about
     * a **placement**, and a node-level flag had no use case behind it — [D-457](../../../docs/NewConcept/90-decision-log.md)
     * gave it one on my reading of «both», not on his.*
     *
     * ⚠️ *Every one of the 7 hidden nodes has exactly one inheritance edge to travel to — measured
     * before writing this, 7 of 7. **The root has none and can therefore never be hidden**, which is
     * correct rather than a gap: it is machinery ([D-194](../../../docs/NewConcept/90-decision-log.md)).*
     */
    private static function moveHideOntoTheEdge(): void
    {
        global $wpdb;

        $nodes = self::table('nodes');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $nodes)) !== $nodes) {
            return;
        }

        $present = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $nodes,
            'hide'
        ));

        if ($present === []) {
            return;
        }

        $relations = self::table('relations');

        // ⚠️ *The value travels **before** the column goes, and the join is the inheritance edge —
        // which is the one thing that puts a node in the tree ([D-014]).*
        $wpdb->query(
            "UPDATE {$relations} r
             JOIN {$nodes} n ON n.id = r.to_id AND r.kind = 'inheritance'
             SET r.hide = 1
             WHERE n.hide = 1"
        );

        $wpdb->query("ALTER TABLE {$nodes} DROP COLUMN hide");
    }

    /**
     * `range_min`, `range_max` and `range_step` become `min`, `max` and `step` — schema 11.
     *
     * ⚠️ **A rename of stored keys, on the owner's word**: *«min, max and step — shorter, we do not
     * need the range».* *16 rows carry the old names today.*
     *
     * ⚠️ **Safe because the new names were free.** *`min`, `max` and `step` are not among the engine's
     * other keys and no free key uses them — checked before writing. **A collision would have hit the
     * unique key `(owner_id, setting_key, path)` and failed loudly**, which is the good failure; the
     * bad one would be two meanings under one name.*
     *
     * ⚠️ *Idempotent by construction: the second run finds no `range_%` rows and updates nothing.*
     */
    private static function shortenRangeKeys(): void
    {
        global $wpdb;

        $settings = self::table('settings');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $settings)) !== $settings) {
            return;
        }

        foreach (['range_min' => 'min', 'range_max' => 'max', 'range_step' => 'step'] as $from => $to) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$settings} SET setting_key = %s WHERE setting_key = %s",
                $to,
                $from
            ));
        }
    }

    /**
     * `hide` moves from the settings table into two columns — schema 10.
     *
     * ⚠️ **The point is that a column is *not in the chain*.** *[OQ-101](../../../docs/NewConcept/91-open-questions.md)
     * established it by experiment and 2026-08-27 reproduced it: `hide` as a **setting** on a type
     * blanked **every field of that type**, because an attribute's chain contains its target node.
     * [D-426](../../../docs/NewConcept/90-decision-log.md): «a column is not in the chain, so the two can
     * no longer reach each other **by construction** rather than by a rule somebody has to remember.»*
     *
     * ⚠️ **Both tables, because the owner asked for both** ([D-457](../../../docs/NewConcept/90-decision-log.md)):
     * *«edge and node both having an attribute `hide`»* — a node hides itself, a placement hides what
     * hangs there.
     *
     * ⚠️ **After `dbDelta`, unlike the rename in schema 9** — this one needs the columns to exist before
     * it can write into them, and `dbDelta` is what creates them. *The opposite order to
     * {@see self::renameRecordColumns()}, and for the opposite reason.*
     *
     * ⚠️ *Only `value_int = 1` travels. A `hide = 0` row says «not hidden», which is what the column
     * already defaults to — writing it would be copying a default into 109 rows.*
     */
    private static function moveHideOutOfSettings(): void
    {
        global $wpdb;

        $settings = self::table('settings');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $settings)) !== $settings) {
            return;
        }

        // Nothing to do on a fresh install, and a no-op on every activation after the first.
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$settings} WHERE setting_key = 'hide'") === 0) {
            return;
        }

        foreach (['nodes', 'relations'] as $name) {
            $table = self::table($name);

            $wpdb->query(
                "UPDATE {$table} t
                 JOIN {$settings} s ON s.owner_id = t.id AND s.setting_key = 'hide' AND s.value_int = 1
                 SET t.hide = 1"
            );
        }

        // ⚠️ *Deleted, not kept «just in case». The changelog is the record of what happened
        // ([D-061](../../../docs/NewConcept/90-decision-log.md)); a second copy in a table nothing
        // reads is the duplicated fact the standard forbids.*
        $wpdb->query("DELETE FROM {$settings} WHERE setting_key = 'hide'");
    }

    /**
     * `records.model_id` becomes `node_id`, `model_version` becomes `node_version` — schema 9.
     *
     * ⚠️ **A rename and nothing else: no row changes, no value moves.** [D-441](../../../docs/NewConcept/90-decision-log.md)
     * asked for it *because the old name lied* — measured, `model_id` pointed into `Compositions` for
     * **21 of 24** records and into `Model` for 3, while `Model` is simultaneously the name of a branch
     * in the tree. *A reader who knew the tree read it as «points into Model» and was wrong four times
     * out of five.*
     *
     * ⚠️ **The index has to come with the column, or `dbDelta` adds a second one.** *A renamed column
     * keeps its index, but the index keeps the **old name** — and `dbDelta`, comparing against a
     * definition that says `KEY node_id`, would helpfully create it. Two indexes over one column,
     * neither wrong, both there forever.*
     *
     * ⚠️ *Every step asks first. This runs on every activation, so it has to be a no-op the second
     * time — and on a fresh install the table does not exist at all yet, which is the first thing
     * checked.*
     */
    private static function renameRecordColumns(): void
    {
        global $wpdb;

        $table = self::table('records');

        // A fresh install: `dbDelta` will create the table with the new names in a moment.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $renames = [
            ['model_id', 'node_id', 'bigint(20) unsigned NOT NULL'],
            ['model_version', 'node_version', 'int(10) unsigned NOT NULL'],
        ];

        foreach ($renames as [$from, $to, $type]) {
            $present = $wpdb->get_col($wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                $from
            ));

            if ($present === []) {
                continue;
            }

            // ⚠️ `CHANGE` and not `RENAME COLUMN`: the latter wants MySQL 8, and this plugin does not
            // get to choose the server it lands on.
            $wpdb->query("ALTER TABLE {$table} CHANGE {$from} {$to} {$type}");
        }

        $index = $wpdb->get_col($wpdb->prepare(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $table,
            'model_id'
        ));

        if ($index !== []) {
            // Drop and recreate rather than `RENAME INDEX`, for the same portability reason.
            $wpdb->query("ALTER TABLE {$table} DROP INDEX model_id, ADD KEY node_id (node_id)");
        }
    }

    /**
     * Take `path` into the settings unique key — because `dbDelta` never touches an index it has
     * already created.
     *
     * ⚠️ **This is the half a schema bump does not do for you.** Adding the column worked on the
     * first run; the key stayed `(owner_id, setting_key)`, so a second row for the same key at a
     * different path would have been refused by a constraint nobody had noticed was still there.
     * *Measured before writing this: the column existed and `SHOW INDEX` still listed two columns.*
     *
     * ⚠️ **Safe in this direction and only in this direction.** The old key is **stricter** than the
     * new one, so no existing row can collide — widening a unique key can never fail on data that a
     * narrower one already accepted. *Narrowing one would be the opposite and would need the
     * duplicates found first.*
     */
    private static function widenSettingUniqueKey(): void
    {
        global $wpdb;

        $table = self::table('settings');

        $columns = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
             ORDER BY SEQ_IN_INDEX',
            $table,
            'owner_key'
        ));

        // Already three columns, or the index is not there at all on a fresh install where dbDelta
        // built it from the current definition.
        if ($columns === [] || in_array('path', $columns, true)) {
            return;
        }

        // ⚠️ **The new index goes in before the old one comes out, and that order is the whole
        // difference between working and silently doing nothing.** `settings.owner_id` carries a
        // foreign key to `identities` ([D-339]), and `owner_key` is an index MySQL can use to enforce
        // it — so `DROP INDEX` alone is **refused**, without an exception a caller would see.
        //
        // ⚠️ *Adding `owner_key_path` first gives the constraint a second index that also begins with
        // `owner_id`. The drop then succeeds, and the temporary name is renamed into place — which is
        // three statements to do one thing, and the reason is written here so nobody tidies it back
        // into one.*
        $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY owner_key_path (owner_id,setting_key,path)");

        if ($wpdb->last_error !== '') {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} DROP INDEX owner_key");
        $wpdb->query("ALTER TABLE {$table} RENAME INDEX owner_key_path TO owner_key");
    }

    /**
     * Give every node that has a parent the inheritance edge it should always have had.
     *
     * ⚠️ **Version 1 and 2 stored the tree only as `nodes.path`** — but D-014 calls the path
     * *derived, rebuildable, never a second truth*, and the truth it should derive from is the
     * inheritance edge. Until this ran, the derived value **was** the only truth, which is the
     * relation the concept forbids, the wrong way round.
     *
     * The parent is read back out of the path, which is exactly what the path is good for. It
     * is a one-time pass over the existing nodes, so the row-by-row insert is not the N+1 that
     * `CD-7` forbids — that rule is about the paths a person walks every day.
     */
    private static function backfillInheritanceEdges(): void
    {
        global $wpdb;

        $nodes     = self::table('nodes');
        $relations = self::table('relations');

        $orphans = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT n.id, n.path FROM {$nodes} n
                 LEFT JOIN {$relations} r ON r.to_id = n.id AND r.kind = %s
                 WHERE n.path LIKE %s AND r.id IS NULL
                 ORDER BY LENGTH(n.path) ASC, n.id ASC",
                RelationKind::Inheritance->value,
                '%.%'
            ),
            ARRAY_A
        );

        foreach ($orphans ?: [] as $row) {
            $segments = explode('.', (string) $row['path']);
            array_pop($segments);
            $parentId = (int) end($segments);

            $position = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(position) FROM {$relations} WHERE from_id = %d AND kind = %s",
                $parentId,
                RelationKind::Inheritance->value
            ));

            $wpdb->insert(
                $relations,
                // ⚠️ *Ohne `id`: seit TASK-004 vergibt `relations` sie selbst.*
                [
                    'version'  => 1,
                    'from_id'  => $parentId,
                    'to_id'    => (int) $row['id'],
                    'kind'     => RelationKind::Inheritance->value,
                    'name'     => '',
                    'position' => $position === null ? 0 : (int) $position + 1,
                ],
                ['%d', '%d', '%d', '%s', '%s', '%d']
            );
        }
    }

    /**
     * Remove columns a later version replaced. `dbDelta` never drops anything, so without this
     * an upgraded installation would carry both the old column and the new one, and nobody
     * reading the table could tell which one is true.
     *
     * ⚠️ **Dropping a column destroys what is in it.** That is only defensible here because
     * `settings.key` never shipped with data in it — version 1 created the table and nothing
     * ever wrote a row. **A future retirement that holds data must copy first and drop after.**
     */
    /**
     * `records.is_test` wird `records.kind` — Schema 15.
     *
     * ⚠️ **Nach `dbDelta`, weil die neue Spalte erst da sein muss, bevor etwas hineingeschrieben
     * wird** — dieselbe Reihenfolge, die {@see moveHideOntoTheEdge()} braucht. *`dbDelta` kennt kein
     * Umbenennen; es fügt hinzu, und dieser Schritt trägt den Inhalt hinüber.*
     *
     * ⚠️ **Und er ist zweimal ausführbar.** *Er läuft nur, solange die alte Spalte da ist, und die
     * fällt am Ende — also tut ein zweiter Lauf nichts. Das ist nötig, weil `install()` bei jeder
     * Aktivierung läuft.*
     */
    private static function moveTestFlagIntoKind(): void
    {
        global $wpdb;

        $records = self::table('records');

        $hatAlt = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $records,
            'is_test'
        ));

        if ($hatAlt !== 1) {
            return;
        }

        // ⚠️ *Nur die markierten Zeilen wandern; alle anderen tragen die Vorgabe `user` schon aus
        // der Spaltendefinition. **Gemessen waren das 29 von 29** — die Umschrift kostet hier nichts
        // und ist auf einer Installation mit Testdaten trotzdem richtig.*
        $wpdb->query("UPDATE {$records} SET kind = 'example' WHERE is_test = 1");

        $wpdb->query("ALTER TABLE {$records} DROP COLUMN is_test");
    }

    /**
     * Der eindeutige Schlüssel `(record_id, path, locale)` fällt — Schema 16,
     * [D-530](../../../docs/NewConcept/90-decision-log.md).
     *
     * ⚠️ **`dbDelta` kann einen Schlüssel nicht entfernen**, nur hinzufügen — also ausdrücklich, und
     * geprüft, ob es ihn überhaupt noch gibt. *Ohne diesen Schritt bliebe er auf jeder bestehenden
     * Installation stehen und verböte weiterhin, was D-530 gerade erlaubt.*
     *
     * ⚠️ *An seine Stelle tritt `of_field (record_id, edge_id, locale)` — **kein eindeutiger**, sondern
     * der Index für die Frage, die es jetzt gibt: «alle Werte dieses Feldes in diesem Datensatz».*
     */
    private static function dropTheOneValueKey(): void
    {
        global $wpdb;

        $tabelle = self::table('record_values');

        $vorhanden = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $tabelle,
            'one_value'
        ));

        if ($vorhanden === 0) {
            return;
        }

        $wpdb->query("ALTER TABLE {$tabelle} DROP INDEX one_value");
    }

    /**
     * `multiplicity` wandert aus `settings` an die Kante — Schema 16,
     * [D-528](../../../docs/NewConcept/90-decision-log.md).
     *
     * ⚠️ **Gemessen vor dem Umzug: 10 Zeilen, alle zehn an Kanten**, keine an einem Knoten. *Die
     * Auflösungskette hat für diesen Schlüssel nie etwas beigesteuert, was nicht schon an der Kante
     * stand — der Umzug ist deshalb eine Umschrift und kein Zusammenführen.*
     *
     * ⚠️ *Nur die vier gültigen Werte werden übernommen ([D-351](../../../docs/NewConcept/90-decision-log.md)).
     * Was etwas anderes sagt, behält die Spaltenvorgabe `1..1`, **die ohnehin das war, was eine
     * fehlende Setting-Zeile bedeutete** ([D-434](../../../docs/NewConcept/90-decision-log.md)).*
     */
    private static function moveMultiplicityOntoTheEdge(): void
    {
        global $wpdb;

        $settings  = self::table('settings');
        $relations = self::table('relations');

        // ⚠️ *Läuft nur, solange die alte Tabelle noch steht — nach ihrem Abbau ist der Schritt eine
        // stille Nulloperation statt eines Fehlers.*
        $steht = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $settings
        ));

        if ($steht === 0) {
            return;
        }

        $wpdb->query(
            "UPDATE {$relations} r
             JOIN {$settings} s ON s.owner_id = r.id AND s.setting_key = 'multiplicity' AND s.path = ''
             SET r.multiplicity = s.value_text
             WHERE s.value_text IN ('0..1', '1..1', '0..*', '1..*')"
        );
    }

    private static function dropRetiredColumns(): void
    {
        global $wpdb;

        $settings = self::table('settings');

        $hasOld = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $settings,
            'key'
        ));

        if ($hasOld === 1) {
            $wpdb->query("ALTER TABLE {$settings} DROP COLUMN `key`");
        }
    }

    /**
     * Schema 20: jede vorhandene Verweiszeile sagt nachträglich, in welchen Raum sie zeigt.
     *
     * ⚠️ **Das geht heute noch und nach TASK-004 nicht mehr** — genau deshalb steht dieser Schritt
     * jetzt: *solange alle Tabellen aus `identities` ziehen, ist eine Id für sich eindeutig, und ein
     * Blick in `nodes` und `records` entscheidet die Frage. Mit eigenen Id-Räumen wäre dieselbe
     * Wanderung nicht mehr möglich.*
     *
     * ⚠️ *Nur wo genau **eine** der beiden Tabellen die Nummer kennt, wird geschrieben. Mehrdeutiges
     * und Verwaistes bleibt `null` — in der lebenden Tabelle gemessen leer, in der Schattentabelle
     * nicht (siehe die Anmerkung an {@see self::VERSION}).*
     */
    private static function nameTheReferenceSpace(): void
    {
        global $wpdb;

        $nodes   = self::table('nodes');
        $records = self::table('records');

        foreach (['record_values', 'record_values_history'] as $name) {
            $tabelle = self::table($name);

            $wpdb->query(
                "UPDATE {$tabelle} v
                 SET v.value_ref_kind = 'node'
                 WHERE v.value_ref IS NOT NULL AND v.value_ref_kind IS NULL
                   AND EXISTS (SELECT 1 FROM {$nodes} n WHERE n.id = v.value_ref)
                   AND NOT EXISTS (SELECT 1 FROM {$records} r WHERE r.id = v.value_ref)"
            );

            $wpdb->query(
                "UPDATE {$tabelle} v
                 SET v.value_ref_kind = 'record'
                 WHERE v.value_ref IS NOT NULL AND v.value_ref_kind IS NULL
                   AND EXISTS (SELECT 1 FROM {$records} r WHERE r.id = v.value_ref)
                   AND NOT EXISTS (SELECT 1 FROM {$nodes} n WHERE n.id = v.value_ref)"
            );
        }
    }

    /**
     * Schema 21: die sieben Bedingungen auf `identities.id` fallen — **vor `dbDelta`**.
     *
     * ⚠️ **Sie müssen weg, bevor irgendetwas anderes geschieht** (TASK-004): *eine Spalte, an der eine
     * Fremdschlüsselbedingung hängt, lässt sich nicht zu `AUTO_INCREMENT` machen, und eine Tabelle,
     * auf die noch eine Bedingung zeigt, lässt sich nicht löschen. **Beides schlägt bei `$wpdb`
     * lautlos fehl** — der Wächter [`id-space-check.php`](../../../scripts/dev/id-space-check.php) ist
     * die Stelle, die es merkt.*
     *
     * ⚠️ **Ersatzlos, und das ist eine Entscheidung** (`PR-9`): *die Bedingungen auf die jeweilige
     * Zieltabelle setzt **TASK-010**, zusammen mit der Umbenennung von `from_id`/`to_id`. Sie hier
     * schon zu setzen hiesse, `ON DELETE RESTRICT` gegen die bestehenden Aufräumwege zu stellen, ohne
     * deren Reihenfolge geprüft zu haben. **Gemessen am 2026-09-04: alle sieben Spalten hatten null
     * Waisen** — die Zusage ist also erfüllt, sie wird bis TASK-010 nur lesend gehalten.*
     *
     * ⚠️ *Idempotent: der zweite Lauf findet keine der Bedingungen mehr und tut nichts.*
     */
    private static function dropIdentityForeignKeys(): void
    {
        global $wpdb;

        foreach (self::RETIRED_IDENTITY_REFERENCES as [$table, $column]) {
            $source     = self::table($table);
            $constraint = 'fk_taxmod_' . $table . '_' . $column;

            $exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = %s
                   AND TABLE_NAME = %s',
                $constraint,
                $source
            ));

            if ($exists === 0) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$source} DROP FOREIGN KEY {$constraint}");
        }
    }

    /**
     * Schema 21: `nodes` und `relations` vergeben ihre Ids selbst, **hinter dem gemeinsamen Raum**.
     *
     * ⚠️ **Der Anfang der beiden Räume ist die entscheidende Zahl.** *Er ist die höchste Nummer, die
     * der gemeinsame Raum je vergeben hat, plus eins — und die steht **nicht** in den lebenden
     * Tabellen. Sie steht in `identities`, und dort auch für alles längst Gelöschte. Deshalb wird sie
     * von dort genommen, solange die Tabelle noch da ist, und erst danach fällt sie. **Nähme man das
     * Höchste der lebenden Zeilen, bekäme die nächste neue Zeile eine Nummer, die im Schatten und im
     * Änderungsbuch schon einer anderen Sache gehört** — genau der Wiedergebrauch, den
     * [D-340](../../../docs/NewConcept/90-decision-log.md) verbietet.*
     *
     * ⚠️ *Zur Sicherheit gehen auch die Schattentabellen und `changelog.owner_id` in das Maximum ein:
     * sie überleben, was sie beschreiben, und wären sonst die eine Quelle, die niemand befragt hat.*
     *
     * ⚠️ **Auf einer frischen Installation beginnen beide bei `2`** — `1` gehört der
     * Installationsidentität ({@see self::RESERVED_INSTALLATION_ID}).
     */
    private static function giveEveryTableItsOwnIdSpace(): void
    {
        global $wpdb;

        $hoechste = (int) get_option(self::RETIRED_COUNTER_OPTION, 0);
        $identities = self::table('identities');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $identities)) === $identities) {
            $hoechste = max($hoechste, (int) $wpdb->get_var("SELECT MAX(id) FROM {$identities}"));
        }

        $quellen = [
            ['nodes', 'id'],
            ['relations', 'id'],
            ['nodes_history', 'id'],
            ['relations_history', 'id'],
            ['changelog', 'owner_id'],
            ['labels', 'owner_id'],
            ['settings', 'owner_id'],
        ];

        foreach ($quellen as [$name, $spalte]) {
            $tabelle = self::table($name);

            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tabelle)) !== $tabelle) {
                continue;
            }

            $hoechste = max($hoechste, (int) $wpdb->get_var("SELECT MAX({$spalte}) FROM {$tabelle}"));
        }

        $beginn = max($hoechste + 1, self::RESERVED_INSTALLATION_ID + 1);

        foreach (self::OWN_ID_SPACE as $name) {
            $tabelle = self::table($name);

            $selbst = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'id'
                   AND EXTRA LIKE %s",
                $tabelle,
                '%auto_increment%'
            ));

            if ($selbst === 0) {
                $wpdb->query(
                    "ALTER TABLE {$tabelle} MODIFY id bigint(20) unsigned NOT NULL AUTO_INCREMENT"
                );
            }

            // ⚠️ *`AUTO_INCREMENT` lässt sich nach unten nicht setzen — MySQL hebt einen zu kleinen
            // Wert stillschweigend auf das nötige Minimum. Der Aufruf ist damit auch beim zweiten
            // Lauf harmlos und kann keinen bereits weitergelaufenen Zähler zurückdrehen.*
            $wpdb->query($wpdb->prepare(
                "ALTER TABLE {$tabelle} AUTO_INCREMENT = %d",
                $beginn
            ));
        }

        // Eine Quelle und nicht zwei. Der alte Zähler aus Fassung 1 wäre nur noch eine Einladung.
        delete_option(self::RETIRED_COUNTER_OPTION);
    }

    /**
     * Schema 21: `identities` fällt — **zuletzt, und nur wenn nichts mehr auf sie zeigt**.
     *
     * ⚠️ *Sie hatte genau eine Spalte, es zieht also nichts um. Was sie festhielt — «diese Nummer war
     * einmal vergeben» —, hält jetzt der Anfang der beiden Räume, den
     * {@see self::giveEveryTableItsOwnIdSpace()} aus ihr gelesen hat, **bevor** sie hier verschwindet.*
     */
    private static function dropIdentitiesTable(): void
    {
        global $wpdb;

        $identities = self::table('identities');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $identities)) !== $identities) {
            return;
        }

        $zeigerAufSie = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = %s',
            $identities
        ));

        // ⚠️ *Steht noch eine Bedingung darauf, bleibt die Tabelle stehen. Ein `DROP`, das MySQL
        // zurückweist, sagt `$wpdb` niemandem — der Wächter schlägt dafür an.*
        if ($zeigerAufSie > 0) {
            return;
        }

        $wpdb->query("DROP TABLE {$identities}");
    }

    /**
     * @return list<string>
     */
    private static function statements(): array
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $t       = static fn (string $n): string => self::table($n);

        return [
            // ⚠️ *`identities` stand hier bis Fassung 20 — der gemeinsame Nummernraum. **Sie ist
            // gestrichen** (TASK-004): jede Tabelle vergibt ihre Ids aus ihrem eigenen
            // `AUTO_INCREMENT`.*
            "CREATE TABLE {$t('nodes')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                version int(10) unsigned NOT NULL DEFAULT 1,
                name varchar(191) NOT NULL,
                path varchar(255) NOT NULL,
                kind varchar(20) DEFAULT NULL,
                settings_record_id bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY path (path),
                KEY name (name),
                KEY settings_record_id (settings_record_id)
            ) {$charset};",

            // ⚠️ `parked_by_group_id` is the one place an edge can be parked (D-371). A node needs
            // no such column, because its **position** is the mark — it sits under the trash
            // (Package 1) — and an edge has no position in the tree, so there is no first truth to
            // duplicate. It holds the **change group** that parked it rather than a bare flag,
            // because D-128 wants a parked attribute labelled *deleted with «X»* and the group is
            // where that act is described (D-348). One column, two facts, neither of them a copy.
            "CREATE TABLE {$t('relations')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                version int(10) unsigned NOT NULL DEFAULT 1,
                from_id bigint(20) unsigned NOT NULL,
                to_id bigint(20) unsigned NOT NULL,
                kind varchar(20) NOT NULL,
                name varchar(191) NOT NULL DEFAULT '',
                position int(10) unsigned NOT NULL DEFAULT 0,
                multiplicity varchar(10) NOT NULL DEFAULT '1..1',
                parked_by_group_id bigint(20) unsigned DEFAULT NULL,
                hide tinyint(1) unsigned NOT NULL DEFAULT 0,
                settings_record_id bigint(20) unsigned DEFAULT NULL,
                target_settings_record_id bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY from_id (from_id),
                KEY to_id (to_id),
                KEY parked_by_group_id (parked_by_group_id),
                KEY settings_record_id (settings_record_id),
                KEY target_settings_record_id (target_settings_record_id)
            ) {$charset};",

            // ⚠️ *Hier stand `settings` samt der Begruendung ihrer `path`-Spalte. **Die Tabelle ist mit
            // D-579 gestrichen** — eine Einstellung ist eine Kante (D-529), und ihr Wert steht in
            // `record_values`. Die Adressfrage, die `settings.path` beantwortete, beantwortet dort der
            // Pfad.*

            "CREATE TABLE {$t('labels')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                owner_id bigint(20) unsigned NOT NULL,
                path varchar(255) NOT NULL DEFAULT '',
                role_id bigint(20) unsigned NOT NULL,
                number varchar(20) NOT NULL DEFAULT '',
                locale varchar(20) NOT NULL DEFAULT '',
                text mediumtext NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY one_text (owner_id,path,role_id,number,locale),
                KEY role_id (role_id)
            ) {$charset};",

            // owner_kind is stored alongside because the changelog outlives what it refers
            // to — frozen history rather than a duplicated fact (D-065).
            // change_group_id brackets the rows written by one act (D-348). It is a number and
            // nothing more — no table, because a second place where history lives is a second
            // place that can disagree with the changelog (D-061).
            "CREATE TABLE {$t('changelog')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                change_group_id bigint(20) unsigned DEFAULT NULL,
                owner_id bigint(20) unsigned NOT NULL,
                owner_kind varchar(20) NOT NULL,
                at datetime NOT NULL,
                by_user_id bigint(20) unsigned DEFAULT NULL,
                what varchar(40) NOT NULL,
                version int(10) unsigned DEFAULT NULL,
                before_state mediumtext DEFAULT NULL,
                after_state mediumtext DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY change_group_id (change_group_id),
                KEY owner_id (owner_id),
                KEY at (at)
            ) {$charset};",

            // The record identity space is its own (D-164), so AUTO_INCREMENT serves it.
            "CREATE TABLE {$t('records')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                node_id bigint(20) unsigned NOT NULL,
                node_version int(10) unsigned NOT NULL,
                version int(10) unsigned NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                kind varchar(20) NOT NULL DEFAULT 'user',
                PRIMARY KEY  (id),
                KEY node_id (node_id),
                KEY kind (kind)
            ) {$charset};",

            // Keyed on a path with the last edge repeated in edge_id, so that
            // `WHERE edge_id = ... AND value_decimal > 1000` finds every occurrence
            // regardless of how deep it sits (D-134).
            "CREATE TABLE {$t('record_values')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                record_id bigint(20) unsigned NOT NULL,
                edge_id bigint(20) unsigned NOT NULL,
                path varchar(255) NOT NULL,
                locale varchar(20) NOT NULL DEFAULT '',
                position int(10) unsigned NOT NULL DEFAULT 0,
                version int(10) unsigned NOT NULL DEFAULT 1,
                value_int bigint(20) DEFAULT NULL,
                value_decimal decimal(30,10) DEFAULT NULL,
                value_text mediumtext DEFAULT NULL,
                value_date datetime DEFAULT NULL,
                value_ref bigint(20) unsigned DEFAULT NULL,
                value_ref_kind varchar(20) DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY of_field (record_id,edge_id,locale),
                KEY edge_id (edge_id),
                KEY value_ref (value_ref)
            ) {$charset};",

            // ⚠️ **Die Geschichte, in eigenen Tabellen** ([D-536](../../../docs/NewConcept/90-decision-log.md),
            // [D-537](../../../docs/NewConcept/90-decision-log.md)). *Dieselben Spalten wie die lebende
            // Tabelle, dazu `deleted` und `archived_at`, und der Schlüssel ist `(id, version)` — dort
            // **darf** eine Id mehrfach vorkommen, in der lebenden nicht. Der Eigentümer: «ich habe
            // einfach den Datensatz oder ich habe ihn nicht».*
            //
            // ⚠️ **Kein Fremdschlüssel auf `identities`, und das ist Absicht.** *Eine alte Zeile führt
            // ihre Verweise als **Datum** mit, nicht als Zwang — sonst hielte die Geschichte eine
            // Identität am Leben, die längst weggeräumt wurde, und `ON DELETE RESTRICT` machte das
            // Aufräumen unmöglich.*
            //
            // ⚠️ *`id` ist hier **nie** `AUTO_INCREMENT`: die Nummer kommt von der Zeile, die
            // hinüberwandert, und darf sich dabei nicht ändern.*
            "CREATE TABLE {$t('nodes_history')} (
                id bigint(20) unsigned NOT NULL,
                version int(10) unsigned NOT NULL,
                name varchar(191) NOT NULL,
                path varchar(255) NOT NULL,
                kind varchar(20) DEFAULT NULL,
                settings_record_id bigint(20) unsigned DEFAULT NULL,
                deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
                archived_at datetime NOT NULL,
                PRIMARY KEY  (id,version),
                KEY archived_at (archived_at)
            ) {$charset};",

            "CREATE TABLE {$t('relations_history')} (
                id bigint(20) unsigned NOT NULL,
                version int(10) unsigned NOT NULL,
                from_id bigint(20) unsigned NOT NULL,
                to_id bigint(20) unsigned NOT NULL,
                kind varchar(20) NOT NULL,
                name varchar(191) NOT NULL DEFAULT '',
                position int(10) unsigned NOT NULL DEFAULT 0,
                multiplicity varchar(10) NOT NULL DEFAULT '1..1',
                parked_by_group_id bigint(20) unsigned DEFAULT NULL,
                hide tinyint(1) unsigned NOT NULL DEFAULT 0,
                settings_record_id bigint(20) unsigned DEFAULT NULL,
                target_settings_record_id bigint(20) unsigned DEFAULT NULL,
                deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
                archived_at datetime NOT NULL,
                PRIMARY KEY  (id,version),
                KEY archived_at (archived_at)
            ) {$charset};",

            "CREATE TABLE {$t('records_history')} (
                id bigint(20) unsigned NOT NULL,
                node_id bigint(20) unsigned NOT NULL,
                node_version int(10) unsigned NOT NULL,
                version int(10) unsigned NOT NULL,
                created_at datetime NOT NULL,
                kind varchar(20) NOT NULL DEFAULT 'user',
                deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
                archived_at datetime NOT NULL,
                PRIMARY KEY  (id,version),
                KEY archived_at (archived_at)
            ) {$charset};",

            "CREATE TABLE {$t('record_values_history')} (
                id bigint(20) unsigned NOT NULL,
                record_id bigint(20) unsigned NOT NULL,
                edge_id bigint(20) unsigned NOT NULL,
                path varchar(255) NOT NULL,
                locale varchar(20) NOT NULL DEFAULT '',
                position int(10) unsigned NOT NULL DEFAULT 0,
                version int(10) unsigned NOT NULL,
                value_int bigint(20) DEFAULT NULL,
                value_decimal decimal(30,10) DEFAULT NULL,
                value_text mediumtext DEFAULT NULL,
                value_date datetime DEFAULT NULL,
                value_ref bigint(20) unsigned DEFAULT NULL,
                value_ref_kind varchar(20) DEFAULT NULL,
                deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
                archived_at datetime NOT NULL,
                PRIMARY KEY  (id,version),
                KEY archived_at (archived_at),
                KEY of_record (record_id)
            ) {$charset};",
        ];
    }
}
