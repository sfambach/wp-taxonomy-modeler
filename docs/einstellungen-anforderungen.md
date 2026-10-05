# Einstellungen — Anforderungen

**Stand 2026-09-11.** Die formale Fassung dessen, was auf
[`einstellungen-von-null.md`](einstellungen-von-null.md) entschieden wurde. Dort stehen die Wege,
Beispiele und seine Worte; hier steht je Sachverhalt eine prüfbare Anforderung. Bei Abweichung gilt
diese Seite; wer sie ändert, ändert sie mit einem Grund.

**Sprache:** *muss* = Anforderung; *darf* = Freiheit; *darf nicht* = Verbot. Was mit `OFFEN`
markiert ist, ist keine Anforderung, sondern eine noch nicht entschiedene Stelle. Was mit
`VORSCHLAG` markiert ist, stammt von mir und ist von ihm noch nicht bestätigt.

---

## 0 · Geltungsbereich

- **0.1** Diese Seite handelt von **Einstellungen**: den Attributen programmierter Klassen und den
  Werten, die Knoten dafür tragen.
- **0.2** Diese Seite handelt **nicht** vom Modell: nicht von Knoten im Baum, nicht von Kanten als
  Feldern (Komposition, Aggregation), nicht von Multiplizität, Reihenfolge oder Labels der Felder.
  Das Modell bleibt, wie es ist.
- **0.3** Einstellungen berühren das Modell an genau zwei Stellen: an der Klasse eines Knotens
  (2.1) und an der Kante als Ort einer Überschreibung (5.3).

---

## 1 · Begriffe

| Begriff | Bedeutung |
|---|---|
| **Klasse** | eine programmierte Knotenklasse (`Integer`, `Text`, `Kategorie`, `Konstante`, `Einheitswert`, …) oder eine programmierte Wertklasse (`CompactRenderer`, `Umrechnung`, …) |
| **Attribut** | ein benanntes, typisiertes Merkmal, das eine Klasse erklärt |
| **Vertrag** | die einmal aus der Klasse abgeleitete, gehaltene Beschreibung ihrer Attribute und Regeln |
| **Einstellungsobjekt** (`settings_object`) | ein Objekt einer Wertklasse, das als Wert eines komplexen Attributs dient, mit eigenen Attributwerten |
| **Zeile** | ein gespeicherter Wert: ein Attribut, ein Träger, ein Wert |
| **Träger** | woran eine Zeile hängt: ein Knoten oder ein Einstellungsobjekt |
| **Adresse** | Klasse + Attributname; benennt ein Attribut eindeutig |
| **Auflösung** | die Reihenfolge, in der ein Wert gesucht wird, bis einer gefunden ist |

---

## 2 · Klassen und Vertrag

### 2.1 Jeder Knoten hat eine Klasse

- **2.1.1** Jeder Knoten muss genau eine Knotenklasse haben.
- **2.1.2** Jede Kante muss genau eine Kantenklasse haben (Modell; hier nur, weil 2.1.3 es braucht).
- **2.1.3** Knotenklassen und Kantenklassen müssen getrennte Mengen sein. Eine Klasse darf nicht
  beides sein.
- **2.1.4** Die Klasse muss beim Anlegen vergeben werden. Sie darf danach wechseln — auf eine Klasse, die der
  Vater erlaubt und die jedes vorhandene Kind erlaubt; Einstellungen, die der neue Vertrag nicht erklärt, fallen
  weg. *Bis zum 2026-09-12 stand hier «darf danach nicht mehr wechseln»; sein Wort: «wir müssen typ wechsel möglich
  machen»* ([D-733](NewConcept/90-decision-log.md)).

### 2.2 Welche Klasse ein Kind bekommt

- **2.2.1** Die Klasse des Vaters muss erklären, welche Klassen ein Kind haben darf, und welche davon
  die Vorwahl ist.
- **2.2.2** Ein Kind darf keine Klasse bekommen, die die Klasse des Vaters nicht erlaubt.
- **2.2.3** Wird beim Anlegen keine Klasse gewählt, muss das Kind die Vorwahl bekommen.
- **2.2.4** Die Klasse `Kategorie` muss überall als Kindklasse erlaubt sein. Sie erlaubt ihrerseits
  alle Klassen als Kinder und ist die Vorwahl, wo keine Klasse etwas anderes sagt.
