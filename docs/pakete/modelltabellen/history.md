# Paket · Datenbank — Historie

**Keine Quelle für aktuelle Entscheidungen** ([`arbeitsmodell.md`](../../arbeitsmodell.md) §3).
Hier steht der Weg, nicht der Stand.

---

## 2026-09-01 · `path` fällt, `identities` fällt

Der Eigentümer, beim Durchgehen der Kerntabellen: *«`path` fliegt raus überall, den brauchen wir
nicht. Dann jeder Nodes und Relations bekommen jeweils ihre eigene Id. Identity stirbt.»*

**Was vorher galt und warum es fiel:**

`nodes.path` trug punktseparierte Id-Ketten und war seit [D-082](../../NewConcept/90-decision-log.md)
als «materialised ancestor path — derived and rebuildable» gedacht: eine vorgerechnete Abkürzung für
die Vorfahrensuche. **Sie stand bis zuletzt auf `agreed (proposal)`** und wurde nie bestätigt.
`50-wordpress-persistence.md` zitierte dafür ausserdem die falsche Nummer (`D-014`).

`identities` kam mit [D-339](../../NewConcept/90-decision-log.md): *«identities(id), append-only …
nodes and relations take the number and use it as their own primary key.»* Sie war der Anker, an dem
alle sieben Fremdschlüssel hingen, und **genau das trug die Schattentabellen** aus
[D-537](../../NewConcept/90-decision-log.md).

**Die Messungen, die den Ausschlag gaben, am 2026-09-01 an der laufenden Datenbank:**

- `nodes.path`: **127 von 127** mehrgliedrigen Pfaden aus `relations` herleitbar, Abweichung **null**.
- **18 Knoten** haben mehr als eine eingehende Kante — ein Pfad kann davon nur einen Weg nennen.
- `record_values.path`: **183 Zeilen, 12 verschiedene Werte** — genau wie `edge_id` daneben.
- `labels.path` und `settings.path`: **0 von 47, 0 von 3** gefüllt.
- `identities`: **65 593 vergeben, rund 680 in Gebrauch — 1,1 %.**

---

## 2026-09-01 · Die Kante wird eindeutig

Der Eigentümer beim Durchgehen von `relations`: *«machen wir es eh eindeutiger. Wir machen eine
From-Node-Id und To-Node-Id. Und zu sagen, das ist eine Knoten-Id — da ist ein Constraint.»* Dazu:
*«den `kind` machen wir Typ draus»* und *«Position würde ich eher Order nennen. Und die erste Position
ist immer null.»*

### Warum `from_node_id` mehr ist als ein Name

**Gemessen: es gibt sieben echte Fremdschlüssel-Constraints, und alle sieben zeigen auf
`identities.id`** — nicht auf `nodes.id`. Die Bedingung sagt heute also nur «muss irgendeine Identität
sein». **Da Datensätze ebenfalls in `identities` stehen, erlaubt die Datenbank strukturell eine Kante,
die von einem Datensatz ausgeht.** Gemessen tut es keine — 166 von 166 zeigen auf Knoten —, aber
nichts hält es auf.

Die sieben Constraints müssen ohnehin umziehen, wenn `identities` fällt. **Beides ist ein
Arbeitsstück.**

### Warum die vier Kantenarten keine Klassen werden

Auf seine Frage, ob `inheritance`, `composition`, `setting` und `aggregation` eigene Klassen sind:

**`RelationKind` ist ein Aufzählungstyp mit vier Fällen** und zwei Methoden, `isComposition()` und
`isSetting()`. **Der Code verzweigt an 19 Stellen darauf — und 15 davon fragen nur «ist es
Vererbung?».** `Composition`, `Aggregation` und `Setting` werden je **einmal** genannt.

**Vier Klassen wären vier Heimaten für Verhalten, das heute aus zwei Ja-Nein-Fragen besteht.**

*Das unterscheidet sie von der Klassenspalte am Knoten: dort geht es um Typen, Renderer, Konverter und
Validatoren — Dinge mit wirklich verschiedenem Verhalten.*

