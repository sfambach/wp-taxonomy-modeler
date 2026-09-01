# Review des Tabellenkonzepts

**Stand 2026-09-01.** Auf Bitte des Eigentümers: *«wir müssen das Tabellenkonzept überarbeiten, das
alte hat Fehler übernommen und wir müssen ein Review und ein paar neue Designentscheidungen machen,
`path` ist ein Beispiel dafür.»*

**Kein Code geändert, nichts entschieden.** Jeder Befund ist an der laufenden Datenbank gemessen.
Vorschläge tragen `PROPOSED` und warten auf ihn.

---

## Ergebnis

**`path` ist nicht ein Beispiel, sondern der Hauptfall — und er ist schlimmer als «unbelegt».**

`nodes.path` enthält punktseparierte Id-Ketten (`1.402.3083.13`) in **127 von 128 Zeilen**. Genau die
Form, von der der Eigentümer seit Tagen sagt, sie sei nie seine gewesen: *«damit waren die IDs gemeint
aus Knoten und Kanten … und nicht ein Pfad aus Punkt separierten IDs. Das war nicht Bestandteil»*,
und *«die IDs stehen aber einzeln in Tabellenzeilen»*.

**Gemessen:**

| | |
|---|---|
| Knoten mit mehrgliedrigem Pfad | **127** |
| davon: vorletztes Glied ist wirklich eine Elternkante in `relations` | **127** — Abweichung **0** |
| Knoten mit **mehr als einer** eingehenden Kante | **18** |

**Daraus folgen zwei Dinge, und das zweite wiegt schwerer als das erste:**

1. **Der Pfad wiederholt, was `relations` schon besitzt.** `CLAUDE.md` verbietet das wörtlich: *«❌ Duplicating a fact. One place owns each piece of state; everything else derives.»*
2. **Für 18 Knoten kann ein einzelner Pfad nur einen von mehreren Wegen nennen.** Er ist dort nicht nur überflüssig, sondern **verlustbehaftet** — er behauptet einen Baum, wo ein Graph steht.

⚠️ *Offen und ausdrücklich nicht von mir entschieden: ob alle eingehenden Kanten als «Eltern» zählen
oder nur bestimmte Arten. Davon hängt ab, ob Punkt 2 achtzehn Knoten betrifft oder weniger.*

---

## D-567 wird zurückgezogen

**STATUS:** `REJECTED`

**BEFUND:** Der Vorschlag wollte die Basistabellen benennen, **wie sie sind**, damit `AR-1` eine
aktuelle Grundlage bekommt.

**BEGRÜNDUNG:** Wenn der Bestand geerbte Fehler enthält, macht genau das die Fehler zum Soll-Zustand.
Das ist §19 des Arbeitsmodells: *«Der Code beschreibt den aktuellen Ist-Zustand, aber nicht
automatisch den gewünschten Soll-Zustand.»*

**AUSWIRKUNG:** `AR-1` bleibt vorerst ohne aktuelle Grundlage. Das ist **richtig so** — die Grundlage
entsteht am Ende dieses Reviews, nicht davor.

---

## A · Die vier `path`-Spalten, einzeln gemessen

| Spalte | Zeilen | gefüllt | verschieden | Befund |
|---|---|---|---|---|
| **`nodes.path`** | 128 | 128 | 128 | **punktseparierte Id-Kette**, 127 mit Punkt · siehe oben |
| **`record_values.path`** | 183 | 183 | **12** | `edge_id` hat **ebenfalls 12** verschiedene Werte — der Pfad ist dessen **Spiegel** |
| **`labels.path`** | 47 | **0** | 1 | **tot** |
| **`settings.path`** | 3 | **0** | 1 | **tot** (Tabelle stirbt ohnehin, [D-529](NewConcept/90-decision-log.md)) |

**STATUS:** `FACT`

**AUSWIRKUNG:** Zwei der vier Spalten tragen nachweislich nichts. Eine spiegelt eine Nachbarspalte.
Eine wiederholt die Kantentabelle. **Keine davon trägt eine Information, die nicht schon woanders
steht.**

**ENTSCHEIDUNG ERFORDERLICH:** **JA** — je Spalte einzeln, in der Reihenfolge `PR-12` (Wächter,
Leser, Daten). `labels.path` und `settings.path` sind die billigsten, `nodes.path` ist die teuerste:
`WpdbNodeRepository` fasst sie an **20 Stellen** an.

---

## B · Zwei beschlossene Mechanismen, die in den Daten nicht vorkommen

**Das ist die gefährlichste Kategorie**, weil das Konzept etwas behauptet, das nicht läuft.

**STATUS:** `FACT`

