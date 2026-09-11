# Einstellungen — Vergleich mit dem Bestand und Bauplan

**Stand 2026-09-11.** Was [`einstellungen-anforderungen.md`](einstellungen-anforderungen.md) und
[`modell-anforderungen.md`](modell-anforderungen.md) am gebauten Stand ändern, und in welchen
Schritten. Beschlüsse: D-712 bis D-719. Aufgaben: TASK-092 bis TASK-096.

**Gemessen am 2026-09-11**, nicht erinnert:

| | |
|---|---|
| Dateien, die den heutigen Einstellungsschlüssel (`SettingKey`) benutzen | 56 |
| Dateien, die die Einstellungskante kennen | 19 |
| Dateien, die die Satzart `settings` kennen | 37 |
| Wächter gesamt / davon mit Einstellungen befasst | 56 / 29 |
| Kern-Tests gesamt / davon mit Einstellungen befasst | 32 / 18 |
| Schema-Fassung heute | 45 |

---

## 1 · Was fällt, was wandert, was bleibt

### Fällt

| heute | warum |
|---|---|
| Die **Einstellungskante** (Kantenart `setting`) | D-712, D-715: Einstellungen sind Zeilen am Knoten, keine Kanten |
| Die **Satzart `settings`** in den Datensätzen | D-712: Einstellungen sind keine Datensätze |
| Die Wertzeile mit `relation_id = 0` als Fach des Knotens | D-712: dafür gibt es `settings_value` |
| Der feste **Einstellungsschlüssel** (`SettingKey`) mit seiner Formliste | D-712: die Klasse erklärt ihre Attribute, der Vertrag liest sie |
| Die **Modellknoten** Renderer, Converter, Validator, Orientation unter `Settings`, samt Feldern | D-718: Objekte im Code |
| Die **Einstellungsknoten** unter Integer, Decimal, Boolean (`integer_min`, `display size`, `Read Only`, …) | D-719 K3a |
| Die Vorfahren in der **Auflösungskette** | D-712: Kante → Knoten → Vertrag |
| `SettingKey::Multiplicity` und `SettingKey::ReadOnly` als Einstellungen | D-713, D-714: Spalten der Kante |
| `nodes.field_type` | D-618/D-621 (schon beschlossen), jetzt fällig, weil `klasse` kommt |
| Die Wächter **`einstellungen-check`** (252 Zusagen) in heutiger Form, **`field-order-check`** in Teilen | bewachen die Einstellungskante; werden auf dem neuen Modell neu geschrieben (PR-9: sichtbarer Teil des Umbaus) |

### Wandert

| von | nach |
|---|---|
| Die Rollen `form`, `table`, `select`, `symbol`, `help` | unter `Constants` (D-719 K3c) |
| Die Kantenklassen Aggregation und Komposition | bleiben, werden von drei auf zwei Werte |
| Multiplizität und `read_only` an Kanten | aus Wertzeilen in Spalten der Kante |
| Die Vorgabe eines simplen Typs | bleibt ein Datensatz der Art `default` — unverändert |

### Bleibt

Knoten, Kanten, Labels, Datensätze (`node_records`, `relation_records`, die Arten `default`,
`example`, `user`), Schatten, Klammer, die Renderer-Klassen selbst, die Konverter und Validatoren
als Code, das Einheitengerüst als Baum (nur die Klassen der Knoten kommen dazu).

### Kommt

| neu | Beschluss |
|---|---|
| `nodes.klasse` | D-716 |
| `settings_object`, `settings_value`, je mit Schatten | D-712 |
| Der **Vertrag**: einmal je Klasse per Reflection, gehalten | D-712 (Anforderung 2.4) |
| Die Klassen `Kategorie`, `Auswahl`, `Konstante`, `Einheitswert`, `Einheitenwert`, `Umrechnung` | D-716, D-719, Anforderung 3.6 |
| `relations.multiplicity`, `relations.read_only` | D-713, D-714 |

---

## 2 · Der Bauplan — sieben Schritte, jeder für sich grün

**Regeln für jeden Schritt:** Kern- und Randlauf grün vor dem Commit (PR-9); am Ende etwas, das er
bedienen kann (PR-2); die Wächter des Schritts kommen mit dem Schritt; was der Schritt annimmt und
das Konzept nicht sagt, steht in seiner Liste. **Das Alte bleibt stehen, bis das Neue es ersetzt** —
kein Schritt lässt die Installation ohne Einstellungen.

### Schritt 1 · Die Klasse am Knoten (TASK-092) — **gebaut 2026-09-11**

*Fassung 46; `Core\Model\NodeClass\*` mit `Contract`/`Contracts`; Wächter `klasse-check` (29 Zusagen);
Kern 511 grün, Rand 57 von 57 grün. Nebenbei gefunden und mitgenommen: der Abzug (`saat-export`) kannte
die Notizen des Einheitengerüsts aus D-709 noch nicht und brach ab — die Muster `taxmod_unit_node_*`
stehen jetzt im Abzug; `data/saat.json` ist neu gezogen, mit `klasse`.*

- Spalte `nodes.klasse`; Schema-Fassung 46 vergibt sie nach der Tabelle K3.
- Die Knotenklassen als Code: `Kategorie`, `Auswahl`, `Konstante`, `Einheitswert`, `Einheitenwert`
  und die bestehenden Typklassen. Jede erklärt erlaubte Kindklassen und Vorwahl.
- Der Vertrag für Knotenklassen: einmal abgeleitet, gehalten; liest Kindklassen und Vorwahl.
- Beim Anlegen: Liste der erlaubten Klassen, Vorwahl vorgewählt; danach fest.
- **Bedienbar:** ein neuer Knoten bekommt seine Klasse; im Baum steht sie dabei.
- **Wächter:** `klasse-check` — jeder Knoten hat eine; K3 stimmt; ein Kind ausserhalb der erlaubten
  Klassen wird abgewiesen; Vorwahl greift.
