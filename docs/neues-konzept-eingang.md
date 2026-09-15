# Eingang für das neue Konzept

**Angelegt 2026-09-01, unmittelbar nach [D-568](NewConcept/90-decision-log.md).**

Mit D-568 gilt `docs/NewConcept/` als veraltet und wird **nicht mehr erweitert** — auch nicht um
offene Fragen. Der Aufbau des neuen Konzepts ist noch nicht festgelegt. Bis dahin sammelt dieses
Blatt, was sonst verloren ginge.

**Das ist ein Eingangskanal, keine Entscheidung** (Arbeitsmodell v1.2 §7.1). Nichts hier ist
`ACTIVE`. Ein Eintrag wird später geprüft und eingeordnet.

---

## INF-001 · Der geteilte Id-Raum als mögliche Wurzel

**Typ:** `HYPOTHESE` · **Status:** `INFERRED` — **vom Eigentümer**, ausdrücklich als Vermutung
formuliert

**Seine Worte, 2026-09-01:**

> *«Ich glaube ehrlich gesagt, dass viele Probleme daraus entstanden sind, dass wir einen gemeinsamen
> Id-Bereich für Knoten und Kanten haben und daraus der Wunsch eines Pfades entstanden ist. Aber das
> ändern wir im neuen Konzept.»*

**Was daran gemessen ist:**

| | |
|---|---|
| Der geteilte Id-Raum **existiert** | [D-339](NewConcept/90-decision-log.md): *«identities(id), append-only … nodes and relations take the number and use it as their own primary key»* |
| Er wird **massiv** verbraucht | 64 703 vergebene Identitäten für **680** lebende Zeilen — **1,1 %**; 53 561 gehören zu gar keiner Tabelle |
| Alle Fremdschlüssel zeigen auf `identities.id` | genau das trägt den Schattentabellen-Entwurf: mehrere Zeilen mit derselben Id brechen keinen davon |

**Was der zweiten Hälfte der Hypothese widerspricht — und es ist eine echte Einschränkung:**

[D-082](NewConcept/90-decision-log.md) nennt als Grund für `nodes.path` **nicht** den Id-Raum,
sondern:

> *«`path` is the **materialised ancestor path — derived and rebuildable**»*

**Der Pfad entstand also aus dem Wunsch nach einer vorgerechneten Vorfahrensuche, nicht aus dem
geteilten Id-Raum.** ⚠️ *Und der Wunsch war meiner, nie seiner — sein Wort, 2026-09-11: «der pfad war
nie mein wunsch, ganz im gegenteil, ist im hintergrund entstanden und war nur sehr schwer wieder zu
entfernen».* Und D-082 steht bis heute auf `agreed (proposal)` — **die Abkürzung wurde nie
bestätigt**, und `50-wordpress-persistence.md` zitiert für sie ausserdem die falsche Nummer (`D-014`).

⚠️ *Beides kann nebeneinander wahr sein: der Id-Raum ist ein eigenes Thema, der Pfad ein anderes. Ich
löse das nicht auf — es gehört in das neue Konzept, und er hat es dort ausdrücklich hingelegt.*

**Für das neue Konzept zu entscheiden:**

1. Teilen sich Knoten und Kanten weiterhin einen Id-Raum?
2. Wenn ja: wann wird eine Identität vergeben — beim Anfordern oder beim Schreiben der Zeile?
3. Braucht es eine vorgerechnete Vorfahrensuche überhaupt, und wenn ja, in welcher Form?

---

## INF-002 · Das Tabellen-Review liegt vor

**Typ:** `MATERIAL` · **Status:** `FACT` — an der laufenden Datenbank gemessen

[`docs/review-tabellen.md`](review-tabellen.md), 2026-09-01. **Eingangsmaterial für das neue Konzept,
kein Flickwerk am alten.** Die Kernpunkte:

- **`path` steht in vier Tabellen.** `labels.path` und `settings.path` sind **leer** (0 von 47, 0 von 3). `record_values.path` ist der **Spiegel** von `edge_id` (je 12 verschiedene Werte bei 183 Zeilen). `nodes.path` trägt punktseparierte Id-Ketten und ist in **127 von 127** Fällen aus `relations` herleitbar, ohne eine einzige Abweichung.
- **18 Knoten haben mehr als eine eingehende Kante** — ein einzelner Pfad je Knoten kann davon nur einen Weg nennen und ist dort **verlustbehaftet**.
- **Zwei beschlossene Mechanismen kommen in den Daten nicht vor:** [D-530](NewConcept/90-decision-log.md)s `position` ist in **0 von 183** Zeilen gefüllt; [D-536](NewConcept/90-decision-log.md)s Journalversion in **25 von 23 229**.
- **Neun Spalten** tragen keine oder nur eine einzige Ausprägung; drei davon sind echte Kandidaten (`nodes.kind`, `labels.number`, die `records_history`-Spalten).

---

## INF-003 · Was das neue Konzept vor seinem Inhalt braucht

**Typ:** `QUESTION` · **Status:** `OPEN`

D-568 sagt: **wie das neue Konzept aufgebaut wird, ist vor seinem Inhalt festzulegen.** Offen ist
damit:

1. **Welche Pakete**, und wo verlaufen ihre Grenzen? (Arbeitsmodell v1.2 §2)
2. **Was verhindert, dass es wieder 2688 KB werden?** [D-566](NewConcept/90-decision-log.md) gibt den Maßstab — *prüfbar durch einen Menschen* —, aber keine Grenze. Ein messbarer Deckel wäre ein Wächter; ohne einen ist es eine Absicht.
3. **Was wird aus den 307 Regeln** des alten Bestandes — je Paket verteilt, oder als Historie geschlossen?
4. **Was wird aus den 562 Entscheidungen?** Nach v1.2 §6.1 sind sie `LEGACY`, bis geprüft. Bisher sind acht geklärt.

