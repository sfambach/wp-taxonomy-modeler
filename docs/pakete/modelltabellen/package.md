# Paket · Modelltabellen

**Stand 2026-09-04.** Nur der aktuell gültige Soll-Zustand. **Jede Begründung steht in
[`history.md`](history.md)**, Offenes in [`tasks.md`](tasks.md), Fehler in [`bugs.md`](bugs.md).

⚠️ **Soll, nicht Ist.** *Wo beides auseinandergeht, steht «gemessen» dabei.*

---

## 0 · Stand

**`nodes` und `relations` sind fertig** ([D-578](../../NewConcept/90-decision-log.md)) — sie werden
umgesetzt, siehe [`tasks.md`](tasks.md). *Kein Lock: veraltet später etwas, wird es mit Grund
ersetzt ([D-565](../../NewConcept/90-decision-log.md)).*

**Die Speicherform gilt für alle Kantenarten** — *es gibt keine zweite Ablage für eine bestimmte
Art. Ein Wert an einer Kante ist eine Zeile in `relation_records`.*

⚠️ **Und seit dem 2026-09-04 gibt es nur noch *eine* Kantenart**
([D-587](../../NewConcept/90-decision-log.md)): *`relation_type` fällt. Was Komposition von
Aggregation unterschied, ist eine einzige Frage — **wird mit dem Knoten gelöscht** —, und ob eine
Kante eine Einstellung trägt, sagt der Ast des Zielknotens. Die Spalte war ohnehin abgeleitet:
die Oberfläche sagt es dem Benutzer schon heute, «Kind is not a choice».*

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
| `parent_node_id` | **unter wem der Knoten hängt** — zeigt auf `nodes.id`, war eine Vererbungskante | §3.3 |
| `sort_order` | **an welcher Stelle unter dem Elternknoten** | §3.3 |
| `field_type` | **`model`** oder **`setting`** | war `kind` |
| `label_id` | → `labels.id`, **verpflichtend** — hier steht der Name | §3.4 |
| ~~`settings_record_id`~~ | **gestrichen** (TASK-057, [D-642](../../NewConcept/90-decision-log.md)) — der Renderer hängt an einer Einstellungskante `1..1` | war 29 gefüllt |
| `hide` | **im Baum nicht anzeigen** ([D-590](../../NewConcept/90-decision-log.md)) — dass ein *Feld* nicht gezeichnet wird, sagt ein Renderer, der nichts ausgibt | 6, von den Kanten übernommen |
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
`parent_node_id` **zeigt auf `nodes.id`** — der Vaterknoten ist ein Knoten, kein Datensatz — und sagt, unter wem der Knoten hängt; `sort_order`, an welcher Stelle unter seinen
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

⚠️ **Entschieden am 2026-09-04: vier Spalten** ([D-598](../../NewConcept/90-decision-log.md)).
*Rollen als Zeilen mit `role_id` sind nicht verworfen, sondern geparkt
([`INF-005`](../../neues-konzept-eingang.md)) — **zu verlassen, wenn zum ersten Mal eine Rolle
fehlt.** Der ursprüngliche Wortlaut der Frage:*

⚠️ **Offen: sind die vier Rollen Spalten oder Zeilen mit `role_id`?**

### 3.5 · `nodes` hat danach keine lesbare Spalte mehr

*Wer die Tabelle roh ansieht, sieht nur Ids. Kein Gegenargument, aber eine spürbare Änderung beim
Suchen von Hand und beim Prüfen.*

---

### 3.6 · Eigene Einstellungen — an einer **Kante**, nicht an einer Spalte

⚠️ **Hier stand `settings_record_id` an Knoten und Kante. Beides ist zurückgebaut** (TASK-057,
Schemafassung 32).

**Der Renderer hängt an einer gewöhnlichen Einstellungskante `renderer` mit `1..1` am Knoten**
([D-642](../../NewConcept/90-decision-log.md)). *Der Eigentümer hat berichtigt, was ich aus
[D-584](../../NewConcept/90-decision-log.md) gemacht hatte: «das hast du leider falsch verstanden,
ich meinte einfach eine Multiplizität von 1» — und auf die Rückfrage, wo: «am Knoten». **Der Schluss
«also braucht es keine Kante, sondern nur einen Zeiger» war meiner, nicht seiner.***

**An der Kante fällt der Renderer ersatzlos** ([D-643](../../NewConcept/90-decision-log.md)): *eine
Kante ist eine Verwendungsstelle, kein Ding. Gemessen trugen beide Spalten dort **je 0 Zeilen**.
[D-582](../../NewConcept/90-decision-log.md) und [D-586](../../NewConcept/90-decision-log.md) sind
damit zurückgenommen.*

```text
nodes        «Integer»
node_record  #2282  node_id = Integer, record_type = default
   #2282 · renderer · → node_record #7978          die Wahl, an der Kante «renderer»

node_record  #7978  node_id = slider               der Satz ist der gewaehlte Renderer
   #7978 · converter · → «keiner»                  geerbt vom Basisknoten «Renderer»
```

