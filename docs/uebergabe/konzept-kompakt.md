# wp-taxonomy-modeler — kompaktes Konzept zur Übergabe

**Zweck dieses Dokuments.** Es fasst zusammen, was gebaut ist und nach welchen Regeln, damit ein
Aussenstehender darauf ein **Ereigniskonzept** entwerfen kann. Es ist absichtlich kurz und
absichtlich ohne Entwicklungsgeschichte.

**Status dieses Dokuments.** Es ist **abgeleitet, nicht Quelle**. Die Quelle ist
[`docs/NewConcept/`](../NewConcept/README.md); bei jedem Widerspruch gilt dort. Dieses Blatt darf
nicht als Beleg zitiert werden und wird nach der Übergabe nicht gepflegt.

**Stand:** 2026-08-31 · 552 Entscheidungen, 141 Fragen, davon 7 offen.

---

## 1 · Was das Ding ist

Ein WordPress-Plugin, mit dem ein Mensch **Datenmodelle baut** und **Daten dazu eingibt** — ohne
Code. Es beschreibt Dinge (Bauteile, Rezepte, Einheiten, Adressen), ihre Felder, ihre Beziehungen,
und es zeichnet Eingabe- und Anzeigeflächen dafür.

Zwei Ebenen, die nie vermischt werden:

| Ebene | Was dort passiert |
|---|---|
| **Modell** | Ein Autor sagt: «ein Bauteil hat eine Position, die ist eine Zahl, sie kommt 0..n vor, sie wird als Schieber gezeichnet.» |
| **Daten** | Ein Benutzer sagt: «diese Position ist 3.» |

Auf Modellebene gibt es **keine Werte, nur Vorgaben** (`default`). Das ist wichtig für alles, was
folgt: die Einstellungen des Modells sind selbst Daten, gespeichert wie Daten, gelesen wie Daten.

---

## 2 · Schichten

Zwei Schichten, harte Grenze.

| Schicht | Inhalt | Konvention |
|---|---|---|
| **Rand** (`src/WordPress/`) | Hooks, REST, Adminseiten, Aktivierung, Blocks | WordPress: Capabilities, Nonces, `sanitize_*`, `esc_*`, `$wpdb` |
| **Kern** (`src/Core/`) | Domänenmodell, Repositories, Renderer, Validatoren, Konverter | PHP 8, `strict_types`, PSR-4, getypt |

**Der Kern ruft keine WordPress-Funktion.** WordPress greift in den Kern hinein, nie umgekehrt.
Der Kern ist ohne WordPress-Bootstrap testbar — der Kern-Testlauf (426 Tests) lädt kein WordPress;
ein zweiter Lauf prüft den Rand gegen eine echte Datenbank (rund 1000 Zusicherungen).

Am Rand gilt immer dieselbe Reihenfolge: **Capability → Nonce → validieren → säubern → handeln →
beim Ausgeben escapen.**

---

## 3 · Datenmodell — vier Tabellen

Alles liegt in eigenen Tabellen, **nicht** in WordPress-Posts, -Postmeta oder -Terms.

```
nodes           Knoten            id, name, path, kind, version
relations       Kante             id, from_id, to_id, kind, name, multiplicity, position, hide, version
records         Knoten-Datensatz  id, node_id, node_version, kind, created_at, version
record_values   Kanten-Datensatz  id, record_id, edge_id, path, locale, value_*, position, version
```

Die Begriffe sind verbindlich: **Knoten**, **Kante**, **Knoten-Datensatz**, **Kanten-Datensatz**.
Synonyme sind verboten, weil sie in Gesprächen sofort Missverständnisse erzeugen.

### 3.1 Knoten

* `path` ist ein **materialisierter Pfad** aus Ids: `1.406.410`. Die Wurzel ist `1`.
  Der Pfad macht «alle Nachfahren» und «alle Vorfahren» zu einer Abfrage.
* `name` ist **absichtlich nicht eindeutig**. Es gibt drei Knoten namens `form`.
  Konsequenz: **nichts wird über einen Namen gefunden**, wenn eine Id verfügbar ist.
  Jeder `LIKE name … LIMIT 1` in diesem Projekt war ein Fehler.
