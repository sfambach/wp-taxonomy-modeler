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
| `type` | die Kantenart | `inheritance` 127 · `composition` 23 · `setting` 11 · `aggregation` 5 |
| `name` | | 39 von 166 |
| `order` | Reihenfolge unter dem Elternknoten, **erste ist `0`** | war `position` |
| `multiplicity` | | `1..1` 156 · `0..1` 5 · `1..*` 3 · `0..*` 2 |
| `hide` | | 6 von 166 |
| `parked_by_group_id` | | 1 von 166 |

**`from_node_id`/`to_node_id` sind keine Umbenennung, sondern eine Verschärfung:** heute zeigen alle
sieben Fremdschlüssel auf `identities.id`, was strukturell **eine Kante von einem Datensatz aus**
erlaubt. → [`history.md`](history.md)

**Die vier Kantenarten sind keine eigenen Klassen und brauchen es nicht** — 15 von 19 Verzweigungen im
Code fragen nur «ist es Vererbung?».

### 4.1 · Was an `type` hängt

| | |
|---|---|
| `name` | **leer genau dann, wenn Vererbung** — 127 von 127 ohne, 39 von 39 mit |
| `multiplicity` | **sagt bei Vererbung nichts** — alle 127 auf `1..1` |
| `hide` | **nur auf Vererbungskanten** — 6 Stück |

### 4.2 · `order` — die Reihenfolge, je Knoten **und** je Kantenart

**`position` heisst künftig `order`. Die erste Stelle ist `0`.** Gezählt wird **je `from_node_id`
und je `type`** — der Knoten in `from` ist der besitzende: bei einem Feld der Knoten, der es hat,
bei Vererbung der Elternknoten.

⚠️ **Zwei Listen, nicht eine.** *Die Kinder eines Knotens im Baum werden 0, 1, 2 … durchgezählt, und
seine Felder ebenfalls 0, 1, 2 … **Beide hängen am selben `from_node_id`.** Sechs Knoten haben
heute beides — `Root`, `Kontact`, `Passiv`, `Prefixes`, `Without prefix`, `render with label` — und
genau dort überschneiden sich die Zahlen.*

**Der eindeutige Schlüssel ist deshalb `(from_node_id, type, order)`, nicht `(from_node_id, order)`.**
→ [`history.md`](history.md)

⚠️ *`order` ist ein reserviertes SQL-Wort und braucht überall Backticks. Der Eigentümer hat es so
gewollt; die Alternative `sort_order` ist verworfen.*

### 4.3 · `parked_by_group_id` — der Papierkorb

**Mit welchem Löschereignis ist diese Kante gefallen?** Löschen ist zweistufig — **parken, dann
endgültig entfernen** ([D-123](../../NewConcept/90-decision-log.md)). Wird ein referenzierter Knoten
gelöscht, parkt jede Kante, die auf ihn zeigt, mit ([D-125](../../NewConcept/90-decision-log.md)),
und **das Wiederherstellen bringt das ganze Ereignis zurück**
([D-127](../../NewConcept/90-decision-log.md)).

*Deshalb eine **Gruppen**-Id und kein Schalter: sie hält zusammen, was zusammen gefallen ist.*
**Gemessen: genau eine Kante ist geparkt.**

⚠️ *Sie zeigt in einen dritten Id-Raum — Änderungsgruppen — und die Spalte sagt das nicht (§6).*

---

## 5 · Noch nicht überarbeitet

`records`, `record_values` und `labels`. Bis dahin gilt für sie
[`review-tabellen.md`](../../review-tabellen.md) als **Befund, nicht als Vorgabe**.

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
