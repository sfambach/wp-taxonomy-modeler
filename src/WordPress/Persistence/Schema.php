<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\SystemClock;

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
 *   R --> RV[(relation_records)]
 *   RC --> RV
 * ```
 *
 * ⚠️ **Jede Tabelle vergibt ihre Ids selbst** (TASK-004,
 * [`package.md` §6](../../../docs/pakete/modelltabellen/package.md)). *`identities` ist gestrichen;
 * eine Nummer ist nur noch innerhalb ihrer Tabelle eindeutig, und wo eine Spalte auf mehr als eine
 * Tabelle zeigen kann, nennt eine zweite Spalte den Raum — `changelog.owner_kind`,
 * `relation_records.value_ref_kind`.*
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
     * 3 — inheritance relations become the tree and `nodes.path` is derived from them (D-014);
     *     `relations.from_node_id` and `to_node_id` join the foreign keys.
     * 4 — the changelog gains `change_group_id`, the bracket around one act (D-348).
     * 5 — the three branches are seeded as framework nodes; no table changed, but the version
     *     moves so that an already-installed copy gets them (D-161).
     * 6 — the label roles are seeded as nodes under their own container (D-151); again no
     *     table changed, and again the version is what carries them to an installed copy.
     * 7 — `relations.parked_by_group_id`, so an attribute can be removed at all (D-371).
     *     ⚠️ *Die Spalte ist mit Fassung 27 wieder fort: das Parken steht seither im Schatten
     *     ([D-619](../../../docs/NewConcept/90-decision-log.md), TASK-013).*
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
     *      again on 2026-08-27.* **Both node and relation, because the owner asked for both**:
     *      «relation and node both having an attribute `hide`».
     * 11 — `range_min`, `range_max` and `range_step` become `min`, `max` and `step` (D-466),
     *      on the owner: «shorter, we do not need the range». **16 rows, a rename and nothing
     *      else.** *The new names were free — a collision would have hit the unique key and
     *      failed loudly, which is the good failure.*
     * 12 — `nodes.hide` goes; `hide` lives on the **relation** alone (D-467). *The owner narrowed
     *      schema 10 once the access he thought was missing turned out to exist: «then we only
     *      need it on the relation». **Hiding is about a placement**, and a node-level flag had no
     *      use case behind it — «I do not simply create a model node and then say I will not
     *      draw it, that would be nonsense».* All 7 values travel to their inheritance relation.
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
     * Schema 20: `relation_records.value_ref_kind` — der Verweis nennt seinen Raum (TASK-005,
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
     * Entscheidung und keine Nachlässigkeit** (`PR-9`): *`relations.from_node_id`/`to_node_id` bekommen ihre
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
    /**
     * Fassung 23: `nodes.implemented_by` — **der Knoten nennt die PHP-Klasse, die ihn umsetzt**
     * (TASK-008, [`package.md` §3.2](../../../docs/pakete/modelltabellen/package.md)).
     *
     * ⚠️ **Der Klassenname und keine Marke, auf sein Wort:** *«wenn das ohne Factory geht, weil der
     * Klassenname da drinsteht, perfekt.»* *Eine Marke hätte eine zweite Tabelle Marke → Klasse
     * gebraucht; der Klassenname bindet die Zeile an den Code — **und genau dafür gibt es den
     * Wächter**, [`implemented-by-check.php`](../../../scripts/dev/implemented-by-check.php): eine
     * Zeile, die eine Klasse nennt, die es nicht gibt, wird rot.*
     *
     * ⚠️ **Keine Wanderung im `dbDelta`-Schritt, und das ist gemessen.** *Die Spalte ist nullbar und
     * `null` heisst «keine Klasse setzt diesen Knoten um» — für 128 von 128 bestehenden Knoten die
     * richtige Antwort, bis die Saat sie füllt. Gefüllt wird sie einmalig von
     * [`implemented-by-migrate.php`](../../../scripts/dev/implemented-by-migrate.php) aus den
     * Optionen, die sie ablöst (TASK-009).*
     */
    /**
     * Fassung 24: `nodes.kind` heisst `field_type` (TASK-007,
     * [`package.md` §3.1](../../../docs/pakete/modelltabellen/package.md)).
     *
     * ⚠️ **Eine Umbenennung und sonst nichts.** *Der Wert `field` wird zu `model` — nach seiner
     * Selbstkorrektur «Entschuldigung, Model und Settings, richtig». **Gemessen vor dem Umbau: der
     * Wert `field` stand in keiner einzigen Zeile**, lebend 97 leer und 39 `setting`, im Schatten
     * 17 202 leer und 190 `setting`. **Der Schritt schreibt ihn trotzdem um**, weil eine Wanderung,
     * die nur den gemessenen Fall kann, auf der nächsten Installation falsch ist — dieselbe Regel
     * wie in Fassung 15.*
     *
     * ⚠️ *Der Schatten zieht mit, sonst wird `shadow-shape-check.php` rot — und das zu Recht: eine
     * Spalte, die nur eine der beiden Tabellen hat, kann `Shadow::keep()` nicht kopieren.*
     *
     * ⚠️ **Der Grund ist `CD-9`, nicht Geschmack:** *drei Spalten hiessen `kind` und meinten drei
     * verschiedene Dinge. **Diese eine sagt jetzt, was sie ist**; `relations.kind` fällt ohnehin
     * ([D-587](../../../docs/NewConcept/90-decision-log.md)), und `records.kind` wird `record_type`
     * (TASK-015).*
     */
    /**
     * Fassung 25: `relations.position` heisst `sort_order`, und `(from_node_id, kind, sort_order)` wird
     * eindeutig (TASK-012).
     *
     * ⚠️ **Der Schlüssel geht über drei Spalten, und die dritte ist der ganze Befund** — die
     * Begründung steht bei {@see renameRelationPositionColumn()}. *Der Einzelindex auf `from_node_id`
     * fällt dabei, weil der neue Schlüssel mit derselben Spalte beginnt.*
     *
     * ⚠️ *Der Schatten bekommt die Umbenennung, aber **nicht** den Schlüssel: dort darf dieselbe
     * Stelle mehrfach vorkommen.*
     */
    /**
     * Fassung 26: `relations.from_id` heisst `from_node_id`, `to_id` heisst `to_node_id`, **und beide
     * bekommen eine Bedingung auf `nodes.id`** (TASK-010).
     *
     * ⚠️ **Der Eigentümer:** *«machen wir es eh eindeutiger … das ist eine Knoten-Id, da ist ein
     * Constraint.»* **Die Umbenennung ist die kleinere Hälfte.** *Bis Fassung 20 zeigten alle sieben
     * Fremdschlüssel auf `identities.id`, und die Bedingung erlaubte strukturell eine Kante, die von
     * einem **Datensatz** ausgeht. Seit TASK-004 hielt nur noch ein Wächter lesend fest, was jetzt
     * die Datenbank hält.*
     *
     * ⚠️ *Der Schatten bekommt die Umbenennung und **keine** Bedingung: eine alte Zeile führt ihre
     * Verweise als Datum mit, nicht als Zwang — sonst hielte die Geschichte einen Knoten am Leben,
     * den jemand weggeräumt hat.*
     */
    /**
     * Fassung 27: `relations.parked_by_group_id` fällt — **das Parken steht im Schatten** (TASK-013).
     *
     * ⚠️ **[D-619](../../../docs/NewConcept/90-decision-log.md), und [D-575](../../../docs/NewConcept/90-decision-log.md)
     * wörtlich von ihm:** *«Parken heisst: in die Schattentabelle wandern, mit der Änderungsgruppe im
     * Gepäck.»* **Und die Wertzeilen einer geparkten Kante wandern mit** — auf sein *«1»* gegen
     * «stehenbleiben» und «verbieten».
     *
     * ⚠️ **Die Wanderung ist umkehrbar, und sie verliert nichts**: jede heute geparkte Zeile geht mit
     * ihrer Gruppe in `relations_history`, bevor die Spalte fällt. *Die Spalte wieder anzulegen und
     * die Zeilen zurückzuschreiben ist möglich, weil der Schatten beides hält — Gruppe und Inhalt.*
     *
     * ⚠️ *Gemessen am 2026-09-05: **14 geparkte Kanten, keine einzige mit einer Wertzeile.** Es geht
     * nichts verloren, und die Regel steht, bevor der erste Fall sie erzwingt.*
     */
    /**
     * Fassung 28: **die Vererbung wird eine Spalte** — `nodes.parent_node_id` mit
     * `nodes.sort_order`, dazu `nodes.hide` (TASK-018,
     * [D-581](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Der gefährlichste Umzug des Pakets, weil er die Struktur selbst bewegt.** *Darum zählt
     * {@see self::moveInheritanceOntoTheNode()} **vorher und nachher dieselben fünf Zahlen** —
     * Knoten, Vererbungskanten, Wurzeln, Tiefe, Geschwisterreihenfolge — und **bricht ab, statt
     * einen Vater zu erfinden**, wenn ein Kind zwei Väter hat, ein Zyklus dasteht oder ein Ziel
     * fehlt.*
     *
     * ⚠️ **Umkehrbar:** *jede berührte Knotenzeile geht vorher in den Schatten, jede Vererbungskante
     * wandert als gelöschte Zeile nach `relations_history`, und beides trägt dieselbe
     * Änderungsgruppe.*
     *
     * ⚠️ **`hide` kommt mit, und das ist kein Nebenprodukt:** *[D-581](../../../docs/NewConcept/90-decision-log.md)
     * hält fest, dass `relations.hide` mit dieser Entscheidung **alle** seine Benutzer verliert, weil
     * alle Vererbungskanten sind. Fällt die Kante, ginge die Angabe sonst verloren. **Was `hide`
     * ist** — Spalte oder Einstellung — hat der Eigentümer ausdrücklich vertagt, und dieser Umzug
     * beantwortet die Frage nicht.*
     */
    /**
     * Fassung 29: die vier Datensatztabellen heissen nach dem, woran sie hängen (TASK-014).
     *
     * ⚠️ **Der Eigentümer:** *«records → node_records, record_values → relation_records».* **Die
     * Zuordnung ist gemessen und ausnahmslos** — jede Zeile in `records` hängt an einem Knoten, jede
     * Zeile in `record_values` an einer Kante. *Die Schatten ziehen mit, sonst findet
     * {@see Shadow::shadowFor()} sie nicht mehr: er liest die beiden Listen nach Stelle.*
     *
     * ⚠️ **Und zwei Spalten nach derselben Regel wie `from_node_id`:** *`record_id` wird
     * `node_record_id`, `relation_id` wird `relation_id` — ein Fremdschlüssel nennt seine Zieltabelle
     * ([D-164](../../../docs/NewConcept/90-decision-log.md)). **Das Wort ist `relation` und nicht
     * `relation`** ([D-576](../../../docs/NewConcept/90-decision-log.md)); die Frage, die in TASK-014
     * offen stand, hat diese Entscheidung schon beantwortet.*
     *
     * ⚠️ **Der Index hiess `relation_id` und musste von Hand fallen**, wie schon bei `from_id`: *eine
     * umbenannte Spalte behält den **Namen** ihres Indexes, und `dbDelta` legte daneben einen
     * zweiten mit dem neuen Namen. Der zusammengesetzte `of_field` folgt der Umbenennung von selbst
     * — sein Name ändert sich nicht.*
     *
     * ⚠️ *Umkehrbar ohne Schattenzeile, und das ist hier kein Versäumnis: **eine Umbenennung bewegt
     * keine Zeile.** `RENAME TABLE` zurück und `CHANGE` zurück stellen denselben Stand her; es gibt
     * keinen Inhalt, den ein Schatten aufheben könnte.*
     */
    /**
     * Fassung 30: `node_records.kind` heisst `record_type`, und `version` steht unter `id`
     * (TASK-015).
     *
     * ⚠️ **Der Eigentümer:** *«`kind` → `type` umbenennen, `version` würde ich nach oben unter `id`
     * packen.»* — und zur Frage, ob `type` oder `record_type`: *«Records — gleiche Handhabung wie
     * Knoten und Kanten.»* **Damit heissen alle drei qualifiziert:** `field_type`, `relation_type`,
     * `record_type`. *Das ist `CD-9`: drei Spalten `kind` mit drei Bedeutungen waren der Befund.*
     *
     * ⚠️ **Die Spaltenordnung ist kein Schmuck, sondern das, was ein Mensch beim `DESCRIBE` liest**
     * — und `dbDelta` stellt sie **nicht** her: es fügt eine fehlende Spalte hinten an und ordnet
     * nie um. *Darum von Hand, mit `MODIFY … AFTER id`, lebend und im Schatten.*
     *
     * ⚠️ **`created_at` fällt hier nicht, und das ist gemessen statt vergessen** (`PR-4`): *die
     * Aufgabe knüpft es an das Änderungsbuch — «erst der neue Leser, dann die Daten». **Gemessen am
     * 2026-09-05: 6 Einträge mit `owner_kind = 'record'` gegen 417 Datensätze.** Die Spalte fallen
     * zu lassen hiesse, 411 Entstehungszeiten wegzuwerfen, für die das Log keine hat. Steht als
     * `INF-039` im Eingang.*
     */
    /**
     * Fassung 31: **`labels` nennt den Raum ihres Eigentümers und bekommt eine Version**
     * (`INF-035`, [D-164](../../../docs/NewConcept/90-decision-log.md),
     * [D-597](../../../docs/NewConcept/90-decision-log.md), [D-634](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Zwei Befunde in einer Fassung, weil beide dieselbe Tabelle betreffen und beide gemessen
     * sind.** *`owner_kind`: eine frische Kante bekam am 2026-09-05 die fünf Beschriftungen eines
     * gleichnummerigen Knotens zurück. `version`: `labels` war die **einzige** Tabelle ohne
     * Zeilennummer, und seit D-634 ist die Version beim Melden ein Pflichtwert — {@see \Taxmod\Core\Service\Labels}
     * musste dem Journal `null` hinschreiben.*
     *
     * ⚠️ **Keine Wanderung von Zeilen, nur das Füllen einer neuen Spalte.** *Gemessen vor dem Schritt:
     * 47 Beschriftungen an 40 Eigentümern, **40 von 40 Knoten**, keiner eine Kante, keine Nummer
     * zugleich beides. Der Schritt schreibt trotzdem beide Richtungen, weil eine Wanderung, die nur
     * den gemessenen Fall kann, auf der nächsten Installation falsch ist.*
     *
     * ⚠️ *Was hier ausdrücklich **nicht** geschieht: die Teilung in `labels` und `label_texts` und der
     * Umzug von `name` ([D-580](../../../docs/NewConcept/90-decision-log.md), TASK-019). Die beiden
     * sind ein Stück und stehen weiter offen — siehe `INF-040` im Eingang.*
     *
     * ⚠️ **Fassung 32 nimmt die drei Spalten aus TASK-020 wieder weg** (TASK-057,
     * [D-642](../../../docs/NewConcept/90-decision-log.md), [D-643](../../../docs/NewConcept/90-decision-log.md)).
     * *`nodes.settings_record_id`, `relations.settings_record_id`, `relations.target_settings_record_id`
     * und ihre beiden Schatten. **Am Knoten wandern 29 Träger auf eine Einstellungskante `1..1`; an
     * der Kante fällt der Renderer ersatzlos**, weil beide Spalten dort je 0 Zeilen hatten.*
     *
     * ⚠️ **Fassung 33 nimmt `nodes.field_type` weg — die zweite Hälfte von
     * [D-621](../../../docs/NewConcept/90-decision-log.md)** (TASK-059). *«Die Kante sagt, was etwas
     * hier ist — nicht der Knoten und nicht der Ast.» **39 Knoten trugen die Marke; die Auskunft kommt
     * jetzt aus den eingehenden Kanten**, und wo keine ist, von der Kante über dem nächsten Vorfahren.
     * Der Schatten geht mit, aus demselben Grund wie in Fassung 32.*
     *
     * ⚠️ **Fassung 34 teilt die Beschriftungen und holt den Namen hinein** (TASK-019,
     * [D-580](../../../docs/NewConcept/90-decision-log.md), [D-598](../../../docs/NewConcept/90-decision-log.md),
     * [D-645](../../../docs/NewConcept/90-decision-log.md), [D-646](../../../docs/NewConcept/90-decision-log.md)).
     * *`labels` trägt das Sprachunabhängige — heute nur noch `icon` —, `label_texts` das
     * Sprachabhängige je Sprache in sechs Spalten: `text_name`, `text_form`, `text_table`,
     * `text_select`, `text_help`, `text_symbol`. **Knoten und Kanten zeigen mit `label_id` dorthin**,
     * damit jeder Fremdschlüssel echt und einspaltig ist; `nodes.name` und `relations.name` fallen.*
     *
     * ⚠️ **Fassung 35 nimmt `nodes.path` weg — die letzte der vier Pfadspalten** (TASK-001,
     * [`review-tabellen.md`](../../../docs/review-tabellen.md) §A). *Der Weg zur Wurzel war **zweimal**
     * gespeichert: als `parent_node_id` und als punktseparierte Kette daneben. **Seit TASK-018 ist
     * `parent_node_id` der Baum** ([D-581](../../../docs/NewConcept/90-decision-log.md)), und
     * [D-082](../../../docs/NewConcept/90-decision-log.md) nannte die Kette von Anfang an «derived and
     * rebuildable». **Ein `Node` trägt den Weg weiter** — er wird beim Lesen gerechnet
     * ({@see WpdbNodeRepository::ancestry()}), nicht abgeschrieben.*
     *
     * ⚠️ *Der Schatten behält seine Spalte, aus demselben Grund wie `name`
     * ([D-065](../../../docs/NewConcept/90-decision-log.md)): eine alte Zeile führt ihre Angaben als
     * **Datum** mit. Sie steht deshalb in {@see self::SHADOW_ONLY_IN}.*
     *
     * ⚠️ **Fassung 36 räumt die leeren `default`-Sätze weg** ([D-653](../../../docs/NewConcept/90-decision-log.md)).
     * *Sein Beschluss: «der default-Satz sollte nicht leer bestehen.» **Gemessen am 2026-09-06: 390 von
     * 454 sind leer, 64 gefüllt.** Ein leerer `default` sagt nichts und kostet eine Zeile je Knoten —
     * [D-609](../../../docs/NewConcept/90-decision-log.md) hat dasselbe für das **Anlegen** schon
     * entschieden, nur bestehen die alten weiter. Umkehrbar: Schattenzeilen, **eine**
     * Änderungsgruppe, gezählt davor und danach, und bei Abweichung bleibt alles stehen.*
     *
     * ⚠️ **Fassung 38 räumt die Renderer-Wahlen weg, die ihr Knoten nicht mehr zulässt**
     * ([D-672](../../../docs/NewConcept/90-decision-log.md)). *Seine Diagnose: «ich glaube das
     * problem ist auch produziert weil wir code ändern ohne die db zu ändern». **Ändert sich, was
     * erlaubt ist, wandert der Bestand im selben Schritt mit** — «was darf gewählt werden» ist
     * genauso ein Vertrag mit den Daten wie eine Spalte. Sie entfernt und schreibt nichts hin, damit
     * der Rückfall greift; welcher Renderer stattdessen gelten soll, ist seine Entscheidung
     * (`PR-4`). **Auf einer Installation, auf der nichts unzulässig ist, tut sie nichts** — gemessen
     * am 2026-09-06 sind alle 12 Wahlen zulässig, also ist sie an einem **gebauten** Fall geprüft
     * und nicht am Bestand.*
     *
     * ⚠️ **Fassung 39 nimmt `relation_records.path` weg — die letzte Pfadspalte überhaupt**
     * (TASK-002, [D-667](../../../docs/NewConcept/90-decision-log.md): *«aber wir hatten die relation
     * id schon vorgesehen im record»*). *Die Hälfte stand seit Fassung 37: `node_records.relation_id`
     * nennt die Verwendungsstelle, und die zwei zweiteiligen Zeilen sind dorthin gewandert.
     * **Gemessen am 2026-09-06 trägt seither jede lebende Wertzeile in ihrem Pfad genau ihre eigene
     * `relation_id`** — null Abweichungen, null mehrteilige —, also sagt die Spalte nichts mehr, was
     * nicht daneben steht. *Der Schatten behält sie ({@see self::SHADOW_ONLY_IN}); Geschichte wird
     * nicht umgeschrieben ([D-065](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ **Fassung 40 gibt der Wertzeile im Schatten ihre Parkgruppe** (`INF-062`,
     * [D-676](../../../docs/NewConcept/90-decision-log.md)). *`relation_records_history` bekommt
     * `parked_by_group_id` — dieselbe Spalte, die `relations_history` seit Fassung 27 trägt, und
     * aus demselben Grund: **eine Zeile, die das Leeren gelöscht hat, und eine, die das Parken
     * mitgenommen hat, sahen dort gleich aus** — beide mit `deleted = 1` und nichts, was den einen
     * Akt vom anderen trennt. *Gemessen am 2026-09-07 auf eigener Wiese: `put` → `clear` → `put` →
     * parken → zurückholen ergab **zwei** lebende Wertzeilen statt einer; mit der Spalte ist es
     * genau eine, und zwar die, die beim Parken lebendig war.* **Eine reine
     * Ergänzung — `dbDelta` legt die Spalte an, es wandert nichts.** Der Bestand bleibt `NULL`:
     * gemessen am 2026-09-07 tragen die 487 geparkten Kanten zusammen **24** Schattenwertzeilen, und
     * **keine einzige davon ist zur Parkzeit ihrer Kante oder später archiviert worden** — sie waren
     * alle vorher schon gelöscht, gehören also auch nach der alten Lesart nicht ins Gepäck.*
     *
     * ⚠️ **Fassung 46 gibt jedem Knoten seine Klasse** ([D-716](../../../docs/NewConcept/90-decision-log.md),
     * [D-719](../../../docs/NewConcept/90-decision-log.md), TASK-092 — Schritt 1 des Bauplans
     * [`einstellungen-bauplan.md`](../../../docs/einstellungen-bauplan.md)). *`nodes.klasse` und
     * `nodes_history.klasse`, gefüllt nach der Tabelle K3 der Protokollseite, die er bestätigt hat:
     * Kategorie für Ordnung und für Knoten mit Feldern; die eigene Typklasse für die elf Typknoten
     * (sie steht schon als `implemented_by` da); `Auswahl` für `Prefixes`, `Base units`, `Currency`
     * und die Rollen; `Konstante` für Präfixe und Rollen; `Einheitswert` für Einheiten **und**
     * Währungen; `Einheitenwert` für den gesäten Knoten. **Nichts wandert, nichts fällt** — die
     * Knoten unter `Settings` und die Einstellungsknoten unter den Typen bekommen `Kategorie` und
     * fallen erst mit Schritt 7 ([D-718](../../../docs/NewConcept/90-decision-log.md)).*
     * ⚠️ *`Currency` ist Inhalt des Eigentümers ohne Notiz und wird **einmal** über den Namen unter
     * `Constants` gefunden — der Fall, den [D-022](../../../docs/NewConcept/90-decision-log.md) meidet,
     * hier als einmalige Wanderung hingenommen und im Bauplan genannt.*
     *
     * ⚠️ **Fassung 47 legt die zwei Tabellen des Einstellungsmodells an — leer** ([D-712](../../../docs/NewConcept/90-decision-log.md),
     * [D-717](../../../docs/NewConcept/90-decision-log.md), TASK-096 a, Schritt 3 des Bauplans).
     * *`settings_object` (id, version, klasse) und `settings_value` (Träger `node_id` **oder**
     * `settings_object_id`, dazu wahlweise `relation_id`; Adresse `klasse`.`attribut`; `position`,
     * `aktiv`; genau eine Wertspalte: `wert_int` — auch für `bool`, [D-315](../../../docs/NewConcept/90-decision-log.md) —,
     * `wert_decimal`, `wert_text`, `wert_knoten_id`, `wert_settings_object_id`), je mit Schatten.
     * **Jede Tabelle zählt ihre Ids selbst, jeder Verweis ist ein Fremdschlüssel**
     * ({@see self::constrainSettingsValues()}). Nichts wandert hinein — sein Wort: «wir beginnen
     * leer, dann können wir schön testen.»*
     *
     * ⚠️ **Fassung 48 macht `read_only` zur Spalte der Kante** ([D-714](../../../docs/NewConcept/90-decision-log.md),
     * [D-713](../../../docs/NewConcept/90-decision-log.md), TASK-094/095, Schritt 2 des Bauplans).
     * *`relations.read_only` und `relations_history.read_only`; was an einer Verwendungsstelle als
     * Einstellung `read_only = 1` stand, wandert in die Spalte; dann geht die Einstellungskante
     * `read_only` an der Wurzel in den Schatten, mit ihren Wertzeilen ([D-619](../../../docs/NewConcept/90-decision-log.md))
     * — **es gibt kein `read_only` am Knoten mehr.** Gemessen am 2026-09-11: eine Einstellungskante,
     * sieben Wertzeilen, alle am Knoten, alle `0` — es wandert nichts in die Spalte, und nichts geht
     * verloren. `multiplicity` war schon Spalte (Fassung 22); was fällt, ist nur ihr Schlüssel.*
     */
    public const VERSION = 53;

    /**
     * Das Wort, das die Kantentabelle für den Baum benutzt hat, bis Fassung 28 (TASK-018).
     *
     * ⚠️ *Es steht hier als Zeichenkette und nicht mehr als Aufzählungsfall, weil
     * `RelationKind::Inheritance` gefallen ist ([D-581](../../../docs/NewConcept/90-decision-log.md)).
     * **Alte Wanderungen lesen alte Daten** — sie dürfen nicht davon abhängen, dass der Kern das
     * Wort noch kennt.*
     */
    private const RETIRED_INHERITANCE_KIND = 'inheritance';

    /**
     * Wie die hohe Beschriftungstabelle heisst, während Fassung 34 aus ihr schöpft.
     *
     * ⚠️ *Sie ist die Sicherung des Umzugs und fällt erst, wenn jede Zeile nachgewiesen angekommen
     * ist — siehe {@see self::moveNamesIntoLabels()}.*
     */
    private const RETIRED_LABELS_TABLE = 'labels_v33';

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
        ['relations', 'from_node_id'],
        ['relations', 'to_node_id'],
        ['settings', 'owner_id'],
        ['labels', 'owner_id'],
        ['changelog', 'owner_id'],
    ];

    /**
     * Die Tabellen, die ihre Ids seit Fassung 21 aus ihrem eigenen `AUTO_INCREMENT` vergeben und
     * es bis dahin nicht taten.
     *
     * ⚠️ *`node_records`, `relation_records`, `labels`, `settings` und `changelog` hatten immer schon ihr
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
    public const LIVE_TABLES = ['nodes', 'relations', 'node_records', 'relation_records', 'settings_object', 'settings_value'];

    /** Ihre Schatten, **in derselben Reihenfolge** — darauf verlässt sich die Prüfung. */
    public const SHADOW_TABLES = ['nodes_history', 'relations_history', 'node_records_history', 'relation_records_history', 'settings_object_history', 'settings_value_history'];

    /** Spalten, die **nur** der Schatten hat und die die Prüfung deshalb übergeht. */
    public const SHADOW_ONLY = ['deleted', 'archived_at'];

    /**
     * Spalten, die **nur eine bestimmte** Schattentabelle hat — Tabellenname => Spalten.
     *
     * ⚠️ **Eine Ausnahme mit Namen statt einer stillen** ([D-619](../../../docs/NewConcept/90-decision-log.md),
     * TASK-013): *`parked_by_group_id` ist seit Fassung 27 keine lebende Spalte mehr — geparkt heisst,
     * **es gibt keine lebende Zeile**. Im Schatten bleibt sie und trägt die Änderungsgruppe, mit der
     * geparkt wurde ([D-575](../../../docs/NewConcept/90-decision-log.md): «mit der Änderungsgruppe im
     * Gepäck»).*
     *
     * ⚠️ *Sie steht hier und nicht in {@see self::SHADOW_ONLY}, weil die dort genannten Spalten in
     * **jedem** Schatten stehen müssen — `nodes_history` hat kein Parken und soll auch keins bekommen.*
     *
     * ⚠️ **Seit Fassung 34 steht `name` hier** (TASK-019, [D-580](../../../docs/NewConcept/90-decision-log.md)):
     * *die lebende Zeile hat keinen Namen mehr, sie zeigt mit `label_id` auf ihre Beschriftung. **Der
     * Schatten behält ihn**, aus demselben Grund, aus dem das Änderungsbuch seine Zustände behält
     * ([D-065](../../../docs/NewConcept/90-decision-log.md)): eine alte Zeile führt ihre Angaben als
     * **Datum** mit. 21 366 + 25 440 Namen wegzuwerfen, um eine Spaltenliste symmetrisch zu machen,
     * wäre der teuerste denkbare Aufräumschritt.*
     */
    public const SHADOW_ONLY_IN = [
        'relations_history' => ['parked_by_group_id', 'name'],
        // ⚠️ **`path` steht hier seit Fassung 35** (TASK-001): *die lebende Zeile hat keinen Weg mehr,
        // er wird beim Lesen aus `parent_node_id` gerechnet. **Der Schatten behält ihn**, aus
        // demselben Grund wie `name` ([D-065](../../../docs/NewConcept/90-decision-log.md)) — 23 470
        // Wege wegzuwerfen, um eine Spaltenliste symmetrisch zu machen, wäre der teuerste denkbare
        // Aufräumschritt, und eine alte Zeile führt ihre Angaben als **Datum** mit.*
        'nodes_history'     => ['name', 'path'],
        // ⚠️ **`path` steht hier seit Fassung 39** (TASK-002): *die lebende Wertzeile hat keine
        // zweite Adresse mehr — ihre `relation_id` **ist** die Adresse, und wem der Satz gehört,
        // sagt `node_records.relation_id` ([D-667](../../../docs/NewConcept/90-decision-log.md)).
        // **Der Schatten behält den Pfad**, aus demselben Grund wie oben
        // ([D-065](../../../docs/NewConcept/90-decision-log.md)): eine alte Zeile führt ihre Angaben
        // als **Datum** mit — auch die zwei zweiteiligen, die Fassung 37 umgehängt hat, und die
        // nirgends sonst mehr stehen.*
        // ⚠️ **`parked_by_group_id` steht hier seit Fassung 40** (`INF-062`,
        // [D-676](../../../docs/NewConcept/90-decision-log.md)): *dieselbe Spalte wie an
        // `relations_history` und mit derselben Aussage — **mit welcher Änderungsgruppe wurde diese
        // Zeile geparkt**. Lebend gibt es sie nicht: eine lebende Wertzeile ist nicht geparkt.*
        'relation_records_history' => ['path', 'parked_by_group_id'],
    ];

    /** @return list<string> The table names, without the WordPress prefix. */
    public static function tableNames(): array
    {
        return ['labels', 'label_texts', 'changelog', ...self::LIVE_TABLES, ...self::SHADOW_TABLES];
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
        // ⚠️ **Ganz vorn, und die Reihenfolge ist zwingend:** *jeder Schritt darunter fragt seine
        // Tabelle über {@see self::table()}, und der Name dort ist seit Fassung 29 der neue. Liefe
        // die Umbenennung später, suchte `renameRecordColumns()` eine Tabelle, die noch anders
        // heisst, fände sie nicht und kehrte still um (TASK-014).
        self::renameRecordTables();

        self::renameRecordColumns();

        // ⚠️ *Vor `dbDelta`, wie jede Umbenennung hier: es kennt keine und legte `node_record_id`
        // neben `record_id`, mit den Daten in der Spalte, die niemand mehr liest (TASK-014).*
        self::renameRelationRecordColumns();

        // ⚠️ *Ebenfalls vor `dbDelta`, aus demselben Grund — und **vor**
        // {@see self::moveTestFlagIntoKind()}, die für eine sehr alte Installation in die Spalte
        // schreibt, die hier gerade ihren heutigen Namen bekommt (TASK-015).*
        self::renameRecordTypeColumn();

        // ⚠️ *Ebenfalls vor `dbDelta`, aus demselben Grund: es kennt keine Umbenennung und legte
        // `field_type` neben `kind`, mit den Daten in der Spalte, die niemand mehr liest (TASK-007).*
        self::renameNodeKindColumn();

        // ⚠️ **Vor der Umbenennung von `position`, und die Reihenfolge ist keine Geschmacksfrage:**
        // *deren Schlüssel lautet schon auf `from_node_id` (TASK-010, TASK-012).*
        self::renameRelationNodeColumns();

        // ⚠️ **Vor `dbDelta`, und dazu der Grund für die Reihenfolge *innerhalb* des Schritts:** *er
        // benennt um **und räumt die eine echte Doppelung weg**, bevor `dbDelta` den eindeutigen
        // Schlüssel `(from_node_id, kind, sort_order)` anlegt. Umgekehrt wiese MySQL den Schlüssel zurück
        // — still, wie `$wpdb` es tut (TASK-012).*
        self::renameRelationPositionColumn();

        // ⚠️ **Ebenfalls vor `dbDelta`, und aus demselben Grund wie die Umbenennung darüber**
        // (TASK-004): *solange die Bedingungen auf `identities` stehen, kann keine der beiden
        // Id-Spalten zu `AUTO_INCREMENT` werden — MySQL weist die Änderung an einer gebundenen
        // Spalte zurück, und `$wpdb` sagt darüber nichts.*
        self::dropIdentityForeignKeys();

        // ⚠️ **Vor `dbDelta`, und aus einem eigenen Grund** (Fassung 34, TASK-019): *`dbDelta` fügt
        // fehlende Spalten hinzu und entfernt keine — es könnte die alte, hohe `labels` nie in die
        // neue, flache verwandeln. **Also tritt die alte zur Seite und die neue wird frisch
        // gebaut**; {@see self::moveNamesIntoLabels()} schöpft danach aus ihr und lässt sie erst
        // fallen, wenn Zeile für Zeile nachgewiesen ist, dass nichts fehlt.*
        self::setOldLabelsAside();

        foreach (self::statements() as $sql) {
            dbDelta($sql);
        }

        // ⚠️ *Nach `dbDelta`, weil die Spalte dastehen muss, bevor sie verschoben werden kann
        // (TASK-015).*
        self::orderRecordColumns();

        self::giveEveryTableItsOwnIdSpace();
        self::dropRetiredColumns();
        self::widenSettingUniqueKey();
        self::moveHideOutOfSettings();
        self::shortenRangeKeys();
        self::moveTestFlagIntoKind();
        self::dropTheOneValueKey();
        self::moveMultiplicityOntoTheRelation();
        self::nameTheReferenceSpace();
        self::dropIdentitiesTable();
        self::dropSettingsTable();

        // ⚠️ **Nach `dbDelta`, und das ist hier zwingend:** *`dbDelta` legt eine fehlende Spalte
        // wieder an, es entfernt keine. Die Wanderung muss also laufen, wenn die Tabelle steht — und
        // sie räumt die Zeilen weg, bevor sie die Spalte fallen lässt (TASK-013).*
        self::moveParkingIntoTheShadow();

        // ⚠️ **Nach `dbDelta`, weil die drei Spalten dastehen müssen, bevor etwas hineinwandert** —
        // und **vor** {@see self::constrainRelationsToNodes()}, weil die Wanderung 136 Kantenzeilen
        // entfernt und eine Bedingung, die auf sie zeigte, im Weg stünde (TASK-018).
        self::moveInheritanceOntoTheNode();

        // ⚠️ **Nach `dbDelta`, weil die Spalte dastehen muss, bevor etwas hineingeschrieben wird** —
        // und nach {@see self::moveInheritanceOntoTheNode()}, weil erst danach feststeht, welche
        // Kanten es überhaupt noch gibt (Fassung 31, `INF-035`).
        self::nameTheLabelSpace();

        // ⚠️ **Nach `dbDelta` und aus demselben Grund wie {@see self::moveParkingIntoTheShadow()}:**
        // *`dbDelta` legt eine fehlende Spalte wieder an, es entfernt keine (Fassung 32, TASK-057).*
        self::dropSettingsRecordColumns();

        // ⚠️ **Nach `dbDelta`, aus demselben Grund** (Fassung 33, TASK-059,
        // [D-621](../../../docs/NewConcept/90-decision-log.md)).
        self::dropNodeFieldTypeColumn();

        // ⚠️ **Nach `dbDelta`, weil `label_texts`, `labels.icon` und die beiden `label_id` dastehen
        // müssen, bevor etwas hineinwandert** (Fassung 34, TASK-019).
        self::moveNamesIntoLabels();

        // ⚠️ **Nach `dbDelta` und aus demselben Grund wie die drei Schritte darüber** (Fassung 35,
        // TASK-001) — *und **vor** {@see self::buildTheReadableViews()}: eine Sicht auf `q.*` friert
        // die Spaltenliste beim Anlegen ein und trüge sonst eine Spalte weiter, die es nicht gibt.*
        self::dropNodePathColumn();

        // ⚠️ **Nach `dbDelta`, weil er Zeilen liest und keine Spalten anfasst** (Fassung 36,
        // [D-653](../../../docs/NewConcept/90-decision-log.md)).
        self::dropEmptyDefaultRecords();

        // ⚠️ **Nach `dbDelta`, weil `node_records.relation_id` dastehen muss, bevor etwas
        // hineinwandert — und *vor* dem Abbau der Pfadspalte, aus der gelesen wird** (Fassung 37,
        // [D-667](../../../docs/NewConcept/90-decision-log.md), TASK-002).
        self::moveUseSiteSettingsIntoTheirOwnRecord();

        // ⚠️ **Unmittelbar danach, und die Reihenfolge ist die ganze Vorsicht** (Fassung 39,
        // TASK-002): *der Schritt darüber ist der **einzige**, der die Spalte noch liest — er hängt
        // die zweiteiligen Zeilen an ihren eigenen Satz. Erst wenn er gelaufen ist, sagt der Pfad
        // nichts mehr, was nicht daneben steht, und **genau das prüft dieser Schritt nach**, bevor er
        // löscht. **Vor `buildTheReadableViews()`**, aus demselben Grund wie Fassung 35: eine Sicht
        // auf `q.*` friert die Spaltenliste beim Anlegen ein.*
        self::dropRelationRecordPathColumn();

        // ⚠️ **Nach der Wanderung, weil sie auf `label_texts` steht** (Fassung 34, TASK-019).
        self::buildTheReadableViews();

        // ⚠️ *Zuletzt: die Bedingung darf erst stehen, wenn die Spalten heissen wie sie heissen und
        // jeder Aufräumschritt darüber gelaufen ist (TASK-010).*
        self::constrainRelationsToNodes();

        // ⚠️ **Fassung 41: unter `Primitives` und `Settings` gibt es keine Benutzersätze**
        // ([D-677](../../../docs/NewConcept/90-decision-log.md), [D-691](../../../docs/NewConcept/90-decision-log.md), TASK-077).
        self::dropUserRecordsUnderPrimitivesAndSettings();

        // ⚠️ **Fassung 42: die vierte Satzart, und die Grenzen wohnen in den Grenzknoten**
        // ([D-704](../../../docs/NewConcept/90-decision-log.md), [D-707](../../../docs/NewConcept/90-decision-log.md), TASK-083).
        self::separateSettingsRecords();

        // ⚠️ **Fassung 44: die Kante `allowed` an `Prefixes`** ([D-697](../../../docs/NewConcept/90-decision-log.md), TASK-068).

        // ⚠️ **Fassung 45: die Einstellung `position` an der Wurzel — ein Kind ordnet geerbte Felder**
        // ([D-698](../../../docs/NewConcept/90-decision-log.md), TASK-087).
        self::declarePositionAtRoot();

        // ⚠️ **Fassung 46: jeder Knoten bekommt seine Klasse** ([D-716](../../../docs/NewConcept/90-decision-log.md),
        // [D-719](../../../docs/NewConcept/90-decision-log.md), TASK-092). *Nach `dbDelta`, weil die
        // Spalte dastehen muss; nach den Sichten, weil `Currency` über `nodes_named` gefunden wird.*
        self::assignNodeClasses();

        // ⚠️ **Fassung 47: die Fremdschlüssel der Einstellungstabellen** ([D-712](../../../docs/NewConcept/90-decision-log.md),
        // Anforderung 4.2.3). *Nach `dbDelta`, weil die Tabellen dastehen müssen — `dbDelta` legt
        // keine Bedingungen an, also hier, wie {@see self::constrainRelationsToNodes()}.*
        self::constrainSettingsValues();

        // ⚠️ **Fassung 48: `read_only` wird Spalte der Kante, die Einstellungskante wandert**
        // ([D-714](../../../docs/NewConcept/90-decision-log.md), TASK-095). *Nach `dbDelta`, weil die
        // Spalte dastehen muss; nach den Sichten, weil die Kante über `relations_named` gefunden wird.*
        self::moveReadOnlyOntoTheRelation();
        self::dropTheSettingsBranch();

        // ⚠️ **Fassung 50: `chooser-dialog` und `chooser-inline` werden `chooser` mit Schalter** ([D-727](../../../docs/NewConcept/90-decision-log.md)).
        self::mergeTheChoosers();

        // ⚠️ **Fassung 52: `with_parent` am Verweis heisst `use_parent_label`** ([D-746](../../../docs/NewConcept/90-decision-log.md)).
        self::renameWithParent();

        // ⚠️ **Fassung 53: `settings_value.wert_kante_id` — ein Verweis auf ein Feld als Einstellungswert** ([D-752](../../../docs/NewConcept/90-decision-log.md)).
        // *Die Spalte legt `dbDelta` aus der Anweisung an; der Schatten muss seinen Spaltenplan vergessen.*
        self::placeWertKanteId();

        // ⚠️ **Ganz zuletzt, und als einzige Wanderung nach allem anderen** (Fassung 38,
        // [D-672](../../../docs/NewConcept/90-decision-log.md)): *sie ist die einzige, die den
        // **Kern** fragt statt eine Spalte zu lesen — {@see \Taxmod\Core\Service\Rendering::choicesForNode()}
        // will Knoten, Kanten und Sätze so vorfinden, wie sie am Ende dastehen, nicht mitten im
        // Umbau.*
    }

    /**
     * Fassung 31: **`labels.owner_id` nennt ihren Raum** — und die Zeile bekommt eine Version.
     *
     * ⚠️ **Der Anlass ist gemessen und nicht hergeleitet** (`INF-035`, 2026-09-05): *`package5-check`
     * fragte die Beschriftungen einer frisch angelegten **Kante** ab und bekam **sechs** Zeilen statt
     * einer — die Kante trug dieselbe Nummer wie ein zwei Zeilen zuvor entstandener **Knoten**, der
     * schon fünf Beschriftungen hatte.*
     *
     * ⚠️ **Die Lücke ist alt, TASK-018 hat sie nur ausgelöst.** *Seit TASK-004 hat jede Tabelle ihren
     * eigenen Id-Raum, und beide begannen bei derselben Zahl. Solange ein neuer Knoten **immer** auch
     * eine Vererbungskante anlegte, liefen die zwei Zähler im Gleichschritt; seit
     * [D-581](../../../docs/NewConcept/90-decision-log.md) ein Knoten keine Kante mehr anlegt, laufen
     * sie verschieden schnell — und dann treffen sie sich.*
     *
     * ⚠️ **Das Muster ist vorgegeben und wird nicht neu erfunden** ([D-164](../../../docs/NewConcept/90-decision-log.md),
     * [D-597](../../../docs/NewConcept/90-decision-log.md), [`package.md` §6](../../../docs/pakete/modelltabellen/package.md)):
     * *«Kann eine Spalte auf mehr als eine Tabelle zeigen, nennt eine zweite Spalte den Raum» —
     * `changelog.owner_kind`, `relation_records.value_ref_kind`, und jetzt `labels.owner_kind`.*
     *
     * ⚠️ **Umkehrbar ohne Schattenzeilen, und das ist keine Nachlässigkeit** (`PR-9`): *dieser Schritt
     * **löscht nichts und schreibt keinen Text um**. Er füllt eine neue Spalte, deren voriger Wert
     * bekanntlich leer war; ein Schatten hielte davon nichts fest, was nicht schon feststünde. **Was
     * er sich nicht zutraut, lässt er leer**: eine Nummer, die zugleich Knoten und Kante ist, und eine,
     * die keines von beidem ist, bleiben ohne Raum stehen und werden gezählt statt geraten (`PR-4`).*
     *
     * ⚠️ *Zweimal ausführbar: ein zweiter Lauf findet keine leere Spalte mehr und nur noch den
     * fertigen Schlüssel.*
     */
    private static function nameTheLabelSpace(): void
    {
        global $wpdb;

        $labels    = self::table('labels');
        $nodes     = self::table('nodes');
        $relations = self::table('relations');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $labels)) !== $labels) {
            return;
        }

        if (! self::hasColumn($labels, 'owner_kind')) {
            return;
        }

        // ⚠️ *Seit Fassung 34 ist `labels` flach: der Raum steht weiter darin, `owner_id` und die
        // Textspalten nicht mehr. Dieser Schritt gehört der alten Form und hat dort nichts zu suchen.*
        if (! self::hasColumn($labels, 'owner_id')) {
            return;
        }

        $vorher = self::countedLabels($labels);

        $wpdb->query(
            "UPDATE {$labels} l
             SET l.owner_kind = 'node'
             WHERE l.owner_kind = ''
               AND EXISTS (SELECT 1 FROM {$nodes} n WHERE n.id = l.owner_id)
               AND NOT EXISTS (SELECT 1 FROM {$relations} r WHERE r.id = l.owner_id)"
        );

        $wpdb->query(
            "UPDATE {$labels} l
             SET l.owner_kind = 'relation'
             WHERE l.owner_kind = ''
               AND EXISTS (SELECT 1 FROM {$relations} r WHERE r.id = l.owner_id)
               AND NOT EXISTS (SELECT 1 FROM {$nodes} n WHERE n.id = l.owner_id)"
        );

        $nachher = self::countedLabels($labels);

        // ⚠️ **Zahl und Text sind die Zusage: dieselbe Menge Beschriftungen, jede mit demselben Text
        // an derselben Stelle** (`PR-9`). *Verglichen wird über eine Prüfsumme aus Eigentümer, Pfad,
        // Rolle, Numerus, Locale und Text — an gezählten Zahlen und nicht an Namen.*
        if ($vorher['rows'] !== $nachher['rows'] || $vorher['texts'] !== $nachher['texts']) {
            throw new \RuntimeException(
                'Fassung 31: die Beschriftungen nach dem Benennen des Raums sind nicht die von vorher. '
                . 'Vorher ' . wp_json_encode($vorher) . ', nachher ' . wp_json_encode($nachher) . '. '
                . 'Es wurde nichts geloescht; die Fassungsnummer bleibt stehen.'
            );
        }

        self::widenLabelUniqueKey($labels);

        // ⚠️ *Die Zahlen bleiben stehen, damit `label-space-check` sie **vergleichen** kann, statt sie
        // nachzurechnen — dieselbe Vorsorge wie bei TASK-018.*
        update_option('taxmod_labelspace_shape', $nachher, false);
    }

    /**
     * Die Beschriftungen, gezählt: wie viele Zeilen, wie viele je Raum, und eine Prüfsumme über
     * Stelle und Text.
     *
     * @return array{rows: int, node: int, relation: int, homeless: int, texts: string}
     */
    private static function countedLabels(string $labels): array
    {
        global $wpdb;

        return [
            'rows'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labels}"),
            'node'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labels} WHERE owner_kind = 'node'"),
            'relation' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labels} WHERE owner_kind = 'relation'"),
            'homeless' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labels} WHERE owner_kind = ''"),
            // ⚠️ *`owner_kind` steht bewusst **nicht** in der Prüfsumme: sie soll gleich bleiben,
            // während genau diese Spalte sich füllt.*
            'texts'    => (string) $wpdb->get_var(
                "SELECT COALESCE(MD5(GROUP_CONCAT(x.z ORDER BY x.z SEPARATOR '|')), '')
                 FROM (SELECT CONCAT_WS(':', owner_id, path, role_id, number, locale, text) z
                       FROM {$labels}) x"
            ),
        ];
    }

    /**
     * Der eindeutige Schlüssel nimmt den Raum auf — sonst schlössen sich Knoten 5 und Kante 5 aus.
     *
     * ⚠️ **Nach `dbDelta` und von Hand, weil `dbDelta` das nicht kann:** *es vergleicht Schlüssel über
     * ihren **Namen**; einen bestehenden `one_text` mit fünf Spalten ersetzt es nicht durch einen mit
     * sechs, sondern versucht, ihn ein zweites Mal anzulegen — und **`$wpdb` sagt darüber nichts**.*
     *
     * ⚠️ *Der neue Schlüssel geht hinein, bevor der alte herauskommt — dieselbe Reihenfolge wie bei
     * `widenSettingUniqueKey()`, und aus demselben Grund: schlägt das Anlegen fehl, bleibt der alte
     * stehen und die Tabelle ist nie ohne ihre Zusage.*
     */
    private static function widenLabelUniqueKey(string $labels): void
    {
        global $wpdb;

        $spalten = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
             ORDER BY SEQ_IN_INDEX',
            $labels,
            'one_text'
        ));

        if ($spalten === [] || in_array('owner_kind', $spalten, true)) {
            return;
        }

        $wpdb->query("ALTER TABLE {$labels} ADD UNIQUE KEY one_text_kind (owner_id,owner_kind,path,role_id,number,locale)");

        if ($wpdb->last_error !== '') {
            return;
        }

        $wpdb->query("ALTER TABLE {$labels} DROP INDEX one_text");
        $wpdb->query("ALTER TABLE {$labels} RENAME INDEX one_text_kind TO one_text");
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
     * (`moveHideOutOfSettings()`, `moveMultiplicityOntoTheRelation()`), und jeder von ihnen prüft mit
     * `SHOW TABLES`, ob es sie gibt. **Eine Installation, die von Fassung 9 kommt, wandert also
     * vollständig, bevor hier gelöscht wird.***
     */
    /**
     * Fassung 27: die heute geparkten Kanten wandern in den Schatten, dann fällt die Spalte.
     *
     * ⚠️ **Die Reihenfolge ist die ganze Sicherheit** ([D-619](../../../docs/NewConcept/90-decision-log.md)):
     * *erst der Schatten, dann die Wertzeilen, dann die lebende Zeile, und zuletzt die Spalte. Wer die
     * Spalte zuerst fallen liesse, hätte 14 Kanten, die lebend und unauffällig dastehen und von denen
     * niemand mehr weiss, dass sie entfernt waren.*
     *
     * ⚠️ *Sie läuft nur, solange es die Spalte gibt — ein zweiter Aufruf findet nichts zu tun und
     * kehrt um. **Umkehrbar bleibt sie über den Schatten**, der Gruppe und Inhalt beide hält.*
     */
    /**
     * Fassung 36: **die leeren `default`-Sätze fallen** ([D-653](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ```mermaid
     * flowchart LR
     *   Z["zaehlen"] --> S["Schatten"] --> L["loeschen"] --> N["nachzaehlen"] --> P{"stimmt?"}
     *   P -- nein --> A["Ausnahme · die Fassung bleibt stehen"]
     * ```
     *
     * ⚠️ **Sein Wort:** *«der default-Satz sollte nicht leer bestehen».* **Gemessen am 2026-09-06:
     * 390 von 454 `default`-Sätzen tragen keine einzige Wertzeile, 64 tragen etwas** (dazu 95 `user`).
     * *[D-609](../../../docs/NewConcept/90-decision-log.md) hat dasselbe für das **Anlegen** schon
     * entschieden — «ein Datensatz entsteht beim ersten Schreiben, nicht beim Ansehen» —, nur bestehen
     * die alten weiter.*
     *
     * ⚠️ **Nur `default`, und das ist keine Vorsicht, sondern die Entscheidung.** *Ein leerer
     * `user`-Satz ist eine **Eingabe, die noch leer ist**; ein leerer `default` ist eine Vorgabe, die
     * nichts vorgibt ([D-654](../../../docs/NewConcept/90-decision-log.md): «der default macht eine
     * Vorgabe, die auch bei der Eingabe verwendet werden soll»).*
     *
     * ⚠️ **Ein Satz, auf den eine Wertzeile zeigt, bleibt stehen — auch wenn er leer ist.**
     * *[D-610](../../../docs/NewConcept/90-decision-log.md) hat genau davor gewarnt und die Zahl
     * genannt: **26 leere Sätze waren die Renderer-Wahl** — «compact ist gewählt, nichts daran
     * eingestellt», und die Aussage steckt in ihrer `node_id`. **Wer sie als leer wegräumt, löscht 26
     * Renderer-Wahlen.** Seit TASK-057 hängt der Zeiger in einer Wertzeile, also ist die Bedingung
     * hier `value_ref` und nicht mehr eine Spalte am Knoten.*
     *
     * ⚠️ **Gezählt davor, gezählt danach, und bei Abweichung eine Ausnahme** (`PR-9`): *«so viele
     * sollten fallen» ist eine Vermutung; **hier ist es die Bedingung dafür, dass es geschieht.**
     * Bricht der Schritt ab, bleibt die Fassungsnummer stehen und der nächste Lauf beginnt von vorn.*
     *
     * ⚠️ **Und er sichert im Fassungsschritt selbst, nicht in einem Skript daneben.** *`require
     * wp-load.php` lässt den Rand die Fassung heben, **bevor** ein Skript daneben seine erste Zeile
     * sichern könnte — dann liest es «vorher» und misst «nachher». Diese Falle hat am 2026-09-06
     * dreimal zugeschnappt; hier kann sie es nicht.*
     *
     * ⚠️ *Zweimal ausführbar: ein zweiter Lauf findet nichts mehr und tut nichts.*
     */
    private static function dropEmptyDefaultRecords(): void
    {
        global $wpdb;

        $saetze = self::table('node_records');
        $werte  = self::table('relation_records');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $saetze)) !== $saetze) {
            return;
        }

        if (! self::hasColumn($saetze, 'record_type')) {
            return;
        }

        // ⚠️ **Seit Fassung 48 auch die Einstellungssätze** ([D-714](../../../docs/NewConcept/90-decision-log.md)):
        // *das Parken der Einstellungskante `read_only` nahm ihre Wertzeilen mit ([D-619](../../../docs/NewConcept/90-decision-log.md))
        // und liess sieben `settings`-Sätze leer zurück — gemessen am 2026-09-11 durch
        // `record-on-first-write-check`. Ein leerer Satz sagt nichts und fällt, gleich welcher Art.*
        $bedingung = "s.record_type IN ('default', 'settings')
             AND NOT EXISTS (SELECT 1 FROM {$werte} v WHERE v.node_record_id = s.id)
             AND NOT EXISTS (SELECT 1 FROM {$werte} h WHERE h.value_ref = s.id AND h.value_ref_kind = 'record')";

        /** @var list<array{id: string, version: string, node_id: string}> $leer */
        $leer = $wpdb->get_results(
            "SELECT s.id, s.version, s.node_id FROM {$saetze} s WHERE {$bedingung}",
            ARRAY_A
        ) ?: [];

        if ($leer === []) {
            return;
        }

        $vorher = count($leer);
        $log    = new WpdbChangelog(new SystemClock());
        $gruppe = null;

        foreach ($leer as $zeile) {
            $id = (int) $zeile['id'];

            Shadow::keepOne('node_records', $id, true);

            $wpdb->delete($saetze, ['id' => $id], ['%d']);

            // ⚠️ *Eine Gruppe für die ganze Wanderung: sie ist **eine** Handlung an seinen Daten, und
            // ein Rückgängig, das 390 Einzelschritte wäre, ist keines
            // ([D-348](../../../docs/NewConcept/90-decision-log.md)).*
            $gruppe = $log->record(
                $id,
                'record',
                'empty default record dropped',
                'node ' . (int) $zeile['node_id'],
                null,
                (int) $zeile['version'],
                $gruppe
            );
        }

        // ⚠️ **Nachgezählt, und die Abweichung ist ein Abbruch und keine Meldung.** *Bleibt auch nur
        // einer stehen, hat `$wpdb` still versagt ({@see Query}) — und eine halb gelaufene Wanderung,
        // die sich als fertig einträgt, ist schlimmer als eine, die gar nicht lief.*
        $geblieben = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$saetze} s WHERE {$bedingung}");

        // ⚠️ *Die Ids kommen aus einer `(int)`-Umwandlung und nicht aus der Eingabe — nichts wird
        // interpoliert, was ein Zeichen sein könnte (`CD-6`).*
        $ids        = implode(',', array_map(static fn (array $z): int => (int) $z['id'], $leer));
        $imSchatten = (int) $wpdb->get_var(
            'SELECT COUNT(DISTINCT id) FROM ' . self::table('node_records_history') . " WHERE id IN ({$ids})"
        );

        if ($geblieben !== 0 || $imSchatten !== $vorher) {
            throw new \RuntimeException(
                'Fassung 36: ' . $vorher . ' leere default-Saetze sollten fallen, '
                . $geblieben . ' stehen noch, ' . $imSchatten . ' liegen im Schatten. '
                . 'Die Fassungsnummer bleibt stehen.'
            );
        }
    }

    private static function moveParkingIntoTheShadow(): void
    {
        global $wpdb;

        $relations = self::table('relations');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $relations)) !== $relations) {
            return;
        }

        $vorhanden = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $relations,
            'parked_by_group_id'
        ));

        if ($vorhanden === []) {
            return;
        }

        /** @var list<array{id: string, parked_by_group_id: string}> $geparkt */
        $geparkt = $wpdb->get_results(
            "SELECT id, parked_by_group_id FROM {$relations} WHERE parked_by_group_id IS NOT NULL",
            ARRAY_A
        ) ?: [];

        // ⚠️ *Der Spaltenplan von `Shadow` ist je Tabelle gemerkt, und `parked_by_group_id` fällt
        // gleich aus ihm heraus — sonst kopierte der nächste Aufruf eine Spalte, die es nicht mehr
        // gibt, und `Shadow::keep()` würde zu Recht scheitern.*
        Shadow::forgetColumnPlan();

        foreach ($geparkt as $zeile) {
            Shadow::keepOne('relations', (int) $zeile['id'], true);

            Shadow::keep('relation_records', 'relation_id = %d', [(int) $zeile['id']], true);

            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . self::table('relation_records') . ' WHERE relation_id = %d',
                (int) $zeile['id']
            ));

            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$relations} WHERE id = %d",
                (int) $zeile['id']
            ));
        }

        $wpdb->query("ALTER TABLE {$relations} DROP COLUMN parked_by_group_id");

        Shadow::forgetColumnPlan();
    }

    /**
     * Die drei Spalten aus TASK-020 fallen — am Knoten, an der Kante, und in beiden Schatten.
     *
     * ⚠️ **Sie waren nie die Form, die der Eigentümer gemeint hat** ([D-642](../../../docs/NewConcept/90-decision-log.md)).
     * *«das hast du leider falsch verstanden, ich meinte einfach eine Multiplizität von 1» — **am
     * Knoten**, an einer gewöhnlichen Einstellungskante. Der Renderer hängt seit TASK-057 dort, und
     * die 29 Träger sind über [`renderer-relation-migrate.php`](../../../scripts/dev/renderer-relation-migrate.php)
     * gewandert.*
     *
     * ⚠️ **An der Kante fällt der Renderer ersatzlos** ([D-643](../../../docs/NewConcept/90-decision-log.md)):
     * *`relations.settings_record_id` und `relations.target_settings_record_id` hatten **je 0
     * Zeilen** — nie belegt, seit es sie gab. Eine Kante ist eine Verwendungsstelle und zeichnet
     * nicht selbst.*
     *
     * ⚠️ **Die Schattenspalten gehen mit, und das ist kein Datenverlust an der Geschichte, sondern
     * die Bedingung dafür, dass sie weiter funktioniert:** *{@see Shadow::keep()} kopiert nach dem
     * Spaltenplan der lebenden Tabelle. Bliebe die Spalte nur im Schatten stehen, fehlte ihr jeder
     * Schreiber — und `NOT NULL` ist sie nicht, also stünde dort auf Dauer eine Spalte voll `NULL`.*
     *
     * ⚠️ **Wer noch eine gefüllte Spalte findet, hält an**, statt sie wegzuwerfen: *eine Installation,
     * die die Wanderung nicht gelaufen hat, verlöre hier still ihre 29 Renderer-Wahlen. Der Abbruch
     * ist die einzige Stelle, an der das noch auffallen kann.*
     */
    private static function dropSettingsRecordColumns(): void
    {
        global $wpdb;

        $offen = 0;

        foreach ([['nodes', ['settings_record_id']], ['relations', ['settings_record_id', 'target_settings_record_id']]] as [$tabelle, $spalten]) {
            $name = self::table($tabelle);

            foreach ($spalten as $spalte) {
                if ($wpdb->get_var("SHOW COLUMNS FROM {$name} LIKE '{$spalte}'") === null) {
                    continue;
                }

                $offen += (int) $wpdb->get_var("SELECT COUNT(*) FROM {$name} WHERE {$spalte} IS NOT NULL");
            }
        }

        if ($offen > 0) {
            // ⚠️ *Still umkehren und nicht werfen: eine Aktivierung darf nicht mit einem Fatal enden.
            // Der Wächter `settings-record-column-check.php` meldet denselben Befund laut.*
            return;
        }

        foreach ([
            ['nodes', ['settings_record_id']],
            ['nodes_history', ['settings_record_id']],
            ['relations', ['settings_record_id', 'target_settings_record_id']],
            ['relations_history', ['settings_record_id', 'target_settings_record_id']],
        ] as [$tabelle, $spalten]) {
            $name = self::table($tabelle);

            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) {
                continue;
            }

            foreach ($spalten as $spalte) {
                if ($wpdb->get_var("SHOW COLUMNS FROM {$name} LIKE '{$spalte}'") === null) {
                    continue;
                }

                $wpdb->query("ALTER TABLE {$name} DROP COLUMN {$spalte}");
            }
        }

        // ⚠️ *Der Spaltenplan von {@see Shadow} ist je Tabelle gemerkt — sonst kopierte der nächste
        // Aufruf eine Spalte, die es nicht mehr gibt.*
        Shadow::forgetColumnPlan();
    }

    /**
     * `nodes.field_type` fällt — am Knoten und im Schatten (Fassung 33, TASK-059).
     *
     * ⚠️ **Die zweite Hälfte von [D-621](../../../docs/NewConcept/90-decision-log.md), und er hat sie
     * eingefordert:** *«aber der Rueckbau am Knoten gehoert doch fachlich dazu, wie kannst du das dann
     * stehen lassen?»* *TASK-032 hatte nur die Kantenseite gebaut.*
     *
     * ⚠️ **Was an ihre Stelle tritt, steht schon da:** *die eingehenden Kanten. Ein Knoten ist selbst
     * eine Einstellung, wenn jede Kante auf ihn eine Einstellungskante ist; wen nur Vererbung
     * erreicht, beantwortet die Kante über seinem nächsten Vorfahren
     * ({@see WpdbNodeRepository::resolvedFieldTypes()}). **Es wird nichts umgerechnet und nichts
     * gefüllt** — die Antwort war die ganze Zeit ableitbar, und die Spalte war die Doppelung.*
     *
     * ⚠️ **Umkehrbar, und darum sichert dieser Schritt selbst, bevor er löscht** (`PR-9`): *jede
     * markierte Zeile geht als Schattenzeile fort, und jede bekommt eine Journalzeile mit **Version**
     * ([D-634](../../../docs/NewConcept/90-decision-log.md)) unter **einer** Änderungsgruppe
     * ([D-348](../../../docs/NewConcept/90-decision-log.md)). **Das Sichern gehört hierher und nicht
     * in ein Skript daneben:** *ein Skript läuft auf dieser einen Installation, die Fassung läuft auf
     * jeder — und ein Sichern, das die Wanderung überholen kann, ist keines. **Genau das ist am
     * 2026-09-05 passiert**, weil das Laden von WordPress die Fassung hebt, bevor ein Skript zum
     * Schreiben kommt.*
     *
     * ⚠️ *Zweimal ausführbar: ein zweiter Lauf findet keine Spalte mehr.*
     */
    private static function dropNodeFieldTypeColumn(): void
    {
        global $wpdb;

        $nodes = self::table('nodes');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $nodes)) !== $nodes) {
            return;
        }

        if ($wpdb->get_var("SHOW COLUMNS FROM {$nodes} LIKE 'field_type'") === null) {
            return;
        }

        self::keepFieldTypesBeforeDropping($nodes);

        $gefallen = false;

        foreach (['nodes', 'nodes_history'] as $tabelle) {
            $name = self::table($tabelle);

            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) {
                continue;
            }

            if ($wpdb->get_var("SHOW COLUMNS FROM {$name} LIKE 'field_type'") === null) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$name} DROP COLUMN field_type");
            $gefallen = true;
        }

        if ($gefallen) {
            // ⚠️ *Der Spaltenplan von {@see Shadow} ist je Tabelle gemerkt — sonst kopierte der
            // nächste Aufruf eine Spalte, die es nicht mehr gibt.*
            Shadow::forgetColumnPlan();
        }
    }

    /**
     * Die Marken in den Schatten und ins Journal, bevor die Spalte fällt (Fassung 33).
     *
     * ⚠️ **Erst der Schatten, dann das Journal**, und die Reihenfolge ist überall dieselbe: *die
     * Schattenzeile ist das, woraus man zurückkommt; die Journalzeile ist nur der Hinweis darauf.*
     *
     * ⚠️ *Eine Änderungsgruppe für alle Zeilen — es ist **ein** Akt
     * ([D-348](../../../docs/NewConcept/90-decision-log.md)), und ein Rückgängig, das nur eine der 39
     * Marken zurückholte, wäre kein Rückgängig dieses Schritts.*
     */
    private static function keepFieldTypesBeforeDropping(string $nodes): void
    {
        global $wpdb;

        $markiert = $wpdb->get_results(
            "SELECT id, version, field_type FROM {$nodes} WHERE field_type IS NOT NULL AND field_type <> ''",
            ARRAY_A
        ) ?: [];

        if ($markiert === []) {
            return;
        }

        $log    = new WpdbChangelog(new SystemClock());
        $gruppe = null;

        foreach ($markiert as $zeile) {
            $id = (int) $zeile['id'];

            Shadow::keepOne('nodes', $id);

            $gruppe = $log->record(
                $id,
                'node',
                'field type dropped',
                (string) $zeile['field_type'],
                null,
                (int) $zeile['version'],
                $gruppe
            );
        }
    }

    /**
     * `nodes.path` fällt — die letzte der vier Pfadspalten (Fassung 35, TASK-001).
     *
     * ```mermaid
     * flowchart LR
     *   V["parent_node_id"] --> R["gerechneter Weg"]
     *   P["gespeicherter Weg"] -.wird verglichen.-> R
     *   R --> D["Spalte faellt"]
     * ```
     *
     * ⚠️ **Der Weg war zweimal gespeichert, und das ist der ganze Befund**
     * ([`review-tabellen.md`](../../../docs/review-tabellen.md) §A). *Seit TASK-018 ist
     * `parent_node_id` der Baum ([D-581](../../../docs/NewConcept/90-decision-log.md)); die
     * punktseparierte Kette daneben ist seine Abkürzung, und
     * [D-082](../../../docs/NewConcept/90-decision-log.md) hat sie von Anfang an «derived and
     * rebuildable» genannt. **Ein `Node` trägt sie weiter** — sie wird beim Lesen gerechnet
     * ({@see WpdbNodeRepository::ancestry()}); was fällt, ist die zweite Ablage, nicht die Tatsache.*
     *
     * ⚠️ **Der Schritt vergleicht, bevor er löscht, und kehrt bei der ersten Abweichung um.** *«Der
     * gerechnete Weg ist derselbe wie der gespeicherte» ist keine Vermutung, die man nach dem Löschen
     * nicht mehr prüfen kann — **hier ist sie die Bedingung dafür, dass gelöscht wird.** Findet er
     * eine einzige Zeile, bei der die beiden auseinandergehen, bleibt die Spalte stehen und die
     * Fassungsnummer bleibt, wo sie war.*
     *
     * ⚠️ **Und er sichert im Fassungsschritt selbst, nicht in einem Skript daneben** (`PR-9`,
     * derselbe Grund wie bei {@see self::dropNodeFieldTypeColumn()}): *jede Zeile geht als
     * Schattenzeile fort — dort **behält** sie ihren Weg ({@see self::SHADOW_ONLY_IN}) — und bekommt
     * eine Journalzeile mit ihrer **Version** ([D-634](../../../docs/NewConcept/90-decision-log.md))
     * unter **einer** Änderungsgruppe ([D-348](../../../docs/NewConcept/90-decision-log.md)). *Ein
     * Skript, das WordPress lädt, kommt zu spät: das Laden hebt die Fassung, bevor das Skript seine
     * erste Zeile sichert.*
     *
     * ⚠️ *Die Zahlen bleiben in einer Option stehen, damit `path-check` sie **vergleichen** kann,
     * statt sie nachzurechnen — dieselbe Vorsorge wie bei TASK-018 und Fassung 31.*
     *
     * ⚠️ *Zweimal ausführbar: ein zweiter Lauf findet keine Spalte mehr.*
     */
    /**
     * Fassung 37: **die Einstellungen einer Verwendungsstelle bekommen ihren eigenen Satz**
     * ([D-667](../../../docs/NewConcept/90-decision-log.md), TASK-002).
     *
     * ⚠️ **Was hier wandert, ist gemessen und klein:** *118 Wertzeilen tragen einen Pfad, **116 davon
     * einteilig** — und bei allen 116 ist er die blosse Wiederholung von `relation_id` (gezählt am
     * 2026-09-06: null Abweichungen). **Zwei Zeilen sind zweiteilig**, und nur sie sagen etwas, was
     * sonst nirgends steht: die zwei Feldbreiten an `Street Name` und `House Number`.*
     *
     * ⚠️ **Der Satz gehört danach der Kante, und `node_id` bleibt der Halter.** *Ein Satz ohne
     * `relation_id` ist der eines Knotens, wie bisher; einer mit ihr gehört dieser
     * Verwendungsstelle. **Beides in einer Tabelle, weil es dasselbe Ding ist** — ein Datensatz.*
     *
     * ⚠️ **Umkehrbar, solange die Spalte steht, und danach steht die Geschichte.** *Die Schattenzeilen
     * behalten ihren `path`; Geschichte wird nicht umgeschrieben
     * ([D-065](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ```mermaid
     * flowchart LR
     *   A["Wertzeile · Pfad «812.97»"] --> B["Satz der Kante 812"]
     *   B --> C["Wertzeile · relation_id 97, kein Pfad"]
     * ```
     */
    private static function moveUseSiteSettingsIntoTheirOwnRecord(): void
    {
        global $wpdb;

        $saetze = self::table('node_records');
        $werte  = self::table('relation_records');

        if (self::tableMissing($saetze) || self::tableMissing($werte)) {
            return;
        }

        // ⚠️ *Beide Spalten müssen dastehen: die neue, in die geschrieben wird, und die alte, aus der
        // gelesen wird. Fehlt eine, ist der Schritt entweder schon gelaufen oder noch nicht dran.*
        if (! self::hasColumn($saetze, 'relation_id') || ! self::hasColumn($werte, 'path')) {
            return;
        }

        /** @var list<array{id: string, node_record_id: string, relation_id: string, path: string}> $zweiteilig */
        $zweiteilig = $wpdb->get_results(
            "SELECT id, node_record_id, relation_id, path FROM {$werte} WHERE path LIKE '%.%'",
            ARRAY_A
        );

        foreach ($zweiteilig as $zeile) {
            $teile = explode('.', (string) $zeile['path']);

            // ⚠️ *Mehr als zwei Teile gibt es gemessen nirgends — und eine Zeile, die sich nicht
            // sicher lesen lässt, bleibt liegen, statt an einer geratenen Adresse zu landen (`PR-4`).*
            if (count($teile) !== 2) {
                continue;
            }

            $stelle = (int) $teile[0];

            if ($stelle === 0) {
                continue;
            }

            $satz = $wpdb->get_row(
                $wpdb->prepare("SELECT id, node_id, node_version, record_type FROM {$saetze} WHERE id = %d", (int) $zeile['node_record_id']),
                ARRAY_A
            );

            if ($satz === null) {
                continue;
            }

            $ziel = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$saetze} WHERE relation_id = %d LIMIT 1", $stelle)
            );

            if ($ziel === 0) {
                $wpdb->insert($saetze, [
                    'version'      => 1,
                    'node_id'      => (int) $satz['node_id'],
                    'node_version' => (int) $satz['node_version'],
                    'created_at'   => current_time('mysql'),
                    'record_type'  => (string) $satz['record_type'],
                    'relation_id'  => $stelle,
                ]);

                $ziel = (int) $wpdb->insert_id;
            }

            if ($ziel === 0) {
                continue;
            }

            $wpdb->update(
                $werte,
                ['node_record_id' => $ziel, 'path' => ''],
                ['id' => (int) $zeile['id']]
            );
        }
    }

    /**
     * Fassung 39: **`relation_records.path` fällt — die letzte Pfadspalte** (TASK-002).
     *
     * ```mermaid
     * flowchart LR
     *   P["path"] -.ist gleich.-> R["relation_id"]
     *   R --> D["Spalte faellt"]
     *   P --> S["Schatten behaelt sie"]
     * ```
     *
     * ⚠️ **Der Beschluss ist [D-667](../../../docs/NewConcept/90-decision-log.md)**, sein Wort:
     * *«aber wir hatten die relation id schon vorgesehen im record»*. **Fassung 37 hat die Hälfte
     * gebaut** — die Einstellungen einer Verwendungsstelle liegen in **ihrem** Satz, und keine
     * lebende Wertzeile trägt mehr eine zweiteilige Adresse. *Was blieb, ist die Spalte, und sie
     * sagt nichts, was `relation_id` nicht schon sagt.*
     *
     * ⚠️ **Geprüft, bevor gelöscht wird, und bei der ersten Abweichung bleibt sie stehen** —
     * dieselbe Ordnung wie bei {@see self::dropNodePathColumn()}: *«jede Zeile sagt in ihrem Pfad
     * genau ihre `relation_id`» ist keine Vermutung, die man hinterher nicht mehr prüfen kann,
     * sondern **hier die Bedingung dafür, dass gelöscht wird.***
     *
     * ⚠️ **Der Schatten behält seinen Pfad** ({@see self::SHADOW_ONLY_IN}): *Geschichte wird nicht
     * umgeschrieben ([D-065](../../../docs/NewConcept/90-decision-log.md)). **Die zwei zweiteiligen
     * Zeilen, die Fassung 37 umgehängt hat, stehen dort und nirgends sonst.***
     *
     * ⚠️ *Gezählt davor und danach, und bei einer Abweichung wirft der Schritt — **an dieser Spalte
     * ist am 2026-09-06 schon einmal etwas verlorengegangen**: sie war kurz weg und kam **leer**
     * zurück, 118 Zeilen ohne Adresse, und sechs Knoten zeichneten mit dem Rückfall.*
     *
     * ⚠️ *Zweimal ausführbar: ein zweiter Lauf findet keine Spalte mehr.*
     */
    private static function dropRelationRecordPathColumn(): void
    {
        global $wpdb;

        $werte = self::table('relation_records');

        if (self::tableMissing($werte) || ! self::hasColumn($werte, 'path')) {
            return;
        }

        // ⚠️ **Die eine Abfrage, die den Schritt rechtfertigt.** *Sie stellt den gespeicherten Pfad
        // neben die Kanten-Id derselben Zeile. **Null Abweichungen ist die Bedingung**, nicht das
        // erwartete Ergebnis.*
        //
        // ⚠️ **Ein *leerer* Pfad ist keine Abweichung, und das ist gemessen und nicht nachgegeben:**
        // *drei Zeilen tragen ihn, und alle drei hat {@see self::moveUseSiteSettingsIntoTheirOwnRecord()}
        // selbst so hinterlassen — sie schreibt `path = ''`, nachdem sie die Zeile an den Satz ihrer
        // Verwendungsstelle gehängt hat. **Ein leerer Pfad sagt nichts**, also kann er auch nichts
        // sagen, was verlorenginge.*
        $abweichend = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$werte} WHERE path <> '' AND path <> CAST(relation_id AS CHAR)"
        );

        if ($abweichend > 0) {
            // ⚠️ *Still umkehren und nicht werfen — eine Aktivierung darf nicht mit einem Fatal
            // enden. `path-check.php` meldet denselben Befund laut, und die Spalte steht so lange
            // weiter.*
            return;
        }

        $vorher = self::countedValues($werte);

        $wpdb->query("ALTER TABLE {$werte} DROP COLUMN path");

        if ($wpdb->last_error !== '') {
            return;
        }

        $nachher = self::countedValues($werte);

        if ($vorher !== $nachher) {
            throw new \RuntimeException(
                'Fassung 39: die Wertzeilen nach dem Streichen von relation_records.path sind nicht die '
                . 'von vorher. Vorher ' . wp_json_encode($vorher) . ', nachher ' . wp_json_encode($nachher)
                . '. Die Fassungsnummer bleibt stehen.'
            );
        }

        update_option('taxmod_relationpath_shape', $nachher, false);

        // ⚠️ *Der Spaltenplan von {@see Shadow} ist je Tabelle gemerkt — sonst kopierte der nächste
        // Aufruf eine Spalte, die es nicht mehr gibt.*
        Shadow::forgetColumnPlan();
    }

    /**
     * Was an den Wertzeilen gezählt wird, bevor und nachdem die Spalte fällt.
     *
     * ⚠️ **Die Prüfsumme nennt den Pfad bewusst nicht** — *sie soll gleich bleiben, während genau
     * diese Spalte verschwindet. Gezählt wird die **Adresse ohne ihn**: Satz, Kante, Sprache,
     * Stelle, und je Zeile, ob überhaupt ein Wert dasteht.*
     *
     * @return array{rows: int, records: int, relations: int, filled: int, sum: string}
     */
    private static function countedValues(string $werte): array
    {
        global $wpdb;

        return [
            'rows'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$werte}"),
            'records'   => (int) $wpdb->get_var("SELECT COUNT(DISTINCT node_record_id) FROM {$werte}"),
            'relations' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT relation_id) FROM {$werte}"),
            'filled'    => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$werte}
                 WHERE value_int IS NOT NULL OR value_decimal IS NOT NULL OR value_text IS NOT NULL
                    OR value_date IS NOT NULL OR value_ref IS NOT NULL"
            ),
            // ⚠️ *Eine Summe und kein `GROUP_CONCAT`: dessen Länge ist auf 1024 Zeichen begrenzt, und
            // eine Prüfsumme, die stillschweigend nur den Anfang sieht, ist keine.*
            'sum'       => (string) $wpdb->get_var(
                "SELECT COALESCE(SUM(CRC32(
                     CONCAT_WS(':', id, node_record_id, relation_id, locale, position)
                 )), 0) FROM {$werte}"
            ),
        ];
    }

    private static function dropNodePathColumn(): void
    {
        global $wpdb;

        $nodes = self::table('nodes');

        if (self::tableMissing($nodes) || ! self::hasColumn($nodes, 'path')) {
            return;
        }

        $vorher = self::countedTree($nodes);

        // ⚠️ **Die eine Abfrage, die den Schritt rechtfertigt.** *Sie rechnet den Weg aus
        // `parent_node_id` und stellt ihn neben den gespeicherten. **Null Abweichungen ist die
        // Bedingung**, nicht das erwartete Ergebnis.*
        $abweichend = (int) $wpdb->get_var(
            "WITH RECURSIVE taxmod_ahnen (id, path) AS (
                 SELECT id, CAST(id AS CHAR(255)) FROM {$nodes} WHERE parent_node_id IS NULL
                 UNION ALL
                 SELECT k.id, CONCAT(v.path, '.', k.id)
                   FROM {$nodes} k INNER JOIN taxmod_ahnen v ON v.id = k.parent_node_id
             )
             SELECT COUNT(*) FROM {$nodes} n
             LEFT JOIN taxmod_ahnen a ON a.id = n.id
             WHERE a.path IS NULL OR a.path <> n.path"
        );

        if ($abweichend > 0) {
            // ⚠️ *Still umkehren und nicht werfen — eine Aktivierung darf nicht mit einem Fatal enden.
            // `path-check.php` meldet denselben Befund laut, und die Spalte steht so lange weiter.*
            return;
        }

        // ⚠️ **Die Prüfsumme über die Wege selbst, genommen, solange die Spalte noch dasteht.** *Sie
        // ist die Zusage, die dieser Schritt einem späteren Lauf hinterlässt: **die Vorfahren liefern
        // dieselbe Kette wie vorher** — nachprüfbar an einer Zahl und nicht an einem Namen. Genommen
        // wird sie vom **gerechneten** Weg, und weil der oben Zeile für Zeile mit dem gespeicherten
        // verglichen wurde, ist es dieselbe Zahl für beide.*
        $wege = (string) $wpdb->get_var(self::ancestryChecksum($nodes));

        self::keepPathsBeforeDropping($nodes);

        $wpdb->query("ALTER TABLE {$nodes} DROP COLUMN path");

        if ($wpdb->last_error !== '') {
            return;
        }

        $nachher = self::countedTree($nodes);

        // ⚠️ **Dieselben Zahlen wie vorher, sonst hat der Schritt etwas getan, was er nicht sollte**
        // (`PR-9`). *Verglichen wird an gezählten Zahlen und nicht an Namen: Knoten, Kanten,
        // Datensätze, Wertzeilen, Beschriftungen, die Tiefen je Ebene und eine Prüfsumme über
        // **Vater · Stelle · Kind**. **Die Prüfsumme nennt den Pfad bewusst nicht** — sie soll gleich
        // bleiben, während genau diese Spalte verschwindet.*
        if ($vorher !== $nachher) {
            throw new \RuntimeException(
                'Fassung 35: der Baum nach dem Streichen von nodes.path ist nicht der von vorher. '
                . 'Vorher ' . wp_json_encode($vorher) . ', nachher ' . wp_json_encode($nachher) . '. '
                . 'Die Zeilen stehen als Schattenzeilen; die Fassungsnummer bleibt stehen.'
            );
        }

        update_option('taxmod_nodepath_shape', ['wege' => $wege, ...$nachher], false);

        // ⚠️ *Der Spaltenplan von {@see Shadow} ist je Tabelle gemerkt — sonst kopierte der nächste
        // Aufruf eine Spalte, die es nicht mehr gibt.*
        Shadow::forgetColumnPlan();
    }

    /**
     * Die Prüfsumme über **alle gerechneten Wege**, als Anweisung.
     *
     * ⚠️ *Sie steht hier und nicht zweimal ausgeschrieben, weil sie an zwei Orten dieselbe sein muss:
     * die Fassung nimmt sie, solange die Spalte noch steht, und `path-check.php` nimmt sie danach
     * wieder. **Zwei Abschriften derselben Abfrage wären zwei Abfragen, die auseinanderlaufen können.***
     */
    public static function ancestryChecksum(?string $nodes = null): string
    {
        $nodes ??= self::table('nodes');

        return "WITH RECURSIVE taxmod_ahnen (id, path) AS (
                    SELECT id, CAST(id AS CHAR(255)) FROM {$nodes} WHERE parent_node_id IS NULL
                    UNION ALL
                    SELECT k.id, CONCAT(v.path, '.', k.id)
                      FROM {$nodes} k INNER JOIN taxmod_ahnen v ON v.id = k.parent_node_id
                )
                SELECT COALESCE(MD5(GROUP_CONCAT(a.path ORDER BY a.path SEPARATOR '|')), '')
                FROM taxmod_ahnen a";
    }

    /**
     * Der Baum, gezählt — und **ohne den Pfad**, damit die Zahl den Schritt überlebt.
     *
     * @return array{nodes: int, relations: int, records: int, values: int, labels: int, depths: string, tree: string}
     */
    private static function countedTree(string $nodes): array
    {
        global $wpdb;

        $zaehle = static fn (string $tabelle): int => (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::table($tabelle)
        );

        return [
            'nodes'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$nodes}"),
            'relations' => $zaehle('relations'),
            'records'   => $zaehle('node_records'),
            'values'    => $zaehle('relation_records'),
            'labels'    => $zaehle('labels'),
            // ⚠️ *Die Tiefen je Ebene — **aus `parent_node_id` gerechnet**, nie aus dem Pfad, sonst
            // stünde die Zusage auf dem, was gerade geprüft wird.*
            'depths'    => (string) $wpdb->get_var(
                "WITH RECURSIVE taxmod_tiefe (id, ebene) AS (
                     SELECT id, 0 FROM {$nodes} WHERE parent_node_id IS NULL
                     UNION ALL
                     SELECT k.id, v.ebene + 1 FROM {$nodes} k INNER JOIN taxmod_tiefe v ON v.id = k.parent_node_id
                 )
                 SELECT COALESCE(GROUP_CONCAT(CONCAT(x.ebene, ':', x.wieviele) ORDER BY x.ebene SEPARATOR '|'), '')
                 FROM (SELECT ebene, COUNT(*) AS wieviele FROM taxmod_tiefe GROUP BY ebene) x"
            ),
            // ⚠️ *Vater · Stelle · Kind, als eine Prüfsumme. **Das ist der Baum selbst**, in der
            // einzigen Form, die ohne den Pfad auskommt.*
            'tree'      => (string) $wpdb->get_var(
                "SELECT COALESCE(MD5(GROUP_CONCAT(z.s ORDER BY z.s SEPARATOR '|')), '')
                 FROM (SELECT CONCAT_WS(':', COALESCE(parent_node_id, 0), sort_order, id) s FROM {$nodes}) z"
            ),
        ];
    }

    /**
     * Die Wege in den Schatten und ins Journal, bevor die Spalte fällt (Fassung 35).
     *
     * ⚠️ **Erst der Schatten, dann das Journal**, und die Reihenfolge ist überall dieselbe: *die
     * Schattenzeile ist das, woraus man zurückkommt; die Journalzeile ist nur der Hinweis darauf.*
     *
     * ⚠️ *Eine Änderungsgruppe für alle Zeilen — es ist **ein** Akt
     * ([D-348](../../../docs/NewConcept/90-decision-log.md)).*
     */
    private static function keepPathsBeforeDropping(string $nodes): void
    {
        global $wpdb;

        $zeilen = $wpdb->get_results("SELECT id, version, path FROM {$nodes}", ARRAY_A) ?: [];

        if ($zeilen === []) {
            return;
        }

        $log    = new WpdbChangelog(new SystemClock());
        $gruppe = null;

        foreach ($zeilen as $zeile) {
            $id = (int) $zeile['id'];

            Shadow::keepOne('nodes', $id);

            $gruppe = $log->record(
                $id,
                'node',
                'path dropped',
                (string) $zeile['path'],
                null,
                (int) $zeile['version'],
                $gruppe
            );
        }
    }

    /**
     * Die alte, hohe `labels` tritt zur Seite, damit die neue, flache frisch gebaut werden kann
     * (Fassung 34, TASK-019).
     *
     * ⚠️ **`dbDelta` kann keine Tabelle umformen** — es fügt fehlende Spalten hinzu und entfernt
     * keine. *Eine Tabelle, deren Schlüssel und deren halbe Spaltenmenge sich ändern, lässt sich
     * damit nicht wandeln; sie muss neu gebaut und ihr Inhalt umgegossen werden.*
     *
     * ⚠️ **Und die zur Seite gestellte Tabelle **ist** die Sicherung.** *Sie fällt erst, wenn
     * {@see self::moveNamesIntoLabels()} Zeile für Zeile nachgewiesen hat, dass jeder Text an seiner
     * Stelle angekommen ist. Bricht der Schritt vorher ab, steht sie unangetastet da und der nächste
     * Lauf beginnt wieder bei ihr.*
     */
    private static function setOldLabelsAside(): void
    {
        global $wpdb;

        $labels   = self::table('labels');
        $beiseite = self::table(self::RETIRED_LABELS_TABLE);

        if (self::tableMissing($labels) || ! self::tableMissing($beiseite)) {
            return;
        }

        // ⚠️ *`owner_id` ist das Kennzeichen der alten Form — hat sie es nicht, ist sie schon die neue.*
        if (! self::hasColumn($labels, 'owner_id')) {
            return;
        }

        $wpdb->query("RENAME TABLE {$labels} TO {$beiseite}");
    }

    /**
     * Fassung 34: **die Beschriftungen bekommen ihre zwei Tabellen, und der Name zieht mit hinein**
     * (TASK-019, [D-580](../../../docs/NewConcept/90-decision-log.md),
     * [D-598](../../../docs/NewConcept/90-decision-log.md),
     * [D-645](../../../docs/NewConcept/90-decision-log.md),
     * [D-646](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ```mermaid
     * flowchart LR
     *   N[(nodes.name)] --> T[(label_texts.text_name)]
     *   R[(relations.name)] --> T
     *   A[(labels alt · eine Zeile je Rolle)] --> T
     *   N -.label_id.-> L[(labels)]
     *   L --> T
     * ```
     *
     * ⚠️ **Der Verweis dreht sich um, und das ist der Kern** (D-580): *«wenn wir jeder Kante eine
     * `label_id` geben … dann hätten wir das Problem gelöst». **Zeigte die Labeltabelle auf ihren
     * Gegenstand, bräuchte sie je neuer Art eine weitere Spalte.***
     *
     * ⚠️ **Die sprachlosen Zeilen werden Zeilen der Standardsprache** (D-580, D-387, D-645). *Es gibt
     * keine sprachneutrale Zeile mehr; die Standardsprache steht auf der Installationsseite und wird
     * hier vom Rand hereingereicht, weil der Kern sie nicht kennen darf (`CD-1`).*
     *
     * ⚠️ **Erst messen, dann wandern, dann nachmessen — Zeile für Zeile und nicht als Summe**
     * (`PR-9`): *jeder Knoten und jede Kante muss nachher denselben Namen und dieselbe Beschriftung
     * liefern wie vorher. Weicht **eine** ab, bricht der Schritt ab, die Fassungsnummer bleibt stehen
     * und die alte Tabelle mit ihr.*
     *
     * ⚠️ **Das Sichern steht in diesem Schritt und nicht in einem Skript daneben.** *Das Laden von
     * WordPress hebt die Fassung, bevor ein Skript seine erste Zeile schreiben kann — am 2026-09-05
     * sind so rund 190 Schattenzeilen verlorengegangen.*
     *
     * ⚠️ *Zweimal ausführbar: ein zweiter Lauf findet weder die alte Tabelle noch eine Spalte `name`.*
     */
    private static function moveNamesIntoLabels(): void
    {
        global $wpdb;

        $labels = self::table('labels');
        $texts  = self::table('label_texts');
        $nodes  = self::table('nodes');
        $rels   = self::table('relations');
        $alt    = self::table(self::RETIRED_LABELS_TABLE);

        if (self::tableMissing($labels) || self::tableMissing($texts) || self::tableMissing($nodes)) {
            return;
        }

        $mitNamen = self::hasColumn($nodes, 'name') || self::hasColumn($rels, 'name');

        if (self::tableMissing($alt) && ! $mitNamen) {
            return;
        }

        $standard = \Taxmod\WordPress\Admin\SettingsScreen::neutralLocale();

        // ── 1. Vorher zählen. Die Rollen zuerst, denn sie hängen an `nodes.name`, das gleich fällt.
        $rollen  = self::labelRolesBeforeTheMove($alt, $nodes);
        $vorher  = self::namesBeforeTheMove($nodes, $rels);
        $texteAlt = self::labelTextsBeforeTheMove($alt, $rollen, $standard);

        // ── 2. Jede lebende Zeile bekommt ihre Beschriftungszeile, und der Name zieht hinein.
        //
        // ⚠️ *Eine Änderungsgruppe für **beide** Tabellen: es ist ein Akt
        // ([D-348](../../../docs/NewConcept/90-decision-log.md)), und ein Rückgängig, das nur die
        // Knoten zurückholte, wäre kein Rückgängig dieses Schritts.*
        $gruppe = null;

        self::giveEveryOwnerALabel($labels, $texts, $nodes, 'node', $vorher['node'], $standard, $gruppe);
        self::giveEveryOwnerALabel($labels, $texts, $rels, 'relation', $vorher['relation'], $standard, $gruppe);

        // ── 3. Die alten Beschriftungen giessen sich in die Spalten ihrer Rolle.
        self::pourOldLabelsIntoColumns($texts, $nodes, $rels, $texteAlt);

        // ── 4. Nachher messen — Zeile für Zeile.
        self::proveTheMove($texts, $nodes, $rels, $vorher, $texteAlt, $standard);

        // ── 5. Erst jetzt fällt, was ersetzt ist.
        foreach ([[$nodes, 'name'], [$rels, 'name']] as [$tabelle, $spalte]) {
            if (! self::tableMissing($tabelle) && self::hasColumn($tabelle, $spalte)) {
                $wpdb->query("ALTER TABLE {$tabelle} DROP COLUMN {$spalte}");
            }
        }

        if (! self::tableMissing($alt)) {
            $wpdb->query("DROP TABLE {$alt}");
        }

        Shadow::forgetColumnPlan();

        // ⚠️ *Die Zahlen bleiben stehen, damit `label-texts-check` sie **vergleichen** kann, statt sie
        // nachzurechnen — dieselbe Vorsorge wie bei Fassung 31.*
        update_option('taxmod_labeltexts_shape', [
            'nodes'     => count($vorher['node']),
            'relations' => count($vorher['relation']),
            'texts'     => count($texteAlt),
            'locale'    => $standard,
        ], false);
    }

    /**
     * Welche Rollennummer welche Rolle war — gelesen, solange `nodes.name` es noch sagen kann.
     *
     * ⚠️ **Geraten wird hier nichts** (`PR-4`): *eine Rollennummer ohne Knoten, oder mit einem Namen,
     * den {@see \Taxmod\Core\Model\SeededRole} nicht kennt, lässt den Schritt abbrechen. Sie still in
     * eine Spalte zu schieben hiesse, einen Text an eine Stelle zu legen, an der ihn niemand sucht.*
     *
     * @return array<int,string> Rollennummer => Rollenwort.
     */
    private static function labelRolesBeforeTheMove(string $alt, string $nodes): array
    {
        global $wpdb;

        if (self::tableMissing($alt)) {
            return [];
        }

        $zeilen = $wpdb->get_results(
            "SELECT DISTINCT l.role_id, n.name FROM {$alt} l LEFT JOIN {$nodes} n ON n.id = l.role_id",
            ARRAY_A
        ) ?: [];

        $rollen = [];

        foreach ($zeilen as $zeile) {
            $wort = (string) ($zeile['name'] ?? '');

            if (\Taxmod\Core\Model\SeededRole::tryFrom($wort) === null) {
                throw new \RuntimeException(
                    'Fassung 34: die Rollennummer ' . (int) $zeile['role_id'] . ' heisst «' . $wort
                    . '» und ist keine bekannte Rolle. Es wurde nichts geloescht.'
                );
            }

            $rollen[(int) $zeile['role_id']] = $wort;
        }

        return $rollen;
    }

    /**
     * Die Namen, wie sie vor dem Umzug dastehen.
     *
     * @return array{node: array<int,string>, relation: array<int,string>}
     */
    private static function namesBeforeTheMove(string $nodes, string $rels): array
    {
        global $wpdb;

        $lesen = static function (string $tabelle) use ($wpdb): array {
            if (self::tableMissing($tabelle) || ! self::hasColumn($tabelle, 'name')) {
                return [];
            }

            $gefunden = [];

            foreach ($wpdb->get_results("SELECT id, name FROM {$tabelle}", ARRAY_A) ?: [] as $zeile) {
                $name = (string) $zeile['name'];

                if ($name !== '') {
                    $gefunden[(int) $zeile['id']] = $name;
                }
            }

            return $gefunden;
        };

        return ['node' => $lesen($nodes), 'relation' => $lesen($rels)];
    }

    /**
     * Die alten Beschriftungen, auf ihre künftige Stelle umgerechnet.
     *
     * ⚠️ **Hier fällt die sprachneutrale Zeile** (D-580, D-387, D-645): *eine leere Sprache wird die
     * Standardsprache. **Träfen dabei zwei Zeilen auf dieselbe Stelle**, würde die eine die andere
     * überschreiben — darum wird das gezählt und nicht geduldet.*
     *
     * @param  array<int,string> $rollen
     * @return array<string,string> «Raum·Eigentümer·Sprache·Numerus·Rolle» => Text.
     */
    private static function labelTextsBeforeTheMove(string $alt, array $rollen, string $standard): array
    {
        global $wpdb;

        if (self::tableMissing($alt)) {
            return [];
        }

        $gefunden = [];

        foreach ($wpdb->get_results("SELECT * FROM {$alt}", ARRAY_A) ?: [] as $zeile) {
            $text = (string) $zeile['text'];

            if ($text === '') {
                continue;
            }

            $pfad = (string) ($zeile['path'] ?? '');

            if ($pfad !== '') {
                throw new \RuntimeException(
                    'Fassung 34: eine Beschriftung traegt einen Pfad («' . $pfad . '»), und die neue Form '
                    . 'hat keine Stelle dafuer. Es wurde nichts geloescht; siehe INF-043.'
                );
            }

            $schluessel = implode("\0", [
                self::spaceOfOldLabel($zeile),
                (string) (int) $zeile['owner_id'],
                ((string) $zeile['locale']) === '' ? $standard : (string) $zeile['locale'],
                ((string) $zeile['number']) === '' ? \Taxmod\Core\Model\Label::BASE_NUMBER : (string) $zeile['number'],
                $rollen[(int) $zeile['role_id']] ?? '',
            ]);

            if (isset($gefunden[$schluessel]) && $gefunden[$schluessel] !== $text) {
                throw new \RuntimeException(
                    'Fassung 34: zwei Beschriftungen fallen auf dieselbe Stelle, weil die sprachlose '
                    . 'Zeile zur Standardsprache wird. Es wurde nichts geloescht.'
                );
            }

            $gefunden[$schluessel] = $text;
        }

        return $gefunden;
    }

    /**
     * An welchem Raum eine alte Beschriftung hing.
     *
     * ⚠️ **Eine Installation, die vor Fassung 31 stehengeblieben ist, hat die Spalte `owner_kind`
     * nicht** — dann wird sie **gelesen** und nicht geraten: gibt es unter der Nummer einen Knoten
     * **und** eine Kante, oder keines von beidem, bricht der Schritt ab (`PR-4`, `INF-035`).
     *
     * @param array<string,mixed> $zeile
     */
    private static function spaceOfOldLabel(array $zeile): string
    {
        global $wpdb;

        $raum = (string) ($zeile['owner_kind'] ?? '');

        if ($raum !== '') {
            return $raum;
        }

        $id      = (int) $zeile['owner_id'];
        $istNode = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('nodes') . ' WHERE id = %d', $id));
        $istKante = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('relations') . ' WHERE id = %d', $id));

        if ($istNode === 1 && $istKante === 0) {
            return 'node';
        }

        if ($istKante === 1 && $istNode === 0) {
            return 'relation';
        }

        throw new \RuntimeException(
            'Fassung 34: die Beschriftung an Nummer ' . $id . ' nennt ihren Raum nicht und er laesst sich '
            . 'nicht eindeutig lesen. Es wurde nichts geloescht.'
        );
    }

    /**
     * Jede lebende Zeile bekommt ihre `label_id`, und ihr Name wird ihr `text_name`.
     *
     * ⚠️ **Am Knoten ist die Beschriftung Pflicht, an der Kante freiwillig** (D-580): *«die 127
     * Vererbungskanten haben heute keinen Namen und brauchen auch keinen». Eine Kante ohne Namen
     * bekommt darum keine Zeile.*
     *
     * ⚠️ **Erst sichern, dann schreiben** (`PR-9`, D-348, D-634): *jede Zeile geht als Schattenzeile
     * fort — mit ihrer `label_id` und ihrem noch vorhandenen Namen —, und jede bekommt eine
     * Journalzeile mit Version unter **einer** Änderungsgruppe.*
     *
     * ⚠️ *Eine Schleife mit Abfragen darin, und das ist hier die richtige Form: **jede Zeile braucht
     * ihre eigene neue Nummer**, die `AUTO_INCREMENT` erst beim Einfügen vergibt. `CD-7` verbietet die
     * Abfrage im Auflösungslauf, nicht den einmaligen Umzug bei der Aktivierung.*
     *
     * @param array<int,string> $namen
     */
    private static function giveEveryOwnerALabel(
        string $labels,
        string $texts,
        string $tabelle,
        string $raum,
        array $namen,
        string $standard,
        ?int &$gruppe
    ): void {
        global $wpdb;

        if (self::tableMissing($tabelle) || ! self::hasColumn($tabelle, 'label_id')) {
            return;
        }

        $offen = $wpdb->get_col("SELECT id FROM {$tabelle} WHERE label_id IS NULL OR label_id = 0");

        if ($offen === []) {
            return;
        }

        $log = new WpdbChangelog(new SystemClock());

        foreach ($offen as $rohe) {
            $id   = (int) $rohe;
            $name = $namen[$id] ?? '';

            // ⚠️ *Eine Kante ohne Namen bleibt ohne Beschriftung — D-580 macht sie dort freiwillig.*
            if ($raum === 'relation' && $name === '') {
                continue;
            }

            $wpdb->insert($labels, ['version' => 1, 'owner_kind' => $raum], ['%d', '%s']);

            $labelId = (int) $wpdb->insert_id;

            if ($labelId === 0) {
                throw new \RuntimeException(
                    'Fassung 34: fuer ' . $raum . ' ' . $id . ' liess sich keine Beschriftungszeile anlegen: '
                    . $wpdb->last_error
                );
            }

            $wpdb->update($tabelle, ['label_id' => $labelId], ['id' => $id], ['%d'], ['%d']);

            if ($name !== '') {
                $wpdb->insert(
                    $texts,
                    [
                        'label_id'  => $labelId,
                        'locale'    => $standard,
                        'number'    => \Taxmod\Core\Model\Label::BASE_NUMBER,
                        'text_name' => $name,
                    ],
                    ['%d', '%s', '%s', '%s']
                );
            }

            $version = (int) $wpdb->get_var($wpdb->prepare("SELECT version FROM {$tabelle} WHERE id = %d", $id));

            Shadow::keepOne($raum === 'node' ? 'nodes' : 'relations', $id);

            $gruppe = $log->record($id, $raum, 'name moved to label', $name, (string) $labelId, $version, $gruppe);
        }
    }

    /**
     * Die alten Beschriftungen in die Spalte ihrer Rolle.
     *
     * @param array<string,string> $texteAlt
     */
    private static function pourOldLabelsIntoColumns(string $texts, string $nodes, string $rels, array $texteAlt): void
    {
        global $wpdb;

        if ($texteAlt === []) {
            return;
        }

        foreach ($texteAlt as $schluessel => $text) {
            [$raum, $ownerId, $locale, $number, $rolle] = explode("\0", $schluessel);

            $labelId = self::labelIdOf($raum === 'relation' ? $rels : $nodes, (int) $ownerId);

            if ($labelId === 0) {
                throw new \RuntimeException(
                    'Fassung 34: die Beschriftung von ' . $raum . ' ' . $ownerId . ' findet keine Zeile, '
                    . 'an der sie haengen koennte. Es wurde nichts geloescht.'
                );
            }

            $spalte = 'text_' . $rolle;

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$texts} (label_id, locale, number, {$spalte}) VALUES (%d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE {$spalte} = VALUES({$spalte})",
                $labelId,
                $locale,
                $number,
                $text
            ));

            if ($wpdb->last_error !== '') {
                throw new \RuntimeException('Fassung 34: eine Beschriftung liess sich nicht schreiben: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * Nachher: jeder Name und jede Beschriftung an derselben Stelle wie vorher — **Zeile für Zeile**.
     *
     * @param array{node: array<int,string>, relation: array<int,string>} $vorher
     * @param array<string,string>                                       $texteAlt
     */
    private static function proveTheMove(
        string $texts,
        string $nodes,
        string $rels,
        array $vorher,
        array $texteAlt,
        string $standard
    ): void {
        foreach ($vorher as $raum => $namen) {
            $tabelle = $raum === 'relation' ? $rels : $nodes;

            foreach ($namen as $id => $name) {
                $jetzt = self::storedText($texts, $tabelle, $id, $standard, \Taxmod\Core\Model\Label::BASE_NUMBER, 'text_name');

                if ($jetzt !== $name) {
                    throw new \RuntimeException(
                        'Fassung 34: ' . $raum . ' ' . $id . ' hiess «' . $name . '» und heisst jetzt «'
                        . (string) $jetzt . '». Die Wanderung bricht ab; es wurde keine Spalte geloescht.'
                    );
                }
            }
        }

        foreach ($texteAlt as $schluessel => $text) {
            [$raum, $ownerId, $locale, $number, $rolle] = explode("\0", $schluessel);

            $jetzt = self::storedText(
                $texts,
                $raum === 'relation' ? $rels : $nodes,
                (int) $ownerId,
                $locale,
                $number,
                'text_' . $rolle
            );

            if ($jetzt !== $text) {
                throw new \RuntimeException(
                    'Fassung 34: die Beschriftung «' . $rolle . '» von ' . $raum . ' ' . $ownerId
                    . ' ist nicht angekommen. Die Wanderung bricht ab; es wurde keine Spalte geloescht.'
                );
            }
        }

        unset($standard);
    }

    private static function labelIdOf(string $tabelle, int $id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT label_id FROM {$tabelle} WHERE id = %d", $id));
    }

    private static function storedText(
        string $texts,
        string $tabelle,
        int $ownerId,
        string $locale,
        string $number,
        string $spalte
    ): ?string {
        global $wpdb;

        $wert = $wpdb->get_var($wpdb->prepare(
            "SELECT t.{$spalte} FROM {$texts} t
             JOIN {$tabelle} o ON o.label_id = t.label_id
             WHERE o.id = %d AND t.locale = %s AND t.number = %s",
            $ownerId,
            $locale,
            $number
        ));

        return $wert === null ? null : (string) $wert;
    }

    /**
     * Zwei Sichten, die einen Knoten und eine Kante **mit ihrem Namen** zeigen (Fassung 34,
     * TASK-019).
     *
     * ```mermaid
     * flowchart LR
     *   N[(nodes)] --> V[["nodes_named"]]
     *   T[(label_texts · Standardsprache)] --> V
     * ```
     *
     * ⚠️ **Sie beantworten die Folge, die [D-580](../../../docs/NewConcept/90-decision-log.md) selbst
     * benannt hat:** *«`nodes` hat danach keine lesbare Spalte mehr — wer die Tabelle roh ansieht,
     * sieht Ids.» **Eine Sicht ist keine zweite Wahrheit** ([D-016](../../../docs/NewConcept/90-decision-log.md),
     * [D-228](../../../docs/NewConcept/90-decision-log.md)): sie hält nichts, sie rechnet bei jedem
     * Blick neu, und sie kann mit der Tabelle nicht auseinanderlaufen.*
     *
     * ⚠️ **Sie sind für das Lesen da und nie für das Schreiben** — *die Prüfläufe fragen «welcher
     * Knoten heisst so», und ohne sie hätte jeder von ihnen denselben Verbund noch einmal
     * hingeschrieben. Der schreibende Weg geht durch {@see WpdbLabelRepository}.*
     *
     * ⚠️ *Die Standardsprache steht **in** der Sicht, weil eine Sicht keine Parameter nimmt. Ändert
     * sie sich, werden die Sichten beim nächsten Fassungslauf neu gebaut; wer sie augenblicklich
     * braucht, fragt die Beschriftungen selbst.*
     */
    private static function buildTheReadableViews(): void
    {
        global $wpdb;

        if (self::tableMissing(self::table('label_texts'))) {
            return;
        }

        $texts    = self::table('label_texts');
        $standard = \Taxmod\WordPress\Admin\SettingsScreen::neutralLocale();

        foreach (['nodes', 'relations'] as $tabelle) {
            $quelle = self::table($tabelle);
            $sicht  = self::table($tabelle . '_named');

            if (self::tableMissing($quelle)) {
                continue;
            }

            $wpdb->query($wpdb->prepare(
                "CREATE OR REPLACE VIEW {$sicht} AS
                 SELECT q.*, COALESCE(t.text_name, '') AS name
                 FROM {$quelle} q
                 LEFT JOIN {$texts} t ON t.label_id = q.label_id AND t.locale = %s AND t.number = %s",
                $standard,
                \Taxmod\Core\Model\Label::BASE_NUMBER
            ));
        }
    }

    /**
     * Beschriftungen, auf die niemand mehr zeigt — samt ihren Texten.
     *
     * ⚠️ **Warum es das seit TASK-019 überhaupt gibt** ([D-580](../../../docs/NewConcept/90-decision-log.md)):
     * *bis dahin hatte ein Knoten nur dann eine Beschriftungszeile, wenn jemand einen Text
     * geschrieben hatte. **Jetzt hat sie jeder**, weil der Name eine ist — und jede Zeile, die mit
     * rohem SQL aus `nodes` entfernt wird, lässt eine zurück. Die Ablagewege räumen selbst auf
     * ({@see WpdbNodeRepository::purgeSubtree()}); die Prüfläufe, die an ihnen vorbei löschen,
     * rufen dies am Ende auf.*
     *
     * ⚠️ *Es räumt **nur** weg, worauf weder ein Knoten noch eine Kante zeigt — eine Beschriftung
     * mit Eigentümer wird nie angefasst.*
     *
     * @return int Wie viele Beschriftungen gefallen sind.
     */
    public static function forgetOrphanLabels(): int
    {
        global $wpdb;

        if (self::tableMissing(self::table('labels')) || self::tableMissing(self::table('label_texts'))) {
            return 0;
        }

        $labels = self::table('labels');

        // ⚠️ **Eine geparkte Kante behält ihre Beschriftung — sie kommt «ganz» zurück (package3, D-128).** *Gemessen am
        // 2026-09-12: dieser Feger lief beim ersten Aufruf nach jeder neuen Fassung und nahm die Beschriftung der geparkten
        // Kante «Type» mit; «id-space» und «labels-page-save» flackerten deshalb je einmal rot.*
        $ids = array_map(intval(...), $wpdb->get_col(
            "SELECT l.id FROM {$labels} l
             WHERE NOT EXISTS (SELECT 1 FROM " . self::table('nodes') . ' n WHERE n.label_id = l.id)
               AND NOT EXISTS (SELECT 1 FROM ' . self::table('relations') . ' r WHERE r.label_id = l.id)
               AND NOT EXISTS (SELECT 1 FROM ' . self::table('relations_history') . ' h WHERE h.label_id = l.id AND h.parked_by_group_id IS NOT NULL AND h.version = (SELECT MAX(version) FROM ' . self::table('relations_history') . ' h2 WHERE h2.id = h.id))'
        ) ?: []);

        if ($ids === []) {
            return 0;
        }

        $slots = implode(',', array_fill(0, count($ids), '%d'));

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table('label_texts') . " WHERE label_id IN ({$slots})",
            ...$ids
        ));

        $wpdb->query($wpdb->prepare("DELETE FROM {$labels} WHERE id IN ({$slots})", ...$ids));

        return count($ids);
    }

    public static function tableMissing(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table;
    }

    private static function dropSettingsTable(): void
    {
        global $wpdb;

        $tabelle = self::table('settings');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tabelle)) !== $tabelle) {
            return;
        }

        $wpdb->query("DROP TABLE {$tabelle}");
    }

    // ⚠️ **Hier stand `moveHideOntoTheRelation()` — Fassung 12 — und der Schritt ist gestrichen**
    // (TASK-018, [D-581](../../../docs/NewConcept/90-decision-log.md)). *Er schob `nodes.hide` auf
    // die Vererbungskante und liess die Spalte dann fallen. **Fassung 28 legt sie wieder an**, also
    // hätte er sie bei jedem Aufstieg aufs Neue entfernt — und zwar **nach** `dbDelta`, das sie
    // gerade angelegt hat. Das Streichen ist hier kein Aufräumen, sondern der sichtbare Teil der
    // Entscheidung (`PR-9`).*
    //
    // ⚠️ *Eine Installation, die noch unter Fassung 12 steht, verliert dadurch nichts: ihre
    // `nodes.hide` steht schon genau dort, wo die Angabe seit TASK-018 hingehört.*

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
     * *«relation and node both having an attribute `hide`»* — a node hides itself, a placement hides what
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
    /**
     * `nodes.kind` heisst `field_type`, und der Wert `field` heisst `model` — Fassung 24 (TASK-007).
     *
     * ⚠️ **Beide Tabellen, lebend und Schatten.** *`Shadow::keep()` kopiert die Spalten, die beide
     * haben; bliebe eine zurück, verlöre die Geschichte still die Angabe — und
     * `shadow-shape-check.php` wäre rot, mit Recht.*
     *
     * ⚠️ *`CHANGE` und nicht `RENAME COLUMN`, aus demselben Portabilitätsgrund wie in
     * {@see renameRecordColumns()}. Und jeder Schritt fragt zuerst: dieser Lauf läuft bei jeder
     * Aktivierung und muss beim zweiten Mal nichts tun.*
     */
    private static function renameNodeKindColumn(): void
    {
        global $wpdb;

        foreach (['nodes', 'nodes_history'] as $name) {
            $table = self::table($name);

            // Eine frische Installation: `dbDelta` legt sie gleich mit dem neuen Namen an.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                continue;
            }

            $vorhanden = $wpdb->get_col($wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                'kind'
            ));

            if ($vorhanden === []) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} CHANGE kind field_type varchar(20) DEFAULT NULL");

            // ⚠️ *Gemessen null Zeilen — geschrieben trotzdem, siehe die Begründung an `VERSION`.*
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET field_type = %s WHERE field_type = %s",
                'model',
                'field'
            ));
        }
    }

    /**
     * `relations.position` heisst `sort_order`, und `(from_node_id, kind, sort_order)` wird eindeutig —
     * Fassung 25 (TASK-012).
     *
     * ⚠️ **Der Eigentümer:** *«Position würde ich eher Order nennen. Und die erste Position ist immer
     * null … die Sort Order entsteht pro Knoten, und zwar dem From-Knoten.»*
     *
     * ⚠️ **Der Schlüssel geht über **drei** Spalten, und die dritte ist der Befund.** *Meine
     * gemeldeten «17 doppelten Reihenfolgen» waren keine: alle 8 Gruppen mischen Kantenarten — Kind
     * im Baum gegen Feld des Knotens —, und **nicht eine Doppelung liegt innerhalb derselben Art**.
     * **Ein Schlüssel auf `(from_node_id, sort_order)` hätte 17 gültige Zeilen abgelehnt.** Gemessen am
     * 2026-09-05: ohne die Art 11 Verletzungen, mit ihr genau eine.*
     *
     * ⚠️ **Diese eine wird hier weggeräumt, und das ist eine Änderung an seinen Daten:** *der Knoten
     * `render with label` trug zwei Einstellungskanten auf Stelle 0, `label_role` und `with_label`.
     * **Die jüngere rückt ans Ende ihrer Liste** — die Reihenfolge zweier Einstellungen, von denen
     * beide auf 0 standen, war ohnehin nicht festgelegt. *Umkehrbar: die Zeile steht vorher im
     * Schatten ({@see Shadow::keep()}).*
     *
     * ⚠️ *Der Einzelindex auf `from_node_id` fällt: der neue Schlüssel beginnt mit derselben Spalte und
     * dient damit als Suchindex. **Zwei Indizes über dieselbe führende Spalte sind Doppelung**, und
     * `dbDelta` räumt einen bestehenden nie von selbst ab.*
     *
     * ⚠️ **Der Schlüssel steht an der lebenden Tabelle allein.** *Im Schatten **darf** dieselbe
     * Stelle mehrfach vorkommen — dort ist `(id, version)` der Schlüssel, und eine Kante, die zweimal
     * an Stelle 0 stand, ist genau das, was Geschichte aufhebt.*
     */
    private static function renameRelationPositionColumn(): void
    {
        global $wpdb;

        foreach (['relations', 'relations_history'] as $name) {
            $table = self::table($name);

            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                continue;
            }

            $vorhanden = $wpdb->get_col($wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                'position'
            ));

            if ($vorhanden !== []) {
                $wpdb->query(
                    "ALTER TABLE {$table} CHANGE position sort_order int(10) unsigned NOT NULL DEFAULT 0"
                );
            }
        }

        self::freeTheDoubledPlaces();

        $relations = self::table('relations');

        // ⚠️ **Beide Namen, und das ist kein Übereifer.** *Ein Umbenennen einer Spalte lässt den
        // **Namen** ihres Indexes stehen — auf einer Installation, die vor Fassung 26 gesät wurde,
        // heisst er weiter `from_id`, obwohl die Spalte `from_node_id` heisst (TASK-010). **Ein Index,
        // den dieser Schritt nicht findet, bleibt für immer neben dem neuen stehen.***
        foreach (['from_id', 'from_node_id'] as $alt) {
            $alterIndex = $wpdb->get_col($wpdb->prepare(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
                $relations,
                $alt
            ));

            if ($alterIndex !== []) {
                $wpdb->query("ALTER TABLE {$relations} DROP INDEX {$alt}");
            }
        }

        // ⚠️ *Dasselbe für `to_id`: die Spalte heisst `to_node_id`, der Index hiess weiter `to_id`,
        // und `dbDelta` hätte einen zweiten daneben gelegt.*
        $altesZiel = $wpdb->get_col($wpdb->prepare(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $relations,
            'to_id'
        ));

        if ($altesZiel !== []) {
            $wpdb->query("ALTER TABLE {$relations} DROP INDEX to_id");
        }
    }

    /**
     * `relations.from_id` heisst `from_node_id`, `to_id` heisst `to_node_id` — Fassung 26 (TASK-010).
     *
     * ⚠️ **Der Eigentümer:** *«machen wir es eh eindeutiger … das ist eine Knoten-Id, da ist ein
     * Constraint.»* **Der Name ist die kleinere Hälfte; die Bedingung ist die eigentliche Aufgabe**
     * ({@see constrainRelationsToNodes()}).
     *
     * ⚠️ *Vor `dbDelta`, wie jede Umbenennung hier — es kennt keine und legte die neuen Spalten
     * daneben. Und vor {@see renameRelationPositionColumn()}, weil deren Schlüssel schon auf den
     * neuen Namen lautet.*
     */
    private static function renameRelationNodeColumns(): void
    {
        global $wpdb;

        $umzuege = [
            ['from_id', 'from_node_id'],
            ['to_id', 'to_node_id'],
        ];

        foreach (['relations', 'relations_history'] as $name) {
            $table = self::table($name);

            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                continue;
            }

            foreach ($umzuege as [$von, $nach]) {
                $vorhanden = $wpdb->get_col($wpdb->prepare(
                    'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    $table,
                    $von
                ));

                if ($vorhanden === []) {
                    continue;
                }

                $wpdb->query(
                    "ALTER TABLE {$table} CHANGE {$von} {$nach} bigint(20) unsigned NOT NULL"
                );
            }
        }
    }

    /**
     * Eine Kante geht von einem **Knoten** aus und zeigt auf einen **Knoten** — und die Datenbank
     * hält das fest (TASK-010).
     *
     * ⚠️ **Das ist die Verschärfung, nicht die Umbenennung.** *Bis Fassung 20 zeigten alle sieben
     * Fremdschlüssel auf `identities.id` — die Bedingung erlaubte strukturell eine Kante, die von
     * einem **Datensatz** ausgeht. Mit TASK-004 fiel `identities`, und die Bedingungen fielen
     * ersatzlos mit; seither hielt [`id-space-check.php`](../../../scripts/dev/id-space-check.php)
     * dieselbe Zusage **lesend**. Jetzt hält sie die Datenbank.*
     *
     * ⚠️ **`ON DELETE RESTRICT`, und der Aufräumweg hält es aus** — *gemessen: {@see
     * WpdbNodeRepository::purgeSubtree()} löscht die Kanten **vor** den Knoten, und der Kommentar
     * dort sagt seit jeher warum: «a relation row whose node is gone is the dangling reference the
     * whole two-stage deletion exists to avoid». **Die Reihenfolge war schon richtig; jetzt kann sie
     * niemand mehr versehentlich umdrehen.***
     *
     * ⚠️ *`dbDelta` kennt keine Fremdschlüssel — darum von Hand, und nur, wenn keiner dasteht. **Und
     * nur, wenn keine Waise dasteht**: eine Bedingung, die MySQL zurückweist, wäre still, und ein
     * halb gesichertes Schema ist schlimmer als ein ungesichertes, weil man sich darauf verlässt.*
     */
    /**
     * Fassung 41: **die Benutzersätze unter `Primitives` und `Settings` fallen** — soweit sie leer sind.
     *
     * ⚠️ **Sein Wort:** *«was unter Primitives liegt, hält keine Benutzerdaten — nur default und
     * example»* ([D-677](../../../docs/NewConcept/90-decision-log.md)), und auf die Frage nach dem
     * Settings-Ast: *«Ja, auch Settings»* ([D-691](../../../docs/NewConcept/90-decision-log.md)).
     * **Gemessen am 2026-09-09: vier solche Sätze — `Decimal`, `Boolean`, `Email`, `chooser-dialog` —,
     * alle ohne eine Wertzeile.** *Ändert sich, was erlaubt ist, wandert der Bestand im selben
     * Schritt mit ([D-672](../../../docs/NewConcept/90-decision-log.md)); `simple-type-check` hält den
     * Zustand danach.*
     *
     * ⚠️ **Nur die leeren, und das ist keine Vorsicht, sondern `PR-4`:** *ein Benutzersatz **mit**
     * Werten an einem einfachen Typ wäre ein Widerspruch, den niemand entschieden hat aufzulösen —
     * er bleibt stehen, der Wächter meldet ihn, und die Zahl steht in der Option zur Fassung.*
     *
     * ⚠️ *Gezählt davor, gezählt danach, Abweichung ist ein Abbruch; über den Schatten umkehrbar;
     * zweimal ausführbar.* Die Astwurzeln kommen aus den Optionen, die {@see SeededFrameworkNodes}
     * führt — `Primitives` ist der Elternknoten der `Data Types`-Wurzel.
     */
    private static function dropUserRecordsUnderPrimitivesAndSettings(): void
    {
        global $wpdb;

        $saetze = self::table('node_records');
        $werte  = self::table('relation_records');
        $nodes  = self::table('nodes');

        if (self::tableMissing($saetze) || self::tableMissing($nodes) || ! self::hasColumn($saetze, 'record_type')) {
            return;
        }

        $dataTypes = (int) get_option('taxmod_branch_data_types_id', 0);
        $settings  = (int) get_option('taxmod_branch_settings_id', 0);
        $primitives = $dataTypes === 0
            ? 0
            : (int) $wpdb->get_var($wpdb->prepare("SELECT parent_node_id FROM {$nodes} WHERE id = %d", $dataTypes));

        $wurzeln = array_values(array_filter([$primitives, $settings]));

        if ($wurzeln === []) {
            return;
        }

        $ast = "WITH RECURSIVE taxmod_ast (id) AS (
                    SELECT id FROM {$nodes} WHERE id IN (" . implode(',', $wurzeln) . ")
                    UNION ALL
                    SELECT k.id FROM {$nodes} k INNER JOIN taxmod_ast v ON v.id = k.parent_node_id
                )";
        // ⚠️ *`relation_id = 0`: der Satz einer **Verwendungsstelle** ist `user`
        // ([D-674](../../../docs/NewConcept/90-decision-log.md)) und trägt Einstellungen, keine Daten
        // des Typs — er ist die Adresse der Stelle und bleibt, wo er ist.*
        $leerBedingung = "s.record_type = 'user' AND s.relation_id = 0
                AND s.node_id IN (SELECT id FROM taxmod_ast)
                AND NOT EXISTS (SELECT 1 FROM {$werte} v WHERE v.node_record_id = s.id)";

        /** @var list<array{id: string, version: string, node_id: string}> $leer */
        $leer = $wpdb->get_results("{$ast} SELECT s.id, s.version, s.node_id FROM {$saetze} s WHERE {$leerBedingung}", ARRAY_A) ?: [];
        $voll = (int) $wpdb->get_var(
            "{$ast} SELECT COUNT(*) FROM {$saetze} s WHERE s.record_type = 'user' AND s.relation_id = 0 AND s.node_id IN (SELECT id FROM taxmod_ast)
                 AND EXISTS (SELECT 1 FROM {$werte} v WHERE v.node_record_id = s.id)"
        );

        if ($leer === []) {
            if ($voll !== 0) {
                update_option('taxmod_fassung41_shape', ['gefallen' => 0, 'mit_werten_geblieben' => $voll], false);
            }

            return;
        }

        $log    = new WpdbChangelog(new SystemClock());
        $gruppe = null;

        foreach ($leer as $zeile) {
            $id = (int) $zeile['id'];
            Shadow::keepOne('node_records', $id, true);
            $wpdb->delete($saetze, ['id' => $id], ['%d']);
            $gruppe = $log->record(
                $id,
                'record',
                'user record under a type dropped',
                'node ' . (int) $zeile['node_id'],
                null,
                (int) $zeile['version'],
                $gruppe
            );
        }

        $geblieben = (int) $wpdb->get_var("{$ast} SELECT COUNT(*) FROM {$saetze} s WHERE {$leerBedingung}");

        if ($geblieben !== 0) {
            throw new \RuntimeException(sprintf(
                'Fassung 41: %d leere Benutzersaetze unter Primitives/Settings sollten fallen, %d stehen noch. Die Fassungsnummer bleibt stehen.',
                count($leer),
                $geblieben
            ));
        }

        update_option('taxmod_fassung41_shape', ['gefallen' => count($leer), 'mit_werten_geblieben' => $voll, 'gruppe' => $gruppe], false);
    }

    /**
     * Fassung 42: **die vierte Satzart `settings`, und die Grenzen der Zahlentypen wohnen in den Grenzknoten.**
     *
     * ⚠️ **Sein Wort zu beidem:** *«ich finde es auch das die vier arten es genauer machen sollten wir
     * so festlegen»* ([D-704](../../../docs/NewConcept/90-decision-log.md)) und *«ja wenn nichts in der
     * kante gesetzt ist gilt der Wert des Zielknoten (wenn einer da ist)»*
     * ([D-707](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ```mermaid
     * flowchart LR
     *   A["default-Satz mit Einstellungen"] -->|"nur Einstellungen"| S["wird settings"]
     *   A -->|"auch eigener Wert"| T["settings-Satz daneben · Einstellungszeilen ziehen um"]
     *   U["Satz einer Verwendungsstelle"] --> S
     *   G["integer_min / max, decimal_min / max"] --> E["eigener Wert = Grenze, im default-Satz"]
     *   K["min / max an Integer, Decimal"] --> X["Wertzeilen in den Schatten"]
     * ```
     *
     * ⚠️ **Gemessen am 2026-09-09, bevor der Schritt geschrieben wurde:** *53 `default`-Sätze; 50 tragen
     * nur Einstellungen, 3 dazu einen eigenen Wert (`Integer = 72`, `integer_min = 0`,
     * `integer_max = 72` — Reste seiner Versuche, «alles andere kann weg»); die Grenzen ±int und
     * ±dezimal standen an den **Kanten** `Integer → min/max` und `Decimal → min/max`.*
     *
     * ⚠️ *Gezählt davor, gezählt danach, Abweichung ist ein Abbruch; jede gelöschte Zeile geht in den
     * Schatten; eine Änderungsgruppe für die ganze Wanderung; zweimal ausführbar.*
     */
    private static function separateSettingsRecords(): void
    {
        global $wpdb;

        $saetze    = self::table('node_records');
        $werte     = self::table('relation_records');
        $relations = self::table('relations');
        $nodes     = self::table('nodes');

        if (self::tableMissing($saetze) || self::tableMissing($werte) || ! self::hasColumn($saetze, 'record_type')) {
            return;
        }

        $log    = new WpdbChangelog(new SystemClock());
        $gruppe = null;
        $jetzt  = (new SystemClock())->now()->format('Y-m-d H:i:s');

        // 1. Sätze, die Einstellungszeilen tragen, aber nicht `settings` sind.
        /** @var list<array{id: string, node_id: string, node_version: string, relation_id: string, record_type: string, version: string}> $traeger */
        $traeger = $wpdb->get_results(
            "SELECT DISTINCT s.id, s.node_id, s.node_version, s.relation_id, s.record_type, s.version
               FROM {$saetze} s
               JOIN {$werte} v ON v.node_record_id = s.id
               JOIN {$relations} r ON r.id = v.relation_id AND r.kind = 'setting'
              WHERE s.record_type <> 'settings'",
            ARRAY_A
        ) ?: [];

        // Dazu die Sätze von Verwendungsstellen, auch ohne Zeile — sie sind der Adresse nach Einstellungssätze.
        /** @var list<array{id: string, node_id: string, node_version: string, relation_id: string, record_type: string, version: string}> $stellen */
        $stellen = $wpdb->get_results(
            "SELECT s.id, s.node_id, s.node_version, s.relation_id, s.record_type, s.version
               FROM {$saetze} s WHERE s.relation_id <> 0 AND s.record_type <> 'settings'",
            ARRAY_A
        ) ?: [];

        $umgetypt = 0;
        $geteilt  = 0;

        foreach (array_merge($traeger, $stellen) as $satz) {
            $id = (int) $satz['id'];

            if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$saetze} WHERE id = %d AND record_type = 'settings'", $id)) === 1) {
                continue;
            }

            $fremde = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$werte} v LEFT JOIN {$relations} r ON r.id = v.relation_id
                  WHERE v.node_record_id = %d AND (v.relation_id = 0 OR r.kind IS NULL OR r.kind <> 'setting')",
                $id
            ));

            Shadow::keepOne('node_records', $id);

            if ($fremde === 0) {
                // ⚠️ *Nur Einstellungen: der Satz wechselt das Wort und bleibt, wie er ist.*
                $wpdb->update($saetze, ['record_type' => 'settings', 'version' => (int) $satz['version'] + 1], ['id' => $id], ['%s', '%d'], ['%d']);
                $gruppe = $log->record($id, 'record', 'record became a settings record', $satz['record_type'], 'settings', (int) $satz['version'] + 1, $gruppe);
                ++$umgetypt;

                continue;
            }

            // ⚠️ *Gemischt: ein Einstellungssatz daneben, die Einstellungszeilen ziehen um, der eigene
            // Wert bleibt im `default`-Satz ([D-673](../../../docs/NewConcept/90-decision-log.md)).
            // **Gibt es an der Adresse schon einen, wird der genommen** — einer je Adresse ([D-538](../../../docs/NewConcept/90-decision-log.md));
            // der erste Lauf legte hier einen zweiten an, gemessen an `Street / H#`.*
            $neu = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$saetze} WHERE node_id = %d AND relation_id = %d AND record_type = 'settings' ORDER BY id LIMIT 1",
                (int) $satz['node_id'],
                (int) $satz['relation_id']
            ));

            if ($neu === 0) {
                $wpdb->insert($saetze, [
                    'version'      => 1,
                    'node_id'      => (int) $satz['node_id'],
                    'node_version' => (int) $satz['node_version'],
                    'created_at'   => $jetzt,
                    'record_type'  => 'settings',
                    'relation_id'  => (int) $satz['relation_id'],
                ]);
                $neu = (int) $wpdb->insert_id;
            }
            $wpdb->query($wpdb->prepare(
                "UPDATE {$werte} v JOIN {$relations} r ON r.id = v.relation_id AND r.kind = 'setting'
                    SET v.node_record_id = %d WHERE v.node_record_id = %d",
                $neu,
                $id
            ));
            $gruppe = $log->record($neu, 'record', 'settings record split off', 'from record ' . $id, 'settings', 1, $gruppe);
            ++$geteilt;
        }

        // 1b. Einer je Adresse ([D-538](../../../docs/NewConcept/90-decision-log.md)): wo zwei
        // Einstellungssätze dieselbe Adresse tragen, bleibt der ältere; die Zeilen des jüngeren
        // ziehen um, soweit ihre Kante dort noch frei ist, sonst in den Schatten — und der geleerte
        // Satz auch. *Gemessen am 2026-09-09: `chooser-dialog` hatte zwei Vorgabesätze aus zwei Tagen.*
        $zusammengefuehrt = 0;

        /** @var list<array{node_id: string, relation_id: string}> $adressen */
        $adressen = $wpdb->get_results(
            "SELECT node_id, relation_id FROM {$saetze} WHERE record_type = 'settings'
              GROUP BY node_id, relation_id HAVING COUNT(*) > 1",
            ARRAY_A
        ) ?: [];

        foreach ($adressen as $adresse) {
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$saetze} WHERE node_id = %d AND relation_id = %d AND record_type = 'settings' ORDER BY id",
                (int) $adresse['node_id'],
                (int) $adresse['relation_id']
            )) ?: []);
            $bleibt = array_shift($ids);

            foreach ($ids as $juengerer) {
                foreach ($wpdb->get_results($wpdb->prepare("SELECT id, relation_id, locale FROM {$werte} WHERE node_record_id = %d", $juengerer), ARRAY_A) ?: [] as $zeile) {
                    $besetzt = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$werte} WHERE node_record_id = %d AND relation_id = %d AND locale = %s",
                        $bleibt,
                        (int) $zeile['relation_id'],
                        (string) $zeile['locale']
                    ));

                    if ($besetzt === 0) {
                        $wpdb->update($werte, ['node_record_id' => $bleibt], ['id' => (int) $zeile['id']], ['%d'], ['%d']);
                    } else {
                        Shadow::keepOne('relation_records', (int) $zeile['id'], true);
                        $wpdb->delete($werte, ['id' => (int) $zeile['id']], ['%d']);
                    }
                }

                Shadow::keepOne('node_records', $juengerer, true);
                $wpdb->delete($saetze, ['id' => $juengerer], ['%d']);
                $gruppe = $log->record($juengerer, 'record', 'settings record merged into the older one', 'record ' . $juengerer, 'record ' . $bleibt, null, $gruppe);
                ++$zusammengefuehrt;
            }
        }

        // 2. D-707: die Grenzen wohnen in den Grenzknoten, nicht an den Kanten.
        $grenzen = [
            ['typ' => 'Integer', 'unten' => 'integer_min', 'oben' => 'integer_max', 'spalte' => 'value_int', 'min' => (string) PHP_INT_MIN, 'max' => (string) PHP_INT_MAX],
            ['typ' => 'Decimal', 'unten' => 'decimal_min', 'oben' => 'decimal_max', 'spalte' => 'value_decimal', 'min' => '-99999999999999999999.9999999999', 'max' => '99999999999999999999.9999999999'],
        ];
        $gesetzt   = 0;
        $gestrichen = 0;

        $knotenNamens = static function (string $name) use ($wpdb, $nodes): int {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::table('nodes_named') . ' WHERE name = %s ORDER BY id LIMIT 1',
                $name
            ));
        };

        foreach ($grenzen as $grenze) {
            $typId = $knotenNamens($grenze['typ']);

            if ($typId === 0) {
                continue;
            }

            foreach ([['knoten' => $grenze['unten'], 'kante' => 'min', 'wert' => $grenze['min']], ['knoten' => $grenze['oben'], 'kante' => 'max', 'wert' => $grenze['max']]] as $seite) {
                $knotenId = $knotenNamens($seite['knoten']);

                if ($knotenId === 0) {
                    continue;
                }

                // Die Kantenwerte am Typ fallen in den Schatten.
                $kantenId = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT r.id FROM ' . self::table('relations_named') . ' r WHERE r.from_node_id = %d AND r.name = %s AND r.kind = %s LIMIT 1',
                    $typId,
                    $seite['kante'],
                    'setting'
                ));

                if ($kantenId !== 0) {
                    $zeilen = $wpdb->get_results($wpdb->prepare(
                        "SELECT v.id, v.version FROM {$werte} v JOIN {$saetze} s ON s.id = v.node_record_id WHERE s.node_id = %d AND v.relation_id = %d",
                        $typId,
                        $kantenId
                    ), ARRAY_A) ?: [];

                    foreach ($zeilen as $zeile) {
                        Shadow::keepOne('relation_records', (int) $zeile['id'], true);
                        $wpdb->delete($werte, ['id' => (int) $zeile['id']], ['%d']);
                        $gruppe = $log->record((int) $zeile['id'], 'record_value', 'limit moved into the bound node', $seite['kante'] . ' at ' . $grenze['typ'], $seite['knoten'], (int) $zeile['version'], $gruppe);
                        ++$gestrichen;
                    }
                }

                // Der eigene Wert des Grenzknotens ist die Grenze — im `default`-Satz.
                $satzId = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$saetze} WHERE node_id = %d AND record_type = 'default' AND relation_id = 0 ORDER BY id LIMIT 1",
                    $knotenId
                ));

                if ($satzId === 0) {
                    $version = (int) $wpdb->get_var($wpdb->prepare("SELECT version FROM {$nodes} WHERE id = %d", $knotenId));
                    $wpdb->insert($saetze, ['version' => 1, 'node_id' => $knotenId, 'node_version' => $version, 'created_at' => $jetzt, 'record_type' => 'default', 'relation_id' => 0]);
                    $satzId = (int) $wpdb->insert_id;
                }

                // ⚠️ *Zweimal ausführbar: steht die Grenze schon da, wird nichts angefasst und nichts journalt.*
                $steht = (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT {$grenze['spalte']} FROM {$werte} WHERE node_record_id = %d AND relation_id = 0 LIMIT 1",
                    $satzId
                ));

                if ($steht !== '' && (float) $steht === (float) $seite['wert'] && $steht === $seite['wert']) {
                    continue;
                }

                foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM {$werte} WHERE node_record_id = %d AND relation_id = 0", $satzId)) ?: [] as $alt) {
                    Shadow::keepOne('relation_records', (int) $alt, true);
                    $wpdb->delete($werte, ['id' => (int) $alt], ['%d']);
                }

                $wpdb->insert($werte, ['node_record_id' => $satzId, 'relation_id' => 0, 'locale' => '', 'position' => 0, 'version' => 1, $grenze['spalte'] => $seite['wert']]);
                $gruppe = $log->record($satzId, 'record_value', 'own value set', null, $seite['knoten'] . ' = ' . $seite['wert'], 1, $gruppe);
                ++$gesetzt;
            }

            // Der Rest «72» am Typ selbst fällt.
            foreach ($wpdb->get_col($wpdb->prepare(
                "SELECT v.id FROM {$werte} v JOIN {$saetze} s ON s.id = v.node_record_id WHERE s.node_id = %d AND s.record_type = 'default' AND v.relation_id = 0",
                $typId
            )) ?: [] as $alt) {
                Shadow::keepOne('relation_records', (int) $alt, true);
                $wpdb->delete($werte, ['id' => (int) $alt], ['%d']);
                ++$gestrichen;
            }
        }

        // Nachgezählt: kein Satz ausser `settings` trägt noch eine Einstellungszeile.
        $geblieben = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT s.id) FROM {$saetze} s JOIN {$werte} v ON v.node_record_id = s.id
               JOIN {$relations} r ON r.id = v.relation_id AND r.kind = 'setting' WHERE s.record_type <> 'settings'"
        );

        if ($geblieben !== 0) {
            throw new \RuntimeException(sprintf(
                'Fassung 42: %d Saetze tragen noch Einstellungszeilen ohne settings zu sein. Die Fassungsnummer bleibt stehen.',
                $geblieben
            ));
        }

        $mehrfach = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (SELECT node_id FROM {$saetze} WHERE record_type = 'settings' GROUP BY node_id, relation_id HAVING COUNT(*) > 1) d"
        );

        if ($mehrfach !== 0) {
            throw new \RuntimeException(sprintf('Fassung 43: %d Adressen tragen noch mehrere Einstellungssaetze. Die Fassungsnummer bleibt stehen.', $mehrfach));
        }

        if ($umgetypt + $geteilt + $gesetzt + $gestrichen + $zusammengefuehrt > 0) {
            update_option('taxmod_fassung42_shape', ['umgetypt' => $umgetypt, 'geteilt' => $geteilt, 'zusammengefuehrt' => $zusammengefuehrt, 'grenzen' => $gesetzt, 'gestrichen' => $gestrichen, 'gruppe' => $gruppe], false);
        }
    }

    /**
     * Fassung 45: die Einstellungskante `position` an der Wurzel, `0..1` auf `Integer`.
     *
     * ⚠️ **[D-698](../../../docs/NewConcept/90-decision-log.md), sein Wort:** *«würde sagen kind darf
     * felder neu anordnen».* *An der Wurzel wie `read_only` und `renderer`, weil sie für jede
     * Verwendungsstelle gilt; gelesen und geschrieben wird sie im Satz `Knoten × Kante`
     * ({@see \Taxmod\Core\Service\FieldOrder}). Nichts wandert: kein Bestand trägt eine Anordnung.*
     */
    private static function declarePositionAtRoot(): void
    {
        global $wpdb;

        $nodes = self::table('nodes');

        if (self::tableMissing($nodes) || self::tableMissing(self::table('relations_named'))) {
            return;
        }

        $log       = new WpdbChangelog(new SystemClock());
        $knoten    = new WpdbNodeRepository();
        $kanten    = new WpdbRelationRepository();
        $framework = new SeededFrameworkNodes($knoten, $kanten, $log);
        $wurzel    = $framework->root()->id;
        $integer   = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$nodes} WHERE implemented_by = %s ORDER BY id LIMIT 1", \Taxmod\Core\Model\Type\IntType::class));

        if ($wurzel === 0 || $integer === 0) {
            return;
        }

        $schonDa = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table('relations_named') . " WHERE from_node_id = %d AND name = %s AND kind = 'setting'",
            $wurzel,
            'position'
        ));

        if ($schonDa > 0) {
            return;
        }

        $editor = new \Taxmod\Core\Service\ModelEditor($knoten, $kanten, $framework, $log, new WpdbLabelRepository(), new WpdbRecordRepository());

        $kante = $editor->addField($wurzel, $integer, 'position', \Taxmod\Core\Model\RelationKind::Setting);
        $editor->setMultiplicity($wurzel, $kante->id, \Taxmod\Core\Model\Multiplicity::ZeroToOne);

        update_option('taxmod_fassung45_shape', ['position' => $kante->id, 'an' => $wurzel], false);
    }

    /**
     * Fassung 46: **jeder Knoten bekommt seine Klasse**, nach der Tabelle K3 der Protokollseite
     * ([D-716](../../../docs/NewConcept/90-decision-log.md), [D-719](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Nur Zeilen ohne Klasse werden angefasst** — die Vergabe ist einmalig, danach ist die Klasse
     * fest (Anforderung 2.1.4). *Ein zweiter Lauf findet nichts Leeres mehr und tut nichts; ein Knoten,
     * den jemand danach anlegt, bekommt seine Klasse vom Kern.*
     *
     * ⚠️ **Anker über Notizen, nicht über Namen** ([D-709](../../../docs/NewConcept/90-decision-log.md)),
     * mit der einen Ausnahme `Currency`, die keine Notiz hat — siehe den Kopf der Fassung.
     */
    private static function assignNodeClasses(): void
    {
        global $wpdb;

        $nodes = self::table('nodes');

        if (self::tableMissing($nodes) || $wpdb->get_var("SHOW COLUMNS FROM {$nodes} LIKE 'klasse'") === null) {
            return;
        }

        $leer = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$nodes} WHERE klasse = ''");

        if ($leer === 0) {
            return;
        }

        $setze = static function (string $klasse, string $where, array $args = []) use ($wpdb, $nodes): int {
            $sql = "UPDATE {$nodes} SET klasse = %s WHERE klasse = '' AND ({$where})";

            return (int) $wpdb->query($wpdb->prepare($sql, $klasse, ...$args));
        };

        $vergeben = [];

        // 1 · Die elf Typknoten sind ihre eigene Klasse — sie steht schon als `implemented_by` da.
        foreach (\Taxmod\Core\Model\Type\SpecialisedTypes::CLASSES as $typ) {
            $vergeben[$typ] = ($vergeben[$typ] ?? 0) + $setze($typ, 'implemented_by = %s', [$typ]);
        }

        // 2 · Auswahlknoten und ihre Kinder.
        $prefixes = (int) (UnitScaffold::nodeId('Prefixes') ?? 0);
        $base     = (int) (UnitScaffold::nodeId('Base units') ?? 0);
        $roles    = (int) get_option('taxmod_roles_id', 0);
        $konstanten = (int) get_option('taxmod_branch_constants_id', 0);
        $currency = $konstanten === 0 ? 0 : (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('nodes_named') . ' WHERE parent_node_id = %d AND name = %s ORDER BY id LIMIT 1',
            $konstanten,
            'Currency'
        ));

        $auswahl   = \Taxmod\Core\Model\NodeClass\Choice::class;
        $konstante = \Taxmod\Core\Model\NodeClass\Constant::class;
        $einheit   = \Taxmod\Core\Model\NodeClass\Unit::class;

        foreach (array_filter([$prefixes, $base, $roles, $currency]) as $id) {
            $vergeben[$auswahl] = ($vergeben[$auswahl] ?? 0) + $setze($auswahl, 'id = %d', [$id]);
        }

        foreach (array_filter([$prefixes, $roles]) as $id) {
            $vergeben[$konstante] = ($vergeben[$konstante] ?? 0) + $setze($konstante, 'parent_node_id = %d', [$id]);
        }

        // ⚠️ *Die Einheiten liegen **unter** `With prefix` / `Without prefix`, die selbst Kategorien
        // bleiben — «mit/ohne präfix ist eine eigenschaft je blatt» (K2).*
        if ($base !== 0) {
            $vergeben[$einheit] = ($vergeben[$einheit] ?? 0) + $setze(
                $einheit,
                "parent_node_id IN (SELECT id FROM (SELECT id FROM {$nodes} WHERE parent_node_id = %d) AS gruppen)",
                [$base]
            );
        }

        if ($currency !== 0) {
            $vergeben[$einheit] = ($vergeben[$einheit] ?? 0) + $setze($einheit, 'parent_node_id = %d', [$currency]);
        }

        // 3 · Der gesäte Einheitenwert.
        $einheitenwert = (int) (UnitScaffold::unitValueId() ?? 0);

        if ($einheitenwert !== 0) {
            $unitValue = \Taxmod\Core\Model\NodeClass\UnitValue::class;
            $vergeben[$unitValue] = ($vergeben[$unitValue] ?? 0) + $setze($unitValue, 'id = %d', [$einheitenwert]);
        }

        // 4 · Alles andere ist eine Kategorie — auch das, was mit Schritt 7 fällt.
        $kategorie = \Taxmod\Core\Model\NodeClass\Category::class;
        $vergeben[$kategorie] = ($vergeben[$kategorie] ?? 0) + $setze($kategorie, '1 = 1');

        Shadow::forgetColumnPlan();

        update_option('taxmod_fassung46_shape', ['leer_vorher' => $leer, 'vergeben' => $vergeben], false);
    }

    /**
     * Fassung 47: **jeder Verweis in `settings_value` ist ein Fremdschlüssel, den die Datenbank prüft**
     * (Anforderung 4.2.3 — sein Wort: *«das können wir über die relationen über die datenbank
     * sicherstellen, das finde ich einen grossen vorteil»*).
     *
     * ⚠️ *Fünf Bedingungen, eine je Verweisspalte; jede nur, wenn sie noch nicht steht. Die Tabellen
     * sind leer ([D-717](../../../docs/NewConcept/90-decision-log.md)), also gibt es keine Waisen, die
     * eine Bedingung verhindern könnten — anders als bei {@see self::constrainRelationsToNodes()}.*
     */
    /**
     * Fassung 48: **`read_only` in die Spalte der Kante, und die Einstellungskante in den Schatten**
     * ([D-714](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Drei Schritte, in dieser Reihenfolge:** *(1) jede Verwendungsstelle, an der ein
     * `settings`-Satz `read_only = 1` trägt, bekommt die Spalte gesetzt — die Zeile ist die Kante
     * selbst, also `node_records.relation_id`; (2) jede Einstellungskante namens `read_only` wird
     * über den Kern geparkt ({@see \Taxmod\Core\Service\ModelEditor::removeField()}), und
     * [D-619](../../../docs/NewConcept/90-decision-log.md) nimmt ihre Wertzeilen mit — die am Knoten
     * darunter, weil es kein `read_only` am Knoten mehr gibt; (3) die Marke, damit es einmal läuft.*
     *
     * ⚠️ *Über den Kern und nicht mit rohem SQL, wie Fassung 44 und 45: ein Parken ist ein Akt mit
     * Änderungsgruppe, und nur der Kern schreibt ihn richtig.*
     */
    private static function moveReadOnlyOntoTheRelation(): void
    {
        global $wpdb;

        $relations = self::table('relations');

        if (self::tableMissing($relations) || $wpdb->get_var("SHOW COLUMNS FROM {$relations} LIKE 'read_only'") === null) {
            return;
        }

        if (get_option('taxmod_fassung48_shape', null) !== null) {
            return;
        }

        $benannt = self::table('relations_named');
        $records = self::table('node_records');
        $values  = self::table('relation_records');

        /** @var list<array{id: string, from_node_id: string}> $kanten */
        $kanten = $wpdb->get_results("SELECT id, from_node_id FROM {$benannt} WHERE kind = 'setting' AND name = 'read_only'", ARRAY_A) ?: [];

        $gesetzt = 0;

        foreach ($kanten as $kante) {
            // 1 · Werte an Verwendungsstellen in die Spalte.
            $gesetzt += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$relations} r
                 JOIN {$records} nr ON nr.relation_id = r.id AND nr.record_type = 'settings'
                 JOIN {$values} rr ON rr.node_record_id = nr.id AND rr.relation_id = %d
                 SET r.read_only = 1
                 WHERE rr.value_int = 1",
                (int) $kante['id']
            ));
        }

        // 2 · Die Einstellungskanten parken — über den Kern.
        $log       = new WpdbChangelog(new SystemClock());
        $knoten    = new WpdbNodeRepository();
        $kantenRep = new WpdbRelationRepository();
        $framework = new SeededFrameworkNodes($knoten, $kantenRep, $log);
        $editor    = new \Taxmod\Core\Service\ModelEditor($knoten, $kantenRep, $framework, $log, new WpdbLabelRepository(), new WpdbRecordRepository());

        $geparkt = 0;

        foreach ($kanten as $kante) {
            $editor->removeField((int) $kante['from_node_id'], (int) $kante['id']);
            $geparkt++;
        }

        Shadow::forgetColumnPlan();

        update_option('taxmod_fassung48_shape', ['spalte_gesetzt' => $gesetzt, 'kanten_geparkt' => $geparkt], false);
    }

    /**
     * Fassung 49: **das Alte fällt** — Schritt 7 des Bauplans ([D-712](../../../docs/NewConcept/90-decision-log.md),
     * [D-718](../../../docs/NewConcept/90-decision-log.md), [D-719](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ```mermaid
     * flowchart LR
     *   R["Label roles"] -->|"wandern"| C["Constants"]
     *   S["Settings: Renderer, Converter, Validator, Orientation"] -->|"Schatten"| X["nodes_history"]
     *   E["Einstellungskanten (ausser position an der Wurzel)"] -->|"Schatten"| Y["relations_history"]
     *   T["integer_min, display size, … unter den Typen"] -->|"Schatten"| X
     * ```
     *
     * ⚠️ **Sein Wort:** *«knoten und felder können weg»* (D-718), *«K3a ja, fallen»* und *«K3c unter
     * constants»* (D-719). *Alles wandert in den Schatten nach der Regel für alles (Anforderung 4.6);
     * kein Sonderweg. Die Werte der alten Einstellungskanten werden **nicht** übertragen — «wir beginnen
     * leer dann können wir schön testen» ([D-717](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ **Was bleibt, und warum:** *die Einstellungskante `position` an der Wurzel mit ihren Sätzen —
     * sie trägt die Stelle geerbter Felder am Kind (Modell 2.2), und wo das künftig gespeichert wird, ist
     * offen (Modell 2.4: «nicht in Einstellungszeilen»). Bis dahin bleibt sie, sichtbar geparkt, und mit
     * ihr die Kantenart `setting` und die Satzart `settings` (TASK-093 bleibt offen).*
     */
    private static function dropTheSettingsBranch(): void
    {
        global $wpdb;

        $nodes     = self::table('nodes');
        $relations = self::table('relations');

        if (self::tableMissing($nodes) || self::tableMissing($relations) || self::tableMissing(self::table('settings_value'))) {
            return;
        }

        if (get_option('taxmod_fassung49_shape', null) !== null) {
            return;
        }

        $log       = new WpdbChangelog(new SystemClock());
        $knoten    = new WpdbNodeRepository();
        $kanten    = new WpdbRelationRepository();
        $records   = new WpdbRecordRepository();
        $framework = new SeededFrameworkNodes($knoten, $kanten, $log);
        $editor    = new \Taxmod\Core\Service\ModelEditor($knoten, $kanten, $framework, $log, new WpdbLabelRepository(), $records);
        $zaehlung  = ['rollen' => 0, 'typknoten' => 0, 'kanten' => 0, 'ast' => 0, 'saetze' => 0];

        // 1 · Die Rollen wandern unter Constants — bevor der Ast fällt, in dem sie wohnten.
        $constants = $framework->rootOf(\Taxmod\Core\Model\Branch::Constants);
        $rollen    = $knoten->find((int) get_option('taxmod_roles_id', 0));

        if ($rollen !== null && $rollen->parentNodeId !== $constants->id) {
            $editor->move($rollen->id, $constants->id);
            $zaehlung['rollen'] = 1;
        }

        // 1b · Und sie bekommen die Klasse aus K3: der Behälter eine Auswahl, jede Rolle eine Konstante
        //      (D-719) — Ziele von `label_role`, das der Verweis-Renderer als Verweis auf eine Konstante erklärt.
        if ($rollen !== null) {
            $klassen = [$rollen->id => \Taxmod\Core\Model\NodeClass\Choice::class];

            foreach ($knoten->childrenOf($rollen) as $rolle) {
                $klassen[$rolle->id] = \Taxmod\Core\Model\NodeClass\Constant::class;
            }

            foreach ($klassen as $id => $klasse) {
                if ($knoten->byId($id)->klasse === $klasse) {
                    continue;
                }

                Shadow::keepOne('nodes', $id);
                $wpdb->update($nodes, ['klasse' => $klasse], ['id' => $id], ['%s'], ['%d']);
                $zaehlung['rollen']++;
            }
        }

        // 2 · Die Einstellungsknoten unter den Typen (K3a) — die neun, die die Saat unter Integer, Decimal
        //     und Boolean angelegt hat. *Sein Wort: «K3a ja, fallen». Gemessen am 2026-09-11: keine Kante
        //     zeigt auf sie, sie tragen nur ihren default-Satz — also nennt die Wanderung sie, wie die Saat
        //     sie nannte, und nur unter diesen drei Typknoten.*
        $dataTypes = $framework->rootOf(\Taxmod\Core\Model\Branch::DataTypes);
        $typknoten = [];
        $dreiTypen = array_map(intval(...), $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$nodes} WHERE parent_node_id = %d AND implemented_by IN (%s, %s, %s)",
            $dataTypes->id,
            \Taxmod\Core\Model\Type\IntType::class,
            \Taxmod\Core\Model\Type\DecimalType::class,
            \Taxmod\Core\Model\Type\BoolType::class
        )) ?: []);

        if ($dreiTypen !== []) {
            $namen     = ['integer_min', 'integer_max', 'integer_step', 'display size', 'decimal_min', 'decimal_max', 'decimal_step', 'Read Only', 'With Label'];
            $plaetze   = implode(',', array_fill(0, count($dreiTypen), '%d'));
            $namenPl   = implode(',', array_fill(0, count($namen), '%s'));
            $typknoten = array_map(intval(...), $wpdb->get_col($wpdb->prepare(
                'SELECT id FROM ' . self::table('nodes_named') . " WHERE parent_node_id IN ({$plaetze}) AND name IN ({$namenPl})",
                ...[...$dreiTypen, ...$namen]
            )) ?: []);
        }

        // 3 · Die Einstellungskanten — alle bis auf `position` an der Wurzel.
        $position = (new \Taxmod\Core\Service\FieldOrder($records, $kanten, $knoten, $framework))->positionRelation()?->id ?? 0;

        foreach ($wpdb->get_col("SELECT id FROM {$relations} WHERE kind = 'setting'") ?: [] as $kanteId) {
            $kanteId = (int) $kanteId;

            if ($kanteId === $position) {
                continue;
            }

            Shadow::keep('relation_records', 'relation_id = %d', [$kanteId], true);
            $wpdb->delete(self::table('relation_records'), ['relation_id' => $kanteId], ['%d']);
            Shadow::keepOne('relations', $kanteId, true);
            $wpdb->delete($relations, ['id' => $kanteId], ['%d']);
            $zaehlung['kanten']++;
        }

        // 4 · Der Ast `Settings` fällt — mit allem, was darin wohnt.
        $ast = $knoten->find((int) get_option('taxmod_branch_settings_id', 0));

        if ($ast !== null) {
            if ($ast->parentNodeId !== $framework->trash()->id) {
                $editor->moveToTrash($ast->id);
            }

            $zaehlung['ast'] = (int) ($editor->clearTrash([$ast->id])['nodes'] ?? 0);
        }

        // 5 · Die Einstellungsknoten unter den Typen fallen.
        foreach ($typknoten as $id) {
            if ($knoten->find($id) !== null) {
                $editor->moveToTrash($id);
            }
        }

        if ($typknoten !== []) {
            $editor->clearTrash($typknoten);
            $zaehlung['typknoten'] = count($typknoten);
        }

        // 6 · Einstellungssätze, die nichts mehr tragen, wandern in den Schatten.
        $saetze = self::table('node_records');
        $werte  = self::table('relation_records');

        foreach ($wpdb->get_col("SELECT r.id FROM {$saetze} r WHERE r.record_type = 'settings' AND NOT EXISTS (SELECT 1 FROM {$werte} v WHERE v.node_record_id = r.id)") ?: [] as $satzId) {
            $records->forgetRecord((int) $satzId);
            $zaehlung['saetze']++;
        }

        // 7 · Die Zeiger auf das Gefallene.
        delete_option('taxmod_branch_settings_id');
        delete_option('taxmod_rendering_scaffold');
        self::forgetOrphanLabels();
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'taxmod\\_render\\_%' OR option_name LIKE 'taxmod\\_setting\\_edge\\_%' OR option_name LIKE 'taxmod\\_setting\\_value\\_edge\\_%'");

        Shadow::forgetColumnPlan();
        update_option('taxmod_fassung49_shape', $zaehlung, false);
    }

    /**
     * Fassung 50: jedes Einstellungsobjekt der beiden alten Wählerklassen wird eines der Klasse `chooser`; wer den
     * Dialog hatte, bekommt `dialog = true`, damit sich nichts ändert, was er sieht ([D-727](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Die Klassennamen stehen hier als Wörter, weil die Klassen nicht mehr existieren — genau dafür ist eine
     * Wanderung da. Die Adresse `klasse` steht in den Wertzeilen ebenso (Attribut `label_role`) und in den Schatten.*
     */
    private static function mergeTheChoosers(): void
    {
        global $wpdb;

        if (self::tableMissing(self::table('settings_object')) || get_option('taxmod_fassung50_chooser', null) !== null) {
            return;
        }

        $alte    = ['Taxmod\\Core\\Renderer\\DialogChooserRenderer', 'Taxmod\\Core\\Renderer\\InlineChooserRenderer'];
        $neue    = \Taxmod\Core\Renderer\ChooserRenderer::class;
        $objekte = self::table('settings_object');
        $dialoge = array_map(intval(...), $wpdb->get_col($wpdb->prepare("SELECT id FROM {$objekte} WHERE klasse = %s", $alte[0])) ?: []);
        $zaehlung = ['dialog' => count($dialoge), 'zeilen' => 0];

        foreach (['settings_object', 'settings_object_history', 'settings_value', 'settings_value_history'] as $tabelle) {
            if (self::tableMissing(self::table($tabelle))) {
                continue;
            }

            $zaehlung['zeilen'] += (int) $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::table($tabelle) . ' SET klasse = %s WHERE klasse IN (%s, %s)',
                $neue,
                $alte[0],
                $alte[1]
            ));
        }

        $ablage = new WpdbSettingsRepository();

        foreach ($dialoge as $objektId) {
            $ablage->addValue(\Taxmod\Core\Model\Setting\SettingsValue::inObject($objektId, $neue, \Taxmod\Core\Renderer\ChooserRenderer::DIALOG, \Taxmod\Core\Model\TypedValue::ofBool(true)));
        }

        update_option('taxmod_fassung50_chooser', $zaehlung, false);
    }

    /** Fassung 52: die Einstellung `with_parent` des Verweis-Renderers heisst `use_parent_label` — sein Wort (D-746). */
    /**
     * Fassung 53: `wert_kante_id` steht im Schatten an derselben Stelle wie in der lebenden Tabelle.
     *
     * ⚠️ *`dbDelta` hängt eine neue Spalte hinten an — im Schatten also hinter `archived_at`, und der
     * Wächter `settings-tables` verlangt dieselbe Reihenfolge wie vorn. Läuft bei jedem Aufbau, tut aber
     * nur etwas, wenn die Spalte an der falschen Stelle steht.*
     */
    private static function placeWertKanteId(): void
    {
        global $wpdb;

        $schatten = self::table('settings_value_history');

        if (self::tableMissing($schatten)) {
            return;
        }

        $spalten = array_map(static fn (object $c): string => (string) $c->Field, $wpdb->get_results("SHOW COLUMNS FROM {$schatten}"));
        $wo      = array_search('wert_kante_id', $spalten, true);

        if ($wo === false || ($spalten[$wo + 1] ?? '') === 'deleted') {
            return;
        }

        $wpdb->query("ALTER TABLE {$schatten} MODIFY COLUMN wert_kante_id bigint(20) unsigned DEFAULT NULL AFTER wert_settings_object_id");
        Shadow::forgetColumnPlan();
    }

    private static function renameWithParent(): void
    {
        global $wpdb;

        if (self::tableMissing(self::table('settings_value')) || get_option('taxmod_fassung52_use_parent_label', null) !== null) {
            return;
        }

        $zeilen = 0;

        foreach (['settings_value', 'settings_value_history'] as $tabelle) {
            if (self::tableMissing(self::table($tabelle))) {
                continue;
            }

            $zeilen += (int) $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::table($tabelle) . ' SET attribut = %s WHERE klasse = %s AND attribut = %s',
                \Taxmod\Core\Renderer\ReferenceRenderer::WITH_PARENT,
                \Taxmod\Core\Renderer\ReferenceRenderer::class,
                'with_parent'
            ));
        }

        update_option('taxmod_fassung52_use_parent_label', ['zeilen' => $zeilen], false);
    }

    private static function constrainSettingsValues(): void
    {
        global $wpdb;

        $values  = self::table('settings_value');
        $objects = self::table('settings_object');

        if (self::tableMissing($values) || self::tableMissing($objects)) {
            return;
        }

        $ziele = [
            ['node_id',                 self::table('nodes'),     'taxmod_sv_node'],
            ['settings_object_id',      $objects,                 'taxmod_sv_object'],
            ['relation_id',             self::table('relations'), 'taxmod_sv_relation'],
            ['wert_knoten_id',          self::table('nodes'),     'taxmod_sv_wert_node'],
            ['wert_settings_object_id', $objects,                 'taxmod_sv_wert_object'],
            ['wert_kante_id',           self::table('relations'), 'taxmod_sv_wert_relation'],
        ];

        foreach ($ziele as [$spalte, $ziel, $bedingung]) {
            $steht = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                $values,
                $spalte
            ));

            if ($steht > 0) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$values} ADD CONSTRAINT {$bedingung} FOREIGN KEY ({$spalte}) REFERENCES {$ziel} (id)");
        }
    }

    private static function constrainRelationsToNodes(): void
    {
        global $wpdb;

        $relations = self::table('relations');
        $nodes     = self::table('nodes');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $relations)) !== $relations) {
            return;
        }

        foreach ([['from_node_id', 'taxmod_rel_from_node'], ['to_node_id', 'taxmod_rel_to_node']] as [$spalte, $bedingung]) {
            $steht = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                $relations,
                $spalte
            ));

            if ($steht > 0) {
                continue;
            }

            $waisen = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$relations} r
                 LEFT JOIN {$nodes} n ON n.id = r.{$spalte} WHERE n.id IS NULL"
            );

            // ⚠️ *Keine Bedingung auf Daten, die sie verletzen — sie käme still nicht zustande.
            // `id-space-check.php` zählt die Waisen und wird rot, also bleibt der Befund sichtbar.*
            if ($waisen > 0) {
                continue;
            }

            $wpdb->query(
                "ALTER TABLE {$relations}
                 ADD CONSTRAINT {$bedingung} FOREIGN KEY ({$spalte}) REFERENCES {$nodes} (id)"
            );
        }
    }

    /**
     * Jede Stelle, die unter demselben Knoten und derselben Kantenart zweimal vergeben ist, ans Ende
     * schieben — damit der eindeutige Schlüssel gelegt werden kann (TASK-012).
     *
     * ⚠️ *Gemessen genau **eine** Gruppe. Der Schritt zählt trotzdem allgemein durch, weil eine
     * Wanderung, die nur den gemessenen Fall kann, auf der nächsten Installation falsch ist.*
     *
     * ⚠️ **Die kleinste Id behält ihre Stelle**, jede weitere bekommt die nächste freie ihrer Liste.
     * *Das ist willkürlich und darf es sein: zwischen zwei Kanten, die beide auf `0` standen, gab es
     * keine Reihenfolge, die man verlieren könnte.*
     */
    private static function freeTheDoubledPlaces(): void
    {
        global $wpdb;

        $relations = self::table('relations');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $relations)) !== $relations) {
            return;
        }

        /** @var list<array{from_node_id: string, kind: string, sort_order: string}> $gruppen */
        $gruppen = $wpdb->get_results(
            "SELECT from_node_id, kind, sort_order FROM {$relations}
             GROUP BY from_node_id, kind, sort_order HAVING COUNT(*) > 1",
            ARRAY_A
        ) ?: [];

        foreach ($gruppen as $gruppe) {
            $ids = array_map(intval(...), $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$relations}
                 WHERE from_node_id = %d AND kind = %s AND sort_order = %d ORDER BY id",
                (int) $gruppe['from_node_id'],
                (string) $gruppe['kind'],
                (int) $gruppe['sort_order']
            )));

            // Die erste behält ihre Stelle.
            array_shift($ids);

            foreach ($ids as $id) {
                $frei = 1 + (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(MAX(sort_order), 0) FROM {$relations} WHERE from_node_id = %d AND kind = %s",
                    (int) $gruppe['from_node_id'],
                    (string) $gruppe['kind']
                ));

                // ⚠️ *Umkehrbar ([D-535](../../../docs/NewConcept/90-decision-log.md)): erst
                // aufheben, dann schreiben.*
                Shadow::keepOne('relations', $id);

                $wpdb->query($wpdb->prepare(
                    "UPDATE {$relations} SET sort_order = %d, version = version + 1 WHERE id = %d",
                    $frei,
                    $id
                ));
            }
        }
    }

    /**
     * Die vier Datensatztabellen heissen nach dem, woran sie hängen — Fassung 29 (TASK-014).
     *
     * ⚠️ **`records` → `node_records`, `record_values` → `relation_records`, samt Schatten.** *Der
     * Eigentümer: «records → node_records, record_values → relation_records».*
     *
     * ⚠️ *Sie läuft vor allem anderen und prüft jede Tabelle einzeln: eine frische Installation hat
     * keine der alten, eine halb gewanderte hat einige. **Umbenannt wird nur, was unter dem alten
     * Namen dasteht und unter dem neuen noch nicht** — sonst überschriebe ein zweiter Lauf eine
     * bereits gefüllte Tabelle.*
     */
    private static function renameRecordTables(): void
    {
        global $wpdb;

        $umzuege = [
            ['records', 'node_records'],
            ['record_values', 'relation_records'],
            ['records_history', 'node_records_history'],
            ['record_values_history', 'relation_records_history'],
        ];

        foreach ($umzuege as [$von, $nach]) {
            $alt = $wpdb->prefix . 'taxmod_' . $von;
            $neu = $wpdb->prefix . 'taxmod_' . $nach;

            $altDa = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $alt)) === $alt;
            $neuDa = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $neu)) === $neu;

            if (! $altDa || $neuDa) {
                continue;
            }

            $wpdb->query("RENAME TABLE {$alt} TO {$neu}");
        }
    }

    /**
     * `relation_records.record_id` heisst `node_record_id`, `relation_id` heisst `relation_id` —
     * Fassung 29 (TASK-014).
     *
     * ⚠️ **Ein Fremdschlüssel nennt seine Zieltabelle** ([D-164](../../../docs/NewConcept/90-decision-log.md)),
     * dieselbe Regel, die aus `from_id` `from_node_id` gemacht hat. *Und das Wort ist `relation`,
     * nicht `relation` ([D-576](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ **Der Einzelindex `relation_id` muss von Hand fallen:** *eine umbenannte Spalte behält den
     * **Namen** ihres Indexes, und `dbDelta` legte daneben einen zweiten `relation_id`. Der
     * zusammengesetzte `of_field` behält seinen Namen und folgt der Umbenennung von selbst.*
     */
    private static function renameRelationRecordColumns(): void
    {
        global $wpdb;

        $umzuege = [
            ['record_id', 'node_record_id'],
            ['relation_id', 'relation_id'],
        ];

        foreach (['relation_records', 'relation_records_history'] as $name) {
            $table = self::table($name);

            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                continue;
            }

            foreach ($umzuege as [$von, $nach]) {
                $vorhanden = $wpdb->get_col($wpdb->prepare(
                    'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    $table,
                    $von
                ));

                if ($vorhanden === []) {
                    continue;
                }

                // ⚠️ `CHANGE` und nicht `RENAME COLUMN`: das will MySQL 8, und dieser Plugin sucht
                // sich den Server nicht aus.
                $wpdb->query("ALTER TABLE {$table} CHANGE {$von} {$nach} bigint(20) unsigned NOT NULL");
            }

            $alterIndex = $wpdb->get_col($wpdb->prepare(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
                $table,
                'relation_id'
            ));

            if ($alterIndex !== []) {
                $wpdb->query("ALTER TABLE {$table} DROP INDEX relation_id");
            }
        }
    }

    /**
     * `node_records.kind` heisst `record_type` — Fassung 30 (TASK-015).
     *
     * ⚠️ **`CD-9`, und der Befund stand als offene Frage in der Aufgabenliste:** *drei Spalten
     * hiessen `kind` und meinten drei verschiedene Dinge. Die erste wurde `field_type` (TASK-007),
     * die zweite fällt mit der Kantenart, und diese heisst jetzt, was sie ist.*
     *
     * ⚠️ *Der Index hiess `kind` und zieht mit — dieselbe Falle wie bei `relation_id`: eine umbenannte
     * Spalte behält den **Namen** ihres Indexes, und `dbDelta` legte daneben einen zweiten.*
     */
    private static function renameRecordTypeColumn(): void
    {
        global $wpdb;

        foreach (['node_records', 'node_records_history'] as $name) {
            $table = self::table($name);

            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                continue;
            }

            $vorhanden = $wpdb->get_col($wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                'kind'
            ));

            if ($vorhanden === []) {
                continue;
            }

            $wpdb->query(
                "ALTER TABLE {$table} CHANGE kind record_type varchar(20) NOT NULL DEFAULT 'user'"
            );

            $alterIndex = $wpdb->get_col($wpdb->prepare(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
                $table,
                'kind'
            ));

            if ($alterIndex !== []) {
                $wpdb->query("ALTER TABLE {$table} DROP INDEX kind");
            }
        }
    }

    /**
     * `version` steht unter `id` — Fassung 30 (TASK-015).
     *
     * ⚠️ **Der Eigentümer:** *«`version` würde ich nach oben unter `id` packen.»* **`dbDelta` stellt
     * das nicht her:** *es fügt eine fehlende Spalte hinten an und ordnet nie um. Wer die Ordnung
     * nur im `CREATE TABLE` ändert, hat sie auf einer frischen Installation und sonst nirgends.*
     *
     * ⚠️ *Nach `dbDelta`, weil die Spalte dastehen muss, bevor sie verschoben werden kann — und der
     * Schritt fragt vorher, wo sie steht: steht sie schon an zweiter Stelle, tut er nichts.*
     */
    private static function orderRecordColumns(): void
    {
        global $wpdb;

        foreach (['node_records', 'node_records_history'] as $name) {
            $table = self::table($name);

            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                continue;
            }

            $stelle = $wpdb->get_var($wpdb->prepare(
                'SELECT ORDINAL_POSITION FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                'version'
            ));

            if ($stelle === null || (int) $stelle === 2) {
                continue;
            }

            // Der Schatten führt `version` im Schlüssel und darum ohne Vorgabe; die lebende Tabelle
            // vergibt sie mit `1`.
            $vorgabe = $name === 'node_records' ? ' DEFAULT 1' : '';

            $wpdb->query(
                "ALTER TABLE {$table} MODIFY version int(10) unsigned NOT NULL{$vorgabe} AFTER id"
            );
        }
    }

    private static function renameRecordColumns(): void
    {
        global $wpdb;

        $table = self::table('node_records');

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

    // ⚠️ **Hier stand `backfillInheritanceRelations()` — Fassung 3 — und der Schritt ist gestrichen**
    // (TASK-018, [D-581](../../../docs/NewConcept/90-decision-log.md)). *Er legte für jeden Knoten,
    // dessen Pfad einen Vater nennt, die fehlende Vererbungskante an. **Seit Fassung 28 gibt es
    // keine mehr** — er hätte beim nächsten Aufstieg 136 Kanten neu erfunden, die die Wanderung
    // gerade abgeräumt hat, und niemand hätte es gemerkt: `$wpdb` meldet ein gelungenes `INSERT`
    // genauso wortlos wie ein misslungenes.*
    //
    // ⚠️ *Was er sicherstellte, stellt jetzt {@see self::moveInheritanceOntoTheNode()} sicher, und
    // zwar zählend: **eine Wurzel, kein Zyklus, kein Kind ohne Vater.** Eine Installation, die von
    // Fassung 2 kommt, bringt ihren Baum weiterhin nur im Pfad mit — dafür steht der Wächter
    // `inheritance-column-check.php`, der genau das misst.*

    /**
     * `records.is_test` wird `records.kind` — Schema 15.
     *
     * ⚠️ **Nach `dbDelta`, weil die neue Spalte erst da sein muss, bevor etwas hineingeschrieben
     * wird** — dieselbe Reihenfolge, die {@see moveHideOntoTheRelation()} braucht. *`dbDelta` kennt kein
     * Umbenennen; es fügt hinzu, und dieser Schritt trägt den Inhalt hinüber.*
     *
     * ⚠️ **Und er ist zweimal ausführbar.** *Er läuft nur, solange die alte Spalte da ist, und die
     * fällt am Ende — also tut ein zweiter Lauf nichts. Das ist nötig, weil `install()` bei jeder
     * Aktivierung läuft.*
     */
    private static function moveTestFlagIntoKind(): void
    {
        global $wpdb;

        $records = self::table('node_records');

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
        // ⚠️ *Die Spalte heisst seit Fassung 30 `record_type` (TASK-015). **Eine alte Wanderung
        // schreibt in die Spalte von heute** — sie läuft nach `dbDelta`, das die neue schon angelegt
        // hat, und der alte Name stünde hier für eine Tabelle, die es so nicht mehr gibt.*
        $wpdb->query("UPDATE {$records} SET record_type = 'example' WHERE is_test = 1");

        $wpdb->query("ALTER TABLE {$records} DROP COLUMN is_test");
    }

    /**
     * Der eindeutige Schlüssel `(node_record_id, path, locale)` fällt — Schema 16,
     * [D-530](../../../docs/NewConcept/90-decision-log.md).
     *
     * ⚠️ **`dbDelta` kann einen Schlüssel nicht entfernen**, nur hinzufügen — also ausdrücklich, und
     * geprüft, ob es ihn überhaupt noch gibt. *Ohne diesen Schritt bliebe er auf jeder bestehenden
     * Installation stehen und verböte weiterhin, was D-530 gerade erlaubt.*
     *
     * ⚠️ *An seine Stelle tritt `of_field (node_record_id, relation_id, locale)` — **kein eindeutiger**, sondern
     * der Index für die Frage, die es jetzt gibt: «alle Werte dieses Feldes in diesem Datensatz».*
     */
    private static function dropTheOneValueKey(): void
    {
        global $wpdb;

        $tabelle = self::table('relation_records');

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
    private static function moveMultiplicityOntoTheRelation(): void
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
     * Blick in `nodes` und `node_records` entscheidet die Frage. Mit eigenen Id-Räumen wäre dieselbe
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
        $records = self::table('node_records');

        foreach (['relation_records', 'relation_records_history'] as $name) {
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
     * Zieltabelle setzt **TASK-010**, zusammen mit der Umbenennung von `from_node_id`/`to_node_id`. Sie hier
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
     * Fassung 28: die Vererbungskanten werden `nodes.parent_node_id`, `nodes.sort_order`, `nodes.hide`.
     *
     * ⚠️ **Erst messen, dann wandern, dann dieselben Zahlen noch einmal messen.** *Fünf Zahlen
     * beschreiben die Struktur, ohne einen einzigen Namen zu nennen: **Knoten, Vererbungskanten,
     * Wurzeln, Tiefenverteilung, Geschwisterreihenfolge**. Sie werden vorher genommen, nachher noch
     * einmal, und wenn eine abweicht, endet der Aufstieg mit einer Ausnahme — die Fassungsnummer
     * bleibt dann stehen, was die einzige Art ist, wie `$wpdb` überhaupt etwas melden kann.*
     *
     * ⚠️ **Vier Gründe, gar nicht erst anzufangen** (`PR-4` — geraten wird nicht): *ein Kind mit zwei
     * Vätern, eine Kante ohne lebendes Ziel oder ohne lebenden Vater, ein Zyklus, oder mehr als eine
     * Wurzel. **Jeder davon bricht ab, statt einen Vater zu erfinden.***
     *
     * ⚠️ **Umkehrbar:** *jede berührte Knotenzeile geht vorher in `nodes_history`, jede
     * Vererbungskante nach `relations_history` mit Löschkennzeichen. Der Rückweg ist der Schatten und
     * nichts sonst — es gibt keine zweite Spalte, die sich merkt, was hier geschah.*
     *
     * ⚠️ *`parked_by_group_id` bleibt bei den wandernden Kanten **leer**: sie sind nicht geparkt,
     * sondern abgelöst, und `parkedFieldRelationsOf()` liest genau diese Spalte. Eine Gruppe darauf
     * hätte 136 Geister in die Liste «entfernte Felder» gestellt.*
     *
     * @throws \RuntimeException Wenn die Struktur vorher nicht in Ordnung ist oder nachher nicht
     *                           mehr dieselbe.
     */
    private static function moveInheritanceOntoTheNode(): void
    {
        global $wpdb;

        $nodes     = self::table('nodes');
        $relations = self::table('relations');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $nodes)) !== $nodes) {
            return;
        }

        if (! self::hasColumn($nodes, 'parent_node_id') || ! self::hasColumn($relations, 'kind')) {
            return;
        }

        $kanten = $wpdb->get_results($wpdb->prepare(
            "SELECT id, from_node_id, to_node_id, sort_order, hide FROM {$relations} WHERE kind = %s",
            self::RETIRED_INHERITANCE_KIND
        ), ARRAY_A) ?: [];

        // Ein zweiter Lauf findet nichts zu tun. Nichts zu tun ist kein Fehler.
        if ($kanten === []) {
            return;
        }

        $vorher = self::countedShapeFromRelations($kanten, $nodes);

        // ⚠️ *Die Wanderung selbst: Zeile für Zeile, weil jede ihren eigenen Vater bekommt. **Das ist
        // nicht das N+1, das `CD-7` verbietet** — das ist ein einmaliger Lauf über ein Modell, das
        // entwurfsgemäss in den Hunderten bleibt ([D-308](../../../docs/NewConcept/90-decision-log.md)).*
        $gruppe = time();

        foreach ($kanten as $kante) {
            $kind = (int) $kante['to_node_id'];

            Shadow::keepOne('nodes', $kind);

            $wpdb->query($wpdb->prepare(
                "UPDATE {$nodes}
                 SET parent_node_id = %d, sort_order = %d, hide = %d, version = version + 1
                 WHERE id = %d",
                (int) $kante['from_node_id'],
                (int) $kante['sort_order'],
                (int) $kante['hide'],
                $kind
            ));
        }

        foreach ($kanten as $kante) {
            Shadow::keepOne('relations', (int) $kante['id'], true);

            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$relations} WHERE id = %d",
                (int) $kante['id']
            ));
        }

        $nachher = self::countedShapeFromColumns($nodes);

        if ($vorher !== $nachher) {
            throw new \RuntimeException(
                'TASK-018: die Struktur nach der Wanderung ist nicht die von vorher. '
                . 'Vorher ' . wp_json_encode($vorher) . ', nachher ' . wp_json_encode($nachher) . '. '
                . 'Der Schatten haelt beide Staende; die Fassungsnummer bleibt stehen.'
            );
        }

        // ⚠️ *Die Zahlen bleiben stehen, damit der Wächter sie nicht nachrechnen muss, sondern
        // **vergleichen** kann — und damit in der Aufgabe nicht behauptet steht, was niemand mehr
        // nachsehen kann.*
        update_option('taxmod_task018_shape', ['group' => $gruppe, 'shape' => $nachher], false);
    }

    /**
     * Die fünf Zahlen, aus den **Kanten** gelesen — und der Abbruch, wenn sie keinen Baum ergeben.
     *
     * @param  list<array<string,mixed>> $kanten
     * @return array{nodes: int, relations: int, roots: int, depths: array<int,int>, siblings: string}
     */
    private static function countedShapeFromRelations(array $kanten, string $nodes): array
    {
        global $wpdb;

        $alle  = array_map(intval(...), $wpdb->get_col("SELECT id FROM {$nodes}"));
        $vater = [];

        foreach ($kanten as $kante) {
            $kind = (int) $kante['to_node_id'];

            if (isset($vater[$kind])) {
                throw new \RuntimeException('TASK-018: Knoten ' . $kind . ' hat mehr als einen Vater.');
            }

            $vater[$kind] = [(int) $kante['from_node_id'], (int) $kante['sort_order']];
        }

        return self::countedShape($alle, $vater);
    }

    /**
     * Dieselben fünf Zahlen, aus den **Spalten** gelesen.
     *
     * @return array{nodes: int, relations: int, roots: int, depths: array<int,int>, siblings: string}
     */
    private static function countedShapeFromColumns(string $nodes): array
    {
        global $wpdb;

        $alle  = [];
        $vater = [];

        foreach ($wpdb->get_results("SELECT id, parent_node_id, sort_order FROM {$nodes}", ARRAY_A) ?: [] as $zeile) {
            $id     = (int) $zeile['id'];
            $alle[] = $id;

            if ($zeile['parent_node_id'] !== null) {
                $vater[$id] = [(int) $zeile['parent_node_id'], (int) $zeile['sort_order']];
            }
        }

        return self::countedShape($alle, $vater);
    }

    /**
     * Die Struktur in fünf Zahlen — **und kein Name darunter**.
     *
     * ⚠️ *Namen bewegen sich aus anderen Gründen; sie hätten die Prüfung weich gemacht. Was gleich
     * bleiben muss, ist: wie viele Knoten, wie viele Einordnungen, wie viele Wurzeln, wie tief, und
     * **welches Kind auf welcher Stelle unter welchem Vater** — die letzte Zahl ist eine Prüfsumme
     * über genau diese Tripel.*
     *
     * @param  list<int>                 $alle
     * @param  array<int, array{int,int}> $vater Kind-Id => [Vater-Id, Stelle].
     * @return array{nodes: int, relations: int, roots: int, depths: array<int,int>, siblings: string}
     */
    private static function countedShape(array $alle, array $vater): array
    {
        $lebt = array_flip($alle);

        foreach ($vater as $kind => [$wer, $stelle]) {
            if (! isset($lebt[$kind]) || ! isset($lebt[$wer])) {
                throw new \RuntimeException(
                    'TASK-018: die Einordnung ' . $wer . ' -> ' . $kind . ' nennt einen Knoten, den es nicht gibt.'
                );
            }
        }

        $tiefen  = [];
        $wurzeln = 0;

        foreach ($alle as $id) {
            $tiefe   = 0;
            $laeufer = $id;
            $gesehen = [];

            while (isset($vater[$laeufer])) {
                if (isset($gesehen[$laeufer])) {
                    throw new \RuntimeException('TASK-018: Knoten ' . $id . ' haengt in einem Zyklus.');
                }

                $gesehen[$laeufer] = true;
                $laeufer           = $vater[$laeufer][0];
                $tiefe++;
            }

            if ($tiefe === 0) {
                $wurzeln++;
            }

            $tiefen[$tiefe] = ($tiefen[$tiefe] ?? 0) + 1;
        }

        ksort($tiefen);

        $tripel = [];

        foreach ($vater as $kind => [$wer, $stelle]) {
            $tripel[] = $wer . ':' . $stelle . ':' . $kind;
        }

        sort($tripel);

        return [
            'nodes'     => count($alle),
            'relations' => count($vater),
            'roots'     => $wurzeln,
            'depths'   => $tiefen,
            'siblings' => md5(implode('|', $tripel)),
        ];
    }

    /** Ob eine Tabelle diese Spalte hat — die Frage, die vor jedem Wanderungsschritt steht. */
    private static function hasColumn(string $table, string $column): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table,
            $column
        )) === 1;
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
            // ⚠️ **`name` steht hier seit Fassung 34 nicht mehr, `label_id` steht an seiner Stelle**
            // (TASK-019, D-580, D-646). *Der Verweis zeigt vom Knoten auf die Beschriftung und nicht
            // umgekehrt — «so bekommt jede Tabelle ihre eigene `label_id`, und jeder Fremdschlüssel
            // ist echt und einspaltig». **Am Knoten ist er Pflicht**, an der Kante freiwillig.*
            // ⚠️ **`path` steht hier seit Fassung 35 nicht mehr, und der Schlüssel darauf auch nicht**
            // (TASK-001). *Der Weg zur Wurzel war zweimal gespeichert; seit TASK-018 ist
            // `parent_node_id` der Baum ([D-581](../../../docs/NewConcept/90-decision-log.md)), und
            // der Weg wird beim Lesen daraus gerechnet ({@see WpdbNodeRepository::ancestry()}) —
            // «derived and rebuildable», wie [D-082](../../../docs/NewConcept/90-decision-log.md) ihn
            // von Anfang an genannt hat.*
            "CREATE TABLE {$t('nodes')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                version int(10) unsigned NOT NULL DEFAULT 1,
                label_id bigint(20) unsigned NOT NULL DEFAULT 0,
                implemented_by varchar(191) DEFAULT NULL,
                parent_node_id bigint(20) unsigned DEFAULT NULL,
                sort_order int(10) unsigned NOT NULL DEFAULT 0,
                hide tinyint(1) unsigned NOT NULL DEFAULT 0,
                klasse varchar(191) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY label_id (label_id),
                KEY implemented_by (implemented_by),
                UNIQUE KEY one_place (parent_node_id,sort_order)
            ) {$charset};",

            // ⚠️ **`parked_by_group_id` steht hier nicht mehr** ([D-619](../../../docs/NewConcept/90-decision-log.md),
            // TASK-013). *Sie war die eine Stelle, an der eine Kante geparkt werden konnte (D-371);
            // seit D-575 heisst Parken «in die Schattentabelle wandern, mit der Änderungsgruppe im
            // Gepäck», und **eine geparkte Kante hat gar keine lebende Zeile mehr**. Die Spalte steht
            // weiter in `relations_history` — dort ist sie die Aussage, hier war sie ein Merkmal an
            // einer Zeile, die es nicht geben dürfte.*
            "CREATE TABLE {$t('relations')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                version int(10) unsigned NOT NULL DEFAULT 1,
                from_node_id bigint(20) unsigned NOT NULL,
                to_node_id bigint(20) unsigned NOT NULL,
                kind varchar(20) NOT NULL,
                label_id bigint(20) unsigned DEFAULT NULL,
                sort_order int(10) unsigned NOT NULL DEFAULT 0,
                multiplicity varchar(10) NOT NULL DEFAULT '1..1',
                hide tinyint(1) unsigned NOT NULL DEFAULT 0,
                read_only tinyint(1) unsigned NOT NULL DEFAULT 0,
                is_unique tinyint(1) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY one_place (from_node_id,kind,sort_order),
                KEY to_node_id (to_node_id),
                KEY label_id (label_id)
            ) {$charset};",

            // ⚠️ *Hier stand `settings` samt der Begruendung ihrer `path`-Spalte. **Die Tabelle ist mit
            // D-579 gestrichen** — eine Einstellung ist eine Kante (D-529), und ihr Wert steht in
            // `relation_records`. Die Adressfrage, die `settings.path` beantwortete, beantwortet dort der
            // Pfad.*

            // ⚠️ **`owner_kind` nennt den Raum, aus dem `owner_id` stammt** (Fassung 31, `INF-035`,
            // D-164, D-597). *Ein Label hängt an einem Knoten **oder** an einer Kante (D-410). Solange
            // ein neuer Knoten immer auch eine Vererbungskante anlegte, liefen die beiden Zähler im
            // Gleichschritt; seit D-581 laufen sie verschieden schnell und treffen sich. **Gemessen am
            // 2026-09-05**: eine frische Kante bekam die fünf Beschriftungen eines gleichnummerigen
            // Knotens zurück.*
            //
            // ⚠️ **`version` ist die Zeilennummer** (D-634). *`labels` war die **einzige** Tabelle ohne
            // sie, und der Melder musste dem Journal `null` hinschreiben.*
            // ⚠️ **Seit Fassung 34 trägt `labels` nur noch das Sprachunabhängige** (TASK-019, D-580,
            // D-645, D-646). *`name` und `symbol` sind sprachabhängig geworden und stehen in
            // `label_texts`; `icon` bleibt hier, weil es «not language-dependent» ist (I5) — «bei drei
            // Sprachen gäbe es dasselbe Bild dreimal».*
            //
            // ⚠️ **`owner_kind` bleibt, `owner_id` fällt** (D-641). *Der Verweis zeigt jetzt von
            // `nodes.label_id` und `relations.label_id` hierher; die Spalte, die den **Raum** nennt,
            // bleibt an der Zeile, damit eine Beschriftung selbst sagen kann, woran sie hängt.*
            "CREATE TABLE {$t('labels')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                version int(10) unsigned NOT NULL DEFAULT 1,
                owner_kind varchar(20) NOT NULL DEFAULT '',
                icon varchar(191) DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY owner_kind (owner_kind)
            ) {$charset};",

            // ⚠️ **Die vier Rollen aus D-598 plus `symbol` (D-646) plus `name` (D-646) — Spalten und
            // nicht Zeilen.** *Der Eigentümer: «würde das mal auf den Parkplatz für mögliche spätere
            // Entwicklungen schieben und bei vier Spalten bleiben». **Woran man merkt, dass der
            // Parkplatz zu verlassen ist, steht dabei: wenn zum ersten Mal eine Rolle fehlt.**
            //
            // ⚠️ **Warum die Spalten `text_…` heissen und nicht `form`, `table`, `select`:** *`table`
            // und `select` sind in MySQL reservierte Wörter, und `dbDelta` liest den ersten Bezeichner
            // einer Zeile roh und schreibt ihn unquotiert in ein `ALTER TABLE` — genau die Falle, die
            // schon `key` zu `setting_key` und `before` zu `before_state` gemacht hat. **Und `$wpdb`
            // sagt über einen Syntaxfehler nichts.** Ein Präfix für alle sechs statt einer Ausnahme
            // für zwei (`CD-9`).*
            //
            // ⚠️ *`number` ist die Numerusklasse (D-216) und steht im Schlüssel, damit die
            // Rückfallkette «Numerus vor Rolle» (D-153) weiter etwas zu finden hat.*
            "CREATE TABLE {$t('label_texts')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                label_id bigint(20) unsigned NOT NULL,
                locale varchar(20) NOT NULL,
                number varchar(20) NOT NULL DEFAULT 'one',
                text_name mediumtext DEFAULT NULL,
                text_form mediumtext DEFAULT NULL,
                text_table mediumtext DEFAULT NULL,
                text_select mediumtext DEFAULT NULL,
                text_help mediumtext DEFAULT NULL,
                text_symbol mediumtext DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY one_row (label_id,locale,number),
                KEY label_id (label_id)
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
            "CREATE TABLE {$t('node_records')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                version int(10) unsigned NOT NULL DEFAULT 1,
                node_id bigint(20) unsigned NOT NULL,
                node_version int(10) unsigned NOT NULL,
                created_at datetime NOT NULL,
                record_type varchar(20) NOT NULL DEFAULT 'user',
                relation_id bigint(20) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY node_id (node_id),
                KEY relation_id (relation_id),
                KEY record_type (record_type)
            ) {$charset};",

            // ⚠️ **Der Pfad ist mit Fassung 39 gefallen** ([D-667](../../../docs/NewConcept/90-decision-log.md),
            // TASK-002). *Wo er zwei Nummern trug, sagt sie der **Satz** — `node_records.relation_id`
            // nennt die Verwendungsstelle, `relation_id` hier die Einstellung. **Er darf hier nicht
            // wieder auftauchen**: `dbDelta` legt eine fehlende Spalte klaglos wieder an, und
            // `path-check.php` sieht genau darauf. Sein Wort zum Zwischenschritt, den er verworfen
            // hat: «also verklausulierst du path als Text».*
            "CREATE TABLE {$t('relation_records')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                node_record_id bigint(20) unsigned NOT NULL,
                relation_id bigint(20) unsigned NOT NULL,
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
                KEY of_field (node_record_id,relation_id,locale),
                KEY relation_id (relation_id),
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
                label_id bigint(20) unsigned NOT NULL DEFAULT 0,
                name varchar(191) NOT NULL,
                path varchar(255) NOT NULL,
                implemented_by varchar(191) DEFAULT NULL,
                parent_node_id bigint(20) unsigned DEFAULT NULL,
                sort_order int(10) unsigned NOT NULL DEFAULT 0,
                hide tinyint(1) unsigned NOT NULL DEFAULT 0,
                klasse varchar(191) NOT NULL DEFAULT '',
                deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
                archived_at datetime NOT NULL,
                PRIMARY KEY  (id,version),
                KEY archived_at (archived_at)
            ) {$charset};",

            // ⚠️ **Die zwei Tabellen des Einstellungsmodells** (Fassung 47, [D-712](../../../docs/NewConcept/90-decision-log.md),
            // [`einstellungen-anforderungen.md`](../../../docs/einstellungen-anforderungen.md) §4).
            // *Ein Objekt ist eine Klasse mit einer Nummer — wem es gehört, sagt die Zeile, die es
            // als Wert nennt (4.3.2).*
            "CREATE TABLE {$t('settings_object')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                version int(10) unsigned NOT NULL DEFAULT 1,
                klasse varchar(191) NOT NULL,
                PRIMARY KEY  (id)
            ) {$charset};",

            // ⚠️ *Genau ein Träger (`node_id` oder `settings_object_id`), die Kante nur als Zusatz,
            // genau eine Wertspalte — das hält {@see \Taxmod\Core\Model\Setting\SettingsValue} und
            // misst `settings-tables-check`. **`bool` liegt in `wert_int`** ([D-315](../../../docs/NewConcept/90-decision-log.md)).*
            "CREATE TABLE {$t('settings_value')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                version int(10) unsigned NOT NULL DEFAULT 1,
                node_id bigint(20) unsigned DEFAULT NULL,
                settings_object_id bigint(20) unsigned DEFAULT NULL,
                relation_id bigint(20) unsigned DEFAULT NULL,
                klasse varchar(191) NOT NULL,
                attribut varchar(191) NOT NULL,
                position int(10) unsigned NOT NULL DEFAULT 0,
                aktiv tinyint(1) unsigned NOT NULL DEFAULT 1,
                wert_int bigint(20) DEFAULT NULL,
                wert_decimal decimal(65,30) DEFAULT NULL,
                wert_text text DEFAULT NULL,
                wert_knoten_id bigint(20) unsigned DEFAULT NULL,
                wert_settings_object_id bigint(20) unsigned DEFAULT NULL,
                wert_kante_id bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY node_id (node_id),
                KEY settings_object_id (settings_object_id),
                KEY relation_id (relation_id),
                KEY wert_knoten_id (wert_knoten_id),
                KEY wert_settings_object_id (wert_settings_object_id),
                KEY wert_kante_id (wert_kante_id)
            ) {$charset};",

            "CREATE TABLE {$t('settings_object_history')} (
                id bigint(20) unsigned NOT NULL,
                version int(10) unsigned NOT NULL,
                klasse varchar(191) NOT NULL,
                deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
                archived_at datetime NOT NULL,
                PRIMARY KEY  (id,version),
                KEY archived_at (archived_at)
            ) {$charset};",

            "CREATE TABLE {$t('settings_value_history')} (
                id bigint(20) unsigned NOT NULL,
                version int(10) unsigned NOT NULL,
                node_id bigint(20) unsigned DEFAULT NULL,
                settings_object_id bigint(20) unsigned DEFAULT NULL,
                relation_id bigint(20) unsigned DEFAULT NULL,
                klasse varchar(191) NOT NULL,
                attribut varchar(191) NOT NULL,
                position int(10) unsigned NOT NULL DEFAULT 0,
                aktiv tinyint(1) unsigned NOT NULL DEFAULT 1,
                wert_int bigint(20) DEFAULT NULL,
                wert_decimal decimal(65,30) DEFAULT NULL,
                wert_text text DEFAULT NULL,
                wert_knoten_id bigint(20) unsigned DEFAULT NULL,
                wert_settings_object_id bigint(20) unsigned DEFAULT NULL,
                wert_kante_id bigint(20) unsigned DEFAULT NULL,
                deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
                archived_at datetime NOT NULL,
                PRIMARY KEY  (id,version),
                KEY archived_at (archived_at)
            ) {$charset};",

            "CREATE TABLE {$t('relations_history')} (
                id bigint(20) unsigned NOT NULL,
                version int(10) unsigned NOT NULL,
                from_node_id bigint(20) unsigned NOT NULL,
                to_node_id bigint(20) unsigned NOT NULL,
                kind varchar(20) NOT NULL,
                label_id bigint(20) unsigned DEFAULT NULL,
                name varchar(191) NOT NULL DEFAULT '',
                sort_order int(10) unsigned NOT NULL DEFAULT 0,
                multiplicity varchar(10) NOT NULL DEFAULT '1..1',
                parked_by_group_id bigint(20) unsigned DEFAULT NULL,
                hide tinyint(1) unsigned NOT NULL DEFAULT 0,
                read_only tinyint(1) unsigned NOT NULL DEFAULT 0,
                is_unique tinyint(1) unsigned NOT NULL DEFAULT 0,
                deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
                archived_at datetime NOT NULL,
                PRIMARY KEY  (id,version),
                KEY archived_at (archived_at)
            ) {$charset};",

            "CREATE TABLE {$t('node_records_history')} (
                id bigint(20) unsigned NOT NULL,
                version int(10) unsigned NOT NULL,
                node_id bigint(20) unsigned NOT NULL,
                node_version int(10) unsigned NOT NULL,
                created_at datetime NOT NULL,
                record_type varchar(20) NOT NULL DEFAULT 'user',
                relation_id bigint(20) unsigned NOT NULL DEFAULT 0,
                deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
                archived_at datetime NOT NULL,
                PRIMARY KEY  (id,version),
                KEY archived_at (archived_at)
            ) {$charset};",

            "CREATE TABLE {$t('relation_records_history')} (
                id bigint(20) unsigned NOT NULL,
                node_record_id bigint(20) unsigned NOT NULL,
                relation_id bigint(20) unsigned NOT NULL,
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
                parked_by_group_id bigint(20) unsigned DEFAULT NULL,
                deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
                archived_at datetime NOT NULL,
                PRIMARY KEY  (id,version),
                KEY archived_at (archived_at),
                KEY of_record (node_record_id)
            ) {$charset};",
        ];
    }
}
