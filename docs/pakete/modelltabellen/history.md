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

**Und `order` ist ein reserviertes SQL-Wort.** *Gegengeprüft: ohne Backticks ist jede Abfrage ein
Syntaxfehler.* Noch nicht entschieden, ob es `order` oder `sort_order` heisst.

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
