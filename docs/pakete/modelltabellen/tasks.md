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
[?] nodes.field_type  — zweite Heimat neben relations.kind = setting?
[?] kind        — drei Spalten dieses Namens, drei Bedeutungen (CD-9)
[?] label_role  — OQ-134; solange offen, kann settings nicht fallen
```

---

```text
[x] TASK-007  nodes.kind in field_type umbenennen
```

**Spalte `kind` → `field_type`, Wert `field` → `model`.** *Nach seiner Selbstkorrektur: «Entschuldigung,
Model und Settings, richtig.»*

**Trotzdem keine Datenwanderung, gemessen:** der Wert `field` steht in **keiner einzigen Zeile** —
gefüllt sind vier, alle `setting`.

Betroffen sind `NodeKind`, `Node`, `NodeRepository`, `Schema` und `node-kind-check`.
**Offen dabei:** 124 von 128 Knoten sagen heute nichts — ist «nichts» gleich `field`, oder muss es
dastehen?

**Gebaut am 2026-09-05, Schema 24.** Die Spalte heisst `nodes.field_type`, der Wert `model`. **Und
die Umbenennung geht durch den ganzen Code, weil sonst der Name weiter lügt** (`CD-9`):
`NodeKind` → **`FieldType`**, `FieldType::Field` → **`FieldType::Model`**, `Node::$kind` →
`$fieldType`, `withKind()` → `withFieldType()`, `ModelEditor::setKind()` → `setFieldType()`,
`kindsOfNodes()` → `fieldTypesOfNodes()`, `NodeRepository::resolvedKinds()` →
`resolvedFieldTypes()`, das Formularfeld `node_kind` → `node_field_type`, und der Wächter heisst
[`field-type-check.php`](../../../scripts/dev/field-type-check.php).

⚠️ **Gemessen vor dem Umbau, und darum keine Datenwanderung: der Wert `field` stand in keiner
einzigen Zeile** — lebend 97 ohne Angabe und 39 `setting`, im Schatten 17 202 ohne und 190
`setting`. *Der Wanderungsschritt schreibt `field` → `model` **trotzdem**, weil eine Wanderung, die
nur den gemessenen Fall kann, auf der nächsten Installation falsch ist (dieselbe Regel wie Fassung
15). Der Schatten zieht mit, sonst kann `Shadow::keep()` die Spalte nicht kopieren.*

⚠️ **Der Eintrag im Änderungsbuch heisst jetzt `field type set`; die alten behalten `kind set`** —
*Geschichte ist eingefroren ([D-065](../../NewConcept/90-decision-log.md)).*

⚠️ **Die offene Frage oben ist offen geblieben** (`PR-4`): *ob «nichts» dasselbe ist wie `model`,
sagt keine Entscheidung. Der Vorfahrenlauf antwortet unverändert mit `FieldType::standard()`, und
der Wähler auf der Seite zeigt weiter drei Zustände — «erbt», `model`, `setting`.*

⚠️ *Mitgezogen, weil sie die Spalte roh abfragten: `setting-kind-check`, `setting-kind-migrate` und
`minmax-specialize`. **`setting-kind-check` war dadurch bereits rot** und meldete «der Ast trägt 0
Knoten» — die Abfrage schlug fehl, und `$wpdb` sagt darüber nichts.*

```text
[x] TASK-008  Spalte: welche PHP-Klasse setzt diesen Knoten um
```

**Entschieden am 2026-09-01: die Spalte trägt den Klassennamen, keine Factory.**
*Der Eigentümer: «wenn das ohne Factory geht, weil der Klassenname da drinsteht, perfekt.»*

**Dazu gehört der Wächter, und er ist Teil derselben Aufgabe:** eine Zeile, die eine Klasse nennt,
die es nicht gibt, wird rot. *Das ist der Ausgleich dafür, dass ein Klassenname die Daten an den
Code bindet — und es ist der eine Vorteil, den eine Marke nicht hätte.*

**Gebaut am 2026-09-05, Schema 23.** Die Spalte heisst `nodes.implemented_by` und trägt den voll
qualifizierten Klassennamen; im Kern sagt es `Node::implementedBy`, geschrieben wird über
`ModelEditor::setImplementedBy()` — journalisiert wie ein Umbenennen, mit Schattenzeile. **Die Saat
schreibt die Angabe selbst**, und zwar auch an Knoten, die schon dastanden: `RenderingScaffold`
fragt die Registratur nach der Klasse (`classFor()`), statt eine zweite Liste zu führen.

⚠️ **`null` heisst «keine Klasse setzt ihn um» und **nicht** «frag die Vorfahren».** *Anders als
`kind`: **eine Klasse erbt sich nicht.** Die drei Behälter `Renderer`, `Converter`, `Validator`
tragen darum nichts — sie sind ein Ort, kein Renderer.*

**Gemessen und gewandert: 23 Knoten** — 17 Renderer, 4 Konverter, 2 Validatoren, über die
aufgeschriebenen Ids und nie über Namen ([D-022](../../NewConcept/90-decision-log.md)).
[`implemented-by-migrate.php`](../../../scripts/dev/implemented-by-migrate.php) ist der Lauf,
[`implemented-by-check.php`](../../../scripts/dev/implemented-by-check.php) der Wächter: er prüft die
Spalte an lebender Tabelle **und Schatten**, dass jede Angabe eine vorhandene Klasse nennt, dass
**keine Klasse an zwei Knoten** steht, und dass jede registrierte Klasse ihren Knoten hat. *Den
Schatten **zählt** er nur — Geschichte ist eingefroren ([D-065](../../NewConcept/90-decision-log.md)),
und eine Fassung darf eine Klasse nennen, die es heute nicht mehr gibt.*

```text
[x] TASK-009  Die 56 Optionen ablösen, die sich Knoten-Ids merken
```

Folgt aus TASK-008. **Gemessen: 56 WordPress-Optionen** halten heute die Bindung «welcher Knoten ist
der Int-Typ, welcher der Slider-Renderer» — `taxmod_type_int_id`, `taxmod_render_renderer_slider_id`
und vierundfünfzig weitere. **Sagt der Knoten es selbst, können sie fallen.**

⚠️ *Das ist eine eigene Aufgabe und kein Nebenbei: 56 Optionen zu entfernen heisst, jeden Leser
vorher umzuziehen — Reihenfolge Wächter, Leser, Daten.*

**Zur Hälfte erledigt am 2026-09-05, und die andere Hälfte ist eine Frage, keine Arbeit.**

⚠️ **Gemessen: es sind 46 Optionen, nicht 56** — die Zahl oben stammt von einem früheren Stand.
**23 sind gefallen**, alle Renderer, Konverter und Validatoren: die Saat findet ihren Knoten jetzt
über `nodes.implemented_by` ({@see ModelEditor::nodeImplementing()}), der Schreiber der Renderer-Wahl
in `NodesScreen` ebenso, und die Option wird nicht mehr geschrieben. *Reihenfolge Wächter, Leser,
Daten: `rendering-scaffold-check` §2, §6 und §7 sind auf die Spalte umgeschrieben — **nicht
entschärft** (`PR-9`), die Zusagen sind wortgleich —, dann die beiden Leser, dann der Lauf, der die
Optionen löscht.*

⚠️ **23 stehen weiter, und warum, steht als `INF-020` im Eingang:** *elf Typoptionen, weil ein
einfacher Typ ein **Aufzählungsfall** ist und keine Klasse — alle elf antworteten mit demselben
Klassennamen; drei Behälteroptionen, weil `Renderer` ein Ort ist und keine Klasse; neun Optionen
für Oberflächenrenderer, die niemand mehr liest und die trotzdem nicht wegzuwerfen sind. **Der Fall
der elf ist der, der in der Aufgabe wörtlich steht** — «welcher Knoten ist der Int-Typ» —, und er
lässt sich mit dieser Spalte nicht beantworten, ohne die Entscheidung zu brechen. Nicht erfunden
(`PR-4`); `type-binding-check` bewacht die alte Form unverändert und ist grün.*

**Nachgemessen am 2026-09-05, und die Zahl ist wieder eine andere: 40, nicht 23** (`INF-025`).
*49 `taxmod_`-Optionen stehen da, **40** merken sich eine Knoten-Id, **31** zeigen dabei auf einen
Knoten, den es gibt. Die Zählung darüber hatte die fünf Äste, die fünf Beschriftungsrollen, die vier
gesäten Einzelknoten und die drei Wegwerfäste der Wächter nicht angesehen.*

⚠️ **Und damit ist die offene Frage viermal so gross wie gedacht, aber es ist dieselbe.** *`Branch`
und `SeededRole` sind genau wie `SimpleType` Aufzählungen und keine Klassen — betroffen sind
**einundzwanzig** Optionen, nicht elf. **Nichts davon ist abgelöst worden**, weil jede Ablösung die
Entscheidung aus TASK-008 gebrochen hätte (`PR-4`). Die drei Wege stehen unverändert in `INF-020`;
nur der Preis von (a) hat sich vervierfacht.*

⚠️ **Ein Befund korrigiert `INF-020`:** *die neun Rückstände zeigen **nicht** auf ältere Knoten. Ihre
Ids stehen weder in `nodes` noch im Schatten — sie halten gar keine Bindung. Stehengelassen, weil
Löschen in seinem Bestand seine Entscheidung ist; die Frage ist damit nur noch eine Ja-Nein-Frage.*

**Zuendegebracht am 2026-09-05: die elf Typoptionen sind gefallen** — *und der Grund, aus dem sie
standen, ist weg. Sie standen, weil ein einfacher Typ ein **Aufzählungsfall** war und keine Klasse;
mit [D-484](../../NewConcept/90-decision-log.md) hat jeder Typ eine Klasse
(`Taxmod\Core\Model\Type\IntType` und zehn weitere), also trägt sein Knoten den Klassennamen wie
jeder Renderer. **Weg (a) aus `INF-020`, und niemand musste die Entscheidung aus TASK-008 dafür
brechen.*** Reihenfolge wie beim ersten Mal — Wächter (`simple-type-check`, `node-binding-check` §3b,
`implemented-by-check` §4), dann der Leser ({@see SeededTypeNodes}, jetzt über
`nodes.implemented_by`), dann der Lauf
[`type-binding-migrate.php`](../../../scripts/dev/type-binding-migrate.php), der nur löscht, was mit
der Zeile übereinstimmt. *Gemessen: elf Optionen, elf Knoten, dieselben elf Ids.*

⚠️ **Damit stehen von den 40 noch 29**, und alle offen aus demselben Grund wie vorher: fünf Äste,
fünf Beschriftungsrollen, vier gesäte Einzelorte, drei Behälter, drei Wegwerfäste der Wächter und
neun Rückstände, die auf nichts zeigen. *Ob Äste und Rollen denselben Weg gehen sollen wie die Typen,
hat der Eigentümer nicht gesagt — es steht als `INF-026` im Eingang und wird nicht geraten (`PR-4`).*

**Der Wächter dazu ist neu:** [`node-binding-check.php`](../../../scripts/dev/node-binding-check.php).
*Er hält fest, was TASK-009 erreicht hat — **keine der 23 abgelösten Klassenoptionen kommt zurück**,
jede registrierte Klasse steht an genau einem Knoten — und er macht den Rest sichtbar: keine
**unbekannte** Option merkt sich eine Knoten-Id, und die neun Rückstände zeigen weiter auf nichts.
Geprüft, dass er beisst, und er lässt beim Lauf nichts liegen (`INF-021`).*

```text
[x] TASK-010  from_id/to_id in from_node_id/to_node_id, Constraint auf nodes.id
```

*Der Eigentümer: «machen wir es eh eindeutiger … das ist eine Knoten-Id, da ist ein Constraint.»*

**Keine Umbenennung, sondern eine Verschärfung.** Die sieben heutigen Fremdschlüssel zeigen **alle**
auf `identities.id` — die Bedingung erlaubt strukturell eine Kante, die von einem **Datensatz**
ausgeht. Gehört zu TASK-004, weil `identities` dabei ohnehin fällt.

**Gebaut am 2026-09-05, Schema 26.** Die Spalten heissen `from_node_id` und `to_node_id`, lebend und
im Schatten; im Kern `Relation::$fromNodeId` und `$toNodeId`. **Und beide tragen wieder eine echte
Bedingung**, `taxmod_rel_from_node` und `taxmod_rel_to_node` auf `nodes.id`.

⚠️ **Der Aufräumweg hält es aus, und das war zu prüfen, nicht zu hoffen:** *`purgeSubtree()` löscht
die **Kanten vor den Knoten**, und der Kommentar dort sagt seit jeher warum. Die Reihenfolge war
schon richtig; jetzt kann sie niemand mehr versehentlich umdrehen.*

⚠️ **Der Schatten bekommt die Umbenennung und keine Bedingung** — *eine alte Zeile führt ihre
Verweise als Datum mit, nicht als Zwang; sonst hielte die Geschichte einen Knoten am Leben, den
jemand weggeräumt hat.*

⚠️ *Die Wanderung legt die Bedingung **nicht**, wenn eine Waise dasteht: eine, die MySQL zurückweist,
wäre still, und ein halb gesichertes Schema ist schlimmer als ein ungesichertes. Gemessen vor dem
Umbau: null Waisen in beiden Spalten.*

⚠️ **Und die Indexnamen mussten mit, sonst hätte `dbDelta` zweimal dasselbe gelegt:** *eine
umbenannte Spalte behält den **Namen** ihres Indexes. Der Schritt räumt `from_id` wie `to_id` ab,
gleich unter welchem der beiden Namen er ihn findet.*

**`id-space-check.php` ist auf die neue Form umgeschrieben** (`PR-9`): *sein Kopf versprach «bis
dahin hält dieser Lauf dieselbe Zusage lesend». Er hält sie weiter für die fünf Verweise ohne
Bedingung — und **prüft für die beiden Kantenspalten zusätzlich, dass die Bedingung wirklich
dasteht**.*

⚠️ **TASK-011 ist gestrichen** (2026-09-04, auf sein Wort: *«TASK-011 löschen»*). *Sie hätte
`relations.kind` in `relation_type` umbenannt — **und [D-587](../../NewConcept/90-decision-log.md)
streicht die Spalte ganz.** Erst umbenennen und dann löschen ist zweimal Arbeit an derselben
Spalte; nach TASK-018 trägt sie ohnehin nur noch 39 von 166 Zeilen. **Die Reihenfolge ist damit
TASK-018 → TASK-032**, nicht TASK-011 → TASK-032.*

⚠️ *Die Beobachtung dahinter bleibt gültig und gehört zu TASK-032: der Code verzweigt an 19
Stellen auf die Kantenart, **15 davon fragen nur «ist es Vererbung?»** — und genau die Frage
verschwindet mit TASK-018.*

```text
[x] TASK-012  position in sort_order umbenennen, Schluessel (from_node_id, relation_type, sort_order)
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