- **2.2.5** Eine Klasse für einen simplen Datentyp (`Integer`, `Decimal`, `Text`, `Bool`) muss als
  Vorwahl für Kinder denselben Typ erklären.
- **2.2.6** Eine Auswahlklasse (ein Knoten, dessen Kinder zur Wahl stehen) muss als Vorwahl für
  Kinder die Klasse `Konstante` erklären.

### 2.3 Vererbung zwischen Klassen

- **2.3.1** Klassen dürfen voneinander erben, wie in der Objektorientierung.
- **2.3.2** Eine Unterklasse muss alle Attribute ihrer Oberklasse haben.
- **2.3.3** Es gibt eine Basisklasse für alle Knotenklassen; ihre Attribute hat jeder Knoten.

### 2.4 Der Vertrag

- **2.4.1** Der Vertrag einer Klasse muss genau einmal je Klasse aus dem Code abgeleitet werden
  (per Reflection) und danach gehalten werden.
- **2.4.2** Beim Zeichnen, Speichern und Auflösen darf nur der Vertrag gelesen werden, nie die
  Klasse selbst.
- **2.4.3** Der Vertrag ist Code. Er darf nicht in einer Tabelle stehen.
- **2.4.4** Der Vertrag muss enthalten: je Attribut Name, Typ (3.1), ob Liste, und den Vorgabewert;
  die erlaubten Kindklassen und die Vorwahl (2.2); für Listen die Regel, ob zwei Einträge derselben
  Klasse erlaubt sind (3.4.2).
- **2.4.5** Ein Listentyp muss im Code an der Eigenschaft angegeben sein, damit der Vertrag ihn
  lesen kann.
- **2.4.6** Der Vertrag muss an die Plugin-Version gebunden sein: eine neue Version, ein neu
  abgeleiteter Vertrag. *Entschieden 2026-09-11: «1 ja».*

---

## 3 · Attribute

### 3.1 Typen

- **3.1.1** Ein Attribut muss genau einen dieser Typen haben: `bool`, `int`, `decimal`, `text`,
  Enum, Verweis auf einen Knoten, Verweis auf ein Feld, Objekt einer Wertklasse. *Verweis auf ein
  Feld seit [D-752](NewConcept/90-decision-log.md): die Kandidaten sind die Felder des Knotens, an dem
  die Einstellung steht — an einer Kante die Felder ihres Zielknotens.*
- **3.1.2** Jeder Typ aus 3.1.1 darf auch als Liste erklärt sein.
- **3.1.3** Ein Enum muss im Code erklärt sein; seine Fälle stehen im Vertrag.
- **3.1.4** Ein Verweis auf einen Knoten muss im Vertrag sagen, welche Knotenklasse das Ziel haben
  darf.
- **3.1.5** Ein Attribut vom Typ Objekt muss im Vertrag sagen, welche Wertklasse (oder Oberklasse)
  das Objekt haben darf.

### 3.2 Wahlen

- **3.2.1** Steht hinter einer Wahl ein Knoten (Rolle, Präfix, Einheit), muss das Attribut ein
  Verweis sein.
- **3.2.2** Steht hinter einer Wahl kein Knoten, muss das Attribut ein Enum sein — oder ein `bool`,
  wenn es genau zwei Fälle gibt.
- **3.2.3** Eine Wahl darf nicht als freier Text erklärt werden.

### 3.3 Adresse

- **3.3.1** Ein Attribut muss durch Klasse + Attributname eindeutig benannt sein.
- **3.3.2** Innerhalb einer Klasse darf ein Attributname nur einmal vorkommen.
- **3.3.3** Derselbe Attributname in verschiedenen Klassen (`Integer.min`, `Decimal.min`) ist
  erlaubt und bezeichnet verschiedene Attribute.
- **3.3.4** Wird ein Attribut im Code umbenannt, ändert sich seine Adresse; gespeicherte Werte
  müssen dann mitgeführt werden.

### 3.4 Listen

- **3.4.1** Eine Liste muss über eine Klasse typisiert sein; ihre Einträge sind Werte dieses Typs
  oder Objekte seiner Unterklassen.
- **3.4.2** Ob eine Liste zwei Einträge derselben Klasse haben darf, muss die Klasse entscheiden,
  die die Liste erklärt.
- **3.4.3** Eine Liste muss eine Reihenfolge haben.

### 3.5 Tiefe

- **3.5.1** Ein Objekt darf Attribute vom Typ Objekt haben, ohne Grenze der Tiefe.
- **3.5.2** Richtlinie: Klassen sollen flach gehalten werden.

