# Antwort auf die Rückfragen zum Ereigniskonzept

**Stand:** 2026-08-31. Gehört zu [`konzept-kompakt.md`](konzept-kompakt.md) und ist wie dieses
**abgeleitet, nicht Quelle**.

Alle Zahlen darin sind an der laufenden Installation gemessen, nicht geschätzt.

---

## 0 · Vorab: die Aufgabe ist schärfer als im ersten Blatt

Das Ereignissystem ist **der Grenzübergang zwischen Oberfläche und Kern**, in zwei Hälften:

* **Absicht** — Fläche → Kern: der Benutzer hat etwas getan.
* **Projektion** — Kern → Fläche: dann gilt jetzt das.

Die Richtung ist damit selbst eine Angabe; ein Feld `source` wird dafür nicht gebraucht.

**Das eigentliche Ziel ist automatisches Speichern, umschaltbar.** Der eine Speichern-Knopf war eine
Vereinfachung am Anfang; er wird der Zustand «aus». Und der Grund, warum automatisches Speichern
heute nervt, ist gemessen: der jetzige Weg ist `POST → Redirect → GET`, also **ein vollständiger
Seitenaufbau pro Änderung** — und damit der Kampf um Scrollposition und Fokus pro Änderung. Im
Vorgängerprojekt sprang die Seite bei jeder Änderung.

In diesem Projekt ist dieser Kampf dreimal geführt worden: ein Skript stellt die Scrollposition aus
`sessionStorage` wieder her; die Erfolgsmeldung liegt `position: fixed`, damit sie das Layout nicht
verschiebt; ein `#fragment`-Sprung wurde wieder entfernt, weil er gegen das Skript arbeitete.

> **Anforderung:** eine Projektion darf Scrollposition und Fokus nicht anfassen. Wird nur die
> geänderte Zeile ersetzt, gibt es keinen Sprung. *Das* ist der Gewinn, nicht die Ersparnis an Daten.

**Warnung zum MVC-Bild:** die Renderer liegen **im Kern**, auf der Modellseite. Der «View» wird dort
*erzeugt*; das Frontend *platziert* ihn nur. Eine Zeichenschicht in JavaScript ist ausgeschlossen.
Genauer wäre: Model – Controller – **Projektion**.

---

## 1 · Frage: soll ein Event `source` führen — technischer Ursprung, Komponente, oder beides?

### Kurz

**Keins von beidem unter diesem Namen.** «Woher» ist im Projekt bereits **dreifach** belegt:

| Begriff | Bedeutet |
|---|---|
| `provenance` | woher ein **Knoten** stammt — welches Datenpaket, und ob er seither geändert wurde |
| `origin` (Spalte «From») | wo ein **Feld erklärt** ist — `own` gegen `inherited` |
| «Filled from …» | woher die **Werte** einer Vorschau kommen — echter Satz, Testsatz, Vorgaben |

Ein viertes `source` daneben wird in einem Gespräch früher oder später das Falsche bedeuten.

### Was stattdessen

* **`address`** — *was* sich geändert hat: Knoten-Id, Kanten-Id, Satz-Id, Pfad. **Die Pflichtangabe**;
  ohne sie ist ein Ereignis nicht handelbar, mit ihr braucht man die Herkunft oft gar nicht.
* **`actor`** — `human` oder `system` (Migration, Saat, Berechnung). Existiert schon als `by_user_id`
  im Änderungsbuch, und die Unterscheidung trägt Gewicht: ein Vorfall mit 24 doppelt angelegten Knoten
  wurde genau daran erkannt — Einträge ohne Benutzer, also ein Saatlauf und kein Mensch.
* **`surface`** — welche Fläche es ausgelöst hat. **Nur am Rand**, reist nicht in den Kern; sie dient
  dazu, dass eine Fläche ihr eigenes Ereignis nicht zweimal verarbeitet.
* **`change_group_id`** — die **bestehende** Ursachenkette: ein POST ist eine Handlung und bekommt eine
  Nummer. Ein Folgeereignis trägt sie weiter, statt eine neue Kausalität zu erfinden.

### Was nicht

* **Der Transport (`wordpress`, `rest`, `javascript`) reist nicht in den Kern.** Der Kern darf keine
  WordPress-Tatsache kennen — das ist die Schichtenregel, und sie ist der Grund, dass der Kern ohne
  WordPress-Bootstrap testbar ist. Braucht ihn der Rand für sich, bleibt er am Rand.
