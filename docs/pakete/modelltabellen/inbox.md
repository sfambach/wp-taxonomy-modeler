# Paket · Datenbank — Eingang

**Ein Eingangskanal, keine Änderung des Soll-Zustands**
([`arbeitsmodell.md`](../../arbeitsmodell.md) §7.1).

Was hier steht, ist erfasst und noch nicht eingeordnet. *«Später bearbeiten» bedeutet nicht
«vergessen».*

---

```text
INF-001
Typ: HYPOTHESE — vom Eigentümer
Beschreibung: Der geteilte Id-Raum könnte die Wurzel mehrerer Probleme sein.
```

*«Ich glaube ehrlich gesagt, dass viele Probleme daraus entstanden sind, dass wir einen gemeinsamen
Id-Bereich für Knoten und Kanten haben und daraus der Wunsch eines Pfades entstanden ist.»*

⚠️ **Die erste Hälfte ist mit TASK-004 beantwortet.** *Die zweite nicht:
[D-082](../../NewConcept/90-decision-log.md) nennt als Grund für `nodes.path` **nicht** den Id-Raum,
sondern die vorgerechnete Vorfahrensuche — «materialised ancestor path, derived and rebuildable».
**Beides kann nebeneinander wahr sein.***

```text
INF-002
Typ: FRAGE
Beschreibung: Alle sieben Fremdschlüssel zeigen heute auf identities.id.
```

Genau das trägt die Schattentabellen: **mehrere Zeilen mit derselben Id brechen keinen davon.**
Fällt `identities` (TASK-004), ist zu klären, worauf sie künftig zeigen — oder ob es sie noch gibt.

```text
INF-003
Typ: VORSCHLAG — offen, er denkt darüber nach
Beschreibung: Labels als Kanten-Datensätze statt eigener Tabelle.
```

**Sein Rahmen:** *«das Label ist nichts anderes als Multilang mit zusätzlichen Feldtypen.»*

**Der Vorschlag:** die fünf Rollen werden **Kanten**, einmal an der Wurzel deklariert und von allen
geerbt — `form`, `table`, `select`, `symbol`, `help`, je `0..*`. Ein Label ist dann **ein
Kanten-Datensatz** mit `locale` und Text. Keine `Label`-Knoten, keine eingebetteten Datensätze.

```text
Knoten-Datensatz  #10   node_id = Condensator · record_type = default
   #10 · form   · de_DE · "Kondensator"
   #10 · form   · en_US · "Capacitor"
   #10 · symbol · ""    · "C"
```

**Was wegfiele:** die Tabelle `labels`, **476 Zeilen** eigener Klassen (`Labels`, `LabelRepository`,
`WpdbLabelRepository`), zwei Wächter, ein zweiter Auflösungsweg und die toten Spalten `path` und
`number`.

**Was es kostet:** die Zeilenzahl bleibt gleich — *eine Zeile je geschriebenem Text, heute wie
morgen*. Dazu **bis zu 40 zusätzliche Knoten-Datensätze**, einer je Knoten mit Labels; die meisten
gibt es schon.

⚠️ **Der Punkt, an dem er abgebrochen hat, und er hat einen Fehler in meinem Vorschlag gefunden:**
*heute hängt ein Label am **Knoten**, in meinem Vorschlag an einem **Knoten-Datensatz** — also an
einer Ausprägung. Bei `Einheitenwert` mit 21 Benutzer-Datensätzen wären das 21 Gelegenheiten für
verschiedene Beschriftungen. **Unsinn.***

**Die Auflösung, die es braucht, und sie muss ausdrücklich dastehen:**

> **Ein Label steht immer am `default`-Datensatz des Knotens — nie an einem `user`-Datensatz.**

*Dort liegen heute schon die Einstellungen, und [D-026](../../NewConcept/90-decision-log.md) begründet
es: «at model level there are no values, only defaults».*

⚠️ **Offen:** *ob dieser Satz reicht, oder ob «Label am Knoten» und «Daten am Datensatz» zwei zu
verschiedene Dinge sind, um sich eine Tabelle zu teilen. **Darüber denkt er nach.***

⚠️ *Und eine zweite Folge, falls es kommt: `locale` müsste auf dem Kanten-Datensatz **bleiben** — wir
hatten sie gestrichen, weil sie leer war, und sie war leer, weil die Labels noch nicht dort lagen.
Der Schlüssel würde dann `(node_record_id, relation_id, sort_order, locale)`.*

## INF-004 · Der Einstellungsbaum zeigt nur Kanten — er denkt noch darüber nach

**Am 2026-09-04 entschieden: «erstmal alles zeigen».** *Also **beide** Zeilenarten — die Kante und
der Knoten dahinter —, nicht die auf Kanten verkürzte Fassung. Das ist ausdrücklich vorläufig:
«erstmal». **Der Grund, es nicht gleich zu verkürzen: die kurze Fassung nimmt eine Auskunft weg,
die man erst vermisst, wenn man sie braucht** — welcher Knoten hinter einer Kante steht. Zeigt
sich im Betrieb, dass die Knotenzeilen nichts beitragen, fallen sie später; umgekehrt wäre es
teurer.*

