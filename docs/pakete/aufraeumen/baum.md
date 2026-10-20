# Aufräumen · Äste «PC» und «Hardware Project» — Befund und Vorschlag

**Stand 2026-10-20. Befund gelesen, Vorschlag `INFERRED`, nichts entschieden, nichts gebaut.** Aus [INF-079](../../neues-konzept-eingang.md).

> *«ok vielleicht sollten wir vorher etwas aufräumen die pc und bauteilspate sind etwas gewuchert»* — der Eigentümer, 2026-10-20, vor dem Lager-Modul.

**Gelesen:** live per `taxmod/read-tree` am 2026-10-20, Knoten bis Tiefe 6. **Nicht gelesen:** Felder und Satzzahlen je Knoten — ein Knoten, der hier leer aussieht, kann Sätze tragen.

## 1 · Befund

| # | Was | Wo (Ids) |
|---|---|---|
| B1 | **Drei Orte für Konstanten:** `PC › Constants`, `Hardware Project › Constants`, `Primitives › Constants`; dort zusätzlich ein leerer Knoten «PC Constants» | 149000102259, 149000104441, 32493 |
| B2 | **Doppelte Konstanten:** «DIP-40» in «Sockel und Gehäuse» **und** in «Bauteil-Bauformen › THT»; «LGA775» **und** «LGA 775» | 149000108733 / 149000104398; 149000108741 / 149000109521 |
| B3 | **Zwei Kataloge für Chips:** CPUs, Koprozessoren, Mikrocontroller unter `PC › … › Prozessoren`; Mikrocontroller, Logik-, Speicher-, Video-IC unter `Electronic Parts › Active › IC` | 149000103929, 149000104028 / 149000104413, 149000109985 |
| B4 | **Zwei Wege für Revisionen:** «Mainboards», «Mainboard-Reihen», «Mainboard-Revisionen» als Geschwister unter «Internal»; «Platine» und «Revision» unter «Hardware Project» | 149000107691/7758/7757; 149000105261/5262 |
| B5 | **Zwei Kataloge für Platinen/Boards:** «Enhancement Cards» (PC) und «Electronic Parts › Module» (Mikrocontroller-Boards, Sensormodule) | 32485; 149000104428 |
| B6 | **Steckertypen doppelt gedacht:** «Steckertypen» (D-Sub, USB …) bei den Bauteilen, «Schnittstellen», «Stromanschlüsse», «Speichermodule» beim PC | 149000104367; 149000103870, 149000107683, 149000108718 |
| B7 | **Gruppen als Auswahl oder Konstante angelegt:** «Hardware», «Internal», «External», «Software», «OS», «DOS» … sind `Choice`, «Prozessoren» ist `Constant` — sonst sind Gruppen `Category` | 149000103001, 32483, 149000103929 … |
| B8 | **Lose Knoten:** «Prozessor-Familien» direkt unter «PC» neben «Models»; «Line Types › Software Change»; «PC-Konfigurationen» zwischen «Internal» und «External» | 149000104245, 149000103429, 149000109401 |
| B9 | **Name «Models» unter `Model`** — liest sich wie die Wurzel | 149000103431 |
| B10 | **Sprachmix und Tippfehler:** Hardware/Internal/Enhancement Cards/Graphics/IO/Resistor/Coil/«Condensator»/Active neben Netzwerkkarten/Soundkarten/Passiv/Schalter | 32485, 3640, 3800, 3642, 3638 |
| B11 | **Lagerdinge mitten in `Model`:** Käufe, Bestellungen, Verkäufe, Verkaufte Artikel, Preisangaben, Exemplare | → Lager-Konzept §3 |
| B12 | **Reste unter `Compositions`:** «Zutat», «Backrezept» | 13416, 13420 |

## 2 · Vorschlag — Ziel-Baum

Passt zur Idee «Module» ([INF-078](../../neues-konzept-eingang.md)); jeder Ast unten wäre später ein Modul.

```mermaid
flowchart TB
  M["Model"] --> HW["Hardware"]
  M --> SW["Software"]
  M --> BT["Bauteile"]
  M --> PR["Projekte"]
  M --> ST["Stock"]
  M --> AL["Allgemein: Quellen, Kompatibilität, Messwesen, Seitenvorlagen"]
  HW --> HI["Intern: Karten, Laufwerke, RAM, Mainboards, Chipsätze"]
  HW --> HE["Extern: Monitore, Eingabegeräte"]
  HW --> HK["PC-Konfigurationen"]
  BT --> BP["Passiv · Aktiv (inkl. CPUs, Mikrocontroller) · Verbinder · Schalter · Module · Sonstiges"]
  PR --> PP["Projekte · Platine · Revision"]
```

| # | Schritt | löst |
|---|---|---|
| V1 | Konstanten an **einen** Ort je Modul (`Hardware › Konstanten`, `Bauteile › Konstanten`); leeres «PC Constants» weg; Doppelte zusammenführen, Verweise umhängen | B1, B2 |
| V2 | **CPUs, Koprozessoren, Mikrocontroller sind Bauteile** (unter «Aktiv › IC»); der PC verweist auf sie — ein Katalog, und die Stückliste findet sie | B3 |
| V3 | Mainboard-Reihe/-Revision auf das Muster «Platine › Revision» bringen oder ausdrücklich getrennt lassen — **seine Entscheidung** | B4 |
| V4 | Gruppen einheitlich `Category`; Auswahl/Konstante nur, wo wirklich gewählt wird | B7 |
| V5 | Lose Knoten einordnen: «Prozessor-Familien» zu den Bauteilen, «PC-Konfigurationen» unter «Hardware», «Line Types» prüfen | B8 |
| V6 | Eine Sprache für Knotennamen (Vorschlag: Deutsch), Tippfehler weg | B10 |
| V7 | Lagerdinge unter «Stock» (Lager-Konzept F2) | B11 |
| V8 | «Zutat», «Backrezept» löschen, wenn keine Sätze daran hängen | B12 |

## 3 · Was er entscheiden soll

- **A1** Ziel-Baum wie oben (Hardware · Software · Bauteile · Projekte · Stock · Allgemein)?
- **A2** CPUs & Co. zu den Bauteilen (V2)?
- **A3** Mainboard-Revisionen wie Platine/Revision (V3)?
- **A4** Knotennamen auf Deutsch (V6)?

## 4 · Wie gebaut würde

Alles über `taxmod/apply` (move_node, rename_node), ein Auftrag je Schritt über WP-Upload. Verschieben behält Ids und Werte; innerhalb von `Model` bleiben geerbte Felder sichtbar ([INF-076](../../neues-konzept-eingang.md)). Zusammenführen doppelter Konstanten und Löschen braucht ein eigenes OK im WP-Upload-Thread. Vor jedem Schritt: Felder und Satzzahlen der betroffenen Knoten live lesen.
