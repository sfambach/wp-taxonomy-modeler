# Paket · Datenbank — Arbeitsliste

**Neue Aufgaben werden hinten angehängt.** Der Status wird nach Erledigung nachgezogen.

⚠️ *Reihenfolge bei jedem Umzug: **Wächter, Leser, Daten** ([`arbeitsmodell.md`](../../arbeitsmodell.md) §5).
Eine Wanderung, bei der die Daten vorangehen, bleibt grün und zeigt still das Falsche.*

---

```text
[x] TASK-001  path aus nodes entfernen
```

Beschlossen am 2026-09-01. **Die teuerste der vier**: `WpdbNodeRepository` fasst die Spalte an
**20 Stellen** an, und sie ist **indiziert** — der Vorfahrenweg muss danach aus `relations` kommen.
`path-check.php` prüft heute eigens diese Spalte und zieht mit.

⚠️ **Gebaut am 2026-09-05, Schema 35 — und der teuerste Teil war schon erledigt.** *Die Beschreibung
oben verlangt, der Vorfahrenweg müsse danach «aus `relations` kommen». **Das war vor TASK-018.** Seit
[D-581](../../NewConcept/90-decision-log.md) ist `nodes.parent_node_id` der Baum, und
[D-082](../../NewConcept/90-decision-log.md) nannte die punktseparierte Kette von Anfang an «derived
and rebuildable». Was fiel, ist die **zweite Ablage derselben Tatsache** — nicht die Tatsache.*

⚠️ **Ein `Node` trägt den Weg weiter, in genau derselben Form.** *Er wird beim Lesen gerechnet, in
einem rekursiven Ausdruck, den jeder Leser gleich benutzt (`WpdbNodeRepository::ancestry()`) — eine
Anweisung, keine Runde je Ebene (`CD-7`). **Damit brauchte keine der gesperrten Dateien angefasst zu
werden**: `ModelEditor`, `NodesScreen` und `RendererChoiceRenderer` fragen `$node->path`,
`ancestorIds()` und `isDescendantOf()`, und die antworten wie zuvor.*

**Die Zahlen, vorher und nachher:**

| | vor der Wanderung | nach ihr |
|---|---|---|
| Knoten | 167 | 167 |
| Kanten | 93 | 93 |
| Datensätze · Wertzeilen | 531 · 103 | 531 · 103 |
| Beschriftungen | 212 | 212 |
| Tiefen je Ebene | `0:1 1:6 2:40 3:54 4:49 5:17` | dieselben |
| Prüfsumme Vater · Stelle · Kind | `f1d5a047…` | dieselbe |

⚠️ **Die Bedingung stand vor dem Löschen, nicht danach:** *der Schritt hat für **jede** Zeile den
gerechneten Weg neben den gespeicherten gestellt — **0 Abweichungen von 167** — und wäre bei einer
einzigen still umgekehrt, ohne die Fassungsnummer zu heben. Danach dieselben Zahlen wie oben, sonst
Abbruch.*

⚠️ **Umkehrbar:** *167 Schattenzeilen, 167 Journalzeilen unter **einer** Änderungsgruppe
([D-348](../../NewConcept/90-decision-log.md)), jede mit ihrer **Version**
([D-634](../../NewConcept/90-decision-log.md)) und ihrem alten Weg. **Der Schatten behält seine
Spalte** — dieselbe Begründung wie bei `name` ([D-065](../../NewConcept/90-decision-log.md)): eine
alte Zeile führt ihre Angaben als **Datum** mit. Sie steht als benannte Ausnahme in
`Schema::SHADOW_ONLY_IN`, nicht als stille.*

⚠️ **Und das Sichern liegt im Fassungsschritt selbst, nicht in einem Skript daneben.** *Die Falle ist
an diesem Tag zweimal zugeschnappt: `require wp-load.php` hebt die Fassung, **bevor** ein Skript
seine erste Zeile sichert. Sie ist auch hier zugeschnappt — die Wanderung lief, während ein
Messskript noch geschrieben wurde —, und **weil sie im Schritt selbst sichert, hat sie nichts
gekostet**.*

⚠️ **`moveSubtree()` ist damit leer geworden, und das ist die Aussage der Aufgabe.** *Sie schrieb den
Weg jedes Nachfahren um; es gibt keinen umzuschreiben. **Auch der Versionszähler bleibt jetzt
stehen**, und sein eigener Grund fällt mit derselben Spalte: er lief mit, weil «`save()` writes name
and path together, so a stale form could rename a node and write its old path back» — ein veraltetes
Formular kann keinen Weg mehr zurückschreiben, weil keiner geschrieben wird. Sie ganz zu streichen
braucht `ModelEditor`, der gesperrt war: `INF-052`.*

**Mitgezogen sind zwölf Wächter** (`PR-9`) — *und die Hälfte davon war **still** kaputt, nicht laut:
`$wpdb` gibt bei einer Abfrage über eine gefallene Spalte dasselbe zurück wie bei einem leeren
Ergebnis, und «0 falsche Pfade» las sich wie ein Erfolg.* Was an ihre Stelle trat, ist überall
dasselbe: `WpdbNodeRepository::subtreeIds()` — **ein Ort für die Frage «was hängt unter diesem
Knoten», nicht zwölf**, und dieselbe Antwort wie im Kode, den sie prüfen.

```text
[x] TASK-002  path aus record_values entfernen
```

**Spiegel von `edge_id`**: 183 Zeilen, und beide Spalten haben dieselben 12 verschiedenen Werte.
Vorher zu klären ist die Frage aus [`review-tabellen.md`](../../review-tabellen.md): bekommt eine
Komposition mit Multiplizität 1 immer ihren eigenen Datensatz?

⚠️ **Angehalten am 2026-09-05, und der Grund ist eine Messung: die Spalte ist kein Spiegel**
(`INF-051` in [`inbox.md`](inbox.md)). *Die Zahl «12 gegen 12» stammt aus der **lebenden** Tabelle,
und dort stimmt sie bis heute — 0 von 103 Abweichungen. **Die Schattentabelle sagt das Gegenteil:
1119 von 5569 Zeilen weichen ab, 1117 tragen einen mehrteiligen Pfad, die jüngste von heute.***

⚠️ **Der Pfad trägt die Adresse einer Einstellung *an einer Verwendungsstelle*** — gebaut, nicht
geplant: `putSettingAtUseSite()` schreibt `Verwendungsstelle.Einstellungskante`,
`clearSettingAtUseSite()` löscht darunter, `createPartAt()` setzt die ganze Kette zusammen, und
**beide Leser schlagen über `$wert->path` nach**, nicht über `relation_id`. *Fiele die Spalte, lägen
«diese Einstellung, überall» und «diese Einstellung, nur hier» auf **derselben** Zeile und die zweite
überschriebe die erste — still.*

⚠️ **Die Frage, die oben schon steht, ist genau die zu entscheidende, und sie wird nicht geraten**
(`PR-4`). *Die Vorlage dafür liegt im Eingang; bis dahin steht die Spalte unverändert, und
[`path-check.php`](../../../scripts/dev/path-check.php) hält den Zustand fest, statt ihn
vorwegzunehmen.*

⚠️ **Erledigt am 2026-09-06, und der Eigentümer hat die Frage entschieden, nicht ich**
([D-667](../../NewConcept/90-decision-log.md)): *«aber wir hatten die relation id schon vorgesehen im
record».* **Die Adresse einer Verwendungsstelle steht seither am Satz** — `node_records.relation_id`
—, nicht als Text an der Wertzeile. *Sein Wort zu dem Zwischenschritt, den ich vorschlug und den er
gekippt hat: «also verklausulierst du path als Text» — eine zweite Zahlenspalte an der Wertzeile wäre
derselbe Pfad im besseren Mantel gewesen.*

**In zwei Schritten, und die Reihenfolge war Wächter, Leser, Daten:**

| | Fassung | Was geschah |
|---|---|---|
| **Hälfte 1** | 37 | `node_records.relation_id` kommt dazu; die drei mehrteiligen Wertzeilen hängen an ihren eigenen Satz um und lassen ihren Pfad leer. |
| **Hälfte 2** | 39 | **Die Spalte fällt.** Bedingung geprüft, vorher und nachher gezählt, bei Abweichung wirft der Schritt. |

**Die Zahlen, gemessen am 2026-09-06:**

| | |
|---|---|
| Wertzeilen vorher / nachher | **89 / 89**, an 17 Kanten, 72 Datensätzen, alle 89 mit einem Wert |
| mehrteilige Pfade | **0** |
| Pfade, die etwas anderes sagen als ihre `relation_id` | **0** — bis auf **drei leere**, und die hat Fassung 37 selbst so hinterlassen |
| Wertzeilen ohne Adresse danach | **0** |
| Prüfsumme über `id · Satz · Kante · Sprache · Stelle` | vorher = nachher (`taxmod_relationpath_shape`) |

⚠️ **Der Schatten behält seinen Pfad** ([D-065](../../NewConcept/90-decision-log.md), benannt in
`Schema::SHADOW_ONLY_IN`): *Geschichte wird nicht umgeschrieben — und **die drei zweiteiligen
Adressen, die Fassung 37 umgehängt hat, stehen dort und nirgends sonst.***

⚠️ **Gezählt: 67 Zeilen in 16 Dateien nannten die Spalte, 21 Dateien sind angefasst** (`PR-9`) —
*Kern, Speicher, Kernlauf und neun Randprüfungen.* Darunter sind zwei Stellen, die die Spalte als
*Mechanismus* benutzten und nicht nur als Adresse: *`DataEntry::clearPath()` — der Weg, eine Zeile
über einen Text statt über ihre Id zu meinen — ist **ersatzlos gefallen**, und
`ModelValues::settingsAt()` hat seinen «Vorlauf» verloren, den kein Aufrufer mehr füllte. `partsOf()`
schlüsselt jetzt über die Kante; wo zwei Teile an einer Kante hängen
([D-548](../../NewConcept/90-decision-log.md)), trennt sie **ihre Zeile**
([D-530](../../NewConcept/90-decision-log.md)) und nicht mehr ein erfundenes `<Kante>.1`.*

⚠️ **Der Wächter hat mitgezogen und sagt jetzt das Gegenteil von vorher:** *aus «die Spalte steht,
und kein Pfad sagt etwas anderes als seine Spalte» wurde «die Spalte ist weg, der Schatten behält
sie, und die Ausnahme ist benannt».*

```text
[x] TASK-003  path aus labels und settings entfernen
```

**Beide nachweislich leer** — 0 von 47, 0 von 3. Die billigste der vier und der geeignete erste
Durchgang durch die Reihenfolge Wächter-Leser-Daten, bevor sie bei `nodes.path` teuer wird.

⚠️ **Erledigt am 2026-09-05, und beide Spalten waren schon weg, als der Durchgang begann.** *Es
wanderte keine Zeile und es fiel keine Spalte — die Aufgabe hatte sich in zwei anderen mit erledigt,
und das ist nachgemessen und nicht erinnert:*

| | Wie sie verschwand | Gemessen am 2026-09-05 |
|---|---|---|
| `settings.path` | **mit der ganzen Tabelle** ([D-579](../../NewConcept/90-decision-log.md), Fassung 22) | `SHOW TABLES` kennt `wp_taxmod_settings` nicht mehr |
| `labels.path` | **mit dem Umbau der Beschriftungen** (TASK-019, [D-580](../../NewConcept/90-decision-log.md), Fassung 34) | `labels` trägt vier Spalten — `id`, `version`, `owner_kind`, `icon`; `label_texts` trägt keinen Pfad |

*Der Grund steht in [`Label.php`](../../../src/Core/Model/Label.php): «**`path` gibt es nicht mehr.**»
Der umgedrehte Verweis gibt einer Beschriftung genau **einen** Eigentümer und keine Stelle darin —
`nodes.label_id` zeigt auf sie, nicht sie auf den Knoten.*

⚠️ **Was hier trotzdem entstanden ist, ist das Bleibende: [`path-check.php`](../../../scripts/dev/path-check.php).**
*Ein Wächter dieses Namens gab es schon einmal — er prüfte `settings.path`
([D-413](../../NewConcept/90-decision-log.md)) und ist **mit der Tabelle gefallen, ohne Ersatz**. Die
neue Zusage ist die umgekehrte: **die Spalte ist weg und darf nicht wiederkommen.** *Das ist keine
Förmlichkeit — `dbDelta` **fügt fehlende Spalten hinzu**: stünde `path` versehentlich wieder in einer
`CREATE TABLE`-Anweisung, legte die nächste Aktivierung sie klaglos an, leer und ungelesen. Der Lauf
prüft deshalb beides, die Datenbank **und** die Anweisung, aus der sie gebaut wird.*

⚠️ *Und er liest den Quelltext **ohne seine Kommentare**. Der erste Entwurf wurde an der eigenen
Begründung rot: `ModelEditor.php` erklärt in zwei Absätzen, warum `labels.path` fiel — der Kommentar
ist der Beleg und nicht der Verstoß.*

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
[x] TASK-019  labels und label_texts; name zieht aus nodes und relations hinein
```

⚠️ **2026-09-05 gebaut — Schemafassung 34.** *`labels` trägt nur noch das Sprachunabhängige (`icon`,
dazu `version` und `owner_kind` aus Fassung 31); `label_texts` trägt sechs Textspalten je Sprache und
Numerus — `text_name`, `text_form`, `text_table`, `text_select`, `text_help`, `text_symbol`. **Knoten
und Kanten zeigen mit `label_id` dorthin**, `nodes.name` und `relations.name` sind gefallen.*

⚠️ **Gemessen vorher: 137 Knotennamen, 58 Kantennamen, 52 Beschriftungen** (39 `symbol`, 4 `form`,
4 `table`, 3 `select`, 2 `help`; 4 `de_DE`, 48 ohne Sprache, 0 mit Pfad, 0 mit einem anderen Numerus
als `one`). **Nachher: 195 Beschriftungszeilen, 197 Textzeilen, 247 belegte Rollenfelder — Zeile für
Zeile derselbe Text an derselben Stelle.** *Die 48 sprachlosen Zeilen sind Zeilen der Standardsprache
`en_US` geworden ([D-387](../../NewConcept/90-decision-log.md),
[D-645](../../NewConcept/90-decision-log.md)); ein Zusammenstoss dabei wurde vorher gezählt: keiner.*

⚠️ **Umkehrbar: 195 Schattenzeilen, eine Änderungsgruppe, jede Journalzeile mit ihrer Version**
([D-348](../../NewConcept/90-decision-log.md), [D-634](../../NewConcept/90-decision-log.md)). *Und
das Sichern steht **im Fassungsschritt selbst** — `INF-045`: das Laden von WordPress hebt die Fassung,
bevor ein Skript daneben zum Schreiben käme. **`nodes_history` und `relations_history` behalten `name`**,
aus demselben Grund, aus dem das Änderungsbuch seine Zustände behält
([D-065](../../NewConcept/90-decision-log.md)) — 21 465 + 25 440 Namen bleiben als Geschichte stehen,
und `Restore` schreibt sie beim Zurückholen wieder in die Beschriftungen.*

⚠️ **Die drei Fragen, die `INF-040` offen nannte, sind beantwortet und nicht umgangen:** *Rollen sind
Spalten ([D-598](../../NewConcept/90-decision-log.md)), `symbol` ist sprachabhängig geworden
([D-645](../../NewConcept/90-decision-log.md)), und die neutrale Zeile ist durch die **erklärte**
Standardsprache abgelöst, die vom Rand in den Kern hereingereicht wird (`CD-1`).*

⚠️ **Neu am Netz und mitgezogen:** *`label-space-check` misst jetzt die neue Form — kein Knoten ohne
`label_id`, keiner ohne Namen, und **die Gegenprobe zum Rückfall**: eine Sprache, für die nichts
gepflegt ist, bekommt den Text der Standardsprache. `id-space-check`, `orphans-check`,
`label-role-check`, `labels-page-save-check`, `journal-address-check`, `rename-survives-check`,
`package5-check`, `cleartrash-check`, `cleanup-screen-check`, `implemented-by-check`,
`setting-kind-check`, `setting-write-check`, `renderer-choice-check`, `seed-twice-check`,
`edge-class-check`, `dangling-reference-check` und `silent-query-check` ziehen mit — **keiner
entschärft**.*

⚠️ **Was der Umzug an Werkzeug gebraucht hat: zwei Sichten.** *`…nodes_named` und `…relations_named`
zeigen die Zeile samt ihrem Namen in der Standardsprache — die Antwort auf die Folge, die D-580 selbst
benannt hat («`nodes` hat danach keine lesbare Spalte mehr»). **Eine Sicht hält nichts und kann mit
der Tabelle nicht auseinanderlaufen** ([D-016](../../NewConcept/90-decision-log.md),
[D-228](../../NewConcept/90-decision-log.md)).*

⚠️ **Was offen blieb, steht als `INF-046` und `INF-047` im Eingang:** *der `path` an einer
Beschriftung (0 Zeilen betroffen, aber eine Möglichkeit weniger), und drei Wächterbefunde, die nicht
am Namensumzug hängen.*

[D-580](../../NewConcept/90-decision-log.md). **Grösster Einzelposten:** `nodes.name` ist indiziert und
wird an vielen Stellen gelesen. Reihenfolge `PR-12` — Wächter, Leser, Daten.

⚠️ *Die Frage davor — Rollen als Spalten oder als Zeilen mit `role_id` — hat
[D-598](../../NewConcept/90-decision-log.md) beantwortet: **Spalten**, und die Zeilenform steht auf
dem Parkplatz.*

⚠️ **Der Zwischenstand vom selben Tag, aufgehoben statt gelöscht: 2026-09-05 angelaufen und zunächst
**nicht** gebaut — der Grund stand gezählt in `INF-040`.** *Die Teilung
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
[x] TASK-037  Loeschen fragt nach den Verwendungen — Dialog, und bei «ja»
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

**TASK-037 gebaut am 2026-09-05, und der Knopf ist kein Knopf mehr, sobald etwas hierher zeigt.**
*Wird der Knoten benutzt, ist der Papierkorb ein **Dialog**: er nennt jede Verwendung mit Namen und
mit dem Knoten, an dem sie hängt, und bietet zwei Antworten — «mit den Verwendungen» und «nur den
Knoten». Wird er nicht benutzt, steht der gewöhnliche Knopf da wie bisher.*

⚠️ **Genau ein Papierkorbknopf auf der Seite, und der steht im Dialog.** *Das ist die Zusage, ohne
die der Dialog Zierat wäre: ein zweiter Knopf daneben liesse die Frage umgehen, und dann hätte D-604
eine Oberfläche statt einer Wirkung.*

⚠️ **Was der Bestand hergibt, gemessen am 2026-09-05:** *von **137** lebenden Knoten sind **22** Ziel
einer benannten Kante — nur dort erscheint der Dialog. **5** davon werden mehrfach verwendet, die
schwerste ist `Text` mit **26** Verwendungen. Für die übrigen **115** ändert sich nichts.*

⚠️ **Was mitgeht, sind die Kanten — nicht die Datensätze, und das ist [D-639](../../NewConcept/90-decision-log.md).**
*Komposition ist eine Aussage über **Datensätze**: «wenn ich den Datensatz von Kunde A lösche, muss
auch die Adresse von Kunde A gelöscht werden.» Ein Zielknoten ist ein **Typ** und selbstverständlich
geteilt — **36 der 42 Kompositionskanten zeigen auf mehrfach verwendete Ziele**. Deshalb nimmt das
Parken die **Verwendungsstellen** mit, und jede geparkte Kante nimmt nach
[D-619](../../NewConcept/90-decision-log.md) ihre Wertzeilen mit. Alles umkehrbar; erst das Leeren
ist es nicht.*

⚠️ **Der Wächter ist gewachsen statt entstanden:** [`used-by-check.php`](../../../scripts/dev/used-by-check.php)
*hatte die Daten schon — dieselben Knoten, dieselbe Frage «was bricht, wenn ich das lösche». Er geht
den Weg jetzt **über die Maske**: Seite zeichnen, Dialog im Markup nachweisen, Knopfzahl zählen,
`handlePost()` mit echtem Nonce rufen, frisch nachlesen, aufräumen. **Der Gegenfall steht daneben** —
ein unbenutzter Knoten darf keinen Dialog bekommen, sonst wäre «es fragt» auch dann wahr, wenn es
immer fragt.*

⚠️ **Offen und im Eingang als `INF-050`:** *der zweite Löschknopf — «die Kinder rücken auf» — parkt
den Knoten genauso und fragt nichts. Sein Ergebnis ist die von D-604 erlaubte «nein»-Hälfte, aber
gewählt hat sie niemand.*

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
[x] TASK-045  Der Schreiber sucht die Einstellungskante an beiden Ketten —
              Besitzer und Ziel (D-611). Nur fuer Einstellungen, nicht fuer
              Modellfelder.
[x] TASK-047  Zwei Kanten auf den Zweigkopf «Constants» entfernen —
              Rueckstand aus Waechterlaeufen, ohne Eintrag im Aenderungsbuch
[x] TASK-046  Die vier Waechter auf die Spaltenform umschreiben — sie fragen
              nach den Kanten 44091/44093, die es nicht mehr gibt — erledigt durch Wegfall, gemessen 2026-09-10
```