**Gebaut am 2026-09-05, Schema 25.** Die Spalte heisst `sort_order`, lebend und im Schatten; der
Schlüssel heisst `one_place` und geht über `(from_id, kind, sort_order)`; der Einzelindex auf
`from_id` ist gefallen. **Der Schatten bekommt die Umbenennung und nicht den Schlüssel** — dort
*darf* dieselbe Stelle mehrfach vorkommen. Im Kern heisst es `Relation::$sortOrder`.

⚠️ **Die Spaltennamen sind die von heute, nicht die aus der Überschrift:** *`from_node_id` kommt mit
TASK-010, und `relation_type` kommt gar nicht mehr — TASK-011 ist gestrichen, die Spalte fällt mit
TASK-032. **Der Schlüssel muss dabei zweimal angefasst werden**, und das ist der Preis dieser
Reihenfolge.*

⚠️ **Die eine echte Doppelung ist weg**, wie angekündigt: *`render with label` trug `label_role` und
`with_label` beide auf Stelle 0; `with_label` steht jetzt auf 1. **Zwischen zwei Kanten, die beide
auf 0 standen, gab es keine Reihenfolge, die man verlieren könnte.** Umkehrbar, die Zeile steht
vorher im Schatten. Gemessen nach dem Umbau: mit der Kantenart **0** Verletzungen, ohne sie **12** —
der Beleg dafür, dass die dritte Spalte nötig ist.*

⚠️ **Zwei Dinge sind dabei kaputtgegangen und stehen im Eingang.** *`INF-023`: **ein Tausch tat
nichts mehr** — er schreibt zwangsläufig einmal auf eine besetzte Stelle, MySQL weist das zurück,
und `$wpdb` sagt darüber nichts. Genau **ein** Wächter hat es gemerkt. `ModelEditor` geht seither
über eine freie Stelle, und **TASK-018 legt denselben Schlüsseltyp an**. `INF-022`: sein Satz «die
erste Position ist immer null» ist gemessen an 12 Listen nicht erfüllt — gezählt, nicht
umnummeriert.*

⚠️ **Und eine Warnung aus derselben Stunde:** *`WpdbNodeRepository` sortierte an zwei Stellen nach
`r.position`. Diese zwei übersehenen Zeilen liessen `childrenOf()` **leer** antworten — und jede
Saat legte darauf ihre Knoten ein zweites Mal an: **38 Knoten und 54 Kanten Rückstand in einem
Durchlauf**, drei komplette Sätze der Datentypen. Zurückgenommen. **Der Wächter, der das gefunden
hat, war keiner** — es fiel an einer Renderer-Auswahl auf, die plötzlich die falsche Liste zeigte.*

**Neu am Netz:** [`sort-order-check.php`](../../../scripts/dev/sort-order-check.php) — Spalte lebend
und im Schatten, `position` gibt es nicht mehr, der Schlüssel steht über genau diese drei Spalten,
der alte Einzelindex ist fort, keine Doppelung, und **ein Tausch tauscht wirklich**.

⚠️ **Und eine echte Doppelung muss vorher weg.** *Gemessen: ohne `type` sind es 8 Verletzungen, **mit
`relation_type` genau eine** — Knoten 55659 «render with label» hat zwei Einstellungskanten auf Stelle 0,
`label_role` und `with_label`. **Meine Zusage «zu bereinigen gibt es nichts» war vorschnell.***

```text
[x] TASK-013  parked_by_group_id aus relations entfernen; Parken wandert in den Schatten
```

[D-575](../../NewConcept/90-decision-log.md), wörtlich von ihm: *«Parken heisst: in die
Schattentabelle wandern, mit der Änderungsgruppe im Gepäck.»*

**Reihenfolge Wächter, Leser, Daten** — und die Leser sind hier viele: **23 Stellen in 12 Dateien**
erwähnen «geparkt», darunter `ModelEditor` 18, `WpdbRelationRepository` 12, `NodesScreen` 11.

⚠️ *Drei bis vier Ansichten brauchen danach eine zweite Abfrage, um Gelöschtes zu zeigen
([D-128](../../NewConcept/90-decision-log.md)s Umschalter). **Das ist der genannte Preis** — gegen
23 Stellen, die heute etwas vergessen können.*

**Gebaut am 2026-09-05, entschieden durch [D-619](../../NewConcept/90-decision-log.md).** Die
offene Frage ist beantwortet: **die Wertzeilen wandern mit**, auf sein «1» gegen
«stehenbleiben» und «verbieten». Schema 27 nimmt `relations.parked_by_group_id` weg und schiebt
die 14 geparkten Kanten mit ihrer Gruppe in `relations_history`; `parkedFieldEdgesOf()` liest
seither aus dem Schatten, und `park()`/`unpark()` sind ein Paar, keine zwei Mechaniken.

**Neu am Netz:** [`parked-in-shadow-check.php`](../../../scripts/dev/parked-in-shadow-check.php)
— er legt sich seinen eigenen Fall an (Knoten, Kante, Wertzeile, Präfix `__`), parkt, prüft
beide Wanderungen und holt zurück. *Nötig, weil heute keine der 14 geparkten Kanten eine
Wertzeile trägt — die Zusage prüfte sonst nur, was zufällig dasteht.*

⚠️ **Ein gemessener Nebenbefund, nicht entschieden:** *nach dem Umzug lesen sich **16** Kanten
als geparkt, nicht 14. Die drei zusätzlichen wurden am 2026-08-30 geparkt und haben seither
keine lebende Zeile mehr — ihr letzter Journaleintrag lautet bei allen dreien «attribute
removed». **Sie waren schon geparkt und wurden nur nie angezeigt.** Ob sie in der Liste
«entfernte Felder» auftauchen sollen, steht als `INF-028` im Eingang.*

⚠️ *Der Stand vom Vormittag, zur Nachvollziehbarkeit:* **Am 2026-09-05 bewusst nicht angefangen, und der Grund steht als `INF-024` im Eingang.** *Das
Handwerk ist überschaubar und der Platz für die Änderungsgruppe existiert schon —
`relations_history` **hat** die Spalte `parked_by_group_id`. **Offen ist, was aus den Wertzeilen
einer geparkten Kante wird**, sobald ihre Zeile aus `relations` verschwindet: mitwandern,
stehenbleiben (Waisen), oder das Parken verbieten. Heute trägt keine der 14 geparkten Kanten eine
Wertzeile — **also verliert man nichts und entscheidet trotzdem etwas, und dazu sagt keine
Entscheidung etwas** (`PR-4`).*