* **`renderer` ist keine Quelle.** Ein Renderer schreibt nie; er zeichnet.
* `field-editor` / `record-editor` sind **Flächen**, `storage` ist eine Schicht — die ursprüngliche
  Liste mischte zwei verschiedene Dinge.

---

## 2 · Frage: was transportiert ein Event bezüglich eines Wertes?

Zur Wahl standen: **A** nur Identität · **B** Identität + `old`/`new` · **C** je nach Ereignis.

### Kurz

**C — aber die Variable ist nicht «welches Ereignis», sondern «wer hält in diesem Moment die
Wahrheit».** Daraus folgt C von selbst, und man bekommt eine Regel statt einer Fallliste.

| Wahrheit liegt bei | Ereignis trägt |
|---|---|
| **dem Speicher** (schon geschrieben) | nur die Identität — der Empfänger liest |
| **dem Browser** (noch nicht geschrieben) | Identität **+ was er geändert hat** — sonst weiss es niemand |
| **der Geschichte** (was war) | nichts — das steht im Änderungsbuch |

### Warum A allein nicht geht

Ob eine Auswahl die leere Wahl anbietet, leitet der Kern **beim Zeichnen** aus der Multiplizität ab.
Wird sie im Browser geändert und nicht gespeichert, liest «der Empfänger liest den aktuellen Zustand
selbst» **den alten**. A funktioniert nur *nach* dem Speichern — und der Nicht-gespeichert-Fall muss
existieren, weil automatisches Speichern umschaltbar sein soll.

### Warum `old` nicht ins Ereignis gehört

Es hat schon eine Heimat. Das Änderungsbuch hält Vorher und Nachher samt Änderungsnummer — **gemessen:
22 408 Zeilen, davon 9 084 mit Vorher-Zustand und 22 346 mit Nachher-Zustand**. Rückgängig und
Geschichte lesen dort. Ein Empfänger, der ohnehin neu ableitet, braucht `old` nie.

**Zwei Heimaten für eine Tatsache ist der teuerste Fehler dieses Projekts** — an einer einzigen Naht
achtmal gezählt.

### Eine Falle in `oldValue`/`newValue` selbst

«Der Wert» ist nicht ein Ding, sondern drei, je nach Adressform:

* ein **Blattwert** ist ein typisierter Wert,
* ein **Verweis** ist eine Knoten-Id,
* ein **Teil** ist eine **Satz-Id**.

Ein `old`/`new` mit zwei Satz-Ids sagt nichts darüber, was sich *im* Teil geändert hat. `old`/`new` ist
also nur für einen **Blattwert** sinnvoll — ein weiteres Argument für C, und ein Grund, die
Ereignisarten nach der **Adressform** zu schneiden statt nach Gefühl.

### Eine vierte Möglichkeit, die zum Gebauten passt

**Die Seite ist ein einziges Formular.** Name, Sorte, Labels, Feldnamen, Multiplizitäten und Werte
hängen alle an einem `<form>` und werden zusammen abgeschickt. Der ungespeicherte Zustand ist also
nicht verstreut, sondern **eine Serialisierung** — und der Kern liest genau diese Form heute schon beim
Speichern.

> **D — Identität + der Formularzustand, in dem es geschah.** Dann ist `new` nicht nötig, weil es darin
> steht, und `old` erst recht nicht: der Kern **leitet ab**, statt zu transportieren.

*Preis:* das ganze Formular reist mit. Bei sechzig Zeilen ist das viel — also nur für Ereignisse, bei
denen ungespeicherter Zustand überhaupt zählt. Womit man wieder bei C landet, aber mit einer
Begründung, die auch für das nächste Ereignis trägt.

### Vorschlag

| Ereignis | trägt | warum |
|---|---|---|
| `record.saved` | Identität + `change_group_id` | steht im Speicher, der Empfänger liest |
| `field.changed` (ungespeichert) | Identität + neuer Wert | nur der Browser weiss es |
| eine **Abhängigkeitsfrage** | Identität + Formularzustand | die Antwort hängt an mehr als einem Feld |
| alles rückblickende | nichts | dafür ist das Änderungsbuch da |

