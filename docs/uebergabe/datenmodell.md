# Das Datenmodell, wie es heute wirklich aussieht

**Zweck.** Grundlage für den Entwurf des Ereigniskonzepts: was adressierbar ist, wie eine Adresse
aussieht, und was sich ändern kann.

**Status.** **Abgeleitet, nicht Quelle** — die Quelle ist [`docs/NewConcept/`](../NewConcept/README.md).
**Jede Zahl darin ist an der laufenden Installation gemessen**, am 2026-08-31, nicht geschätzt.

---

## 1 · Zwölf Tabellen, vier davon tragen das Modell

| Tabelle | Zeilen | Was |
|---|---|---|
| `taxmod_nodes` | 130 | **Knoten** — ein Ding im Modell |
| `taxmod_relations` | 169 | **Kante** — Vererbung oder Feld zwischen zwei Knoten |
| `taxmod_records` | 204 | **Knoten-Datensatz** — eine Ausfüllung eines Knotens |
| `taxmod_record_values` | 183 | **Kanten-Datensatz** — eine Wertzeile darin |
| `taxmod_labels` | 47 | Texte je Rolle, Nummer und Sprache |
| `taxmod_identities` | 64 261 | vergebene Ids, damit keine zweimal entsteht |
| `taxmod_changelog` | 23 067 | wer wann was geändert hat |
| `taxmod_settings` | **3** | die alte Einstellungstabelle, im Abbau |
| `*_history` (4×) | 6 545 / 7 996 / 109 / 885 | die **Schattentabellen** |

Die Begriffe sind verbindlich: **Knoten, Kante, Knoten-Datensatz, Kanten-Datensatz.**

---

## 2 · Die vier tragenden Tabellen, Spalte für Spalte

### `taxmod_nodes` — der Knoten

```
id            bigint unsigned   PRIMARY
version       int unsigned      default 1
name          varchar(191)      INDEX
path          varchar(255)      INDEX
kind          varchar(20)       NULL
```

* `path` ist ein **materialisierter Pfad aus Ids**: `1.406.410`. Die Wurzel ist `1`.
  Damit sind «alle Nachfahren» und «alle Vorfahren» je **eine** Abfrage.
* `name` ist **absichtlich nicht eindeutig** — es gibt drei Knoten namens `form`. Der Index ist
  keine Eindeutigkeit. **Nichts wird über einen Namen gefunden, wenn eine Id verfügbar ist.**
* `kind`: gemessen **126×`null`** und **4×`setting`**. Die Marke sagt «dies ist ein
  Einstellungsknoten» und wird von der Auswahl übersprungen.

### `taxmod_relations` — die Kante

```
id                 bigint unsigned   PRIMARY
version            int unsigned      default 1
from_id            bigint unsigned   INDEX
to_id              bigint unsigned   INDEX
kind               varchar(20)
name               varchar(191)
position           int unsigned      default 0
parked_by_group_id bigint unsigned   NULL, INDEX
hide               tinyint unsigned  default 0
multiplicity       varchar(10)       default '1..1'
```

Gemessene Verteilung:

| `kind` | Anzahl | | `multiplicity` | Anzahl |
|---|---|---|---|---|
| `inheritance` | 129 | | `1..1` | 159 |
| `composition` | 24 | | `0..1` | 5 |
| `setting` | 11 | | `1..*` | 3 |
| `aggregation` | 5 | | `0..*` | 2 |

* `position` ist die **einzige** Heimat der Reihenfolge — ein Knoten ordnet sich über seine
  Vererbungskante, ein Feld über seine Feldkante.
* `hide` ist eine **Spalte**, keine Einstellung. Eine versteckte Kante wird auf keiner Ebene
  gezeichnet, weil der Abstieg sie nie erreicht.
* `parked_by_group_id` merkt sich, mit welchem Akt eine Kante geparkt wurde.

### `taxmod_records` — der Knoten-Datensatz

```
id            bigint unsigned   PRIMARY
node_id       bigint unsigned   INDEX
node_version  int unsigned
created_at    datetime
kind          varchar(20)       default 'user', INDEX
version       int unsigned      default 1
```

Gemessen: **179×`default`**, **25×`user`**.

* `default` ist die **Modellebene**: dort stehen die Vorgaben und die Einstellungswerte.
  Genau **einer** je Knoten.
* `user` ist die **Datenebene**: was ein Benutzer eingegeben hat.
* `node_version` hält fest, gegen welche Fassung des Knotens geschrieben wurde.

### `taxmod_record_values` — der Kanten-Datensatz

