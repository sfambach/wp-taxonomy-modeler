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
[x] TASK-004  identities streichen — JEDE Tabelle bekommt ihren eigenen Id-Raum
```

Die Tabelle hat genau eine Spalte, es zieht nichts um. **Nicht nur `nodes` und `relations`, sondern
jede Tabelle** — der Eigentümer: «jede Tabelle bekommt ihren eigenen Id-Raum … Records hatten dann
einen zweiten Nummernraum, das eliminieren wir jetzt.»

**Vorher muss TASK-005 stehen**, sonst wird `value_ref` mehrdeutig.

⚠️ *Eingereiht, nicht sofort — auf sein Wort: «das kommt noch, wir können nicht alles auf einmal
machen.»*

**Gebaut am 2026-09-04, Schema 21.** `identities` ist gelöscht; `nodes` und `relations` vergeben ihre
Ids aus ihrem eigenen `AUTO_INCREMENT`. **Nichts wurde umnummeriert** — beide Räume beginnen über
`79 755`, dem Höchstwert des gemeinsamen Raums, damit keine Nummer ein zweites Mal vergeben wird
([D-340](../../NewConcept/90-decision-log.md)). Der Kern kennt keinen Id-Vergeber mehr: `add()` gibt
die geschriebene Zeile mit ihrer Nummer zurück, **`0` heisst «vergib eine»**.

⚠️ **Die sieben Fremdschlüssel auf `identities.id` fallen ersatzlos, und das ist eine Entscheidung:**
*die Bedingungen auf `nodes.id` setzt **TASK-010**, wo auch die Umbenennung steht; sie hier zu setzen
hiesse, `ON DELETE RESTRICT` gegen ungeprüfte Aufräumwege zu stellen. Bis dahin hält
[`id-space-check.php`](../../../scripts/dev/id-space-check.php) dieselbe Zusage lesend — **gemessen:
null Waisen in allen sieben Spalten.***

⚠️ **Zwei Behelfe blieben, beide im Eingang und beide bewacht.** *`INF-008`: die
Installationsidentität hat keinen Raum mehr, aus dem sie ziehen könnte — auf einer frischen
Installation ist `1` reserviert. **`INF-009`: `settings.owner_id` nennt ihren Raum nicht** und zeigt
gemessen auf 3 Knoten und 10 Kanten; ohne Abstand zwischen den Räumen hielt `Settings` eine Kante für
einen Knoten, und `package4-check` brach daran ab. **Der Kantenraum beginnt darum eine Milliarde über
dem Knotenraum, bis die Spalte ihren Raum nennt.***

```text
[x] TASK-005  Fremdschlüssel nennen ihre Zieltabelle; value_ref bekommt eine Spalte für den Raum
```

Heute zeigt `record_values.value_ref` auf **Knoten (49) und Datensätze (88)** — ohne dass etwas sagt,
worauf. Mit einem gemeinsamen Id-Raum ging das; mit eigenen Ids nicht mehr.
**`changelog.owner_kind` ist das Muster.** [D-164](../../NewConcept/90-decision-log.md) hat die
Abhilfe längst beschlossen und sie wurde nie gebaut.

**Gebaut am 2026-09-04, Schema 20.** Die Spalte heisst `value_ref_kind` und trägt `node` oder
`record`; im Kern sagt es [`ReferenceSpace`](../../../src/Core/Model/ReferenceSpace.php), und
`TypedValue::ofRecordReference()` steht neben `ofReference()`. **Der einzige Schreiber eines
Datensatzverweises im Kern ist `DataEntry::createPart()`** — alles andere meint einen Knoten.

⚠️ **Nachgemessen vor dem Umbau: 143 Verweise, 50 auf Knoten, 93 auf Datensätze, keine
Überschneidung** — die Zahlen oben stammen von einem früheren Stand. *Die Wanderung konnte deshalb
jede lebende Zeile eindeutig benennen; **nach TASK-004 wäre genau das nicht mehr gegangen**.*

⚠️ **Die Schattentabelle bleibt lückenhaft, und das ist gemessen statt hingenommen:** *von 810
Verweisen alter Fassungen lassen sich 393 auf einen Knoten und 58 auf einen Datensatz auflösen,
**359 zeigen auf nichts Lebendes mehr oder wären mehrdeutig**. Geraten wird nicht (`PR-4`) — der
Wächter [`value-ref-space-check.php`](../../../scripts/dev/value-ref-space-check.php) verlangt die
Angabe deshalb von den lebenden Zeilen und **zählt** die Lücke im Schatten.*

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

⚠️ **TASK-011 ist gestrichen** (2026-09-04, auf sein Wort: *«TASK-011 löschen»*). *Sie hätte
`relations.kind` in `relation_type` umbenannt — **und [D-587](../../NewConcept/90-decision-log.md)
streicht die Spalte ganz.** Erst umbenennen und dann löschen ist zweimal Arbeit an derselben
Spalte; nach TASK-018 trägt sie ohnehin nur noch 39 von 166 Zeilen. **Die Reihenfolge ist damit
TASK-018 → TASK-032**, nicht TASK-011 → TASK-032.*

⚠️ *Die Beobachtung dahinter bleibt gültig und gehört zu TASK-032: der Code verzweigt an 19
Stellen auf die Kantenart, **15 davon fragen nur «ist es Vererbung?»** — und genau die Frage
verschwindet mit TASK-018.*

```text
[ ] TASK-012  position in sort_order umbenennen, Schluessel (from_node_id, relation_type, sort_order)
```

**Entschieden: die erste Stelle ist `0`, gezählt je `from_node_id` und je `type`.**
*Der Eigentümer: «Position würde ich eher Order nennen. Und die erste Position ist immer null … die
Sort Order entsteht pro Knoten, und zwar dem From-Knoten.»*

⚠️ **Meine gemeldeten «17 doppelten Reihenfolgen» waren keine.** *Alle 8 Gruppen mischen
Kantenarten — Kind im Baum gegen Feld des Knotens. **Nicht eine Doppelung liegt innerhalb derselben
Art.** Es sind zwei Listen in einer Spalte, und beide sind für sich in Ordnung. **Mein Vorschlag
eines Schlüssels auf `(from_node_id, sort_order)` hätte 17 gültige Zeilen abgelehnt.***

**Zu bauen:** die Umbenennung, der eindeutige Schlüssel über drei Spalten — der zugleich als
Suchindex dient und den heutigen Einzelindex auf `from_id` überflüssig macht.

⚠️ **Und eine echte Doppelung muss vorher weg.** *Gemessen: ohne `type` sind es 8 Verletzungen, **mit
`relation_type` genau eine** — Knoten 55659 «render with label» hat zwei Einstellungskanten auf Stelle 0,
`label_role` und `with_label`. **Meine Zusage «zu bereinigen gibt es nichts» war vorschnell.***

```text
[ ] TASK-013  parked_by_group_id aus relations entfernen; Parken wandert in den Schatten
```

[D-575](../../NewConcept/90-decision-log.md), wörtlich von ihm: *«Parken heisst: in die
Schattentabelle wandern, mit der Änderungsgruppe im Gepäck.»*

**Reihenfolge Wächter, Leser, Daten** — und die Leser sind hier viele: **23 Stellen in 12 Dateien**
erwähnen «geparkt», darunter `ModelEditor` 18, `WpdbRelationRepository` 12, `NodesScreen` 11.

⚠️ *Drei bis vier Ansichten brauchen danach eine zweite Abfrage, um Gelöschtes zu zeigen
([D-128](../../NewConcept/90-decision-log.md)s Umschalter). **Das ist der genannte Preis** — gegen
23 Stellen, die heute etwas vergessen können.*

```text
[ ] TASK-014  records -> node_records, record_values -> relation_records
```

*Der Eigentümer: «records → node_records, record_values → relation_records».* **Die Zuordnung ist
gemessen und ausnahmslos** — 209 von 209, 183 von 183.

**Dazu gehören zwei Spalten, nach derselben Regel wie `from_node_id`:**
`record_id` → **`node_record_id`**, `edge_id` → **`relation_id`**.

⚠️ **Vorher zu entscheiden: «edge» oder «relation»?** *Gemessen im Quelltext: **edge 1080-mal,
relation 407-mal.** Die Tabelle heisst `relations`, der Code sagt überwiegend `edge`, und sein
deutsches Wort ist **Kante** — was näher an «edge» liegt. **Zwei Wörter für eine Sache verbietet
`CD-9`; welches bleibt, ist offen.** Erst die Wortwahl, dann die Spaltennamen.*

```text
[ ] TASK-015  node_records: kind -> record_type, version unter id, created_at faellt
```

*Der Eigentümer: «`kind` → `type` umbenennen, `version` würde ich nach oben unter `id` packen. Das
`created_at` ist eigentlich was fürs Log.»*

⚠️ **`created_at` fällt erst, wenn das Änderungsbuch Datensätze führt.** *Gemessen: **null Einträge
mit `owner_kind = 'record'`** — das Log protokolliert Knoten und Kanten, Datensätze nicht als eigene
Art. **Erst der neue Leser, dann die Daten** (`PR-12`).*

⚠️ *Die Frage «`type` oder `record_type`» ist entschieden: **`record_type`.** Der Eigentümer:
«Records — gleiche Handhabung wie Knoten und Kanten.» Damit heissen alle drei qualifiziert:
`field_type`, `relation_type`, `record_type`.*

```text
[?] node_version bei Versionskonflikt — bewusst zurückgestellt
```

*«Das können wir erstmal so lassen.»* **Seine Vermutung ist bestätigt:** `node_version` wird
geschrieben, angezeigt und **nirgends verglichen**. 29 von 209 Datensätzen sind älter als ihr Knoten.

```text
[ ] TASK-016  «edge» im Quelltext durch «relation» ersetzen
```

[D-576](../../NewConcept/90-decision-log.md). **Nicht Teil der Tabellenbenennung** — eigenes
Arbeitsstück.

**Gemessen: 699 Bezeichner in 26 Dateien**, dazu 381 Nennungen in Kommentaren. **Vier Dateien tragen
79 %:** `ModelEditor` 180, `Rendering` 149, `NodesScreen` 123, `DataEntry` 98. Dazu `EdgeRecord` →
`RelationRecord`.

⚠️ *Kein Feldzug mit einem regulären Ausdruck — siehe [`bekannte-fallen.md`](../../bekannte-fallen.md).*

```text
[x] TASK-017  settings streichen — Tabelle und Code
```

[D-579](../../NewConcept/90-decision-log.md), auf sein Wort: *«settings bitte rausschmeissen»*, und
zur Abwägung Umzug gegen Neueingabe: *«B»*.

**Was mitgeht:** die Tabelle, `WpdbSettingRepository`, die Leser in `SettingsScreen`, die Einträge in
`Schema` — und `path-check.php`, das eigens `settings.path` prüft.

⚠️ **Drei Werte gehen verloren:** `label_role = symbol` an `prefix`, `prefix (Kopie)` und `einheit`,
alle von `Einheitenwert`. **Bis `label_role` seinen neuen Ort hat (`OQ-134`), zeigt `Einheitenwert`
den langen Namen statt des Zeichens** — «Kiloohm» statt «kΩ». Kein Fehler, umkehrbar, und bewusst in
Kauf genommen.

**Gebaut am 2026-09-04, Schema 22.** **Gemessen vor dem Streichen: 13 Zeilen**, nicht drei — die drei
`label_role` an Kanten und dazu **zehn `read_only`** (eine am Wurzelknoten, zwei an Knoten, sieben an
Kanten). *Die zehn hat niemand gelesen: gemessen an der geleerten Tabelle wurden **nur die drei
`label_role`** irgendwo bemerkt — zwei Wächter, und beide genau an der Stelle, die D-579 benannt hat.*

**Was gefallen ist:** die Tabelle, `WpdbSettingRepository`, `SettingRepository`, `SettingRecord`, der
`Settings`-Dienst (768 Zeilen), `InMemorySettings`, `SettingsTest` — dazu die Materialisierung nach
[D-423](../../NewConcept/90-decision-log.md), `ModelEditor::copySettings()`, der Schreiber der
Einstellungstafel in `NodesScreen`, die Zeilen-Akte `empty_setting`/`reset_setting`, die
Grenzen-Saat in `BaseScaffold` und die Waisenquelle auf der Aufräumseite. *`SettingsScreen` bleibt —
sie schreibt WordPress-Optionen und hat die Tabelle nie angefasst; D-579s Satz «die Leser in
`SettingsScreen`» geht ins Leere.*

⚠️ **Der Milliarden-Abstand aus TASK-004 ist weg** (`INF-009` erledigt): *er hing an
`settings.owner_id`, die ihren Raum nicht nannte. Beide Räume beginnen wieder dort, wo der gemeinsame
aufgehört hat; **umnummeriert wurde nichts**, `AUTO_INCREMENT` lässt sich nicht nach unten setzen.
`id-space-check.php` verlangt die Trennung nicht mehr, sondern zählt sie — gemessen **1** Nummer, die
zugleich Knoten und Kante ist, und `package.md` §6 will genau das.*

⚠️ **Drei Wächter sind gelöscht, weil ihr Gegenstand fort ist**: `path-check.php` (prüfte
`settings.path`), `settings-screen-check.php`, `materialise-check.php` samt `materialise-backfill.php`
— und `package4-check.php`, dessen ganzer Gegenstand «Einstellungen und die Kette» war. *Elf weitere
sind mitgezogen, jeder mit der Begründung im Text (`PR-9`).*

⚠️ **Drei Befunde blieben offen und stehen im Eingang:** *`INF-010` — eine Einstellung **erbt heute
nicht** mehr, weil die Kette mit der Tabelle ging; `INF-011` — es gibt **keinen Schreiber** für eine
Einstellung an einer Verwendungsstelle; `INF-012` — **niemand prüft mehr**, dass eine Grenze nur enger
wird ([D-312](../../NewConcept/90-decision-log.md)). **Zwei Kerntests und ein Abschnitt von
`package7-check` tragen den ersten Befund sichtbar als `markTestIncomplete`** statt gelöscht zu sein.*

```text
[ ] TASK-018  Vererbung wird nodes.parent_node_id + nodes.sort_order
```

[D-581](../../NewConcept/90-decision-log.md). **`relations` fällt von 166 auf 39 Zeilen.** Betroffen
ist alles, was heute «ist es Vererbung?» fragt — 15 von 19 Verzweigungen auf `RelationKind`.

```text
[ ] TASK-019  labels und label_texts; name zieht aus nodes und relations hinein
```

[D-580](../../NewConcept/90-decision-log.md). **Grösster Einzelposten:** `nodes.name` ist indiziert und
wird an vielen Stellen gelesen. Reihenfolge `PR-12` — Wächter, Leser, Daten.

⚠️ *Offen davor: sind die vier Rollen Spalten in `label_texts` oder Zeilen mit `role_id`?*

```text
[?] hide — vertagt bis zum Settings-Umbau
```

*Der Eigentümer: «`hide` ist eigentlich eine Einstellung am Modell», und dazu: «das besprechen wir,
wenn wir Settings nochmal umwerfen».* **[D-457](../../NewConcept/90-decision-log.md) sagt das
Gegenteil** — «a column on a node and a column on an edge, and **never** a chain-resolved setting» —
**und sie entstand, weil sein Wort «Attribut» als «Setting» gelesen wurde.** Nicht aufgelöst.

```text
[x] TASK-020  settings_record_id an nodes und relations
```

**2026-09-04 in Betrieb genommen.** *28 Halter sind aus der Wertzeile an der toten Kante `44093` in
`nodes.settings_record_id` gezogen; der Leser ({@see ModelValues}) fragt die Spalte vor der Kante,
der Schreiber ({@see DataEntry::chooseSettingRecordAtNode()}) füllt sie, und
`settings-record-column-check.php` bewacht beide Richtungen.* **Was stehenblieb — der Halter der
Wurzel und die drei Reste in Satz `2233` — steht in `INF-016`.**

[D-582](../../NewConcept/90-decision-log.md). **Damit kann eine Kante die Einstellungen ihres
Zielknotens überschreiben** — ohne Kante-zu-Kante-Beziehung und ohne Überschreiben-Konstrukt.

**Die Auflösung wird zweistufig:** Einstellungsdatensatz der Kante → der des Zielknotens → Rückfall.
Der Code kennt die Verwendungsstelle schon: **`UseSite` kommt an 17 Stellen vor**, acht davon in
`Rendering.php`. **Was fehlte, war der Ort, an dem so ein Wert liegt** — seit `settings.path` weg ist.

⚠️ *Nur die Einstellungskante, die den **Weg zum Behälter** beschreibt, wird dadurch überflüssig —
drei von elf. Felder im Behälter und Einstellungen direkt am Knoten bleiben Kanten.*

```text
[x] TASK-021  Die Renderer-Wahl setzt den Knoten eines vorhandenen Datensatzes
[x] TASK-022  Die Einstellungsmaske zeigt den Unterbaum und haengt die Renderer-
              Felder rechts an derselben Zeile an