```text
[x] TASK-014  records -> node_records, record_values -> relation_records
```

*Der Eigentümer: «records → node_records, record_values → relation_records».* **Die Zuordnung ist
gemessen und ausnahmslos** — 209 von 209, 183 von 183.

**Dazu gehören zwei Spalten, nach derselben Regel wie `from_node_id`:**
`record_id` → **`node_record_id`**, `edge_id` → **`relation_id`**.

⚠️ **Vorher zu entscheiden: «edge» oder «relation»?** *Gemessen im Quelltext: **edge 1080-mal,
relation 407-mal.** Die Tabelle heisst `relations`, der Code sagt überwiegend `edge`, und sein
deutsches Wort ist **Kante** — was näher an «edge» liegt. **Zwei Wörter für eine Sache verbietet
`CD-9`; welches bleibt, ist offen.** Erst die Wortwahl, dann die Spaltennamen.*

**Gebaut am 2026-09-05, Schema 29.** Die vier Tabellen heissen `node_records`, `relation_records`
und ihre beiden Schatten; die Spalten heissen `node_record_id` und `relation_id`.

⚠️ **Die offene Frage war schon beantwortet und musste nicht neu entschieden werden:**
*[D-576](../../NewConcept/90-decision-log.md) legt «relation» fest — es ist der Gegenstand von
TASK-016. **Die Spalte heisst darum `relation_id`, nicht `edge_id`**, und die Messung «edge
1080-mal» ist keine Gegenstimme, sondern der Umfang von TASK-016.*

⚠️ **Gezählt vor und nach der Wanderung, und es ist dieselbe Zahl:** *node_records **413 → 413**,
relation_records **73 → 73**, node_records_history **1596 → 1596**, relation_records_history
**4439 → 4439**. Keine der vier alten Tabellen steht noch da. *Eine Umbenennung bewegt keine Zeile —
darum **keine Schattenzeile**: `RENAME TABLE` und `CHANGE` sind für sich umkehrbar, und es gibt
keinen Inhalt, den ein Schatten aufheben könnte.*

⚠️ **Der Index musste von Hand fallen, wie schon bei `from_id`:** *eine umbenannte Spalte behält den
**Namen** ihres Indexes — `dbDelta` hätte neben `edge_id` einen zweiten `relation_id` gelegt. Der
zusammengesetzte `of_field` behält seinen Namen und folgt der Umbenennung von selbst. **Gemessen
nachher:** `of_field(node_record_id,relation_id,locale)`, `relation_id(relation_id)`,
`value_ref(value_ref)` — und kein `edge_id` mehr.*

⚠️ **Mitgezogen, jeder mit dem Grund im Text und keiner entschärft (`PR-9`): 54 Dateien.** *Rot
geworden ist genau einer, und er zeigt, warum ein Name mehr ist als Kosmetik:
[`id-space-check.php`](../../../scripts/dev/id-space-check.php) hielt `records` als **Schlüssel**
einer Liste, gab ihn an `Schema::table()` weiter, fand die Tabelle nicht — und las den Zähler als
`0`. **Der Wächter meldete «0 gegen 7634» statt einer stillen Null.***

⚠️ *Zwei Formularfelder sind mitgekommen, weil sie dieselbe Spalte meinen: das versteckte Feld und
sein Leser in `NodesScreen` heissen `node_record_id`. **Nicht mitgekommen** ist `settings_record_id`
an `nodes` und `relations` — sie steht nicht in der Aufgabe, und sie umzubenennen wäre eine
Entscheidung gewesen, die niemand getroffen hat (`PR-4`).*

```text
[~] TASK-015  node_records: kind -> record_type, version unter id, created_at faellt
```

*Der Eigentümer: «`kind` → `type` umbenennen, `version` würde ich nach oben unter `id` packen. Das
`created_at` ist eigentlich was fürs Log.»*

⚠️ **`created_at` fällt erst, wenn das Änderungsbuch Datensätze führt.** *Gemessen: **null Einträge
mit `owner_kind = 'record'`** — das Log protokolliert Knoten und Kanten, Datensätze nicht als eigene
Art. **Erst der neue Leser, dann die Daten** (`PR-12`).*

⚠️ *Die Frage «`type` oder `record_type`» ist entschieden: **`record_type`.** Der Eigentümer:
«Records — gleiche Handhabung wie Knoten und Kanten.» Damit heissen alle drei qualifiziert:
`field_type`, `relation_type`, `record_type`.*

**Zwei Drittel gebaut am 2026-09-05, Schema 30 — und das dritte ist eine Frage, keine Arbeit.**
Die Spalte heisst `node_records.record_type`, lebend und im Schatten; `version` steht unter `id`,
ebenfalls in beiden. **Im Kern heisst die Aufzählung `RecordType`** (vorher `RecordKind`) und die
Marke `NodeRecord::$recordType` — dieselbe Umschrift wie bei `NodeKind` → `FieldType` (TASK-007),
denn sonst lügt der Name weiter (`CD-9`).

⚠️ **Gezählt vor und nach der Wanderung, und es ist dieselbe Verteilung:** *`node_records`
**421 → 421** (default 392, user 29), `node_records_history` **1630 → 1630** (user 1406, default
224). **Gemessen nachher:** die Spaltenfolge lautet `id, version, node_id, node_version, created_at,
record_type`, der Index heisst `record_type`, und `kind` gibt es an keiner der beiden Tabellen mehr.*

⚠️ **Die Spaltenordnung stellt `dbDelta` nicht her, und das ist der Grund für einen eigenen
Schritt:** *es hängt eine fehlende Spalte hinten an und ordnet nie um. Wer die Reihenfolge nur im
`CREATE TABLE` ändert, hat sie auf einer frischen Installation und sonst nirgends.*

⚠️ **`created_at` steht noch, und der Grund ist die Bedingung aus der Aufgabe selbst** (`PR-4`):
*«erst der neue Leser, dann die Daten». **Gemessen: 9 Einträge mit `owner_kind = 'record'` gegen 421
Datensätze** — den Schreiber gibt es inzwischen, aber er läuft erst, seit es ihn gibt. Für 412
Datensätze hält allein die Spalte fest, wann sie entstanden sind. Steht als `INF-039` im Eingang.*

⚠️ **Der Eintrag im Änderungsbuch heisst jetzt `record_type`; die alten behalten `kind`** —
*Geschichte ist eingefroren ([D-065](../../NewConcept/90-decision-log.md)), dieselbe Regel wie beim
Eintrag «field type set».*

⚠️ *Mitgezogen, weil sie die Spalte roh abfragten: `record-on-any-node-check` (er verlangt jetzt
`record_type` **und** dass `kind` weg ist), `setting-write-check`, `preview-check`,
`task044-047-reset` und `edge-class-check`. **Keiner ist entschärft.***

⚠️ **Und einer war schon vorher wacklig, ohne dass es jemand gesehen hat:** *`preview-check` suchte
«einen Knoten mit Kanten und ohne Datensätze» und nahm bei Gleichstand, was die Datenbank zuerst
lieferte. **Es gibt zwei Knoten namens `Adresse` mit je fünf Kanten** — einer unter einem Ast, der
Daten hält, einer unter einem, der keine hält. Mit dem einen war der Abschnitt grün, mit dem anderen
viermal rot, **und an der Vorschau war beides Mal nichts falsch.** Er nimmt jetzt den ersten
Bewerber, an dem die Vorschau überhaupt gezeichnet wird, in fester Reihenfolge.*

```text
[?] node_version bei Versionskonflikt — bewusst zurückgestellt
```

*«Das können wir erstmal so lassen.»* **Seine Vermutung ist bestätigt:** `node_version` wird
geschrieben, angezeigt und **nirgends verglichen**. 29 von 209 Datensätzen sind älter als ihr Knoten.

```text
[x] TASK-016  «edge» im Quelltext durch «relation» ersetzen
```

[D-576](../../NewConcept/90-decision-log.md). **Nicht Teil der Tabellenbenennung** — eigenes
Arbeitsstück.

**Gemessen: 699 Bezeichner in 26 Dateien**, dazu 381 Nennungen in Kommentaren. **Vier Dateien tragen
79 %:** `ModelEditor` 180, `Rendering` 149, `NodesScreen` 123, `DataEntry` 98. Dazu `EdgeRecord` →
`RelationRecord`.

⚠️ *Kein Feldzug mit einem regulären Ausdruck — siehe [`bekannte-fallen.md`](../../bekannte-fallen.md).*

**Gebaut am 2026-09-05.** *`EdgeRecord` heisst `RelationRecord`, `$edge` heisst `$relation`,
`edgeId` heisst `relationId`, `fieldEdgesOf()` heisst `fieldRelationsOf()`, und die drei
Wächterdateien `setting-edge-check`, `setting-branch-edge-check` und `dead-edge-values-clean` tragen
den neuen Namen.*

⚠️ **Gezählt, nicht geschätzt** — gemessen über `src`, `tests` und `scripts` ohne die Sonden der
stillgelegten Fassung:

| | vorher | nachher |
|---|---|---|
| `edge` in `src` und `tests` | 1524 | **24** |
| `edge` in `scripts` (ohne `_smoke-*`) | 514 | **8** |

⚠️ **Und die 32, die stehen, stehen mit Grund — jede einzelne ist nachgesehen:**

| | Zahl | warum |
|---|---|---|
| `SettingEdge`, `AggregationEdge`, `CompositionEdge` samt Namensraum und ihrem Wächter | **17** | **TASK-032**, an dem gleichzeitig gebaut wurde |
| `'taxmod_setting_edge_'` und `'taxmod_setting_value_edge_'` | **3** | **Optionsschlüssel sind Daten** — ein neuer Name fände die vorhandenen Zeilen nicht |
| die Aufzeichnung `taxmod_task018_shape` und ihr Leser | **2** | dieselbe Regel, und Geschichte ist eingefroren ([D-065](../../NewConcept/90-decision-log.md)) |
| `knowledge`, `hedge` | **3** | Wörter, die «edge» enthalten und keine Kante meinen |

⚠️ **Was dabei kaputtging und was es gefunden hat:** *`inheritance-column-check` wurde rot und hatte
recht. Die Wanderung von TASK-018 hat ihre gemessene Gestalt als **Option** hinterlegt, und deren
Schlüssel hiess `edges`. **Der Leser suchte nach `relations`, fand nichts und meldete einen Umbau,
den es nie gegeben hat.** Geschrieben wird jetzt `relations`, gelesen werden beide — das ist
dieselbe Regel wie bei den Optionsnamen, nur eine Ebene tiefer.*