---

## INF-004 · Ein Feld, das den Knotennamen benutzt, folgt ihm — statt ihn zu kopieren

**Typ:** `DECISION` · **Status:** `CONFIRMED`, aber **zurückgestellt** — vom Eigentümer, 2026-09-04:
*«vergiss bitte den Vorschlag mit der Id, das werde ich später angehen, vielleicht könnte man das
auf den Parkplatz schieben für spätere Releases»*. Die Entscheidung steht; der Zeitpunkt ist offen.
Ein erster Bauversuch (Schema-Spalte, `Relation`-Modell, Wanderung) wurde noch am selben Tag
zurückgenommen, bevor er fertig war — nichts davon ist im Code.

**Sein Wort:** *«wenn use the node name gewählt ist sollte eigentlich die node_labels_id an das
Feld gehängt werden, nur wenn später ein Name eingegeben wird soll ein eigenes Label gewählt
werden»* — und auf Rückfrage: Basisname des Knotens (nicht eine Rolle/Locale-Label); bestehende,
bereits kopierte Feldnamen werden umgestellt statt nur künftige; der Eintrag hier steht auf sein
Geheiss.

**Der gemessene Ist-Zustand, vor der Änderung:** [`ModelEditor::addField()`](../src/Core/Service/ModelEditor.php)
nimmt einen reinen `string $name`. Beim Anlegen mit angehaktem "Use the node name" kopiert
[`NodesScreen`](../src/WordPress/Admin/NodesScreen.php) einmalig den **damaligen** Basisnamen des
Zielknotens in `relations.name`. Wird der Knoten später umbenannt, läuft die Kopie auseinander —
niemand aktualisiert sie nach.

**Die Entscheidung:** Ein Feld, das "use node name" trägt, zeigt den **aktuellen** Basisnamen
seines Zielknotens, nicht eine eingefrorene Kopie. Erst ein eigener, ausdrücklich eingegebener
Name löst die Kopplung — dann bekommt die Kante ihren eigenen Text, wie heute.

**Was das berührt (Datenmodell, deshalb hier und nicht stillschweigend gebaut, Arbeitsmodell v1.2
§4.1):** eine neue Spalte auf `relations` (Schema-Version, `dbDelta`), das `Relation`-Modell, die
Zeile in [`FieldRowRenderer`](../src/Core/Renderer/FieldRowRenderer.php), und eine rückwirkende
Wanderung für bestehende Kanten, deren Name heute zufällig mit dem Namen ihres Zielknotens
übereinstimmt (das einzige Erkennungsmerkmal, das es rückwirkend gibt — echte Verwechslungen mit
einem absichtlich gleichlautenden eigenen Namen sind eine bekannte, hingenommene Unschärfe dieser
Wanderung).

**Nicht umgesetzt — Parkplatz für ein späteres Release**, auf sein Wort.

---

## INF-005 · Beschriftungsrollen als Zeilen statt als Spalten — auf dem Parkplatz

**Typ:** `IDEA` · **Status:** `PARKED` — vom Eigentümer, 2026-09-04

**Sein Wort:** *«zu 2: ich glaube, Rollen sind was Programmiertechnisches. Aber wenn ich überlege,
dass ich die im Renderer auswählen kann, brauche ich evtl. dann und wann neue, wenn die alten nicht
reichen. Würde das mal auf den Parkplatz für mögliche spätere Entwicklungen schieben und bei vier
Spalten bleiben.»*

**Es bleibt bei vier Spalten** in `label_texts`: `form`, `table`, `select`, `help`
([D-580](NewConcept/90-decision-log.md), [D-598](NewConcept/90-decision-log.md)).

**Was den Parkplatz begründet, ist sein eigener Einwand gegen sich selbst.** *Eine Rolle sieht nach
Programmierung aus — bis man merkt, dass **er** sie im Renderer auswählt. Damit ist sie eine
Angabe des Modellierers, und Angaben des Modellierers wachsen. **Vier Spalten heisst: eine fünfte
Rolle ist eine Schemaänderung**; Zeilen mit `role_id` hiesse: eine fünfte Rolle ist eine neue Zeile.*

⚠️ *Woran man merken wird, dass der Parkplatz zu verlassen ist: **wenn zum ersten Mal eine Rolle
fehlt.** Solange die vier reichen, wäre die Zeilenform eine Tabelle mehr für einen Fall, den es
nicht gibt.*

---

## INF-006 · Labels tragen keine Version — und die Chronik merkt es

**Typ:** `QUESTION` · **Status:** `OPEN` — beim Bau von TASK-056 gefunden, 2026-09-05

⚠️ **Gemessen am Schema, nicht vermutet:** *`nodes`, `relations`, `records` und `record_values`
haben je eine Spalte `version`; **`labels` hat keine.** Damit ist eine Beschriftungsänderung die
einzige Modelländerung, die im Änderungsbuch **ohne** Version steht — seit
[D-634](NewConcept/90-decision-log.md) nicht mehr aus Versehen, sondern sichtbar als `null`.*

**Die Frage, und sie ist nicht hier zu beantworten:** *werden Labels versioniert wie alles andere —
mit Schattentabelle, `(id, version)` und Rückgängig ([D-536](NewConcept/90-decision-log.md),
[D-537](NewConcept/90-decision-log.md)) — oder sind sie ausdrücklich etwas anderes?*

⚠️ **Was für «versionieren» spricht:** *Eine Umbenennung ist für den Benutzer dasselbe Ereignis,
egal ob sie den Knotennamen oder seinen Text in einer Sprache trifft. Heute ist das eine
zurückholbar und das andere nicht.*