```

[D-583](../../NewConcept/90-decision-log.md). **Gemessen am 2026-09-02: es gibt keinen einzigen
Datensatzverweis ausser auf `DisplayOption`, und die Maske zeichnet genau eine Ebene.**

**TASK-021** ist die kleinere: heute entsteht bei der Wahl ein Knotenverweis, künftig trägt die
schon vorhandene Zeile den gewählten Knoten. **TASK-022** ist die sichtbare — `Kontakt` muss die
Renderer von `Street`, `No.`, `Post Code`, `City` und `Country` zeigen, damit sie dort
überschrieben werden können.

⚠️ *Der Eigentümer hat beides gesehen, bevor es gemessen war. Die Messung hat seinen Satz
bestätigt, nicht geprüft.*

```text
[x] TASK-023  Basisknoten «Renderer» mit der Einstellung converter, alle
              Renderer erben von ihm
[ ] TASK-024  Huellknoten DisplayOption abschaffen — 29 Datensaetze,
              32 Wertzeilen: umhaengen oder wegwerfen ist zu entscheiden
```

[D-584](../../NewConcept/90-decision-log.md), [D-585](../../NewConcept/90-decision-log.md).

**TASK-024 ist am 2026-09-04 entschieden: umziehen** ([D-594](../../NewConcept/90-decision-log.md)).
*Gemessen sind es **29 Datensätze mit Inhalt und 125 leere** — die leeren fallen ersatzlos, die 29
Renderer-Wahlen ziehen um, die 3 Konverter werden Werte am geerbten `converter`-Feld. Der Verweis,
der schon dasteht, wird zur `node_id` des Datensatzes.*

⚠️ *Der alte Text stand hier und war die offene Frage:* Dieselbe Abwägung wie bei `settings`
([D-579](../../NewConcept/90-decision-log.md)), wo er «neu eingeben» gewählt hat — dort waren es
drei Zeilen, hier sind es 32.

```text
[x] TASK-025  Waechter von seinen Knotennamen loesen — eigene Knoten anlegen,
              pruefen, wegraeumen