⚠️ *Eine Stufe und nicht zwei: der Teil hinter der Einstellungskante **ist** der gewählte Renderer,
und seine `node_id` sagt, welcher ([D-583](../../NewConcept/90-decision-log.md)). Der Hüllknoten
`DisplayOption` dazwischen ist gefallen ([D-604](../../NewConcept/90-decision-log.md)).*

**Es gibt kein Überschreiben-Konstrukt, nur eine Reihenfolge:**

```text
Renderer für «Kunde.vorname»:
   1. Einstellungsdatensatz der Kante        → gilt
   2. sonst der des Zielknotens «Text»       → gilt
   3. sonst Rückfall
```

**Mehrere Renderer sind kein Mechanismus, sondern ein Modell**
([D-584](../../NewConcept/90-decision-log.md)): ein Knoten `render list` mit einer Kante `1..n`.
Die Reihenfolge kommt über `sort_order` in `relation_records`, wie bei jedem anderen Feld.

⚠️ **Der Konverter hängt am Basisknoten `Renderer` und wird vererbt**
([D-585](../../NewConcept/90-decision-log.md)). *Eine Spalte an `nodes` wäre falsch: bei einer
`render list` hätte der ganze Knoten einen Konverter, obwohl jeder Renderer darin seinen eigenen
braucht. **Der Hüllknoten `DisplayOption` entfällt** — er sah immer gleich aus und trug damit keine
Aussage.*

⚠️ *Damit fällt **nur** die Einstellungskante, die den Weg zum Behälter beschreibt (drei von elf).
Felder **im** Behälter und Einstellungen direkt am Knoten bleiben Kanten.*
Felder **im** Behälter und Einstellungen direkt am Knoten bleiben Kanten.*

---

## 4 · `relations` — die Kante

| Spalte | | gemessen |
|---|---|---|
| `id` | eigener Id-Raum | |
| `version` | für die Schattentabelle | |
| `from_node_id` → `to_node_id` | **Constraint auf `nodes.id`** | je 166 |
| ~~`relation_type`~~ | **gestrichen** ([D-587](../../NewConcept/90-decision-log.md)) | war `composition` 23 · `setting` 11 · `aggregation` 5 |
| `deletes_with_node` | **wird mit dem Knoten gelöscht** — Spalte, nicht Einstellung ([D-591](../../NewConcept/90-decision-log.md)); bei einem einfachen Typ immer an und nicht wählbar ([D-588](../../NewConcept/90-decision-log.md)) | aus `relation_type`: `composition` 48 und `setting` 11 → **1**, `aggregation` 6 → **0** |
| `label_id` | → `labels.id`, **optional** — hier steht der Name | §3.4 |
| ~~`settings_record_id`~~ | **ersatzlos gestrichen** ([D-643](../../NewConcept/90-decision-log.md)) — eine Kante ist eine Verwendungsstelle und zeichnet nicht selbst | war **0** gefüllt, nie belegt |
| ~~`target_settings_record_id`~~ | **ersatzlos gestrichen** ([D-643](../../NewConcept/90-decision-log.md)); [D-586](../../NewConcept/90-decision-log.md) ist damit zurückgenommen | war **0** gefüllt, nie belegt |
| `sort_order` | Reihenfolge unter dem Elternknoten, **erste ist `0`** | war `position` |
| `multiplicity` | | `1..1` 156 · `0..1` 5 · `1..*` 3 · `0..*` 2 |
| ~~`hide`~~ | **gestrichen** ([D-590](../../NewConcept/90-decision-log.md)) — zieht als `nodes.hide` an den Knoten | war 6, alle auf Vererbungskanten |

**`from_node_id`/`to_node_id` sind keine Umbenennung, sondern eine Verschärfung:** heute zeigen alle
sieben Fremdschlüssel auf `identities.id`, was strukturell **eine Kante von einem Datensatz aus**
erlaubt. → [`history.md`](history.md)

**Die Kantenarten sind keine eigenen Klassen und brauchen es nicht** — 15 von 19 Verzweigungen im
Code fragten nur «ist es Vererbung?», und die Frage verschwindet mit
[D-581](../../NewConcept/90-decision-log.md) ganz.

⚠️ **Zwei Zeiger und nicht einer, und beide werden gleichzeitig gebraucht**
([D-586](../../NewConcept/90-decision-log.md)): *der eigene trägt den **Form-Renderer** der Kante —
wie die Liste aussieht, wo die Beschriftung steht —, der zweite die **Überschreibungen am
Zielknoten**. Ein Einstellungsdatensatz ist ein Datensatz von genau einem Knoten und kann nicht
beides sein. **Der Form-Renderer zählt seine Kinder nicht auf**, er läuft die Kanten des
Zielknotens ab und fragt jede nach ihrem Renderer.*

### 4.1 · Was an `relation_type` hängt

| | |
|---|---|
| `name` | **leer genau dann, wenn Vererbung** — gemessen 133 von 133 ohne, 65 von 65 mit |
| `multiplicity` | **sagt bei Vererbung nichts** — alle 133 auf `1..1` |
| `hide` | **nur auf Vererbungskanten** — 6 Stück, und alle sechs ziehen mit an den Knoten ([D-590](../../NewConcept/90-decision-log.md)) |

