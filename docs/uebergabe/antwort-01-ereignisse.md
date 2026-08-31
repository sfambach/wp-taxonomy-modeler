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

## 3 · Zwei Festlegungen, die seither dazugekommen sind

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

## 4 · Was ein Entwurf weiterhin beantworten muss

Unverändert gültig aus dem ersten Blatt, hier nur die, die durch die zwei Antworten **nicht** erledigt
sind:

1. **Wer hört zu?** Beschreibt eine Zeile selbst, wovon sie abhängt, oder gibt es eine Stelle, die alle
   Abhängigkeiten kennt? *(Die Körnung «Zeile» entschärft es, beseitigt es aber nicht: eine Änderung an
   Zeile A kann Zeile B betreffen.)*
2. **Wettläufe und Fehler.** Zwei Ereignisse überholen sich; die Antwort kommt nach der nächsten
   Änderung; das Netz ist weg. Was zeigt der Schirm dann?
3. **Ohne JavaScript.** Die Seite funktioniert heute vollständig ohne. Bleibt das so — Ereignisse als
   Verbesserung — oder wird JavaScript Voraussetzung?
4. **Wie wird es geprüft?** Es muss ein Wächter existieren, der rot wird, wenn Zeichner und
   Ereignisantwort auseinandergehen. Wie sieht der aus?
5. **Nachladen.** Ist «lade den Wertblock dieser Zeile» dasselbe Ereignis wie «diese Angabe hat sich
   geändert», oder zwei Dinge?