### 3.6 Welche Attribute es gibt

- **3.6.1** Die Basisklasse Knoten muss erklären: `renderer` (Liste von Renderer-Objekten),
  `converter` (Liste von Konverter-Objekten), `validator` (Liste von Validator-Objekten).
  *`display_size` stand hier bis zum 2026-09-11 und ist an die einfachen Typen gewandert (3.6.2,
  [D-724](NewConcept/90-decision-log.md)).* *Alle drei als Liste — sein Wort 2026-09-11: «im grunde haben wir alle
  möglichkeiten, einschränken können wir es immer noch.» Eine Einschränkung auf einen Eintrag wäre
  Sache der Klasse (3.4.2).*
- **3.6.2** `Integer` und `Decimal` müssen erklären: `min`, `max`, `step`, je im eigenen Typ; `Date and time`
  erklärt `min` und `max` als Datum ([D-757](NewConcept/90-decision-log.md)). **Jede
  einfache Typklasse** und die Klasse `Konstante` muss `display_size` (`int`, Vorgabe 20) erklären — *sein Wort 2026-09-11: «display
  size gibts nur an den simplen datentypen»* ([D-724](NewConcept/90-decision-log.md)).
- **3.6.3** Ein Renderer, Konverter oder Validator muss seine eigenen Attribute selbst erklären
  (`CompactRenderer.orientation`, `CompactRenderer.withLabel`). **Es gibt einen Wähler** `chooser` mit
  `label_role`, `dialog` (`bool`, Vorgabe aus) und `display_size` — *sein Wort 2026-09-12: «chooser dialog, chooser
  inline zusammenfassen … einen schalter für dialoge einführen, default aus»* ([D-727](NewConcept/90-decision-log.md)).
- **3.6.2a** `Constant` muss `label_role` erklären (Verweis auf eine Rolle) — *«bei constant müsste der label typ
  wählbar sein der angezeigt wird … einfacher wäre im typ»* ([D-728](NewConcept/90-decision-log.md)). Ein
  Verweisattribut darf einen **Anker** nennen; dann bietet es nur die Kinder dieses Gerüstknotens an (Rollen,
  Präfixe), nie alle Knoten der Klasse.
- **3.6.4** `Umrechnung` ist eine Wertklasse mit `factor` und `offset` (`decimal`). Die Klassen
  `Konstante` (Präfix) und `Einheitswert` müssen ein Attribut vom Typ `Umrechnung` erklären.
- **3.6.5** `Einheitswert` muss erklären: `mit_praefix` (`bool`), `erlaubte_praefixe` (Liste von
  Verweisen auf Knoten der Klasse `Konstante`), `symbol` (`text`). *Bestätigt 2026-09-11.*
- **3.6.6** Wird an einem Datensatz die Einheit oder das Präfix umgeschaltet, muss die Maske die Zahl
  über `Umrechnung` umrechnen; die Grösse bleibt dieselbe. Sein Wort: «müsste evtl. beim umschalten
  wert umrechnen» — dann «2 ja». Sache der Daten, nicht der Ablage; eigene Aufgabe nach dem Umbau
  (Bauplan).
- **3.6.7** Es gibt **kein** Attribut `default`; ein Vorgabewert ist ein Datensatz.
- **3.6.8** Es gibt **kein** Attribut `position`; die Stelle eines Knotens und eines Felds ist
  Modell.
- **3.6.9** Es gibt **kein** Attribut `icon`; das Icon ist ein Label.
- **3.6.10** Es gibt **kein** Attribut `read_only` und **kein** Attribut `multiplicity`; beide sind
  Eigenschaften des Felds, Modell.

### 3.7 Spalte statt Zeile

- **3.7.1** Ein Attribut, das jedes Objekt genau einmal trägt, nie als Liste, und das nichts
  überschreibt, darf als Spalte der Tabelle des Trägers abgelegt sein statt als Zeile. Der Vertrag
  muss dann sagen, dass der Wert aus der Spalte kommt.
- **3.7.2** Heute gilt das für keine Einstellung; `position`, `read_only` und `multiplicity` sind
  Modellspalten (3.6.8, 3.6.10).

---

## 4 · Ablage

### 4.1 Tabellen

- **4.1.1** Einstellungen liegen in genau zwei Tabellen: `settings_object` und `settings_value`
  (die Zeilen). *Namen bestätigt 2026-09-11.*