⚠️ **Der eine Dateiname, der bleibt:** *[`edge-class-check.php`](../../../scripts/dev/edge-class-check.php)
gehört zu TASK-032 und wurde im selben Baum gerade geschrieben. **Umbenannt wird er, wenn TASK-032
eingecheckt ist**; im Kopf der Datei steht, warum er heisst, wie er heisst.*

⚠️ *Kein Schnitt über Zeilenbereiche, keine gelöschte Methode: der Umbau ist ausschliesslich
Umbenennung. Geprüft mit `php -l` über jede Datei, mit dem Kernlauf (443 grün) und mit **allen**
Wächtern — grün bis auf die beiden, die schon vorher rot waren (`unitvalue-check` an `OQ-134`,
`always-on-check` an `INF-036`).*

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
[x] TASK-018  Vererbung wird nodes.parent_node_id + nodes.sort_order
```

[D-581](../../NewConcept/90-decision-log.md). Betroffen ist alles, was «ist es Vererbung?» fragt —
15 von 19 Verzweigungen auf `RelationKind`.

**Gebaut am 2026-09-05, Schema 28.** `nodes` bekommt `parent_node_id`, `sort_order` und `hide`, der
Schlüssel `one_place` geht über `(parent_node_id, sort_order)`; im Kern heissen sie
`Node::$parentNodeId`, `$sortOrder`, `$hide`. **`RelationKind::Inheritance` ist gefallen**, mit ihm
`Relation::inheritance()` und die fünf Baumleser des `RelationRepository` — ihre Ablösung steht im
`NodeRepository` (`nextPositionUnder()`, `reparentChildren()`, `allPlacements()`).

⚠️ **Die Zahlen vorher und nachher, und sie sind gezählt und nicht erinnert.** *Die Wanderung nimmt
sie selbst, vor und nach dem Schreiben, und **bricht mit einer Ausnahme ab, wenn eine abweicht** —
dann bleibt die Fassungsnummer stehen, was die einzige Art ist, wie `$wpdb` etwas melden kann.*

| | vorher | nachher |
|---|---|---|
| Knoten | 137 | 137 |
| Einordnungen (Vererbungskanten → Spalte) | 136 | 136 |
| Wurzeln | 1 | 1 |
| Tiefen 0…5 | 1 · 6 · 29 · 46 · 41 · 14 | 1 · 6 · 29 · 46 · 41 · 14 |
| Geschwisterreihenfolge (Prüfsumme über *Vater : Stelle : Kind*) | `ab865ea5…` | `ab865ea5…` |
| `hide` | 9 (an Kanten) | 9 (an Knoten) |
| `relations` | 193 | **57** |

⚠️ **Die Zahl aus der Entscheidung war eine ältere:** *D-581 rechnete mit 166 → 39. Das Modell ist
seither gewachsen; das Verhältnis stimmt — **mehr als zwei Drittel der Kantentabelle waren Baum**.*

⚠️ **Umkehrbar, und das ist nachgemessen:** *jede der 136 Knotenzeilen ging vor dem Schreiben nach
`nodes_history`, jede der 136 Kanten als gelöschte Zeile nach `relations_history` —
**136 von 136 in beiden Richtungen deckungsgleich**. Die Kanten bekommen dabei **keine**
Änderungsgruppe in `parked_by_group_id`: sie sind abgelöst und nicht geparkt, und eine Gruppe hätte
136 Geister in die Liste «entfernte Felder» gestellt.*

⚠️ **`hide` ist mitgekommen, und das war keine Wahl.** *[D-581](../../NewConcept/90-decision-log.md)
hält fest, dass `relations.hide` mit dieser Entscheidung **alle** seine Benutzer verliert, weil alle
Vererbungskanten sind. Fällt die Kante, ginge die Angabe verloren. **Was `hide` ist** — Spalte oder
Einstellung — bleibt vertagt, auf sein «das besprechen wir, wenn wir Settings nochmal umwerfen».
`relations.hide` bleibt stehen: sie trägt weiter das Verstecken eines **Feldes**.*

⚠️ **Zwei Schemaschritte sind gestrichen, und das gehört sichtbar** (`PR-9`): *`moveHideOntoTheEdge()`
(Fassung 12) hätte `nodes.hide` bei jedem Aufstieg wieder entfernt — **nach** dem `dbDelta`, das sie
gerade angelegt hat. `backfillInheritanceEdges()` (Fassung 3) hätte beim nächsten Aufstieg 136 Kanten
neu erfunden. **Beide waren richtig für ihre Fassung und sind jetzt Fallen**; eine Installation, die
noch unter Fassung 12 steht, verliert dadurch nichts — ihr `nodes.hide` steht schon dort, wo die
Angabe hingehört.*

⚠️ **Ein Wächter hat den ganzen Umzug bezahlt gemacht, ohne dafür gebaut worden zu sein:**
*`cleanup-screen-check` meldete **101 lebende Knoten als Rückstand**. `Residue::nodesWithoutConnections()`
fragte «keine Kante in beide Richtungen» — und die Vererbungskante war gerade fort. **Ein
Aufräumschirm, der 101 gesunde Knoten zum Wegwerfen anbietet, ist schlimmer als keiner.***

**Neu am Netz:** [`inheritance-column-check.php`](../../../scripts/dev/inheritance-column-check.php)
— die drei Spalten lebend und im Schatten, der Schlüssel über genau zwei Spalten, keine lebende
Vererbungskante mehr, **eine Wurzel, kein Zyklus, kein Kind ohne Vater**, der Pfad folgt der Spalte,
jede Einordnung ist im Schatten wiederzufinden, und die gemessene Gestalt ist noch die von der
Wanderung.

**Mitgezogen sind:** `package2-check` (Abschnitt 1 und 2 lesen die Spalte statt der Kante),
`sort-order-check` (Abschnitt 4 tauscht jetzt zwei **Felder**, denn der Schlüssel
`(from_node_id, kind, sort_order)` bewacht nur noch die), `preview-check`, `setting-write-check`,
`cleanup-screen-check` (seine Wiese muss beides kappen), `setting-branch-relation-check`, `used-by-check`
und `package5-check`. **Keiner davon ist entschärft** — jeder prüft dieselbe Zusage an der Stelle, an
der sie heute steht.

⚠️ **Drei Befunde stehen im Eingang:** *`INF-034` — `SeededFrameworkNodes` bekommt einen
Kantenspeicher, den sie nicht mehr liest (58 Aufrufstellen, fällt mit TASK-032); `INF-035` —
**`labels.owner_id` nennt ihren Raum nicht**, und seit ein Knoten keine Kante mehr anlegt, laufen die
beiden Id-Zähler verschieden schnell und treffen sich; `INF-036` — `always-on-check` ist über der
Decke, aus Gründen, die diesen Umbau nicht berühren.*

⚠️ **Ein Fehler im Ablauf, und er gehört ins Protokoll:** *der Probelauf war vorgesehen und ist nicht
gelaufen. **Die Wanderung startete von selbst**, als eine Messung `wp-load.php` einband — der Plugin
ruft beim Laden `Schema::ensureCurrent()`, und die Fassungsnummer stand schon auf 28. Sie hat
getan, was sie tun sollte, und ihre eigene Vorher/Nachher-Prüfung bestanden; **verlassen konnte man
sich darauf erst hinterher**. Wer eine Schemafassung hebt, hat die Wanderung ab diesem Augenblick
scharf gestellt — jeder `wp-load.php` löst sie aus.*

```text
[ ] TASK-019  labels und label_texts; name zieht aus nodes und relations hinein
```

[D-580](../../NewConcept/90-decision-log.md). **Grösster Einzelposten:** `nodes.name` ist indiziert und
wird an vielen Stellen gelesen. Reihenfolge `PR-12` — Wächter, Leser, Daten.

⚠️ *Offen davor: sind die vier Rollen Spalten in `label_texts` oder Zeilen mit `role_id`?*

⚠️ **2026-09-05 angelaufen und **nicht** gebaut — der Grund steht gezählt in `INF-040`.** *Die Teilung
und der Umzug des Namens sind ein Stück: die Umkehrung des Verweises allein erzeugt 194 leere
Beschriftungszeilen, der Umzug allein hat keine Tabelle. **Gemessen, was daran hängt: 137 Knoten- und
57 Kantennamen, 166 SQL-Stellen in 60 der 88 Prüfläufe, 20 685 + 25 124 Namen in den
Schattentabellen.** Dazu drei unentschiedene Fragen — die von D-580 selbst genannte (Rollen als
Spalten oder als Zeilen), `symbol` als Spalte gegen [D-262](../../NewConcept/90-decision-log.md)s
«ein Standard, keine Tatsache», und der Wegfall der neutralen Zeile, der die Standardsprache in den
Kern reichen müsste (`CD-1`).*

⚠️ **Was aus dieser Aufgabe **vorgezogen und gebaut** ist: die beiden Befunde, die sie vorausschickte
— Schemafassung 31.** *`labels.owner_kind` nennt den Raum ihres Eigentümers
([D-164](../../NewConcept/90-decision-log.md), [D-597](../../NewConcept/90-decision-log.md),
`INF-035`), und `labels.version` gibt es — sie war die einzige Tabelle ohne Zeilennummer, und
[D-634](../../NewConcept/90-decision-log.md) macht die Version beim Melden zum Pflichtwert.
**47 Beschriftungen vorher, 47 nachher, dieselbe Prüfsumme über Text und Stelle, 47 mal `node`, keine
ohne Raum.** Neu am Netz: `label-space-check.php`. Steht im Eingang unter `INF-035`.*

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
[x] TASK-032  Drei Werte, drei Klassen — und Komposition ist eine Aussage
              ueber Datensaetze                                    (D-639)
```

**Gebaut am 2026-09-05, und die Aufgabenzeile heisst anders als vorher.** *Sie versprach «ein
Schalter am Feld»; [D-639](../../NewConcept/90-decision-log.md) hat den Schalter wieder abgeschafft,
bevor er gebaut war. Sein Wort: «dann haben wir **ein Mittel**, das bestimmt, was für eine Verbindung
es ist, und nicht noch einen Schalter.» **Ein Schalter neben der Art wäre orthogonal — dann müsste
«Einstellung und mitlöschen» eine Bedeutung haben. Hat sie nicht.***

**Was steht:** `RelationKind` bleibt eine Spalte mit drei Werten, und hinter jedem Wert steht eine
Klasse — `SettingEdge`, `AggregationEdge`, `CompositionEdge`, alle drei `final` unter `Relation`, die
dafür `abstract` geworden ist. **Der Klassenname steht ausdrücklich nicht in der Zeile**, anders als
beim Knoten ([D-620](../../NewConcept/90-decision-log.md), `nodes.implemented_by`): *die Menge ist
geschlossen und hat drei Elemente; ein Name je Zeile wäre dieselbe Auskunft 57 mal statt einmal.*
Die Ableitung Wert → Klasse steht in `Relation::classFor()`, und **jeder Bauweg geht durch
`Relation::make()`** — auch `withKind()`, weshalb eine Kante beim Artwechsel ihre Klasse wechselt.

