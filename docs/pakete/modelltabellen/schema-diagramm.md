# Modelltabellen — Diagramm zum Spielen

**Stand 2026-09-04.** Gibt den Soll-Zustand aus [`package.md`](package.md) wieder.
**Zum Ausprobieren gedacht** — Änderungen hier sind kein Konzept, bis sie dort ankommen.

```mermaid
erDiagram
    nodes {
        int  id PK
        int  version
        int  parent_node_id FK "auf nodes.id — Vererbung"
        int  sort_order     "Stelle unter dem Vater"
        enum field_type     "model | setting"
        int  label_id       FK "verpflichtend"
        int  settings_record_id FK "auf node_records — optional"
        text php_klasse
        bool hide "D-590: nur im Baum verstecken"
    }

    relations {
        int  id PK
        int  version
        int  from_node_id FK
        int  to_node_id   FK
        bool deletes_with_node "D-591: aus relation_type abgebildet"
        int  label_id      FK "optional"
        int  settings_record_id FK "der eigene Form-Renderer der Kante"
        int  target_settings_record_id FK "ueberschreibt den Zielknoten"
        int  sort_order    "je Knoten und Kantenart, erste ist 0"
        text multiplicity  "1..1 | 0..1 | 1..* | 0..*"
    }

    node_records {
        int  id PK
        int  version
        int  node_id FK
        int  node_version "gegen welche Knotenversion"
        enum record_type  "default | user"
    }

    relation_records {
        int     id PK
        int     version
        int     node_record_id FK "zu welcher Auspraegung"
        int     relation_id    FK "welches Feld"
        int     sort_order        "innerhalb eines Feldes, erste ist 0"
        int     value_int
        decimal value_decimal
        text    value_text
        date    value_date
        int     value_node_record_id FK "ODER Verschachtelung"
        int     value_node_id        FK "ODER Knotenverweis"
    }

    labels {
        int  id PK
        text name   "sprachunabhaengig"
        text symbol
        text icon
    }

    label_texts {
        int  label_id FK
        text locale
        text form
        text table
        text select
        text help
    }

    nodes            ||--o{ nodes            : "parent_node_id"
    nodes            ||--o{ relations        : "from_node_id"
    nodes            ||--o{ relations        : "to_node_id"
    nodes            ||--o{ node_records     : "node_id"
    labels           ||--o{ nodes            : "label_id"
    labels           ||--o{ relations        : "label_id"
    labels           ||--o{ label_texts      : "label_id"
    node_records     ||--o{ relation_records : "node_record_id"
    relations        ||--o{ relation_records : "relation_id"
    node_records     ||--o| nodes            : "settings_record_id"
    node_records     ||--o| relations        : "settings_record_id"
    node_records     ||--o| relations        : "target_settings_record_id"
    node_records     ||--o{ relation_records : "value_node_record_id"
    nodes            ||--o{ relation_records : "value_node_id"
```

## Die zwei Schlüssel

```text
from_node_id · relation_type · sort_order      Reihenfolge der Felder unter einem Knoten
node_record_id · relation_id · sort_order      Reihenfolge der Werte in einem Feld
```

## Was offen ist

- Was bei einem Konflikt zwischen `node_records.node_version` und dem Knoten geschieht

## Was seit dem 2026-09-02 beantwortet ist

**Ob `settings_record_id` ein zweiter Mechanismus ist** — nein. *Der Verdacht kam daher, dass
[D-529](../../NewConcept/90-decision-log.md) sagt «eine Einstellung ist ein Feld, also eine Kante».
**Mit genau einem Renderer je Knoten und Kante** ([D-584](../../NewConcept/90-decision-log.md)) ist
ein einzelner Zeiger auf einen einzelnen Datensatz genau richtig, und dessen `node_id` sagt schon,
welcher Renderer es ist.*

**Wo der Konverter hängt** — am Basisknoten `Renderer`, vererbt an alle 18
([D-585](../../NewConcept/90-decision-log.md)). *Der Hüllknoten `DisplayOption` entfällt: er sah
immer gleich aus und trug damit keine Aussage.*
