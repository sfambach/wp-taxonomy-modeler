# Paket · Modelltabellen

**Stand 2026-09-01.** Nur der aktuell gültige Soll-Zustand. **Jede Begründung steht in
[`history.md`](history.md)**, Offenes in [`tasks.md`](tasks.md), Fehler in [`bugs.md`](bugs.md).

⚠️ **Soll, nicht Ist.** *Wo beides auseinandergeht, steht «gemessen» dabei.*

---

## 0 · Stand

**`nodes` und `relations` sind fertig** ([D-578](../../NewConcept/90-decision-log.md)) — sie werden
umgesetzt, siehe [`tasks.md`](tasks.md). *Kein Lock: veraltet später etwas, wird es mit Grund
ersetzt ([D-565](../../NewConcept/90-decision-log.md)).*

**Die Speicherform gilt für alle Kantenarten.** *Vererbung, Komposition, Aggregation, Einstellung —
**es gibt keine zweite Ablage für eine bestimmte Art.** Ein Wert an einer Kante ist eine Zeile in
`relation_records`; was die Kante ist, sagt `relation_type`, nicht die Ablage.*

**Noch offen:** `labels`, `changelog` (im Paket [`aenderungstabellen/`](../aenderungstabellen/package.md)),
**`settings` ist gestrichen** ([D-579](../../NewConcept/90-decision-log.md)) — die drei letzten Werte
werden neu eingegeben, sobald `label_role` seinen Ort hat.

---

## 1 · Zweck

Die vier Tabellen, die das Modell tragen — **Knoten, Kante, Knoten-Datensatz, Kanten-Datensatz** —
und ihr Schema.

## 2 · Verantwortungsgrenze

**Hinein:** die vier Tabellen und `labels` · ihr Schema über `dbDelta()` · alles, was `$wpdb`
berührt.

**Nicht hinein:** fachliche Modelllogik · Darstellung · die Schatten- und Journaltabellen, die in
[`aenderungstabellen/`](../aenderungstabellen/package.md) liegen.

**Dieses Paket weiss, wie Zeilen liegen — nicht, was sie bedeuten.**

---

## 3 · `nodes` — der Knoten

| Spalte | | |
|---|---|---|
| `id` | eigener Id-Raum je Tabelle | §6 |
| `version` | für die Schattentabelle | |
| `parent_node_id` | **unter wem der Knoten hängt** — war eine Vererbungskante | §3.3 |
| `sort_order` | **an welcher Stelle unter dem Elternknoten** | §3.3 |
| `field_type` | **`model`** oder **`setting`** | war `kind` |
| `label_id` | → `labels.id`, **verpflichtend** — hier steht der Name | §3.4 |
| `settings_record_id` | → `node_records.id`, optional — eigene Einstellungen | §3.6 |
| *(neu)* | die **PHP-Klasse**, die diesen Knoten umsetzt | §3.2 |

**`name` ist gestrichen — er steht in `labels.name`** ([D-580](../../NewConcept/90-decision-log.md)).

**`path` ist gestrichen.** Er wiederholte, was `relations` besitzt, und war bei mehreren Elternkanten
verlustbehaftet. → [`history.md`](history.md)

### 3.1 · `field_type` — Modellfeld oder Einstellungsfeld

Zeigt ein Feld auf diesen Knoten: sind seine Werte **Modelldaten** oder **Benutzerdaten**?
Werte `model` · `setting`.

**Die Angabe wäre ableitbar** — aus dem Ast und aus der Relation, die darauf zeigt — **und wird
trotzdem geführt: sie soll dastehen statt errechnet zu werden.** Das ist eine bewusste Ausnahme und
keine übersehene Doppelung.

### 3.2 · Der Knoten nennt die PHP-Klasse, die ihn umsetzt

**Neue Spalte, trägt den Klassennamen. Keine Factory.**

Sie löst **56 WordPress-Optionen** ab, die heute die Bindung «welcher Knoten ist der Int-Typ» ausserhalb
des Modells halten — gegen [`AR-1`](../../../CLAUDE.md).

**Dazu gehört ein Wächter:** eine Zeile, die eine Klasse nennt, die es nicht gibt, wird rot.
→ [`tasks.md`](tasks.md) TASK-008, TASK-009

---

### 3.3 · Vererbung ist eine Spalte, keine Kante

**`parent_node_id` und `sort_order` stehen am Kind** ([D-581](../../NewConcept/90-decision-log.md)).
`parent_node_id` sagt, unter wem der Knoten hängt; `sort_order`, an welcher Stelle unter seinen
Geschwistern. **Schlüssel und Index: `(parent_node_id, sort_order)`.**