**2026-09-10, gemessen:** *kein einziger Wächter (`scripts/dev/*-check.php`) nennt die Kanten 44091
oder 44093 noch — die Nummern stehen nur in zwei Wanderungsskripten (`dead-relation-values-clean`,
`displayoption-migrate`), die Geschichte sind. Die vier, die hier gemeint waren, sind seit dem
2026-09-06 und dem 2026-09-10 gefallen oder umgeschrieben; es gibt nichts mehr umzuschreiben.*

**TASK-045 gebaut am 2026-09-05, und der Leser ging mit.** *`settingRelationAtUseSite()` sucht die
Einstellungskante jetzt an **beiden** Ketten — der des Besitzers, dann der des Ziels — und
`useSiteSettingRelation()` prüft dieselbe Menge, damit die Maske nicht abweist, was sie eine Zeile
vorher angeboten hat. **Beide gehen durch dieselbe eine Stelle**, sonst gälte «beide Ketten» an einem
Ort und am anderen nicht.*

⚠️ **Der Leser musste mit, sonst wäre die alte Begründung wahr geworden.** *Es stand da: «ein
Schlüssel, den nur das Ziel erklärt, bekommt `null` — ihn zu schreiben hiesse, eine Zeile anzulegen,
die niemand liest.» **Das war richtig, solange `ModelValues::settingRelation()` nur den Besitzer
kannte.** Sie sucht eine Verwendungsstelle jetzt in derselben Reihenfolge. **Schreiber und Leser auf
zwei Adressen ist der Fehler, den [D-611](../../NewConcept/90-decision-log.md) selbst beschreibt** —
einer der beiden allein hätte ihn spiegelverkehrt wiederhergestellt.*

⚠️ **Keine Zusatzregel für Namensgleichheit** — *die hatte ich zu D-611 mitentschieden, und der
Eigentümer hat sie zurückgenommen: «gibt es nur an Root, nicht doppelt; Integer erbt es und kann es
umstellen.» **Eine Einstellung wird einmal erklärt und vererbt.** Die Reihenfolge steht trotzdem
fest, damit sie nicht von der Reihenfolge einer Abfrage abhängt.*

⚠️ **Der Wächter ist gewachsen:** [`setting-write-check.php`](../../../scripts/dev/setting-write-check.php)
*hatte den Rundlauf an einer Verwendungsstelle schon. Er hat einen zweiten dazubekommen, mit seinem
Fall: ein **eigener** Zieltyp trägt eine Einstellungskante, die die Kette des Besitzers nicht kennt —
erst wird nachgewiesen, dass sie dort wirklich fehlt, dann dass der Schreiber sie findet, dass der
Wert danach im Satz des **Besitzers** unter der zweistufigen Adresse steht, dass der Leser ihn dort
wiederfindet und dass «nichts» ihn wieder herausnimmt. **Vor TASK-045 war die dritte Zusage nicht zu
erfüllen**, weil schon der Schreiber die Kante nicht fand.*

⚠️ *Die Grenze steht: **nur Einstellungen.** Ein Modellfeld trägt Daten des Benutzers, und die an
einer Verwendungsstelle zu überschreiben wäre etwas anderes — sein Wort: «a, aber aktuell nur für
Settings».*

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
[x] TASK-049  Zehn Waechter von seinen Knotennamen loesen (D-613) — erledigt 2026-09-10, der Rest mit D-709
              form-membership · label-role · package1 · page-blocks
              record-on-any-node · rename-survives · renderer-choice
              setting-edge · setting-write · unitvalue
```

**2026-09-10, nachgemessen und weitergebaut.** *Von den zehn sind **fünf gefallen** (`package1`,
`rename-survives`, `renderer-choice` am 2026-09-06; `page-blocks`, `setting-write` am 2026-09-10 mit
D-706), **zwei waren schon gelöst** (`form-membership`, `label-role` — nur noch die Erzählung nennt den
Namen), und **heute sind zwei weitere umgestellt**, die die Liste nicht kannte: `path` holt den Behälter
`Renderer` über seine notierte Id statt über den Namen, `record-kind` holt `Integer` und `Decimal` über
ihre Klasse, die Grenzknoten als Ziel der Kanten `min`/`max` und die Kante `read_only` an der Wurzel —
so, wie der Kode sie findet.* ⚠️ **Der Rest, und wie er fiel:** *`unitvalue`, `record-on-any-node` und `allowed` griffen `Prefixes`,
`Base units`, `Gramm`, `Ohm`, `kilo`, `milli` — die Namen des **Einheitengerüsts**, und das Gerüst selbst
fand seine Knoten genauso. Für sie gab es keine Rolle und keine notierte Id; ob das Gerüst notieren soll,
war eine Entscheidung (`PR-4`). **Sein Wort am selben Tag: «ja notieren»** —
[D-709](../../NewConcept/90-decision-log.md): Fassung 4 des Gerüsts hinterlegt je Knoten eine Option,
die drei Wächter und Fassung 44 (`allowed` an `Prefixes`) holen ihn darüber. Damit greift kein Wächter
mehr einen Knoten über seinen Namen, ausser den eigenen mit Präfix.*

⚠️ **Der Grund ist schärfer als «fragil»:** *[D-022](../../NewConcept/90-decision-log.md) sagt,
Knotennamen sind **absichtlich nicht eindeutig**, und «nothing resolves, references or branches on a
name». **Gemessen kommen fünf Namen doppelt vor, darunter `Adresse`.** Ein Wächter mit
`WHERE name = … LIMIT 1` greift eine von beiden und weiss nicht welche — der Fall ist in
`setting-write-check` schon dokumentiert.*

```text
[x] TASK-050  Ein Testast fuer Waechterknoten (D-614) — dort duerfen sie
              liegenbleiben; unsichtbar, ausserhalb jeder Aufloesungskette,
              und zaehlbar — gefallen 2026-09-10 (D-710)
```

**2026-09-10, sein Wort: *«ok fällt».*** *[D-710](../../NewConcept/90-decision-log.md) nimmt D-614
zurück: seit der Klammer vom 2026-09-06 dreht jeder Wächter alles zurück, auch nach einem Absturz,
und `no-model-write-check` bewacht es. **Ein Ast für Reste braucht Reste, und es gibt keine mehr.***

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

[x] TASK-053  Die Kantenart wird angegeben, nicht geraten (D-618)

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

**Teil 1 und Teil 2 gebaut am 2026-09-05.** *`addField()` nimmt die Art als vierte Angabe entgegen;
die Anlege-Zeile trägt einen Wähler mit genau drei Einträgen — `composition`, `aggregation`,
`setting` —, und der Satz «‹Kind› is not a choice» ist von der Oberfläche verschwunden. An seiner
Stelle steht, was wahr ist: die Art **sagt**, was die Kante ist, und sie wird beim Anlegen gewählt.*

⚠️ **Vorbelegt mit `composition`, und das ist eine Messung:** *gemessen am 2026-09-05 tragen **42**
benannte Kanten `composition`, **12** `setting`, **4** `aggregation`. **Eine Vorbelegung auf dem
Schirm ist etwas anderes als eine Ableitung, die niemand sieht** — sie steht da, bevor der Knopf
gedrückt wird, und ist zu ändern.*

⚠️ **Ein Fehler ging mit, den niemand gesucht hatte:** *`duplicate()` und `duplicateField()` liessen
die Art am Zielast **neu ableiten**. Eine kopierte Einstellungskante kam damit als Komposition
zurück, weil `setting` die einzige Art ist, die kein Ast hergibt. **Die Vorlage gibt ihre Art jetzt
mit** — eine Kopie, deren Kanten anders heissen als die des Originals, ist keine.*

⚠️ **Der Wächter ist gewachsen, nicht neu:** [`setting-branch-relation-check.php`](../../../scripts/dev/setting-branch-relation-check.php)
*hatte Teil 3 schon. Er geht jetzt zusätzlich den **Weg über die Maske**: der Wähler ist gezeichnet,
er steht im Formular des Anlegen-Knopfes, er bietet genau die drei Arten und keine vierte, der falsche
Satz ist nachweislich fort — und ein abgeschicktes `composition` auf ein Ziel **im Einstellungsast**
kommt als `composition` an der Kante an. **Das ist der Fall, den die Ableitung gar nicht bauen
konnte**, und er fällt danach der Regel aus Abschnitt 1 auf, was die beiden Hälften der Aufgabe
zusammennäht. Sein Gegenfall aus Teil 3 hängt nicht mehr am abgelösten Verhalten: die Abweichung wird
benannt statt geerbt.*

⚠️ **Offen und im Eingang als `INF-052`:** *rund **140** weitere Aufrufe von `addField()` — Saatgut,
Gerüste, Wächter, Tests — lassen die Angabe weg und bekommen weiter die Art des Zielastes. `null`
heisst «niemand hat es gesagt»; ob diese Stellen ihre Art nennen sollen, ist eine eigene Aufgabe.*

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

[x] TASK-055  Warum sich ein Knoten nicht verschieben laesst

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

**Geschlossen am 2026-09-06, an seinen eigenen Knoten nachgemessen — nicht an einer Ersatzlage.**
*Die Maske für `Street / H#` (75473) und für `Zip/City` (75477) bietet heute je **127 Ziele**, und
`Combined` (3984) steht in beiden als anklickbares `<input type="radio" name="target" value="3984">`.
**Das war der ganze Fehler** — der Akt hielt schon vorher, das Ziel war nur nicht anzukommen.
`move-mask-check.php` läuft grün, 14 Zusagen.*

⚠️ *Verschoben ist weiterhin nichts. **Das ist seine Einordnung und sein Klick**, und ein Wächter,
der ihm die Arbeit abnimmt, schreibt in sein Modell — genau das, was am selben Tag an
`field-hide-check.php` schiefging.*

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

[x] TASK-057  Die Renderer-Einstellung zieht von der Spalte auf eine Kante 1..1 zurueck

**Gebaut am 2026-09-05** auf sein Wort: *«settings an Knoten bitte wieder mit Settings-Relation,
kannst du jetzt bauen.»*

**2026-09-05, [D-642](../../NewConcept/90-decision-log.md).** *Er hat berichtigt, was ich aus
[D-584](../../NewConcept/90-decision-log.md) gemacht hatte: «ich meinte einfach eine Multiplizitaet
von 1» — **am Knoten**, an einer gewoehnlichen Einstellungskante. Nicht an einer Spalte.*

**Die Wanderung, gemessen vorher und nachher** ([`renderer-relation-migrate.php`](../../../scripts/dev/renderer-relation-migrate.php)):

| | vorher | nachher |
|---|---|---|
| Traeger am Knoten | 29 (Spalte) | 29 (Kante `renderer`, `1..1`) |
| Traeger an Kanten | 0 | — die Spalten sind weg |
| Knoten, die einen Renderer aufloesen | **59** von 139 | **59** von 139 |
| Knoten, die etwas anderes zeichnen | — | **0** |

⚠️ **Der Massstab war nicht die Zahl der Zeilen, sondern was jeder Knoten zeichnet.** *Vorher und
nachher wurde je Knoten der Renderername ueber die echte Aufloesung abgenommen. Das Skript nimmt
alles zurueck, wenn ein einziger abweicht; **es musste nicht.** Die Datensaetze wurden
**weitergereicht** und nicht neu angelegt — zwei der 29 trugen eigene Wertzeilen (`Integer → slider`,
`Base units → table`), ein neuer Satz haette sie verloren.*

**Schemafassung 32:** *`nodes.settings_record_id`, `relations.settings_record_id`,
`relations.target_settings_record_id` und ihre beiden Schatten sind gefallen. An der Kante ersatzlos
([D-643](../../NewConcept/90-decision-log.md)) — je 0 Zeilen —, und `SettingKey::applyingTo(…,
isRelation: true)` bietet den Schluessel dort nicht mehr an.*

**Was dabei aufgefallen ist, und es war schon da:** *{@see DataEntry::chooseSettingRecord()} warf den
alten Teil weg und **liess den Verweis auf ihn stehen** — die naechste Wahl legte eine zweite Zeile
daneben. Gemessen **drei Zeilen an einer Kante mit `1..1`** nach drei Wahlen, und die Aufloesung nahm
die aelteste. **Die Spalte hatte den Fehler zugedeckt**, weil sie eine Zahl haelt und keine Zeilen.
Behoben, mit einem Kerntest und einer Zusage in `setting-write-check.php`.*

**Waechter, die mitgezogen sind** (`PR-9`, keiner entschaerft):
`renderer-choice-mask-check` (der einzige, der den Schreibweg des Benutzers geht — **gruen**),
`rename-survives-check`, `settings-record-column-check` → **umbenannt** zu
`settings-record-carrier-check`, `renderer-choice-check`, `setting-write-check`,
`multiplicity-check`, `edge-class-check`. *`settings-record-column-migrate.php` ist zurueckgezogen —
sie war die Wanderung in die Gegenrichtung.*

⚠️ **Offen geblieben und im Eingang:** *`INF-042` — dass die Renderer-Wahl in einem eigenen Block
steht und nicht in der Wertspalte ihrer eigenen Zeile.*

---

[x] TASK-058  Der gewaehlte Renderer wird auch verwendet — nachweisen, nicht annehmen

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

⚠️ **Gebaut, und die Antwort auf seinen Satz ist: es klappt.** *Drei Zusagen sind an
`renderer-choice-mask-check` angewachsen (23 → 26), weil dort der ganze Weg schon steht und ein
zweiter Waechter ihn nur ein zweites Mal gegangen waere. Der Weg geht jetzt durch ein **Feld**, so
wie die Oberflaeche zeichnet: ein Traegerknoten zeigt auf die Probe, die Probe traegt die Wahl.
**Jede angebotene Wahl** — fuer `Integer` `field`, `spinner`, `slider` — wird gewaehlt, ueber die
Maske gespeichert, mit frischen Repositorien aufgeloest und **gezeichnet**; im Ergebnis steht ihr
eigener Name. Dazu zwei, die das absichern: keine faellt auf `plain` zurueck ([R14b](../../NewConcept/30-renderer.md)),
und verschiedene Wahlen zeichnen verschieden — sonst zeichnete nicht die Wahl, sondern etwas
dahinter.*

⚠️ **Gegengeprobt, und das ist der eigentliche Befund:** *die Aufloesung wurde versuchsweise blind
gemacht — die gespeicherte Wahl ignoriert, genau der Verlust aus [D-543](../../NewConcept/90-decision-log.md).
**Die neuen Zusagen fielen rot, und alle 23 alten blieben gruen.** Der Riss zwischen «gespeichert»
und «gezeichnet» war also wirklich unbewacht, und er ist es jetzt nicht mehr.*

---

[x] TASK-059  Die zweite Haelfte von D-621: `nodes.field_type` faellt

**2026-09-05, auf seine Rueckfrage:** *«aber der Rueckbau am Knoten gehoert doch fachlich dazu, wie
kannst du das dann stehen lassen?»* **Er hat recht: TASK-032 hat nur die Kantenseite gebaut.**

⚠️ **Was an die Stelle der Spalte tritt, steht in [D-621](../../NewConcept/90-decision-log.md) selbst:**
*«die Kante sagt, was etwas hier ist — nicht der Knoten und nicht der Ast.» **Ein Knoten ist selbst
eine Einstellung, wenn jede eingehende Kante eine Einstellungskante ist**; wen nur Vererbung erreicht,
beantwortet die Kante ueber seinem naechsten Vorfahren — *«wenn man am Vater irgendwas anhaengt, ist
es genauso in den Kindern verfuegbar»*.*

⚠️ **Gemessen, bevor gebaut wurde:** *39 Knoten trugen die Marke, 98 nicht. **Die Ableitung
reproduziert 136 von 137**; die eine Abweichung ist `Boolean` und liegt als `INF-044` im Eingang
(`PR-4`).*

⚠️ **Der Massstab war, was jeder Knoten zeichnet, und er ist gehalten:** *`renderer-per-node.php`
nimmt je Knoten den Renderernamen ueber die echte Aufloesung ab. **Vorher und nachher Zeile fuer
Zeile identisch — 130 von 137.***

⚠️ **Die gewollte Nebenwirkung ist eingetreten** (`INF-042`, Antwort 1): *ein Waehler entsteht aus den
**unmarkierten** Kindern des Kantenziels ([D-540](../../NewConcept/90-decision-log.md)). Die neunzehn
Renderer trugen alle die Marke, also bot die Auswahl **null** Moeglichkeiten an. **Jetzt bietet sie
12 an** — `Label roles` 0 → 5, `Converter` 0 → 4, `Validator` 0 → 2, `Orientation` 0 → 2. *Was noch
fehlt, sind die sechs unter dem Zwischenknoten `render with label`: `INF-043`.*

**Was gebaut ist:**
- `nodes.field_type` faellt, lebend und im Schatten; **Schemafassung 33**.
- **Gesichert wird im Fassungsschritt selbst**, nicht in einem Skript daneben — 39 Schattenzeilen und
  39 Journalzeilen unter **einer** Aenderungsgruppe, jede mit Version
  ([D-634](../../NewConcept/90-decision-log.md)). *Warum das dort und nicht daneben gehoert: `INF-045`.*
- `Node::$fieldType`, `Node::withFieldType()`, `ModelEditor::setFieldType()` und der Waehler auf der
  Knotenseite sind weg. *`ModelEditor::kindsOfTargets()` und `fieldTypesOfNodes()` ebenfalls — beide
  hatten ausser dem Waehler keinen Aufrufer.*
- Die Auskunft kommt aus `NodeRepository::ownFieldTypes()` (eigene Sorte, aus den eingehenden Kanten)
  und `resolvedFieldTypes()` (Lauf ueber die Vorfahren). **Die eigene entscheidet die Waehlbarkeit,
  die aufgeloeste den Behaelter** — dieselbe Trennung wie in [D-544](../../NewConcept/90-decision-log.md).

