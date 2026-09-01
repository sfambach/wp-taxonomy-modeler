# Paket · Modelltabellen

**Stand 2026-09-01.** Nur der aktuell gültige Soll-Zustand. **Jede Begründung steht in
[`history.md`](history.md)**, Offenes in [`tasks.md`](tasks.md), Fehler in [`bugs.md`](bugs.md).

⚠️ **Soll, nicht Ist.** *Wo beides auseinandergeht, steht «gemessen» dabei.*

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
| `name` | indiziert | |
| `field_type` | **`model`** oder **`setting`** — Modellfeld oder Einstellungsfeld | war `kind` |
| *(neu)* | die **PHP-Klasse**, die diesen Knoten umsetzt | §3.2 |

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

## 4 · `relations` — die Kante

| Spalte | | gemessen |
|---|---|---|
| `id` | eigener Id-Raum | |
| `version` | für die Schattentabelle | |
| `from_node_id` → `to_node_id` | **Constraint auf `nodes.id`** | je 166 |
| `relation_type` | die Kantenart | `inheritance` 127 · `composition` 23 · `setting` 11 · `aggregation` 5 |
| `name` | | 39 von 166 |
| `sort_order` | Reihenfolge unter dem Elternknoten, **erste ist `0`** | war `position` |
| `multiplicity` | | `1..1` 156 · `0..1` 5 · `1..*` 3 · `0..*` 2 |
| `hide` | | 6 von 166 |

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

⚠️ **Zwei Listen, nicht eine.** *Die Kinder eines Knotens im Baum werden 0, 1, 2 … durchgezählt, und
seine Felder ebenfalls 0, 1, 2 … **Beide hängen am selben `from_node_id`.** Sechs Knoten haben
heute beides — `Root`, `Kontact`, `Passiv`, `Prefixes`, `Without prefix`, `render with label` — und
genau dort überschneiden sich die Zahlen.*

**Der eindeutige Schlüssel geht deshalb über drei Spalten: `(from_node_id, relation_type, sort_order)`.**
Er dient zugleich als **Suchindex** — gefiltert wird nach `from_node_id`, oft zusätzlich nach `relation_type`,
und `sort_order` ist die Sortierspalte am Ende. **Damit wird der heutige Einzelindex auf `from_id`
überflüssig:** ein zusammengesetzter Index mit `from_node_id` an erster Stelle deckt ihn mit ab.

⚠️ **Eine echte Doppelung bleibt und muss vor dem Schlüssel weg** — *gemessen: ohne `relation_type` sind es **8**
Verletzungen, mit `relation_type` genau **eine**. Knoten 55659 «render with label» hat **zwei
Einstellungskanten auf Stelle 0**: `label_role` und `with_label`. **Ich hatte vorschnell gesagt, es
gebe nichts zu bereinigen — das war falsch.** Siehe [`tasks.md`](tasks.md) TASK-012.*

⚠️ **`sort_order` und nicht `order`** — *`order` ist ein reserviertes SQL-Wort und bräuchte in jeder
Abfrage Backticks; wer sie einmal vergisst, merkt es erst zur Laufzeit. Gegengeprüft am 2026-09-01:
ohne Backticks ist jede Abfrage ein Syntaxfehler.*

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
| `type` | `default` oder `user` — indiziert | war `kind` |
| ~~`created_at`~~ | **fällt — gehört ins Änderungsbuch** | siehe unten |

**Die Paarung `node_id` + `node_version` bleibt: ein Datensatz gehört immer zu einer bestimmten
Version des Knotens.**

### 5.1 · Offen: was bei einem Versionskonflikt geschieht

**STATUS: `OPEN` — bewusst zurückgestellt**

*Der Eigentümer: «wenn wir Konflikte haben — Knoten hat sich geändert, Record zeigt auf alte
Version — Konflikt muss manuell aufgelöst werden, somit kann dieser Record nicht mehr dargestellt
werden. Gibt es keinen Konflikt, müsste die `node_version` upgedatet werden. **Das können wir
erstmal so lassen.**»*

⚠️ **Seine Vermutung über den heutigen Code ist bestätigt, gemessen:** *`node_version` wird
geschrieben, zurückgelesen und auf dem Bildschirm als «Version» angezeigt — **aber nirgends
verglichen.** Keine einzige Verzweigung hängt daran. Gemessen sind **29 von 209** Datensätzen älter
als die heutige Version ihres Knotens; keiner ist neuer.*

### 5.2 · `created_at` fällt — aber nicht sofort

*Der Eigentümer: «das `created_at` ist eigentlich was fürs Log, brauchen wir glaube ich nicht mehr
im `node_record`.»* **Richtig — nur führt das Log es heute nicht.**

⚠️ **Gemessen: im Änderungsbuch gibt es null Einträge mit `owner_kind = 'record'`.** *Es
protokolliert Knoten (17 608) und Kanten (5 964), **Datensätze nicht als eigene Art**. Wird die
Spalte jetzt gestrichen, ist die Entstehungszeit weg — das Log kann sie nicht übernehmen, weil es
sie nie geführt hat.*

**Reihenfolge nach `PR-12`: erst führt das Änderungsbuch Datensätze, dann fällt die Spalte.**
→ [`tasks.md`](tasks.md) TASK-015

---

## 5a · `relation_records` — der Kanten-Datensatz

**Die Tabellen heissen künftig wie der Wortschatz:** `records` → **`node_records`**,
`record_values` → **`relation_records`**.

*Der Eigentümer: «records → node_records, record_values → relation_records».*

⚠️ **Die Zuordnung ist gemessen und ausnahmslos:** *`records.node_id` zeigt in **209 von 209**
Fällen auf einen Knoten, `record_values.edge_id` in **183 von 183** auf eine Kante,
`record_values.record_id` in **183 von 183** auf einen Datensatz. Ausgeschrieben liest sich eine
Zeile genau so: **Knoten «Condensator» · Kante «Value» (Typ Decimal) = 110.***

**Daraus folgen zwei Spaltennamen** — nach derselben Regel wie `from_node_id` (§6):

| heute | künftig | |
|---|---|---|
| `record_values.record_id` | **`node_record_id`** | zeigt auf `node_records` |
| `record_values.edge_id` | **`relation_id`** | zeigt auf `relations` |

⚠️ **«edge» und «relation» sind zwei Wörter für dieselbe Sache, und es ist offen, welches gewinnt.**
*Gemessen im Quelltext: **«edge» 1080-mal, «relation» 407-mal** — fast dreimal so häufig. Die Tabelle
heisst `relations` und die Klasse `Relation`, **der Code sagt aber überwiegend `edge`.** Zwei Wörter
für eine Sache sind das, was [`CD-9`](../../../CLAUDE.md) verbietet — **welches bleibt, ist nicht
entschieden.** Sein deutsches Wort ist **Kante**, was näher an «edge» liegt als an «relation».*

⚠️ *Die Spaltennamen oben stehen deshalb unter Vorbehalt: `relation_id` folgt dem Tabellennamen,
`edge_id` folgt dem Code. **Erst die Wortwahl, dann die Spalte.***

**Die übrigen Spalten der beiden Tabellen sind noch nicht überarbeitet**, ebenso `labels`. Bis dahin
gilt [`review-tabellen.md`](../../review-tabellen.md) als **Befund, nicht als Vorgabe** — dort steht
unter anderem, dass `relation_records.value_ref` auf **Knoten und Datensätze zugleich** zeigt und
`position` in **0 von 183** Zeilen gefüllt ist.

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