**`relations` verliert damit 127 von 166 Zeilen.** Die Frage «ist es Vererbung?», die heute 15 von
19 Verzweigungen im Code stellen, verschwindet.

⚠️ *Eine Liste von Ids am Elternknoten wäre ein zusammengesetzter Wert in einer Spalte — dasselbe
Muster wie der Pfad. Deshalb am Kind.*

### 3.4 · Beschriftungen — `labels` und `label_texts`

```text
nodes       label_id  → labels.id     verpflichtend
relations   label_id  → labels.id     optional

labels        id · name · symbol · icon                       sprachunabhängig
label_texts   label_id · locale · form · table · select · help sprachabhängig
```

**Der Verweis zeigt vom Gegenstand auf das Label, nicht umgekehrt** — so bekommt jede Tabelle ihre
eigene `label_id`, statt dass `labels` je neuer Art eine Spalte wächst.

**`name` steht in `labels`, nicht mehr an Knoten und Kante.** Damit liegt die **ganze Rückfallkette
in einer Tabelle**: angefragte Sprache → Standardsprache → `labels.name`.

**Keine neutrale Zeile.** Die Standardsprache ist die von WordPress (`get_locale()`, hier `en_US`).

⚠️ **`symbol` und `icon` sind sprachunabhängig** — *gemessen: 38 von 38 `symbol`-Labels tragen keine
Sprache, und [`I5`](../../NewConcept/40-i18n.md) sagt es fürs Icon ausdrücklich.*

⚠️ **Offen: sind die vier Rollen Spalten oder Zeilen mit `role_id`?**

### 3.5 · `nodes` hat danach keine lesbare Spalte mehr

*Wer die Tabelle roh ansieht, sieht nur Ids. Kein Gegenargument, aber eine spürbare Änderung beim
Suchen von Hand und beim Prüfen.*

---

### 3.6 · Eigene Einstellungen an Knoten **und** Kante

**`settings_record_id` steht an beiden** ([D-582](../../NewConcept/90-decision-log.md)) und zeigt auf
den eigenen Einstellungsdatensatz.

```text
node_record  #40   node_id = DisplayOption
   #40 · render · → Knoten «slider»

relations    «vorname»  settings_record_id = #40
```

**Es gibt kein Überschreiben-Konstrukt, nur eine Reihenfolge:**

```text
Renderer für «Kunde.vorname»:
   1. Einstellungsdatensatz der Kante        → gilt
   2. sonst der des Zielknotens «Text»       → gilt
   3. sonst Rückfall
```

**Ein zusätzlicher Renderer ist kein neuer Mechanismus** — die Kante trägt mehrere Werte für
dasselbe Feld, `sort_order` unterscheidet sie.

⚠️ *Damit fällt **nur** die Einstellungskante, die den Weg zum Behälter beschreibt (drei von elf).
Felder **im** Behälter und Einstellungen direkt am Knoten bleiben Kanten.*

---

## 4 · `relations` — die Kante

| Spalte | | gemessen |
|---|---|---|
| `id` | eigener Id-Raum | |
| `version` | für die Schattentabelle | |
| `from_node_id` → `to_node_id` | **Constraint auf `nodes.id`** | je 166 |
| `relation_type` | die Kantenart — **ohne Vererbung** | `composition` 23 · `setting` 11 · `aggregation` 5 |
| `label_id` | → `labels.id`, **optional** — hier steht der Name | §3.4 |
| `settings_record_id` | → `node_records.id`, optional — **überschreibt den Zielknoten** | §3.6 |
| `sort_order` | Reihenfolge unter dem Elternknoten, **erste ist `0`** | war `position` |
| `multiplicity` | | `1..1` 156 · `0..1` 5 · `1..*` 3 · `0..*` 2 |
| `hide` | **offen** — verliert mit der Vererbung alle Benutzer | §4.4 |

**`from_node_id`/`to_node_id` sind keine Umbenennung, sondern eine Verschärfung:** heute zeigen alle
sieben Fremdschlüssel auf `identities.id`, was strukturell **eine Kante von einem Datensatz aus**
erlaubt. → [`history.md`](history.md)

**Die vier Kantenarten sind keine eigenen Klassen und brauchen es nicht** — 15 von 19 Verzweigungen im
Code fragen nur «ist es Vererbung?».

### 4.1 · Was an `relation_type` hängt

| | |
|---|---|
| `name` | **leer genau dann, wenn Vererbung** — 127 von 127 ohne, 39 von 39 mit |
| `multiplicity` | **sagt bei Vererbung nichts** — alle 127 auf `1..1` |
| `hide` | **nur auf Vererbungskanten** — 6 Stück |