**Waechter** (`PR-9`, keiner entschaerft):
- **Neu** `field-type-gone-check` — die Spalte kommt nicht zurueck (lebend, im Schatten und im
  `CREATE TABLE`); jede gefallene Marke hat Schatten, Gruppe und Version; **so viele
  Einstellungskanten wie die Spalte sagt** (heute 12) und keine fremde Kantenart; **die Renderer-Zeile
  bietet Moeglichkeiten an, nicht null**.
- **Umgezogen** `setting-kind-check` — dieselbe Zusage, jetzt ueber die Kante beantwortet. *Sein
  dritter Abschnitt ist mit der Spalte gegenstandslos geworden und in `field-type-gone-check`
  aufgehoben, nicht gestrichen.*
- **Gefallen** `field-type-check` — es bewachte die Spalte. *`PR-9`: ein Waechter bewacht den
  **aktuellen** Zielzustand, nie einen vergangenen.* Mit ihm `setting-kind-migrate`, die Wanderung,
  die die Spalte gefuellt hat.
- `minmax-specialize` und `geruest` zeigen bzw. setzen die Marke nicht mehr; die Kante sagt es.

⚠️ *Alle beruehrten Waechter gruen, `vendor/bin/phpunit` 445 gruen. **Zwei Waechter bleiben rot und
waren es vorher schon:** `unitvalue-check` (dokumentiert im Eingang, `OQ-134` und der Befund darunter)
und `always-on-check` (Groesse des Immer-Gelesenen).*

⚠️ **Nicht fertig, und es steht hier statt in einer stillen Annahme:** *[D-644](../../NewConcept/90-decision-log.md)
schliesst `INF-042` mit Antwort 1 — «genau 1, bitte so umsetzen» —, und Antwort 1 hat **zwei**
Haelften: der Waehler kommt zurueck, **und der eigene Renderer-Block faellt weg**. **Die erste ist
gebaut, die zweite nicht.** Gemessen: fiele der Block heute, verlöre der Eigentuemer `form`, `table`,
`compact`, `reference` und die beiden Waehler-Renderer — sie haengen unter dem Zwischenknoten
`render with label`, und nur der Block holt sie noch aus der Registratur. **Das ist eine Entscheidung
ueber diese sechs und keine Aufraeumarbeit** (`PR-4`, `INF-043`).*

---

## Angehaengt am 2026-09-05, auf sein Wort «dann haenge das mal an die Bauliste an»

**Die Reihenfolge, in der es gebaut wird, sobald er es freigibt.** *Sie steht hier und nicht im
Kopf, weil `TASK-001` bis `TASK-003` seit dem 2026-09-01 beschlossen sind und **nie angefangen
wurden** — nicht wegen einer offenen Frage, sondern weil immer etwas dazwischenkam. Das ist mein
Versaeumnis und keine Unklarheit.*

1. **Die Auswahl fragt die Registratur — und zeichnet nach der Tiefe** (TASK-059, neu). *Die Moeglichkeiten eines Renderer-Waehlers
   kommen aus `RendererRegistry`, nicht aus den Kindern des Kantenziels. **Gemessen kennt die
   Registratur 27 Renderer** — `form`, `table`, `compact`, `reference`, `chooser-dialog`,
   `chooser-inline` sind darunter, **`render with label` ist es nicht.** Damit wird der
   Zwischenknoten fuer den Waehler bedeutungslos, und die sechs fehlenden Renderer aus `INF-043`
   kommen zurueck, ohne dass eine Ersatzregel erfunden werden muss (die naheliegende ist gemessen
   falsch: sie machte `Base units` von 2 auf 14 waehlbar). `eligibleFor()` schraenkt weiter auf den
   Typ ein — am Knoten der Knoten, an der Kante der Zielknoten ([D-603](../../NewConcept/90-decision-log.md)).*
2. **`TASK-003`** — `path` aus `labels`. *Nachweislich leer, der billigste Durchgang.*
3. **`TASK-002`** — `path` aus `relation_records`. *Erledigt am 2026-09-06, Fassung 37 und 39.*
4. **`TASK-001`** — `path` aus `nodes`. *Die teuerste, aber **billiger als beschlossen**: die
   Aufgabe verlangt noch, der Vorfahrenweg muesse danach «aus `relations` kommen» — das war vor
   TASK-018. **Seit dem 2026-09-05 ist `parent_node_id` der Baum**, die Vorfahren laufen darueber,
   und der teuerste Teil hat sich von selbst erledigt. Gemessen kommt `path` noch in **17 Dateien**
   vor, allein 24 mal im Knotenspeicher.*
5. **Danach** die drei gemerkten Punkte aus dem Marken-Rueckbau: die sechs Renderer (erledigt sich
   mit 1), der eigene Renderer-Block (faellt mit 1, dann ist [D-644](../../NewConcept/90-decision-log.md)
   ganz erfuellt) und die Vorkehrung gegen den Sicherungsverlust.

⚠️ **Nicht angefasst wird `render with label`, bis er es sagt.** *Er wollte ihn herausnehmen —
«das ist das einfachste». **An ihm haengen zwei Einstellungskanten**, `with_label` (4 Werte) und
`label_role` (0 Werte); er ist die Zwischenklasse, die `with_label` traegt (sein Wort). Faellt er
ohne neuen Ort fuer die beiden, fallen sie mit. **Nach Schritt 1 stoert er den Waehler ohnehin
nicht mehr** — dann ist es seine freie Entscheidung statt einer erzwungenen.*

⚠️ **Berichtigung zu Schritt 1, am 2026-09-05, nachdem er mich auf `R63` gestossen hat.** *Ich hatte
geschrieben, `render with label` falle dem Waehler «auf die Fuesse», und Schritt 1 als «Registratur
**statt** Baum» aufgesetzt. **Beides war falsch, und die Regel gab es die ganze Zeit** — ich hatte
im Entscheidungsprotokoll gesucht und nicht im Renderer-Konzept:*

> **`R63`:** *«If the selectable set has **no children — only one level** — it is really a selection
> list … **One level → list, several levels → tree view.**»* Und dazu
> [D-109](../../NewConcept/90-decision-log.md): **«List or tree in a chooser — derived from the
> depth of the branch, not a third renderer and not a setting.»**

**Ein Ziel mit mehreren Ebenen ist danach kein Hindernis, sondern der zweite Fall der Regel.** *Der
Zwischenknoten wird nicht uebersprungen, er wird **gezeichnet** — als Baum statt als Liste —, und
die sechs Renderer sind darin sichtbar, ohne dass irgendetwas «hindurchsehen» muss.*

⚠️ **Und die Registratur bleibt trotzdem gefragt, auf sein Wort: «die Registratur muesste immer noch
gefragt werden».** *Die beiden beantworten verschiedene Fragen und ersetzen einander nicht:*

| | Frage | Antwort aus |
|---|---|---|
| **Was ist ueberhaupt waehlbar** | welche Renderer taugen fuer diesen Typ | **Registratur** — `eligibleFor()`, `handles()` an der Klasse ([D-603](../../NewConcept/90-decision-log.md)) |
| **Wie wird gewaehlt** | Liste oder Baum | **Tiefe des Astes** (`R63`, [D-109](../../NewConcept/90-decision-log.md)) |

*Der Ast liefert also die **Gestalt** der Bedienung, die Registratur die **Menge**. Wer nur den Ast
fragt, bietet an einem `Text`-Feld alle vier Ganzzahl-Konverter an — der gemessene Fehler aus
D-603. Wer nur die Registratur fragt, verliert die Baumansicht, die `R63` verlangt.*

⚠️ **Erledigt am 2026-09-05: der eigene Renderer-Block ist gefallen, [D-644](../../NewConcept/90-decision-log.md)
ist damit ganz erfuellt.** *Sein Wort war «Renderer-Box ist uebrigens immer noch da, die muss weg!».
**Die Menge kommt jetzt aus der Registratur** — `eligibleFor()` am Knoten selbst
([D-603](../../NewConcept/90-decision-log.md)) —, und damit fallen `render with label` und jeder
andere Knoten heraus, den keine Renderer-Klasse umsetzt. **Er ist nicht geloescht und nicht
verschoben**, wie oben festgehalten; er stoert den Waehler nur nicht mehr.*

*Gemessen vorher/nachher, je Zeile `renderer`: `Integer` 3 → 3, `Text` 2 → 2, `Boolean` 2 → 2,
`Prefixes` **0 → 2**, `kilo` **0 → 2**, `Electronic Parts` **1 → 4**. **Fuenf der sechs vermissten
Renderer sind wieder zu erreichen** — `form`, `table`, `compact` an einem Knoten ohne eigenen Typ,
`chooser-dialog` und `chooser-inline` an einem Knotenverweis. `reference` unterstuetzt nur
`Purpose::Display` und war auch im alten Block nie waehlbar (`INF-048`).*

*Der Waechter `renderer-choice-mask-check` haelt es fest: die Zeile bietet an, der Block kommt im
Markup nicht mehr vor, ein Knoten neben den Renderern ohne Klasse ist keine Moeglichkeit, und die
Wahl laesst sich ueber die Zeile speichern und wiederfinden — 19 Zusagen, alle gruen.*

---

[x] TASK-061  Der Knotenname wird in der gewaehlten Sprache gelesen und geschrieben

⚠️ **Gebaut am 2026-09-07, ohne Schemaaenderung — es fehlte nur der Weg.** *Der Leser
(`WpdbNodeRepository`) traegt jetzt seine Sprache: zwei Verbuende auf `label_texts` und ein
`COALESCE(gewaehlt, standard)`. Der Schreiber schreibt in dieselbe Sprache, in der gelesen wurde. Wer
die Adresse liest, ist **eine** Stelle geworden (`SettingsScreen::requestedLocale()`) — vorher las die
Maske sie und der Speicher nicht, und genau dazwischen ging der deutsche Text in die englische Zeile.*

⚠️ **Der Rueckfall wird nicht festgeschrieben, und das ist die eigentliche Arbeit gewesen.** *In einer
anderen Sprache als der Standardsprache wird nur geschrieben, **wenn der Name sich von dem der
Standardsprache unterscheidet**. Gleichheit heisst «der Rueckfall war es», und ein Rueckfall ist eine
Anzeige, keine Eingabe. **Gemessen mit ausgebauter Sicherung:** ein Verstecken auf Franzoesisch machte
den englischen Text zum franzoesischen Namen — die Zusage wird rot, wenn die Sicherung faellt.*

⚠️ *Ein **neuer** Knoten bekommt seinen Namen immer in der Standardsprache, gleich was oben gewaehlt
ist: er ist der Boden der Rueckfallkette, und ohne ihn stuende der Knoten in jeder anderen Sprache
namenlos da.*

⚠️ *Der Preis steht im Code: wer eine Uebersetzung eintippt, die dem Text der Standardsprache Zeichen
fuer Zeichen gleicht, bekommt keine eigene Zeile. Er sieht denselben Text, den er sehen wollte — die
Sprache bleibt ungepflegt statt falsch gepflegt.*

**Neun Zusagen an `label-space-check.php`, Abschnitt 7** — je Sprache ihr Text, der Rueckfall wo eine
fehlt, ein Speichern in Sprache A laesst Sprache B unangetastet, der Rueckfall legt keine Zeile an,
und die Gegenprobe, dass ein wirklich anderer Text sie sehr wohl anlegt.

**2026-09-06, von ihm gefunden:** *«node name ist noch nicht sprachabhaengig, obwohl du geschrieben
hast, dass label gebaut wurde».* **Er hat recht, und beides stimmt:** *gespeichert ist der Name
sprachabhaengig — `label_texts` traegt `locale` und `text_name`, gemessen 195 Zeilen `en_US` und 2
`de_DE`. **Der Weg dorthin fehlt.***

⚠️ **Gemessen, wo es haengt — zwei Stellen, beide am Rand:**

1. **Gelesen wird immer die Standardsprache.** *`WpdbNodeRepository::nameArgs()` gibt fest
   `SettingsScreen::neutralLocale()` in den Verbund: `t.locale = 'en_US'`. **Welche Sprache die
   Seite gewaehlt hat, erreicht den Leser nicht.** Darum steht sein deutscher Text «Straße /Haus
   Nr.» in der **englischen** Zeile — er hat ihn eingetragen, und der Schreiber kannte keine andere.*
2. **Geschrieben wird ohne Sprache.** *`ModelEditor::rename()` nimmt einen Namen entgegen und keine
   Locale; `Node::renamedTo()` ebenso. **Jede Umbenennung trifft dieselbe Zeile**, gleich was oben
   gewaehlt ist.*

**Zu bauen:** *der Leser bekommt die gewaehlte Sprache mit Rueckfall auf die Standardsprache
([D-645](../../NewConcept/90-decision-log.md), [D-387](../../NewConcept/90-decision-log.md)), der
Schreiber gibt sie mit. **Der Rueckfall ist der heikle Teil:** ein Knoten ohne deutschen Namen muss
den englischen zeigen und darf ihn beim naechsten Speichern **nicht** als deutschen festschreiben —
sonst wandert die englische Beschriftung stillschweigend in jede Sprache.*

⚠️ **Waechter:** *ein Knoten mit zwei Sprachen liefert je Sprache seinen Text; wo eine fehlt, kommt
die Standardsprache; und ein Speichern in Sprache A laesst Sprache B unangetastet. **Der letzte
Punkt ist der, der ohne Zusage still kaputtgeht.***

Rest von TASK-019 — die Tabellen stehen, der Weg dorthin nicht.

---

[ ] TASK-062  Die zwei Riesendateien werden geteilt

**2026-09-06, auf seinen Hinweis, dass ein kleiner Schritt fuenf Minuten dauert.** *Gemessen:*

| Datei | Groesse | Methoden |
|---|---|---|
| `src/WordPress/Admin/NodesScreen.php` | **232 KB** | 63 |
| `src/Core/Service/Rendering.php` | **171 KB** | 56 |

⚠️ **Der Schaden ist nicht die Groesse, sondern die Kollision.** *Gestern wollten drei Baustellen
gleichzeitig in `NodesScreen.php` — Renderer-Waehler, Satzarten, Loeschdialog. Zweimal ist dabei
fremde Arbeit in einen fremden Commit gerutscht, einmal musste ein Agent auf einen anderen warten.
**Eine Datei, die drei Auftraege gleichzeitig anfasst, ist zu gross** — das ist der Massstab, nicht
die Zeilenzahl.*

⚠️ **Und der zweite Schaden ist die Lesezeit:** *wer eine Zeile darin aendert, liest 232 KB.*

**Wonach geteilt wird — nach dem, was ein Auftrag anfasst, nicht nach Zeilenzahl:** *die Bloecke
der Knotenseite sind schon heute getrennte Methoden (Baum, Felder, Einstellungen, Vorschau,
Datensaetze, Beschriftungen, Aufraeumen). **Sie sind der natuerliche Schnitt** — jeder Block eine
Klasse, die Seite setzt sie zusammen.*

⚠️ *Kein Umbau um des Umbaus willen: geteilt wird, **bevor** die naechsten drei Baustellen dort
gleichzeitig aufschlagen — und ohne dass eine Zusage sich aendert (`PR-9`).*

---

[x] TASK-002 (neu zugeschnitten)  `relation_records.path` faellt — die Adresse steht in Ids

**2026-09-06, auf sein Wort:** *«das hoert sich so an, als wolltest du den Pfad behalten, und das
will ich nicht — sollte alles ueber die Ids abgelegt sein»* und *«es sollte schon mit den
vorhandenen Ids gehen».* **Er hat recht, und meine Zwischenfolgerung war falsch:** *aus «der Wert
liegt im Satz des Halters» hatte ich geschlossen, die **Spalte** muesse bleiben. Bleiben muss die
**Adresse** — nicht ihre Schreibweise.*

**Was der Pfad heute traegt, gemessen:**

| | ein Teil | zwei Teile |
|---|---|---|
| lebend | 116 | **2** |
| Schatten | 5062 | **1280** |

*Mehr als zwei Teile gibt es nirgends. Der zweiteilige ist `<Kante der Verwendungsstelle>.<Einstellungskante>` — **zwei Ids, in einen Text geschrieben**.*

**Das Beispiel aus seinem Modell** (die zwei Feldbreiten von heute):

| | Wertzeile 15276 | Wertzeile 15277 |
|---|---|---|
| Satz | 4756 (`Street / H#`) | 4756 (derselbe) |
| Kante | `display_size` | `display_size` |
| Pfad | `75476.…` (House Number) | `75475.…` (Street Name) |
| Wert | 5 | 40 |

*Beide Zeilen haengen im **selben Satz** an **derselben Kante** und unterscheiden sich nur im Pfad.
Nimmt man ihn weg, sind es zwei gleiche Zeilen, und die zweite ueberschreibt die erste.*

**Der Weg, den er meint, und er ist schon einmal gegangen worden:** *der Satz bekommt einen
**Besitzer mit Raum** — `owner_id` + `owner_kind` (Knoten oder Kante), genau wie `labels` es seit
Fassung 31 hat ([D-641](../../NewConcept/90-decision-log.md)). Dann gehoert die Ueberschreibung dem
Satz **der Kante**, `node_record_id` und `relation_id` sagen alles, und der Pfad faellt ersatzlos.*

⚠️ **Was es kostet, benannt:** *je Verwendungsstelle mit eigenen Einstellungen entsteht ein Satz —
**heute genau zwei**. Die 1280 Schattenzeilen bleiben unveraendert; Geschichte wird nicht
umgeschrieben ([D-065](../../NewConcept/90-decision-log.md)).*

⚠️ **Und die Tabelle heisst danach falsch:** *`node_records` haelt dann auch Kanten-Saetze. Der Name
zieht mit oder wird als Befund vermerkt — nicht stillschweigend stehengelassen.*

⚠️ *Beide Leser entschieden **am Pfad** (`$gesucht[$wert->path]`); sie fragen jetzt die Kante. Das
war der eigentliche Umbau, nicht die Spalte.*

⚠️ **Erledigt am 2026-09-06, in zwei Fassungen — und der Weg war ein anderer als der hier
entworfene.** *Entworfen war ein `owner_id` + `owner_kind` am Satz, nach dem Vorbild von `labels`.
**Der Eigentuemer hat es kuerzer gemacht** ([D-667](../../NewConcept/90-decision-log.md)): «aber wir
hatten die relation id schon vorgesehen im record» — **eine Spalte statt zweier**, weil der Knoten
schon am Satz steht (`node_id`) und nur die Kante fehlte. Fassung 37 hat sie gefuellt, Fassung 39 hat
die Pfadspalte gestrichen. **Die zwei Feldbreiten aus dem Beispiel oben liegen seither in zwei
Saetzen**, einer je Verwendungsstelle, und ueberschreiben einander nicht.*

⚠️ *Und der Befund oben ueber den Namen steht weiter: **`node_records` haelt jetzt auch
Kanten-Saetze.** Er ist damit faellig und nicht erledigt.*

---

[x] TASK-063  Der Tabellenrenderer bekommt den Umschalter horizontal / vertikal

**2026-09-06, sein Auftrag:** *«ich würde gerne hier auch horizontal und vertikal einfügen, horizontal
kopf oben daten darunter, vertikal kopf links daten rechts davon, wir können auch zwei rendere daraus
machen ist mir im prinzip egal»* — und: *«renderer anpassung hinten anhängen»*, also ans Ende der
Liste.