⚠️ **Alle drei sind Belege für dieselbe Sache, und die ist entschieden**
([D-581](../../NewConcept/90-decision-log.md)): *jede dieser Spalten sagt bei Vererbung nichts oder
etwas anderes als sonst. **Gemessen am 2026-09-04: 133 Vererbungskanten für 134 Knoten, davon 133
mit genau einer und keiner mit mehr** — eine Beziehung, die immer genau eine ist, ist eine Spalte
und keine Tabelle. Zwei Drittel der Zeilen von `relations` verschwinden damit.*

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

### 5.1 · Was bei einem Konflikt geschieht

**STATUS: `ENTSCHIEDEN` am 2026-09-04** ([D-599](../../NewConcept/90-decision-log.md))

**Ein Konflikt entsteht nicht durch die Art der Änderung, sondern dadurch, dass die vorhandenen
Werte sie nicht überleben — gemessen, nicht angenommen.** Zahl→Text immer gut; Text→Zahl gut,
wenn jeder Wert eine Zahl ist; Text kürzen gut, wenn der längste hineinpasst; `1..*`→`1..1` gut,
wenn kein Datensatz mehr als einen hat. **Was sich nicht messen lässt, ist ein Konflikt.**

**Ein Datensatz mit Konflikt wird angezeigt, soweit es geht, und als solcher kenntlich gemacht** —
*ein Datensatz, den man nicht sieht, ist einer, den man nicht reparieren kann.*

⚠️ **`node_version` taugt als Auslöser nicht und wird dafür auch nicht gebraucht.** *Gemessen:
34 von 407 Datensätzen liegen hinter ihrem Knoten, zwei davon 170 und 188 Zählschritte —
[D-349](../../NewConcept/90-decision-log.md) sagt, der Zähler bewegt sich bei **jedem** Schreiben
auf die Zeile, auch beim Umbenennen. **Gemessen wird an den Werten, nicht an einer Zahl.***

⚠️ *Der ursprüngliche Wortlaut, zurückgestellt seit dem 1.9.:*

**STATUS: war `OPEN` — bewusst zurückgestellt**

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
| `value_ref` | **oder** ein Verweis — auf einen Knoten *oder* eine eingebettete Ausprägung ([D-597](../../NewConcept/90-decision-log.md)) | 50 Knoten · 93 Datensätze |
| `value_ref_kind` | in welchem Raum die Id gilt — `node` oder `record` | Muster: `changelog.owner_kind` |
| ~~`path`~~ · ~~`locale`~~ | gestrichen | |

**Schlüssel: `(node_record_id, relation_id, sort_order)`** — dieselbe Form wie bei der Kante, eine
Etage tiefer.

⚠️ **Eine Spalte plus Raum, nicht zwei Spalten** ([D-597](../../NewConcept/90-decision-log.md)).
*Gemessen: 143 Verweise, kein einziger Zwitter — «entweder oder» ist heute ausnahmslos wahr.
**Offen ist nicht ob, sondern wie viele «oder» es einmal gibt:** sobald eine Einstellung
«sortiere nach *diesem Feld*» lautet, zeigt ein Wert auf eine **Relation**. Eine Spalte plus Raum
nimmt das als neuen Wert auf; zwei Spalten bräuchten eine dritte.*

⚠️ **Der Preis: kein echter Fremdschlüssel** — *das Ziel wechselt, also kann die Datenbank nicht
mitprüfen. **Es hält allein `scripts/dev/value-ref-space-check.php`**, und damit ist dieser
Wächter nicht Komfort, sondern die Zusicherung selbst.*

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
| `labels.owner_id` | Knoten **oder** Kanten | **`owner_kind` sagt den Raum** (Fassung 31, gebaut) |
| `labels.role_id` | Knoten | Name sagt es **nicht** |
| **`settings.owner_id`** | **Kanten** | **derselbe Name, anderes Ziel als in `labels`** |
| `record_values.value_ref` | Knoten 50 · Datensätze 93 | **`value_ref_kind` sagt den Raum** (TASK-005, gebaut) |

**Gebaut am 2026-09-04, Schema 21** (TASK-004). `identities` ist gelöscht, `nodes` und `relations`
vergeben aus ihrem eigenen `AUTO_INCREMENT`, und beide Räume beginnen über dem Höchstwert des
gemeinsamen — **es wurde nichts umnummeriert und keine Nummer wird ein zweites Mal vergeben.**

⚠️ **Ein Satz oben stimmt so noch nicht: `settings.owner_id` zeigt gemessen auf Knoten (3) *und*
Kanten (10)**, nicht nur auf Kanten — *und sie nennt ihren Raum nicht, was dieselbe Zeile darüber
verlangt.* **Das ist der eine Punkt, der aus TASK-004 offen blieb** (`INF-009` in
[`inbox.md`](inbox.md)); solange er offen ist, beginnt der Kantenraum weit über dem Knotenraum, damit
keine Nummer beides sein kann, und [`id-space-check.php`](../../../scripts/dev/id-space-check.php)
zählt es nach.

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