```
id             bigint unsigned   PRIMARY
record_id      bigint unsigned   INDEX
edge_id        bigint unsigned   INDEX
path           varchar(255)
locale         varchar(20)
value_int      bigint            NULL
value_decimal  decimal(30,10)    NULL
value_text     mediumtext        NULL
value_date     datetime          NULL
value_ref      bigint unsigned   NULL, INDEX
position       int unsigned      default 0
version        int unsigned      default 1
INDEX of_field (record_id, edge_id, locale)
```

* **Kein** eindeutiger Schlüssel auf `(record_id, edge_id, locale)` — mehrere Werte eines Feldes
  sind **mehrere Zeilen** mit demselben Pfad, unterschieden durch `position` und `id`.
* `decimal(30,10)` — eine Dezimalzahl wird **nie** zu `float`.

---

## 3 · Adressierung — und hier entstehen die meisten Fehler

Drei verschiedene Dinge, die leicht verwechselt werden:

| Form | Bedeutung |
|---|---|
| **`edge_id`** | welche Kante der Wert beantwortet |
| **`path`** | die Kette von Kanten-Ids **innerhalb eines Datensatzes** |
| **`value_ref`** | der Sprung **zwischen** Datensätzen — **oder** ein Verweis auf einen Knoten |

Regeln:

1. Ein Wert direkt an seiner Kante hat `path === edge_id`. **Gemessen: alle 183 Zeilen sind heute
   so.**
2. Ein Wert an einer **Verwendungsstelle** hätte `path = "<Stelle>.<Kante>"`. Der Mechanismus ist
   gebaut und gelesen — **und es gibt aktuell keine einzige solche Zeile**. Im Schatten liegen 58.
3. Ein **Teil** — ein eigener Datensatz für ein zusammengesetztes Feld — wird über seine
   **Satz-Id** angesprochen, nie über einen zusammengesetzten Pfad. Bei mehreren Teilen tragen alle
   *dieselben* Kanten; nur der Satz unterscheidet sie.

Echte Zeilen als Beispiel:

```
Satz #2240 an «Celsius» (default)        Kante 63349  Pfad 63349
Satz #2233 an «DisplayOption» (default)  Kante 44092  Pfad 44092  value_ref -> 43531   (ein Knoten)
Satz #2838 an «Primitives» (default)     Kante 44093  Pfad 44093  value_ref -> 3061    (ein Datensatz)
```

### ⚠️ Zwei Id-Räume, und `value_ref` sagt nicht, aus welchem

**Das ist entschieden und nicht ein Versehen.** *Punkt 1 des Domänenkerns: «Model and data are two
halves … They do **not** share an id space.» **Knoten und Kanten teilen einen** ([D-090](../NewConcept/90-decision-log.md));
**Datensätze haben ihren eigenen** ([D-164](../NewConcept/90-decision-log.md), P4a).*

**Und das Konzept kannte die Folge schon:** *«identities run from 1 to 26 453, records have their own
`AUTO_INCREMENT` and run from 16 to 879. **They overlap — id 16 is at once a relation and a record.**
An owner id alone therefore does not say which space it came from, and without the column the row is
simply unreadable.»* Das Mittel ist also bekannt: **eine Spalte, die den Raum nennt.** Das
Änderungsbuch hat sie als `owner_kind`. Derselbe Absatz sagt: *«Decided, **not built**.»*

**`record_values.value_ref` hat keine solche Spalte** — und darum bedeutet die Spalte zweierlei,
unterschieden nur daran, in welcher Tabelle die Id gefunden wird.

Gemessen: **138 Werte** tragen ein `value_ref` — **49** zeigen auf einen **Knoten**, **88** auf einen
**Datensatz**. Unterschieden wird heute daran, in welcher Tabelle die Id gefunden wird.

Und die Id-Räume sind **nicht getrennt**:

| | |
|---|---|
| grösste Knoten-Id | 64 091 |
| grösste Satz-Id | 3 061 |
| nächste Knoten-Id darüber | **3 083** («Kontact») |
| **Abstand** | **22 Datensätze bis zur ersten Kollision** |

Knoten-Ids kommen aus `identities`, Satz-Ids aus dem eigenen Zähler von `records`. **42 Knoten haben
eine Id unter 4 000.** Sobald der 22. weitere Datensatz entsteht, gibt es eine Id, die **beides**
ist — und `value_ref` ist dann zweideutig. Heute: **0 Überschneidungen, 0 zweideutige Verweise, 1
Verweis, der auf nichts zeigt.**

**Für ein Ereigniskonzept heisst das: eine Adresse muss sagen, *was* sie adressiert, und darf sich
nicht darauf verlassen, dass eine Id nur eines sein kann.**

---

## 4 · Die Äste unter der Wurzel

