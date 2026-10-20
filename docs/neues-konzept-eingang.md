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

**Entschieden: a ([D-824](NewConcept/90-decision-log.md)), sein Wort «3 agreed».**

## INF-056 · Zusatzfunktionen: angeschaltetes Verhalten an Kante oder Knoten

**Sein Wort (2026-09-17):** *«eigentlich müssten wir dann an den konstanten sagen ist filter oder ist sort smd filter, tht filter, almost .. sort … wir haben hier so eine art "rankonfiguriertes verhalten" das hatte wir schon öfters eine art modul mit zusatz funktionen. hast du eine idee dafür wie wir das generalisieren könnten, ich denke da an sowas wie -> relation hat zusatz funktion filter/sort. das bedingt dass … meist sind es funktionen die nur an wenigen stellen benötigt werden, natürlich könne man das auch über ein feld dran konfigurieren kann aber noch nicht abschätzen ob das die richtig variante ist»*

### Was schon steht (gemessen)

| Art | Beispiele | Wie angehängt |
|---|---|---|
| Benannte Objekte aus einer Registratur, mit eigenen Feldern | Renderer (orientation, with_label …), Converter (Umrechnung: Faktor, Offset), Validator | Liste `renderer`/`converter`/`validator` an Knoten oder Kante; Kette Kante → Knoten → Vertrag |
| Felder einer Knotenklasse | Category: `summary_fields`, `preset_field`, `preset_source`, `preset_mode`, `pick_field` · Unit: `erlaubte_praefixe`, `mit_praefix` · UnitValue: `erlaubte_einheiten` | stehen an **jedem** Knoten der Klasse, auch wo niemand sie braucht |
| Kantenspalten | Anzahl, eindeutig, nur lesen, verborgen | an jeder Kante |
| Typen mit Verhalten | Sprung (Ziel, Filterfeld, Quellfeld), Weg (gerechnet), Medien | als Typ des Feldes |

Ausgedrückt «das bedingt, dass …» wird bisher **nirgends**: keine Funktion sagt, was sie an anderen Knoten braucht.

### Mein Vorschlag

Eine vierte Registratur neben Renderer, Converter und Validator: **Zusatzfunktion**. Jede Funktion ist eine Klasse und erklärt:

1. **wo** sie hängen darf (Kante auf Sätze, mehrfache Kante, Knotenklasse),
2. **ihre eigenen Felder** (für die Vorbelegung: die Vergleichspaare, D-844),
3. **was sie bedingt** — Felder, die sie an anderen Knoten verlangt und die nur dort erscheinen, wo sie benutzt wird (für die Vorbelegung: an den Werten der verglichenen Auswahl «filter» oder «sort», also SMD → filter, «hauptsächlich SMD» → sort),
4. **an welcher Stelle** sie eingreift (Angebot filtern/sortieren, Zeilen anlegen, Wert rechnen).

Gewählt wird wie ein Validator: eine Liste `functions` an Knoten oder Kante. Ohne Wahl erscheinen ihre Felder nicht — die Einstellungen der Kategorie werden kleiner.

| Heute | Als Zusatzfunktion |
|---|---|
| `preset_field`/`preset_source`/`preset_mode` | «Vorbelegung» an der Kante; bedingt filter/sort an den Werten |
| `pick_field` | «Mehrere hinzufügen» an der mehrfachen Kante |
| `summary_fields` | bleibt Feld der Klasse — jede Kategorie hat eine Zusammenfassung |
| Sprung, Weg, Medien | bleiben Typen |

### Gegenüber «über ein Feld dranhängen»

| | Zusatzfunktion (Registratur) | nur Felder |
|---|---|---|
| Verhalten im Code | ja, je Funktion eine Klasse | nein, der Code fragt Felder ab |
| «bedingt, dass …» | erklärbar und prüfbar | nicht ausdrückbar |
| sichtbar nur, wo gebraucht | ja | nein, an jedem Knoten der Klasse |
| neue Funktion | Klasse schreiben | Feld anlegen, Code anpassen |

### Zu entscheiden

1. Registratur «Zusatzfunktion» ja/nein?
2. Die Vorbelegung als erste Funktion — mit filter/sort an den Werten?
3. `pick_field` als zweite umziehen?

**Entschieden: alle drei ja, und der Validator zieht mit ein ([D-845](NewConcept/90-decision-log.md)).**

## INF-057 · Was deine Projektbeiträge tragen, und was davon ins Modell passt

**Typ:** `BEFUND` + `VORSCHLAG` · **Status:** `INFERRED` — sein Auftrag vom 2026-09-19: *«durchforste mal alle seiten es gibt auch test, firmware treiber ... und schau wie wir das noch erweitern können»*

### Was gemessen ist

96 Beiträge «Retro Projekt …» im lokalen WordPress (16 veröffentlicht, 80 Entwurf), alle aus einer Vorlage. Fünf Blöcke haben feste Form:

| Block | Beiträge mit echtem Inhalt | Beispiel |
|---|---|---|
| Platinenbestellung (Gerber vorhanden, bestellt wo, Stück, Preis, Farbe/Oberfläche) | 65 Tabellen, ~40 echt | «Enig, Grün», «12€ incl. Porto» |
| Bauteilliste (Referenz, Grösse, Anzahl, Beschreibung, Preis, Lager) | 42 gefüllt, 777 Zeilen | Summe «19,26€» |
| Fazit (Erfolgreich, Preis pro Stück, Lötdauer, Schwierigkeit, Funktion, Lohnt es sich, Einschränkungen) | 38 | «Nur 8 Bit» (7-mal) |
| Logbuch (Datum, Text) | 74, 232 Einträge | «PCB Eingetroffen», «Platine getestet» |
| Dateien (Bilder 327, PDF 102, ZIP 62, dazu bin/jed/uf2 als Firmware) | 50 | Schaltplan, Gerber, Datenblatt |

Firmware steht in 19, Treiber in 8, Probleme in 13 Beiträgen mit echtem Inhalt; **Tests** als eigene Rubrik nur in 2 — Testwissen steht verstreut unter Inbetriebnahme, Probleme und im Logbuch. Jumper-/DIP-Tabellen in etwa 14 Beiträgen. ⚠️ *Vorlagenwerte («1 Stunde», «10», «JLCPCB») sind von echten nicht zu unterscheiden.*

### Mein Vorschlag (nicht beschlossen)

Als Teile (wiederholbar, mit Unterfeldern) an einem Projekt: **Platinenbestellung**, **Bauteilposition**, **Firmware** (Name, Version, Datei, Zielchip, Programmiergerät), **Treiber** (Name, Datei, Betriebssystem, Aufrufzeile, Optionen), **Jumper-Einstellung** (gibt es am Mainboard schon), **Test** (Datum, Werkzeug, Schritt, Ergebnis), **Problem** (Fehlerbild, Ursache, Lösung, gelöst), **Logbuch-Eintrag** (Datum, Text). Als Felder: **Fazit** (Erfolgreich, Preis pro Stück, Lötdauer in Minuten, Schwierigkeit, Funktion, Lohnt es sich, Einschränkungen), **Schaltplan**, **Gerber-Datei**, **Gehäuse**. Der Status liesse sich aus dem Logbuch ableiten statt doppelt zu führen.

