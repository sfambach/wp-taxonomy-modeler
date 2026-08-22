> ## ⚠️ FROZEN — LEGACY DOCUMENT
>
> This file belongs to the **pre-2026-08-22 planning round** and is **no longer maintained**.
>
> - Do **not** edit it. Do **not** treat it as source of truth. Do **not** implement from it.
> - It is kept as a **quarry**: content reaches the new concept only through a reviewed
>   harvest sheet (see [`../NewConcept/README.md`](../NewConcept/README.md)).
> - Version numbers, `Q<n>` question ids, status flags and decision-log entries in here
>   describe the **old** model. They carry no authority over the new one.

# Model catalog (Fallstudie / `wtt_fs`)

Snapshot of **Model/** attribute hosts for the top-down **BOM** spine (Q85 composition-first).

> **Update policy:** Agents refresh this file **only when the user explicitly asks**
> (e.g. “Model-Katalog aktualisieren”, “Modelle speichern”, “update model catalog”).
> Do **not** rewrite it on routine seed/ensure/UI work.

| Field | Value |
|-------|--------|
| Taxonomy | `wtt_fs` |
| Path | `Fallstudie/Model/…` |
| Last snapshot | 2026-08-08 |
| Plugin ≈ | `0.0.349` |

---

## Top-down BOM spine

```text
Platine
  ├── Name, Version, Gerber…, Bestellt wo → Kontakt, Stück, Preis, …  (besteht_aus)
  └── Bauteilliste → Bauteilliste                                      (aggregation)
        ├── Name                                                       (besteht_aus)
        └── Position[0..*] → Bauteillisten Position                    (aggregation)
              ├── Referenz (text)
              ├── Wert → Bauteil
              ├── Menge (int)
              ├── Beschreibung (text, 0..1)
              └── Auf Lager (bool, 0..1)
```

Not a Collection `table`/`list` type (Q90 parked). Table UI = view of composition.

Lieferant / supplier catalog is **out of this cut** (left for later).

**Aggregation** = Platine owns/links Bauteilliste; Bauteilliste owns/links line objects (same Bindung pattern).

---

## Kontakt

Person + address (also type for Platine **Bestellt wo**).

| Attribute | Type | Mult. |
|-----------|------|-------|
| Titel | text | 1 |
| Name | text | 1 |
| Vorname | text | 1 |
| E-Mail | email | 1 |
| Telefon | text | 1 |
| Strasse | text | 1 |
| Hausnummer | text | 1 |
| Postleitzahl | text | 1 |
| Ort | text | 1 |

---

## Bauteillisten Position

Minimal BOM line object (sibling under `Model/`, formerly `Position`).

| Attribute | Type | Mult. | Notes |
|-----------|------|-------|--------|
| Referenz | text | 1 | Designator (PCB, R1, …) |
| Wert | Bauteil | 1 | Part pick (kind / catalog) |
| Menge | int | 1 | Stück (Q58) |
| Beschreibung | text | 0..1 | |
| Auf Lager | bool | 0..1 | In stock |

Sample line (ESP8266-RS232): `PCB | (Bauteil) | 1 | ESP8266-RS232 Leiterplatte | true`

---

## Bauteilliste

BOM / parts list (alias concept: **BOM**).

| Attribute | Type | Mult. | Notes |
|-----------|------|-------|--------|
| Name | text | 1 | |
| Position | Bauteillisten Position | 0..* | **aggregation** |

---

## Platine

PCB / board — **slim** cut (review / Optionen / Protokoll extras removed for now).

| Attribute | Type | Mult. | Notes |
|-----------|------|-------|--------|
| Name | text | 1 | Board title |
| Version | text | 0..1 | |
| Gerber vorhanden | bool | 1 | |
| Gerberdatei | media | 1 | |
| Bestellt wo | Kontakt | 1 | Fab contact |
| Stück | int | 1 | Order qty |
| Preis | double | 1 | Order total |
| Besonderheiten | text | 0..1 | |
| Bauteilliste | Bauteilliste | 0..1 | Linked BOM — **aggregation** |

---

## Bauteil

Kind host under `Model/Bauteil`. Groups = inheritance folders (Q88). MPN records live under Implementation/Bauteile when that branch exists (Q83). No Lieferant / Bestellnummer / Hersteller on kinds.

### Passiv

#### Widerstand

| Attribute | Type |
|-----------|------|
| Wert | double |
| Praefix | Präfixe |
| Einheit | Basiseinheiten |
| Bauform | text |
| Toleranz | text |
| Nennleistung | text |
| Datenblatt | media |

#### Kondensator

| Attribute | Type |
|-----------|------|
| Wert | double |
| Praefix | Präfixe |
| Einheit | Basiseinheiten |
| Nennspannung | text |
| Dielektrikum | text |
| Bauform | text |
| Datenblatt | media |

#### Spule

| Attribute | Type |
|-----------|------|
| Wert | double |
| Praefix | Präfixe |
| Einheit | Basiseinheiten |
| Nennstrom | text |
| Bauform | text |
| Datenblatt | media |

### Halbleiter

#### Dioden

CatalogChoice Arten (no extra slots on the kind host):

- Schalt, Schottky, Zener, Gleichrichter, TVS, LDD

#### Transistor

| Attribute | Type |
|-----------|------|
| Transistortyp | text |
| U_max | text |
| I_max | text |
| Bauform | text |
| Datenblatt | media |

#### LED

| Attribute | Type |
|-----------|------|
| Farbe | text |
| U_f | text |
| I_f | text |
| Bauform | text |
| Datenblatt | media |

#### IC

| Attribute | Type |
|-----------|------|
| Funktion | text |
| Gehaeuse | text |
| Versorgung | text |
| Datenblatt | media |

### Elektromechanik

#### Relais

| Attribute | Type |
|-----------|------|
| Spulenspannung | text |
| Kontakt | text |
| Bauform | text |
| Datenblatt | media |

#### Steckverbinder

| Attribute | Type |
|-----------|------|
| Steckertyp | text |
| Polzahl | int |
| Bauform | text |
| Datenblatt | media |

#### Schalter

| Attribute | Type |
|-----------|------|
| Schaltertyp | text |
| Pole | text |
| Bauform | text |
| Datenblatt | media |

### Sonstige

#### Quarz

| Attribute | Type |
|-----------|------|
| Wert | double |
| Praefix | Präfixe |
| Einheit | Basiseinheiten |
| Lastkapazitaet | text |
| Bauform | text |
| Datenblatt | media |

#### Sicherung

| Attribute | Type |
|-----------|------|
| Nennstrom | text |
| Charakteristik | text |
| Nennspannung | text |
| Bauform | text |
| Datenblatt | media |

---

## How to refresh

User phrase examples (German or English):

- „Model-Katalog aktualisieren“
- „Modelle speichern“
- „update model catalog“

Then re-read live `wtt_fs` → `Fallstudie/Model` and replace the tables above.
