# Modell — Anforderungen

**Stand 2026-09-11.** Das Gegenstück zu [`einstellungen-anforderungen.md`](einstellungen-anforderungen.md):
was das **Modell** — Knoten im Baum, Kanten als Felder — erfüllen muss. Angelegt, weil beim
Einstellungsmodell Entscheidungen fielen, die zum Modell gehören (dort Abschnitt 7), und weil es seit
dem Schliessen des alten Konzepts keine lebende Modellseite gibt.

Diese Seite ist **nicht vollständig**: sie enthält nur, was am 2026-09-11 entschieden wurde, und
darunter einen Abgleich, was das gebaute Modell davon schon hat. Sein Wort: *«die modellseite sollte
das schon abbilden, was wir besprochen haben; wenn nicht, gehen wir dann nochmal über die
modellseite.»*

**Sprache** wie auf der Einstellungsseite: *muss*, *darf*, *darf nicht*; `OFFEN`; `VORSCHLAG`.

---

## 1 · Kanten als Felder

- **1.1** Es gibt zwei Kantenklassen: `Aggregation` und `Komposition`. Jede Kante hat genau eine.
- **1.2** Jede Kante muss eine Multiplizität tragen: genau einen der vier Werte `0..1`, `1..1`,
  `0..*`, `1..*`.
  - **1.2.1** Die Multiplizität ist eine Regel für die **Daten**: die untere Grenze sagt, ob das
    Feld am Datensatz gefüllt sein muss; die obere, ob es einen oder mehrere Sätze haben darf.
  - **1.2.2** Jede Kantenklasse darf jeden der vier Werte tragen.
  - **1.2.3** Eine Zielklasse darf die erlaubten Werte einschränken, und nur dann, wenn sie kein
    «leer» kennt. Heute: `Bool` erlaubt nur `1..1`. `Integer`, `Decimal`, `Text` erlauben alle vier.
  - **1.2.4** Die Multiplizität ist eine Spalte der Kante, keine Einstellung.
- **1.3** Jede Kante muss `read_only` tragen (`bool`): ob das Feld an dieser Stelle nur lesbar ist.
  - **1.3.1** `read_only` ist eine Spalte der Kante, keine Einstellung. Es gibt kein `read_only` am
    Knoten.
- **1.4** Jede Kante muss eine Stelle in der Feldliste ihres Von-Knotens haben.
- **1.5** Eine Kante darf eigene Labels haben. Fehlen sie, muss das Feld den Namen seines
  Zielknotens tragen.
- **1.6** Eine Kante darf `hide` tragen (`bool`): das Feld wird dem Benutzer nicht gezeigt, arbeitet
  aber im Hintergrund — für Berechnungsfelder. Sein Wort, 2026-09-11: «es kann berechnungsfelder im
  hintergrund geben, diese sollen für den benutzer nicht sichtbar sein — ein hide im frontend.»

## 2 · Felder und Vererbung

- **2.1** Felder vererben sich vom Vaterknoten auf seine Kinder, wie in der Objektorientierung: ein
  Kind hat alle Felder des Vaters und darf eigene hinzufügen.
- **2.2** Ein Kind darf geerbte Felder umstellen.
- **2.3** Ein Kind darf geerbte Felder verbergen.
- **2.4** `OFFEN` Wo 2.2 und 2.3 gespeichert werden. Nicht in Einstellungszeilen
  (Einstellungen 0.2).

## 3 · Knoten

- **3.1** Jeder Knoten muss eine Klasse tragen (Einstellungen 2.1). Das ist die einzige Änderung,
  die das Einstellungsmodell am Knoten verlangt.
- **3.2** Jeder Knoten muss eine Stelle unter seinem Vater haben.

## 4 · Eingabe, was aus 1.2 folgt

- **4.1** Ein Auswahlfeld muss bei unterer Grenze `0` einen Eintrag «nichts» anbieten und darf ihn
  bei `1` nicht anbieten.
- **4.2** Ein Auswahlfeld muss bei oberer Grenze `1` genau eine Wahl zulassen und bei `*` mehrere.
- **4.3** Ein Textfeld darf bei unterer Grenze `0` leer bleiben und muss bei `1` gefüllt sein.
- **4.4** Ein Textfeld muss bei oberer Grenze `1` genau einen Text halten und bei `*` mehrere.

---

## Abgleich mit dem gebauten Modell, gemessen 2026-09-11

*Eigener Abschnitt, damit die Anforderungen oben ohne «heute» bleiben. Gemessen am Code, nicht
erinnert.*

| Anforderung | gebaut | Befund |
|---|---|---|
| 1.1 zwei Kantenklassen | **teils** | es gibt eine Kantenart mit den Werten Aggregation und Komposition; sie heisst nicht Klasse und hat keinen Vertrag |
| 1.2 Multiplizität, vier Werte | **ja, Spalte — mit einem Einstellungsschlüssel davor** | ⚠️ *Berichtigt 2026-09-11 beim Bauen:* `relations.multiplicity` **ist** seit Fassung 22 eine Spalte; was fällt, ist nur der Schlüssel `SettingKey::Multiplicity`, über den die Maske sie wie eine Einstellung anbietet. Die erste Fassung dieser Zeile («in Wertzeilen») war falsch — gemessen am `CREATE TABLE`, nicht erinnert. |
| 1.2.3 Bool nur `1..1` | **nein** | keine Zielklasse schränkt heute ein |
| 1.3 `read_only` an der Kante | **ja, aber als Einstellung und auch am Knoten** | Einstellungsschlüssel, für Knoten und Kanten gleichermassen (1.3.1: nein) |
| 1.4 Stelle in der Feldliste | **ja** | Spalte an der Kante |
| 1.5 Kantenlabels | **ja** | Labels haben eine Besitzerart «Kante»; jede Kante bekommt beim Anlegen Beschriftungen |
| 2.1 Felder erben sich | **ja** | die Feldliste eines Knotens enthält die geerbten Felder |
| 2.2 / 2.3 geerbte Felder umstellen und verbergen | **nicht gemessen** | erst prüfen, wenn 2.4 entschieden ist |
| 3.1 Klasse am Knoten | **nein** | es gibt eine Feldart am Knoten (`field_type`), keine Klasse im Sinn der Einstellungsseite |
| 3.2 Stelle unter dem Vater | **ja** | Spalte am Knoten |
| 4.x Eingabe | **teils** | Pflicht und Liste werden aus der Multiplizität gelesen; nicht je Zeile gemessen |

**Was daraus folgt:** die Modellseite bildet das Besprochene **nicht** vollständig ab. Drei Stellen
weichen ab, alle an der Kante: Multiplizität und `read_only` sind heute Einstellungen statt Spalten
(1.2.4, 1.3.1), und die Kantenart ist keine Klasse (1.1). Dazu fehlt die Klasse am Knoten (3.1). Das
ist der Umfang, über den «nochmal über die Modellseite» gehen müsste.