* `kind` markiert einen Knoten fachlich, unter anderem als `setting`.

### 3.2 Kante

Eine Kante ist entweder eine **Vererbung** (`inheritance`) oder ein **Feld**. Feldarten:
`composition`, `aggregation`, `setting`.

* `multiplicity` ∈ `{0..1, 1..1, 0..*, 1..*}` — die ganze Liste, vier Konstanten.
* `position` ist die **einzige** Heimat der Reihenfolge. Ein Knoten ordnet sich über seine
  Vererbungskante, ein Feld über seine Feldkante — dieselbe Spalte, andere Geschwisterliste.
* `hide` ist eine **Spalte auf der Kante**, keine Einstellung. Eine versteckte Kante wird auf
  keiner Ebene gezeichnet, weil der Abstieg sie nie erreicht.
* Vererbung ist echt: ein Feld, das an einem Vorfahren erklärt ist, gilt am Nachfahren. Geändert
  wird es **nur dort, wo es erklärt ist** — sonst würde eine Änderung an einer Stelle für alle
  gelten, still.

### 3.3 Datensätze

* Ein **Knoten-Datensatz** gehört einem Knoten. Sorte `default` (Modellebene: Vorgaben) oder
  `user` (Datenebene: echte Werte). Genau **ein** `default`-Satz je Knoten.
* Ein **Kanten-Datensatz** ist eine Wertzeile in einem Knoten-Datensatz. Sie nennt die Kante
  (`edge_id`), ihre Adresse (`path`) und den Wert in einer typisierten Spalte.

---

## 4 · Adressierung — die drei Formen

Das ist die Stelle, an der die meisten Fehler entstanden sind. Drei verschiedene Dinge:

| Form | Bedeutung | Beispiel |
|---|---|---|
| **`edge_id`** | Welche Kante der Wert beantwortet | `44091` |
| **`path`** | Die Kette von Kanten-Ids **innerhalb eines Datensatzes** | `13954.44093` |
| **`value_ref`** | Der Sprung **zwischen** Datensätzen, oder ein Knotenverweis | Satz-Id bzw. Knoten-Id |

Regeln daraus:

1. Ein Wert, der direkt an seiner Kante steht, hat `path === edge_id`.
2. Ein Wert an einer **Verwendungsstelle** hat die Kante der Stelle davor:
   `path = "<Stelle>.<Kante>"`.
3. Ein **Teil** (ein eigener Datensatz für ein zusammengesetztes Feld) wird über seine
   **Satz-Id** angesprochen, niemals über einen zusammengesetzten Pfad. Bei mehreren Teilen
   tragen alle *dieselben* Kanten — nur der Satz unterscheidet sie.

Praktische Folge: ein Formularfeld für einen Wert in einem Teil heisst
`taxmod_part[<Satz-Id>][<Kanten-Id>]`, eines für einen Wert am Knoten
`taxmod_value[<Kanten-Id>]` bzw. `taxmod_value[<aussen>][<innen>]`.

---

## 5 · «Alles ist ein Feld»

Der zentrale Vereinfachungsschritt: **eine Einstellung ist kein eigener Mechanismus, sondern ein
Feld.** Es gab eine `settings`-Tabelle; sie ist im Abbau (heute 5 Reste).

* Eine Einstellung ist eine **Kante der Art `setting`** von einem Knoten auf einen Zielknoten.
* Ihr Wert liegt im **`default`-Datensatz** des Knotens, an der oben beschriebenen Adresse.
* Damit erbt eine Einstellung wie jedes Feld, und der Eingabemechanismus ist die gewöhnliche
  Eingabefläche — es gibt keine zweite Art, eine Einstellung zu setzen.

Zwei Regeln bestimmen, wie eine Einstellung aussieht:

* **Auswahl oder Eingabe:** ein Feld ist eine **Auswahl**, wenn sein Zielknoten sichtbare,
  unmarkierte Kinder hat; sonst eine Eingabe. «Markiert» heisst `kind = setting`; die Auswahl
  schaut **durch** markierte Gruppierungsknoten hindurch.
