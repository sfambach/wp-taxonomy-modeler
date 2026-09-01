# Paket · Datenbank

**Stand 2026-09-01.** Dieses Dokument beschreibt **ausschliesslich den aktuell gültigen
Soll-Zustand**. Was einmal galt, steht in [`history.md`](history.md); was offen ist, in
[`tasks.md`](tasks.md).

⚠️ **Soll, nicht Ist.** *Der Bestand hat Fehler geerbt ([D-568](../../NewConcept/90-decision-log.md)).
Wo beides auseinandergeht, steht es hier ausdrücklich: **«gilt»** ist der Soll-Zustand,
**«gemessen»** der heutige Ist-Zustand.*

---

## 1 · Zweck

Die technische Speicherung des Modells: Tabellen, Abfragen, Transaktionen, Schemaversion.

## 2 · Verantwortungsgrenze

**Gehört hinein:** die Tabellen und ihr Schema · das Anlegen und Fortschreiben über `dbDelta()` ·
die Schattentabellen und das Änderungsbuch · alles, was `$wpdb` berührt.

**Gehört ausdrücklich nicht hinein:** fachliche Modelllogik · Darstellung · die Frage, *was* ein
Knoten ist. **Dieses Paket weiss, wie Zeilen liegen — nicht, was sie bedeuten.**

---

## 3 · Die Kerntabellen

### 3.1 · `nodes` — der Knoten

| Spalte | | |
|---|---|---|
| `id` | **eigener Id-Raum** | **geändert 2026-09-01** |
| `version` | für die Schattentabelle | |
| `name` | indiziert | |
| `kind` | Knotenart | **offen, siehe 3.4** |

**`path` ist gestrichen.** *Der Eigentümer: «`path` fliegt raus überall, den brauchen wir nicht.»*

⚠️ **Warum er entbehrlich ist, gemessen:** *er trug punktseparierte Id-Ketten (`1.402.3083.13`) in
**127 von 128** Zeilen — und **alle 127** sind aus `relations` herleitbar, ohne eine einzige
Abweichung. **Er wiederholte, was die Kantentabelle bereits besitzt**, was `CLAUDE.md` ausdrücklich
verbietet: «One place owns each piece of state; everything else derives.» Ausserdem war er
**verlustbehaftet**: 18 Knoten haben mehr als eine eingehende Kante, und ein einzelner Pfad kann davon
nur einen Weg nennen. Seine eigene Entscheidung [D-082](../../NewConcept/90-decision-log.md) nannte ihn
«derived and rebuildable» — und steht bis heute auf `agreed (proposal)`, war also nie bestätigt.*

### 3.2 · `relations` — die Kante

| Spalte | | |
|---|---|---|
| `id` | **eigener Id-Raum** | **geändert 2026-09-01** |
| `version` | für die Schattentabelle | |
| `from_id` → `to_id` | beide zeigen auf **Knoten** | indiziert |
| `kind` | **die Kantenart** — `inheritance`, `composition`, `aggregation`, `setting` | gemessen: 127 · 23 · 5 · 11 |
| `name` | nur bei benannten Kanten | gemessen: 39 von 166 |
| `position` | Reihenfolge unter dem Elternknoten | gemessen: 127 von 166 |
| `multiplicity` | `1..1`, `0..1`, `1..*`, `0..*` | gemessen: 156 · 5 · 3 · 2 |
| `hide` | | gemessen: 6 von 166 |
| `parked_by_group_id` | | gemessen: **1 von 166** |

**`path` ist auch hier nicht vorhanden und kommt nicht.**

### 3.3 · Jede Tabelle bekommt ihren eigenen Id-Raum · `identities` ist gestrichen

**Es gibt keinen geteilten Nummernraum mehr — jede Tabelle zählt für sich.**

*Der Eigentümer: «jede Tabelle bekommt ihren eigenen Id-Raum.» Und zur Klammer, die es bisher gab:
«dass es die Klammer war, stimmt, aber sie war **nicht aus der Not geboren, sie war Konzept**. Aber
das hat oft dazu geführt, dass … Records dann einen zweiten Nummernraum hatten. Das eliminieren wir
jetzt.»*

⚠️ *Meine erste Fassung nannte die Klammer einen Notbehelf. **Das war meine Deutung und sie ist
korrigiert** — sie war beabsichtigt; was sie erzeugt hat, war ein zweiter Raum daneben.*

**`identities` stirbt ersatzlos.** Sie hatte genau eine Spalte, `id` — es zieht nichts um.

⚠️ **Gemessen, was sie gekostet hat: 65 593 vergebene Identitäten für rund 680 lebende Zeilen — 1,1 %.**
*53 561 gehörten zu gar keiner Tabelle.*

#### Die Folge: ein Fremdschlüssel muss sagen, wohin er zeigt

**STATUS: `PROPOSED` — folgt aus der Entscheidung, ist aber selbst eine**

