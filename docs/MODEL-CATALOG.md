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

## Partner (planned — replace flat Kontakt)

> **Status:** Planning agreed 2026-08-07. **Not yet seeded** under `Fallstudie/Model`.  
> Adopt into the tree later: replace flat `Model/Kontakt` with this host family.  
> Until then, the scaffold still seeds **Kontakt** (see [Current scaffold: Kontakt](#current-scaffold-kontakt)).

B2B-style partner model for BOM / procurement / returns:

- One **Organisation** (e.g. Conrad, Reichelt) has many **Person** contacts.
- Roles are **multi-valued** (Lieferant *and* Kunde / Empfänger) — not `child_of` kinds.
- **Adresse** is a reusable composition host (billing / shipping / returns).

### Intended tree shape

```text
Model/
  Partner/                 ← abstract host (is_abstract)
    Organisation/          ← child_of Partner
    Person/                ← child_of Partner
  Adresse/                 ← schema host (composition target)
  PartnerRolle/            ← CatalogChoice (depth ≤ 1 → ListChooser)
    Lieferant | Kunde | Support | Empfänger | Absender | Hersteller
  AdressArt/               ← CatalogChoice
    Hauptadresse | Lieferadresse | Rechnungsadresse | Retourenadresse
```

### Class diagram

```mermaid
classDiagram
  direction TB

  class Partner {
    <<abstract>>
    +text Name [1]
    +PartnerRolle[] Rollen [1..*]
    +text Notiz [0..1]
    +bool Aktiv [1]
  }

  class Organisation {
    +text Rechtsform [0..1]
    +text USt_IdNr [0..1]
    +text Webseite [0..1]
    +text Kundennummer_bei_uns [0..1]
    +text Unsere_KdNr_bei_Partner [0..1]
  }

  class Person {
    +text Anrede [0..1]
    +text Titel [0..1]
    +text Vorname [1]
    +text Nachname [1]
    +text Funktion [0..1]
    +email E-Mail [0..1]
    +text Telefon [0..1]
    +text Mobil [0..1]
  }

  class Adresse {
    +AdressArt Art [1]
    +text Strasse [1]
    +text Hausnummer [0..1]
    +text Postleitzahl [1]
    +text Ort [1]
    +text Land [1]
    +text Zusatz [0..1]
  }

  class PartnerRolle {
    <<CatalogChoice>>
  }
  class Lieferant
  class Kunde
  class Support
  class Empfaenger
  class Absender
  class Hersteller

  class AdressArt {
    <<CatalogChoice>>
  }
  class Hauptadresse
  class Lieferadresse
  class Rechnungsadresse
  class Retourenadresse

  Partner <|-- Organisation : child_of
  Partner <|-- Person : child_of

  PartnerRolle <|-- Lieferant
  PartnerRolle <|-- Kunde
  PartnerRolle <|-- Support
  PartnerRolle <|-- Empfaenger
  PartnerRolle <|-- Absender
  PartnerRolle <|-- Hersteller

  AdressArt <|-- Hauptadresse
  AdressArt <|-- Lieferadresse
  AdressArt <|-- Rechnungsadresse
  AdressArt <|-- Retourenadresse

  Organisation "1" *-- "0..*" Adresse : besteht_aus
  Organisation "1" *-- "0..*" Person : besteht_aus Ansprechpartner
  Person "0..1" --> "0..1" Organisation : gehoert_zu
  Person "0..*" *-- "0..*" Adresse : besteht_aus
```

### Partner (abstract host)

| Attribute | Type | Mult. | Notes |
|-----------|------|-------|--------|
| Name | text | 1 | Display name (org or person label) |
| Rollen | PartnerRolle | 1..* | Multi-role; not hierarchy kinds |
| Notiz | text | 0..1 | Free note |
| Aktiv | bool | 1 | Soft disable |

### Organisation (`child_of` Partner)

| Attribute | Type | Mult. | Notes |
|-----------|------|-------|--------|
| Rechtsform | text | 0..1 | GmbH, SE, … |
| USt-IdNr | text | 0..1 | VAT id |
| Webseite | text | 0..1 | |
| Kundennummer bei uns | text | 0..1 | Our account number for this partner |
| Unsere KdNr bei Partner | text | 0..1 | Their customer number for us |
| Adresse | Adresse | 0..* | `besteht_aus` |
| Ansprechpartner | Person | 0..* | `besteht_aus` — people at this org |

### Person (`child_of` Partner)

| Attribute | Type | Mult. | Notes |
|-----------|------|-------|--------|
| Anrede | text | 0..1 | |
| Titel | text | 0..1 | Dr., … |
| Vorname | text | 1 | |
| Nachname | text | 1 | |
| Funktion | text | 0..1 | Job role at org / context |
| E-Mail | email | 0..1 | |
| Telefon | text | 0..1 | |
| Mobil | text | 0..1 | |
| gehört zu | Organisation | 0..1 | Optional; private customer has none |
| Adresse | Adresse | 0..* | `besteht_aus` when person has own address |

### Adresse

| Attribute | Type | Mult. | Notes |
|-----------|------|-------|--------|
| Art | AdressArt | 1 | CatalogChoice |
| Strasse | text | 1 | |
| Hausnummer | text | 0..1 | |
| Postleitzahl | text | 1 | |
| Ort | text | 1 | |
| Land | text | 1 | |
| Zusatz | text | 0..1 | c/o, floor, … |

### PartnerRolle (CatalogChoice)

Lieferant, Kunde, Support, Empfänger, Absender, Hersteller.

### AdressArt (CatalogChoice)

Hauptadresse, Lieferadresse, Rechnungsadresse, Retourenadresse.

### Migration notes (when adopting into tree)

| Current scaffold | Planned |
|------------------|---------|
| `Model/Kontakt` (flat person+address) | `Model/Partner` + `Organisation` / `Person` + `Adresse` |
| Platine **Bestellt wo** → Kontakt | → Partner (prefer Organisation / Lieferant) |
| Relais attribute named Kontakt (text) | Unrelated — keep as text slot on Relais |

Open decisions before seed: Person without Organisation (yes for private customers); whether `Bestellt wo` binds `Partner` or only `Organisation`.

---

## Current scaffold: Kontakt

> Live seed today (`ensure_kontakt_model`). Superseded by [Partner (planned)](#partner-planned--replace-flat-kontakt) when adopted.

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
| Bestellt wo | Kontakt | 1 | Fab / vendor — **planned:** Partner |
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

Then: re-read live `Fallstudie/Model` (Attribute::list_own + Bauteil groups/kinds) and replace the **seeded** snapshot tables above; bump **Last snapshot** / plugin version note.

Do **not** drop the [Partner (planned)](#partner-planned--replace-flat-kontakt) section on a routine refresh — only change it when the user revises the Partner plan or asks to adopt it into the tree seed.