---

## 3 · Drei Festlegungen, die seither dazugekommen sind

### 3.1 Körnung: die **Zeile**, nicht das Feld

Die Kette lautet: Multiplizität ändert sich → das Wertfeld daneben braucht eine leere Wahl. Damit muss
irgendwer wissen: *«das Wertfeld hängt an der Multiplizität»*. Heute steht dieses Wissen an **einer**
Stelle, in einer Zeile im Kern.

Sagt der Browser «Feld X hat sich geändert, zeichne Feld Y neu», kennt er die Abhängigkeit — und sie
steht zweimal da. Sagt er **«diese Zeile hat sich geändert»** und der Kern antwortet mit der **ganzen
Zeile**, braucht niemand eine Abhängigkeitskarte.

**Der Kern braucht dafür kein neues Wissen.** Gemessen an genau dem genannten Fall:

| Multiplizität | darf nichts sein | leere Wahl im Kasten |
|---|---|---|
| `1..*` | nein | **nein** |
| `0..*` | ja | **ja** |
| `1..1` | nein | nein |
| `0..1` | ja | ja |

Ohne jede Möglichkeit: bei `1..*` **gesperrt ohne** leere Wahl (das Modell ist nicht erfüllbar), bei
`0..*` **gesperrt mit** leerer Wahl (nichts ist eine gültige Antwort). Das steht und leitet der Kern
schon ab. **Es fehlt nur der Kanal.**

### 3.2 Die Projektion trägt **gezeichnetes Markup**, keine Zustandsbeschreibung

Eine Beschreibung («gesperrt, drei Optionen, nichts erlaubt») bräuchte einen Zeichner im Browser — und
dann gäbe es die Zeichnung zweimal. Das ist genau das Vermischen, das ausgeschlossen ist.

### 3.3 Löschen gibt es nicht — es wird **aufgehoben**

*«Löschen tun wir ja eh nicht, wir schieben es in die Schattentabelle.»* Das ist gebaut: vor jedem
Entfernen werden die Zeilen nach `<tabelle>_history` geschrieben. Ein Ereignis über eine Entfernung
beschreibt also **eine Verschiebung**, nicht eine Vernichtung — und der alte Zustand ist danach
weiterhin lesbar, was ein weiterer Grund ist, ihn nicht im Ereignis mitzuschleppen.

---

## 4 · Frage: soll das Änderungsbuch mit Ereignissen gefüllt werden?

*Der Gedanke war: Empfänger melden sich an und bekommen Ereignisse — das passt fast perfekt für ein
Änderungsbuch.*

### Kurz

**Die Richtung umdrehen: das Änderungsbuch stösst Ereignisse aus, es hört nicht zu.**

### Warum — das ist das entscheidende Argument

Das Änderungsbuch ist eine **Zusicherung**, keine Reaktion. Heute wird es im **selben Codeweg**
geschrieben wie die Änderung — man *kann* nichts ändern, ohne es aufzuzeichnen. Schreibt es ein
Empfänger, hängt die Geschichte daran, dass jemand angemeldet ist und nicht scheitert.

> **Beweismittel, das davon abhängt, dass jemand zugehört hat, ist kein Beweismittel.**

Das ist hier nicht theoretisch: «geschrieben und nicht gelesen» wurde an einer einzigen Naht achtmal
gefunden. «Aufgezeichnet, falls jemand zuhörte» ist dieselbe Form, eine Stufe schlimmer.

### Umgekehrt ist es sehr attraktiv

Die Zeile im Änderungsbuch **ist fast schon die Nutzlast aus Abschnitt 2**:

| Spalte | Rolle im Ereignis |
|---|---|
| `owner_id` + `owner_kind` | die Adresse |
| `by_user_id` | der Handelnde |
| `change_group_id` | die Ursachenkette |
| `before_state` / `after_state` | Vorher und Nachher |
| `at` | die Zeit |

Eine Stelle weiss «etwas hat sich geändert», und jeder Empfänger bekommt einen vollständigen,
geordneten, schon gruppierten Strom.

### Aber es kann nur Vergangenheitsform tragen

| | Quelle | Zeitform |
|---|---|---|
| **Absicht** | die Fläche | «ich tue gerade» |
| **Projektion** | das Änderungsbuch | «es ist geschehen» |