Mit einem geteilten Raum durfte eine Spalte auf irgendetwas zeigen. Mit eigenen Räumen gibt es
**Knoten 5, Kante 5 und Datensatz 5.** Gemessen am 2026-09-01:

| Spalte | zeigt auf | |
|---|---|---|
| `records.node_id` · `record_values.record_id` · `record_values.edge_id` | Knoten · Datensätze · Kanten | **der Name sagt es** |
| `changelog.owner_id` | Knoten 1602 · Kanten 238 · Datensätze 9 · Werte 10 | **`owner_kind` sagt es** |
| `relations.from_id` · `to_id` | immer Knoten (166) | Name sagt es **nicht** |
| `labels.owner_id` · `role_id` | Knoten (47 · 47) | Name sagt es **nicht** |
| **`settings.owner_id`** | **Kanten (3)** | **derselbe Name, anderes Ziel als in `labels`** |
| **`record_values.value_ref`** | **Knoten 49 · Datensätze 88** | **mehrdeutig, nichts sagt es** |

**Vorschlag, und er folgt aus deiner Entscheidung statt sie zu erweitern:**

> **Ein Fremdschlüssel nennt seine Zieltabelle im Namen** — `node_id`, `relation_id`, `record_id`.
> **Kann eine Spalte auf mehr als eine Tabelle zeigen, nennt eine zweite Spalte den Raum**, so wie
> `changelog.owner_kind` es seit jeher tut.

⚠️ *Ohne das ist `owner_id` in `labels` ein Knoten und in `settings` eine Kante — bei geteiltem Raum
folgenlos, bei eigenen Räumen ein Verweis ins Falsche. Und `value_ref` ist genau der Fall, für den
[D-164](../../NewConcept/90-decision-log.md) die Abhilfe längst beschlossen hat, ohne dass sie je
gebaut wurde. Siehe [`tasks.md`](tasks.md), TASK-005.*

### 3.4 · `nodes.kind` heisst künftig `field_type` — **Modellfeld oder Einstellungsfeld**

**Die Spalte bleibt und bekommt einen Namen, der die Wahrheit sagt.**

*Der Eigentümer: «bei Field und Setting — das war mir nicht so bewusst, dass wir da einen
Unterschied machen beim Knoten, weil eigentlich ergibt sich das zum einen, unter welchem Ast es
hängt, und zum anderen, welche Relation dann darauf zeigt. **Finde ich aber im Grunde besser, dass
ich sage, es ist entweder ein Modellfeld oder ein Einstellungsfeld.** Und so würde ich es auch
definieren. Modellfeld oder Settingsfeld. Und das lassen wir. **Dann machen wir aber Feldtyp
draus.**»*

| | |
|---|---|
| **Was sie beantwortet** | Zeigt ein Feld auf diesen Knoten — ist es ein **Modellfeld** oder ein **Einstellungsfeld**? |
| **Werte** | `model` · `setting` |
| **Herkunft** | [D-518](../../NewConcept/90-decision-log.md), seine Frage wörtlich: «eine Option erfinden, die sagt: ist Field oder ist Setting?» |
| **gemessen** | 4 von 128 gefüllt, alle `setting` |

⚠️ **Er hat selbst gesehen, dass die Angabe ableitbar wäre** — aus dem Ast und aus der Relation, die
darauf zeigt — **und sie trotzdem behalten.** *Das ist eine bewusste Ausnahme von «zwei Angaben, die
nie widersprechen können, sind eine»: hier soll die Aussage **dastehen** statt errechnet zu werden.*

⚠️ **Spalte und Werte werden umbenannt: `kind` → `field_type`, `field` → `model`.** *Der Eigentümer
zunächst «wir lassen Field und Settings», dann seine Selbstkorrektur: «Entschuldigung, **Model und
Settings**, richtig.» **Es gilt die zuletzt eindeutige Aussage** ([`arbeitsmodell.md`](../../arbeitsmodell.md) §8.4).*

⚠️ **Trotzdem keine Datenwanderung, und das ist gemessen:** *der Wert `field` steht in **keiner
einzigen Zeile** — gefüllt sind nur vier, alle `setting`. Es ist eine Umbenennung im Code und im
Schema, die Daten fasst niemand an.*

⚠️ *Damit sagt die Spalte, was sie hält: **Modellfeld** oder **Einstellungsfeld**, kurz `model` und
`setting`.*

⚠️ **Offen: 124 von 128 Knoten sagen heute nichts.** *Ist «nichts» gleich «Modellfeld», oder muss es
dastehen? Siehe [`tasks.md`](tasks.md), TASK-007.*

### 3.5 · Neu: der Knoten nennt die PHP-Klasse, die ihn umsetzt

**STATUS: `CONFIRMED` — vom Eigentümer entschieden. Die Spalte existiert heute nicht.**