### 4.2 · `sort_order` — die Reihenfolge, je Knoten **und** je Kantenart

**`position` heisst künftig `sort_order`. Die erste Stelle ist `0`.** Gezählt wird **je `from_node_id`
und je `relation_type`** — der Knoten in `from` ist der besitzende: bei einem Feld der Knoten, der es hat,
bei Vererbung der Elternknoten.

**Der eindeutige Schlüssel geht deshalb über drei Spalten: `(from_node_id, relation_type, sort_order)`.**
Er dient zugleich als **Suchindex** — gefiltert wird nach `from_node_id`, oft zusätzlich nach `relation_type`,
und `sort_order` ist die Sortierspalte am Ende. **Damit wird der heutige Einzelindex auf `from_id`
überflüssig:** ein zusammengesetzter Index mit `from_node_id` an erster Stelle deckt ihn mit ab.

### 4.3 · Kein `parked_by_group_id` mehr — Parken heisst wandern

**Die Spalte ist ersatzlos gestrichen** ([D-575](../../NewConcept/90-decision-log.md)).

> **Parken heisst: in die Schattentabelle wandern, mit der Änderungsgruppe im Gepäck. Die lebende
> Tabelle verliert die Spalte ersatzlos — ein Datensatz ist da oder er ist nicht da.**

Löschen bleibt zweistufig — **parken, dann endgültig entfernen**
([D-123](../../NewConcept/90-decision-log.md)). Was sich ändert, ist **wo** eine geparkte Zeile liegt.

**Die Gruppe zieht mit in die Schattentabelle** → [`aenderungstabellen/`](../aenderungstabellen/package.md).
*Damit bringt das Wiederherstellen weiter das ganze Löschereignis zurück
([D-127](../../NewConcept/90-decision-log.md)): es sind die Schattenzeilen mit derselben Gruppen-Id.*

⚠️ *Der Preis der alten Lösung, gemessen: **23 Stellen in 12 Dateien** mussten an «geparkt» denken.*

---

## 5 · `node_records` — der Knoten-Datensatz

| Spalte | | |
|---|---|---|
| `id` | Schlüssel, eigener Id-Raum | |
| `version` | für die Schattentabelle — **steht künftig direkt unter `id`** | |
| `node_id` | welcher Knoten — indiziert | |
| `node_version` | gegen **welche Version des Knotens** der Datensatz entstand | |
| `record_type` | `default` oder `user` — indiziert | war `kind` |
| ~~`created_at`~~ | **fällt — gehört ins Änderungsbuch** | siehe unten |

**Die Paarung `node_id` + `node_version` bleibt: ein Datensatz gehört immer zu einer bestimmten
Version des Knotens.**

### 5.1 · Offen: was bei einem Versionskonflikt geschieht

**STATUS: `OPEN` — bewusst zurückgestellt**

*Der Eigentümer: «wenn wir Konflikte haben — Knoten hat sich geändert, Record zeigt auf alte
Version — Konflikt muss manuell aufgelöst werden, somit kann dieser Record nicht mehr dargestellt
werden. Gibt es keinen Konflikt, müsste die `node_version` upgedatet werden. **Das können wir
erstmal so lassen.**»*

### 5.2 · `created_at` fällt — aber nicht sofort

*Der Eigentümer: «das `created_at` ist eigentlich was fürs Log, brauchen wir glaube ich nicht mehr
im `node_record`.»* **Richtig — nur führt das Log es heute nicht.**

**Reihenfolge nach `PR-12`: erst führt das Änderungsbuch Datensätze, dann fällt die Spalte.**
→ [`tasks.md`](tasks.md) TASK-015

---

## 5a · `relation_records` — der Kanten-Datensatz

| Spalte | | |
|---|---|---|
| `id` | Schlüssel, eigener Id-Raum | |
| `version` | für die Schattentabelle | |
| `node_record_id` | zu **welcher Ausprägung** der Wert gehört | war `record_id` |
| `relation_id` | **welches Feld** | war `edge_id` |
| `sort_order` | Reihenfolge **innerhalb eines Feldes**, erste Stelle `0` | war `position` |
| `value_int` · `value_decimal` · `value_text` · `value_date` | der Wert | |
| `value_node_record_id` | **oder** ein Verweis auf eine eingebettete Ausprägung | Verschachtelung |
| `value_node_id` | **oder** ein Verweis auf einen Knoten | Knotenverweis |
| ~~`path`~~ · ~~`locale`~~ | gestrichen | |