*«Ich habe die Multiplizität auf `0..*` gestellt und noch nicht gespeichert»* ist kein Eintrag im
Änderungsbuch und wird nie einer. Keine Schwäche, sondern eine Trennung — aber es heisst, dass die
beiden Ströme sich überschneiden und **nicht dasselbe** sind.

### Drei gemessene Lücken, die vorher zu müssen

1. **Datensatzänderungen stehen gar nicht drin.** *`owner_kind` kennt `node` (16 813), `relation`
   (5 589), `installation` (3) und `gone` (3) — **kein einziges `record`**. Und die Klasse, die
   Datensätze schreibt, ruft das Änderungsbuch nirgends auf: **jede Modelländerung ist aufgezeichnet,
   keine einzige Datenänderung.*** Als Ereignisquelle wäre das die Hälfte aller Ereignisse — und die
   Lücke darf nicht nebenbei geschlossen werden, weil ein Datenbuch anders wächst als ein Modellbuch.
2. **`what` ist ein Satz, kein Typ.** *41 verschiedene Werte, zusammengesetzt aus Verb und Schlüssel:
   `setting read_only set`, `setting converter set`, `setting range_max set` — die Liste wächst mit jedem
   neuen Einstellungsschlüssel.* Auf `setting.set` kann sich so niemand anmelden.
3. **`by_user_id` ist meist leer** — *2 929 von 22 408 Zeilen haben einen Benutzer; der Rest sind Saaten,
   Migrationen und Prüfläufe.* Als `actor` brauchbar, aber die Antwort lautet fast immer «System».

---

## 5 · Frage: an PSR-14 anlehnen?

**Festlegung: nicht übernehmen, nur ähnlich handeln.** Der Standard ist *Vorbild*, nicht Abhängigkeit —
und er darf in Code und Dokumentation **nicht** als «PSR-14» bezeichnet werden. *Eine
Konformitätsbehauptung, die niemand prüft, ist in diesem Projekt schon mehrfach als Beleg zitiert
worden, ohne je gestimmt zu haben.*

### Was die Form von selbst löst

**Die Ereignisklasse *ist* der Typ.** Statt `"setting read_only set"` als eine Zeichenkette gibt es eine
Klasse mit einer Eigenschaft `key`. Damit ist Lücke 2 aus Abschnitt 4 an der Wurzel erledigt — man kann
gar nicht anders.

**Die zwei Sorten Ereignis decken die zwei Hälften ab, ohne dass etwas erfunden wird:**

| Form | Hälfte |
|---|---|
| Meldung — Vergangenheitsform, nichts kommt zurück | **Projektion** |
| veränderbares Ereignis — der Aufruf gibt das Objekt zurück, der Empfänger hat es gefüllt | **Absicht** |

Das zweite ist der Kanal aus Abschnitt 2, als Rückgabewert statt als neuer Begriff.

**Und es passt zur Schichtenregel:** ein Verteiler und ein Verzeichnis der Empfänger, kein Rahmenwerk.
Der Kern darf beides definieren und benutzen, ohne WordPress zu kennen; das **Verzeichnis der
Empfänger** — die Stelle, die weiss, wer zuhört — gehört an den Rand.

### Drei Dinge, die die Form nicht löst

1. **Sie ist prozessintern und synchron.** Eine *Verteilung*, kein *Transport*. Der Grenzübergang
   Browser → Server bleibt HTTP und bleibt zu entwerfen. **Die Form ordnet, was passiert, nachdem die
   Anfrage in PHP angekommen ist.**
2. **Ein «stoppbares» Ereignis ist eine Gefahr für jede Zusicherung.** Darf ein Empfänger die Weitergabe
   abbrechen, kann er einen anderen stumm schalten. Zweites Argument dafür, dass das Änderungsbuch
   **ausstösst** und nicht zuhört.
3. **Die Reihenfolge der Empfänger ist unbestimmt.** Für eine Projektion egal; für alles, was genau
   einmal und in Reihenfolge geschehen muss, nicht.

### Zwei Dinge, die daran hängen