*«Ich brauche ein Feld, das sagt, dass es die und die PHP-Klasse ist … die Idee dahinter ist, dass
ich dann eine Objekt-Factory habe, und wenn ich einen Knoten habe, der Klassentyp `int` hat, dann
auch die Klasse verwendet wird, um ein Objekt davon zu erzeugen.»* Und nach der Abwägung: **«wenn
das ohne Factory geht, weil der Klassenname da drinsteht, perfekt.»**

**Die Spalte trägt den Klassennamen. Eine Factory entfällt damit.**

⚠️ **Was das ablöst, gemessen: 56 WordPress-Optionen.** *Heute sagt der Knoten **nicht**, was er ist —
es merken sich Optionen für ihn: `taxmod_type_int_id = 1171`, `taxmod_render_renderer_slider_id =
43521`, `taxmod_render_validator_range_id = 63128` und dreiundfünfzig weitere. Elf Typen, zwanzig
Renderer, fünf Konverter, drei Validatoren, dazu Wurzel, Papierkorb und die Zweige. **Die Bindung
«welcher Knoten ist der Int-Typ» liegt ausserhalb des Modells**, in WordPress' Optionstabelle — und
[`AR-1`](../../../CLAUDE.md) verlangt, dass das Modell in eigenen Tabellen liegt. **Diese Änderung
bringt sie heim.***

⚠️ **Der Preis, einmal benannt:** *ein Klassenname in einer Zeile **bindet die Daten an den Code**.
Wird eine Klasse umbenannt oder verschoben, zeigen die Zeilen ins Leere, und
[D-169](../../NewConcept/90-decision-log.md) nennt Portierbarkeit ausdrücklich als Ziel. **Eine Marke
hätte das überlebt.** Der Eigentümer hat die Abwägung gehört und sich für den Klassennamen
entschieden — und der hat einen Vorteil, den die Marke nicht hat: **er ist prüfbar.***

⚠️ **Deshalb gehört ein Wächter dazu, und er ist der Ausgleich für den Preis:** *eine Zeile, die eine
Klasse nennt, die es nicht gibt, wird rot. Das fängt genau den Fall, den die Bindung an den Code
erzeugt. Siehe [`tasks.md`](tasks.md), TASK-008.*

### 3.6 · `kind` bleibt an zwei Stellen — und heisst dort verschiedenes

| | |
|---|---|
| **`relations.kind`** | die **Kantenart** — `inheritance`, `composition`, `aggregation`, `setting`. Tragend, alle vier in Gebrauch. |
| **`records.kind`** | `default` oder `user`. Gemessen 184 · 25. |

⚠️ *Mit der Umbenennung von `nodes.kind` heissen nur noch zwei Spalten so — und sie bedeuten
weiterhin Verschiedenes. **Ob auch sie sprechendere Namen brauchen, ist offen** und nicht
entschieden.*

---

## 4 · Schnittstellen

**Dieses Paket erklärt nichts nach aussen — es *erfüllt*.** Der Kern erklärt die
Repository-Schnittstellen, dieses Paket setzt sie mit `$wpdb` um
([`CD-1`](../../../CLAUDE.md), [D-009](../../NewConcept/90-decision-log.md)).

```text
Kern erklärt eine Schnittstelle
        ↓
Datenbank erfüllt sie
```

**Von aussen herein:** Aktivierung (Schema anlegen und fortschreiben), Prüfläufe, Aufräumseite.

## 5 · Abhängigkeiten

**Benutzt:** `$wpdb` und `dbDelta()` — sonst nichts.
**Wird benutzt von:** Kern (über die Schnittstellen), Plattform (Aktivierung).

⚠️ *Gemessen am 2026-09-01: `src/Core` enthält **keinen einzigen** WordPress-Aufruf. Die Grenze hält,
und sie hält, weil ein Prüflauf beim ersten Verstoss scheitert.*

## 6 · Regeln dieses Pakets

| | |
|---|---|
| [`CD-6`](../../../CLAUDE.md) | `taxmod_`-Präfix, `dbDelta()`, gespeicherte Schemaversion. **Nur vorbereitete Abfragen.** |
| [`AR-1`](../../../CLAUDE.md) | Das Modell liegt in eigenen Tabellen, nicht in WordPress-Posts, -Postmeta, -Terms oder CPTs. |
| | **Eine kaputte `$wpdb`-Abfrage liefert ein leeres Ergebnis, keinen Fehler** — [`bekannte-fallen.md`](../../bekannte-fallen.md). |

---

## 7 · Noch nicht überarbeitet

`records`, `record_values`, `labels`, `changelog`, `settings` und die vier Schattentabellen stehen
noch so, wie sie sind. **Sie kommen einzeln dran** — bis dahin gilt für sie
[`review-tabellen.md`](../../review-tabellen.md) als Befund, nicht als Vorgabe.