```

**Der Eigentümer hat es gefunden:** *«warum haben wir einen Check auf Adresse, ich hatte das mal so
angelegt, aber das war kein Vertrag».* Und `CLAUDE.md` verbietet es ausdrücklich — *«Special-casing
by display name, label, path, or a specific node»*.

**Gemessen am 2026-09-04:** `composition-check`, `multiplicity-check` und `page-blocks-check` suchen
`Adresse`, `Dimension`, `Zutat`, `Backrezept` und `Einheitenwert` beim Namen — **und legen sie an,
wenn sie fehlen.** Ein Wächter, der Beispielknoten in sein Arbeitsmodell schreibt.

⚠️ *Vorbild ist `package3-check`: eigene Knoten mit eigenem Namensraum, geprüft, weggeräumt.*

**TASK-021 bis TASK-023 erledigt am 2026-09-04.** Die Maske zeigt am gewählten Renderer jetzt
`orientation`, `with_label`, `label_role` und den geerbten `converter` — neben dem Auswahlkasten,
nicht statt seiner.

⚠️ **Drei Stellen mussten dafür weichen, und alle drei stammten aus derselben Annahme** — *ein
Knotenverweis hat keine Felder*: der Abstieg brach bei jedem Typ ab, die Teile eines Teils wurden
nicht mitgegeben, und die Felder wurden am Kantenziel statt am gewählten Knoten geholt.

---

## Seine Liste vom 2026-09-04

```text
[x] TASK-026  Fields/Anlegen: ohne Namen wird der Knotenname benutzt — mit
              Schalter, und die Beschriftung zieht mit