**Es wird einer mit einem Umschalter und nicht zwei** — das hat er selbst schon einmal entschieden,
am Kompaktrenderer ([D-471](../../NewConcept/90-decision-log.md)): *«der Kompaktrenderer, der die
Eigenschaften hat horizontal beziehungsweise vertikal, also **einen Umschalter**»*. Die Begründung von
damals gilt hier wörtlich: zwei Renderer, die sich in **einer** Achse unterscheiden, sind zwei
Registrierungen, zwei Namen im `renderer`-Schlüssel und zwei Stellen, an denen dieselbe
Tabellenhaftigkeit gepflegt wird.

⚠️ **Der Umschalter existiert schon und muss nicht gebaut werden.** *Der Knoten `Orientation` mit den
Kindern `horizontal` und `vertical` steht im Modell, und `compact` hängt daran
(`compact --orientation--> Orientation`, gemessen am 2026-09-06). Für `table` ist es **eine Kante
mehr** — kein neuer Mechanismus, und Vorschau, Vererbung und Einstellungstafel können es sofort.*

| | Kopf | Daten |
|---|---|---|
| **horizontal** (Vorgabe) | oben | darunter, eine Zeile je Datensatz |
| **vertikal** | links | rechts daneben, eine **Spalte** je Datensatz |

⚠️ *Vorgabe `horizontal`, wie bei `compact` — dann ändert sich nichts, solange niemand umstellt.*

**Zusage:** *derselbe Datensatz, zweimal gezeichnet, einmal je Lage — und die Köpfe stehen einmal
oben und einmal links.* Gemessen am Markup, nicht an einer Dienstmethode.

⚠️ **Gebaut am 2026-09-07, in vier Stücken:**

| | |
|---|---|
| `src/Core/Renderer/Orientation.php` | Schlüssel, die zwei Worte und **die Vorgabe an einer Stelle** — `CompactRenderer` liest jetzt von dort |
| `TableRenderer` | eine Sammlung von Zellen, zweimal ausgelegt: `thead`/`scope="col"` gegen `th scope="row"` |
| `Rendering::recordsAsTable()` | reicht die Angaben des **gewählten** Renderers durch — hier stand ein leerer Zeichenkontext |
| `scripts/dev/table-orientation-migrate.php` | die eine Kante `table --orientation--> Orientation`, über `ModelEditor::addField()` |

⚠️ **Der Umschalter kam nicht an, und der Grund war nicht der Renderer.** *`recordsAsTable()` baute
den Zeichenkontext **ohne Angaben** — genau der Fehler, den er am Kompaktrenderer schon gemeldet hat
(«compact mit horizontal und ohne Label gewählt, aber gerendert wird vertikal»). `orientation` und
`with_label` hängen am **Satz des gewählten Renderers**, nicht an der Kette des gezeichneten Knotens.
**Die Kante allein hätte nichts bewirkt**, und am Markup gemessen wäre es aufgefallen, an einer
Dienstmethode nicht.*

⚠️ **Der Abzug ist nachgezogen** (`data/saat.json`, [D-600](../../NewConcept/90-decision-log.md)):
Kanten 48 → **49**. *Ohne ihn hätte eine frische Installation die Kante nicht.*

**Die Zusage hängt an `package7-check.php`, Abschnitt 17c** — eigene Wiese `__t63`, Aufräumen im
`finally`, in der Klammer aus `lib/no-write.php`. Dazu zwei Zusagen im Kernlauf
(`TableRendererTest`): die zwei Lagen, und dass **nur das genaue Wort** die Achse dreht.

---

[x] TASK-064  Der gewachsene Bestand wird das Abbild einer frischen Installation

**2026-09-06, sein Auftrag auf die Frage aus `INF-063`:** *«wir sollten den aktuellen bestand
einfrieren lass aber alles mit `__` weg das ist dir»* — der Vollzug von
[D-600](../../NewConcept/90-decision-log.md), *«eine Neuinstallation entsteht kuenftig aus einem
Abbild des gewachsenen Baums»*.

**Was entstanden ist, in drei Stuecken:**

| | |
|---|---|
| `scripts/dev/saat-export.php` | zieht ab — **ein Lesen**, in der Klammer aus `lib/no-write.php` |
| `data/saat.json` | der Abzug: Zeilen, Optionen, Zaehlung, Pruefsumme |
| `SeedImage::importOnce()` | spielt ein, **nur in ein leeres Modell**, vor Saat und Geruesten |

**Der Abzug vom 2026-09-06, gezaehlt:**

| Tabelle | im Bestand | im Abzug |
|---|---|---|
| Knoten | 139 | **136** |
| Kanten | 48 | **48** |
| Beschriftungen | 187 | **184** |
| Beschriftungstexte | 189 | **186** |
| Saetze | 194 | **194** |
| Wertzeilen | 90 | **90** |
| Optionen | 26 | **23** |

*Die Luecke sind die drei Knoten `__Test` der Wiesen mit ihren Beschriftungen und die drei Optionen
`taxmod_testast_*`, die auf sie zeigten. Im Papierkorb lag nichts.*

**Die Zusage haengt an `seed-twice-check.php`, Abschnitt 5** — keine neue Datei, weil der Lauf schon
die Saat zweimal ueber den Bestand schickt und die Umklammerung dafuer schon steht: *das Modell wird
im `SAVEPOINT` geleert, der Abzug eingespielt, und **Zaehlung und Pruefsumme** muessen Zeile fuer
Zeile und Feld fuer Feld dieselben sein. Danach wird zurueckgedreht und nachgezaehlt, dass sein
Bestand wieder steht.*

⚠️ **Sie spricht in Zahlen und nicht in Namen, und das ist der Kern der Sache.** *Genau daran sind
die vier Gerueste krank: `BaseScaffold`, `UnitScaffold`, `CompositionScaffold` und
`RenderingScaffold` suchen **am Namen** und legen an, was sie nicht finden — so kam dreimal eine
deutsche `Adresse` in seinen Bestand, nachdem er sie in `Address` umbenannt hatte. **Ein Abzug
bringt die Nummern mit und braucht diese Suche nicht.***

⚠️ **Welche Geruese damit entbehrlich werden, ist eine eigene Entscheidung und wird hier nur
benannt** (`PR-4`): *alle vier. Auf einer frischen Installation legt der Abzug an, was sie anlegen
wuerden, und auf einer gewachsenen finden sie ihre Arbeit vor. **Gestrichen sind sie nicht** — sie
sind heute noch der einzige Weg fuer eine Installation ohne Abzug, und dass die vier Fassungen
`taxmod_*_scaffold` im Abzug mitreisen, ist genau das, was sie stillstellt.*

⚠️ **Ein Abbild und kein zweites Modell** (`PR-1`): *`data/saat.json` wird erzeugt und eingecheckt,
nicht gepflegt. Wer sie von Hand aendert, hat eine zweite Quelle der Wahrheit angelegt.*

---

[x] TASK-065  Auch der Kantenname wird in der gewaehlten Sprache gelesen und geschrieben

**Der Rest von TASK-061**, dort beim Bauen gefunden und ausdrücklich liegengelassen:
`WpdbRelationRepository::nameArgs()` liest weiter fest die Standardsprache. **Der Knotenname folgt
seit heute der gewählten Sprache, der Kantenname nicht** — also heisst ein Feld in jeder Sprache
gleich, auch wo der Knoten dahinter übersetzt ist.

⚠️ **Es ist derselbe Umbau ein zweites Mal**, und der Weg ist gegangen: der Speicher trägt seine
Sprache (`__construct(?string $locale = null)`), die Abfrage verbindet zweimal — gewählte Sprache,
dann Standardsprache —, und `writeName()` schreibt in die Sprache, in der gelesen wurde.

⚠️ **Und dieselbe Sicherung ist Pflicht**, sonst wandert der englische Text still in jede Sprache:
*in einer anderen Sprache als der Standardsprache wird nur geschrieben, wenn der Name sich von dem
der Standardsprache **unterscheidet**. Gleichheit heisst «das war der Rückfall», und ein Rückfall ist
eine Anzeige, keine Eingabe.* **Gemessen mit ausgebauter Sicherung**, beim Knotennamen: ein
Verstecken auf Französisch machte den englischen Text zum französischen Namen.

⚠️ *Der Unterschied zum Knoten: eine Kante darf **namenlos** sein
([D-580](../../NewConcept/90-decision-log.md)) — sie hat dann gar keine Beschriftungszeile. Der
Rückfall muss das aushalten, ohne eine anzulegen.*

**Zusage:** je Sprache ihr Text, Rückfall wo eine fehlt, Speichern in Sprache A lässt B unangetastet
— an einer Kante gemessen, nicht an einem Knoten. Anzuhängen an `label-space-check.php`, wo dieselben
neun Zusagen für den Knoten seit heute stehen.

**Gebaut am 2026-09-07.** Derselbe Umbau: der Kantenspeicher trägt seine Sprache
(`__construct(?string $locale = null)`, `null` heisst «die gewählte»), verbindet `label_texts`
zweimal und nimmt `COALESCE(gewählt, standard, '')`, und `writeName()` schreibt in die Sprache, in
der gelesen wurde. **Dieselbe Sicherung steht:** in einer anderen Sprache als der Standardsprache
wird nur geschrieben, wenn der Name sich von dem der Standardsprache unterscheidet. *Mit ausgebauter
Sicherung gemessen: ein Verstecken auf Französisch trug «`__ls Feld`» als französischen Namen ein.*

⚠️ **Die namenlose Kante geht unverändert hindurch:** ein leerer Name ist keine Gleichheit mit dem
Rückfall, sondern räumt die Zeile der gewählten Sprache weg — und wo keine steht, räumt er nichts.
*Gemessen: nach einem Speichern auf Französisch trägt eine namenlose Kante null Beschriftungszeilen.*

⚠️ *Ein Anlegen und ein Zurückholen aus dem Schatten schreiben weiter in die Standardsprache, gleich
was oben gewählt ist — sie sind der Boden der Rückfallkette, keine Übersetzung.*

**Elf Zusagen an `label-space-check.php`, Abschnitt 8.**

---

[x] TASK-066  Die Kantenart ist in der Feldzeile aenderbar — gebaut 2026-09-09

**Zusage** (`field-kind-check`, 17 Aussagen): *die eigene Feldzeile trägt die Art als dasselbe
Auswahlfeld wie beim Anlegen, mit genau den drei Werten; die geerbte Zeile zeigt sie als Wort; ohne
Werte wechselt die Art einfach, ein unbekanntes Wort ist keine Angabe. **Werte wandern mit**
([D-690](../../NewConcept/90-decision-log.md)): Feld → Einstellung schiebt den einen Wert aus den
Benutzersätzen in den `default`-Satz; Einstellung → Feld lässt ihn dort, er ist jetzt die Vorgabe
(D-524); zwei verschiedene Werte sind ein Konflikt mit Satz, und nichts wandert.*

⚠️ **Berichtigt am Abend desselben Tages** ([D-699](../../NewConcept/90-decision-log.md)): *die
gebaute Wanderung zählte Werte statt Sätze — ein `0..*`-Feld mit `rot` und `grün` in einem Satz galt
schon als Konflikt. Sein Beschluss ersetzt sie: **es wandert nichts.** Gibt es Benutzersätze mit
Werten, warnt die Seite, wie viele in den Schatten gehen, verlangt eine Bestätigung, und erst dann
wechselt die Art; die Einstellung beginnt leer. Einstellung → Feld bleibt.* **Umgebaut am 2026-09-10**
(Baureihenfolge II, Punkt 2): *die Wanderung ist weg; ohne Haken bleibt die Art, die Seite nennt Sätze
und Werte, die Weiterleitung trägt den wartenden Wechsel als Umstand, die Zeile zeigt die gewünschte
Art vorgewählt mit dem Haken «ich bestätige» und dem Satz, was er kostet; mit Haken gehen die Sätze
über den Löschweg in den Schatten, dann wechselt die Art. `field-kind-check` misst es in den
Abschnitten 3 bis 5, 22 Aussagen.*

**2026-09-06, sein Auftrag** (mit Bild aus dem Feldblock der Knotenseite, Spalte `Kind`):
*«müsste änderbar sein mit den schon benannten regeln»* — und auf die Rückfrage, wo:
*«ist aus fields in der einstellungsseite»*.

**Heute steht die Art als Marke da und ist nicht zu bedienen.** In der Zeile «Feld hinzufügen»
darunter gibt es sehr wohl ein Auswahlfeld (`composition ⌄`) — **an einer bestehenden Zeile nicht.**
Die Art lässt sich also beim Anlegen wählen und danach nie wieder ändern.

⚠️ **Die Regeln, die er meint, stehen schon:**
[D-618](../../NewConcept/90-decision-log.md) und [D-621](../../NewConcept/90-decision-log.md) — *der
Benutzer bestimmt die Kantenart, es gibt keinen Ast-Automatismus*; und
[D-665](../../NewConcept/90-decision-log.md) — *ein Auswahlfeld wird überall gleich gezeichnet, nur
die Listenbeschriftung, keine Sonderfälle*.

⚠️ **Der Schreiber ist da:** `ModelEditor::markAsSetting()` (die Zeile im Änderungsbuch heisst «field
became a setting» / «setting became a field»), und `retargetField()` hat heute gelernt, die Art beim
Umhängen **nicht** mehr zu verlieren ([D-669](../../NewConcept/90-decision-log.md)). Was fehlt, ist
das Steuerelement in der Zeile und der Weg vom Formular dorthin.

⚠️ *Zu bedenken und **nicht** nebenbei zu entscheiden (`PR-4`): eine Kante von `composition` auf
`setting` umzustellen ändert, wo ihr Wert wohnt ([D-529](../../NewConcept/90-decision-log.md),
[D-026](../../NewConcept/90-decision-log.md)) — bestehende Werte wandern nicht von selbst mit. Was
mit ihnen geschieht, gehört gefragt, bevor der Umschalter gebaut wird.*

**Zusage:** *die Art steht in jeder Feldzeile als dasselbe Auswahlfeld wie beim Anlegen; ein Wechsel
kommt an und steht nach dem Neuladen noch da.* Am Markup gemessen.

---

[x] TASK-067  Aufklappen im Auswahldialog zeigt eine Ebene, nicht den ganzen Ast

**2026-09-06, sein Befund:** *«bezüglich ellapsed and collapsed -> das ist schon richtig das der
data types ast bei fields als default ast übergeben werden soll am besten noch text als knoten, aber
das meinte ich nicht wenn ich auf einen andern knoten klicke wird der dann fast vollständig
ausgeklappt, was nicht sein sollte nur der eine knoten soll aufgemacht werden.»*

**Zwei Dinge, und beide erledigt.**

⚠️ **Der Fehler sass im Skript, nicht im Kern.** *Die Klapp-Behandlung in `assets/admin.js` lief
über **alle** folgenden Zeilen mit grösserer Tiefe und setzte jede auf sichtbar — ein Klick auf einen
Knoten klappte damit sein ganzes Unterholz auf, gleich wie tief. Der Klappzustand der Kinder war
dabei egal, weil niemand ihn ansah.* **Jetzt zeigt das Aufklappen genau `Tiefe + 1`.**

⚠️ **Zuklappen nimmt dagegen den ganzen Ast mit und stellt seine Klapper auf «zu».** *Sonst stünde
ein Kind als «offen» markiert da, während es versteckt ist, und der nächste Klick auf den Vater
brächte einen Ast zurück, den niemand mehr im Sinn hatte.*

⚠️ **Der Text ist im Feldziel-Dialog vorausgewählt** — auf sein *«am besten noch text als knoten»*.
Der Weg dorthin geht über `SimpleType::Text` und die Klasse in `nodes.implemented_by`
([D-510](../../NewConcept/90-decision-log.md)), **nicht über den Namen des Knotens**: den darf er
umbenennen, und ein Sonderfall über einen Anzeigenamen ist verboten (`CD`, Verbotsliste).

**Gemessen an der echten Seite:** 118 Feldziel-Knöpfe, genau einer angehakt, und es ist der
Text-Knoten. `collapsed-default-check` bleibt grün — die Zeilen stehen weiterhin alle im Dokument,
nur ihr Anfangsbild und das Skript entscheiden, was man sieht.

---

[x] TASK-068  Die Auswahl einschraenken — `allowed` — gebaut 2026-09-10 ([D-697](../../NewConcept/90-decision-log.md))

**Zusage** (`allowed-check`, 15 Aussagen): *Fassung 44 erklärt an `Prefixes` die Kante `allowed`
(setting, `0..*`, Ziel `Node reference`); die aufgeklappte Zeile `Präfix` an `Gramm` zeigt eine
Hakenliste je angebotenem Präfix, alle gesetzt, solange nichts gespeichert ist; speichern schreibt die
erlaubten als Verweise in den Satz `Gramm × Präfix` (`settings`, D-667); `Ohm` bleibt leer, also alle;
das Angebot des Feldes `prefix` in einem Einheitenwert hängt am gewählten Nachbarn `einheit` — mit
`Gramm` zwei, mit `Ohm` alle; ein Kind von `Gramm` erbt die Liste (D-221); alle Haken gesetzt heisst
nichts gespeichert.*

**Beim Bauen entschieden, im Kode und hier benannt:** *(1) die Liste wird an der **Struktur** erkannt
— Einstellungskante aus der Kette des Ziels, mehrere Werte, Ziel `Node reference` —, nicht am Namen;
(2) die Adresse `Knoten × geerbte Kante` bekam ihren Leser (`ofRelationAt`), den der Speicher schon
konnte (D-667); (3) das Angebot fragt sein Geschwister: trägt ein Feld desselben Satzes einen Knoten
als Wert, und der erbt ein Feld auf dasselbe Ziel, gilt dessen Liste; (4) die Liste selbst ist nicht
gesperrt wie andere geerbte Einstellungen — verengen darf jeder, und leer heisst «wie oben».*

*Nicht gebaut: der Konflikt nach D-680, wenn eine Liste ein Pflichtfeld leer lässt — der Wähler zeichnet
ein leeres Angebot heute schon als «nicht erfüllbar»; ob das reicht, zeigt der erste Fall im Bestand.*

**Was jetzt gilt und den alten Text unten ablöst:** *das Wort ist `allowed`, nicht `choices`. Die
Kante `allowed` (setting, `0..*`, Ziel `Node reference`) wird **am Ziel** erklärt — für die Präfixe an
`Prefixes` —, nicht an Root. Der Wert liegt im Satz des Knotens zur geerbten Kante (`node_id = Gramm`,
`relation_id = Präfix`), je erlaubtem Kind eine Verweiszeile; gespeichert werden die **erlaubten**,
leer heisst alle. Sein Aufbau steht: `Base units --Unit--> Node reference`, `With prefix --Präfix-->
Prefixes`. **Zu bauen sind drei Stücke:** (1) die Liste in der Kette lesen — heute nimmt
`settingsAt()` je Schlüssel den ersten Wert; (2) die Hakenliste in der Tafel an `Gramm` für das
geerbte Feld `Präfix`; (3) das Angebot des Feldes `prefix` an einer Verwendungsstelle aus der Liste
der **gewählten Einheit** im selben Satz — der neue Mechanismus, ein Feld fragt sein Geschwister —,
dazu die Verengung an der Stelle (D-221, nie weiten) und der Konflikt nach D-680.* Und daneben
[D-698](../../NewConcept/90-decision-log.md): ein Kind darf geerbte Felder anordnen, an derselben Adresse.

⚠️ **Nicht gebaut, mit Grund** (`PR-4`): *seine Frage im Auftrag — «wo wir das festmachen» — ist beim
Lesen des Bestands eine echte geblieben. Drei Stücke fehlen, und das erste ist eine Entscheidung:*

