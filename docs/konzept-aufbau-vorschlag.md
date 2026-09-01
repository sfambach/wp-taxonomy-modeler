# Aufbau des neuen Konzepts — Vorschlag

**Stand 2026-09-01.** [D-568](NewConcept/90-decision-log.md) sagt: *«wie das neue Konzept aufgebaut
wird, ist vor seinem Inhalt festzulegen.»* Dies ist der Vorschlag dazu.

**Alles hier ist `PROPOSED`. Nichts ist entschieden.** Am Projekt wurde nichts geändert.

---

## 1 · Wo es liegt

**Vorschlag: `docs/pakete/`**, ein Ordner je Paket.

```text
docs/pakete/
├── 00-uebersicht.md          die Karte: welche Pakete, wer darf wen benutzen
├── datenbank/
│   ├── package.md            nur der aktuelle Soll-Zustand
│   ├── tasks.md              offene Arbeiten
│   ├── bugs.md               bekannte Fehler
│   ├── inbox.md              Eingang
│   └── history.md            alles Vergangene
├── datenzugriff/
├── kern/
├── renderer/
├── plattform/
└── integration/
```

⚠️ **Nicht `docs/NewConcept2/`.** *Der Name des alten Ordners hat mitgetragen, dass er nie fertig
wurde — «neu» ist kein Zustand, den ein Ordner halten kann. `pakete/` sagt, was drin ist.*

⚠️ **Die fünf Dateien je Paket entstehen erst, wenn sie Inhalt haben** — sonst sind es bei sechs
Paketen dreissig Dateien, von denen fünfundzwanzig leer sind. **Pflicht ist `package.md`.**

---

## 2 · Der Schnitt, gegen den heutigen Code gemessen

| Paket | Was heute hineinfiele | Dateien | Zeilen |
|---|---|---|---|
| **Datenbank** | `Schema`, die `Wpdb*`-Repositories, `Shadow` | ~12 | ~3 200 |
| **Datenzugriff** | `src/Core/Repository` (die Schnittstellen) | 10 | 800 |
| **Kern** | `Model`, `Converter`, `Validator`, `Exception`, aus `Service`: `ModelEditor`, `DataEntry`, `ModelValues`, `Tree`, `Labels` | ~65 | ~7 800 |
| **Renderer** | `src/Core/Renderer` + `Service/Rendering` | 52 | 9 233 |
| **Plattform** | `src/WordPress/Admin`, `Plugin`, `UnitScaffold` und die übrigen Scaffolds | ~8 | ~5 500 |
| **Integration** | — noch nichts; entsteht mit dem Ereignissystem | 0 | 0 |

**Gesamt 29 352 Zeilen in 144 Dateien.**

---

## 3 · Drei Stellen, an denen der Schnitt nicht passt

### 3.1 · `src/Core/Service` ist kein Ding, sondern fünf

**STATUS:** `FACT`

**7 135 Zeilen in 8 Dateien — 892 Zeilen je Datei.** Darin steckt:

| | Zeilen | gehört nach |
|---|---|---|
| `Rendering.php` | **2 868** | **Renderer** |
| `ModelEditor.php` | **1 466** | Kern |
| `DataEntry.php` | 988 | Kern |
| `Settings.php` | 744 | **stirbt mit [D-529](NewConcept/90-decision-log.md)** |
| `ModelValues.php` | 439 | Kern |
| `Labels.php` | 331 | Kern |
| `Tree.php` | 263 | Kern |

**AUSWIRKUNG:** Der Ordner heisst `Service` und sagt damit nichts — genau was `CD-9` verbietet
(*«no `data` / `info` / `manager` / `helper` as a whole name»*). Beim Paketschnitt **zerfällt er
ohnehin**, weil `Rendering` in ein anderes Paket gehört als `ModelEditor`.

### 3.2 · Drei Dateien tragen 27 % des Codes

**STATUS:** `FACT`

```text
NodesScreen.php    3 631 Zeilen   (Plattform)
Rendering.php      2 868 Zeilen   (Renderer)
ModelEditor.php    1 466 Zeilen   (Kern)
                   ─────
                   7 965 von 29 352 Zeilen
```

**AUSWIRKUNG:** Das sind die «Gottobjekte», vor denen §15 des Arbeitsmodells warnt. **Der Paketschnitt
zerlegt sie nicht** — er verteilt sie nur. Ob sie zerlegt werden, ist eine eigene Entscheidung und
gehört in die `tasks.md` ihres Pakets, nicht in den Schnitt.

### 3.3 · Der Renderer liegt heute *im* Kern

**STATUS:** `FACT` · **ENTSCHEIDUNG ERFORDERLICH**

`src/Core/Renderer`, 51 Klassen. Das Arbeitsmodell §2 führt «Renderer / UI-Abstraktion» als eigenes
Paket **hinter** Domain/Core.