⚠️ **Was dagegen spricht und ehrlich benannt gehört:** *Labels sind die einzige Tabelle, in die ein
Seitenspeichern **viele** Zeilen schreibt — vier Rollen mal Sprachen. Eine Schattentabelle wächst
dort schneller als überall sonst.*

*Solange es unentschieden ist, steht `null` in der Spalte und
[`version-check.php`](../scripts/dev/version-check.php) kennt die drei Verben namentlich. **Wird
entschieden, fällt die Ausnahme aus dem Wächter** — das ist der Ort, an dem es auffällt.*

---

## INF-038 · Der Ast bestimmt weiter die Ablage — und D-622 sagt, dass er es nicht mehr sollte

**Typ:** `QUESTION` · **Status:** `OPEN` — beim Bau von TASK-032 gefunden, 2026-09-05

⚠️ **[D-622](NewConcept/90-decision-log.md), sein Wort:** *«Datensatz wird immer wie Aggregation
gespeichert (ausser simple Typen), aber beim Löschen wird auf das Flag geschaut.»* Also **eine
Angabe mit zwei Fällen** — einfacher Typ oder nicht — **an der Kante**.

⚠️ **Gebaut ist das nicht, und zwar mit Absicht.** *Die Frage stellt heute
`DataEntry::ownsItsRecord()`, und sie stellt sie über `Branch::storage()`: **`Compositions` bekommt
einen eigenen Satz, `Model` und `Constants` nicht.** Hängt man das auf «alles ausser einfachen
Typen» um, bekommen Ziele in `Model` und `Constants` plötzlich eigene Sätze — **eine Änderung an den
Daten, nicht am Code.** Wie viele Zeilen das trifft, ist nicht gemessen, und wer sie will, hat es
nicht gesagt.*

⚠️ **[D-621](NewConcept/90-decision-log.md) hat genau diese beiden schon einmal geparkt:** *«ob die
beiden anderen denselben Weg gehen, hat er nicht gesagt und wird nicht geraten (`PR-4`)» — gemessen
an 9 Stellen in vier Dateien. **D-622 beantwortet die Frage im Grundsatz, aber nicht den Umzug.***

⚠️ *Dazu gehört die dritte Ast-Auskunft, die D-621 ausdrücklich streicht:
`Branch::relationKind()` bestimmt weiter die Art aus dem Ort des Ziels. **Sie fällt erst, wenn der
Benutzer die Art an der Kante wählen kann** — und das ist eine Maske, keine Tabellenarbeit.*

---

## INF-039 · Sagt eine gesammelte Hilfe, zu welchem Feld sie gehört?

**Typ:** `QUESTION` · **Status:** `OPEN` — beim Bau von [D-662](NewConcept/90-decision-log.md)
gefunden, 2026-09-06

⚠️ **Sein Wort in [D-662](NewConcept/90-decision-log.md):** *«Bei compact horizontal würde ich die
Texte sammeln und in ein Fragezeichen am Ende kombinieren.»* **Gebaut ist genau das:** die Sätze der
Felder einer waagerechten `compact`-Zeile stehen hintereinander in **einem** Fragezeichen am Ende,
getrennt durch ein Trennzeichen.

⚠️ **Offen ist, was dabei nicht dasteht: der Name des Feldes.** *Bei einem Feld ist das gleichgültig
— das Zeichen steht neben ihm. **Bei dreien ist es das nicht:** drei Sätze hintereinander sagen
nicht, welcher zu welchem Feld gehört, und die Zeile zeigt die Namen zwar an, aber der Leser muss
sie selbst zuordnen. **Der naheliegende Ausweg — «Name: Satz» je Eintrag — steht so nicht in D-662**,
und ihn hier zu wählen, hiesse eine Entscheidung im Vorbeigehen treffen (`PR-4`).*

⚠️ *Heute betrifft es niemanden: gemessen tragen **4** Beschriftungen im ganzen Modell einen
`help`-Text, und keine zwei davon stehen in derselben `compact`-Zeile. **Die Frage wird an dem Tag
akut, an dem er anfängt, Hilfen zu schreiben** — und dann ist sie eine Zeile Code.*

---

## INF-040 · Eine Einstellungskante auf einen Knoten ist ein Verweis — und geht heute einen eigenen Weg

**Typ:** `QUESTION` · **Status:** `OPEN` — von ihm gefunden an `Prefixes`, 2026-09-10

⚠️ **Sein Wort:** *«label role geändert symbol eingetragen fehler siehe bild. zusätzlich eigentlich
müsste man es hier genauso mit reference field machen oder ich glaube hier haben wir eine große lücke
und das ist der konzeptänderung geschuldet?»*

⚠️ **Der Fehler ist behoben, und er ist ein Symptom.** *`label_role` ist die Kante
`Renderer --label_role--> Label roles`, ihr Wert ist ein Verweis auf einen Rollenknoten. Der Schreiber
fragte für diesen Verweis die **Kette des Knotens** («gehört die Kante zu Prefixes?») statt die Kante
selbst — und die Kette kennt sie nicht, weil sie dem Renderer gehört. `with_label`, ein Schalter, ging
diesen Zweig nie. Gebaut: die Frage geht an die Kante ([D-667](NewConcept/90-decision-log.md)).*