**Keine Laufzeit-Abhängigkeit.** *Gemessen: das Plugin hat heute **keine** — nur PHP ≥ 8.1, PHPUnit nur
für Tests.* In WordPress laufen alle Plugins in einem Prozess; zwei Fassungen desselben
Interface-Namens sind ein harter Fehler. «Keine Abhängigkeiten» ist eine Eigenschaft, die es zu
verlieren gibt, und sie wird nicht für drei Interface-Dateien verkauft.

**WordPress hat schon ein Ereignissystem** — `do_action` / `apply_filters`. Zwei davon in einem Plugin
wären genau das «zwei Heimaten für eine Sache», das an einer Naht achtmal zugeschlagen hat. Der
Zuschnitt: **eigener Verteiler innen, und *ein* Empfänger am Rand, der als `do_action('taxmod_…')`
weiterreicht.** Eine Brücke, kein zweites System — und andere Plugins können sich anhängen, was sie
sonst nicht könnten.

---

## 6 · Offene Fragen — der Stand

### Aus dem ersten Blatt, weiterhin offen

1. **Wer hört zu?** Beschreibt eine Zeile selbst, wovon sie abhängt, oder gibt es eine Stelle, die alle
   Abhängigkeiten kennt? *(Die Körnung «Zeile» aus 3.1 entschärft es, beseitigt es nicht: eine Änderung
   an Zeile A kann Zeile B betreffen.)*
2. **Wettläufe und Fehler.** Zwei Ereignisse überholen sich; die Antwort kommt nach der nächsten
   Änderung; das Netz ist weg. Was zeigt der Schirm dann?
3. **Ohne JavaScript.** Die Seite funktioniert heute vollständig ohne. Bleibt das so — Ereignisse als
   Verbesserung — oder wird JavaScript Voraussetzung?
4. **Wie wird es geprüft?** Es muss ein Wächter existieren, der rot wird, wenn Zeichner und
   Ereignisantwort auseinandergehen. Wie sieht der aus?
5. **Nachladen.** Ist «lade den Wertblock dieser Zeile» dasselbe Ereignis wie «diese Angabe hat sich
   geändert», oder zwei Dinge?

### Neu aus diesen Antworten

6. **Der Transport.** Die Form ordnet nur, was innerhalb von PHP geschieht. Wie kommt eine Absicht
   hinein und eine Projektion hinaus — eine eigene Route, `admin-post.php`, die REST-Schnittstelle? Und
   in welcher Verpackung reist das gezeichnete Stück aus Festlegung 3.2?
7. **Gehören Datensatzänderungen ins Änderungsbuch?** Heute stehen null drin. Die Antwort entscheidet,
   ob das Änderungsbuch als Ereignisquelle die Hälfte aller Ereignisse kennt.
8. **Wie wird `what` aufgespalten**, und was geschieht mit den 22 408 Zeilen in der alten Form? *Eine
   Umschreibung der Geschichte ist eine Änderung an Beweismitteln.*
9. **Wer darf ein Ereignis auslösen?** Nur der Rand, oder auch der Kern? *Ein Kern, der ausstösst, kann
   in eine Rückkopplung laufen; ein Kern, der es nicht darf, kann keine Folgeänderung melden.*
10. **Wie viele Ereignisse verträgt ein Speichern?** Ein Seitenspeichern schreibt heute Name, Sorte,
    Labels, Feldnamen, Multiplizitäten und Werte in **einer** Handlung mit **einer** Änderungsnummer.
    Wird das ein Ereignis oder dreissig — und was hört ein Empfänger dann?
11. **Umschaltbares automatisches Speichern: wo steht der Schalter?** *Es ist eine Einstellung, und
    Einstellungen sind in diesem Modell Felder an Knoten. Gilt das auch für eine Einstellung der
    Oberfläche, oder ist das eine WordPress-Option?*

---

## 7 · Arbeitshypothese: der Kern beschreibt, der Rand malt

> ⚠️ **Dies ist eine Arbeitshypothese, kein Faktum.** *Der Eigentümer hat sie ausdrücklich so
> eingeordnet. Sie ist zwischen ihm und Claude entstanden, ist **nicht ausgebaut** und **nicht
> geprüft**. Was daran entschieden ist, steht als [D-563](../NewConcept/90-decision-log.md); alles
> andere ist ein Vorschlag, gegen den man denken soll.*