### Was beim Umbenennen von `position` zum Vorschein kam

Seine Regel «die erste Stelle ist `0`» beseitigt die Zweideutigkeit der Spalte — `NOT NULL` konnte
nicht zwischen «erste Stelle» und «nicht gesetzt» unterscheiden.

**Dabei kam eine andere heraus: 17 Kanten teilen sich eine Stelle mit einem Geschwister, in 8
Gruppen.** Knoten 55659 hat **drei** Kinder auf `0`, Knoten 3636 zwei auf `0` und zwei auf `1`.
**Dort ist die Reihenfolge nicht definiert** — das ist kein Benennungsproblem, sondern ein fehlender
eindeutiger Schlüssel.

**Und `sort_order` ist ein reserviertes SQL-Wort.** *Gegengeprüft: ohne Backticks ist jede Abfrage ein
Syntaxfehler.* Noch nicht entschieden, ob es `sort_order` oder `sort_order` heisst.

### Was `parked_by_group_id` ist — nachgeschlagen, nicht erklärt

Auf seine Frage. Es ist der Papierkorb:

- **[D-123](../../NewConcept/90-decision-log.md):** *«Deletion is two-stage: park, then purge.»*
- **[D-125](../../NewConcept/90-decision-log.md):** wird ein referenzierter Knoten gelöscht, parkt jede Kante, die auf ihn zeigt, mit.
- **[D-127](../../NewConcept/90-decision-log.md):** *«A trash entry is one deletion event … restore puts back the whole event.»*

**Die Spalte beantwortet: mit welchem Löschereignis ist diese Kante gefallen.** Deshalb eine
Gruppen-Id und kein Schalter. Gemessen ist genau eine Kante geparkt.

### Eine Beobachtung über dieses Dokument selbst

`package.md` stand bei **14,2 KB**, über der Decke von 12 — und **42 % davon war meine Herleitung**.
Beim Entscheidungsbuch waren es 64 %, in `CLAUDE.md` 41 %. **Dasselbe Muster zum dritten Mal an einem
Tag.** Die Herleitungen sind hierher gezogen; `package.md` steht danach bei 7,0 KB, mit denselben
Entscheidungen.

### Nachtrag: die «17 doppelten Reihenfolgen» waren keine

Seine Frage machte es sichtbar: *«wenn ich im Knoten ein Attribut hinzufüge, wo steht dann der Knoten,
in `from` oder in `to`?»*

**Gemessen: der besitzende Knoten steht immer in `from`.** Bei einem Feld ist es der Knoten, der es
hat — «Value» von `Passiv` nach `Decimal`. Bei Vererbung der Elternknoten — von `Data Types` nach
`Integer`.

**Damit löste sich der Befund auf: alle 8 Gruppen mit gleicher Stelle mischen Kantenarten**, ohne
Ausnahme. `Passiv` hat auf Stelle 0 sein Kind `Resistor` **und** sein Feld `Value`. `Prefixes` hat auf
Stelle 0 sein Kind `yotta` **und** seine Einstellung `exponent`.

**Es sind zwei Listen in einer Spalte, und beide sind für sich lückenlos.** Sechs Knoten haben
gleichzeitig Kinder und Felder — genau die sechs, in denen die Zahlen sich überschneiden.

⚠️ **Mein Vorschlag war falsch.** *Ich wollte einen eindeutigen Schlüssel auf `(from_node_id, sort_order)`;
der hätte **17 gültige Zeilen abgelehnt.** Richtig ist `(from_node_id, type, sort_order)`, und zu bereinigen
gibt es nichts.*

### Nachtrag: `sort_order`, nicht `order`

Ich hatte seinen Satz *«so, der Order ist okay»* als Zustimmung zum Spaltennamen `order` gelesen.
**Gemeint war `sort_order`**, das ich als Alternative vorgeschlagen hatte — er stellte es richtig:
*«warum hast du das `sort_order` jetzt verworfen, das war doch schon ok.»*