* **Zusammengesetzt oder Verweis:** hat das Ziel eigene Felder, bekommt der Wert einen **eigenen
  Datensatz** (einen Teil); hat es nur Kinder, ist der Wert ein **Knotenverweis**.

Beispiel, das im Betrieb ständig auftritt: die Einstellung `Display Option` an der Wurzel hat
Multiplizität `1..*` und zeigt auf einen Knoten `DisplayOption` mit zwei Feldern, `render` (`1..1`,
zeigt auf den Ast `Renderer`) und `converter` (`0..1`, zeigt auf `Converter`). Also:

* Ein Knoten kann **mehrere** Renderer haben — über mehrere `DisplayOption`-Teile.
* Jeder Teil hat **genau einen** Renderer.
* Der Grund für die Mehrzahl ist das **Farbschema**: dieselben Daten, verschieden gezeichnet.

---

## 6 · Zeichnen — die Renderer-Kette

### 6.1 Ein Renderer

Ein Renderer bekommt einen `Renderable` (einen Knoten, eine Kante, einen Datensatz) und einen
`RenderContext` und **gibt eine Zeichenkette zurück**. Er gibt nichts aus (`echo` ist verboten).
Ein Renderer darf andere Renderer aufrufen.

`RenderContext` trägt unter anderem:

| Feld | Bedeutung |
|---|---|
| `purpose` | `Display` · `Edit` · `Search` |
| `level` | `Display` · `Admin` · `Settings` (drei Ebenen, drei Vorschauseiten) |
| `value` | der Wert, der gezeichnet wird |
| `settings` | die aufgelösten Angaben zu diesem Subjekt |
| `editable` | darf hier bearbeitet werden (z. B. nur, wo die Kante erklärt ist) |
| `fieldName` | der Formularname, unter dem ein Wert zurückkommt |
| `surroundings` | Umstände: Optionen, `mayBeNothing`, `formId`, Bedienelemente, Teile, Abschnitte |

### 6.2 Der Abstieg

Eine Fläche wird gezeichnet, indem der Abstieg vom Knoten aus über seine Felder läuft, für jedes
Feld einen Renderer wählt und die Ergebnisse einsetzt. Der Abstieg lädt das Modell **je Ebene in
einer Abfrage**, nicht je Zeile. Kein N+1: es gibt kein SQL in einer Schleife und keine
rekursive Funktion, die pro Ebene fragt.

Renderer-Wahl, in dieser Reihenfolge:

1. Was das Modell sagt (die Einstellung `render` am Knoten oder an der Verwendungsstelle).
2. Sonst: welcher Renderer den **Typ** verträgt (`eligibleFor`).
3. Sonst: ein Rückfall, der sich selbst als Rückfall markiert.

Was angeboten wird, ist die Schnittmenge aus «was das Modell als Möglichkeit führt» und «was
dieser Typ verträgt». Gemessene Beispiele: `Integer` → `field, slider, spinner`;
`Boolean` → `checkbox, toggle`. Ein **schon gespeicherter** Wert bleibt stehen, auch wenn er heute
nicht mehr angeboten würde — Angebot, kein Zaun.

### 6.3 Die Regel für Bedienelemente

Diese Regel ist für ein Ereigniskonzept die wichtigste, weil sie **Zustand aus Umständen
ableitet**:

> Niemals eine Entscheidung vorlegen, die die Umstände schon getroffen haben.

Ausgerechnet als: `Ausgänge = Anzahl der Möglichkeiten + (darf es nichts sein ? 1 : 0)`

| Ausgänge | Bedienelement |
|---|---|
| 0 | **gesperrt** und **markiert** — das Modell ist nicht erfüllbar |
| 1 | **vorausgewählt und ausgegraut** — dieser Ausgang *ist* die Antwort |
| > 1 | ein echtes Bedienelement |

Ob *nichts* ein Ausgang ist, folgt aus der **Multiplizität**: `0..1` ja, `1..1` nein.
Und die Multiplizität wird durchgesetzt, nicht bloss angezeigt: bei `1..1` muss ein Wert gesetzt
sein, bei `1..*` muss ein Datensatz existieren.