**Was verschwunden ist, gezählt:** *`RelationKind::isSetting()` und `isComposition()` waren die
einzigen beiden Stellen, die auf die Kantenart verzweigten (`$this === self::Setting`); sie sind
Verhalten geworden. Die **14 Aufrufer** im Quelltext fragen jetzt die Kante statt ihre Art und
verzweigen nicht mehr. **Gemessen nach dem Umbau: keine einzige Verzweigung auf `RelationKind` mehr
im Kern oder am Rand** — geblieben sind zwei Stellen, die eine Art **bauen** (`Branch::relationKind()`
und `ModelEditor::markAsSetting()`), und das ist keine Verzweigung.*

⚠️ **Und «erbt von» heisst jetzt, was es meint.** *`isComposition()` gab es nur, um zu sagen, dass
eine Einstellung eine Komposition ist. An ihrer Stelle steht `deletesRecordWithOwner()` — **eine
Aussage über Datensätze**, wie er sie berichtigt hat: «wenn ich einen Datensatz lösche — also den
Datensatz von Kunde A —, dann muss auch die Adresse von Kunde A gelöscht werden.» Der Zielknoten
bleibt selbstverständlich stehen.*

**Neu am Netz:** [`edge-class-check.php`](../../../scripts/dev/edge-class-check.php) — jede lebende
Kante trägt einen der drei Werte, die Ableitung ist vollständig und eindeutig, eine **geladene** Kante
kommt als ihre Klasse an, und **kein Datensatz hängt an zwei Besitzern**. *Gemessen im Bestand: 57
Kanten (composition 42, setting 11, aggregation 4), 415 Datensätze, 29 gehalten, **null Verstösse**.
Den Verstoss legt sich der Wächter selbst an und räumt ihn weg — sonst wäre er auch grün, wenn die
Abfrage gar nichts fände.*

⚠️ **Der Wächter, der hier fast gestanden hätte, und er steht als Warnung im Kopf der Datei:** *«eine
Komposition darf nicht auf ein geteiltes Ziel zeigen». **Gemessen zeigen 36 von 42
Kompositionskanten auf Typknoten** — `Text` 26 mal, `Einheitenwert` 6 mal. Ein Typ ist im Modell
geteilt; **die Prüfung hätte 36 richtige Zeilen berichtigt.** Modell und Daten vermischt, denselben
Fehler zum zweiten Mal.*

⚠️ **Nicht mitgebaut, `PR-4`:** *`Branch::relationKind()`, `holdsData()` und `storage()` fragen
weiter den Ast. [D-621](../../NewConcept/90-decision-log.md) parkt die beiden letzten ausdrücklich
(«wird nicht geraten»), und [D-622](../../NewConcept/90-decision-log.md) sagt zwar, dass die Ablage
eine Angabe mit zwei Fällen ist — **wer sie heute an `DataEntry::ownsItsRecord()` umhängt, ändert für
`Model` und `Constants`, welche Ziele einen eigenen Satz bekommen.** Das hat niemand entschieden.
Steht als `INF-038` im Eingang.*

⚠️ **Am 2026-09-05 nach TASK-018 angesehen und bewusst nicht angefangen, `PR-4`.** *Es fehlt eine
Entscheidung mitten im Weg: **woran erkennt man eine Einstellungskante, wenn `relation_type`
gefallen ist?** [D-587](../../NewConcept/90-decision-log.md) sagt «der Ast des Zielknotens»,
[D-622](../../NewConcept/90-decision-log.md) erklärt genau diese Hälfte für überholt und
[D-621](../../NewConcept/90-decision-log.md) streicht den Ast-Automatismus — **und keine Entscheidung
sagt, was an ihre Stelle tritt.** Gemessen hängen daran **16 Stellen** mit `isSetting()` und
**26 Verzweigungen** auf die Kantenart; sie entscheiden, ob ein Feld gezeichnet wird, ob ein Wert
geschrieben werden darf und welche Liste die Knotenseite zeigt. Steht als `INF-037` im Eingang.*

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
              Einstellung an ihm                       (geparkt, INF-033)
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
[x] TASK-038  Ein Verweis auf einen verschwundenen Knoten ist am Feld
              sichtbar, nicht in einer Liste woanders  (D-604)
[x] TASK-039  cleartrash-check leert den ganzen Papierkorb, nicht seinen
              Teil — er darf nur wegraeumen, was er selbst angelegt hat
```

**TASK-038 gebaut am 2026-09-05, und es war eine Zeile.** *Die **Anzeige** zeichnete den Verweis ins
Leere seit je als `#4711` in Rot ({@see ReferenceRenderer}); **der Auswahldialog zeichnete einen
Gedankenstrich** — dasselbe Bild wie «nichts gewählt». **Genau dort war die Leiche unsichtbar**, und
zwar im Bedienweg, also da, wo jemand hinsieht.*

⚠️ *Der Fall wird **abgeleitet, nicht gemeldet**: der Wert trägt einen Verweis, der Name kam nicht an.
**Keine neue Angabe an `Surroundings`** — die Unterscheidung stand schon da, sie war nur an einer
Stelle nicht gelesen.*

⚠️ **Gemessen: null Wertzeilen im Bestand zeigen ins Leere** — *der Wächter
[`dangling-reference-check.php`](../../../scripts/dev/dangling-reference-check.php) legt sich seine
Leiche darum selbst an (eigener Knoten, Wert darauf, Knoten löschen, zeichnen, nachsehen, wegräumen).
**Er zählt den Bestand und bewertet ihn nicht**: ein Verweis ins Leere ist nach
[D-604](../../NewConcept/90-decision-log.md) erlaubt — es ist die Hälfte, die der Benutzer mit «nein»
wählt. Falsch wäre nur, ihn auszublenden.*

⚠️ **Nicht gebaut und im Eingang als `INF-021`:** *ob zum Mal ein **Grund** gehört, wie ihn
[D-608](../../NewConcept/90-decision-log.md) für die gesperrte Zeile verlangt. Die Nummer ist
sprachlos und darum vom Kern zeichenbar; ein Satz wäre Benutzertext und müsste vom Rand kommen.*

⚠️ *TASK-037 — der **Dialog beim Löschen** — bleibt offen. Erst er erzeugt den Fall absichtlich;
sichtbar ist er ab jetzt.*

⚠️ **TASK-039 ist kein Notfall, aber es ist derselbe Fehler wie bei TASK-025:** *ein Wächter, der
mehr anfasst als seine eigenen Knoten. **«Geparkt, nicht gelöscht» gilt nicht, solange ein
Wächterlauf dazwischenkommt** — gemessen an `DisplayOption`, den der Eigentümer selbst geparkt
hatte und der beim nächsten Lauf endgültig fiel.*

**Gebaut am 2026-09-05.** `ModelEditor::clearTrash()` nimmt jetzt eine **Auswahl** entgegen: genannte
Knoten samt ihren Unterbäumen, alles andere im Papierkorb bleibt liegen. Ohne Angabe bleibt es der
Akt hinter dem Knopf und leert ganz. *Der Wächter nennt seinen eigenen Knoten und weist danach Knoten
für Knoten nach, dass das Fremde noch dasteht.*

⚠️ **Die vorige Fassung wich aus, statt zu lösen:** *sie räumte gar nicht, sobald Fremdes im
Papierkorb lag — auf einem Arbeitsstand mit geparkter Arbeit hätte sie den Akt also **nie** geprüft.
Die neue prüft ihn bei jedem Lauf.*

⚠️ **Und ein zweiter Befund fiel dabei an, gemessen:** *der Wächter baute seinen `ModelEditor` mit
einer Variablen, die es nie gab — **ohne Record-Repository**, also nahm der Akt in diesem Lauf die
Datensätze gar nicht mit. Die Zusage «its records went with it» stand nirgends und steht jetzt da
(`PR-9`). Im Eingang als `INF-017`.*

**Neu am Netz:** zwei Kernprüfungen (`clearTrash` räumt nur die genannten Knoten; ein Datensatz
entsteht erst beim Schreiben) und der Grenzwächter
[`record-on-first-write-check.php`](../../../scripts/dev/record-on-first-write-check.php).

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
[x] TASK-041  Die Marke «ist eine Einstellung» einmalig aus dem Ast fuellen —
              36 von 38 Knoten fehlen (D-606), mit Waechter, dass sie
              gepflegt bleibt
```

⚠️ **Beim Füllen ist `min` und `max` gesondert anzusehen:** *sie liegen im Ast, tragen keine Marke,
und **keine Kante zeigt auf sie**. Vermutlich sind sie kein Einstellungsknoten mehr, sondern Rest —
**nicht blind mitmarkieren.***

⚠️ *Danach kann [D-605](../../NewConcept/90-decision-log.md) greifen (ein markierter Knoten erbt
keine Einstellungskanten) — heute griffe die Regel an drei Knoten statt an achtunddreissig.*

**Gelaufen und nachgemessen am 2026-09-05.** *Der Ast trägt **37** Knoten unter der Wurzel, **36
tragen die Marke**, und `setting-kind-check` ist grün. **Zu markieren blieb nichts** — die Wanderung
[`setting-kind-migrate.php`](../../../scripts/dev/setting-kind-migrate.php) meldet im Probelauf
«Bekommen die Marke `setting`: 0».*

⚠️ **`min` und `max` sind gar nicht mehr im Ast** — die genannte Sonderprüfung ging ins Leere.
*An ihrer Stelle steht **ein anderer Rest**, auf den nichts zeigt: der Knoten `__Test`
(`149000087094`) aus einem abgestürzten Gerüstlauf. **Nicht angefasst**, weil er nicht mir gehört —
im Eingang als `INF-020` und ein Beleg für TASK-050.*

⚠️ **TASK-040 neu gefasst am 2026-09-04.** *Die erste Fassung (`kind = setting` erbt nichts,
[D-605](../../NewConcept/90-decision-log.md)) war zu breit — sie hätte `render with label` den
geerbten `converter` genommen. **Er hat es gesehen, bevor es gebaut war.** Gemessen betrifft die
neue Fassung zwei Kanten: `Root --validator--> Validator` und `Root --read_only--> read_only`.*

⚠️ *Und `min`/`max` sind damit **kein Rest**: sie wurden ausgelagert, um genau dieser Rekursion
auszuweichen — «wenn wir am Knoten `int` die `min`/`max` einhängen und `int` `min`/`max` als Setting
hat, erben `min`/`max` diese wieder». **Mit [D-607](../../NewConcept/90-decision-log.md) fällt der
Grund weg**, sie können zurück unter `Integer`.*

```text
[x] TASK-043  Ein Datensatz entsteht beim ersten Schreiben, nicht beim
              Ansehen (D-609) — behebt BUG-004