[x] TASK-027  Fields/Anlegen: Vorauswahl im Typbaum sind die Simple Types
[x] TASK-028  NodeChooser ist ein reiner Auswahldialog — waehlen, bestaetigen,
              fertig; der Anlegen-Knopf bleibt in der Zeile
[x] TASK-029  Fields/Bestand: «Points at» ist der Typ und muss aenderbar sein,
              Pflichtfeld ohne leere Wahl
[x] TASK-030  TreeChooser heisst NodeChooser — er waehlt einen Knoten, keinen
              Baum
[x] TASK-031  NodeChooser bekommt oben ein Such-/Filterfeld
[ ] TASK-032  Komposition und Aggregation werden dieselbe Ablage; ein Schalter
              am Feld sagt nur, ob die Daten mitgeloescht werden
```

**INF-005 · Er hat `city` und `street` von Simple nach Combined verschoben** (2026-09-04). *Kein
Auftrag, sondern der Stand — wird hier vermerkt, weil Wächter daran hängen können (TASK-025).*

⚠️ **Zu TASK-026, seine Beschreibung:** *«wenn kein Name eingegeben wird, wird der Knotenname
verwendet, die Frage ist, was mit Labels ist — die sollten dann auch aus dem Knoten verwendet
werden, somit müsste `label_id` von Kante auf den gleichen zeigen wie Knoten. Evtl. sollte man das
mit Schalter machen … Schalter umschalten, Feld wird geöffnet, Name steht noch da, aber beim
Speichern wird eigenes Label angezeigt.»*

⚠️ **Zu TASK-032, seine Beschreibung:** *«Composition nur noch Knoten ohne Funktion. Benutzer wählt
am Feld, ob Verbindung eine Composition oder eine Aggregation ist, aber er sagt nur: Schalter wird
mit Knoten-Daten gelöscht ⇒ Composition, sonst Aggregation. Alle Daten werden gleich abgelegt für
Composition und Aggregation. Nur simple Werte werden direkt geschrieben.»*

⚠️ *Punkt 3 seiner Liste ist leer geblieben — offen, was dort stehen sollte.*

**Seine Antworten vom 2026-09-04:**

| | |
|---|---|
| **Punkt 3** | *«ignorieren»* — bleibt leer |
| **TASK-026** | Der Schalter steht auf **«Knotennamen benutzen»** |
| **TASK-029** | *«haben wir ein Konzept für: sollten keine Daten da sein, einfach ändern; wenn Daten da sind, neue Version und Konflikt»* — die Schattentabellen tragen das schon |
| **TASK-032** | *«dann vereinfachen wir und haben nur noch eine Relation und Feld das mit löschen»* — [D-587](../../NewConcept/90-decision-log.md) |

⚠️ **TASK-032 ist damit grösser als die anderen:** *`relation_type` fällt ganz, nicht nur die
Unterscheidung Komposition/Aggregation. Was von `setting` übrig ist, trägt `record_type`
([D-583](../../NewConcept/90-decision-log.md)); was ein Ziel ist, sagt sein Ast.*

```text
[ ] TASK-033  Ein Auswahl-Renderer statt zwei — dialog/inline wird eine
              Einstellung an ihm