⚠️ *Der Stand, den er sich angesehen hat:*

**2026-09-02, unentschieden.** Sein Satz: *«die Frage die sich mir stellt, wir könnten auch nur
die Kanten nehmen, das würde glaube ich reichen»* — und danach: *«merk dir das mal, da muss ich
nochmal drüber nachdenken».*

**Der Stand, bis er entscheidet:**

- Die Einstellungsmaske eines Knotens zeigt **eine Wurzelzeile** (der Knoten selbst) und darunter
  **nur Kantenzeilen**, geschachtelt.
- Die Knotenzeilen entfallen in der Anzeige, weil jeder Knoten über genau **eine** Kante erreicht
  wird. Damit gibt es je Schritt nur eine Zeile und keine Vorrangfrage.
- **Leer heisst geerbt:** sagt die Kantenzeile nichts, gilt der Zielknoten, dann der Rückfall. Der
  geerbte Wert steht blass an derselben Stelle; Tippen macht blass zu schwarz.
- «Alle `Text` sollen so aussehen» sagt er dann **auf dem Knoten `Text`**, wo dieser die Wurzel ist.
- **In den Daten bleibt die Abwechslung Kante/Knoten bestehen** — sie trägt die Adresse. Nur das
  Bild lässt die Knotenzeilen weg.

⚠️ **Offen ausserdem:** *die Tiefe hat kein Ende, und ein Knoten, der auf sich selbst zeigt, lässt
den Baum ewig laufen. Beides ungeklärt.*

Gehört zu [D-583](../../NewConcept/90-decision-log.md), TASK-021 und TASK-022.

## INF-006 · Was der 2026-09-04 an der Oberfläche geändert hat

**Kein Auftrag, sondern der Stand** — damit der nächste Leser die Maske wiedererkennt.

| | |
|---|---|
| **Auswahldialog** | wählt und schliesst sich; der gewählte Knoten steht danach in einem gesperrten Feld der Zeile, der Anlegen-Knopf daneben ([D-589](../../NewConcept/90-decision-log.md)) |
| **Baumansicht** | Suchfeld oben, in **jedem** Baum. In der Seitenansicht sucht der Server, im Dialog filtert der Browser |
| **Auswahlbaum** | zeichnet dieselbe Zeile wie der Modellbaum, ohne die Funktionen rechts, mit Klapp-Pfeilen |
| **Feld anlegen** | ohne Namen zieht es den Knotennamen; nur der Einstiegsast ist offen |
| **Feld ändern** | «Points at» ist über einen Dialog änderbar, für eigene Felder |

⚠️ **Zwei Stellen brauchen jetzt Skript, die vorher keines brauchten:** *der Auswahldialog — Klappen,
Wählen, Schliessen — und der Schalter «Knotennamen benutzen». **Die Seitenansicht bleibt skriptfrei**,
und ohne Skript bleibt jede Wahl gültig: sie reist über den Radioknopf im Formular.*

⚠️ **Was es kostet, gemessen an `Adresse`:** *176 KB Seite, 0,13 s, vier Auswahldialoge, 183
Baumzeilen. **Es wächst mit der Zahl der Felder**, weil jede eigene Zeile ihren eigenen Dialog trägt.*

## INF-007 · Eine Spalte plus Raum, oder zwei Spalten? — `package.md` §5a gegen TASK-005

**2026-09-04, beim Bauen von TASK-005 aufgefallen. Nicht entschieden, sondern erfasst (`PR-4`).**

TASK-005 sagt: *«`value_ref` bekommt eine Spalte für den Raum»* — so gebaut, `value_ref_kind` mit
`node` oder `record`, nach dem Muster `changelog.owner_kind`, wie [§6](package.md) es verlangt.

**Der Soll-Zustand in [§5a](package.md) sieht dagegen zwei getrennte Spalten vor** —
`value_node_record_id` **oder** `value_node_id`. Das ist dieselbe Aussage in einer anderen Form: dort
sagt der **Spaltenname** die Zieltabelle, hier eine zweite Spalte.

| | dafür | dagegen |
|---|---|---|
| **eine Spalte + Raum** | ein Index, eine Wertspalte mehr neben `value_int` … `value_date`; der Raum ist ein Datum wie jedes andere | zwei Spalten müssen zusammenpassen — der Wächter muss es prüfen |
| **zwei Spalten** | jeder Fremdschlüssel nennt seine Tabelle im Namen, echte Fremdschlüssel möglich | zwei nullbare Spalten, die einander ausschliessen müssen — auch das braucht einen Wächter |

⚠️ **Gebaut ist die erste Form, weil TASK-005 sie wörtlich verlangt und weil sie vor TASK-004 stehen
muss.** *Sie steht der zweiten nicht im Weg: eine spätere Aufteilung liest `value_ref_kind` und
verteilt — **was heute nicht ginge, weil der Raum nirgends stünde.** Die Frage stellt sich erst beim
Umbenennen auf `relation_records`.*
