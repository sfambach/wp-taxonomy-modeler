# Model catalog (Fallstudie / `wtt_fs`)

Snapshot of **Model/** attribute hosts and Bauteil kinds as seeded in the scaffold.

> **Update policy:** Agents refresh this file **only when the user explicitly asks**
> (e.g. “Model-Katalog aktualisieren”, “Modelle speichern”, “update model catalog”).
> Do **not** rewrite it on routine seed/ensure/UI work.

| Field | Value |
|-------|--------|
| Taxonomy | `wtt_fs` |
| Path | `Fallstudie/Model/…` |
| Last snapshot | 2026-08-07 |
| Plugin ≈ | `0.0.343` |

---

## Kontakt

Person + address (also used as type for Platine **Bestellt wo**).

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

## Platine

PCB / board — mirrors Retro Projekt post tables (Fakten, Optionen, Aufbau, Protokoll).

| Attribute | Type | Mult. | Notes |
|-----------|------|-------|--------|
| Name | text | 1 | Board title |
| Version | text | 0..1 | e.g. Meine Version |
| Gerber vorhanden | bool | 1 | |
| Gerberdatei | media | 1 | |
| Bestellt wo | Kontakt | 1 | Fab / vendor |
| Stück | int | 1 | Order qty |
| Preis | double | 1 | Order total (aliases: Preis inclusive, …) |
| Besonderheiten | text | 1 | Lead-free, color, … |
| Optionen | textarea | 0..1 | Variants table |
| Erfolgreich | bool | 1 | Build review |
| Preis Pro Stück | double | 1 | |
| Lötdauer | text | 1 | |
| Schwierigkeitsgrad | text | 1 | alias: Schwierigkeitsfaktor |
| Funktion | text | 1 | Schlecht / OK / Gut / Klasse |
| Lohnt es sich | textarea | 1 | |
| Einschränkungen | textarea | 1 | |
| Protokoll | textarea | 0..1 | Dated change log |

---

## Bauteil

Kind host under `Model/Bauteil`. Groups = inheritance folders (Q88). MPN records live under Implementation/Bauteile when that branch exists (Q83).

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

Then: re-read live `Fallstudie/Model` (Attribute::list_own + Bauteil groups/kinds) and replace the snapshot tables above; bump **Last snapshot** / plugin version note.