[x] TASK-034  Das Suchfeld gehoert als Einstellung an den Auswahl-Renderer,
              nicht fest in den Dialog gebaut
```

**Sein Wort:** *«der Renderer soll eigentlich den Dialog-Chooser nur verwenden, weil dieser auch an
anderer Stelle nicht nur im Renderer verwendet wird. Ich würde auch sagen, der Renderer hat ein
Setting dialog/inline.»*

⚠️ **Und wann `inline` überhaupt Sinn ergibt, hat er gleich mitgesagt:** *«nur wenn wir einen Knoten
haben, der nur eine Ebene hat, also Knoten mit Kindern die alle Blätter sind — ansonsten erscheint
beim Aufklappen wieder [ein Baum].»*

⚠️ *Heute sind es zwei Renderer-Knoten, `chooser-dialog` (43499) und `chooser-inline` (43501). Sie
werden einer, und der Unterschied wird ein Feld — dieselbe Bewegung wie bei
[D-585](../../NewConcept/90-decision-log.md), wo `DisplayOption` fiel.*

**TASK-034 anders gelöst als notiert, auf sein Wort:** *«das Suchfeld sollten wir in die Baumansicht
integrieren, kann auch in tax config Sinn ergeben zu filtern»* — bestätigt mit *«genau so»*.

⚠️ *Es ist damit **keine** Einstellung am Auswahl-Renderer geworden, sondern Teil des Baumes selbst.
Das ist der bessere Ort: die lange Liste steht in der Seitenansicht, und der Dialog bekommt es
umsonst mit, weil beide denselben Baum zeichnen. Gemessen: drei Bäume auf der Seite, drei Suchfelder.*

⚠️ **TASK-027 anders gelöst, und die erste Lösung war falsch.** *Ich hatte den Ast der einfachen
Typen nach oben **sortiert** — damit stand er nicht mehr unter `Primitives`, und der Dialog zeigte
eine andere Hierarchie als die Seitenansicht. Er hat es sofort gesehen: «das ist falsch». Jetzt ist
alles **zugeklappt ausser dem Einstiegsast**, auf sein Wort: «aufgeklappt werden soll nur der
Einstiegsast … bekommt optionaler Default-Knoten, Einstiegsast und Root-Knoten als Parameter».
Gemessen: der Dialog zeigt 30 statt 134 Zeilen.*

```text
[x] TASK-035  Klapp-Pfeile im Auswahldialog — heute fehlt «toggle», weil ein
              Klapp-Link die Seite neu laedt und damit den Dialog schliesst