⚠️ **Die Lücke, die er meint, gemessen:** *es gibt **zwei Mechanismen für dieselbe Sache**. Ein
**Feld**, das auf einen Knoten zeigt (`einheit → Base units`, `prefix → Prefixes`), wird über
`fieldsFor()` gezeichnet — Angebot aus den Kindern des Ziels, verengt durch `allowed`
([D-697](NewConcept/90-decision-log.md)), gespeichert als Verweis im Satz. Eine **Einstellungskante**,
die auf einen Knoten zeigt (`label_role → Label roles`, `converter → Converter`, `validator`,
`renderer → Renderer`), wird über `settingsFor()` und `drawChoice()` gezeichnet — ein eigener Wähler,
eigenes Angebot, kein `allowed`, eigener Schreibweg mit dem Fehler von heute. **Seit
[D-529](NewConcept/90-decision-log.md) ist eine Einstellung eine Kante, und seit
[D-621](NewConcept/90-decision-log.md) sagt die Kante, was etwas ist** — dann ist eine
Einstellungskante auf einen Knoten ein Verweisfeld, und der zweite Weg ist ein Rest der Zeit, als
Einstellungen noch Schlüssel in einer Tabelle waren. Insofern: ja, der Konzeptänderung geschuldet.*

⚠️ **Was zu entscheiden wäre, nicht hier (`PR-4`):** *(1) ob eine Einstellungskante mit Knotenziel
**denselben** Zeichner und Schreiber nimmt wie ein Verweisfeld — ein Angebot aus den Kindern des
Ziels, `allowed` auch dort («welche Rollen darf dieser Knoten wählen»), ein Schreibweg über die Kante;
(2) was mit den Sonderfällen wird, die der eigene Weg heute trägt: der Renderer bringt seinen
**Teil** mit ([D-583](NewConcept/90-decision-log.md), die Einstellungen des Renderers unter der
Zeile), der Konverter seine Registratur; (3) ob `renderer` selbst dazugehört oder wegen des Teils
eine eigene Sorte bleibt. **Es ist der grössere Umbau seit D-529**, und er fällt nicht nebenbei.*

---

## INF-041 · Wo eine Einstellung erklärt ist, sagt, wer sie hat — nichts sagt, wer sie braucht

**Typ:** `QUESTION` · **Status:** `OPEN` — von ihm gefunden an `Parts List`, 2026-09-10

⚠️ **Sein Wort:** *«form und table haben garkein label role und converter / validator eigentlich auch
nicht trotzdem werden sie angezeigt»* — *«es gibt keine converter für table und form, feld ist offen
weil falsche converter angezeigt werden, render with label ist ok, label_role ist an form und table,
eigentlich brauchen wir das aktuell soweit ich weiss nur bei der reference — ich glaube wir haben auch
hier eine konzeptlücke».*

⚠️ **Gemessen, wer heute was erklärt:** *`converter` und `label_role` am Behälter `Renderer` — also
erbt sie jeder Renderer, auch `form`, `table`, `compact`. `with_label` am Zwischenknoten
`render with label` (form, compact, table, reference, chooser-inline, chooser-dialog) — das ist
richtig, und er sagt es. `orientation` nur an `compact` und `table` — das Muster, das stimmt.
`validator` an der Wurzel — also an jedem Knoten, und die Auswahl bietet an `Parts List` «range» und
«shape» an, die zu einem zusammengesetzten Knoten nichts sagen.*

⚠️ **Die Lücke, in zwei Hälften:** *(1) **Die Erklärung ist zu weit.** [D-529](NewConcept/90-decision-log.md)
sagt, eine Einstellung ist eine Kante, und [D-621](NewConcept/90-decision-log.md), die Kante sagt,
was etwas ist — aber der Renderer-Ast gruppiert nur nach **einer** Eigenschaft (mit Beschriftung),
nicht nach den anderen: «zeichnet einen Wert» (dann Konverter) und «zeigt auf einen Knoten» (dann
Rolle der Beschriftung). Das Gerüst (`RenderingScaffold`) baut den Ast aus dem Kode, und der Kode
weiss je Renderer, was er kann — der Ast sagt es nicht. (2) **Das Angebot folgt nicht dem Passenden.**
Für `converter` fragt der Kode die Registratur («welche passen zu diesem Typ»), für `validator` nicht,
für `label_role` gibt es nichts zu fragen. Und ein leeres Angebot wird als **leeres Feld** gezeichnet —
«feld ist offen» —, was eine ältere Zusage sogar verlangte («eine Wahl ohne Inhalt ist ein totes
Steuerelement, kein leeres»; heute steht sie in `einstellungen-check`, Abschnitt 7).*

⚠️ **Was zu entscheiden wäre (`PR-4`), als Vorschlag:** *(a) Der Renderer-Ast bekommt seine Gruppen
**aus dem Kode**: unter `render with label` ein Zwischenknoten für die drei, die auf einen Knoten
zeigen (reference, chooser-inline, chooser-dialog) — dort wird `label_role` erklärt; neben ihm ein
Zwischenknoten für die, die einen Wert zeichnen (field, spinner, slider, textarea, …) — dort wird
`converter` erklärt. Die Kanten behalten ihre Nummern, gespeicherte Werte bleiben gültig; eine Fassung
hängt um. (b) Das Angebot jeder Einstellung mit Knotenziel folgt dem, was **an dieser Stelle passt** —
für `converter` und `validator` nach dem Typ, wie die Registraturen es heute schon wissen; das ist
derselbe Gedanke wie [D-697](NewConcept/90-decision-log.md), nur aus dem Kode statt aus einer Liste.
(c) **Ein leeres Angebot zeichnet keine Zeile** — statt eines toten oder offenen Feldes. Das nimmt die
ältere Zusage zurück und braucht deshalb sein Wort. (d) `validator` an der Wurzel oder am Ast der
Datentypen — hängt an (b): folgt das Angebot dem Typ, kann die Kante an der Wurzel bleiben und ist an
`Parts List` einfach leer, also nach (c) nicht da.*