1. **Wo die Einstellungskante `choices` erklärt ist.** *Eine Einstellung ist eine Kante
   ([D-529](../../NewConcept/90-decision-log.md)); `min` und `max` sind Kanten von `Integer` auf
   eigene Knoten darunter. `choices` gilt für **jedes** Feld mit Auswahl, also an `Root` — wie
   `renderer` und `read_only` —, oder am Typ `Node ref`, oder je Ziel. Das ist die Frage, die er
   gestellt hat; sie ist nicht entschieden.*
2. **Mehrere Werte in der Kette.** *`ModelValues::settingsAt()` nimmt je Schlüssel den **ersten**
   Wert; die Ausschlüsse sind viele. Sie sind direkt am Satz der Verwendungsstelle zu lesen
   (`appendValue`, `countValues` gibt es seit D-530), nicht über die Kette.*
3. **Das Steuerelement:** *eine Hakenliste über der angebotenen Menge, alle an, Ausschlüsse ab —
   gezeichnet aus `offeredUnder()`, und der Filter gehört in `optionsFor()` mit dem Unterbaum
   (D-287) und der Konfliktprüfung nach D-680.*

*Sobald 1 entschieden ist, sind 2 und 3 ein Tag. Ohne 1 wäre es eine Kante an einer geratenen Stelle.*

**2026-09-07, sein Auftrag** (mit Bild aus der alten Umsetzung, Bereich «Choices» mit Hakenliste
über den Präfixen): *«ich sollte bei der Verwendung von konstanten werte einschliessen/auschliessen
können. Die frage ist wie wir das realisieren und wo wir das festmachen wir stellen ja schon fest
wann auswahlisten möglich sind das muss glaube ich der gleiche punkt sein. und dann müsten wir in
den feldeinstellungen das wählen können.»*

⚠️ **Es ist beschlossen und nicht gebaut.** *Gemessen: **kein** Verweis auf `D-221` oder `D-287` in
`src/`, und **kein** Einstellungsschlüssel dafür in {@see SettingKey} — 13 gibt es, keiner heisst
so. Sein «der schon mal funktioniert hatte den es aber nicht mehr gibt» ist wörtlich richtig.*

> **[D-287](../../NewConcept/90-decision-log.md):** «*All choices start enabled; unchecking a node
> excludes it **and its subtree*** — the allow-list of [D-221] with the obvious reading, since
> excluding a branch while keeping its children would mean the children are reachable through
> nothing.»

> **[D-221](../../NewConcept/90-decision-log.md):** «**There is no *fixed value*. There is a
> restriction that collapses to one.** … **Restrictions narrow downwards and never widen.** A use
> site further down may restrict further; it may not reopen, or *only Ohm* guaranteed nothing in the
> first place.»

**Und er hat den Ort richtig geraten.** Die Stelle, an der heute festgestellt wird, ob ein Feld eine
Auswahl ist, ist `Rendering::optionsFor()` → `offeredUnder()`, nach
[D-540](../../NewConcept/90-decision-log.md). **Dort und nur dort gehört der Filter hin** — jeder
zweite Ort wäre eine Liste, die anders aussieht als die, aus der gewählt wird.

⚠️ **Gespeichert werden die Ausschlüsse, nicht die Erlaubnisse** — *weil D-287 «all choices start
enabled» sagt: nichts gespeichert heisst alles erlaubt. **Und es macht das Verengen von selbst
richtig**: eine Verwendungsstelle weiter unten fügt Ausschlüsse hinzu, die Vereinigung wächst
monoton, und «may not reopen» ist damit keine Prüfung, sondern eine Eigenschaft.*

⚠️ *Ein Schlüssel mit `0..*` und Knotenverweisen — die Mehrfachwerte gibt es seit
[D-530](../../NewConcept/90-decision-log.md) (`appendValue`, `countValues`). **Kein neues Mittel.***

⚠️ **Was mitgeprüft gehört** ([D-287](../../NewConcept/90-decision-log.md)): *alles ausgeschlossen
ist ein **Modellkonflikt** und keine leere Liste — «an empty allow-list is a model conflict, caught
where the narrowing happens».* Und [D-056](../../NewConcept/90-decision-log.md): *bleibt genau eine
Möglichkeit, verschwindet das Bedienelement* — womit D-221s «restriction that collapses to one» von
selbst der feste Wert wird.

**Zusage:** *an einer Feldzeile lassen sich Werte abwählen; die Auswahlliste desselben Feldes zeigt
danach genau die übrigen, und ein Ausschluss nimmt seinen Unterbaum mit.* Am Markup gemessen.

⚠️ **Nachtrag zur Konfliktprüfung, seine Verengung** ([D-680](../../NewConcept/90-decision-log.md)):
*«Genau wenn 0 keine option ist muss mindestens ein wert da bleiben.»* **Alles abgewählt ist nur bei
`1..1` und `1..*` ein Konflikt.** *Bei `0..1` und `0..*` ist nichts eine gültige Antwort
([D-380](../../NewConcept/90-decision-log.md)), und die leere Auswahl heisst dort «hier wird nichts
gewählt» — keine Störung. **Gefangen wird es beim Verengen**, nicht beim Eingeben.*

---

[x] TASK-069  Der Einstellungssatz wird sichtbar — am Entwicklerschalter — gebaut 2026-09-09

**Zusage** (`page-blocks-check`, Abschnitt TASK-069): *ohne Entwicklermodus steht der `default`-Satz
nicht im Datensatzblock; mit Entwicklermodus steht er da, mit der Marke «settings» statt einer
umstellbaren Art, und nennt seine Werte in Worten — `renderer = …`, `read_only = …` —, weil sie an
Einstellungskanten hängen und der Block dafür keine Spalten führt.*

**2026-09-07, sein Auftrag:** *«ein leiner zusatz ich würde die settings records gerne unten sehen in
den records sehen. Das soll mit dem developer flag im installation menü ein und ausgeschaltet
werden.»*

⚠️ *Er beantwortet damit `INF-073` selbst: der `default`-Satz, in dem die Einstellungen wohnen, **soll**
im Datensatzblock stehen — aber nur, wenn der Entwicklerschalter an ist. Nicht verstecken, sondern
kenntlich machen.*

⚠️ *Der Schalter ist gebaut: {@see \Taxmod\WordPress\Admin\NodesScreen::inDeveloperMode()} wird schon
an `recordsAsTable()` gereicht. Was fehlt, ist die Zeile selbst und die Unterscheidung «Satz mit
Einstellungswerten» — sie braucht keine Spalte: **seine Werte hängen alle an Einstellungskanten**.*

---

[x] TASK-070  Der Installationsbildschirm gegen das Beschlossene prüfen — aufgegangen in TASK-080 (2026-09-09)

*Die Messung ist gemacht, von der anderen Sitzung am selben Tag: die Liste steht in
[`konfigurationsseite.md`](konfigurationsseite.md) und in TASK-080 unten, und der Bau wartet dort auf
eine Entscheidung. Hier nichts zweimal.*

**2026-09-07, sein Auftrag:** *«im menü fehlt so einiges was wir schon besprochen hatten bitte mal
gegen checken und auch implementieren.»*

⚠️ *Gemeint ist der Installationsbildschirm ([D-397](../../NewConcept/90-decision-log.md): «Die
Installation bekommt einen eigenen Bildschirm, unter dem Modellierer»). **Zu tun ist erst eine
Messung, keine Bauerei:** jede Entscheidung, die etwas dorthin legt, gegen das gelegt, was der
Bildschirm heute zeigt — und die Lücke als Liste. Erst danach bauen.*

---

[x] TASK-071  Der Knopf «Add as example» steht nicht an der Bearbeiten-Seite der Vorschau — gebaut 2026-09-09

**Zusage** (`preview-check`): *der Knopf steht in der zweiten Vorschauseite, «As an editor sees it»,
als eigenes Kind der Seite nach dem Absatz — nicht in der ersten, und nicht mehr unter beiden. Das
Formular liegt nicht mehr in einem `<p>`, also legt es die Spalten nicht um.*

**2026-09-07, sein Befund:** *«der button save as example ist auch nicht im edit preview sichtbar»*

⚠️ *Gebaut wurde er in [D-679](../../NewConcept/90-decision-log.md) — er steht **unter** beiden
Vorschauseiten und nicht in der rechten, weil ein `<form>` in einem `<p>` neben dem zweiten
Vorschaublock die zwei Spalten umlegt. **Offenbar ist er dort nicht zu finden**, und das ist ein
Befund über die Anordnung, nicht über die Funktion.*

---

[x] TASK-072  `min` lässt sich nicht auf 0 stellen, `max` nicht auf den Höchstwert — geschlossen durch D-686, nachgemessen 2026-09-09

**Nachgemessen am 2026-09-09, am gezeichneten Markup der Seiten `Integer`, `integer_min` und
`integer_max` mit allen Zeilen offen:** *jedes Zahlfeld trägt `step="1"`, keines eine Unter- oder
Obergrenze; `min` steht auf `-9223372036854775808`, `max` auf `9223372036854775807` — die Grenzen
eines `bigint`, nicht 255.* Der Fall aus dem Verdacht oben — geerbtes `min` am Typ — war es nicht;
es war die Schrittweite, die `min` von `Integer` erbte ([D-686](../../NewConcept/90-decision-log.md),
gebaut in derselben Sitzung). *Nichts mehr zu tun.*

**2026-09-07, sein Befund:** *«irgendwas stimmt mit den einstellungen noch nicht, min kann ich nicht
auf 0 stellen und max nicht auf 255»*, *«bzw auf intmax»*, *«min könnte auch negativ sein davon
abgesehen -int_max»*.

⚠️ *Der Verdacht ist die Verengungsrichtung: `min` darf nach [D-084](../../NewConcept/90-decision-log.md)
nur **steigen** (`Narrowing::OnlyUp`), `max` nur **fallen**. Steht an einem Vorfahren ein `min`, kommt
kein Nachfahre mehr darunter — **auch nicht auf 0, und schon gar nicht ins Negative.***

⚠️ **Noch nicht gemessen**, und die Messung entscheidet zwischen zwei ganz verschiedenen Fällen: *ein
geerbtes `min` am Typ (dann wirkt die Regel wie vorgesehen und die Frage ist, ob sie hier gelten
soll), oder eine Schranke im Steuerelement (dann ist es schlicht ein Fehler).*

⚠️ *Und seine Zahl ist eine eigene Aussage: **die Grenzen eines `int` sind ±int_max**, nicht 255 —
`display_size` reicht bis 255 ([D-660](../../NewConcept/90-decision-log.md)), der Wertebereich nicht.*

---

[x] TASK-073  Ein schreibender Wächter stirbt an einem parallelen Lauf — gebaut 2026-09-09

**Zusage:** *die Klammer holt vor `START TRANSACTION` eine benannte Verbindungssperre, drei Sekunden
Geduld; wer sie nicht bekommt, sagt es und geht mit Rückgabewert 3, ohne auf das Ende des anderen zu
warten; sie fällt mit der Verbindung, auch nach einem Abbruch; danach bekommt der nächste Lauf sie
sofort.* Gemessen in Abschnitt 4 von `no-model-write-check` an zwei echten Prozessen, mit
`lib/klammer-probe.php` als Gegenüber. **Bestätigt am Abend des 2026-09-09**, sein Wort: *«ok dann
lass erstmal die sperre so oft bauen wir nicht parallel»* — die Wahl «ein Lauf zur Zeit» statt «jeder
Wächter auf eigener Wiese» bleibt; eine eigene Wiese je Wächter hätte den Fall «derselbe Wächter
zweimal» nicht abgedeckt. *Und seine Frage dazu, offen: ob die vielen Wächter überhaupt sinnvoll sind,
wo zwei am selben Ort arbeiten — eine Messung der Überlappung ist angeboten, nicht begonnen.*

**2026-09-09, sein Auftrag:** *«kannst du die korrektur der beiden wächter dort bitte anhängen»*, nach
dem Befund aus dem Prüflauf desselben Morgens.

⚠️ **Gemessen:** *`cleanup-screen-check` brach im vollen Randlauf mit einem Deadlock ab — MySQL hat ihn
festgehalten: um 07:26:35 legte der Wächter seinen Knoten `__cl Modell` an, und **eine zweite
Verbindung** legte in derselben Sekunde Knoten unter demselben Elternknoten an, mit 84 offenen
Schreibvorgängen. Zeitgleich lief eine zweite interaktive Sitzung auf demselben Rechner. Vier
Wiederholungen danach, jede allein: grün. Kein Rückstand unter dem Elternknoten.*

⚠️ **Es ist kein Fehler des Wächters, sondern eine Eigenschaft des Netzes:** *alle Wächter arbeiten in
derselben Datenbank und im selben Bereich des Modells, und die Klammer aus `lib/no-write.php` hält
jede Zeile bis zum Prozessende gesperrt. **Zwei Läufe zugleich sind damit ein Deadlock mit Ansage** —
und der eine, der verliert, meldet rot, obwohl seine Aussage stimmt. Ein Wächter, der ohne Grund rot
wird, wird übersehen (siehe `doc-reach-stillgelegt`, [D-574](../../NewConcept/90-decision-log.md)).*

⚠️ **Was zu entscheiden ist, bevor gebaut wird** (`PR-4`): *entweder **ein** Lauf zur Zeit — eine Sperre,
die ein zweiter Start sieht und mit einem klaren Satz wartet oder abbricht —, oder jeder Wächter auf
seiner eigenen Wiese, so dass sich zwei nicht berühren. Das zweite ist teurer und schützt nicht gegen
denselben Wächter zweimal. **Nicht gewählt:** ein Wiederholen bei Deadlock — es versteckt genau das,
was hier sichtbar wurde.*

**Lösungsweg (2026-09-09, `PROPOSED` — sein Auftrag: «kannst du auch gleich lösungen erarbeiten»):**

*Die Sperre gehört in die Klammer, nicht in die Wächter.* `lib/no-write.php` ist die **eine** Datei,
die jeder schreibende Lauf unmittelbar nach `wp-load` trägt — `no-model-write-check` erzwingt das
schon. Wer dort eine Sperre setzt, hat alle 50 Läufe auf einmal, ohne einen einzigen anzufassen.

1. **Die Sperre ist eine benannte Datenbanksperre**, geholt vor `START TRANSACTION`, mit einer
   Wartezeit von einigen Sekunden. MySQL gibt sie beim Trennen der Verbindung von selbst frei — also
   auch nach einem `exit(1)` in Zeile 200, genau wie die Klammer selbst. *Kein Sperrdatei-Mechanismus:
   eine Datei überlebt einen abgestürzten Prozess, eine Verbindungssperre nicht.*
2. **Wer sie nicht bekommt, sagt es und bricht ab** — mit einem eigenen Rückgabewert, nicht mit dem
   der roten Aussage. Der Satz nennt, dass ein anderer Lauf sie hält. **Nicht warten bis zum Ende des
   anderen:** ein voller Randlauf dauert Minuten, und ein Wächter, der stumm Minuten hängt, sieht aus
   wie einer, der hängt.
3. **Der Wächter dafür ist `no-model-write-check` selbst:** er prüft schon, dass die Klammer da ist und
   nichts ein zweites `START TRANSACTION` beginnt. Dazu kommt eine Zusage: *zwei Prozesse mit der
   Klammer zugleich, der zweite bekommt den eigenen Rückgabewert und den Satz.* Das ist am Kind-Prozess
   messbar, ohne die Datenbank zu berühren.
4. **Die Schleife in `tests/README.md` bleibt, wie sie ist.** Sie läuft seriell; die Sperre schützt den
   Fall, der dort nicht steht — zwei Fenster, zwei Sitzungen.

*Was das nicht löst und nicht lösen soll:* ein Wächter, der **ohne** Klammer schreibt. Den gibt es nach
der Messung vom 2026-09-06 nicht mehr, und `no-model-write-check` hält es so.

---

[ ] TASK-074  `always-on` steht mit 0 Byte auf seiner Decke — Befund, keine Änderung

**2026-09-09, derselbe Auftrag.** ⚠️ **Gemessen:** *CLAUDE.md, das Arbeitsmodell und AGENTS.md
summieren sich auf genau 47652 Bytes, und das ist die Decke. Die nächste hinzugefügte Zeile in einer
der drei Dateien macht den Wächter rot.*

⚠️ **Das ist kein Fehler, sondern seine Entscheidung, und der Wächter sagt es selbst:** *«Am 2026-09-06
auf 47652 Bytes angehoben — seine Entscheidung, sein Wort: ‹anheben›»* und *«Die Decke steht bewusst
genau auf dem heutigen Stand und nicht darüber. … Eine Decke mit Luft darin wäre keine.»* **Die
Korrektur ist also keine am Wächter.** Was hier steht, ist die Warnung an den Nächsten, der eine der
drei Dateien anfasst: **die Frage ist «was kommt dafür weg?», nicht «wie hoch darf es»** — und wenn er
die Decke doch anheben will, ist das sein Wort und eine Zeile im Entscheidungsbuch, kein Nebeneffekt
eines Umbaus.

⚠️ *Zu tun ist nichts, solange niemand schreibt. Der Eintrag wird geschlossen, sobald die Decke das
nächste Mal bewusst bewegt oder der Bestand darunter gekürzt wurde.*

---

[x] TASK-075  Das Vokabular, das D-506 abgeschafft hat, lebt im Kode weiter — und sperrt aus — gebaut 2026-09-09, und das Sperren war schon gefallen

**Was beim Bauen herauskam** (`PR-7`): *das Aussperren gab es beim Messen am Morgen nicht mehr. Der
Bau zu [D-682](../../NewConcept/90-decision-log.md) hatte es geschlossen: die Tafel zeichnet jeden im
Modell erklärten Namen **über seine Kante** — `with_label` als Schalter, `label_role` als Auswahl —,
und das Speichern findet die Kante am Namen oder an der Nummer. `renderer-choice-mask-check` hält
genau das an `converter`, `label_role`, `with_label`; **der Wächter aus dem Lösungsweg war also schon
da.** Die Zählung «4 von 14 kennt die Aufzählung nicht» stimmte, nur die Folgerung «und sperrt aus»
war die vom 2026-09-07 und nicht mehr die von heute.*

**Gebaut, und damit ist von D-529s Liste eingelöst, was einlösbar war:** *`Narrowing` ist gefallen
samt `direction()` und `isBounding()` — kein Leser, ein Test, der die gefallene Regel behauptete, mit
ihr. Die letzte Methode, die einen freien Namen als typlos las (`settingValue`, kein Aufrufer), ist
weg. Die drei Kommentare, die «once fixed never unfixed» und «narrowing means higher» behaupteten,
sagen jetzt, was gilt (D-399). `SettingKey` trägt im Kopf, was es seither ist: eine Liste
reservierter Wörter, keine Liste erlaubter.*

**Bewusst stehengelassen, mit Grund:** *`SettingKey` selbst — 37 Verwendungen, fast alle als Name
(`Multiplicity`, `Renderer`, `ReadOnly`); ein Umbenennen nach `CD-9` wäre ein Durchgang durch
29 Dateien ohne eine neue Aussage, und `applyingTo()` / `typeFor()` tragen noch den Rückfall der
Vorschau ohne Modellzugang. `SettingShape`, `SettingCategory`, `ResolvedSetting` sind Darstellung
(D-506: «ein Wort für den Benutzer ist keine Kategorie im Modell»).*

**2026-09-09, sein Auftrag:** *«deine findings zu db settings und relations kannst du hierfür eine
korrekturliste erstellen»*. Dies ist der erste von drei Einträgen; der vierte Befund — ob D-582 und
D-586 zurückgezogen sind — ist keiner: [D-643](../../NewConcept/90-decision-log.md) hat beide am
2026-09-05 zurückgenommen, mit der Messung «je 0 Zeilen». *Ich hatte behauptet, keine Zeile dazu zu
finden; sie steht in D-643 und in `package.md`.*