```

**Gebaut am 2026-09-05.** *`DataEntry` hat jetzt zwei Wege statt einem: `findDefaultRecord()` **sucht**
und gibt `0` zurück, `defaultRecordOf()` **legt an**. Wer liest oder löscht, fragt den ersten — nur
das Schreiben nimmt das Anlegen in Kauf.* **Damit legt `clearSettingAt()` nichts mehr an**, und die
Frage «ist das Ziel ein eigener Datensatz?» wird am **Knoten** beantwortet statt am Datensatz, der
dafür erst entstehen musste.

⚠️ **Was noch dasteht, bleibt stehen** (`INF-017`): *die 324 vorhandenen leeren Datensätze rührt
dieser Umbau nicht an — das ist TASK-044, und die Grenze aus
[D-610](../../NewConcept/90-decision-log.md) gilt: unbenutzt heisst nirgends referenziert, nicht ohne
Wertzeilen.*

**Gemessen: 324 von 377 Datensätzen tragen keine einzige Wertzeile, 287 davon `default`.**
*Sie entstehen in `DataEntry::defaultRecordOf()`, gerufen auch aus `clearSettingAt()` — **Löschen
legt an**, bevor es merkt, dass nichts zu löschen ist.*

⚠️ **Nicht entschieden: was mit den 324 vorhandenen geschieht.** *Sie tragen nichts, also verliert
ihr Wegräumen nichts — aber es ist eine Handlung an seinen Daten und gehört gefragt, nicht
nebenbei erledigt.*

```text
[x] TASK-044  Einmaliger Reset: die 2 Zeilen mit value_ref = 0 und die
              wirklich unbenutzten leeren Datensaetze (D-610)
```

⚠️ **Die Grenze ist das Wichtige an dieser Aufgabe, nicht die Zahl.** *Von 324 leeren Datensätzen
stehen **26 in `nodes.settings_record_id`** — sie tragen ihre Aussage in der `node_id` («dieser
Renderer ist gewählt, nichts daran eingestellt»). **Wer sie als leer wegräumt, löscht 26
Renderer-Wahlen.** Der Reset braucht deshalb eine Bedingung, keine Zählung: *unbenutzt* heisst
nirgends referenziert — nicht *ohne Wertzeilen*.

**Gelaufen am 2026-09-05**, [`scripts/task044-047-reset.php`](../../../scripts/task044-047-reset.php).
*Die Bedingung steht im Lauf und nicht in einer Liste: **jede der vier Richtungen einzeln gefragt** —
eigene Wertzeilen, fremde Verweise, `nodes.settings_record_id`, die beiden Spalten an `relations`.*

⚠️ **Gefallen ist die Teilmenge, bei der zusätzlich der Knoten selbst fort ist:** ***eine** Zeile mit
`value_ref = 0` (von den zweien aus D-610 stand noch eine, in Satz `2233`) und **29 Datensätze ohne
Knoten** — Rückstand der drei abgestürzten Gerüstläufe vom 2026-09-04, alle ohne eine einzige
Wertzeile und von nichts referenziert. **Die 26 Renderer-Wahlen sind nicht angefasst**, und die
übrigen leeren Datensätze an lebenden Knoten stehen weiter (`INF-017`).*

⚠️ *Umkehrbar: jede Zeile steht vorher als Schattenzeile mit `deleted = 1` in ihrer `_history`
([D-535](../../NewConcept/90-decision-log.md)).*

⚠️ **Ein vierter Posten ging mit und hat nichts gelöscht:** *zwei Wertzeilen nannten im `path` die
Kante `44092`, während ihr eigenes `edge_id` die lebende `65595` nennt. **`path` ist der Spiegel von
`edge_id`** (TASK-002) und trägt keine eigene Aussage — der Spiegel wurde auf seinen Herrn gestellt.
Herkunft: `44092` war die `converter`-Kante des gestrichenen Hüllknotens `DisplayOption`
([D-585](../../NewConcept/90-decision-log.md)); die Wanderung zog `edge_id` nach und liess den
Spiegel stehen. **Gemessen: es waren die einzigen zwei Zeilen im Bestand, bei denen die Spalten
auseinandergingen.***

```text
[ ] TASK-045  Der Schreiber sucht die Einstellungskante an beiden Ketten —
              Besitzer und Ziel (D-611). Nur fuer Einstellungen, nicht fuer
              Modellfelder.
[x] TASK-047  Zwei Kanten auf den Zweigkopf «Constants» entfernen —
              Rueckstand aus Waechterlaeufen, ohne Eintrag im Aenderungsbuch
[ ] TASK-046  Die vier Waechter auf die Spaltenform umschreiben — sie fragen
              nach den Kanten 44091/44093, die es nicht mehr gibt
```

⚠️ **TASK-047 erledigt am 2026-09-05 — und die Quelle mit, sonst wäre es in einer Woche wieder da.**
*Die zwei Kanten `Decimal --renderer--> Constants` und `Integer --read_only--> Constants` legt
**`package7-check` selbst** an: sein Behelf `$kanteFuer()` erzeugt eine fehlende Einstellungskante mit
dem **Zweigkopf `Constants` als Platzhalterziel** und räumte sie nie weg. **Gemessen: sie standen nach
jedem einzelnen Lauf wieder da**, und weil sie an **gesäten** Knoten hängen, greift die
`__p7%`-Aufräumung nicht an sie heran. Jetzt merkt sich der Lauf, was er selbst gelegt hat, und nimmt
es am Ende zurück — aufheben, dann löschen.*

⚠️ *Derselbe Fehler wie in TASK-025 und TASK-039: **ein Wächter, der mehr anfasst als seine eigenen
Knoten.** Der Eigentümer hat den Rückstand in seinem Modell gefunden, nicht der Wächter.*

⚠️ **Zu TASK-046, gemessen:** *die Optionen `taxmod_setting_edge_renderer` (44093) und
`taxmod_setting_value_edge_renderer` (44091) zeigen auf **gelöschte Kanten**. Sie hingen am
Hüllknoten `DisplayOption`; seit [D-584](../../NewConcept/90-decision-log.md) steht der Renderer in
`nodes.settings_record_id` und nicht mehr an einem Kantenpaar. **Alle zwanzig verbliebenen
Fehlschläge von `setting-write`, `page-blocks`, `multiplicity` und `renderer-choice` fragen nach der
alten Form.** Das umzuschreiben ist eine sichtbare Konzeptänderung (`PR-9`) und gehört benannt.*

```text
[x] TASK-048  Ein Klick auf den Klapp-Pfeil gewinnt gegen den Vorrang der
              Auswahl (D-612) — heute tut er sichtbar nichts, wenn der
              gewaehlte Knoten in diesem Ast liegt