Sie gehört hierher, weil sie das Ereigniskonzept **berührt**: wenn der Kern eine Beschreibung
zurückgibt statt HTML, dann kann eine Projektion diese Beschreibung tragen — und der Renderer kann
die **Absichten** gleich mitgeben.

### 7.1 Der Anlass, gemessen

Ein Renderer gibt heute eine **Zeichenkette HTML** zurück. Gemessen am 2026-08-31 im Kern:

| | |
|---|---|
| Dateien, die Tags schreiben | **28** |
| Tag-Literale | rund **245** |
| `dashicons-…`-Klassen | **14** |
| `style="…"` inline | **30** |
| `<div>` / `<tr>` | **29** / **13** |
| `htmlspecialchars()` statt `esc_html()` | 1 Stelle, mit dem Grund «der Kern darf nicht nach `esc_html()` greifen» |

`CD-1`s Buchstabe ist gewahrt — HTML ist keine WordPress-Funktion. **Der Sinn nicht: der Domänenkern
kennt den Icon-Satz von WordPress.**

### 7.2 Und der Grund steht seit dem 22. August im Konzept

[D-021](../NewConcept/90-decision-log.md), wörtlich:

> *«Weil der Gutenberg-Editor von Bauart React ist, sind seine **Bedienelemente metadatengetrieben**:
> der Editor bekommt die Attribute eines Knotens mit ihren Typen und Einstellungen und zeichnet sie
> mit **einem generischen Satz** von Steuerelementen … **Deshalb muss `RenderResult` die
> Attribut-Metadaten mitführen, die ein Renderer benutzt hat, und nicht nur fertiges Markup.»***

**Die Beschreibung ist also keine neue Richtung, sondern eine halb gebaute Entscheidung** — und ihr
Grund ist Gutenberg. Heute trägt `RenderResult` `usedEdges` als Metadaten, und dort hörte es auf.

### 7.3 Die drei Flächen — schon entschieden

[R8](../NewConcept/30-renderer.md) und [D-253](../NewConcept/90-decision-log.md): *Admin* sagt, was
ein Ding **ist**; der *Block* sagt, was eine Seite **zeigt**; das *Frontend* **zeichnet nur**.

[D-254](../NewConcept/90-decision-log.md): *«Blocks zeichnen auf dem Server, bei jedem Aufruf — auch
im Editor.»* Sonst zeigte eine Seite letztes Jahres Preis. **Also bedient ein PHP-Maler alle drei
Flächen**; JavaScript nur, wo Interaktion es verlangt, und es *«re-implementiert nie einen
Renderer»*.

**Die Ausnahme, die keine ist:** der generische Steuerelement-Satz des Editors zeichnet nicht einen
Renderer nach, sondern **eine Beschreibung**. Daraus folgt eine harte Anforderung: **die Beschreibung
muss als Daten reisen können.** Für den Admin genügte Markup; für den Block-Editor nicht.

Fürs Frontend: Interactivity API auf servergezeichnetem Markup.

### 7.4 Was der Kern sagen dürfte — und was tabu wäre

Die Regel des Eigentümers ist schärfer als «was, nicht wieviel». Sie lautet: **Bedürfnisse, keine
Werkzeuge** — *«genau die Worte Textarea oder Editor werden dann tabu, weil der Maler entscheiden
muss, wie er deine Wünsche umsetzt.»*

| Der Kern sagt | Der Kern sagt **nicht** |
|---|---|
| «eine Zahl», «ein Text», «ein Datum», «eine Wahrheit», «ein Verweis», «eine Wahl aus diesen Einträgen» | `input`, `select`, `textarea`, `checkbox`, `div`, `table`, `tr`, `td`, `span` |
| «gesetzt / nicht gesetzt / darf nicht leer sein / nicht bedienbar / nur ein Ausgang» | `disabled`, `checked`, `selected` |
| **Anweisungen**: «kann lang werden», «braucht Formatierung», «will stufenlos verstellt werden», «muss als Farbe sichtbar sein» | `editor`, `range`, `color`, `number` |
| **Gruppierungen**: «das gehört zusammen», «bitte in einen Kasten», «eine Tabelle von Datensätzen», «eingeklappt», und der Titel dazu | Breiten, Höhen, `cols`, `rows`, Pixel, `style`, CSS-Klassen |
| ein Icon **unter unserem Namen** | `dashicons-…` |