| Entscheidung | Was sie sagt | Was die Daten sagen |
|---|---|---|
| **[D-530](NewConcept/90-decision-log.md)** | mehrere Werte sind mehrere **Zeilen, geordnet durch `position`** | `record_values.position` ist in **0 von 183** Zeilen gefüllt |
| **[D-536](NewConcept/90-decision-log.md)** | jede Version bleibt erhalten, **das Journal gruppiert nur noch**, welche Identität in welcher Version geändert wurde | `changelog.version` ist in **25 von 23229** Zeilen gefüllt · und **9490** Zeilen tragen weiter einen `before_state`, obwohl «die alte Zeile *ist* der Vorher-Zustand» |

**BEGRÜNDUNG:** Direkt gezählt.

**AUSWIRKUNG:** Bei D-536 ist das **bekannte offene Arbeit** — Schritt 6, «das Journal schrumpft».
Bei **D-530 nicht**: dort ist die Ordnung mehrerer Werte entschieden und die Spalte, die sie tragen
soll, ist leer. Entweder gibt es noch keinen Fall mit mehreren Werten, oder die Ordnung läuft
woanders.

**ENTSCHEIDUNG ERFORDERLICH:** **JA** für D-530 — ist der Mechanismus gebaut und nur ungenutzt, oder
ist er nicht gebaut? Ich habe das **nicht** aufgelöst, weil beide Antworten plausibel sind und die
falsche eine Bauaufgabe erfindet oder eine verschweigt.

---

## C · Der Id-Raum blutet

**STATUS:** `FACT`

| | |
|---|---|
| Vergebene Identitäten | **64 703** (höchste Id: 64 703 — also lückenlos ausgegeben) |
| davon von einer **lebenden** Zeile benutzt | **680 · 1,1 %** |
| nur noch in einer Schattentabelle | 10 462 |
| **in gar keiner Tabelle** | **53 561 · 83 %** |

**BEGRÜNDUNG:** Gezählt gegen alle acht Tabellen mit einer Identität.

**AUSWIRKUNG:** Für 681 lebende Objekte sind fast fünfundsechzigtausend Ids verbraucht. Das ist
**kein Datenfehler** — Ids sind billig und dürfen Lücken haben. Es ist ein **Hinweis auf den
Vergabezeitpunkt**: offenbar wird eine Identität geholt, bevor feststeht, dass eine Zeile entsteht,
und ein Fehlschlag gibt sie nicht zurück. Prüfläufe, die anlegen und aufräumen, verbrennen dann
dauerhaft Ids.

⚠️ *Der Schaden ist heute null. Er wird erst zu einem, wenn eine Zahl irgendwo eine Grenze hat — und
er macht jede Aussage der Form «wieviele Dinge gibt es» aus dieser Tabelle wertlos.*

**ENTSCHEIDUNG ERFORDERLICH:** **JA**, aber klein: soll eine Identität erst vergeben werden, wenn die
Zeile geschrieben wird? Das berührt [D-339](NewConcept/90-decision-log.md) und den Allokator.

---

## D · Spalten, die nichts sagen

**STATUS:** `FACT` · gemessen als «gefüllt» und «verschiedene Werte»

| Spalte | Zeilen | gefüllt | verschieden | |
|---|---|---|---|---|
| `nodes.kind` | 128 | **4** | **1** | 124 Knoten haben keine Art, die vier übrigen dieselbe |
| `labels.number` | 47 | 47 | **1** | überall derselbe Wert |
| `record_values.locale` | 183 | **0** | 1 | nie benutzt |
| `record_values.value_text` | 183 | **0** | 0 | in der **lebenden** Tabelle leer — in der Schattentabelle 411 von 923 |
| `record_values.value_date` | 183 | **0** | 0 | nie benutzt |
| `changelog.by_user_id` | 23 229 | 3 109 | **1** | ein einziger Benutzer |
| `relations.parked_by_group_id` | 166 | **1** | 2 | eine einzige Zeile |
| `records_history.deleted` | 123 | 123 | **1** | überall derselbe Wert |
| `records_history.version` | 123 | 123 | **1** | überall derselbe Wert |

**AUSWIRKUNG:** Nicht jede dieser Spalten ist ein Fehler. `by_user_id` hat einen verschiedenen Wert,
weil es **eine** Person gibt — das ist richtig und wird sich ändern. `value_date` ist leer, weil noch
niemand ein Datum gepflegt hat.

**Drei sind echte Kandidaten:**

- **`nodes.kind`** — 124 von 128 leer. Wenn die Art eines Knotens woanders steht (Zweig, Kante), ist die Spalte eine zweite Heimat.
- **`labels.number`** — trägt in 47 Zeilen einen einzigen Wert und damit keine Information.
- **`records_history.version` und `.deleted`** — je ein Wert über 123 Zeilen. Das legt nahe, dass der Schattenmechanismus für `records` **nie wirklich gelaufen ist**; er wurde als [N-11](audit-v1.2.md) schon als ungeprüft geführt.