- **Noch nicht:** kein Attribut wird aus der Klasse gelesen; die Einstellungen laufen weiter über
  die Kanten.

### Schritt 2 · Die zwei Spalten an der Kante (TASK-094, TASK-095)

- `relations.multiplicity` (Enum, vier Werte) und `relations.read_only`; Fassung 47 füllt sie aus
  den heutigen Wertzeilen, dann fallen die zwei Schlüssel.
- `Bool` als Ziel: nur `1..1` (aus dem Vertrag der Klasse, Schritt 1).
- **Bedienbar:** Multiplizität und Nur-lesen an der Feldzeile, wie heute, nur aus der Spalte.
- **Wächter:** `kantenspalten-check`; die Zusagen zu den zwei Schlüsseln in `einstellungen-check`
  fallen sichtbar.

### Schritt 3 · Die zwei Tabellen, leer (TASK-096 a)

- `settings_object`, `settings_value`, Schatten, Fassung 48. Repositories im Kern mit Fake für die
  Kern-Tests. Eigene Id-Zähler, Fremdschlüssel.
- **Bedienbar:** nichts Neues; die Tabellen sind leer (D-717).
- **Wächter:** `settings-tables-check` — Form, Fremdschlüssel, «genau eine Wertspalte», «genau ein
  Träger», `kante_id` nie allein.

### Schritt 4 · Der Vertrag für Wertklassen und das Lesen (TASK-096 b)

- Renderer, Konverter, Validatoren, `Umrechnung` erklären ihre Attribute im Code; der Vertrag
  liest sie (Listentyp per Angabe an der Eigenschaft).
- Die Basisklasse Knoten erklärt `renderer`, `converter`, `validator`, `display_size`; `Integer`
  und `Decimal` erklären `min`, `max`, `step`.
- Die **Auflösung** Kante → Knoten → Vertrag liest aus `settings_value`; solange dort nichts steht,
  gilt die Vorgabe des Vertrags. **Die Renderer zeichnen ab hier aus der neuen Auflösung**; die
  alten Einstellungskanten werden nicht mehr gelesen — sie stehen noch, aber wirkungslos.
- **Bedienbar:** die Maske zeigt je Knoten seine Attribute aus dem Vertrag, mit Vorgaben; noch
  nichts zu speichern.
- **Wächter:** `vertrag-check` — jede Klasse hat einen; die Attributliste je Klasse stimmt mit der
  Anforderungsseite 3.6 überein; Reflection läuft genau einmal.

### Schritt 5 · Schreiben, Objekte, Listen (TASK-096 c)

- Speichern eines einfachen Werts (Zeile), eines komplexen (Objekt + Zeilen), einer Liste
  (`position`); nur Gesetztes wird gespeichert (4.5).
- **Bedienbar:** der ganze Einstellungsbereich am Knoten auf dem neuen Modell.
- **Wächter:** `einstellungen-check` **neu geschrieben** auf dem neuen Modell, Abschnitt für
  Abschnitt; die alte Fassung fällt in demselben Commit.

### Schritt 6 · Überschreiben an der Kante (TASK-096 d)

- Zeile mit `kante_id`: einfacher Wert, Wert im Objekt, Listeneintrag mit `aktiv` und `position`.
- Der Haken «hier anders» an der Feldzeile; die Maske zeigt den aufgelösten Wert.
- **Bedienbar:** an einer Feldzeile einen Knotenwert überschreiben, einen geerbten Renderer
  abschalten, die Reihenfolge ändern.
- **Wächter:** `ueberschreiben-check`.

### Schritt 7 · Das Alte fällt (TASK-093, TASK-096 e, D-718, D-719)

- Einstellungskanten und ihre Sätze wandern in den Schatten; Kantenart `setting` fällt, zwei Werte
  bleiben; Satzart `settings` fällt; `SettingKey` fällt; die Knoten unter `Settings` fallen, die
  Rollen wandern unter `Constants`; die Einstellungsknoten unter den Typen fallen; `field_type`
  fällt. Fassung 49.
- Das Einheitengerüst vergibt Klassen und Umrechnungssätze (Fassung 5 des Gerüsts).
- **Bedienbar:** alles wie nach Schritt 6, ohne die Leichen.
- **Wächter:** die 29 Wächter mit Einstellungsbezug werden je einzeln umgestellt oder fallen
  sichtbar (Liste in `waechter-bestand.md`); `field-order-check` auf die neue Form.

---

## 3 · Was der Plan annimmt, das die Anforderungen nicht sagen

- Die Tabellennamen der Kern-Fakes und die Namen der neuen Wächter.
- Dass die Renderer zwischen Schritt 4 und 7 aus dem neuen Modell zeichnen, während die alten
  Kanten noch stehen — die Übergangszeit ist bewusst, damit jeder Schritt grün bleibt.
- Dass `Einheitenwert` (Zahl + Einheit + Präfix) als Klasse mit dem Wähler «Basiseinheit» in
  Schritt 4 nur erklärt und in Schritt 7 im Gerüst verdrahtet wird.
- Die Reihenfolge 1 → 2 → 3 → 4 → 5 → 6 → 7. Schritt 2 ist unabhängig von 3 bis 6 und könnte
  auch zuletzt kommen.

**Nicht enthalten:** die Konfigurationsseite (TASK-080/086, bei Opus), das Umrechnen beim
Umschalten (Anforderung 3.6.6, offen), `hide` am Feld (offen).