[x] TASK-036  Einstiegsast und Root als Parameter des Auswahldialogs, nicht
              im Feldformular ausgerechnet
```

**TASK-036 erledigt am 2026-09-04, und die Suche im Modellbaum dazu.**

⚠️ **Sein Einwand war berechtigt:** *«ausserdem hast du nicht das gemacht was besprochen war, wir
haben ein Konzept für den Knoten-Chooser: Root-Knoten, optional Ast der [aufgeklappt] ist, optional
Knoten der vorselektiert ist.»* *Ich hatte den Klappzustand im Feldformular ausgerechnet — der
nächste Aufrufer hätte es noch einmal getan, und die zweite Rechnung wäre irgendwann anders
ausgefallen. Jetzt trägt `Rendering::nodeChooser()` das Konzept.*

⚠️ **Und «knoten filter geht nicht» hatte einen Grund, den kein Skript beheben kann:** *die
Seitenansicht ist zugeklappt — 11 von 145 Zeilen stehen im Dokument. **Was nicht dasteht, findet
kein Skript.** Deshalb sucht dort jetzt der Server: voll aufklappen, auf Treffer plus deren Weg
eindampfen. Der Auswahldialog behält den Browserfilter, weil er alle Zeilen hat.*

⚠️ **TASK-026 zur Hälfte erledigt am 2026-09-04.** *Der **Name** zieht aus dem Zielknoten, der
Schalter steht auf «Knotennamen benutzen», und das Feld wird gesperrt statt geleert — «Schalter
umschalten, Feld wird geöffnet, Name steht noch da».*

⚠️ **Die andere Hälfte wartet auf TASK-019:** *«die Frage ist, was mit Labels ist — die sollten dann
auch aus dem Knoten verwendet werden, somit müsste `label_id` von Kante auf den gleichen zeigen wie
Knoten.» **Das geht erst, wenn `labels` und `label_texts` stehen** ([D-580](../../NewConcept/90-decision-log.md)) —
heute gibt es die Spalte `relations.label_id` noch nicht.*

⚠️ **TASK-029 erledigt am 2026-09-04 — mit einer benannten Lücke.** *Der Typ eines **eigenen** Feldes
ist über einen Auswahldialog in der Zeile änderbar; ein geerbtes bleibt beim Vorfahren
([D-376](../../NewConcept/90-decision-log.md)). Die Kante wird über ihre Version gespeichert, ein
gleichzeitiger Umbau meldet sich also als Konflikt.*

⚠️ **Was noch fehlt, ist die Hälfte seiner Regel:** *«sollten keine Daten da sein, einfach ändern;
wenn Daten da sind, neue Version und Konflikt.» **Der zweite Teil ist nicht gebaut** — heute ändert
sich der Typ auch dann, wenn Werte an der Kante hängen, und die Werte bleiben stehen. Das gehört zur
Versionierung in den Schattentabellen und wartet auf TASK-013 bis TASK-015.*

⚠️ *Gemessen, was der Dialog je Zeile kostet: die Seite von `Adresse` wächst auf 176 KB und zeichnet
in 0,13 s — vier Auswahldialoge, 183 Baumzeilen. Tragbar, aber es wächst mit der Zahl der Felder.*

⚠️ **TASK-030 abgeschlossen als Befund, nicht als Umbenennung.** *«TreeChooser» gibt es im Code
nicht: die Klassen heissen `DialogChooserRenderer`, `InlineChooserRenderer`, `ChooserCellRenderer`,
die Methode `chooserFor()`, die Renderer-Knoten `chooser-dialog` und `chooser-inline`. Der Begriff
steht nur in Kommentaren — **und die Hälfte davon sind seine eigenen Sätze, wörtlich zitiert.** Die
umzuschreiben hiesse, ein Zitat zu fälschen.*

---

## Was von seiner Liste noch offen ist

**TASK-032** (`relation_type` fällt, [D-587](../../NewConcept/90-decision-log.md),
[D-588](../../NewConcept/90-decision-log.md)) und **TASK-033** (ein Auswahl-Renderer statt zwei)
sind beide **Tabellenarbeit**, keine Maskenarbeit.

⚠️ **TASK-032 gehört hinter TASK-018, nicht hinter eine Umbenennung.** *TASK-011 hätte die Spalte
umbenannt, die TASK-032 streicht — sie ist deshalb gestrichen. **TASK-018 nimmt der Spalte 127 von
166 Zeilen ab**, und was danach übrig ist, fällt in einem Zug.*

```text
[ ] TASK-037  Loeschen fragt nach den Verwendungen — Dialog, und bei «ja»
              wandern sie mit in den Papierkorb        (D-604)