- **4.1.2** Die Knotentabelle des Modells muss eine Spalte `klasse` bekommen (2.1.1). Sonst ändert
  sich am Modell nichts.
- **4.1.3** Es gibt keine Tabelle, die Attribute erklärt (2.4.3), keine Tabelle je Liste oder
  Tiefe, keine Satzart, keinen Unterschied zwischen Einstellungs- und Objektsätzen.

### 4.2 Schlüssel und Verweise

- **4.2.1** Jede Tabelle muss ihren eigenen Schlüsselzähler haben. Es gibt keinen gemeinsamen
  Id-Raum.
- **4.2.2** Ein Verweis muss eine Spalte je Zieltabelle sein; ein Paar aus Art und Nummer darf nicht
  vorkommen.
- **4.2.3** Jeder Verweis muss ein Fremdschlüssel sein, den die Datenbank prüft.

### 4.3 `settings_object`

- **4.3.1** Ein Einstellungsobjekt muss haben: `id`, `klasse`.
- **4.3.2** Ein Einstellungsobjekt muss von genau einer Zeile als Wert (4.4.5) benannt sein; wem es
  gehört, ergibt sich daraus. Es hat keine eigene Trägerspalte.
- **4.3.3** Ein Einstellungsobjekt ohne benennende Zeile darf nicht bestehen bleiben (4.6.4).

### 4.4 Die Zeile

- **4.4.1** Eine Zeile muss genau einen Träger haben: `knoten_id` **oder** `settings_object_id`.
- **4.4.2** Eine Zeile darf zusätzlich `kante_id` tragen; dann gilt sie nur an dieser Kante (5).
  `kante_id` darf nicht ohne Träger vorkommen.
- **4.4.3** Eine Zeile muss ihre Adresse tragen: `klasse` und `attribut` (3.3).
- **4.4.4** Eine Zeile muss `position` tragen: die Stelle in der Liste, sonst 0.
- **4.4.5** Eine Zeile muss genau eine Wertspalte gefüllt haben: `wert_int` (auch für `bool`, als
  `0`/`1`), `wert_decimal`, `wert_text` (auch für Enum), `wert_knoten_id` (Verweis),
  `wert_settings_object_id` (Objekt). *Beim Bauen (Schritt 3, 2026-09-11) ist `wert_bool` mit
  `wert_int` zusammengefallen: ein Wahrheitswert liegt im ganzen Bestand als `0`/`1` in der
  Ganzzahlspalte ([D-315](NewConcept/90-decision-log.md)), und der Vertrag weiss, welches Attribut
  ein `bool` ist — die Ablage muss es nicht ein zweites Mal wissen.*
- **4.4.6** Eine Zeile darf `aktiv` tragen; das gilt nur für Zeilen nach 5.5.3.
- **4.4.7** Eine Liste muss aus mehreren Zeilen derselben Adresse am selben Träger bestehen,
  geordnet über `position`.

### 4.5 Was gespeichert wird

- **4.5.1** Es dürfen nur Werte gespeichert werden, die jemand gesetzt hat. Ein Wert, der der
  Vorgabe des Vertrags entspricht, ohne dass jemand ihn gesetzt hat, darf keine Zeile haben.
- **4.5.2** Ein Knoten ohne gesetzte Werte hat keine Zeilen.

### 4.6 Versionen und Parken

- **4.6.1** Beide Tabellen müssen eine Schattentabelle haben. Jede Version bleibt; Löschen ist
  Wandern in den Schatten.
- **4.6.2** Wird ein Knoten geparkt, müssen alle Zeilen, die auf ihn verweisen (`wert_knoten_id`),
  mitwandern, samt der Einstellungsobjekte, die daran hängen.
- **4.6.3** Das Mitwandern muss als eine Änderungsgruppe erfasst sein; beim Zurückholen kommt genau
  diese Gruppe zurück.
- **4.6.4** Wandert eine Zeile, die ein Einstellungsobjekt benennt, muss das Objekt mit seinen
  Zeilen mitwandern.
- **4.6.5** Eine lebende Zeile darf nie auf ein geparktes Ding zeigen.

---

## 5 · Überschreiben an der Kante

- **5.1** Jedes Attribut eines Knotens darf an jeder Kante, die den Knoten verwendet, überschrieben
  werden — einzeln, Wert für Wert. Es gibt keine Liste von Ausnahmen.