⚠️ **Der Beschluss:** [D-506](../../NewConcept/90-decision-log.md): *«Das Wort ‹Einstellung›
verschwindet. Es gibt Felder … Mehr ist es nicht.»* Und: *«damit verschwindet ein ganzes Vokabular,
nicht nur eine Tabelle: `SettingKey`, `SettingRecord`, `SettingShape`, der `Settings`-Dienst …»*
[D-529](../../NewConcept/90-decision-log.md) vollzieht es und nimmt `Narrowing` mit: *«alle vier Fälle
beschreiben, wie ein Wert die Auflösungskette hinunterwandern darf, und die Kette gibt es nicht mehr.»*

⚠️ **Gemessen am 2026-09-09:** *`SettingKey` steht in **29** Dateien, `SettingShape` in 6,
`ResolvedSetting` in 6, `SettingCategory` in 4, `Narrowing` in 3. `SettingRecord` ist weg.* **Und die
Aufzählung tut Schaden, nicht nur Unordnung:** *von **14** Einstellungsnamen im Modell kennt sie
**4 nicht** — `orientation`, `exponent`, `with_label`, `label_role`. [D-682](../../NewConcept/90-decision-log.md)
hat an genau dieser Liste gemessen, dass sie die Einstellungstafel absperrte: «eine geschlossene Liste
sperrte einen offenen Mechanismus ab».*

⚠️ **Was davon bleiben darf, ist entschieden, nicht zu erfinden:** *[D-084](../../NewConcept/90-decision-log.md)
reserviert die Namen, die der Motor selbst liest — `hide`, `read_only`, `renderer` — damit ein Autor
sie nicht überschreibt. **Das ist eine Liste reservierter Wörter, keine Liste erlaubter.** Der Umbau
ist: jede Stelle, die «kenne ich den Namen?» fragt, fragt künftig «gibt es die Kante?» — und die
Aufzählung schrumpft auf das, was der Motor wirklich liest, oder fällt.*

**Reihenfolge nach `PR-9`:** Wächter zuerst — ein Lauf, der an einer frei benannten Einstellungskante
(`orientation`) dieselbe Tafel, dasselbe Speichern und dieselbe Auflösung verlangt wie an `min` —,
dann die Leser, dann das Aufräumen.

**Lösungsweg (2026-09-09, `PROPOSED`):**

*Zuerst die Messung, die den Umbau klein macht:* von den Aufrufen an `SettingKey` sind **fast alle
Namen, keine Fragen** — `Multiplicity` 13 mal, `Renderer` 11, `ReadOnly` 8, `Converter` 4, und so
weiter. Das sind die reservierten Wörter aus D-084, und die dürfen bleiben. **Nur vier Stellen
fragen «kenne ich den Namen?»** — `tryFrom()` in `RenderedSetting`, zweimal in `Rendering`, einmal
im `NodesScreen` —, und genau dort fällt eine freie Einstellung durch. Dazu je einmal `applyingTo()`,
`isRelationOnly()`, `defaultSwitch()` und dreimal `typeFor()`.

1. **Die vier `tryFrom()`-Stellen werden zu «gibt es die Kante?».** Was sie heute aus dem Namen
   ableiten — den Typ, die Form, die Vorgabe —, sagt die Einstellungskante und ihr Ziel selbst:
   der Typ ist der Typ des Zielknotens, die Vorgabe der `default`-Satz, die Form folgt aus dem Typ
   (`NodeRef` → Auswahl, `Bool` → Schalter, sonst Eingabe). *Das ist [D-556](../../NewConcept/90-decision-log.md),
   schon entschieden: «keine neue Regel, sondern zwei bestehende zusammengelegt».*
2. **`typeFor()` und `applyingTo()` fallen**, denn beides beantwortet die Kette
   ([D-668](../../NewConcept/90-decision-log.md): «welche Einstellungen eine Verwendungsstelle
   anbietet, sagt allein die Kette ihres Ziels»). Eine Liste, die es «weiss», ist die zweite Ablage,
   die D-578 verbietet.
3. **`isRelationOnly()` ist die eine echte Ausnahme** — `multiplicity` gilt nur an der Verwendungsstelle
   und ist seit [D-528](../../NewConcept/90-decision-log.md) eine **Spalte**, keine Kante. Sie gehört
   gar nicht in eine Einstellungsliste; die eine Stelle liest die Spalte.
4. **Was danach übrig bleibt, ist `SettingKey` als Liste reservierter Namen mit `isReserved()`** — und
   nichts weiter. Ob sie dann noch «SettingKey» heissen darf oder «ReservedName» heissen muss, ist
   `CD-9` und keine Konzeptfrage.
5. **`Narrowing` fällt ersatzlos** — D-529 sagt es wörtlich, und die drei Stellen sind die Aufzählung
   selbst, `SettingKey::direction()` und ein Kommentar in `Schema`. `SettingShape`, `SettingCategory`,
   `ResolvedSetting` sind Darstellung (Renderer, Tafel) und **keine Kategorie im Modell**; sie bleiben,
   bis die Tafel selbst umgebaut wird — D-506: «Ein Wort für den Benutzer ist keine Kategorie im
   Modell.»

**Der Wächter zuerst**, damit der Umbau nicht rät: ein Lauf legt eine Einstellungskante mit einem
Namen an, den keine Aufzählung kennt, an einem Knoten ohne Sonderfall — und verlangt, dass die Tafel
sie zeigt, das Speichern sie schreibt und die Auflösung sie am Kind findet. **Heute wäre er an der
Tafel rot** (D-682 hat es gemessen), und das ist der Beleg, den der Umbau braucht.

---

[x] TASK-076  `FieldType` trägt die Kantenart ein zweites Mal — gebaut 2026-09-09, kleiner als geplant

**Was beim Bauen herauskam, gegen den Lösungsweg unten** (`PR-7`): *von den drei Fällen war nur der
erste einer. Die zwei Stellen im Renderer — der Behälter eines Einstellungsknotens
([D-546](../../NewConcept/90-decision-log.md)) und das Hindurchsehen durch markierte Zwischenknoten
([D-544](../../NewConcept/90-decision-log.md)) — zeichnen einen **Knoten**, nicht eine Kante, und haben
keine Kante in der Hand. Sie stehen auf D-621s **zweitem** Satz: «ein Knoten, den nur Vererbung
erreicht, bekommt seinen Charakter von der Kante über seinem nächsten Vorfahren». **Das ist die
Ableitung, die `resolvedFieldTypes()` rechnet — beschlossen, nicht erfunden.** Der Bezug auf D-605
und D-607 im Lösungsweg war falsch: die handeln vom Erben einer Einstellungs**kante**, nicht von der
Sorte eines Knotens.*

**Gebaut:** *die Überschrift der zwei Blöcke im Bildschirm fragt dieselbe Kantenart wie die Zeilen
darunter; `FieldType` ist aus dem Bildschirm verschwunden, und eine nie gerufene Methode an der
Aufzählung mit ihr. Die Ableitung im Speicher bleibt, mit D-621 als Grund.* Kernlauf, `page-blocks`,
`preview`, `renderer-choice-mask` grün.

**2026-09-09, derselbe Auftrag.** ⚠️ **Der Beschluss:** [D-621](../../NewConcept/90-decision-log.md):
*«Die Kante sagt, was etwas hier ist — nicht der Knoten und nicht der Ast. `nodes.field_type` faellt.»*
Und [D-639](../../NewConcept/90-decision-log.md): *die Kantenart ist **eine** Spalte mit drei Werten
und je einer Klasse dahinter.*

⚠️ **Gemessen:** *die Spalte ist gefallen (TASK-059), die Aufzählung `FieldType` mit den Fällen
`Setting` und `Model` steht noch in 4 Dateien. Der Speicher baut sie aus `relations.kind` nach: ein
Knoten gilt als `Setting`, wenn **alle** Kanten auf ihn Einstellungskanten sind. **Das ist eine
Ableitung, keine zweite Ablage** — erlaubt nach `CD · Prohibited` («everything else derives»).*

⚠️ **Der Befund ist deshalb kleiner als der Name:** *es geht nicht um eine Spalte, sondern darum, ob
eine Frage «was für ein Knoten ist das?» überhaupt noch gestellt werden darf, wenn D-621 sagt, dass die
Kante es sagt. Wo `FieldType` gelesen wird, wird eine Knotenfrage gestellt, die eine Kantenfrage sein
müsste — und an einem Knoten wie `Integer`, auf den Benutzer- **und** Autorenkanten zeigen, hat die
Knotenfrage keine richtige Antwort.* **Zu tun:** die 4 Stellen einzeln lesen; jede, die die Kante
schon in der Hand hat, fragt die Kante; was übrig bleibt, wird hier notiert. Kein Neubau.

**Lösungsweg (2026-09-09, `PROPOSED`) — die vier Stellen sind gelesen, und es sind drei Fälle:**

1. **`NodesScreen`, Feldblock:** die Aufzählung ist dort nur die **Überschrift** der zwei Blöcke
   ([D-518](../../NewConcept/90-decision-log.md): «zweimal dieselbe Tabelle, mit je eigener
   Überschrift»). Die Zeilen werden schon an der Kante getrennt — die Kante liegt in der Hand. **Die
   Überschrift kann die Kantenart nehmen**, und `FieldType` verschwindet aus dem Bildschirm.
2. **`Rendering`, zwei Stellen:** hier wird gefragt, ob ein **Knoten** eine Einstellung ist — einmal
   für die eigene Sorte, einmal aufgelöst entlang der Vorfahren. *Das ist die Frage aus
   [D-605](../../NewConcept/90-decision-log.md), die [D-607](../../NewConcept/90-decision-log.md) als
   «zu breit» zurückgenommen hat:* die Regel ist «ein Knoten erbt keine Einstellungskante, die auf
   **ihn selbst** zeigt» — eine Aussage über eine Kante und ihren Zielknoten, nicht über eine Sorte.
   **Beide Stellen fragen künftig: zeigt diese Kante auf diesen Knoten?** Damit fällt auch die
   Auflösung entlang der Vorfahren, denn eine Kante hat ein Ziel und braucht keine Kette.
3. **`WpdbNodeRepository`, die Ableitung selbst:** bleibt genau so lange, wie 2 sie liest. Fällt 2, fällt
   sie mit — samt der Aufbewahrung in `Schema`, die die alte Spalte vor dem Streichen gesichert hat
   (TASK-059); die ist ein Schatten und bleibt.

*Der Massstab:* `renderer-per-node` ist kein Wächter, sondern der Vorher-Nachher-Abzug, mit dem
TASK-059 den Fall der Spalte gemessen hat — «was jeder Knoten zeichnet», Zeile für Zeile. **Er wird
vor dem Umbau gezogen und danach verglichen**, und `preview-check` läuft dazu. Weicht keine Zeile ab,
ist die Knotenfrage ohne Verlust gefallen. **Ein neuer Wächter ist nicht nötig; das ist der seltene
Fall.**

---

[x] TASK-077  111 von 113 Benutzersätzen tragen keine einzige Wertzeile — gebaut 2026-09-09

**Zusage:** *(1) `cleanup-screen-check`, Abschnitt 6: ein `user`-Satz ohne Wertzeile **und ohne
Journalzeile** ist die fünfte Quelle des Cleanup-Bildschirms, mit eigenem Knopf; ein Satz, den ein
Mensch anlegt, hat seine Anlegezeile («record created») und wird nicht gelistet; ein Satz mit Werten
auch nicht; das Entfernen geht in den Schatten und ist umkehrbar. (2) `simple-type-check`,
Abschnitt 8: unter `Primitives` und unter `Settings` gibt es keinen Benutzersatz (D-664, D-677,
D-691) — Fassung 41 hat die drei leeren gestrichen, in den Schatten, mit Änderungsgruppe.*

**Entschieden am Abend** ([D-702](../../NewConcept/90-decision-log.md)): *der Satz einer
Verwendungsstelle ist `default`, nicht `user`. Die Ausnahme `relation_id ≠ 0` fällt — umzubauen, nicht
begonnen: die zwei Sätze von `Street / H#` umtypen (Fassung 42), der Rand legt solche Sätze als
`default` an, `simple-type-check` und Fassung 41 ohne die Ausnahme.* *Vorher stand hier: angenommen,
der Satz einer Verwendungsstelle sei `user` (D-674) und bleibe ausgenommen.* Und «ohne Journal»
nimmt einen Satz aus, der vor dem Journal der Anlage entstand; die 104 an `Condensator` bleiben liegen,
bis er sie auf dem Cleanup-Bildschirm entfernt.*

**2026-09-09, derselbe Auftrag.** ⚠️ **Gemessen:** *113 Sätze mit `record_type = user`; **111** davon
ohne eine Wertzeile. 105 hängen an `Condensator`, je einer an `Parts List`, `Address`,
`chooser-dialog`, `Decimal`, `Boolean`, `Email`. Von den 51 `default`-Sätzen ist **keiner** leer.
**104 der 105 an `Condensator` sind am 2026-08-30 entstanden** — dem Tag, an dem die
`settings`-Tabelle umzog ([D-529](../../NewConcept/90-decision-log.md)); das Journal nennt für
keinen davon einen Wächterlauf.*

⚠️ **Was das nicht ist:** *kein Konzeptfehler. Ein Satz ohne Werte ist erlaubt — ein Benutzer kann
einen Datensatz anlegen und nichts eintragen. **Aber 104 an einem Tag, an einem Knoten, ohne einen
Wert, sind kein Benutzer** — das ist eine Wanderung oder ein Lauf, der Sätze angelegt und die Werte
nicht hinterhergeschrieben hat.*

⚠️ **Was zu tun ist, in dieser Reihenfolge, und nichts davon ist ein Löschen ohne Befund:** *(1) im
Journal des 2026-08-30 die Änderungsgruppe finden, die die 104 angelegt hat, und sagen, welcher Akt es
war; (2) prüfen, ob die Sätze im Schatten Werte hatten, die die Wanderung verloren hat — dann ist es
ein Datenverlust und der Eintrag wird ein anderer; (3) erst dann: entweder gehören sie dem
Cleanup-Bildschirm als vierte Quelle «Satz ohne Werte» ([D-247](../../NewConcept/90-decision-log.md)),
oder sie fallen mit einem Wächter, der zählt, dass danach 0 leere Benutzersätze übrig sind und die
2 nichtleeren unberührt.*

⚠️ *`Decimal`, `Boolean` und `Email` sind einfache Typen: dort darf es nach
[D-677](../../NewConcept/90-decision-log.md) **gar keinen** `user`-Satz geben, nur `default` und
`example`. Diese drei sind damit nicht nur leer, sondern am falschen Ort — und `simple-type-check`
sieht es nicht, sonst wäre er rot.*

**Lösungsweg (2026-09-09, `PROPOSED`) — Schritt 1 und 2 sind gelaufen, und der Befund ist schärfer:**

⚠️ **Die 104 Sätze hat kein Akt angelegt.** *Gemessen: alle 104 tragen `created_at = 2026-08-30
00:00:00` — **Mitternacht auf die Sekunde**, ein Datum ohne Uhrzeit. Das Journal kennt **keine
einzige Zeile** zu ihnen, weder Anlegen noch Ändern; um Mitternacht steht dort nichts. Im Schatten
liegt keiner von ihnen. Ihre Nummern reichen von 7625 bis 10497 mit 2769 Lücken — sie sind über einen
langen Zeitraum vergeben worden, nicht in einem Lauf. **Keine Wanderung in `Schema.php` schreibt ein
Datum ohne Uhrzeit**, und kein Wächter im Repository, auch nicht in seiner Geschichte, legt Sätze an
`Condensator` an.* **Der Ursprung liegt ausserhalb dessen, was das Repository heute sagt** — am
ehesten ein Import oder ein Umbenennen der Satztabelle am 2026-08-30, bei dem `created_at` mit dem
Tagesdatum gefüllt wurde. `PR-7`: das ist eine Vermutung, keine Messung.

⚠️ **Verloren ist nichts:** *`Condensator` hat **keine eigene Kante** und keine Kinder; seine Felder
sind die zwei geerbten von `Passiv`. Die einzige Schattenwertzeile an einem seiner Sätze gehört zu
Satz 172 — der eine, der **nicht** um Mitternacht entstand — und sie ist am 2026-09-08 mit ihrer Kante
**geparkt** worden ([D-575](../../NewConcept/90-decision-log.md)), nicht verloren. Die 104 hatten nie
einen Wert, den es zu verlieren gab.*

**Damit ist Schritt 3 die Antwort, und sie ist zweiteilig:**

1. **Die 104 sind Rückstand und gehören dem Cleanup-Bildschirm** ([D-247](../../NewConcept/90-decision-log.md))
   als vierte Quelle: *«Benutzersatz ohne Wertzeile, ohne Journal».* **Ohne Journal** ist die
   Bedingung, die einen echten leeren Satz eines Benutzers ausnimmt — der hat eine Anlegezeile. So
   entscheidet keine Zahl und kein Datum, sondern die Geschichte. `cleanup-screen-check` bekommt die
   Quelle als eigenen Fall, mit eigener Wiese wie die drei anderen.
2. **Die drei an einfachen Typen sind ein Loch im Netz, kein Rückstand.** `simple-type-check` prüft
   das Inventar der Typen, nicht ihre Sätze. **Die Zusage aus D-664 und D-677 — «unter `Primitives`
   niemals `user`» — hat heute keinen Wächter.** Sie kommt zu `simple-type-check`, weil sie eine
   Aussage über einfache Typen ist; und die drei Sätze fallen mit der Wanderung, die der Wächter
   erzwingt ([D-672](../../NewConcept/90-decision-log.md): «ändert sich, was erlaubt ist, wandert der
   Bestand im selben Schritt mit»). *Ob sie `example` werden oder fallen, entscheidet ihr Inhalt: sie
   sind leer, also fallen sie.*

*Die zwei an `Parts List` und `Address` sind, was ein Benutzersatz ohne Eingabe ist, und bleiben.
`chooser-dialog` ist ein Renderer-Knoten — ein `user`-Satz dort ist derselbe Fall wie an einem
einfachen Typ, und die Frage, ob D-677 den Settings-Ast mitmeint, ist offen und gehört auf den
Eingang, nicht hierher.*

---

[x] TASK-078  Geerbt ist sichtbar, und ein unzulässiger geerbter Wert ist ein Konflikt — für jede Einstellung — gebaut 2026-09-09

**Zusage** (`setting-lock-check`, 24 Aussagen, und `InheritedSettingLockTest` im Kern): *eine geerbte
Einstellung steht gesperrt da, mit «inherited from ‹Name›» in Worten und einem Haken «override»;
ohne Haken schreibt das Seitenspeichern die Zeile nicht, mit Haken wird sie ein eigener Wert; ein
geerbter Renderer, der hier nicht zulässig ist, gilt nicht — der Typ-Standard zeichnet, die Zeile
steht offen mit gesetztem Haken und dem Satz «chosen automatically — ‹compact› is not permitted
here»; hier Gewähltes bleibt, auch wenn es unzulässig ist (D-360). Beides an beiden Adressen
(D-685): Wertspalte am Knoten und Tafel unter der Feldzeile.*