**ENTSCHEIDUNG ERFORDERLICH:** **JA** für die drei; **NEIN** für die übrigen.

---

## E · Zwei Fehler in meiner eigenen Messung

**Damit du nicht darauf baust:**

1. **Zeitspalten falsch gemeldet.** Mein erster Lauf meldete `changelog.at`, `records.created_at` und alle vier `archived_at` als «nie gefüllt». **Falsch** — der Test verglich `datetime` mit der Zeichenkette `'0'`, was MySQL mit Umwandlung erledigt. Nachgemessen: **alle sind vollständig gefüllt**, kein NULL, kein Nulldatum.
2. **Im Audit stand «51 Renderer-Klassen»** als Umfang des Renderer-Umbaus. Die belastbare Zahl steht in [D-563](NewConcept/90-decision-log.md) und ist eine andere: **28 Dateien im Kern schreiben Tags, rund 245 Tag-Literale.**

---

## Was ich vorschlage — alles `PROPOSED`

**Reihenfolge nach Risiko und Kosten, nicht nach Auffälligkeit.**

| | Vorschlag | Warum zuerst |
|---|---|---|
| **V-1** | **`labels.path` und `settings.path` entfernen** | nachweislich leer, keine Konzeptfrage, billigster erster Schritt in der Reihenfolge Wächter–Leser–Daten |
| **V-2** | **`record_values.path` entfernen** | Spiegel von `edge_id`, 12 gegen 12 verschiedene Werte · Arbeitsliste Zeile 109 |
| **V-3** | **`nodes.path` entfernen und den Weg aus `relations` herleiten** | 127 von 127 belegt, dass die Kante die Wahrheit hat · **teuerste Stelle: 20 Aufrufe** · und für 18 Knoten ist der Pfad ohnehin verlustbehaftet |
| **V-4** | **Klären, ob D-530s `position` gebaut ist** | ein entschiedener Mechanismus ohne eine einzige Datenzeile |
| **V-5** | **`nodes.kind`, `labels.number` prüfen** | zwei Spalten, die keine Information tragen |
| **V-6** | **Identität erst bei der Zeile vergeben** | heute schadlos, macht aber jede Zählung aus `identities` wertlos |

**Was ich ausdrücklich nicht vorschlage:** eine Sammeländerung. Jede Spalte ist eine eigene
Konzeptänderung mit eigenem Wächter, eigenem Leser und eigener Datenwanderung — in dieser Reihenfolge
(`PR-12`). Ein Feldzug über alle sechs auf einmal ist genau die Art Umbau, bei der der Bildschirm
weiterläuft und still das Falsche zeigt.

---

## Zusammenfassung

```text
BEZUG
Review des Tabellenkonzepts, an der laufenden Datenbank gemessen.

ERGEBNIS
path ist der Hauptfall, nicht ein Beispiel. nodes.path traegt in 127 von 128
Zeilen punktseparierte Id-Ketten - die Form, die er nie beschlossen hat - und
alle 127 sind aus relations herleitbar, ohne eine einzige Abweichung. Fuer 18
Knoten mit mehreren eingehenden Kanten ist ein einzelner Pfad ausserdem
verlustbehaftet.

GEAENDERT
Nichts. D-567 wird zurueckgezogen, weil sie den Ist-Zustand zum Soll-Zustand
gemacht haette.

WEITERE BEFUNDE
Zwei beschlossene Mechanismen kommen in den Daten nicht vor: D-530s position
ist in 0 von 183 Zeilen gefuellt, D-536s Journalversion in 25 von 23229.
Der Id-Raum: 64703 vergeben, 680 lebend benutzt, 1,1 Prozent.
Neun Spalten tragen keine oder nur eine einzige Auspraegung; drei davon sind
echte Kandidaten.

EIGENE FEHLER
Meine erste Messung meldete alle Zeitspalten falsch als leer - der Vergleich
war kaputt. Nachgemessen sind sie vollstaendig gefuellt.

ENTSCHEIDUNGEN ZUR BESTAETIGUNG
V-1 bis V-6, einzeln und nacheinander, nicht als Sammelaenderung.

OFFENE FRAGEN
Zaehlen alle eingehenden Kanten als Eltern, oder nur bestimmte Arten?
Ist D-530s position gebaut und ungenutzt, oder nicht gebaut?

NAECHSTER SCHRITT
V-1. Zwei nachweislich leere Spalten, keine Konzeptfrage, und der Weg
Waechter-Leser-Daten laesst sich daran einmal sauber gehen, bevor er bei
nodes.path teuer wird.
```
