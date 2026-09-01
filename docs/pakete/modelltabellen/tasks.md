# Paket · Datenbank — Arbeitsliste

**Neue Aufgaben werden hinten angehängt.** Der Status wird nach Erledigung nachgezogen.

⚠️ *Reihenfolge bei jedem Umzug: **Wächter, Leser, Daten** ([`arbeitsmodell.md`](../../arbeitsmodell.md) §5).
Eine Wanderung, bei der die Daten vorangehen, bleibt grün und zeigt still das Falsche.*

---

```text
[ ] TASK-001  path aus nodes entfernen
```

Beschlossen am 2026-09-01. **Die teuerste der vier**: `WpdbNodeRepository` fasst die Spalte an
**20 Stellen** an, und sie ist **indiziert** — der Vorfahrenweg muss danach aus `relations` kommen.
`path-check.php` prüft heute eigens diese Spalte und zieht mit.

```text
[ ] TASK-002  path aus record_values entfernen
```

**Spiegel von `edge_id`**: 183 Zeilen, und beide Spalten haben dieselben 12 verschiedenen Werte.
Vorher zu klären ist die Frage aus [`review-tabellen.md`](../../review-tabellen.md): bekommt eine
Komposition mit Multiplizität 1 immer ihren eigenen Datensatz?

```text
[ ] TASK-003  path aus labels und settings entfernen
```

**Beide nachweislich leer** — 0 von 47, 0 von 3. Die billigste der vier und der geeignete erste
Durchgang durch die Reihenfolge Wächter-Leser-Daten, bevor sie bei `nodes.path` teuer wird.

```text
[ ] TASK-004  identities streichen — JEDE Tabelle bekommt ihren eigenen Id-Raum
```

Die Tabelle hat genau eine Spalte, es zieht nichts um. **Nicht nur `nodes` und `relations`, sondern
jede Tabelle** — der Eigentümer: «jede Tabelle bekommt ihren eigenen Id-Raum … Records hatten dann
einen zweiten Nummernraum, das eliminieren wir jetzt.»

**Vorher muss TASK-005 stehen**, sonst wird `value_ref` mehrdeutig.

⚠️ *Eingereiht, nicht sofort — auf sein Wort: «das kommt noch, wir können nicht alles auf einmal
machen.»*

```text
[ ] TASK-005  Fremdschlüssel nennen ihre Zieltabelle; value_ref bekommt eine Spalte für den Raum
```

Heute zeigt `record_values.value_ref` auf **Knoten (49) und Datensätze (88)** — ohne dass etwas sagt,
worauf. Mit einem gemeinsamen Id-Raum ging das; mit eigenen Ids nicht mehr.
**`changelog.owner_kind` ist das Muster.** [D-164](../../NewConcept/90-decision-log.md) hat die
Abhilfe längst beschlossen und sie wurde nie gebaut.

```text
[ ] TASK-006  Identität erst vergeben, wenn die Zeile geschrieben wird
```

Gemessen: **65 593 vergebene Ids für rund 300 lebende Knoten und Kanten.** Offenbar wird eine Nummer
geholt, bevor feststeht, dass eine Zeile entsteht, und ein Fehlschlag gibt sie nicht zurück.
*Heute schadlos — aber es macht jede Zählung aus dieser Tabelle wertlos.*
⚠️ *Erledigt sich womöglich mit TASK-004 von selbst.*

---

## Wartet auf eine Entscheidung des Eigentümers

```text
[?] nodes.kind  — zweite Heimat neben relations.kind = setting?
[?] kind        — drei Spalten dieses Namens, drei Bedeutungen (CD-9)
[?] label_role  — OQ-134; solange offen, kann settings nicht fallen
```

---

```text
[ ] TASK-007  nodes.kind in field_type umbenennen
```

**Spalte `kind` → `field_type`, Wert `field` → `model`.** *Nach seiner Selbstkorrektur: «Entschuldigung,
Model und Settings, richtig.»*

**Trotzdem keine Datenwanderung, gemessen:** der Wert `field` steht in **keiner einzigen Zeile** —
gefüllt sind vier, alle `setting`.

Betroffen sind `NodeKind`, `Node`, `NodeRepository`, `Schema` und `node-kind-check`.
**Offen dabei:** 124 von 128 Knoten sagen heute nichts — ist «nichts» gleich `field`, oder muss es
dastehen?

```text
[ ] TASK-008  Spalte: welche PHP-Klasse setzt diesen Knoten um
```

**Entschieden am 2026-09-01: die Spalte trägt den Klassennamen, keine Factory.**
*Der Eigentümer: «wenn das ohne Factory geht, weil der Klassenname da drinsteht, perfekt.»*

**Dazu gehört der Wächter, und er ist Teil derselben Aufgabe:** eine Zeile, die eine Klasse nennt,
die es nicht gibt, wird rot. *Das ist der Ausgleich dafür, dass ein Klassenname die Daten an den
Code bindet — und es ist der eine Vorteil, den eine Marke nicht hätte.*

```text
[ ] TASK-009  Die 56 Optionen ablösen, die sich Knoten-Ids merken
```

Folgt aus TASK-008. **Gemessen: 56 WordPress-Optionen** halten heute die Bindung «welcher Knoten ist
der Int-Typ, welcher der Slider-Renderer» — `taxmod_type_int_id`, `taxmod_render_renderer_slider_id`
und vierundfünfzig weitere. **Sagt der Knoten es selbst, können sie fallen.**

⚠️ *Das ist eine eigene Aufgabe und kein Nebenbei: 56 Optionen zu entfernen heisst, jeden Leser
vorher umzuziehen — Reihenfolge Wächter, Leser, Daten.*

```text
[ ] TASK-010  from_id/to_id in from_node_id/to_node_id, Constraint auf nodes.id
```

*Der Eigentümer: «machen wir es eh eindeutiger … das ist eine Knoten-Id, da ist ein Constraint.»*

**Keine Umbenennung, sondern eine Verschärfung.** Die sieben heutigen Fremdschlüssel zeigen **alle**
auf `identities.id` — die Bedingung erlaubt strukturell eine Kante, die von einem **Datensatz**
ausgeht. Gehört zu TASK-004, weil `identities` dabei ohnehin fällt.

```text
[ ] TASK-011  relations.kind in type umbenennen
```

Gleiche Bewegung wie TASK-007 am Knoten. **Vier Klassen braucht es nicht:** der Code verzweigt an 19
Stellen auf die Kantenart, 15 davon fragen nur «ist es Vererbung?».

```text
[ ] TASK-012  position in order umbenennen, Schluessel (from_node_id, type, order)
```

**Entschieden: die erste Stelle ist `0`, gezählt je `from_node_id` und je `type`.**
*Der Eigentümer: «Position würde ich eher Order nennen. Und die erste Position ist immer null … die
Sort Order entsteht pro Knoten, und zwar dem From-Knoten.»*

⚠️ **Meine gemeldeten «17 doppelten Reihenfolgen» waren keine.** *Alle 8 Gruppen mischen
Kantenarten — Kind im Baum gegen Feld des Knotens. **Nicht eine Doppelung liegt innerhalb derselben
Art.** Es sind zwei Listen in einer Spalte, und beide sind für sich in Ordnung. **Mein Vorschlag
eines Schlüssels auf `(from_node_id, order)` hätte 17 gültige Zeilen abgelehnt.***

**Zu bereinigen gibt es nichts.** Zu bauen ist die Umbenennung und der Schlüssel über drei Spalten.