| Ast | Id | Knoten darunter | Wofür |
|---|---|---|---|
| `Trash` | 2 | 2 | geparkt, nicht vernichtet |
| `Model` | 402 | 18 | die Dinge, die ein Autor beschreibt |
| `Compositions` | 404 | 6 | zusammengesetzte Typen (`Adresse`, …) |
| `Primitives` | 406 | 58 | Datentypen und Konstanten |
| `Settings` | 40768 | 39 | `Renderer`, `Converter`, `Validator` und die Einstellungsknoten |
| `Label roles` | 55130 | 0 | `form`, `table`, `select`, `symbol`, `help` |

---

## 5 · Einstellungen sind Kanten — die vollständige Liste

Eine Einstellung ist eine **Kante der Art `setting`**; ihr Wert liegt im `default`-Datensatz des
Knotens. Es gibt heute genau elf:

| Kante | an | zeigt auf | Mult. | Werte |
|---|---|---|---|---|
| `exponent` | `Prefixes` | `Integer` | `1..1` | 20 |
| `render` | `DisplayOption` | `Renderer` | `1..1` | 29 |
| `converter` | `DisplayOption` | `Converter` | `0..1` | 3 |
| `Display Option` | `Root` | `DisplayOption` | `1..*` | 88 |
| `validator` | `Root` | `Validator` | `0..*` | 0 |
| `read_only` | `Root` | `read_only` | `1..1` | 3 |
| `label_role` | `render with label` | `Label roles` | `1..1` | **0** |
| `with_label` | `render with label` | `Boolean` | `1..1` | 4 |
| `orientation` | `compact` | `Orientation` | `1..1` | **0** |
| `factor` | `Without prefix` | `Decimal` | `0..1` | 1 |
| `offset` | `Without prefix` | `Decimal` | `0..1` | 1 |

⚠️ **Drei davon hängen an Renderer-Knoten** — `label_role`, `with_label`, `orientation`. Zwei haben
**null** Werte, weil man sie nur erreicht, wenn man den Renderer-Knoten selbst öffnet, nicht dort, wo
man ihn auswählt. *Das ist die Lücke, die der Eigentümer benannt hat: «zu jeder Zeile, wo ich einen
Renderer eingebe, hätte ich auch Renderer-Settings.»*

---

## 6 · Geschichte und Löschen

**Gelöscht wird nicht — es wird aufgehoben.** Vor jedem Entfernen schreibt `Shadow::keep()` die
Zeilen nach `<tabelle>_history`; die Schattentabelle hat dieselben Spalten plus `deleted` und
`archived_at`, und ihr Schlüssel ist `(id, version)`.

Vier Tabellen haben einen Schatten: `nodes`, `relations`, `records`, `record_values`.
**`labels`, `settings` und `changelog` haben keinen.**

### `taxmod_changelog`

```
id               bigint unsigned   PRIMARY
owner_id         bigint unsigned   INDEX
owner_kind       varchar(20)
at               datetime          INDEX
by_user_id       bigint unsigned   NULL
what             varchar(40)
before_state     mediumtext        NULL
after_state      mediumtext        NULL
change_group_id  bigint unsigned   NULL, INDEX
version          int unsigned      NULL
```

Gemessen, und alle drei Zahlen sind für ein Ereigniskonzept wichtig:

* `owner_kind`: **`node` 16 813 · `relation` 5 589 · `installation` 3 · `gone` 3** —
  **kein einziges `record`.** Jede Modelländerung ist aufgezeichnet, **keine Datenänderung.**
* `what` ist ein **Satz**, kein Typ: **41 verschiedene Werte**, zusammengesetzt aus Verb und
  Schlüssel — `setting read_only set`, `setting converter set`, `setting range_max set`.
* `by_user_id` ist in **19 479 von 23 067** Zeilen leer: Auslieferung, Migrationen, Prüfläufe.

**Ein `POST` ist eine Handlung und bekommt eine `change_group_id`.** Gemessen tragen 2 736 von
17 524 Gruppen mehr als eine Zeile.

---

## 7 · Was ein Ereignis adressieren muss

Aus dem Obigen, ohne Wertung:

| Was | Wodurch adressiert |
|---|---|
| ein Knoten | `nodes.id` |
| eine Kante | `relations.id` |
| ein Knoten-Datensatz | `records.id` |
| ein Wert darin | `record_values.id` — **oder** `(record_id, path, locale, position)` |
| eine Verwendungsstelle | die Kante, plus der Knoten, an dem sie benutzt wird |
| ein Teil | seine **Satz-Id** |
| eine Handlung | `change_group_id` |

Und die drei Fallen: **`value_ref` ist zweideutig** (Abschnitt 3), **`name` ist nicht eindeutig**
(Abschnitt 2), und **mehrere Werte eines Feldes teilen ihren Pfad** — unterschieden durch
`position` und `id`.