- **5.2** Eine Überschreibung ist eine Zeile mit `kante_id` (4.4.2) und der Adresse des
  Knotenattributs. Sie darf nur bestehen, wenn jemand sie gesetzt hat (4.5.1); es wird kein Wert
  vom Knoten kopiert.
- **5.3** Die Kante trägt keine eigenen Einstellungen. Ihre Klasse hat keinen Vertrag mit
  Attributen.
- **5.4** Ein Wert in einem Einstellungsobjekt des Knotens (etwa `orientation` in seinem Renderer)
  darf an der Kante überschrieben werden: Träger ist das Objekt, dazu `kante_id`.
- **5.5** Listen an der Kante:
  - **5.5.1** Zeilen der Kante ergänzen die Liste des Knotens; sie ersetzen sie nicht.
  - **5.5.2** Die Kante darf die Reihenfolge der ganzen Liste bestimmen, geerbte Einträge
    eingeschlossen.
  - **5.5.3** Jeder geerbte Eintrag ist an der Kante von Haus aus aktiv. Die Kante darf ihn auf
    nicht aktiv setzen; dann gilt er dort nicht.
  - **5.5.4** Eine Zeile der Kante zu einem geerbten Eintrag benennt ihn über sein
    Einstellungsobjekt (komplex) oder seinen Wert (einfach) und trägt `position` und `aktiv`.
- **5.6** Werte vererben sich nicht von einem Knoten auf seine Kinder. Ein Kind hat seine Klasse,
  deren Vertrag und seine eigenen Zeilen.
- **5.7** Auflösung: ein Wert wird zuerst an der Kante gesucht, dann am Knoten, dann im Vertrag der
  Klasse. Der erste gefundene gilt.

---

## 6 · Maske

- **6.1** Die Maske muss immer den aufgelösten Wert zeigen (5.7). Fehlt eine Zeile, zeigt sie die
  Vorgabe des Vertrags — und genau die gilt.
- **6.2** Die Maske darf keinen eigenen Vorgabewert haben, der vom Vertrag abweicht.
- **6.3** Beim Anlegen eines Kindes muss die Maske nur die erlaubten Klassen anbieten, mit der
  Vorwahl vorgewählt (2.2).
- **6.4** Ob «aktiv» (5.5.3) als Haken oder Schalter gezeichnet wird, ist Zeichnung, nicht Modell.
- **6.5** Vor dem Parken eines Knotens muss die Maske zeigen, was mitwandert (4.6.2), und mit
  Ja/Nein fragen.
- **6.6** Die Maske darf nie die Klasse selbst lesen, nur den Vertrag (2.4.2).

---

## 7 · Nicht Gegenstand dieser Seite — auf die Modellseite übertragen

Am 2026-09-11 mitentschieden, aber Modell, nicht Einstellung. **Übertragen nach
[`modell-anforderungen.md`](modell-anforderungen.md)**, samt Abgleich mit dem gebauten Modell; hier
nur noch zur Übersicht:

- **7.1** Multiplizität ist eine Eigenschaft des Felds: ein Enum mit vier Fällen (`0..1`, `1..1`,
  `0..*`, `1..*`); jede Kantenklasse darf jeden Wert; Bool erlaubt als Ziel nur `1..1`; Integer,
  Decimal und Text erlauben alle vier. Regel: eine Klasse schränkt nur ein, wenn sie kein «leer»
  kennt.
- **7.2** `read_only` ist eine Eigenschaft des Felds, Spalte der Kante.
- **7.3** Eine Kante darf eigene Labels haben, muss aber nicht; fehlen sie, heisst das Feld wie
  sein Ziel.
- **7.4** Ein Kind darf geerbte Felder umstellen und verbergen; wie, ist Modell.
- **7.5** Felder vererben sich von Vater auf Kind, wie in der Objektorientierung.
- **7.6** Zwei Kantenklassen: Aggregation und Komposition.

---

## 8 · Offen

- **8.1** ~~`OFFEN`~~ Entschieden: `hide` am Feld gibt es, für Berechnungsfelder im Hintergrund (Modell 1.6).
- **8.2** ~~`OFFEN`~~ Entschieden («4 ja»): jede Klasse nennt im Vertrag ein Icon, der Baum zeichnet es; ein Label-Icon am Knoten geht vor. Kommt mit Schritt 1 des Bauplans.
- **8.3** ~~`OFFEN`~~ 2.4.6 entschieden: Plugin-Version.
