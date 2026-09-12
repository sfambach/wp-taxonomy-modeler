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

## Erledigte Eingänge

*(noch keine)*