**Und `sort_order` ist die bessere Wahl:** `order` ist ein reserviertes SQL-Wort und bräuchte in jeder
Abfrage Backticks — gegengeprüft, ohne sie ist jede Abfrage ein Syntaxfehler. **Wer sie einmal
vergisst, merkt es erst zur Laufzeit.**

### Nachtrag zum Nachtrag: eine Doppelung bleibt doch

Beim Prüfen des dreispaltigen Schlüssels gegen die Daten: **ohne `type` sind es 8 Verletzungen, mit
`type` genau eine.**

`type` löst also 7 der 8 auf — sie waren Kind gegen Feld. **Die achte ist echt:** Knoten 55659
«render with label» hat **zwei Einstellungskanten auf Stelle 0**, `label_role` und `with_label`.

⚠️ *Meine Zusage «zu bereinigen gibt es nichts» war vorschnell. Sie stand auf der Messung ohne `type`
und ich hatte sie nicht mit `type` wiederholt, bevor ich sie aussprach.*


---

## 2026-09-01 · Herleitungen aus `package.md`

*Nach [`arbeitsmodell.md`](../../arbeitsmodell.md) §4 gehoert die Herleitung in die Historie. Die
folgenden Absaetze standen in `package.md` und haben es ueber die Decke gebracht.*

⚠️ **Zwei Listen, nicht eine.** *Die Kinder eines Knotens im Baum werden 0, 1, 2 … durchgezählt, und
seine Felder ebenfalls 0, 1, 2 … **Beide hängen am selben `from_node_id`.** Sechs Knoten haben
heute beides — `Root`, `Kontact`, `Passiv`, `Prefixes`, `Without prefix`, `render with label` — und
genau dort überschneiden sich die Zahlen.*

⚠️ **Eine echte Doppelung bleibt und muss vor dem Schlüssel weg** — *gemessen: ohne `relation_type` sind es **8**
Verletzungen, mit `relation_type` genau **eine**. Knoten 55659 «render with label» hat **zwei
Einstellungskanten auf Stelle 0**: `label_role` und `with_label`. **Ich hatte vorschnell gesagt, es
gebe nichts zu bereinigen — das war falsch.** Siehe [`tasks.md`](tasks.md) TASK-012.*

⚠️ **`sort_order` und nicht `order`** — *`order` ist ein reserviertes SQL-Wort und bräuchte in jeder
Abfrage Backticks; wer sie einmal vergisst, merkt es erst zur Laufzeit. Gegengeprüft am 2026-09-01:
ohne Backticks ist jede Abfrage ein Syntaxfehler.*

⚠️ **Seine Vermutung über den heutigen Code ist bestätigt, gemessen:** *`node_version` wird
geschrieben, zurückgelesen und auf dem Bildschirm als «Version» angezeigt — **aber nirgends
verglichen.** Keine einzige Verzweigung hängt daran. Gemessen sind **29 von 209** Datensätzen älter
als die heutige Version ihres Knotens; keiner ist neuer.*

⚠️ **Gemessen: im Änderungsbuch gibt es null Einträge mit `owner_kind = 'record'`.** *Es
protokolliert Knoten (17 608) und Kanten (5 964), **Datensätze nicht als eigene Art**. Wird die
Spalte jetzt gestrichen, ist die Entstehungszeit weg — das Log kann sie nicht übernehmen, weil es
sie nie geführt hat.*

⚠️ **Bei einer Komposition ist ein verwaister Datensatz ein Fehler.** *Ein Teil kann nicht ohne sein
Ganzes bestehen. Zeigt niemand mehr auf `#3`, ist das kein gültiger Zustand — **und die Struktur
verhindert es nicht, also muss eine Prüfung es finden.***

⚠️ *Der Unterschied zwischen **Komposition** und **Aggregation** ist allein, **ob zwei Datensätze auf
denselben zeigen dürfen.** Die Speicherung muss dafür nichts anderes können — es sagt
`relation_type`.*