```

⚠️ *Die Stelle ist eine Zeile in `NodesScreen`: `$collapsed = array_diff($collapsed,
$selected->ancestorIds())`. **Sie stammt aus [D-480](../../NewConcept/90-decision-log.md) und ist
richtig** — sie darf nur nicht gegen eine ausdrückliche Handlung gewinnen.*

**Gebaut am 2026-09-05, und die Zeile steht noch da.** *Sie greift nur noch beim **Wechsel** der
Auswahl: die Seite merkt sich in ihrer Adresse, für welchen Knoten sie den Weg schon geöffnet hat.
Ist es derselbe, hat die Hand recht und das Zuklappen hält; ist es ein anderer, ist der Weg neu und
wird geöffnet. **Damit bleibt [D-615](../../NewConcept/90-decision-log.md) unangetastet** — die
Knoten-Angabe öffnet weiterhin einen Weg und fasst keinen anderen Ast an.*

⚠️ *Der Merker reist wie der Faltzustand: in den Links **und** in den Formularen, weil ein Akt eine
POST ohne Adresse ist. Ohne die zweite Hälfte hielte das Zuklappen genau einen Klick lang — derselbe
Fehler wie in [D-480](../../NewConcept/90-decision-log.md).*

**Am Netz:** `collapsed-default-check` bekam einen Abschnitt statt eines zweiten Wächters — zuklappen
mit gewähltem Knoten im Ast, **und** die Gegenprobe, dass ein frisch gewählter Knoten seinen Weg
weiterhin öffnet. *Ohne die Gegenprobe wäre auch grün, was das Öffnen ganz abgeschafft hätte.*

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
[x] TASK-051  Die Konfigurationsseite haelt den Faltzustand — «alles zu»
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

**Gebaut am 2026-09-05, und Zeile 202 blieb stehen — sie war nicht der Fehler.** *Sie berechnet die
Vorgabe **für den ersten Aufruf**, und das ist sein Wunsch von 2026-08-28. Falsch war, wie oft ein
Aufruf als «der erste» ankam.*

⚠️ **Gemessen an der Seite von `Adresse`: 10 von 17 Formularen trugen den Faltzustand nicht** —
jeder Akt aus einem von ihnen kam ohne Angabe zurück, und dann greift die Ast-Angabe, die niemand
übergeben hat. *Die Sprachwahl baute ihre Adresse ausserdem selbst und verlor ihn ebenfalls.*

⚠️ **Der Grund war nicht Vergesslichkeit, sondern fünf Stellen, die dasselbe verschieden bauten:**
*die verborgenen Felder hatten den Rückfall auf den gemerkten Zustand, die Formulare der Baumzeilen
lasen nur die Adresse — und auf einer frischen Seite steht dort nichts. **Jetzt gibt es eine
Stelle**, und alle fünf nehmen von dort.*

⚠️ *Der Dialogbaum aus TASK-054 ist nicht angefasst: er geht über `Rendering::nodeChooser()` und
bekommt vom Faltzustand der Seite ohnehin nichts mehr.*

**Am Netz:** `collapsed-default-check` zählt jetzt die Formulare der gezeichneten Seite und verlangt
null ohne Faltzustand — *mit der Bedingung, dass es überhaupt Formulare gibt, sonst wäre eine leere
Zählung grün.*

---

[x] TASK-052  Die Renderer-Wahl wird nicht beruecksichtigt und nicht gespeichert

**2026-09-05, vom Eigentümer gefunden:** *«der wird irgendwie aktuell nicht berücksichtigt und auch
nicht gespeichert».*

⚠️ **Seine Aussage ist der Befund, und sie widerlegt vier grüne Wächter.** *`renderer-choice-check`,
`setting-write-check`, `page-blocks-check` und `multiplicity-check` sind alle grün — sie prüfen
also **nicht das, was er tut**. Vermutung, ungemessen: sie schreiben über den Kern und er über die
Maske, und dazwischen liegt der Rand. **Zu messen ist der Weg vom Klick bis in die Zeile**, nicht
noch einmal der Kern.*

⚠️ **Zwei Enden, und beide müssen geprüft werden:** *«nicht gespeichert» ist der Schreiber am Rand,
«nicht berücksichtigt» der Leser beim Zeichnen. **Es können zwei Fehler sein oder einer** — wenn
nichts ankommt, sieht der Leser genauso aus, als hätte er nicht hingesehen.

⚠️ **Und ein Wächter fehlt hier, gleich welcher Fehler es ist:** *keiner der vier geht den Weg über
die Maske. Der neue muss genau das tun — wählen, speichern, neu laden, nachsehen —, sonst ist es
derselbe blinde Fleck beim nächsten Mal.*

Gehört zu [D-617](../../NewConcept/90-decision-log.md) und `INF-014`. **Block A.**

**Gebaut am 2026-09-05. Es war *ein* Fehler, nicht zwei — und ein anderer als vermutet.**

⚠️ **Gemessen am Markup der Seite von `Integer`: kein einziges Steuerelement mit `renderer` im
Namen.** *Nicht «der Schreiber findet die Kante nicht», sondern **es gab nichts zu speichern**. Der
Wähler stand in der **Wertspalte des Einstellungsblocks**, und dieser Block zeichnet **Kanten**; seit
[D-584](../../NewConcept/90-decision-log.md) steht der Renderer in `nodes.settings_record_id`, und
mit dem Hüllknoten `DisplayOption` ([D-594](../../NewConcept/90-decision-log.md)) fiel seine
Trägerkante. **Der Wähler ging mit ihr, und kein Wächter merkte es.***

⚠️ **«Nicht berücksichtigt» ist der Schatten desselben Fehlers, gemessen:** *was gespeichert **ist**,
wird gezeichnet — die Vorschau von `Integer` zeigt den Schieber, den die Spalte nennt. Es sah nur
aus wie zwei Fehler, weil nie etwas ankam.*

**Was durchgeht:** die Zeile zeichnet {@see Rendering::settingsFor()} längst; sie hängt jetzt über
`form="…"` am Seitenformular und wird über den **Schlüssel** angenommen — nicht über eine Kanten-Id,
denn es gibt keine Kante. Von dort in `nodes.settings_record_id` über den vorhandenen Kernweg
`DataEntry::chooseSettingRecordAtNode()`.

**Neu am Netz:** [`renderer-choice-mask-check.php`](../../../scripts/dev/renderer-choice-mask-check.php)
— **der erste Wächter, der den Weg über die Maske geht**: eigener Knoten, Seite zeichnen, Markup
lesen, `handlePost()` mit einem echten Nonce rufen, frisch auflösen, nachsehen, wegräumen. *Die vier
grünen gehen über den Kern und waren genau deshalb blind.*

⚠️ **Offen und im Eingang als `INF-019`:** *wo so eine Zeile auf Dauer hingehört (eigener Block oder
kantenlose Zeile im Einstellungsblock) und was «kein Renderer» heissen soll. **Gezeichnet wird
bewusst nur der Renderer** — gemessen ist er der einzige Schlüssel, den der Leser aus der Spalte
holt.*

---

[ ] TASK-053  Die Kantenart wird angegeben, nicht geraten (D-618)

Drei Teile, aus [D-618](../../NewConcept/90-decision-log.md):

1. **`addField()` bekommt die Art als Angabe** statt sie aus dem Zielast abzuleiten.
2. **Der Satz auf der Oberfläche fällt** — *«‹Kind› is not a choice — it follows from where the
   target sits in the tree»* war nie wahr; an seine Stelle tritt eine Wahl.
3. **Ein Wächter:** jede Kante, die in den Einstellungsast zeigt, ist eine Einstellungskante.
   *Gemessen am 2026-09-04 stimmt das — der Wächter hält fest, er stellt nicht her.*

**Teil 3 gebaut am 2026-09-05:**
[`setting-branch-relation-check.php`](../../../scripts/dev/setting-branch-relation-check.php). *Er meldet und
ändert nichts. **Nachgemessen: 4 Kanten zeigen in den Ast, alle vier tragen `setting`** — null
Abweichungen. Vererbungskanten sind ausgenommen, weil sie **der Ast selbst** sind.*

⚠️ **Vier ist wenig, und das gehört dazugesagt:** *die meisten Einstellungskanten zeigen gar nicht in
den Ast, sondern auf einen Typ (`read_only` → `Boolean`). **Der Wächter deckt darum eine Richtung ab,
nicht die Einstellungskanten insgesamt** — die Umkehrung («markiert heisst im Ast») wäre falsch, aus
demselben Grund wie bei [D-606](../../NewConcept/90-decision-log.md).*

⚠️ *Der Gegenfall steht im Lauf: ein eigener `__astkante`-Knoten im Ast plus ein Feld darauf muss
**rot** werden, und dieselbe Kante als Einstellungskante wieder grün. **Ohne ihn wäre «null
Abweichungen» auch dann wahr, wenn die Regel gar nichts prüft.** `addField()` erzeugt den Gegenfall
auf dem gewöhnlichen Weg — genau die Ableitung, die Teil 1 ablösen soll.*

⚠️ *Teil 1 und Teil 2 sind **nicht** gebaut.*

⚠️ *Ableiten ist damit nicht verboten, sondern vertagt: «später, aber auch nur vielleicht».*

---

[x] TASK-054  Der Faltzustand des Dialogs ist der des Dialogs, nicht der der Seite

**2026-09-05, vom Eigentümer gemeldet:** *«der baum im dialog funktioniert noch nicht so wie er
soll, er hat noch die einstellungen bezüglich elapsed und collapsed von der baumansicht auf der
einstellungsseite, dies muss unabhängig voneinander sein.»*

**Was gelten soll, in seinen Worten:** *«im dialog muss alles collapsed sein bis auf den default
ast oder/und wenn knoten gegeben wird der ast des knotens, der benutzer muss aufklappen wenn er
was anderes braucht — und das gilt nicht nur für die oberste ebene sondern auch für alle darunter.»*

⚠️ **Zwei Ansichten, ein Zustand — das ist der Fehler.** *Der Faltzustand der Seitenansicht reist
heute in den Dialog hinein. **Er gehört ihm nicht:** die Seite hält, was der Benutzer sich dort
eingerichtet hat; der Dialog fängt jedes Mal am selben Punkt an, weil er eine Frage stellt und
keine Arbeitsfläche ist.*

⚠️ **«Alle darunter» ist die Hälfte, die man übersieht:** *heute genügt es, die oberste Ebene zu
schliessen, und die Tiefe bleibt offen. Verlangt ist **zu bis auf einen Weg** — den des
Einstiegsastes und den des vorgewählten Knotens, und sonst nichts.*

Gehört zu [D-615](../../NewConcept/90-decision-log.md) und TASK-036, TASK-048, TASK-051.

**Gebaut am 2026-09-05. Es war eine einzige Übergabe, und sie stand seit dem Verschiebe-Dialog da.**

⚠️ **Gemessen: `parentChooser()` bekam die Zeilen der Seitenansicht gereicht** — dieselben, die die
Seite gerade zeichnete, **mit ihrem Faltzustand und, bei gesetztem Filter, nur den Treffern**. Der
Dialog war damit nicht «vom Faltzustand der Seite beeinflusst», sondern **war** die Seitenansicht in
einem Überlagerungsfenster. *Die anderen drei Dialoge gingen längst über `Rendering::nodeChooser()`
und waren richtig; dieser eine war der Rest von vorher.*

⚠️ **Und die zweite Hälfte fehlte auch dort:** *`closedApartFrom()` öffnete nur den Weg des
Einstiegsastes, nicht den des **vorgewählten** Knotens. Nach [D-615](../../NewConcept/90-decision-log.md)
sind das zwei Angaben — der Ast schliesst den Rest, der Knoten öffnet nur seinen Weg — und der
Dialog wandte bisher nur die erste an. Liegt die Vorauswahl in einem anderen Ast, sah man sie nicht.*

⚠️ *«Alle darunter» war dagegen schon richtig und ist jetzt bloss geprüft: geschlossen wird jede
Zeile, die nicht auf einem der beiden Wege liegt, auf jeder Tiefe.*

**Neu am Netz:** [`collapsed-default-check`](../../../scripts/dev/collapsed-default-check.php) prüft
jetzt auch den Dialog — erweitert statt ein zweiter Wächter, weil dort schon der Faltzustand der
Seite steht und die beiden nur nebeneinander etwas heissen. Er zeichnet die echte Seite **einmal
weit offen und einmal frisch** und liest das Markup des Verschiebe-Dialogs: der Enkel steht im
Dokument (der Dialog klappt ohne Neuaufbau), ist aber ausgeblendet; **genau ein Ast trägt eine
offene Klappmarke**, also steht unterhalb kein zweiter offen; und **beide Seitenzustände ergeben
denselben Dialog**.

⚠️ **Gegengeprobt, sonst wäre «grün» nichts wert:** *mit der alten Übergabe fallen 5 der 9 Zusagen,
darunter die Unabhängigkeit; mit abgeschaltetem Schliessen im Kern fallen 4.*

---

[ ] TASK-055  Warum sich ein Knoten nicht verschieben laesst

**2026-09-05, vom Eigentümer gemeldet:** *«schau warum ich knoten nicht verschieben kann — wollte
`stree/…` und `zip/…` nach `combined` verschieben, weil es dort besser hinpasst; ist ein typ der
aus zwei feldern besteht.»*

⚠️ **Erst messen, dann bauen.** *Ob der Akt fehlt, ob er verweigert oder ob er still nichts tut,
ist ungemessen. **Sein Satz ist der Befund** — die Frage ist nur, an welcher der drei Stellen es
hängt.*

⚠️ *Der Fall ist der begründete: ein Typ aus zwei Feldern gehört unter `Combined` und nicht dort,
wo er heute liegt. Das ist keine Aufräumlaune, sondern seine Einordnung — **und dass sie sich nicht
vollziehen lässt, ist der Fehler**, nicht die Einordnung.*

**Gemessen am 2026-09-05. Es war keiner der drei Fälle sauber — es war der dritte in seiner
leisesten Form: der Akt tut nichts, weil das Ziel nicht anzuklicken ist.**

⚠️ **Der Akt ist vollständig da und er hält.** *Kern, Rand und Maske: die Seite zeichnet den
Knopf, die Zeilen tragen `target`, beide stecken im selben Formular, das Abschicken läuft durch,
der Knoten hängt danach unter dem Ziel, sein Pfad sagt dasselbe wie seine Kante, das Kind wandert
mit, eine Schattenzeile steht da und das Änderungsbuch trägt «moved» mit Vorher und Nachher.
**Nichts davon musste gebaut werden.***

⚠️ **Woran es hing, gemessen an derselben Seite zweimal am selben Abend:** *der Wähler im
Verschiebe-Dialog bot **nur die aufgeklappten Zeilen** an — 12 Ziele, und `Combined` war keines
davon. Ein geschlossener Ast war schlicht kein möglicher Ort. **Nach dem Umbau des Dialogs
(TASK-054) sind es 123 Ziele**, und für seine vier Knoten — `Street / H#`, `Zip/City` in zwei
Ästen — ist `Combined` jetzt wählbar. Nichts weiter verweigert: keiner der vier ist geschützt,
jeder hat seine Vererbungskante, keiner enthält das Ziel.*