Ein gesperrtes Bedienelement schickt **nichts** ab. Das ist beabsichtigt und wird beim Lesen
vorausgesetzt: ein Schlüssel, der im POST ankommt, war ein Bedienelement, das dastand.

### 6.4 Eine Art, eine Sache zu zeichnen

Jede Fläche, die Modelldaten zeigt — Adminschirm, Block, Tabelle, Formular, Export,
REST-Antwort — geht durch den Renderer-Vertrag. **Kein zweites Steuerelement für dieselbe
Angabe**, kein handgeschriebenes HTML daneben. Diese Regel ist mehrfach verletzt und jedes Mal
teuer geworden.

---

## 7 · Der Adminschirm, wie er heute aussieht

Eine Seite: links der Baum, rechts die Detailansicht des gewählten Knotens.

Die Detailansicht besteht aus Blöcken:

| Block | Inhalt |
|---|---|
| **Kopf** | Name, Sorte, Aktionen, **ein** Speichern-Knopf für die ganze Seite |
| **Labels** | die Texte des Knotens je Rolle und Sprache |
| **Fields** | Tabelle: die Datenfelder des Knotens |
| **Settings** | dieselbe Tabelle, für die Einstellungskanten |
| **Preview** | drei Seiten — Anzeige, Admin, Einstellungen |
| **Records** | die Datensätze des Knotens |