⚠️ *Zusammen mit [INF-040](#inf-040--eine-einstellungskante-auf-einen-knoten-ist-ein-verweis--und-geht-heute-einen-eigenen-weg)
ist das **eine** Baureihe: dort, wie eine Einstellung mit Knotenziel gezeichnet und geschrieben wird;
hier, wo sie erklärt ist und was sie anbietet. Nichts davon ist gebaut.*

## INF-042 · Die Stelle geerbter Felder am Kind — die letzte Einstellungskante wartet auf Modell 2.4

**Stand 2026-09-11, Schritt 7 des [Bauplans](einstellungen-bauplan.md).** Alle Einstellungskanten sind in den Schatten
gewandert — bis auf eine: `position` an der Wurzel. Sie trägt mit ihren `settings`-Sätzen die Stelle geerbter Felder am
Kind ([Modell 2.2](modell-anforderungen.md): «Ein Kind darf geerbte Felder umstellen»). Wo das künftig gespeichert wird,
ist offen ([Modell 2.4](modell-anforderungen.md): «nicht in Einstellungszeilen»).

**Was deshalb steht, bis er entscheidet:** die Kante `position` mit ihren Sätzen; der Wert `setting` in der Kantenart
(nicht mehr wählbar, nicht mehr anlegbar) und der Wert `settings` in der Satzart; `FieldOrder` liest die Stelle von dort.
TASK-093 bleibt offen. **Zu entscheiden:** wo die Stelle eines geerbten Felds am Kind wohnt — eine Spalte an einer
eigenen Tabelle `Knoten × Kante`, oder etwas anderes. Nichts davon ist gebaut.

## INF-043 · Wie heisst der Typ mit gestuften Nummern?

**Stand 2026-09-12.** Der Typ «Version» hat seit [D-738](NewConcept/90-decision-log.md) die Einstellung `levels`
(1 → «1», 2 → «1.1»). Sein Wort: *«ich weiss aber nicht ob man das version nennen sollte kann auch für kapitel oder
andere beschreibungen verwendet werden».* **Zu entscheiden:** der Name des Typs — «Version», «Nummerierung»,
«Gliederung» oder ein anderes Wort. Bis dahin bleibt «Version».

---

## INF-044 · Datum/Zeit als zusammengesetzter Typ mit ausblendbaren Teilen?

**Stand 2026-09-12.** Seit [D-737](NewConcept/90-decision-log.md) hat das Datum Teile zum An- und Ausschalten. Sein Wort:
*«Einstellungen Teile an/aus ich nicht, aber ich überlege, ob wir das nicht als zusammengesetzten Typ machen, und bei
Verwendung können die Felder, die nicht gebraucht werden, als hidden deklariert werden. Müssen wir mal challengen.»*
**Zu entscheiden:** Datum bleibt ein simpler Typ mit Schaltern, oder wird ein zusammengesetzter Typ (Jahr, Monat, Tag,
Zeit als Felder), bei dem die Verwendung Felder versteckt.

## INF-045 · Braucht es «Reference» als Klasse noch, wo es den Feldtyp «Node reference» gibt?

**Stand 2026-09-12.** Sein Wort: *«brauchen wir reference an Kategorie? Für was brauchen wir reference als Kategorie
noch, wir haben ja jetzt den Feldtyp node_reference.»* **Zu entscheiden:** die Klasse «Reference» fällt, oder sie hat
eine Aufgabe, die der Feldtyp nicht hat.

## INF-046 · Wie heisst «Node reference»?

**Stand 2026-09-12.** Sein Wort: *«Sollte node reference nicht lieber label reference heissen. Oder besser: label is
input.»* **Zu entscheiden:** der Name des Typs — «Node reference», «Label reference» oder ein Schalter «label is input».
Bis dahin bleibt «Node reference».

## INF-047 · Telefonnummer: Typ, Validator, Konverter oder Renderer?

**Stand 2026-09-12.** Seine Fragen: *«type? Validator/Converter/Renderer?»* — Landeskennung, Ortsvorwahl, Nummer als
eigene Felder oder in der Form «+49 …»; die Art (Landline, Mobile, Fax …) *«hier würde ich glaube ich eine Konstante
(Knoten) bevorzugen»*; dazu *«eine oder mehrere Extensions mit einer Beschreibung, oder wäre das eine eigene
Datenstruktur, die man anhängen kann — Extension -01 Zentrale».* **Zu entscheiden:** ob Telefon ein simpler Typ, ein
zusammengesetzter Typ oder nur ein Validator am Text ist; ob Land und Ort eigene Felder sind; ob die Art eine Konstante
ist; ob Extensions eine anhängbare Struktur sind.

## INF-048 · «unique» wird zum Primärschlüssel aus mehreren Feldern?

**Stand 2026-09-12.** Sein Wort: *«Ich glaube, wir müssen unique in pk umwandeln, mehrere Felder zusammen ergeben den
unique Schlüssel für die Eingabe.»* «Eindeutig» ist seit [D-735](NewConcept/90-decision-log.md) eine Spalte je Feld.
**Zu entscheiden:** ob mehrere als «eindeutig» markierte Felder zusammen einen Schlüssel bilden, und ob der die Eingabe
identifiziert (Anlegen findet den Satz statt ihn zu verdoppeln).

## INF-049 · Festwert gegen Vorgabe plus «nur lesen»

**Stand 2026-09-12.** Sein Wort: *«Festwert: man wählt einen Knoten, der ist gesetzt. Das Gleiche ist möglich über
default und read only. Aber wenn read only gesetzt ist, kann keine Eingabe mehr gemacht werden, also müsste default immer
eingebbar sein … prüfen, was besser ist; readonly wird nicht angezeigt, wenn es gesetzt ist.»* **Zu entscheiden:** ob es
einen eigenen Festwert gibt, oder Vorgabe plus «nur lesen» ([D-739](NewConcept/90-decision-log.md)) ihn ersetzt — und ob
die Vorgabe bei «nur lesen» eingebbar bleibt.

## INF-050 · Wer liest «with_label» an der Konstante?

**Stand 2026-09-12.** [D-747](NewConcept/90-decision-log.md) hat den Schalter auf sein Wort in den Vertrag der Konstantenklasse
gesetzt, Standard aus. **Gebaut: der Schalter steht im Einstellungsbereich; beim Zeichnen liest ihn noch niemand.**
**Zu entscheiden:** was «mit Label» an einer Konstante bewirkt — der Name der Konstante neben ihrem Wert (etwa
«Kiloohm» neben «kΩ»), oder etwas anderes.

## INF-051 · Innere und äussere Werte eines Satzes tragen verschiedene Sprachangaben

**Stand 2026-09-12.** Gemessen beim Bau von [D-742](NewConcept/90-decision-log.md) am Hersteller-Satz: die Werte der
Adressfelder (innere Kanten) werden mit der Sprache der Seite gespeichert («en_US»), die Werte der äusseren Felder
desselben Satzes ohne Sprache (leere Spalte). Beide Wege lesen sich zurück, aber es sind zwei Regeln für eine Sache.
[D-645](NewConcept/90-decision-log.md) sagt: *es gibt keine sprachneutrale Zeile mehr, die Standardsprache steht als sie
selbst da* — danach wäre der äussere Weg der falsche. **Zu entscheiden:** welche der beiden Regeln gilt, dann wird der
andere Weg angeglichen und der Bestand umgeschrieben. Nichts davon ist gebaut.

## INF-052 · Wo liegen mehrere Werte eines zusammengesetzten Feldes — und wohin führt der Link?

**Stand 2026-09-13.** Beim Bau des Komplex-Renderers ([D-758](NewConcept/90-decision-log.md)). Sein Wort: *«bei
höherer Multiplizität Datensätze hinzufügen und entfernen»*. **Gemessen:** die Werte eines zusammengesetzten Feldes
liegen im Satz des Besitzers an der innersten Kante ([D-667](NewConcept/90-decision-log.md),
[D-742](NewConcept/90-decision-log.md)); zwei Adressen in einem Satz hätten dieselbe Kante für «Street», und das
Schreiben weist ein Feld mit mehreren Werten ab. ~~**Zu entscheiden:** (1) ob jede Zeile ein eigener Teil-Satz wird …~~
⚠️ **(1) war keine offene Frage, sondern mein Lesefehler** — *sein Wort: «ein komplexer Typ wird gruppiert gespeichert».*
[D-577](NewConcept/90-decision-log.md) hatte es entschieden: jeder Teil ein eigener Satz. Die Messung oben beschrieb den
flachen Weg aus D-741/D-742, nicht das Modell; gebaut nach D-577 in [D-759](NewConcept/90-decision-log.md).
**Offen bleibt nur (2):** wohin der «Link mit Summary» einer tieferen Stufe führt — bis dahin klappt er an Ort und Stelle auf.

## INF-053 · Der Typ `Link` (früher «Medium») im heutigen Modell

**Typ:** `VORSCHLAG` · **Status:** ~~`INFERRED`~~ **entschieden, L1–L4 wie empfohlen — [D-761](NewConcept/90-decision-log.md)** — *sein Auftrag 2026-09-13: «wir hatten mal einen media typ angedacht, wo ist der
geblieben?» und «pass das mal bitte ans neue Konzept an».* Beschlossen ist er seit August ([D-229](NewConcept/90-decision-log.md),
[D-230](NewConcept/90-decision-log.md), [D-287](NewConcept/90-decision-log.md), [D-294](NewConcept/90-decision-log.md),
[D-322](NewConcept/90-decision-log.md), [D-323](NewConcept/90-decision-log.md)); **gebaut: nein** — kein Typ, keine Klasse, kein
Renderer. Die Beschlüsse sprechen von `records`, `record_values` und Typen als Daten; das ist nicht mehr das Modell.

**Zu entscheiden, bevor etwas gebaut wird** — je mit meiner Empfehlung:

| # | Frage | Empfehlung | Warum |
|---|---|---|---|
| L1 | Eigene Knotenklasse `Link` oder ein gewöhnlicher Kategorieknoten mit Feldern? | **eigene Klasse** | Renderer, Prüfung und erlaubte Arten müssen einen Link erkennen; am Namen erkennen ist verboten (`CD`, keine Sonderfälle nach Namen). Wie `Einheitswert` ([D-719](NewConcept/90-decision-log.md)). |
| L2 | Die Id in der WordPress-Mediathek: eigener einfacher Typ oder Text? | **eigener einfacher Typ**, wie der Benutzerverweis ([D-649](NewConcept/90-decision-log.md)) | Der Rand löst ihn auf (Vorschaubild, Dateiname); [D-319](NewConcept/90-decision-log.md): ein Typ hat Platz, wo er anders gezeichnet wird. |
| L3 | Die erlaubten Dateiarten ([D-287](NewConcept/90-decision-log.md)): feste Aufzählung oder Konstanten unter einem Gerüstknoten? | **Konstanten unter einem Anker**, wie die Präfixe ([D-728](NewConcept/90-decision-log.md)) | Eine neue Art ist dann ein Knoten, keine Kodeänderung. |
| L4 | Eine leere Liste erlaubter Arten: alles erlaubt, oder ein Konflikt? | **alles erlaubt** | [D-287](NewConcept/90-decision-log.md) sagte «Konflikt»; dein Wort vom 2026-09-11 zu den Listen: *«im Grunde haben wir alle Möglichkeiten, einschränken können wir es immer noch.»* Das widerspricht sich — deine Wahl. |

**Die Übersetzung, wo nichts zu entscheiden ist** — alt gegen heute:

| Beschluss | damals | heute |
|---|---|---|
| [D-229](NewConcept/90-decision-log.md) Ablage | ein Typ unter `Model`, Satz in `records`, Attribute als Werte | ein Knoten unter `Model` (Ast `Model` = eigener Satz, geteilt); seine Angaben sind **Felder**: `url` (Text), Mediathek-Id (L2), `mime` (Text), `source` (Text), `licence` (Text). Ein Satz je Datei in `node_records`, die Werte in `relation_records` ([D-577](NewConcept/90-decision-log.md)). |
| [D-229](NewConcept/90-decision-log.md) geteilt | «aggregated by whoever uses it» | ein Feld auf `Link` ist eine **Aggregation** — «ob zwei Datensätze auf denselben zeigen dürfen» ([D-577](NewConcept/90-decision-log.md), [D-715](NewConcept/90-decision-log.md)); mehrere Datenblätter sind `0..*`. ⚠️ *«Welche Sätze benutzen diese Datei» ist **nicht gebaut**: «Used by» ([D-199](NewConcept/90-decision-log.md)) zeigt heute Knoten, die auf einen Knoten zeigen, nicht Sätze, die auf einen Satz zeigen.* |
| [D-323](NewConcept/90-decision-log.md) mindestens eine Adresse | Invariante | ein **Validator** am Knoten `Link`: `url` oder Mediathek-Id muss stehen. |
| [D-230](NewConcept/90-decision-log.md) Zeichnung | ein Renderer, Art aus MIME, Grad eingestellt | ein Renderer `link`, der ein Aggregationsfeld auf einen `Link` zeichnet; er erklärt sein Attribut **`presence`** (Symbol · Link · Vorschaubild · voll · eingebettet) selbst (Anforderung 3.6.3), Vorgabe am Knoten, **an der Kante überschreibbar** (Anforderung §5). Die Art liest er aus `mime`. Ohne ihn zeigt das Feld die Zusammenfassung ([D-753](NewConcept/90-decision-log.md)). |
| [D-287](NewConcept/90-decision-log.md) erlaubte Arten | Allow-List am Medienattribut | ein Listenattribut der Klasse `Link` (L3), an der Kante überschreibbar — wie `summary_fields`. |
| [D-294](NewConcept/90-decision-log.md), [D-316](NewConcept/90-decision-log.md) Kopie | einmal beim Speichern holen, keine Bytes in der Datenbank | unverändert: die Kopie liegt in der Mediathek, der **Rand** holt sie beim Speichern; der Kern hält nur die Id. |

*Nicht Teil dieses Vorschlags: das Hochladen selbst und der Block «alle Datenblätter einer Stückliste» aus
[D-230](NewConcept/90-decision-log.md) — beides kommt, wenn der Typ steht.*

## INF-054 · Eingabe und Änderung komplexer Daten — Entwurf für ein Konzept

**Typ:** `HYPOTHESE` · **Status:** `INFERRED` — auf sein Wort vom 2026-09-13: *«Eingabe von Daten: wir sollten mal
ein Konzept entwickeln, wie auch komplexe Daten gut eingegeben werden können bzw. geändert werden können.»*
Das hier ist der Entwurf dazu, kein Beschluss. Jede Zeile unter «Zu entscheiden» wartet auf sein Wort.

### Was schon steht, und was es trägt

| Beschluss | Was er für die Eingabe festlegt |
|---|---|
| [D-759](NewConcept/90-decision-log.md) | *«Ein zusammengesetzter Wert in einem Datensatz ist ein eigener Teil-Satz … die Maske spricht einen Teil über seine Satz-Id an … Bei einer Komposition mit mehreren Werten lassen sich Zeilen anhängen und entfernen.»* — Teile werden im Satz des Besitzers erfasst, nicht auf einer eigenen Seite. |
| [D-758](NewConcept/90-decision-log.md) | Der Komplex-Renderer ordnet die Eingabe: feste Angaben, einfache Felder, Teile mit höchstens einem Wert als Formular, mit mehreren als Tabelle, tiefere Teile als Zusammenfassung. |
| [D-753](NewConcept/90-decision-log.md) | Ein Verweis auf einen Satz zeigt seine Zusammenfassung; beim Bearbeiten ein Auswahlfeld über die Sätze des Ziels, in einer Abfrage je Block. |
| [D-540](NewConcept/90-decision-log.md) | *«hat es sichtbare, unmarkierte Kinder, wählt man aus ihnen — sonst gibt man einen Wert ein. Der Ast entscheidet das nicht.»* |
| [D-756](NewConcept/90-decision-log.md) | Ein Satz wandert in den Vater oder ein Kind, mit Ansage, was dabei fällt. |
| [D-760](NewConcept/90-decision-log.md) | Erst prüfen, dann schreiben: eine Beschwerde eines Validators, und nichts des Satzes wird gespeichert. |
| [D-730](NewConcept/90-decision-log.md) | Ein Akt, der etwas braucht, fragt erst — der Dialog vor dem Anlegen. |
| [D-392](NewConcept/90-decision-log.md), Zeile 11 | Das Speichern einer Seite ist gebaut; das automatische Speichern beim Verlassen eines Feldes ist beschlossen und wartet. |

### Was heute beim Aufbau der Betriebssysteme aufgefallen ist

1. Am Feld «Nachfolger» bietet der Wähler **Knoten** an, weil das Ziel «Software» Kinder hat (D-540) — gemeint ist aber ein **Satz**, eine OS-Version. Die Anzeige geht seit D-753, die Eingabe nicht.
2. Die Zusammenfassung eines Verweises reicht eine Stufe tief; wer tiefer will, sieht Nummern.
3. Ein neuer Satz, der noch nirgends passt — etwa ein Hersteller, den es noch nicht gibt —, muss auf der Seite des Ziels angelegt werden, bevor man ihn wählen kann. Sein Wort dazu: *«bei der Eingabe muss man den Satz auch auswählen oder eingeben können»* und Zeile 124: *«Eingabe von Daten sucht im Datensatz»*.
4. Zwei leere Sätze (Beispiel, leerer Benutzersatz) stehen im Angebot des Wählers als «#Nummer».
5. Der Satzblock zeichnet je Zeile zwei vollständige Baumwähler; bei zwanzig Zeilen wird die Seite schwer.
6. Was ein Speichern mit einem abgelehnten Wert von dreissig tut, ist für Validatoren entschieden (D-760: nichts), für den Kern-Fehler mitten im Satz nicht (Zeile 11).

### Zu entscheiden — je Zeile eine Frage, mit meiner Empfehlung

| # | Frage | Empfehlung |
|---|---|---|
| E1 | **Was wählt ein Feld an einer Aggregation in den Modellast: Knoten oder Sätze?** D-540 sagt Knoten, sobald das Ziel Kinder hat; der Nachfolger will Sätze. | D-540 verfeinern: an einer Aggregation in den Modellast werden **Sätze unter dem Ziel** gewählt; Knoten wählt man an Kanten in die Konstanten und an Verweistypen. |
| E2 | **Wie wird ein Satz gewählt, wenn es hundert sind?** Ein Auswahlfeld mit hundert Zeilen ist keine Eingabe. | Tippen sucht in den Zusammenfassungsfeldern des Ziels (das Renderer-Papier sagt schon: *«the visible fields are the default search fields»*) und bietet Treffer an; das Auswahlfeld bleibt für kleine Mengen. |
| E3 | **Darf man aus dem Feld heraus einen neuen Satz anlegen?** | Ja, als Dialog nach D-730: die Zusammenfassungsfelder des Ziels als Eingabe, «Anlegen und wählen». Kein Treffer beim Tippen bietet das an. |
| E4 | **Wie werden Teile mit mehreren Werten erfasst — Tabelle mit Zeilen (D-759) oder je Teil ein Formular?** | Tabelle bleibt die Vorgabe; ab drei Feldern je Teil ein aufklappbares Formular je Zeile, weil eine Tabelle mit acht Spalten nicht mehr lesbar ist. Das ist eine Einstellung am Renderer, keine neue Regel. |
| E5 | **Wie tief wird an Ort und Stelle erfasst?** D-758 sagt: tiefere Teile als Zusammenfassung. | Eine Stufe an Ort und Stelle; die Zusammenfassung einer tieferen Stufe ist ein Sprung auf den Satz, der sie hält (der «Link» aus seinem Wort zu D-758), nicht ein weiteres Aufklappen. |
| E6 | **Was tut ein Speichern, wenn der Kern einen Wert von dreissig ablehnt?** (Zeile 11) | Wie D-760: nichts wird geschrieben, alle Ablehnungen stehen in der Meldung, die eingegebenen Werte bleiben im Formular stehen. |
| E7 | **Automatisches Speichern beim Verlassen eines Feldes** — sein Wunsch aus D-392. | Erst, wenn E6 steht; dann je Feld ein Speichern des ganzen Satzes mit derselben Regel, und der Bearbeiter sieht, ob es gelang. |
| E8 | **Leere Sätze im Angebot** — zeigen, ausblenden, löschen? | Ausblenden: ein Satz ohne Wort ist keine Wahl. |
| E9 | **Wer ist Pflicht?** Zeile 31 (Pflichtfeld) wartete auf Zeile 8, die jetzt teils steht. | Ein Validator «required», gewählt wie «range», mit Beschwerde nach D-760. |
| E10 | **Die Last der Seite** — zwei Baumwähler je Satzzeile. | Der Wähler ist ein Dialog (D-727 hat den Schalter), der seinen Baum erst beim Öffnen holt — das ist Zeile 126 «nur laden, wenn es gebraucht wird», auf die Wähler übertragen. |

**Nicht gebaut, nichts davon.** Bis er entscheidet, gilt, was oben unter «Was schon steht» zitiert ist.

## INF-055 · Typ und Wert als ein Feld?

**Sein Wort (2026-09-15):** *«if type would be LM7905 as well as 33Kohm +-5% you could simply show the type/value when nothing is set. think about that type and value the same field is it currently even possible?»*

### Was heute steht (gemessen)

- Ein aktives Bauteil trägt «Typ» als **Text** am Knoten `Active` (LM7905).
- Ein Widerstand oder Kondensator trägt seinen Wert als **Teile vom Typ Einheitenwert** («Widerstandswert», «Toleranz») an seinem eigenen Knoten.
- Beides sind **verschiedene Kanten mit verschiedenen Typen**. Ein Feld kann heute nicht einmal Text und einmal Einheitenwert sein.
- Was eine Stückliste zeigt, sagt die Einstellung `summary_fields` je Kategorie; ohne sie das erste Textfeld, eine Stufe tief alle Werte (D-797, D-810).

### Zu entscheiden — mit meiner Empfehlung

| Frage | Möglichkeiten | Empfehlung |
|---|---|---|
| Braucht es ein gemeinsames Feld «Bezeichnung»? | a) nein — `summary_fields` je Kategorie legt fest, was «Typ/Wert» ist (Active: Typ; Resistor: Widerstandswert + Toleranz) · b) ein **abgeleitetes** Feld (wie ein Weg-Feld, D-755), das je Kategorie aus anderen Feldern gerechnet und gespeichert wird · c) ein echtes Feld, dessen Typ je Kind wechseln darf | **a** jetzt, **b** wenn gesucht/sortiert werden soll — c bricht «ein Feld, ein Typ». |

**Entschieden: a ([D-815](NewConcept/90-decision-log.md)), sein Wort «3 agreed».**

## Erledigte Eingänge

*(noch keine)*
