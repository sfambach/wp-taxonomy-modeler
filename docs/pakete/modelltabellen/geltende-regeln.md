# Was heute gilt — gemessen, nicht erinnert

**Stand 2026-09-04.** *Auf seine Bitte: «wir verlieren die klaren Regeln, glaube ich» — und im August
zweimal davor: «bitte aufräumen».*

**Diese Seite entscheidet nichts.** Sie sagt, was am 2026-09-04 in den Tabellen steht und im Kode
gefragt wird. **Jede Zahl ist an diesem Tag gemessen.** Wo Konzept und Bestand auseinandergehen,
steht es hier als Widerspruch und nicht als Absicht.

---

## 1 · Die vier Tabellen, wie sie heute wirklich aussehen

```text
nodes           id · version · name · path · kind
relations       id · version · from_id · to_id · kind · name · position
                parked_by_group_id · hide · multiplicity
records         id · node_id · node_version · created_at · kind · version
record_values   id · record_id · edge_id · path · locale
                value_int · value_decimal · value_text · value_date
                value_ref · value_ref_kind · position · version

133 Knoten · 199 Kanten · 378 Datensaetze · 77 Wertzeilen
```

⚠️ *`identities` und `settings` sind heute gestrichen ([D-579](../../NewConcept/90-decision-log.md),
TASK-004). Die Namen im Soll — `node_records`, `relation_records`, `field_type`, `sort_order`,
`relation_type` — **gibt es noch nicht**; die Umbenennungen stehen als TASK-007, 012, 014, 015.*

⚠️ **`settings_record_id` steht hier nicht mehr, an keiner der beiden Tabellen** (TASK-057,
Schemafassung 32). *Der Renderer hängt an einer gewöhnlichen Einstellungskante `1..1` am Knoten
([D-642](../../NewConcept/90-decision-log.md)); an der Kante ist er ersatzlos gefallen
([D-643](../../NewConcept/90-decision-log.md)), weil beide Spalten dort je 0 Zeilen trugen.*

---

## 2 · Die eine Frage, die zählt

> **Wo liegt der Wert?**

Es gibt genau drei Antworten, und alle drei stehen in den Daten:

| | woran man es sieht | gemessen |
|---|---|---|
| **in der Wertzeile** | der Knoten hat einen einfachen Typ | 52 von 77 Wertzeilen |
| **als Verweis** | `value_ref` gefüllt, `value_ref_kind` sagt worauf | 25 von 77 |
| **in einem eigenen Satz** | der Knoten hat eigene Felder | über `value_ref_kind = record` |

⚠️ **Alles andere ist Darstellung und gehört dem Renderer** — *sein Einwand, und er trägt: «ein
Knoten, der Kinder hat, muss ich einen Renderer geben, der es ermöglicht, diesen Kindknoten erst
auszuwählen und dann Daten einzugeben». **«Auswahl» ist damit keine Eigenschaft des Knotens.***

---

## 3 · Wer das heute entscheidet — und das ist der Widerspruch

**Nicht der Knoten. Der Ast.**

```text
Rendering::typeOf()
   Ast ist Constants    →  Auswahl
   Ast ist Data Types   →  der Typ des Knotens
   sonst                →  nichts, und das Feld ist gesperrt
```

**Der Ast wird an 17 Stellen in 6 Dateien gefragt.** `Branch` ist eine Aufzählung von fünf Werten im
Kern — `Model`, `Compositions`, `Data Types`, `Constants`, `Settings`.

⚠️ **Was daran heute klemmt, an seinem eigenen Modell gemessen:** *`Combined` liegt unter
`Primitives`, neben `Data Types` und `Constants`. **`Primitives` ist kein Ast**, also liegt `Combined`
in keinem — und `Street / H#` ist im Auswahldialog gesperrt. **Nicht als Regel, sondern als Folge:**
ohne Ast kein Typ, ohne Typ kein Renderer und keine Wertspalte.*

---

## 4 · Was der Knoten selbst wüsste

Gemessen, indem dieselbe Frage nur aus dem Knoten beantwortet wird — *hat er einen Typ, hat er eigene
Felder, hat er Kinder*:

```text
Ast heute        was der Knoten selbst sagen wuerde

data-types       11 Wert in der Zeile   ✓   1 Auswahl · 3 nichts
compositions      9 eigener Datensatz   ✓   1 Auswahl
constants         6 Auswahl             ✓  38 nichts   ← genauer als der Ast
settings          7 Auswahl · 30 nichts ✓
model            10 nichts · 5 Auswahl · 5 eigener Datensatz   ✗
```

**Bei `Constants` ist die Knotenfrage schärfer als der Ast:** *heute sagt der Ast pauschal «Auswahl»,
auch für `kilo`. Der Knoten sagt: `Prefixes` ist die Auswahl, `kilo` ein Wert darin.*

**`Model` geht nicht auf.** *Er antwortet `ExternalReference`, und das folgt aus keinem Merkmal des
Knotens. Es ist der Fall, den er selbst abgegrenzt hat: «ganze Zeilentypen, ganze Tabellen — da würde
ich darauf bestehen, dass es extern ist.» **Die Angabe dafür gibt es seit heute:
«Gehört zu»** ([D-592](../../NewConcept/90-decision-log.md)).*

---

## 5 · Wo eine Auskunft zweimal steht

| | | |
|---|---|---|
| **die Typbindung** | 11 WordPress-Optionen halten «welcher Knoten ist `int`» | gegen `AR-1`; die Spalte ist beschlossen (§3.2, TASK-008) |
| **die Einstellungsmarke** | `nodes.field_type` an 3 Knoten, `relations.kind` an 11 Kanten | zwei Zählungen derselben Sache ([D-606](../../NewConcept/90-decision-log.md)) |
| **`relation_type`** | sagt, was der Ast des Ziels auch sagt | fällt ([D-587](../../NewConcept/90-decision-log.md)) |
| **`before_state`** | trägt als Text, was die Schattenzeile in Spalten hat | fällt ([D-601](../../NewConcept/90-decision-log.md)) |

---

## 6 · Was heute rot ist, und warum

**9 Wächter**, drei Ursachen:

```text
20 Fehlschlaege   fragen nach den Kanten 44091/44093 — geloescht mit DisplayOption
28 Waisen         Datensaetze aus abgestuerzten Waechterlaeufen
 3 Reste          in Satz 2233, bewusst stehengelassen
```

⚠️ *Kern: **424 grün.** Keiner der neun meldet einen Fehler im Modell — alle neun melden, dass eine
Zusage auf eine Adresse zeigt, die es nicht mehr gibt.*

---

## 7 · Die Regeln, die wirklich tragen

Nach allem Gemessenen bleiben **vier** Sätze übrig. Alles andere ist Folge:

1. **Ein Wert liegt in der Zeile, in einem eigenen Satz oder als Verweis.** Sonst nirgends.
2. **Was ein Ding *ist*, sagt das Ding — nicht der Ort.** *Die Marke zieht mit, der Ast nicht
   ([D-606](../../NewConcept/90-decision-log.md)).*
3. **Näher schlägt ferner.** *Die Auflösung ([D-602](../../NewConcept/90-decision-log.md)), der
   Klapp-Pfeil ([D-612](../../NewConcept/90-decision-log.md)), der Zweigkopf — dreimal dieselbe Regel.*
4. **Wie etwas aussieht, entscheidet der Renderer.** *Nicht der Ast, nicht die Tabelle.*

⚠️ **Und eine über das Vorgehen, an einem Tag viermal belegt:** *eine Regel gilt erst, wenn ihre
Grundlage gezählt ist. **Vier meiner Vorschläge sind heute an einer Messung gescheitert**, die ich
vor dem Vorschlag hätte machen können — «Ziel einer Einstellungskante» (traf `Boolean` mit), «die
Marke» (gibt es an 2 von 38), «markierte erben nicht» (hätte dem Renderer den `converter` genommen),
«bei Namensgleichheit» (war aus zwei Zeilen Müll abgeleitet). **Jedes Mal hat sein Blick es gefunden,
nicht mein Lauf.***