**Schlüssel: `(node_record_id, relation_id, sort_order)`** — dieselbe Form wie bei der Kante, eine
Etage tiefer.

### 5a.1 · Wie eine Verschachtelung aussieht

Modell: `Kunde` hat `vorname`, `name` und `Adresse` (`0..*`); `Adresse` hat `Strasse` und `Nr`.

```text
node_records
  #1  node_id = Kunde        #3  node_id = Adresse
  #2  node_id = Kunde        #5  node_id = Adresse
                             #7  node_id = Adresse

relation_records
  node_record_id   relation   sort_order   Wert / Verweis
  #1               vorname    0            «Anna»
  #1               name       0            «Müller»
  #1               Adresse    0            → #3
  #1               Adresse    1            → #5
  #3               Strasse    0            «Hauptstr.»
  #3               Nr         0            «12»
  #5               Strasse    0            «Bahnhofstr.»
  #5               Nr         0            «5»
  #2               vorname    0            «Peter»
  #2               name       0            «Schmidt»
  #2               Adresse    0            → #7
```

**Jede Verbindung ist genau eine Zeile, und jede steht genau einmal da.** Kein Pfad, keine `node_id`
auf der Wertzeile, kein Elternzeiger.

### 5a.2 · `sort_order` gibt es auf zwei Ebenen

An der **Kante** ordnet es die Felder **im Modell** — `vorname` vor `name` vor `Adresse`.
Am **Wert** ordnet es die Werte **innerhalb eines Feldes** — erste Adresse, zweite Adresse.
**Gleiches Wort, gleiche Idee, verschiedene Ebene.**

### 5a.3 · Was die Struktur nicht verhindert

**Die Verbindung steht nur in eine Richtung** — vom Eltern hinunter. Wer wissen will, zu wem
Adresse `#3` gehört, sucht über die Verweisspalte; **ein Index darauf macht das billig.** Ein
Elternzeiger am Kind wäre eine zweite Heimat und kann auseinanderlaufen.

---

## 6 · Jede Tabelle hat ihren eigenen Id-Raum · `identities` ist gestrichen

**Es gibt keinen geteilten Nummernraum mehr.** `identities` stirbt ersatzlos — sie hatte genau eine
Spalte.

**Die Folge, und sie ist zu bauen:** mit eigenen Räumen gibt es Knoten 5, Kante 5 **und** Datensatz 5.

> **Ein Fremdschlüssel nennt seine Zieltabelle im Namen.** Kann eine Spalte auf mehr als eine Tabelle
> zeigen, nennt eine zweite Spalte den Raum — so wie `changelog.owner_kind` es seit jeher tut.

| heute | zeigt auf | |
|---|---|---|
| `records.node_id` · `record_values.record_id` · `record_values.edge_id` | Knoten · Datensätze · Kanten | Name sagt es |
| `changelog.owner_id` | vier Tabellen | `owner_kind` sagt es |
| `labels.owner_id` · `role_id` | Knoten | Name sagt es **nicht** |
| **`settings.owner_id`** | **Kanten** | **derselbe Name, anderes Ziel als in `labels`** |
| **`record_values.value_ref`** | **Knoten 49 · Datensätze 88** | **mehrdeutig** |

→ [`tasks.md`](tasks.md) TASK-004, TASK-005, TASK-010

---

## 7 · Schnittstellen

**Dieses Paket erklärt nichts nach aussen — es *erfüllt*.** Der Kern erklärt die
Repository-Schnittstellen, dieses Paket setzt sie mit `$wpdb` um
([`CD-1`](../../../CLAUDE.md), [D-009](../../NewConcept/90-decision-log.md)).

```text
Kern erklärt eine Schnittstelle
        ↓
Modelltabellen erfüllen sie
```

**Von aussen herein:** Aktivierung, Prüfläufe, Aufräumseite.

## 8 · Abhängigkeiten

**Benutzt:** `$wpdb`, `dbDelta()` — sonst nichts.
**Wird benutzt von:** Kern (über die Schnittstellen), Plattform (Aktivierung).

## 9 · Regeln dieses Pakets

| | |
|---|---|
| [`CD-6`](../../../CLAUDE.md) | `taxmod_`-Präfix, `dbDelta()`, Schemaversion. **Nur vorbereitete Abfragen.** |
| [`AR-1`](../../../CLAUDE.md) | Das Modell liegt in eigenen Tabellen. |
| | **Eine kaputte `$wpdb`-Abfrage liefert ein leeres Ergebnis, keinen Fehler** — [`bekannte-fallen.md`](../../bekannte-fallen.md). |