[ ] TASK-038  Ein Verweis auf einen verschwundenen Knoten ist am Feld
              sichtbar, nicht in einer Liste woanders  (D-604)
[ ] TASK-039  cleartrash-check leert den ganzen Papierkorb, nicht seinen
              Teil — er darf nur wegraeumen, was er selbst angelegt hat
```

⚠️ **TASK-039 ist kein Notfall, aber es ist derselbe Fehler wie bei TASK-025:** *ein Wächter, der
mehr anfasst als seine eigenen Knoten. **«Geparkt, nicht gelöscht» gilt nicht, solange ein
Wächterlauf dazwischenkommt** — gemessen an `DisplayOption`, den der Eigentümer selbst geparkt
hatte und der beim nächsten Lauf endgültig fiel.*

```text
[x] TASK-040  Ein Knoten erbt keine Einstellungskante, die auf ihn selbst
              zeigt (D-607) — plus Waechter dafuer
[x] TASK-042  Die gesperrte Zeile wird angezeigt, als gesperrt erkennbar,
              mit Hinweistext, der den Grund nennt (D-608)
```

⚠️ *Gemessen betrifft es heute **einen** Knoten: `read_only` unter `Boolean`. `Validator` und
`render with label` sind über den Ast schon geschützt ([D-545](../../NewConcept/90-decision-log.md)).
**Der Wächter ist trotzdem der wichtigere Teil** — der Fall ist heute selten und morgen wieder da,
sobald jemand einen Einstellungsknoten ausserhalb des Astes anlegt.*

```text
[ ] TASK-041  Die Marke «ist eine Einstellung» einmalig aus dem Ast fuellen —
              36 von 38 Knoten fehlen (D-606), mit Waechter, dass sie
              gepflegt bleibt
```

⚠️ **Beim Füllen ist `min` und `max` gesondert anzusehen:** *sie liegen im Ast, tragen keine Marke,
und **keine Kante zeigt auf sie**. Vermutlich sind sie kein Einstellungsknoten mehr, sondern Rest —
**nicht blind mitmarkieren.***

⚠️ *Danach kann [D-605](../../NewConcept/90-decision-log.md) greifen (ein markierter Knoten erbt
keine Einstellungskanten) — heute griffe die Regel an drei Knoten statt an achtunddreissig.*

⚠️ **TASK-040 neu gefasst am 2026-09-04.** *Die erste Fassung (`kind = setting` erbt nichts,
[D-605](../../NewConcept/90-decision-log.md)) war zu breit — sie hätte `render with label` den
geerbten `converter` genommen. **Er hat es gesehen, bevor es gebaut war.** Gemessen betrifft die
neue Fassung zwei Kanten: `Root --validator--> Validator` und `Root --read_only--> read_only`.*

⚠️ *Und `min`/`max` sind damit **kein Rest**: sie wurden ausgelagert, um genau dieser Rekursion
auszuweichen — «wenn wir am Knoten `int` die `min`/`max` einhängen und `int` `min`/`max` als Setting
hat, erben `min`/`max` diese wieder». **Mit [D-607](../../NewConcept/90-decision-log.md) fällt der
Grund weg**, sie können zurück unter `Integer`.*

```text
[ ] TASK-043  Ein Datensatz entsteht beim ersten Schreiben, nicht beim
              Ansehen (D-609) — behebt BUG-004
```

**Gemessen: 324 von 377 Datensätzen tragen keine einzige Wertzeile, 287 davon `default`.**
*Sie entstehen in `DataEntry::defaultRecordOf()`, gerufen auch aus `clearSettingAt()` — **Löschen
legt an**, bevor es merkt, dass nichts zu löschen ist.*

⚠️ **Nicht entschieden: was mit den 324 vorhandenen geschieht.** *Sie tragen nichts, also verliert
ihr Wegräumen nichts — aber es ist eine Handlung an seinen Daten und gehört gefragt, nicht
nebenbei erledigt.*

```text
[ ] TASK-044  Einmaliger Reset: die 2 Zeilen mit value_ref = 0 und die
              wirklich unbenutzten leeren Datensaetze (D-610)