### 7.5 Anweisung, Rückmeldung, Bauplan

Der Eigentümer: *«man gibt ihm schon eine Anweisung, ne, zeichne das. Und wenn er dieser Anweisung
nicht Folge leisten kann, dann muss er das mitteilen. Und dann sieht man das auch: kann an dieser
Stelle nicht dargestellt werden, weil Werkzeug fehlt.»*

**Das ist keine neue Regel, sondern [R14b](../NewConcept/30-renderer.md) eine Ebene höher** — dort
markiert sich der Renderer-Rückfall selbst, statt still zu ersetzen. Drei Schritte:

1. Der Kern **weist an**.
2. Kann der Maler nicht, **meldet er es sichtbar**.
3. Dann bekommt er einen **Bauplan** — einen neuen Pinsel.

⚠️ *Ein unverbindlicher «Wunsch» wäre gefährlich: still ignoriert, und der Autor merkt es nur daran,
dass es anders aussieht. **Das ist «geschrieben und nicht gelesen» in neuer Verkleidung**, und dieses
Muster wurde an einer einzigen Naht achtmal gefunden.*

### 7.6 Eine Folgerung, die noch nicht entschieden ist

**Ein Werkzeugname darf *Daten* sein — die Wahl des Autors. Er darf nie *Code* sein.**

Damit bleibt `render = slider` als Einstellung bestehen; was ginge, ist ein `SliderRenderer`, der
`type="range"` schreibt. Und daraus folgt etwas Weitergehendes:

> **Die Renderer-Liste wäre die Palette des Malers.**

Heute kommt sie aus dem Kern (`ShippedRenderers::namesForNodes()` legt die Knoten unter `Renderer`
an). In dieser Hypothese weiss **der Maler**, welche Pinsel es gibt — ein anderes Rahmenwerk hat
andere, also bekommt der Autor andere Wahlmöglichkeiten. *Die Verengung «nur was der Typ verträgt»
gibt es schon (`eligibleFor`); «nur was der Maler kann» wäre dieselbe Mechanik.*

### 7.7 Was für einen Umbau gemessen wurde

* **Die Reihenfolge ist umgekehrt zur Intuition:** ein **Behälter** als Beschreibung kann ein bereits
  gemaltes Kind tragen; ein **Blatt** als Beschreibung kann keinen unbeschriebenen Behälter tragen.
  Also **Behälter zuerst, Blätter danach** — und das ist `PR-12`s Ordnung: der Maler zuerst.
* **Zwei Fragen davor**, sonst werden 28 Dateien zweimal angefasst: beschreibt der Kern die
  **Anordnung** überhaupt, und gilt `R1` auch für Maler (**einer je Ausgabeform**)?
* **Nichts geht verloren.** Der Schalter, der Wähler-Dialog, das Ausgrauen — alles darf bleiben, es
  zieht nur an den Rand. Wo WordPress etwas Eigenes hat, nimmt der Maler es, statt es nachzubauen.

### 7.8 Warum das hier steht und nicht nur in der Arbeitsliste

Weil es **eine** Frage aus Abschnitt 6 entscheidet und **eine** neue stellt.

*Entschieden:* was eine Projektion trägt. Für den Admin genügte Markup, für den Block-Editor nicht —
**also eine Beschreibung.**

*Neu:* wenn die Beschreibung die **Absichten** mitträgt, dann sind Zeichnung und Ereignis **nicht
getrennt entwerfbar**. Der Eigentümer: *«das ist eng verstrickt mit dem Action-, Event-,
Listener-Framework, weil der Renderer bereits die Nachrichten kennt und sie mitgeben kann, damit im
Rand ein Event daraus wird.»* Und er weiss es wirklich — er kennt beim Zeichnen die Kante, den
Datensatz und den Feldnamen. **Das ist genau die Adresse.**

⚠️ *Preis, der benannt sein muss: eine Beschreibung mit Absichten ist **nicht mehr rein
beschreibend** — sie nennt Verhalten beim Namen. Diese Namen müssen ein geschlossener,
aufgeschriebener Satz sein, sonst laufen Kern und Maler auseinander. Dieselbe Falle, die das
Änderungsbuch mit `what` als Satz statt als Typ hat (siehe [`datenmodell.md`](datenmodell.md),
Abschnitt 6).*