Eine Zeile in *Fields*/*Settings* hat die Spalten: Name · Zeigt auf · Art · Herkunft · Wie oft ·
**Wert** · Aktionen.

### 7.1 Ein Formular für die Seite

HTML verbietet ein `<form>`, das Tabellenzellen umschliesst. Gelöst über `form="…"`: jedes
Bedienelement **nennt** das Formular der Seite, auch wenn es ausserhalb steht. Damit gibt es
**einen** Speichern-Knopf für Name, Sorte, Labels, Einstellungen, Feldnamen, Multiplizitäten und
Werte.

Daraus folgen zwei Dinge, die ein Ereigniskonzept respektieren muss:

1. **Jeder Feldname muss über die ganze Seite eindeutig sein**, sonst gewinnt die letzte Zeile.
   Namen tragen darum Ids: `taxmod_field_setting[<Kanten-Id>][multiplicity]`.
2. **Ein `required` in einer von sechzig Zeilen sperrt das Speichern der ganzen Seite.** Deshalb
   steht überall `aria-required` und die Pflicht wird im Kern geprüft, nicht vom Browser
   erzwungen.

### 7.2 Ein POST ist eine Handlung

Ein abgeschicktes Formular ist **eine** Handlung und bekommt **eine** Änderungsnummer. Alles, was
darin geschrieben wird — Umbenennung, Multiplizität, Werte, Texte — gehört zu derselben Nummer.
Danach wird umgeleitet (POST → Redirect → GET).

Geschrieben wird nur, **was sich geändert hat**. Sonst wäre jedes Speichern einer unberührten
Seite sechzig Einträge im Änderungsbuch.

Ein **leeres Feld ist eine Wahl**: es nimmt den Wert heraus. Nicht «nichts tun», sondern
«unbeantwortet».

---

## 8 · Was heute fehlt — der Anlass für das Ereigniskonzept

Der Schirm ist heute **vollständig serverseitig gezeichnet** und hat kein nennenswertes
JavaScript ausser Faltzuständen. Alles, was ein Bedienelement über sich weiss, wird **beim
Zeichnen** entschieden.

Das bricht an drei Stellen, alle vom Eigentümer gefunden:

### 8.1 Eine Angabe hängt von einer anderen ab

Die Multiplizität einer Kante entscheidet, ob das Wertfeld daneben *nichts* anbieten darf. Stellt
jemand die Multiplizität im Browser von `1..1` auf `0..1`, **weiss das Wertfeld davon nichts** —
es zeigt erst nach dem Speichern die leere Wahl. Dasselbe gilt für die Pflichtmarkierung.

Allgemein: `mayBeNothing`, die Liste der Optionen, «gesperrt/ausgegraut/echt», die Pflicht — alles
Ableitungen aus dem Modellzustand, und der Modellzustand ändert sich im Browser, bevor er
gespeichert ist.

### 8.2 Etwas soll erst geladen werden, wenn es gebraucht wird

Die Wertspalte zeichnet heute bei jedem Seitenaufruf den ganzen Abstieg — bei einem Feld auf eine
Adresse sind das fünf Eingabefelder in einer Tabellenzelle, bei mehreren Teilen zwei je Teil. Der
Wunsch: die Werte in den bestehenden Ausklapp-Bereich verschieben und **erst beim Ausklappen
laden**. Ebenso der Datensatz-Block. Das ist der erste Punkt, an dem der Schirm etwas
**nachfordern** muss.

### 8.3 Eine Anzeige hängt von einer Wahl an anderer Stelle ab

Beispiel: ist an einem Feld ein Konverter gesetzt (etwa «römisch»), müsste der Wert in dieser
Notation dargestellt werden — auch im Schieber, der heute vermutlich nicht einmal seinen Wert
anzeigt. Die Notation ist Sache des Konverters, die Struktur die des Renderers; beide leben im
Kern.

---

## 9 · Randbedingungen für einen Entwurf

Ein Ereigniskonzept muss mit diesen Bedingungen leben. Sie sind nicht verhandelbar, weil jede
einzelne aus einem konkreten Schaden entstanden ist.

1. **Die Regel wohnt im Kern, nicht im JavaScript.** Was angeboten wird, ob *nichts* erlaubt ist,
   ob gesperrt wird — das sagt der Kern. Der Browser darf **fragen**, aber nicht selbst
   entscheiden. Eine zweite Regel im JavaScript ist zwei Wahrheiten über eine Sache, und die
   laufen auseinander.
2. **Eine Art, eine Sache zu zeichnen.** Wenn ein Bedienelement nach einem Ereignis neu gezeichnet
   wird, muss es von **demselben** Renderer kommen wie beim ersten Mal. Kein Zwilling im
   Browser.
3. **Der Kern kennt kein WordPress.** Ein Endpunkt, der Ereignisse beantwortet, gehört an den
   Rand; die Entscheidung darin gehört in den Kern.
4. **Am Rand: Capability → Nonce → validieren → säubern → handeln → escapen.** Auch für einen
   Endpunkt, der «nur» ein Bedienelement neu zeichnet. Auch für lesende Anfragen.
5. **Eine Seite ansehen darf nichts schreiben.** Kein Datensatz, kein Teil, keine Zeile entsteht
   beim Zeichnen. Wenn ein Ereignis einen Teil braucht, muss klar sein, ob es schreibt.
6. **Kein N+1.** Ein Ereignis, das zehn Zeilen betrifft, darf nicht zehn Anfragen auslösen.
7. **Ein Formular, eindeutige Namen.** Ein nachgeladenes Bedienelement muss dem Seitenformular
   beitreten und einen Namen tragen, der noch nicht vergeben ist.
8. **Ungespeicherter Zustand ist real.** Der Browser kennt Werte, die der Server nicht hat. Ein
   Entwurf muss sagen, wer bei einer Frage die Wahrheit hält — und wie ungespeicherte
   Änderungen mitreisen, ohne dass ein Zeichnen zum Speichern wird.
9. **Nichts benutzerseitig Sichtbares ist hart verdrahtet.** Softwaretexte gehen durch den
   WordPress-Textbereich; die Namen der Modellknoten sind Labels im Modell, je Sprache. Die zwei
   Mechanismen teilen sich nie etwas.
10. **Wenn Daten umziehen, zieht der Leser zuerst — und es muss eine Prüfung geben, die rot wird,
    wenn nur eines von beidem wandert.** Diese Regel wurde an einem Abend achtmal verdient. Jedes
    Mal sah der Schirm richtig aus und zeigte etwas Falsches, und keine der Prüfungen bemerkte es.

---

## 10 · Die Fragen, die ein Entwurf beantworten sollte

1. **Was ist ein Ereignis?** Eine Änderung an einem Bedienelement? Eine Änderung an einer Angabe
   des Modells? Beides — und wie hängen sie zusammen?
2. **Wer hört zu?** Beschreibt eine Zeile selbst, wovon sie abhängt («mein Wertfeld hängt an
   meiner Multiplizität»), oder gibt es eine Stelle, die alle Abhängigkeiten kennt? Die erste
   Antwort ist lokal und läuft leichter auseinander; die zweite ist eine neue zentrale Instanz.
3. **Was geht über die Leitung?** Der neue HTML-Schnipsel eines Bedienelements? Eine Beschreibung
   seines Zustands («gesperrt, drei Optionen, nichts erlaubt»)? Das erste ist einfach und bindet
   die Darstellung an den Verkehr; das zweite trennt sauberer und braucht einen Zeichner im
   Browser — womit Bedingung 1 und 2 in Gefahr sind.
4. **Wie reist ungespeicherter Zustand mit?** Nur die geänderte Angabe, oder das ganze Formular?
   Und wie wird verhindert, dass eine Frage als Speichern gedeutet wird?
5. **Wie sieht Nachladen aus?** Ist «lade den Wertblock dieser Zeile» dasselbe Ereignis wie
   «diese Angabe hat sich geändert», oder zwei Dinge?
6. **Was passiert bei Fehlern und Wettläufen?** Zwei Ereignisse überholen sich; die Antwort kommt
   nach der nächsten Änderung; das Netz ist weg. Was zeigt der Schirm dann?
7. **Was, wenn kein JavaScript läuft?** Die Seite funktioniert heute vollständig ohne. Soll das so
   bleiben — Ereignisse als Verbesserung — oder wird JavaScript Voraussetzung?
8. **Wie wird es geprüft?** Bedingung 10 verlangt einen Wächter, der rot wird, wenn Zeichner und
   Ereignisantwort auseinandergehen. Wie sieht der aus?

---

## 11 · Was ein Entwurf nicht vorschlagen sollte

* Ein Frontend-Rahmenwerk, das den Schirm übernimmt. Der Schirm ist eine WordPress-Adminseite und
  soll eine bleiben.
* Eine Zustandsverwaltung im Browser, die das Modell spiegelt. Das wäre eine zweite Heimat für
  eine Sache.
* Regeln im JavaScript, die es im Kern auch gibt.
* Einen zweiten Speicherweg neben dem Seitenformular.
* Alles, was ein Zeichnen zum Schreiben macht.

---

## 12 · Kurzes Wörterbuch

| Wort | Bedeutung |
|---|---|
| **Knoten** | Ein Ding im Modell. Zeile in `nodes`. |
| **Kante** | Vererbung oder Feld zwischen zwei Knoten. Zeile in `relations`. |
| **Knoten-Datensatz** | Eine Ausfüllung eines Knotens. Zeile in `records`. Sorte `default` oder `user`. |
| **Kanten-Datensatz** | Eine Wertzeile darin. Zeile in `record_values`. |
| **Feld** | Eine Kante, die keine Vererbung ist. |
| **Einstellung** | Ein Feld der Art `setting`. Kein eigener Mechanismus. |
| **Verwendungsstelle** | Eine Kante, betrachtet als «hier wird dieser Typ benutzt». Trägt eigene Angaben. |
| **Teil** | Ein eigener Datensatz für ein zusammengesetztes Feld. Adressiert über seine Satz-Id. |
| **Abstieg** | Der Lauf vom Knoten über seine Felder, der eine Fläche zeichnet. |
| **Vorgabe** (`default`) | Was das Modell sagt, wenn niemand etwas eingegeben hat. |
| **Multiplizität** | `0..1`, `1..1`, `0..*`, `1..*`. Entscheidet auch, ob *nichts* eine Antwort ist. |
| **Ebene** (`level`) | `display`, `admin`, `settings`. Bestimmt, was gezeichnet wird. |
| **Zweck** (`purpose`) | `Display`, `Edit`, `Search`. Bestimmt, wie gezeichnet wird. |