### Zu entscheiden

1. Welche dieser Teile zuerst — und ob Werkzeuge (T48, Terminalprogramm) und Rechner eigene Knoten werden.
2. Ob die Beiträge dann ins Modell übernommen werden (Skript wie bei den Quellen) — und was mit den Vorlagenwerten geschieht.
3. Wie das mit dem Aufbau der Seiten zusammengeht (Gutenberg, von ihm für den 2026-09-20 angekündigt).

---
## INF-058 · Hardware-Tabellen und Benchmarks: was darin steckt und was davon stimmt

**Typ:** `BEFUND` + `VORSCHLAG` · **Status:** `INFERRED` — sein Auftrag vom 2026-09-19: *«erst mal gucken, welche Tabellen gehören zusammen … stimmen die Einträge der Hardware … und danach gucken wir, wie wir die Benchmark-Tests aufbauen»*, und zu den Benchmarks: *«jeder Test hat einen Testaufbau»*, *«so ein Benchmark ist so eine eigene Softwarekategorie»*, Betriebssysteme *«nicht weiter als Windows 2000»*.

### Gruppen der TablePress-Tabellen (gemessen)

CPUs (#12, zwei Blöcke nebeneinander) · Mainboards (#16, 25 eigene Boards) · Benchmarks (#13, #14, #19, #20, #22 — eine Sammlung, nach Epochen geteilt; #14 und #19 teilen 7 Testsysteme) · Karten: Sound #17, Netzwerk #21, Grafik #23 (+ #24 leer), Controller #25 (leer) **und #27** — «Kopie von Netzwerk-Adapter» ist in Wahrheit die Multi-I/O-Karte Winbond W83757F · Laufwerke #26 (leer) · Komplettsysteme #28 · Wareneingang #15 (53 Käufe) · Maker, nicht Retro: #1, #5, #6, #7, #8, #10, #11.

### Stimmen die Einträge?

**CPU-Tabelle #12 — geprüft gegen Wikipedia EN/DE: nur Rohmaterial.** Rund 20 sichere Fehler, u. a. Pentium-Busbreite 32 statt 64 Bit, P120 Bustakt 50 statt 60, P150 Multiplikator 3,5 statt 2,5, P166/P200 Codename P55CS statt P54CS, drei Einführungsdaten; 386: SX/DX-Einführung vertauscht, «8,33 MHz» und «/2,/3,/4» als Platzhalter über alle Spalten, i386SL Busbreite, RapidCAD-1 «externer Koprozessor». Dazu verrutschte Zellen, gemischte Einheiten und Formate. Empfehlung: CPUs frisch aus Quellen übernehmen, je Wert die Quelle.

**Mainboards #16 (von mir gelesen, nicht gegen Quellen geprüft):** Werte in falschen Zeilen (JT-586IV4 «FDD = 2xIDE, 1xFDD», CPU-Takte unter «Bus»; GA-586S2 «RAM-Frequenz = 60ns»), zweifelhafte Werte (Dell E93839), M598 Rev 5.1 und 7.0A Feld für Feld gleich, Tippfehler, uneinheitliche Schreibweisen. **Karten:** «Marox», «Tiro32», «ET300AX», «Creativ»; Millennium «VGA / DVI» prüfen.

### Benchmarks (Recherche, Quellen im Befund)

- Sein Paket ist fast sicher **Phil's Computer Lab DOS Benchmark Pack 1.6** (https://www.philscomputerlab.com/dos-benchmark-pack.html).
- **Was seine Daten selbst zeigen:** Landmark CPU, Norton SI CPU und Speedsys CPU sind auf allen Socket-7-Boards mit derselben CPU gleich — sie messen die CPU, nicht das Board. Doom «Zeit» (immer 2134) und Quake «Frames» (immer 969) sind Konstanten, keine Messwerte; Chris-Score = FPS × 1,667; 3DBench liefert Stufenwerte; Landmark läuft ab etwa Pentium II über; Quake 640×480 bei den Celeron D vermutlich an 60 Hz gebunden.
- **Wofür welcher Test:** CPUs — Speedsys CPU, Doom, Quake 320×200, 3DBench 1.0c, TOPBENCH bis 486; Boards/Cache — Speedsys L1/L2/Durchsatz, Cachechk; Grafikkarten — PC Player 640×480, Chris 640×480, Doom max, Landmark Video, dazu VIDSPEED; Platten — Speedsys HDD, Norton SI.
- **Weitere DOS-Tests:** CACHECHK, VIDSPEED, VGAFPS, VESATEST, Wolf3D-timedemo, Memory Speed, MIPS u. a. **Windows 3.11–2000:** Ziff-Davis PC Benchmarks, WinBench/Winstone 99, WinTune 98, Final Reality, 3DMark99/2000/2001, PCMark 2002, Quake II/III-timedemo, Sandra.
- **Ein Test als Datensatz (Vorschlag):** Programm, Version, Paket, Betriebssystem, Szenario, Einstellung, Rohwert mit Einheit, abgeleiteter Wert mit Formel, Richtung, Messbereich/Grenzen, gemessene Komponenten mit Gewicht, Voraussetzungen (FPU, VESA 2.0). **Jede Messung** mit Testaufbau (Board, CPU, RAM, Karte, Platte, BIOS-Einstellungen, Treiber), Datum und Gültigkeit.

### Zu entscheiden

1. Eine vierte Stufe «Exemplar» unter der Revision — sein Stück mit BIOS-Stand, Kauf (aus #15) und Benchmarks?
2. Fehler beim Übernehmen berichtigen (mit Vermerk) oder erst übernehmen und getrennt abarbeiten?
3. Welche Tests gelten, welche fallen weg — und welche werden wiederholt (sein Wort: *«vielleicht müssen wir auch ein paar Tests wiederholen»*).

---
## INF-059 · Am Exemplar: Umbauten, Reparaturen, Fehlersuche — und Messwerte einlesen statt eintippen

**Typ:** `NOTIZ` · **Status:** `INFERRED` — seine Worte vom 2026-09-19, zum Behalten:

> *«Reparatur und Fehlersuche ist noch so ein Punkt, den du mal mit im Hinterkopf behalten könntest.»*
> *«auf den älteren Boards sind zum Beispiel auch Quarze verbaut, die man austauschen kann … man kann auch tunen oder den Cache erweitern. Das ergibt sich dann so ein bisschen auch aus den Einstellungen, die das Board bietet.»*
> *«wie ich das möglichst leicht eingeben kann, wenn ich solche Tests durchführe … vielleicht ein DOS-Programm schreiben, das alle Tests durchführt. Und dann irgendwie eine Tabelle extrahiert und die hochlädt … oder ich importiere sie … die erste Importfunktion.»*

### Was daraus folgt (Vorschlag, nicht beschlossen)

- Am Exemplar ([D-867](NewConcept/90-decision-log.md)) wiederholbare Teile **Umbau** (was, womit — Quarz, Cache, Spannung —, Datum) und **Reparatur** (Fehlerbild, Ursache, Massnahme, Datum, Ergebnis). Die möglichen Umbauten ergeben sich aus den Einstellungen des Boards (Jumper, Takt, Cache-Grössen) — die stehen am Modell.
- Messwerte gehören an ein Exemplar und einen Testaufbau (INF-058).
- **Einlesen statt eintippen:** ein DOS-Programm (Stapeldatei oder kleines Programm), das die Tests nacheinander startet und die Ergebnisse in eine Textdatei schreibt; das Plugin bekommt dafür seine erste Importfunktion.

---
## INF-060 · Beim Anlegen einer Seite gleich fragen

**Typ:** `IDEE` · **Status:** `INFERRED` — sein Wort vom 2026-09-19, ausdrücklich für später:

> *«wenn ich so eine Seite anlege, dann könnte ich ja gleich schon ein paar Fragen beantworten. Was möchte ich anlegen? Welche Prozessorfamilie geht es? … Aber das ist wahrscheinlich für die nächste Version eher dann gedacht.»*

**Was es heute gibt:** Startmuster aus den Seitenvorlagen ([D-870](NewConcept/90-decision-log.md)); seine wiederverwendbaren Blöcke (etwa für 386er und 486er) stehen darin als Verweise und bleiben überall gleich. **Was fehlt:** ein kurzer Fragedialog vor dem Muster, dessen Antworten Blöcke auswählen (etwa den Block der Prozessorfamilie) und den Beitrag mit einem Satz im Modell verbinden.

---
## INF-061 · Bezeichnung und Beschreibung an «Model» — für alles

**Typ:** `VORSCHLAG` · **Status:** `INFERRED` — seine Frage vom 2026-09-19, als die Bilder vor dem Projektnamen standen: *«Ich sehe es weil in modell die bildell jetzt die bilder hängen, hat nicht alles einen titel und beschreibung ?»*

**Gemessen am 2026-09-19** — Namensfelder (Text) unter «Model», mit gefüllten Werten: Hardware › Bezeichnung 213 · Quellenverzeichnis › Titel 203 · Kontact › Name 56 · Prozessor-Familien › Name 48 · Schnittstellen › Bezeichnung 32 · Projekte › Project Name 19 · Seitenvorlagen › Name 17 (vom Code gelesen, `StarterPattern::NAME`) · Platine › Name 6 · Formfaktoren › Bezeichnung 6. Beschreibungen: Electronic Parts 28 · Projekte 16 · Platine 4. Ohne Namen: Exemplare (heissen nach ihrem Modell).

**Vorschlag:** «Bezeichnung» (0..1, damit ein Exemplar ohne auskommt) und «Beschreibung» (0..1) als die ersten Felder an «Model»; die Kante Hardware › Bezeichnung wandert hinauf (Id, Werte und Einstellungen bleiben), die übrigen Namens- und Beschreibungsfelder gehen mit ihren Werten darin auf. Dann stehen Name und Beschreibung überall vorn, ohne Anordnung je Knoten.

**Zu entscheiden:** 1. So bauen? 2. Kontakt › Name dabei (Personen haben Vorname + Name)? 3. «Bezeichnung» an Hardware bleibt Pflicht (1..1) oder überall 0..1?

**Entschieden 2026-09-19 → [D-883](NewConcept/90-decision-log.md):** gebaut; Kontakt ohne; Bezeichnung Pflicht.

---
## INF-062 · Kompatibilität als Beziehung: die Form ist offen

**Typ:** `FRAGE` · **Status:** `INFERRED` — zu [D-886](NewConcept/90-decision-log.md), sein Wort: *«So haben wir vielleicht eine neue Art von Relationstyp, und dann sagen wir, A ist kompatibel zu B. Ja, finde ich gut.»*

Heute stehen 151 Sätze unter «Kompatibilität» mit «Art», «von», «zu» und «zu Familie»; keiner trägt einen Namen. Offen: wie eine solche Beziehung gespeichert wird (eigene Kante, eigener Satz mit zwei Enden, oder eine Kantenart), wie man sie **von beiden Seiten** sieht, und was mit den 151 Sätzen geschieht.

**Zweiter Fall, gemeldet am 2026-10-16:** seine Fragen *«haben wir bei graphikarten und sound karten sowas wie vorgänger nachfolger ?»* und *«ich frage mich auch ob wir das richtig modelliert haben als feld und nicht als relation»*. Gemessen: «Nachfolger» ist ein **Feld** an «Models» (Verweis auf ein Modell, 0..1), das alle Modelle erben, auch Grafik- und Soundkarten; gefüllt ist es 52-mal, nur bei Betriebssystemen. «Vorgänger» gibt es nicht — es wäre dieselbe Beziehung von der anderen Seite gelesen. Eine Entscheidung zum Feld habe ich im Log nicht gefunden. Ebenso ist «Kompatibel mit» an den Karten nach [D-897](NewConcept/90-decision-log.md) «vorerst Text». Beides gehört in dieselbe Antwort: Beziehungen zwischen zwei Sätzen, die man von beiden Seiten sieht.

---

## INF-063 · Eine Abfrage steht 27- bis 28-mal auf zwei Seiten

**Typ:** `BEFUND` · **Status:** `INFERRED` — gemessen am 2026-09-20 von mir, nicht von ihm gemeldet.

`seitenlast-check` ist auf «Kompatibilität» (28-mal) und «Parts List» (27-mal) rot: dieselbe Abfrage nach den Kanten eines Besitzers. Die Decke liegt bei 20 ([D-814](NewConcept/90-decision-log.md)). **Nicht von den Änderungen dieses Tages:** gemessen mit dem Stand vor D-888 und vor D-889 — dieselben Zahlen. Zu finden: wer die Kanten je Teil neu liest.

**Erledigt 2026-09-20:** gefunden in {@see SettingsResolver}: je **Feldverweis** einer Einstellung (`summary_fields`) eine eigene Kantenabfrage — 28 verschiedene Kanten, 28 Abfragen. Jetzt lädt die Vorladung sie in einer (`RelationRepository::byIds()`). Gemessen danach: «Kompatibilität» 138 → 114 Abfragen, häufigste 28 → 19; «Parts List» 116 → 93, häufigste 27 → 14.

---
## INF-064 · Serien sind Gruppen — und Gruppen gibt es auch für Links

**Typ:** `VORSCHLAG` · **Status:** `INFERRED` — seine Worte vom 2026-09-20: *«Serien scheinen eine Gruppierung zu sein, damit würde die Serie als eine Entität entstehen, mit zusätzlichen Infos. Und einen kurzen Titel, den wir wiederum verwenden könnten?»* und *«Hier kommen wir zu einem Thema, das ich auch schon für Links entdeckt habe: Gruppen, Gruppen von Links zum Beispiel Retro-Webshops, Retro-Software, Retro-Foren …»*

**Gemessen am 2026-09-20:** «Serie» ist heute ein **Text** am Teil, fünfmal gefüllt — und die Texte sind schon halbe Steckbriefe: *«90331 (Industry Standard Power Supply Housing); Gegenstück KK 396»*, *«Mini-Fit Jr. (Gehäuse 5557, Stiftleiste 5566)»*. Darum wurde die gerechnete Bezeichnung eines Steckers so lang. Links stehen an fünf Stellen als `Medium` (Adresse + Beschriftung), 28 Werte; eine Gruppe haben sie nicht.

**Vorschlag Serie:** ein Knoten «Bauteil-Serien» mit je einem Satz: **Bezeichnung** (kurz, «KK 396»), Hersteller, Beschreibung, Rastermaß, Links/Datenblätter. Das Feld «Serie» am Teil wird ein Verweis darauf statt Text; die fünf Texte wandern hinein (kurzer Name in die Bezeichnung, der Rest in die Beschreibung). Die Bezeichnung eines Steckers liest dann den **kurzen** Namen: «KK 396 · 6 St · männlich».

**Vorschlag Gruppe, allgemein:** eine Gruppe ist immer dasselbe — ein Satz mit Bezeichnung und Beschreibung, auf den andere zeigen. Für Links: ein Knoten «Linkgruppen» (Retro-Webshops, Retro-Software, Retro-Foren) und am Link ein Feld «Gruppe». Serien wären dann nichts anderes als Gruppen von Teilen, nur mit eigenen Feldern.

**Zu entscheiden:** 1. Serien zuerst bauen? 2. Sind «Gruppe» und «Serie» dasselbe Muster mit verschiedenen Feldern — oder zwei Sachen? 3. Gehört ein Link künftig in eine Gruppe **und** an sein Ding, oder nur in die Gruppe?

---
## INF-065 · Die Satzauswahl «Models» wächst mit jedem Satz

**Typ:** `BEFUND` · **Status:** `INFERRED` — gemessen am 2026-09-21 von mir.

Auf der CPU-Seite steht die Auswahl aller Sätze unter «Models» als geteilte Vorlage — 171 KB, bei rund 300 Sätzen. Jeder neue Satz (Mainboards, PC-Konfigurationen, CPUs, Karten) macht sie grösser; die Seite liegt jetzt bei 1010 KB gegen eine Decke von 1 MB ([D-818](NewConcept/90-decision-log.md)). Mit den Karten wird sie rot.

**Vorschlag:** die Vorlage einer Satzauswahl erst laden, wenn der Dialog aufgeht — derselbe Weg wie die geerbten Feldzeilen ([D-871](NewConcept/90-decision-log.md)). Bis dahin trägt die Seite nur, was sichtbar ist.

**Erledigt 2026-09-21 → [D-898](NewConcept/90-decision-log.md):** gebaut; CPU-Seite 1010 → 781 KB.

---
## INF-066 · Das Exemplar hat eigene Kauffelder, die den Kauf doppeln

**Typ:** `BEFUND` · **Status:** `INFERRED` — gemessen am 2026-09-22 von mir.

Das Exemplar trägt «Gekauft am», «Kaufpreis», «Währung» und «Händler»; seit D-897 hat ein Kauf dieselben Angaben und nennt seine Exemplare ([D-901](NewConcept/90-decision-log.md)). Eine Tatsache an zwei Orten widerspricht dem Code-Standard (*«One place owns each piece of state»*). Gefüllt sind die Felder bei 2 von 63 Exemplaren.

**Vorschlag:** die vier Felder am Exemplar streichen und die zwei Werte in je einen Kauf überführen; am Exemplar zeigt man den Kauf als Rückverweis. Offen, ob ein Exemplar ohne Kauf (geschenkt, gefunden) ein Feld «Herkunft des Stücks» braucht.

**Erledigt 2026-09-22 → [D-902](NewConcept/90-decision-log.md):** gebaut wie vorgeschlagen; offen bleibt nur das Feld für ein Stück ohne Kauf.

---
## INF-067 · Ein Block für Vergleichsstatistiken

**Typ:** `WUNSCH` · **Status:** `OPEN` — gemeldet am 2026-10-16 vom Eigentümer.

> *«mit diesen Prozenten, Abweichungen und so weiter, ähm, das würde ich mir für einen anderen Block später, wenn wir Vergleichsstatistiken darstellen, äh, aufheben wollen.»*

Im Muster zum Vergleich ([D-920](NewConcept/90-decision-log.md)) zeigte Variante C je Vergleichsspalte die Abweichung vom Hauptsatz in Prozent, grün oder rot nach der Richtung der Messgröße («höher/niedriger ist besser»). Das kommt nicht in die Anzeige «Vergleich» des Satz-Blocks.

Am selben Tag genauer:

> *«einen Block bauen können mit irgendwelchen Balkendiagrammen, der die einzelnen Resultate, ja, oder ähnliche Resultate vergleicht und dann praktisch den Satz in den Fokus stellt, der aktuell in diesem Block äh, bearbeitet wird. Und aber auch die Möglichkeit gibt, sich die anderen Daten anzuschauen. Also ähnlich wie auf einer Hardware-Testseite […] Das wäre aber, glaube ich, ein anderer Block einfach.»* — und gleich danach: *«oder aber eine umschaltung auf statistik im vorhandenen block»*

- **Gewünscht:** Balkendiagramme je Messgröße, der Hauptsatz des Blocks im Fokus, die übrigen Ergebnisse einsehbar — wie auf Hardware-Testseiten.
- **Offen:** eigener Block oder eine vierte Anzeige «Statistik» im Block `taxmod/record` ([D-916](NewConcept/90-decision-log.md)). *Mein Vorschlag (INFERRED): die vierte Anzeige, weil Hauptsatz ([D-920](NewConcept/90-decision-log.md)), Satzauswahl und Puffer ([D-919](NewConcept/90-decision-log.md)) dieselben sind.* Welche Sätze in die Balken kommen, ist entschieden: alle mit derselben Messgröße ([D-921](NewConcept/90-decision-log.md)).

---
## INF-068 · Benchmarks heissen «#36005» statt mit ihrem Namen

**Typ:** `BEFUND` · **Status:** `INFERRED` — gemessen am 2026-10-16 von mir, lokal an einer Kopie seiner Daten.

Ein Verweis auf einen Benchmark (Feld «Benchmark» an den Messgrössen) zeigt in der Anzeige und im Vergleich «#36005» statt «3D Bench». Die Benchmarks liegen unter «Software», und dort wählt `summary_fields` Hersteller und Version — beides hat ein Benchmark nicht, also bleibt die Nummer. Der Rückfall auf das erste Textfeld greift nur, wenn **keine** Feldwahl da ist, nicht wenn die gewählten Felder leer sind.

**Vorschlag:** am Knoten «Benchmarks» eine eigene Feldwahl «Bezeichnung» setzen (eine Datenänderung über den Upload, kein Code). Offen, ob der Rückfall allgemein auch bei leeren gewählten Feldern greifen soll.

**Für den Vergleich bis dahin:** als Zeilenbeschriftung die «Bezeichnung» der Messgrösse («3D Bench für langsame PCs») und die Einheit.

**Am 2026-10-16 bestätigt → [D-922](NewConcept/90-decision-log.md).** ⚠️ *Die Fähigkeit `taxmod/apply` konnte keine Einstellungen setzen; seit [D-923](NewConcept/90-decision-log.md) kann sie es (`set_list`).* Zweiter Befund dabei: bei Landmark, Speedsys und System Info steht der gemessene Teil (CPU, FPU, Video, RAM, HDD) nur in der Bezeichnung («Landmark [CPU]»), Szenario ist leer.

---
## INF-069 · Marktpreise mit Datum

**Typ:** `WUNSCH` · **Status:** `OPEN` — gemeldet am 2026-10-17 vom Eigentümer.

> *«historische preise mit datum erhalten»* — und gleich danach: *«wäre das ne eigene tabelle wie modellieren wir das ?»*

Bei den Mikrocontroller-Boards stehen Preise heute als Text in «Beschreibung» («Preise: 17.10.2026 ab 31,99 $ (Luckfox); 16.06.2025 16,99 $ (Waveshare)»). Ein Feld dafür gibt es nicht. Ein Marktpreis ist nicht dasselbe wie ein **Kauf** ([D-897](NewConcept/90-decision-log.md)): der Kauf hält fest, was er bezahlt hat, der Marktpreis, was ein Händler an einem Tag verlangt — auch für Dinge, die er nie kauft.

- **Gewünscht:** ältere Preise bleiben mit Datum erhalten, neue kommen dazu.
- **Offen:** (a) eigener Knoten «Preisangabe» (Datum, Betrag, Währung, Händler, Quelle) als Komposition 0..* am Satz, (b) ein Textfeld «Preis» mit mehreren Einträgen, (c) bleibt Text in «Beschreibung». *Mein Vorschlag (INFERRED): (a), weil jede Angabe Zeitpunkt und Quelle hat und sich dann sortieren und vergleichen lässt; offen dabei, ob «Preisangabe» und «Kauf» Betrag, Währung und Händler teilen.*
- **Bis zur Entscheidung:** Preise mit Datum im Text, in Beiträgen und in «Beschreibung».

**Entschieden 2026-10-17 → [D-926](NewConcept/90-decision-log.md):** Variante (a), *«preis gehört ja auch immer zu nem lieferanten oder auch druchschnitt also a is sowas wie bei einer bestellung»*. Offen: Ort des Knotens, gemeinsame Felder mit dem Kauf.

*Angelegt 2026-10-17 (INFERRED, meine Form, nicht von ihm entschieden):* Knoten «Preisangaben» unter «Model», neben «Käufe»; eigene Felder, keine mit dem Kauf geteilt: Modell (→ Models) und Bauteil (→ Electronic Parts) — wie «von»/«von Bauteil» an der Kompatibilität, weil «Model» als Rahmenknoten kein Ziel sein darf —, Datum, Art (Angebot, Durchschnitt, Listenpreis), Preis je Stück, Währung, Plattform, Händler; die Quelle steht im geerbten «Herkunftshinweis». Neue Währung «Australian Dollar». Ort und gemeinsame Felder bleiben offen. ⚠️ *Am selben Abend ersetzt durch [D-927](NewConcept/90-decision-log.md): Preis als Kombifeld «Geldbetrag», Lieferant als Verweis auf «Lieferant»; die getrennte Form ging nie online.*

*«Geldbetrag» war online (Käufe, Preisangaben) und wird ersetzt durch [D-929](NewConcept/90-decision-log.md): der Preis ist ein Einheitenwert, «Currency» hängt unter «Base units».*

## INF-070 · Preise in der Währung des Besuchers zeigen

**Typ:** `WUNSCH` · **Status:** `OPEN` — gemeldet am 2026-10-17 vom Eigentümer.

> *«es war ja mal eine speicherung der in wärung aber anzeige in der seiten einstellunge (sprache, wäreung im browser)»*

Gespeichert wird in der eigenen Währung ([D-064](NewConcept/90-decision-log.md): *«An amount is stored in its own currency; a frozen rate is stored beside it; the converted figure is derived.»*). Seit [D-929](NewConcept/90-decision-log.md) ist der Preis ein Einheitenwert mit einer Währung als Einheit.

- **Gewünscht:** Anzeige umgerechnet in die Währung, die Seite oder Besucher (Sprache, Browser) vorgeben.
- **Offen:** Woher die Kurse kommen (fest eingetragen, je Tag abgerufen), ob ein Kurs am Satz eingefroren wird ([D-064](NewConcept/90-decision-log.md)), wie die Zielwährung bestimmt wird (Seiteneinstellung, Sprache, Browser) und ob der Originalbetrag mit angezeigt wird.
- **Gebaut:** nein — es gibt weder Kurse noch Umrechnung; Preise erscheinen in ihrer gespeicherten Währung.

## INF-071 · Datum im Vergleich in der Genauigkeit des Felds zeigen

**Typ:** `BEFUND` · **Status:** `OPEN` — gemeldet am 2026-10-17 vom Eigentümer, später zu besprechen.

> *«datum sollten wir später drüber regeden aber dafür gibts einen type»*

Anlass: Im Vergleich der Netzwerkkarten steht «Erscheinungs Monat/Jahr» als «1994-01-01». Die Genauigkeit gibt es schon ([D-737](NewConcept/90-decision-log.md): *«Datum und Zeit kennen fünf Genauigkeiten: Jahr, Monat, Tag, Zeit, Tag und Zeit — gewählt am Renderer (`date_precision`).»*); [D-925](NewConcept/90-decision-log.md) liess das Datum im Vergleich unformatiert, *«offen, bis ein Datumsformat ein Merkmal des Felds ist»*.

- **Offen:** ob der Vergleich `date_precision` des Felds übernimmt, und wie die Reihenfolge Tag-Monat-Jahr je Sprache aussieht (D-737 lässt sie offen).
- **Entschieden am selben Abend:** die Genauigkeit gilt je Wert ([D-932](NewConcept/90-decision-log.md), seine Wahl *«Genauigkeit je Wert»*); gebaut in 0.1.17 — «1994», «1997-03».
- **Weiter offen:** die Schreibweise je Sprache («März 1997» statt «1997-03»).

## INF-072 · Daten schreiben ohne das Modell ändern zu dürfen

**Typ:** `VORSCHLAG` · **Status:** `DECIDED` — [D-936](NewConcept/90-decision-log.md), gebaut in 0.1.20 (2026-10-18), weil der Importer der Bestellmails schreiben soll.

> *«würde ich gerne erstmal offen starten und ohne diese Sicherheit […] Später, wenn wir vielleicht andere Tools haben, die nur auf die Daten zugreifen sollen, ähm, würde ich das ändern wollen»*

Anlass: ein geplanter Importer für Käufe (Bestellmails von eBay und AliExpress → «Käufe») soll Sätze anlegen, aber das Modell nicht ändern können. Heute verlangen alle drei Abilities `manage_options` (`ModelAbilities::allowed()`), und `taxmod/apply` nimmt Daten- und Modelländerungen in einer Liste ([D-911](NewConcept/90-decision-log.md)).

- **Vorgeschlagen, nicht entschieden:** eine eigene Ability nur für `create_record`, `set_values`, `remove_record`; eine eigene Berechtigung dafür und eine Rolle ohne Admin-Rechte; ein Wächter, der Modelländerungen über diesen Weg ablehnt.
- **Bis dahin:** ein Werkzeug, das schreibt, braucht einen Benutzer mit `manage_options`.
- **Wann:** *«wenn wir ihn brauchen implementieren wir inf-072»* (2026-10-18) — also bevor der Importer schreibt. Der Benutzer dafür ist angelegt: `auto_einkauf`, Rolle Redakteur, und soll es bleiben.
- **Gebaut:** ja, 0.1.20 — `taxmod/write-data`, Rolle `taxmod_data_writer` ([D-936](NewConcept/90-decision-log.md)).

## INF-073 · Bestand und Fertigung: vom Wareneingang über die Platine zum Verkauf

**Typ:** `WUNSCH` · **Status:** `OPEN` — gemeldet am 2026-10-18 vom Eigentümer, nicht besprochen.

> *«wir können nur verkaufen, was wir produziert haben. Ne? Das heißt, wir brauchen auch irgendwie äh, einen Bestand oder sowas. […] das sollten wir vielleicht noch als ja, dritte Ebene mit einführen. Ich meine auch die, wir haben ja so eine Art Produktion, ne? Also die Wareneingänge führen zu, werden verwendet, was weiß ich, wenn ich Widerstände kaufe, dann wieder für eine Platine und die Platine wird dann verkauft.»*

Anlass: Bestellungen ([D-933](NewConcept/90-decision-log.md)) und Verkäufe ([D-934](NewConcept/90-decision-log.md)) sind angelegt; dazwischen fehlt, was auf Lager ist und was daraus gebaut wurde. Der Einstandspreis eines Eigenbaus (Greaseweazle) ließe sich erst damit herleiten.

- **Vorhanden:** Stücklisten (`Part List Item`, [D-912](NewConcept/90-decision-log.md), [D-914](NewConcept/90-decision-log.md)), Projekte mit Platinen, Exemplare (Einzelstücke), Käufe mit Einstandspreis.
- **Offen:** Bestand als gepflegte Zahl je Artikel oder als Summe von Bewegungen (Zugang aus Kauf, Verbrauch und Zugang aus einem Baulauf, Abgang aus Verkauf); was ein Lagerartikel ist (Bauteil, Platine, Fertigprodukt — Exemplare sind schon Einzelstücke); wie die Selbstkosten eines Baulaufs aus den verbrauchten Teilen entstehen. Eine Summe über Bewegungen braucht Code im Plugin — das Modell rechnet heute nicht.
- **Ablauf, wie der Eigentümer ihn sieht (2026-10-18, noch unsicher, nicht entschieden):**
  > *«würde gerne auch den bestand den ich schon haben einbringen aber nur wenn ich was baue also ne platine kommt oder ich lege ein neues projek an, ich suche nach den teilen die schon da sind. dann trage ich einfach den katuellen bestand ein. später gehen wir dazu über was ist im bestand was muss ich bestellen wenn alles da ist fange ich an zu bauen wenn fertig kann man den verbrauch buchen, evtl ist es wichtig auch sowas darszustellen wir reserviert, ich habe meist mehrere projekte laufen wenn 10 widerstände da sind und diese sind schon reserviert muss ich welche fürs nächste projekt bestellen oder ich teile sie auf.»*
  1. **Anfangsbestand nach Bedarf:** kein Inventurlauf — erfasst wird, wenn ein Bau oder ein neues Projekt ansteht, und nur für dessen Teile.
  2. **Bedarf:** Stückliste des Projekts gegen Bestand → was fehlt, wird bestellt.
  3. **Reservierung:** Teile im Bestand sind einem laufenden Projekt zugeordnet; mehrere Projekte laufen gleichzeitig, frei ist Bestand minus reserviert. Aufteilen muss möglich sein.
  4. **Verbrauch buchen,** wenn der Bau fertig ist.
- **Gebaut:** nein. Die Erfassung braucht Formulare — [INF-075](#inf-075--prozesse-und-formulare-im-modell-anlegen).
- **Erster Schritt, sein Wort am 2026-10-19:** *«erstmal was einfaches ein wareneingang erhöht den bestand ein verbrauch mindert ihn»*. Anlass: zehn Bauteile mit gezähltem Bestand, auf seinen Vorschlag als Bestellung mit Händler «Bestand» erfasst (*«mach einfach eine bestellung mit den ganzen teilen Lieferant ist Bestand»*).
- **Vorschlag (meiner, nicht entschieden):**
  1. Der Bestand ist **keine gespeicherte Zahl**, sondern Zugänge minus Abgänge — sonst steht eine Tatsache an zwei Orten (Code-Standard, «One place owns each piece of state»).
  2. **Zugang** ist eine Kaufposition: ihre `Anzahl`. Dafür braucht der Kauf einen Verweis auf das Bauteil; `Modell` zeigt heute nur auf «Models», nicht auf «Electronic Parts» — also ein Feld `Bauteil` am Kauf, Satzverweis auf «Electronic Parts», `0..1`.
  3. **Abgang** ist ein Satz unter einem neuen Knoten `Verbrauch`: Bauteil, Anzahl, Datum, optional Projekt, Hinweis.
  4. **Anzeige:** am Bauteil eine gerechnete Zeile «Bestand» — eine Abfrage für alle Bauteile einer Seite (`CD-7`). Das braucht Code; das Modell rechnet heute nicht.
  - Später, nicht Teil des ersten Schritts: Verkäufe als Abgang, Reservierung, Bedarf aus der Stückliste.
- **Rückmeldung des Eigentümers zum Vorschlag, 2026-10-20 (nicht entschieden):**
  > *«habe mir das schon irgendwie mehr all umfassender vorgestellt. Verbrauchen und We als aktion prozess, ich überlege aber weil tax eigentlich nur datenstruktur ist ob es hier ein tax stock modul oder sowas braucht»*
  - Wareneingang und Verbrauch sind **Aktionen, ein Prozess** — nicht nur Sätze, die man von Hand anlegt.
  - Offen: ob der Bestand ein **eigenes Modul** wird, das das Modell als Datenstruktur nutzt, statt in den Modeller zu wandern.
- **Vorschlag dazu (meiner, nicht entschieden):** ein eigenes Modul («Taxmod Stock», eigenes Plugin oder abtrennbarer Teil). Der Modeller bleibt Datenstruktur (Bauteile, Händler, Käufe, Datenblätter) und wird vom Modul nur gelesen; das Modul besitzt die Vorgänge — Wareneingang (auch aus einer Bestellung), Verbrauch, Inventur, Umbuchung — als **Buchungen**, der Bestand ist deren Summe. Später Lagerorte, Mindestbestand, Reservierung je Projekt. Berührt [INF-075](#inf-075--prozesse-und-formulare-im-modell-anlegen): ein Prozess mit Schritten und Regeln ist dort als Daten oder als Code offen.
- **Konzeptentwurf «Taxmod Stock», 2026-10-20 (nicht entschieden):** [`pakete/lager/konzept.md`](pakete/lager/konzept.md) — Schnitt Daten/Vorgänge, Bestand als Summe der Bewegungen, Vorgänge, Lagerartikel per Zusatzfunktion, Bewertung, sechs Fragen an den Eigentümer.

## INF-075 · Prozesse und Formulare im Modell anlegen

**Typ:** `WUNSCH` · **Status:** `OPEN` — gemeldet am 2026-10-18 vom Eigentümer, nicht besprochen.

> *«Was wir noch gar nicht haben, sind irgendwelche Formulare, um das Ganze zu verarbeiten. Das wäre wahrscheinlich dann der nächste Schritt in der Taxonomie, dass wir Prozesse und Formulare anlegen. Auf eine möglichst einfache Art und Weise und aber auch modular, sodass man einfach neue erstellen kann, wenn man das braucht.»*

Anlass: [INF-073](#inf-073--bestand-und-fertigung-vom-wareneingang-über-die-platine-zum-verkauf) — Bestand erfassen, reservieren, Verbrauch buchen sind Abläufe über mehrere Sätze, keine Eingabe in einen einzelnen Satz.

- **Vorhanden:** Eingabe je Satz über den Komplex-Renderer ([D-758](NewConcept/90-decision-log.md)); Schreiben über `taxmod/apply` ([D-911](NewConcept/90-decision-log.md)).
- **Offen:** was ein Prozess ist (Schritte über mehrere Sätze?), ob ein Formular selbst im Modell steht (Daten) oder Code ist, wo es läuft (Admin, Beitrag, Block).
- **Seine Fragen, 2026-10-20:** *«Was macht ein tax projekt/modell aus? eigene datetypen, modelle, konstanten und nun auch prozesse.»* — *«die frage ist auch was ist ein prozess und kann man ihn im tax generell andenken und dann in den einzelnen modellen implementieren»* — *«Prozess abfolge von schritten ? Manipuliert/transformiert daten ?»*
- **Vorschlag (meiner, nicht entschieden):**
  - **Ein Prozess ist eine Abfolge von Schritten, die Sätze von einem Zustand in den nächsten bringt** (Bestellung «bestellt» → Wareneingang → Bestand +). Jeder Schritt **liest** eine Eingabe (ein Formular über einem Knoten), **prüft** Regeln und **schreibt** in einer Handlung — alles oder nichts, wie `taxmod/apply` ([D-911](NewConcept/90-decision-log.md)). Das **Ergebnis ist selbst ein Satz** (der Vorgang mit seinen Pos), damit nachvollziehbar bleibt, was geschah.
  - **Allgemein im Tax, umgesetzt im Modell — nach dem Muster der Zusatzfunktionen** ([D-845](NewConcept/90-decision-log.md), `src/Core/Addon/`): ein Verhalten wird im Code registriert, erklärt selbst, wo es hängen darf, und wird an einem Knoten angeschaltet, mit eigenen Feldern. Das Tax kennt die Hülle (Name, Auslöser, Eingabeknoten, Schritte, Ergebnisknoten), ein Modul wie das Lager liefert die Schritte. Einfache Schritte später als Daten.
  - Ein Tax-Modell bestünde dann aus: Datentypen, Modellen (Knoten mit Feldern), Konstanten, Sätzen — und Prozessen.
- **Seine Einordnung, 2026-10-20 (nicht entschieden):** *«für formulare und prozesser bräuchten wir glaube ich erstmal ein konzept formulare bauen sollte mit den richtigen renderen ja schon möglich sein prozesse ist was neues»*
  - **Formulare:** sieht er als Sache der Renderer, kein neuer Mechanismus.
  - **Prozesse:** neu, brauchen zuerst ein eigenes Konzept. Nachsatz: *«verwendet aber formulare»* — ein Prozess nutzt Formulare.
- **Seine Präzisierung, 2026-10-20 (nicht entschieden):** *«im grunde muss ich ein formular anlegen können, formulare erfassen daten, hierfür bräuchten wir ein neue modul tax_form. generell denke ich das wir in tax admin ein menüpunkt module vorsehen sollten und da reihen sich alle module ein»*
  - Formulare **legt der Eigentümer selbst an**; ein Formular erfasst Daten.
  - Dafür ein **eigenes Modul `tax_form`**.
  - Siehe [INF-078](#inf-078--model-in-module-umbenennen): Menüpunkt «Module».
- **Konzeptentwurf «tax_form», 2026-10-20 (nicht entschieden):** [`pakete/formulare/konzept.md`](pakete/formulare/konzept.md) — Formular als gespeicherte Auswahl über einem Zielknoten, als Satz im Modell, Absenden über denselben Weg wie `write-data`, Widerspruch zu D-253, sechs Fragen an den Eigentümer.
- **Gebaut:** nein.

## INF-074 · Angebote bei eBay einstellen, Verkaufstexte, Link zum laufenden Angebot

**Typ:** `WUNSCH` · **Status:** `OPEN` — gemeldet am 2026-10-18 vom Eigentümer, ausdrücklich für später.

> *«gibt die [eBay] API her, dass man dann Verkäufer einstellen kann. Dann wäre es später natürlich auch interessant, Verkaufstexte irgendwie zu erstellen und vielleicht sogar einen Link äh, bei den Artikeln auf den aktuellen eBay-Verkauf, wenn da irgendwas vorhanden ist, zu machen.»*

- **Was eBay anbietet (nicht gemessen, aus allgemeinem Wissen):** Angebote einstellen über die Sell-APIs (Inventory API, oder die ältere Trading API mit `AddItem`); laufende eigene Angebote lesen, um von einem Satz auf das Angebot zu verlinken. Beides braucht einen eBay-Entwicklerzugang und die Zustimmung des Kontos (OAuth).
- **Verkaufstexte** kämen aus dem Modell (Projekt, Platine, Exemplar, Messwerte) — dafür braucht es keine eBay-API.
- **Gebaut:** nein. Hängt an [INF-073](#inf-073--bestand-und-fertigung-vom-wareneingang-über-die-platine-zum-verkauf) (was ist verkaufbar) und an [D-934](NewConcept/90-decision-log.md) (Verkäufe).

## INF-076 · Ein Knoten verlässt den Bereich seiner geerbten Felder

**Typ:** `LÜCKE` · **Status:** `OPEN` — gefunden am 2026-10-20 beim Lager-Konzept ([`pakete/lager/konzept.md`](pakete/lager/konzept.md) §1).

> *«warum das sollte so nicht sein»* — der Eigentümer, auf meinen Satz, beim Verschieben von Bestellungen aus `Model` heraus gingen die geerbten Felder verloren.

- **Gelesen, nicht ausprobiert:** Verschieben schreibt nur Vater und Pfad um (`ModelEditor::move()` → `reparent()`); die Werte bleiben in ihren Zeilen, gebunden an die Kanten von `Model` («Bezeichnung», «Herkunftshinweis» …). Liegt der Knoten danach nicht mehr unter `Model`, gelten diese Kanten für ihn nicht — die Werte sind **nicht gelöscht, aber ohne Feld**: nicht sichtbar, nicht bearbeitbar, bis er zurückkommt.
- **Mein «gehen verloren» war darum falsch** — sie werden unsichtbar, nicht gelöscht.
- **Offen:** was beim Verschieben geschehen soll — warnen und fragen wie bei [D-701](NewConcept/90-decision-log.md) und [D-033](NewConcept/90-decision-log.md) (Felder mitnehmen als eigene Felder am Knoten / Werte verwerfen / abbrechen), oder verbieten. Für das Lager ist es nicht nötig, wenn «Stock» unter `Model` steht.
- **Gebaut:** nein.

## INF-077 · Der Ast `Compositions` entscheidet im Code noch, ob ein Wert ein eigener Teil wird

**Typ:** `LÜCKE` · **Status:** `DECIDED` ([D-938](NewConcept/90-decision-log.md)) — gefunden am 2026-10-20 auf seine Frage *«der knoten kompositionen hat er noch eine funktion oder haben wir die jetzt abgeschafft bitte geanau prüfen in dokumenation und code»*.

- **Beschluss:** [D-890](NewConcept/90-decision-log.md) — sein Wort *«vorgabe ist composition benutzer kann aggregation wählen. Den compositions ast gibts aus meiner sicht nur noch aus historischen gründen prüfe das mal bitte»*; die Messung damals: *«Im Kode kennen ihn nur zwei Gerüste und der Rückfall für die Art — er ist ein Ort, kein Zwang.»* Abgeschafft hat ihn kein Beschluss.
- **Gebaut (gelesen am 2026-10-20, nicht im Browser geprüft):** die Messung von D-890 war unvollständig. Ob ein Wert ein **eigener Teil** (Teilsatz, [D-577](NewConcept/90-decision-log.md)) wird, entscheidet nicht die Art der Kante, sondern der **Ast des Ziels**: `DataEntry::ownsItsRecord()` fragt `Branch::storage()`, und `OwnRecords` gilt nur für `Compositions` und `Combined`. `createPart()` und `attachPart()` lehnen sonst ab — *«does not point into Compositions, so its value lives in the record itself»*. Die Art der Kante (Komposition/Aggregation) steuert nur Darstellung und Auswahl.
- **Weitere Stellen:** Rückfall der Art, wenn keine genannt wird (`ModelEditor::fallbackKind()`); die Gerüste `CompositionScaffold` und `UnitScaffold`; Rahmenknoten, geschützt und gesät.
- **Folge:** eine Komposition auf einen Knoten ausserhalb von `Compositions` — etwa unter einem Hauptast wie «Kontact» oder einem künftigen «Stock» — bekommt keine Teile.
- **Offen:** soll die Art der Kante entscheiden, ob ein Teil entsteht (dann ist der Ast wirklich nur noch ein Ort), oder bleibt der Ast die Regel ([D-232](NewConcept/90-decision-log.md))?
- **Entschieden:** [D-938](NewConcept/90-decision-log.md) — die Kante entscheidet.
- **Gebaut:** ja, 0.1.25 (Schema 56), noch nicht live. `Storage::forField()` antwortet aus Kante und Ziel, `Branch::storage()` ist weg. Beim Upgrade werden Kompositionen, die auf Sätze ausserhalb von `Compositions` verweisen, zu Aggregationen (INFERRED, siehe D-938). Wächter `kante-entscheidet-check`: rot auf dem alten Stand, grün auf dem neuen. Im Browser nicht geprüft.

## INF-078 · «Model» in «Module» umbenennen

**Typ:** `IDEE` · **Status:** `OPEN` — 2026-10-20, beim Lager-Konzept.

> *«vielleicht sollten wir modelle in module umbenennen. Module sind dann Kontakt, Stock, Bauteile, Hardware, Sorftware genaue aufteilung ist mir noch nicht klar, die module könen andere module nutzen»*

- **Davor:** *«Was macht ein tax projekt/modell aus? eigene datetypen, modelle, konstanten und nun auch prozesse.»*
- **Mein Verständnis (INFERRED):** ein Modul bündelt eigene Knoten, Konstanten und Vorgänge ([INF-075](#inf-075--prozesse-und-formulare-im-modell-anlegen)); Kontakt käme wieder unter den gemeinsamen Ast ([D-884](NewConcept/90-decision-log.md) stellte ihn daneben); ein Modul nutzt ein anderes über Aggregationen, nie durch Kopie.
- **Offen:** Aufteilung der Module; nur ein Name oder auch eine Einheit, die Code mitbringt (Plugin wie `wp_tax_stock`); ob Sätze zwischen Modulen nur verwiesen werden dürfen.
- **Sein Wunsch, 2026-10-20 (nicht entschieden):** im Tax-Admin ein **Menüpunkt «Module»**, unter dem sich alle Module einreihen (Anlass: Modul `tax_form`, [INF-075](#inf-075--prozesse-und-formulare-im-modell-anlegen)).
- **Gebaut:** nein.

---
## Erledigte Eingänge

*(noch keine)*