```

⚠️ **Die Grenze ist das Wichtige an dieser Aufgabe, nicht die Zahl.** *Von 324 leeren Datensätzen
stehen **26 in `nodes.settings_record_id`** — sie tragen ihre Aussage in der `node_id` («dieser
Renderer ist gewählt, nichts daran eingestellt»). **Wer sie als leer wegräumt, löscht 26
Renderer-Wahlen.** Der Reset braucht deshalb eine Bedingung, keine Zählung: *unbenutzt* heisst
nirgends referenziert — nicht *ohne Wertzeilen*.

```text
[ ] TASK-045  Der Schreiber sucht die Einstellungskante an beiden Ketten —
              Besitzer und Ziel (D-611). Nur fuer Einstellungen, nicht fuer
              Modellfelder.
[ ] TASK-047  Zwei Kanten auf den Zweigkopf «Constants» entfernen —
              Rueckstand aus Waechterlaeufen, ohne Eintrag im Aenderungsbuch
[ ] TASK-046  Die vier Waechter auf die Spaltenform umschreiben — sie fragen
              nach den Kanten 44091/44093, die es nicht mehr gibt
```

⚠️ **Zu TASK-046, gemessen:** *die Optionen `taxmod_setting_edge_renderer` (44093) und
`taxmod_setting_value_edge_renderer` (44091) zeigen auf **gelöschte Kanten**. Sie hingen am
Hüllknoten `DisplayOption`; seit [D-584](../../NewConcept/90-decision-log.md) steht der Renderer in
`nodes.settings_record_id` und nicht mehr an einem Kantenpaar. **Alle zwanzig verbliebenen
Fehlschläge von `setting-write`, `page-blocks`, `multiplicity` und `renderer-choice` fragen nach der
alten Form.** Das umzuschreiben ist eine sichtbare Konzeptänderung (`PR-9`) und gehört benannt.*

```text
[ ] TASK-048  Ein Klick auf den Klapp-Pfeil gewinnt gegen den Vorrang der
              Auswahl (D-612) — heute tut er sichtbar nichts, wenn der
              gewaehlte Knoten in diesem Ast liegt
```

⚠️ *Die Stelle ist eine Zeile in `NodesScreen`: `$collapsed = array_diff($collapsed,
$selected->ancestorIds())`. **Sie stammt aus [D-480](../../NewConcept/90-decision-log.md) und ist
richtig** — sie darf nur nicht gegen eine ausdrückliche Handlung gewinnen.*

```text
[ ] TASK-049  Zehn Waechter von seinen Knotennamen loesen (D-613)
              form-membership · label-role · package1 · page-blocks
              record-on-any-node · rename-survives · renderer-choice
              setting-edge · setting-write · unitvalue
```

⚠️ **Der Grund ist schärfer als «fragil»:** *[D-022](../../NewConcept/90-decision-log.md) sagt,
Knotennamen sind **absichtlich nicht eindeutig**, und «nothing resolves, references or branches on a
name». **Gemessen kommen fünf Namen doppelt vor, darunter `Adresse`.** Ein Wächter mit
`WHERE name = … LIMIT 1` greift eine von beiden und weiss nicht welche — der Fall ist in
`setting-write-check` schon dokumentiert.*

```text
[ ] TASK-050  Ein Testast fuer Waechterknoten (D-614) — dort duerfen sie
              liegenbleiben; unsichtbar, ausserhalb jeder Aufloesungskette,
              und zaehlbar
```

⚠️ **Warum ein Ast und nicht besseres Aufräumen:** *aufgeräumt wird schon — die Regel greift nur
nicht, wenn ein Lauf **mitten in der Arbeit abstürzt.** Genau das ist am 2026-09-04 dreimal
passiert: 60, dann 12, dann 8 Probeknoten in seinem Arbeitsbaum. **Wer abstürzt, räumt nicht auf.**
Ein eigener Ast hält den Rückstand dort, wo er niemanden stört.*

```text
[ ] TASK-051  Die Konfigurationsseite haelt den Faltzustand — «alles zu»
              gilt nur beim ersten Aufruf, nicht bei jedem Link, der ihn
              vergessen hat (D-615, D-480)
```

**Gemessen an `NodesScreen`:**

```text
Zeile 202   $collapsed = $carried ?? collapsedByDefault($selected)
               ↑ klappt ALLES zu — das ist die Ast-Angabe, ausschliessend
Zeile 212   $collapsed = diff($collapsed, $selected->ancestorIds())
               ↑ oeffnet nur den Weg — das ist die Knoten-Angabe, richtig
```

⚠️ **Der Fehler ist Zeile 202, und er ist derselbe, den [D-480](../../NewConcept/90-decision-log.md)
schon einmal behoben hat:** *«der Zustand lag in der URL und niemand schrieb ihn hinein.» **Wo ein
Link ihn heute noch verliert, fällt die Seite auf «alles zu» zurück** — und der Benutzer verliert
einen Zustand, den er nicht angefasst hat. Sein Satz von damals: «wenn ich zwischen zwei Knoten
arbeite und dauernd die Äste zugehen, das ist ziemlich nervig».*

⚠️ *Nach [D-615](../../NewConcept/90-decision-log.md) ist «alles zu» ausserdem die **Ast**-Angabe —
und die Konfigurationsseite darf nur den **Wurzelknoten** übergeben. Sie wendet damit eine Angabe an,
die sie gar nicht bekommen hat.*