⚠️ *Das hängt an [D-563](NewConcept/90-decision-log.md), die auf `PROPOSED` steht und auf das
Ereigniskonzept wartet: **gibt der Renderer eine Beschreibung zurück oder HTML?** Solange das offen
ist, ist auch offen, ob der Renderer im Kern bleibt. **Ich löse es nicht auf.***

---

## 4 · Was das Zuwachsen verhindert

[D-573](NewConcept/90-decision-log.md) deckelt das Immer-Gelesene auf **42,2 KB**. Für die Pakete
kommt eine zweite Decke dazu, und sie folgt aus `PR-13`:

> **Was ein Mensch vor einer Aufgabe lesen muss, ist: die Regeln plus *ein* `package.md`.**

**Vorschlag: `package.md` ≤ 12 KB.** Bei sechs Paketen wären das höchstens 72 KB Paketdokumentation
insgesamt — aber **nie mehr als 42 + 12 = 54 KB auf einmal.**

⚠️ *Die Zahl 12 ist **nicht** gemessen, sondern abgeleitet: `CLAUDE.md` ist heute 13 KB und ist das
Grösste, was in diesem Projekt je jemand in einem Zug gelesen hat. **Ich kennzeichne sie als geraten**,
weil noch kein `package.md` existiert. Sobald das erste steht, wird sie durch die Messung ersetzt.*

**`history.md` bleibt ungedeckelt.** Dort zieht hin, was heute 64 % des Entscheidungsbuchs ausmacht.

---

## 5 · Was aus dem Altbestand wird

| | Vorschlag |
|---|---|
| **307 Regeln** | Je Paket verteilt, wo sie hingehören — der Rest wird Historie. `02-rules-index.md` bleibt als Verzeichnis und wird weiter erzeugt. **Nicht in einem Zug**, sondern je Paket, wenn dessen `package.md` entsteht. |
| **562 Entscheidungen** | Bleiben, wo sie sind. Das Entscheidungsbuch ist **kein** Konzeptdokument, sondern die Historie — es wird nachgeschlagen, nicht gelesen, und ist deshalb nicht gedeckelt. Nach §6.1 sind sie `LEGACY`, bis sie beim Zitieren geprüft werden; **elf sind bisher geklärt.** |
| **`docs/NewConcept/`** | Bleibt liegen wie `docs/legacy/`: geerntet, geschlossen, zitierbar. **Kein Umzug, kein Löschen** — das wäre Arbeit ohne Ertrag. |

---

## 6 · Womit angefangen wird

**Vorschlag: mit dem Paket, dessen Konzept am gründlichsten geprüft ist — `Datenbank`.**

Das Tabellen-Review ([`review-tabellen.md`](review-tabellen.md)) liegt fertig, an der laufenden
Datenbank gemessen, mit `V-1` bis `V-6` und zwei offenen Fragen. **Es ist damit das einzige Paket, für
das der Inhalt schon da ist** — `package.md` wäre kein Neuschreiben, sondern das Aufschreiben eines
Befunds, den du schon gelesen hast.

⚠️ *Und es ist die Stelle, an der du selbst angefangen hättest: «ich würde gerne mit der DB anfangen».*

---

## Zusammenfassung

```text
BEZUG
Aufbau des neuen Konzepts, vor seinem Inhalt - D-568.

ERGEBNIS
Der Paketschnitt aus dem Arbeitsmodell geht auf dem heutigen Code auf. Drei
Stellen passen nicht, alle gemessen: src/Core/Service ist kein Ding sondern
fuenf und zerfaellt beim Schnitt ohnehin; drei Dateien tragen 27 Prozent des
Codes; und der Renderer liegt heute im Kern, was an D-563 haengt und offen
bleiben muss.

GEAENDERT
Nichts.

VORSCHLAEGE ZUR BESTAETIGUNG
1  docs/pakete/, ein Ordner je Paket, package.md ist die einzige Pflichtdatei
2  Der Schnitt aus Abschnitt 2
3  package.md hoechstens 12 KB - die Zahl ist geraten und wird ersetzt,
   sobald das erste existiert
4  Die 307 Regeln wandern je Paket mit, nicht in einem Zug
5  Das Entscheidungsbuch bleibt Historie und wird nicht gedeckelt
6  Angefangen wird mit Datenbank, weil das Review schon vorliegt

OFFENE FRAGEN
Bleibt der Renderer im Kern? Haengt an D-563 und dem Ereigniskonzept.
Werden die drei grossen Dateien zerlegt, und wann?

NAECHSTER SCHRITT
Dein Ja oder Nein zu den sechs. Danach docs/pakete/datenbank/package.md aus
dem Tabellen-Review - und dessen V-1 bis V-6 als seine tasks.md.
```