⚠️ *Seine Knoten sind **nicht** verschoben worden. Das ist seine Einordnung und sein Klick.*

**Neu am Netz:** [`move-mask-check.php`](../../../scripts/dev/move-mask-check.php) — eigene Knoten
(`__mv `), Seite zeichnen, Markup lesen, abschicken, frisch nachlesen, wegräumen. **Die Zusage
schliesst das Ziel ein**: nicht nur *irgendein* `target`, sondern *dieser Zielknoten steht als
wählbare Zeile darunter*. *Ohne diesen einen Punkt wäre der Wächter an genau dem Abend grün
gewesen, an dem er nicht verschieben konnte.*

---

[x] TASK-056  Die Version wird beim Melden mitgeschrieben

**2026-09-05.** *Das Aenderungsbuch ist auf sein Wort **vollstaendig** geleert worden — erst «alte ohne Version wegschmeissen» (35 524 Zeilen), dann «einmal komplett leeren, nicht selektiv» (die restlichen 162). Es steht jetzt auf null. **Damit ist der Zustand aufgeraeumt, die Ursache
nicht:** die Version wird beim Melden nicht mitgeschrieben, und ohne diese Aufgabe steht in einer
Woche derselbe Befund da.*

⚠️ **Gemessen vor dem Loeschen:** *160 Zeilen trugen eine Version, die aelteste vom 31. August —
**es war also nie eine Frage von alt und neu**, sondern von *welcher Weg schreibt*. Die juengste
Zeile ohne Version war vom Tag des Loeschens, 15:51 Uhr.*

⚠️ **Die zweite Haelfte derselben Luecke** ([D-631](../../NewConcept/90-decision-log.md)):
*`DataEntry` haelt ueberhaupt kein Aenderungsbuch — 4 354 Schattenzeilen bei Datensatzwerten und
null Chronikzeilen. **Wertaenderungen melden heute nichts**, und `§7` des Ereigniskonzepts verlangt
die Version im Kontext jedes Ereignisses.*

⚠️ *Die geloeschten Zeilen liegen als Sicherung im Arbeitsordner der Sitzung — beide Schritte
einzeln (`changelog-ohne-fassung.jsonl`, 35 524 Zeilen, 8,9 MB; `changelog-rest.jsonl`, 162
Zeilen) —, **nicht im Projekt**, weil sie dorthin nicht gehoeren, aber bis zum Sitzungsende
zurueckholbar.*

⚠️ **Nach dem Leeren nachgemessen, damit «es hat nichts gekostet» kein Eindruck bleibt:** *439
Kerntests gruen, `cleanup-screen`, `used-by`, `package5`, `package7` und `inheritance-column`
gruen. **Der Aufraeum-Bildschirm holt seine entfernten Felder also nicht allein aus dem Journal** —
sonst waere er der erste gewesen, der rot wird.*

**Gebaut 2026-09-05.**

⚠️ **Der Vorgabewert ist weg, und das ist der ganze Punkt.** *`Changelog::record()` nimmt die
Version jetzt **vor** der Aenderungsgruppe und **ohne** Vorgabe entgegen; wer meldet, muss sie
hinschreiben. Beide Umsetzungen und der Doppelgaenger im Test tragen dieselbe Signatur, damit ein
Kerntest nicht gruen sein kann, wo der Rand schweigt.*

⚠️ **Und der Stapelweg schrieb die Spalte gar nicht erst.** *`recordMany()` fuellte `version` in
keiner einzigen Zeile — nicht «vergessen zu uebergeben», sondern **im `INSERT` nicht vorhanden**.
Gefunden beim Umbau, nicht gesucht.*

**Angefasst: 25 Meldestellen** — 17 in `ModelEditor`, 6 in `Residue`, je eine in `Labels` und
`SeededFrameworkNodes`. *Alle nennen die Version der Zeile, die die Aenderung erzeugt hat.*

⚠️ **Drei Stellen kennen sie nicht, und das steht hier statt einer erfundenen Zahl** (`PR-4`):

| Wo | Warum es keine gibt |
|---|---|
| `label set` / `label cleared` / `labels removed` | **`labels` hat keine Versionsspalte** — gemessen am Schema, anders als `nodes`, `relations`, `records` und `record_values`. Ob Labels versioniert werden, ist nicht entschieden: [`neues-konzept-eingang.md`](../../neues-konzept-eingang.md). |
| `trash cleared` | Ein Sammelakt ueber hunderte Zeilen mit je eigener Version; der Papierkorb selbst aendert sich nicht. Eine davon auszusuchen waere geraten. |

*Der Waechter kennt genau diese Verben namentlich — kommt ein anderes ohne Version dazu, wird er
rot.*

**`DataEntry` hat sein Aenderungsbuch.** *Es meldet `record created`, `record removed`,
`value set`, `value appended`, `value cleared` und `part linked` — im Format aller anderen Melder
(`FrozenState`, Adresse als `path`, Wert zuletzt) und in der **vorhandenen** Akt-Klammer, nicht in
einer zweiten. Der Betreff ist der Datensatz, die Version die der geaenderten Zeile.*

⚠️ **Damit das ueberhaupt gehen konnte, gibt der Speicher die Version jetzt zurueck**:
`putValue()` liefert sie, die drei Vergess-Wege die der entfernten Zeile. *`EdgeRecord` traegt
keine Version — die Nummer entsteht im Speicher, und **genau deshalb konnte der Kern sie bisher
nicht melden**. Kein Raten an keiner Stelle.*

**Neu am Netz:** [`version-check.php`](../../../scripts/dev/version-check.php) — eigene Knoten
(`__ver `), Modellaenderung und Wertaenderung ueber die **echte Verdrahtung**, dann die Frage
**ueber den ganzen Bestand** statt nur ueber die eigenen Ids. *Beide Zusagen beissen, testweise
geprueft: Version weglassen faerbt sie rot, Meldung herausnehmen ebenso.* Dazu drei Kerntests in
`DataEntryTest`.

⚠️ *Nebenbefund, nicht behoben: `unitvalue-check` raeumt seine `__uv`-Knoten weg, **seine
Chronikzeilen aber nicht** — zwei Waisen lagen im Buch und liessen den neuen Waechter zu Recht rot
werden. Sie sind entfernt; die Luecke im Waechter selbst ist es nicht.*

---

[ ] TASK-057  Die Renderer-Einstellung zieht von der Spalte auf eine Kante 1..1 zurueck

**2026-09-05, [D-642](../../NewConcept/90-decision-log.md).** *Er hat berichtigt, was ich aus
[D-584](../../NewConcept/90-decision-log.md) gemacht hatte: «ich meinte einfach eine Multiplizitaet
von 1» — **am Knoten**, an einer gewoehnlichen Einstellungskante. Nicht an einer Spalte.*

**Was zurueckzubauen ist, gemessen:** *`nodes.settings_record_id` (29 Traegerzeilen),
`relations.settings_record_id` und `relations.target_settings_record_id` (**je 0 Zeilen — sie waren
nie belegt**), dazu die Schattenspalten. **17 Quelltextdateien** kennen die Spalte.*

⚠️ **Die Wanderung ist keine Erfindung, sondern eine Umkehrung:** *jeder Traeger wird eine
Einstellungskante `renderer` mit `1..1` auf den Renderer-Knoten, und der Satz haengt daran wie bei
jeder anderen Einstellung. **Der Zielzustand ist der, den es vor TASK-020 gab** — nur mit der
Mehrfachheit, die er gemeint hat.*

⚠️ **Warum es nicht nur Kosmetik ist:** *die Sonderform hatte einen Sonderfehler. Als der
Eigentuemer `DisplayOption` loeschte, fiel die Traegerkante — **der Leser kam ueber die Spalte
weiter, der Schreiber in der Maske nicht**, und vier gruene Waechter merkten nichts (TASK-052).
Dazu steht sie quer zu [D-621](../../NewConcept/90-decision-log.md) («die Kante sagt, was etwas
hier ist») und zu [D-639](../../NewConcept/90-decision-log.md) (drei Kantenarten, drei Klassen).*

⚠️ **Offen ist nur der Zeitpunkt:** *jetzt oder mit dem Renderer-Umbau. Beruehrt 17 Dateien.*

⚠️ **Auf sein Wort vertagt (2026-09-05): «nicht gleich bauen».** *Die Aufgabe steht, der Zeitpunkt
ist offen. **Bis dahin bleibt die Spalte in Betrieb** — sie ist gemessen tragfaehig (29 Traeger,
`renderer-choice-mask-check` gruen), sie ist nur nicht die Form, die er gemeint hat.*

---

[ ] TASK-058  Der gewaehlte Renderer wird auch verwendet — nachweisen, nicht annehmen

**2026-09-05, sein Auftrag nach TASK-057:** *«schau danach, dass der Renderer auch verwendet wird.»*

⚠️ **Zwei Dinge sind zu trennen, und heute prueft niemand das zweite:** *(1) die Wahl wird
**gespeichert** — das haelt `renderer-choice-mask-check` seit TASK-052; (2) der gespeicherte
Renderer **zeichnet auch wirklich**. **Zwischen beidem liegt die Aufloesungskette**, und dort ist
schon zweimal etwas verlorengegangen: die Umbenennung der Traegerkante ([D-543](../../NewConcept/90-decision-log.md),
sechs Knoten zeichneten `plain`) und der Wegfall des Huellknotens ([D-604](../../NewConcept/90-decision-log.md)).*

**Zu bauen ist die Zusage, die beides verbindet:** *fuer jeden Traeger — waehlen, speichern, frisch
aufloesen, **zeichnen**, und im Ergebnis muss der gewaehlte Renderer stehen. Nicht «ein Renderer»,
sondern **der gewaehlte**.*

⚠️ *Sein Satz endete mit «das klappt naemlich aktuell» — ob «klappt» oder «klappt nicht» gemeint
war, ist nicht sicher. **Die Zusage deckt beide Lesarten ab:** sie haelt fest, was funktioniert, und
faellt rot, wo es nicht funktioniert.*