**Was beim Bauen anders wurde als im Lösungsweg, mit Grund:** *(1) **Kein zweiter Seitenaufruf und
kein Skript** — der Haken ist das «Überschreiben», das Stilblatt sperrt das Steuerelement daneben über
`:has()`, und der Rand schreibt eine geerbte Zeile ohne Haken nicht, was auch immer im Steuerelement
steht. Damit ist die Sperre auch dort eine, wo das Stilblatt nichts kann. (2) **Gemessen, warum es
den Fehler gab:** das Seitenspeichern schrieb **jeden** gezeigten Wert als eigenen — der geerbte
Renderer wurde beim ersten Speichern zur Tatsache, und was danach oben geändert wurde, kam nie mehr
an. Das ist die Kopie, die D-402 ausschliesst; der Riegel schliesst sie. (3) **Die Zulässigkeit misst
der Kern an einer Stelle**, {@see \Taxmod\Core\Renderer\RendererRegistry::permits()}, dieselbe Regel,
nach der angeboten wird; der Abstieg und die Tafel fragen beide dort. (4) **Die Untereinstellungen
eines geerbten Renderers** sind nicht gesperrt — sie gehören dem Satz des Knotens (D-684) und dürfen
hier gesetzt werden (D-682); ihre ↑-Marke bleibt.*

*Nicht gebaut und benannt: die Zulässigkeit gilt heute für Auswahlen (Renderer); ein geerbter
**Zahlenwert** an einem Typ, der ihn nicht fassen kann, ist noch kein Konflikt. Der Fall braucht erst
ein Beispiel, das es im Bestand gibt.*

**2026-09-09, sein Befund und seine Regel** ([D-687](../../NewConcept/90-decision-log.md)): *«es
entstehen immer wieder fehler dadurch das in der gui eine default schalterstellung steht diese aber
nicht gespeichert ist»* — und: *«gerade wenn ein vererbter renderer nicht zulässig ist müsste auch ein
zulässiger gewählt werden das gilt für alle einstellung».*

⚠️ **Zwei Hälften, und die erste ist alt:** *[D-266](../../NewConcept/90-decision-log.md) und
[D-361](../../NewConcept/90-decision-log.md) verlangen seit dem 2026-08-23, dass geerbt sichtbar ist
und zurück zu geerbt eine ausdrückliche Handlung. Gebaut ist ein Pfeil «↑» mit dem Wort im Tooltip;
das Bedienelement selbst zeigt den geerbten Wert wie einen gesetzten. Der Kommentar im Renderer nennt
den Fall «bald selten» — das war, als jede Zeile materialisiert war (D-423). **Seit D-529 ist geerbt
am Renderer der Normalfall.***

**Lösungsweg (2026-09-09) — die Sperre war mein Vorschlag und ist seit [D-689](../../NewConcept/90-decision-log.md)
sein Wort: «sperren finde ich gut». Schritt 1 bis 4 sind damit entschieden, nicht vorgeschlagen:**

1. **Geerbt heisst gesperrt.** Das Steuerelement einer geerbten Einstellung ist nicht bedienbar und
   zeigt den Wert mit seiner Herkunft **in Worten**: «geerbt von Integer», kein Pfeil, kein Tooltip.
   Der Name braucht die Verdrahtung, die der Renderer heute nicht hat (D-159: ein Renderer holt
   nichts) — der Abstieg reicht ihn hinein wie den Wert. *Ein gesperrtes Feld kann nicht so aussehen,
   als hätte man es gesetzt: die Anzeige und das Gespeicherte fallen zusammen, das ist die ganze
   Lösung für seinen ersten Satz.*
2. **«Überschreiben» je Zeile schaltet frei**, das Gegenstück zum vorhandenen «Reset». Zwei Akte in
   beide Richtungen (D-266), in fester Position (D-370). Erst nach «Überschreiben» schreibt das
   Seitenspeichern die Zeile. **Der dritte Zustand — niemand hat es gesagt — bleibt ein leeres,
   freies Feld ohne Herkunft.**
3. **Zulässigkeit misst auch das Geerbte.** Die Auflösung ([D-602](../../NewConcept/90-decision-log.md))
   liefert heute den ersten Wert die Kette hinauf. Künftig fragt sie an jedem gefundenen Wert
   `handles()` gegen den Typ der Stelle (D-603: am Knoten der Knoten, an der Kante der Zielknoten).
   Ein unzulässiger Wert wird **nicht** übersprungen — das wäre ein stilles Weiterwandern zum
   nächsten Vorfahren, und «näher schlägt ferner» hätte einen Wert, den niemand sieht. **Er wird als
   Konflikt gemeldet**, an derselben Stelle wie D-680: beim Wechsel des Typs, beim Ändern am
   Vorfahren, beim Anlegen. Die Tafel zeigt ihn als solchen — und was sie dann zeigt, sagt Schritt 4.
4. **Wer wählt, und was mit der Sperre geschieht, ist entschieden** — beides sein Wort am selben
   Tag: [D-688](../../NewConcept/90-decision-log.md) *«ersten zulässigen als Vorgabe aber nur wenn
   der vererbte nicht mehr zulässig ist»*, und [D-689](../../NewConcept/90-decision-log.md) *«sperren
   finde ich gut, aktiv sagen hier überschreibe ich, muss automatisch gemacht werden wenn der
   renderer (oder anderes) für kind nicht mehr zulässig ist».* **Im Konflikt tut das System also, was
   sonst der Modellierer tut:** *es löst «Überschreiben» an dieser Zeile aus — die Sperre fällt —, und
   setzt den **ersten zulässigen aus der Registratur** als Vorgabe hinein. Nicht geschrieben; in der
   Tafel als «automatisch gewählt, weil ‹compact› hier nicht zulässig ist» sichtbar, dritter Zustand
   neben gesetzt und geerbt, am Ort ersetzbar (R33c).* Solange der geerbte Wert taugt, bleibt die
   Zeile gesperrt und nichts wählt an ihm vorbei. **Die Stelle steht nie ohne Wert da, und sie sagt,
   dass er nicht von ihr stammt.**

**Der Wächter zuerst:** ein Lauf, der an einem Knoten einen Renderer setzt, darunter einen Knoten
eines fremden Basistyps anlegt, und verlangt: die Tafel dort zeigt «geerbt», der Wert gilt **nicht**,
und ein Konflikt steht — nicht `plain` und nicht der Wert von oben. Heute wäre er rot, und das ist
der Beleg. `preview-check` und `renderer-choice-mask-check` laufen dazu.

*Nicht Teil dieser Aufgabe:* die Untereinstellungen eines geerbten Renderers. D-682 sagt, sie kommen
«gesetzt oder geerbt» dazu, und D-684 gibt ihnen ihr Fach im Satz des Knotens — das steht und bleibt.

---

**Baureihenfolge vom 2026-09-09** — sein Auftrag: *«kannst du mal eine build reihenfolge aufstellen und
die dann selbstänig bauen, fragen vorher und nachher möglichst viel umsetzen ohne was zu verbauen».*
Vorher gefragt, seine Antworten: TASK-062 **gar nicht in diesem Lauf**; TASK-066 **Werte wandern mit**
([D-690](../../NewConcept/90-decision-log.md)); D-677 gilt **auch für Settings**
([D-691](../../NewConcept/90-decision-log.md)); Bestand darf **mit Wanderung und Wächter** geändert
werden, die 104 an `Condensator` nur über den Cleanup-Bildschirm.

| | Aufgabe | Warum an dieser Stelle |
|---|---|---|
| 1 | TASK-072 | nachmessen, ob D-686 sie schon geschlossen hat |
| 2 | TASK-076 | klein, abgeschlossen, berührt nichts Folgendes |
| 3 | TASK-075 | öffnet die Tafel für freie Namen — Voraussetzung für 078 und 068 |
| 4 | TASK-078 | die Tafel nach D-687 bis D-689 |
| 5 | TASK-069 | klein, Datensatzblock |
| 6 | TASK-071 | klein, Anordnung |
| 7 | TASK-077 | vierte Quelle im Cleanup, Zusage in `simple-type-check`, Wanderung der vier Sätze |
| 8 | TASK-066 | Umschalter mit D-690 |
| 9 | TASK-068 | `choices`, braucht 075 |
| 10 | TASK-070 | Messung gegen das Beschlossene, dann die Lücke bauen |

*Jede Aufgabe endet mit beiden Läufen grün und einem eigenen Commit. Was blockiert, wird geparkt und
hier vermerkt, nicht gefragt.*

---

[x] TASK-079  `cleartrash-check` ist wackelig — «its labels went with it» fällt in etwa jedem vierten Lauf — behoben 2026-09-09

**Gemessen, auf sein Wort «ja laufen lassen»:** *zehn Läufe hinter `cleanup-screen-check`, einer rot,
immer dieselbe Zusage mit «1». Der Verdacht aus dem Eintrag — das Aufräumen der Beschriftungen beim
Herunterfahren — war es nicht.* **Die Ursache war die Zählabfrage des Wächters selbst:** *sie nahm
die Nummer des Knotens und die der Kante in **eine** Liste und suchte beide in **beiden** Tabellen.
Knoten und Kanten zählen seit D-581 in getrennten Räumen und treffen sich (INF-035); traf die
Kantennummer einen fremden lebenden Knoten, zählte dessen Beschriftung mit. Wie oft, hing an den
Nummern, die die Wiese gerade bekam.* **Behoben:** jede Nummer sucht nur in ihrem Raum. Danach zehn
Läufe: neun grün, einer mit Rückgabewert 3 — die Sperre aus TASK-073, weil währenddessen ein anderer
Lauf lief. *Nicht rot, und genau so gemeint.*

**2026-09-09, beim Bauen gefunden, geparkt.** ⚠️ **Gemessen:** *im vollen Randlauf für TASK-075 rot
mit Rückgabewert 1; allein wiederholt grün. Dreimal die Folge `cleanup-screen-check`, dann
`cleartrash-check`: grün, **rot**, grün — jedes Mal dieselbe Zusage: «its labels went with it — 1»,
eine Beschriftung des geleerten Papierkorbs bleibt stehen.* **Nicht von heute:** die Klammer aus
TASK-073 ändert an Beschriftungen nichts, und der Wächter ist nicht angefasst. **Nicht behoben**, weil
ein wackeliger Wächter keine Aussage über den Bau ist, sondern eine über sich selbst — und «einmal
wiederholen» ihn genau zu dem macht, was TASK-073 ausschliesst: ein Rot, das man übergeht.

⚠️ *Verdacht, nicht Befund: die Beschriftungszählung hängt an `forgetOrphanLabels()`, das viele Läufe
beim Herunterfahren rufen — **nach** dem `ROLLBACK` der Klammer und bei `autocommit = 0`, also in einer
Transaktion, die niemand bestätigt. Ob davon etwas übrig bleibt oder ob der Wächter eine fremde Zeile
mitzählt, ist zu messen, bevor etwas geändert wird.*

---

[ ] TASK-080  Die Konfigurationsseite — Liste steht, Bau wartet auf eine Entscheidung

⚠️ *Hiess bis 2026-09-09 versehentlich **TASK-077**, eine Nummer, die schon vergeben war
(«111 von 113 Benutzersätzen»). Umnummeriert, damit die Baureihenfolge oben eindeutig bleibt:
**Zeile 7 dort meint die Benutzersätze, nicht diese Seite.**

**2026-09-09, sein Auftrag:** *«wir hatten öffters gesagt wenn etwas ins admin menü muss
(settings/installation) allerdings fehlen hier die meisten einträge durchsuche das projekt und
erstelle eine implementierungsliste nur für das installatoins menü, ich würd es lieber in
configutation umbennen.»*

**Die Liste steht in [`konfigurationsseite.md`](konfigurationsseite.md)** — zwölf Zeilen, jede mit
ihrem Beschluss und dem heutigen Zustand, dazu was ausdrücklich **nicht** dorthin gehört und in
welcher Reihenfolge gebaut würde.

⚠️ **Gebaut wird nichts, bevor eine Frage beantwortet ist** (Abschnitt 3): *[D-079](../../NewConcept/90-decision-log.md)
und [D-404](../../NewConcept/90-decision-log.md) legen die installationsweiten Vorgaben auf die
**Installationsidentität** — und die ist mit [D-602](../../NewConcept/90-decision-log.md) aus der
Auflösungskette gefallen. **Gemessen: Id 641, kein Knoten, null Datensätze.** Entweder bekommt sie
ihre Stufe zurück, oder D-079 wird zurückgenommen; der jetzige Zustand ist ein Beschluss ohne
Mechanismus.*

⚠️ *Ein gemessener Fehler liegt schon in der Liste und braucht keine Entscheidung:
**`taxmod_developer` und `taxmod_developer_mode` stehen beide in der Datenbank**, gelesen wird nur
die erste, die zweite steht auf `0` und täuscht.*

---

**Bauanleitung zur Konfigurationsseite** — sein Auftrag, 2026-09-09: *«all das was beschlossen ist als
bauanleitung an die taskliste anhängen.»*

⚠️ **Nur was beschlossen ist steht hier.** *Die Zeilen 1 und 5 der Liste
([`konfigurationsseite.md`](konfigurationsseite.md)) fehlen absichtlich: sie hängen an der offenen
Frage aus deren Abschnitt 3 — bekommt die Installationsidentität ihre Stufe zurück, oder fällt
[D-079](../../NewConcept/90-decision-log.md)? **Zeile 11 fehlt ebenfalls**, weil
[D-368](../../NewConcept/90-decision-log.md) die Zeichen der Bedienelemente als Kode und nicht als
Konfiguration bestimmt hat. **Zeile 12** wartet auf einen eigenen Beschluss (TASK-081).*

| | Schritt | Beschluss | Was zu tun ist |
|---|---|---|---|
| 1 | **Die doppelte Entwickleroption wegräumen** | sein Wort: *«zu 6 ist beides das gleiche»* | `taxmod_developer_mode` löschen. Gelesen wird nur `taxmod_developer`; kein Kode betroffen, `SeedImage` kennt beide Namen schon |
| 2 | **Umbenennen in `Configuration`** | sein Auftrag 2026-09-09 | Menüeintrag und Überschrift ändern, **Seitenschlüssel `taxmod-settings` lassen** — er ist eine Adresse, kein Name. *Ausser er sagt, die Lesezeichen sind ihm gleich* |
| 3 | **Die Schema-Fassung zeigen** | `CD-6` | Zwei Zahlen nebeneinander: was liegt, was der Kode erwartet. Nur Anzeige, kein Aufstieg von hier aus |
| 4 | **Die gesäten Gerüste zeigen** | [D-119](../../NewConcept/90-decision-log.md) | Vier Zeilen aus `taxmod_base_scaffold`, `taxmod_composition_scaffold`, `taxmod_rendering_scaffold`, `taxmod_unit_scaffold` — welches lief, in welcher Fassung |
| 5 | **Die Rahmen-Ids zeigen** | [D-510](../../NewConcept/90-decision-log.md) | 21 Optionen als Liste: Wurzel, Papierkorb, die sechs Äste, die Rollen. Lesbar, nicht änderbar — `node-binding-check` bleibt der Wächter |
| 6 | **Die Icon-Liste kuratieren** | [D-251](../../NewConcept/90-decision-log.md), sein Auftrag 2026-09-09 | Je Zeichen ein Haken. Abgehakt heisst *steht im Wähler*; die 39 Schlüssel aus `NodesScreen::ICONS` sind der Vorrat, und der Kode nennt diesen Schritt selbst als den nächsten |
| 7 | **Backup-Pflicht und Update-Log** | [D-475](../../NewConcept/90-decision-log.md), [D-476](../../NewConcept/90-decision-log.md) | **Ein Stück**, weil D-476 den Rückbau aus dem Backup holt und nicht aus einem Rückwärtslauf. *Gilt für ein Release, nicht für die jetzige Arbeit — sein eigener Zuschnitt* |

⚠️ **Reihenfolge:** *1 bis 5 setzen nichts voraus und sind vier Anzeigen und ein Wegräumen — kein
neuer Mechanismus. **6 hängt an nichts** und kann jederzeit dazwischen. **7 zuletzt**, weil es das
einzige Stück mit einem eigenen Mechanismus ist.*

⚠️ **Jeder Schritt endet mit beiden Läufen grün und einem eigenen Commit** (`PR-9`). *Und jeder
Schritt, der eine Anzeige baut, bekommt seinen Wächter — eine Seite, die eine Option zeigt, ist
genau die Sorte, die still falsch wird, wenn die Option umzieht.*

---

[x] TASK-081  Der Entwicklermodus wird einzeln schaltbar — gebaut 2026-09-09 ([D-705](../../NewConcept/90-decision-log.md)), drei Haken statt vier

**Drei Haken unter einem Modus**, sichtbar nur, während er an ist, Vorgabe alle an: der
Schreibzähler am Knotennamen, der Einstellungssatz im Datensatzblock, und `show the root` im Baum.
Bewacht in `page-blocks-check` an dem, **was verschwindet** — eine Zusage, die nur prüft, dass etwas
dasteht, sieht einen Haken nicht, der nichts tut.

**2026-09-09, sein Auftrag:** *«Welche einstellungen gibt es aktuell für den developer mode? wir
sollten diese einzeln unter schaltbar machen. wenn developer mode aktiv ist»*

**Gemessen: ein Haken, zwei Anzeigen, eine zugesagte dritte.** *Der Schreibzähler am Knotennamen
(`TreeNodeRenderer`), die Renderer-Diagnose unter einem Datensatz (`RecordRenderer`), und der
Einstellungssatz im Datensatzblock aus `TASK-069`, der noch nicht gebaut ist.*

**Dazu, sein Nachtrag am selben Tag:** *«das show root node aus der baumansicht sollte auch in den
developer mode wandern.»*

⚠️ **Gemessen: `show the root` ist heute ein Ansichtsschalter neben `show hidden`**, über die
Adresse `taxmod_root=1`, ohne jede Bedingung sichtbar. **Wandern heisst hier nicht umziehen:** der
Schalter bleibt, wo er ist — er ist ein Ansichtsschalter und kein gespeicherter Vorzug, sein Wort
dazu steht im Kode: *«wenn ich dem Wurzelknoten Felder geben möchte, muss ich ihn **kurzzeitig**
sehen können»* ([D-273](../../NewConcept/90-decision-log.md)). **Er wird nur noch angeboten, wenn
der Entwicklermodus an ist** — also ein vierter Haken in derselben Liste, und die Zeile im Kode, die
ihn ausdrücklich neben `showsHidden()` stellt, bleibt richtig.

⚠️ *Offen und nicht von mir zu entscheiden: **`show hidden` steht direkt daneben und ist dieselbe
Sorte.** Er hat nur die Wurzel genannt. Wenn beide gehen sollen, ist es ein Wort und dieselbe Zeile
Kode; wenn nur die Wurzel geht, stehen künftig zwei gleich aussehende Schalter unter verschiedenen
Bedingungen nebeneinander — das ist die Art Unterschied, die man später nicht mehr erklären kann.*

⚠️ **Wartet auf einen Beschluss, und zwar auf einen, der [D-248](../../NewConcept/90-decision-log.md)
verfeinert** (`PR-3`). *D-248 hat den Testmodus im Entwicklermodus aufgehen lassen — «two modes that
overlap are two things to explain and two ways to be in a surprising state» —, und der Kode zitiert
das an Ort und Stelle: «D-248 says there is **one** mode for that rather than a switch per
diagnostic». **Sein Auftrag widerruft das nicht:** die Einzelhaken wirken nur **innerhalb** des
Modus, also gibt es weiterhin einen einzigen Zustand, in dem man überrascht werden kann. Aber die
Zeile im Kode wird damit falsch und muss mit dem Beschluss fallen — das ist eine sichtbare
Konzeptänderung, keine nebenbei.*

⚠️ **Und ein Ort ist zu wählen:** *drei Haken sind drei Tatsachen über die Installation — also
dieselbe Frage wie in TASK-080s Abschnitt 3. Solange die offen ist, wären es drei weitere
WordPress-Optionen.*

**Nach `TASK-069`**, nicht davor, sonst wird zweimal an derselben Stelle gebaut.

---

[x] TASK-082  Verschieben unter `Primitives` nimmt die Sätze mit — Teile bleiben, Einträge ohne Verwender nach Bestätigung in den Schatten — gebaut 2026-09-10

**Zusage** (`move-under-primitives-check`, 14 Aussagen): *ein Umzug unter `Primitives` oder `Settings`
zählt die Einträge ohne Verwender im Unterbaum; gibt es welche, geschieht nichts, die Seite sagt Zahl
und Ziel, und die Weiterleitung trägt den wartenden Umzug; die Seite des Knotens zeigt den Knopf «ich
bestätige» mit dem Ziel im Formular; mit ihm gehen die Einträge über den Löschweg in den Schatten und
der Knoten zieht um; der Teil mit Verwender bleibt samt Wert, und der Verwender zeigt weiter auf ihn;
ein Umzug innerhalb von `Model` fragt nicht.* Dieselbe Form wie der Artwechsel aus D-699.

**2026-09-09, sein Beschluss** ([D-701](../../NewConcept/90-decision-log.md)): *«Fall 1 bleibt ganz,
Fall 2 wird gezeigt der benutzer muss bestätigen».*

⚠️ *Gemessen: das Verschieben zieht heute nur den Knoten um. Sätze, auf die ein Verwendersatz zeigt
(`value_ref_kind = record`), bleiben richtig, weil der Verweis die Adresse ist (D-541). Sätze ohne
Verweis bleiben liegen, bis `simple-type-check` sie meldet.*

**Zu bauen:** *(1) beim Verschieben eines Knotens unter `Primitives` (oder `Settings`, D-691) zählt
der Rand die `user`-Sätze ohne Verwender; gibt es welche, geschieht das Verschieben nicht sofort —
die Seite nennt die Zahl und verlangt eine Bestätigung, dieselbe Form wie der Artwechsel aus D-699;
(2) mit der Bestätigung gehen sie in den Schatten ({@see DataEntry::removeRecord}), dann wandert der
Knoten; (3) ein Wächter: ein Knoten mit einem Eintrag ohne Verwender und einem Teil mit Verwender
wandert unter `Primitives` — der Teil steht noch, der Eintrag liegt im Schatten, und ohne Bestätigung
ist nichts passiert.* Nicht begonnen.

---

[x] TASK-083  Die vierte Satzart `settings` — Wanderung, Rand, Leser, Wächter — gebaut 2026-09-09/10

**Zusage** (`record-kind-check`, 24 Aussagen; Kernlauf): *jede Einstellungszeile liegt in einem
`settings`-Satz, keiner trägt etwas anderes, der Satz einer Verwendungsstelle ist `settings`, einer je
Adresse; die vier Grenzknoten tragen ihre Grenze als eigenen Wert im `default`-Satz, an den Kanten
`min`/`max` von `Integer` und `Decimal` steht nichts mehr, und die Kette holt die Grenze aus dem
Zielknoten (D-707, letzte Stufe); der Rand legt Einstellungssätze und die Sätze von
Verwendungsstellen als `settings` an; die Auswahl beim Anlegen bietet `settings` nicht an;
`simple-type-check` ohne Ausnahme.*

**Beim Bauen gefunden:** *(1) Fassung 42 lief auf der Datenbank, bevor der Wächter stand — ein
Seitenaufruf der anderen Sitzung mit meinem Stand auf der Platte; der Wächter zeigte danach zwei
Adressen mit je zwei Einstellungssätzen: `chooser-dialog` hatte schon zwei Vorgabesätze aus zwei
Tagen, und die Aufteilung an `Street / H#` hatte einen zweiten angelegt, obwohl einer da war. Fassung
43 führt je Adresse zusammen, der ältere bleibt; die Aufteilung nimmt seither den vorhandenen.
(2) Ein direktes Kind von `Integer` erbt `min` nicht — es ist ein Geschwister von `integer_min`
(D-686); die Wiese des Wächters prüft deshalb am Enkel.*

**2026-09-09, sein Beschluss** ([D-704](../../NewConcept/90-decision-log.md)): *«ich finde es auch
das die vier arten es genauer machen sollten wir so festlegen».*

**Zu bauen, in der Reihenfolge Wächter, Leser, Daten (`PR-9`):** *(1) `RecordType::Settings` mit Wort
und Kopf; (2) der Wächter: ein Einstellungssatz trägt `settings`, kein `default`-Satz trägt eine
Einstellungskante, unter `Primitives` und `Settings` gibt es keinen `user`-Satz — ohne Ausnahme;
(3) die 12 Leser in 4 Dateien, die `RecordType::Default` als Einstellungssatz meinen
(`defaultRecordOf`, `settingValuesOf`, der Entwicklerschalter aus TASK-069, die Kette in
`ModelValues`); (4) der Rand legt Einstellungssätze und die Sätze von Verwendungsstellen als
`settings` an; (5) Fassung 42: 50 Sätze, die nur Einstellungen tragen, werden `settings`, die zwei
Sätze von `Street / H#` auch — **und nach [D-707](../../NewConcept/90-decision-log.md):** die vier
Grenzknoten `integer_min`, `integer_max`, `decimal_min`, `decimal_max` tragen ihre Grenze als eigenen
Wert in einem `default`-Satz, die Kantenwerte `min`/`max` an `Integer` und `Decimal` sowie die Reste
`72` und `0` gehen in den Schatten, und die Auflösung bekommt ihre letzte Stufe, den Zielknoten;
(6) die Satzart-Auswahl beim Anlegen (D-651) bietet
`settings` nicht an — ein Einstellungssatz entsteht beim ersten Schreiben (D-609), nie von Hand.*
Nicht begonnen. Löst den Umbau aus TASK-077 (D-702) mit ab.

---

[x] TASK-084  Ein Lauf durch die Einstellungen — die elf Wächter an der Renderer-Wahl werden einer — gebaut 2026-09-10

**2026-09-09, sein Beschluss** ([D-706](../../NewConcept/90-decision-log.md)): *«1 ja zusammen».*
Grundlage: [`waechter-ueberlappung.md`](waechter-ueberlappung.md) — elf Wächter, 489 Zusagen an
derselben Stelle.

**Zu bauen, in dieser Reihenfolge:** *(1) der neue Lauf `einstellungen-check`: ein Weg, wie ein Mensch
ihn geht — eine Einstellungskante erklären, einen Renderer wählen, das Kind erbt, die Zeile ist
gesperrt, überschreiben, ein unzulässiger geerbter fällt auf den Typ-Standard, die Art einer Kante
umstellen, zurücksetzen, und nach jedem Schritt: steht, was stehen soll, und liest die Seite, was
gespeichert ist? Eigene Wiese, Präfix `__es`; (2) grün; (3) dann je Wächter der elf ein eigener
Commit: die Liste seiner Zusagen, je Zusage «steht in `einstellungen-check`, Abschnitt n» oder «entfällt,
weil …»; erst dann fällt die Datei; (4) `tests/README.md` und `waechter-bestand.md` fortschreiben.*

**2026-09-10, Schritte 1 und 2:** *`einstellungen-check` steht und ist grün — 242 Zusagen in fünfzehn
Abschnitten (0 Gerüst und Bestand, 1 die Wiese, 2 erklären, 3 wählen, 4 erben und sperren, 5 Konflikt
und Wurzel, 6 die Tafel der Feldzeile, 7 Werte und Steuerelemente, 8 Konverter, 9 Vorschau, 10 Kantenart,
11 Datensätze, 12 die Seite, 13 Umbenennen, 14 Fassung 38). Eine eigene Wiese `__es`; nichts hängt mehr
an seinen Knoten (`Kontact`, `Passiv`, `yotta`, `form`), und nichts schreibt mehr mit rohem SQL an
lebende Sätze. Schritt 3 folgt je Wächter mit eigenem Commit; die Liste steht in
[`waechter-bestand.md`](waechter-bestand.md) unter «Vollzug am 2026-09-10».*

**Schritte 3 und 4, am selben Tag:** *elf Commits, je einer je Wächter, in dieser Reihenfolge:
`rendering-scaffold`, `setting-branch-relation`, `converter`, `field-kind`, `setting-lock`, `preview`,
`setting-relation`, `setting-write`, `page-blocks`, `renderer-choice-mask`, `package7`. Je Zusage steht
in `waechter-bestand.md`, wo sie im neuen Lauf steht oder warum sie entfällt. `tests/README.md` trägt die
neue Zeile und nicht mehr die alten; Kode-Stellen, die einen der elf als **heutige** Wache nannten, nennen
jetzt `einstellungen-check` — die Erzählungen («hat es gemeldet») bleiben, sie sind Geschichte.*

⚠️ **Zwei Funde beim Lesen der elf, beide in der Liste benannt:** *`setting-write` Nr. 15 («der alte Satz
ist vergessen») mass eine Satztabelle gegen eine Knotennummer und war seit D-684 immer grün; `preview`
Nr. 19/20 suchte ein Steuerelement, das seit D-520 nicht mehr gezeichnet wird, und war trivial grün.
**Zwei Zusagen, die nie hätten rot werden können** — genau die Sorte, die er meinte.*

---

[x] TASK-085  Die Renderer-Diagnose wird von niemandem gefüllt — gebaut 2026-09-10 ([D-711](../../NewConcept/90-decision-log.md))

**2026-09-10, sein Wort auf die offene Frage: *«je zelle».*** *Der Kern (`Rendering::recordsAsTable()`)
gibt dem Rand nach dem Zeichnen je Satz seine gezeichneten Felder; der Rand (`NodesScreen::drawnByPerCell()`,
vorher der ungerufene `drawnBy()`) macht daraus eine Zeile je Satz mit einem Eintrag je Feld — Typ und
Renderer, «no renderer» rot, «hidden by a setting» wo eine Einstellung die Zelle schliesst. Nur im
Entwicklermodus, neben dem Formular (D-705). Zusage in `einstellungen-check`, Abschnitt 11.*

**2026-09-09, beim Bauen von TASK-081 gefunden.** ⚠️ **Gemessen:** *`RecordRenderer` zeichnet unter
einem Datensatz eine Diagnose, sobald der Entwicklermodus gilt — «which renderer drew what».
`Rendering::recordsAsTable()` und ihre Schwester nehmen dafür einen Text `$diagnostic` entgegen und
setzen den Abschnitt nur, wenn er nicht leer ist. **Kein einziger Aufrufer im ganzen Zusatzstück
übergibt ihn.** Die Anzeige ist also gebaut und dunkel.*

⚠️ **Deshalb bekam sie in [D-705](../../NewConcept/90-decision-log.md) keinen eigenen Haken:** *ein
Schalter für etwas, das nie erscheint, ist Möbel ([D-429](../../NewConcept/90-decision-log.md)).*

⚠️ *Was zu tun ist, ist klein: **der Weg steht schon** — es fehlt die Stelle, die beim Zeichnen
mitschreibt, welcher Renderer welche Zelle gemacht hat, und sie durchreicht.
`RenderResult::usedRelations` zeigt, dass der Kern so etwas schon einmal zurückgibt. **Zu klären ist
vorher, ob die Diagnose je Datensatz oder je Zelle gemeint war** — der Abschnitt sitzt am Satz, der
Befund entsteht an der Zelle.*

---

**Baureihenfolge II, 2026-09-09 abends** — sein Auftrag: *«erstelle schon mal eine build reihenfolge
und wenn ich das go gib arbeite sie komplett ab».* Alles aus den Beschlüssen des Abends, nichts
begonnen; es startet mit seinem Go.

| | Aufgabe | Beschlüsse | Warum an dieser Stelle |
|---|---|---|---|
| 1 | TASK-083 | D-704, D-702, D-707 | **Der Boden für alles Weitere:** die vierte Satzart `settings`, die Ausnahme fällt, die Auflösung bekommt die letzte Stufe (Zielknoten), Fassung 42 trägt die vier Grenzen und räumt die Reste. Erst Wächter, dann Leser, dann Daten. |
| 2 | TASK-066 Umbau | D-699 | Die Wanderung fällt, der Bestätigungsschritt kommt: warnen, bestätigen, Sätze in den Schatten, Einstellung leer. `field-kind-check` wird umgeschrieben. |
| 3 | TASK-082 | D-701 | **Derselbe Bestätigungsschritt** wie in 2, an der zweiten Stelle: Verschieben unter `Primitives` — Teile bleiben, Einträge ohne Verwender nach Bestätigung in den Schatten. Neuer Wächter. |
| 4 | TASK-068 | D-697 | `allowed` an `Prefixes`, die Liste im `settings`-Satz zur geerbten Kante (braucht 1), die Hakenliste, das Angebot aus der gewählten Einheit, die Verengung an der Stelle, der Konflikt nach D-680. Neuer Wächter. |
| 5 | TASK-087 | D-698 | Anordnen geerbter Felder am Kind, an derselben Adresse wie 4 — eine Angabe `position`, die zwei Knöpfe. Kommt direkt nach 4, weil es dieselbe Adresse und denselben Satz benutzt. *Gebaut 2026-09-10.* |
| 6 | TASK-084 | D-706 | **Zuletzt**, damit der neue Lauf durch die Einstellungen die fertige Form misst und nicht zweimal umgeschrieben wird: ein Lauf, dann fallen die elf einzeln. *Gebaut 2026-09-10 — zwölf Commits.* |

*Jede Aufgabe endet mit beiden Läufen grün und einem eigenen Commit. Was blockiert, wird geparkt und
hier vermerkt; gefragt wird vorher, nicht mittendrin — offen ist heute nichts.*

---

[ ] TASK-086  Die Konfigurationsseite speichert wie WordPress — mit dem Knopf, nicht direkt

⚠️ *Hiess bei ihrer Aufnahme **TASK-085**, eine Nummer, die eine Stunde vorher schon vergeben war
(«Die Renderer-Diagnose wird von niemandem gefüllt»). **Zwei Läufe am selben Nachmittag, dieselbe
Nummer** — umnummeriert auf die spätere, weil die frühere zuerst eingecheckt war. Ihr Commit nennt
noch die alte Nummer; das Verzeichnis hier ist die Quelle.*

**2026-09-09, sein Wunsch und sein Vorbehalt:** *«die admin einstellungs seite kein save braucht es soll
direkt gespeichert werden. oder hat wp einen anderen standard würde mich da an wp orientieren».*

⚠️ **Der Standard von WordPress ist der Knopf.** *Einstellungsseiten im Verwaltungsbereich schicken
ein Formular an `options.php` und haben «Änderungen speichern» — die Options-API kennt nichts anderes.
Direkt gespeichert wird in WordPress an zwei Stellen, und beide sind keine Einstellungsseiten: der
Customizer (Vorschau, dann «Veröffentlichen») und der Block-Editor (Autosave eines Entwurfs, den man
danach ausdrücklich veröffentlicht).* **Mit seinem Vorbehalt «würde mich da an wp orientieren» bleibt
der Knopf**, und die Seite sieht aus wie jede andere Einstellungsseite in WordPress.

⚠️ *Gehört zur Konfigurationsseite und damit zu TASK-080, die bei der anderen Sitzung liegt — hier
nur festgehalten, damit die Frage nicht ein zweites Mal gestellt wird. Nichts zu bauen, solange die
Seite den Knopf hat.*

---

[x] TASK-087  Ein Kind ordnet geerbte Felder an — `position` — gebaut 2026-09-10 ([D-698](../../NewConcept/90-decision-log.md))

**Sein Wort:** *«würde sagen kind darf felder neu anordnen»* — und sein Go zur Baureihenfolge II, in der
Punkt 5 die Form nannte: *«eine Angabe `position`, die zwei Knöpfe»*. Damit ist die `INFERRED`-Form aus
D-698 die gebaute.

**Zusage** (`field-order-check`, 23 Aussagen): *Fassung 45 erklärt an der Wurzel die Einstellung
`position` (`0..1` auf `Integer`), wie `read_only` und `renderer`; an einem Kind hat jede Feldzeile die
zwei Knöpfe, die Enden ausgegraut (D-429); ein Schritt schreibt die Positionen der ganzen Liste in die
Sätze `Kind × Kante` (`settings`, D-667, D-704); die Kante des Besitzers und ihr `sort_order` bleiben;
der Enkel erbt die Anordnung, sein eigenes Feld kommt danach, und er darf wieder umstellen, ohne das
Kind zu ändern (näher schlägt ferner, D-602); gilt an einem Knoten eine Anordnung, geht auch die eigene
Zeile über sie; beim Besitzer ohne Anordnung bleibt D-435.*

**Beim Bauen entschieden, im Kode und hier benannt:** *(1) **die ganze Liste wird geschrieben**, nicht
nur die zwei Getauschten — eine Position ist nur gegen die anderen eine Aussage, und mit der ganzen
Liste sagt der Knoten, was er sieht; (2) **dünn bleibt es trotzdem**: solange niemand verschoben hat,
steht kein Satz, und Zeilen ohne Position kommen nach den angeordneten in der Reihenfolge der Besitzer;
(3) **zwei Ordnungen stehen nie nebeneinander** — gilt an einem Knoten eine Anordnung, geht auch das
Verschieben einer eigenen Zeile über sie, sonst bliebe `sort_order` neben `position` und die Knöpfe
sagten einmal dies und einmal das; (4) die Einstellungskanten der Wurzel sind keine Feldzeilen und
werden nicht angeordnet; (5) der Sammelleser `ofNodes` las nur Sätze ohne Kante — die Adresse
`Knoten × Kante` bekam einen Leser für viele Adressen auf einmal (`CD-7`), zwei Abfragen je Kette;
(6) das Schreiben geht nicht über den gewöhnlichen Wertpfad, weil der das Ziel `Integer` unter
`Compositions` als Teil liest — derselbe Weg wie bei den anderen Einstellungen einer Stelle.*

*Nicht gebaut: `position` erscheint als Zahl im Einstellungsbereich jeder Feldzeile, weil sie an der
Wurzel erklärt ist wie `read_only` — dort tippen statt klicken geht, ist aber nicht bewacht.*

---

[x] TASK-088  Ein Verweis am geerbten Renderer liess sich nicht setzen — «Field … does not belong to Prefixes» — behoben 2026-09-10

**Sein Fund, mit Bild:** *«label role geändert symbol eingetragen fehler siehe bild».* An `Prefixes`, das
seinen Renderer `chooser-dialog` erbt, `label_role` auf `symbol` gestellt.

⚠️ **Gemessen:** *`putSettingAt()` fragte für einen Verweis «braucht das Ziel einen eigenen Satz» über
die **Kette des Knotens** — und `label_role` gehört dem Renderer, nicht `Prefixes`. `with_label` ist ein
Schalter und ging diesen Zweig nie; deshalb war die geerbte Renderer-Zeile im Wächter grün und der
Verweis daneben rot.* **Behoben:** die Frage geht an die Kante selbst (D-667). Zusage in
`einstellungen-check`, Abschnitt 4: ein Verweis am geerbten Renderer steht danach am Knoten selbst,
der Vater bleibt leer.

⚠️ **Seine zweite Frage — «müsste man es hier genauso mit reference field machen … grosse Lücke …
der Konzeptänderung geschuldet?» — ist ein Eingang, keine Aufgabe:**
[INF-040](../../neues-konzept-eingang.md). *Zwei Mechanismen für dieselbe Sache, und der zweite ist ein
Rest aus der Zeit vor D-529. Was zu entscheiden wäre, steht dort.*
