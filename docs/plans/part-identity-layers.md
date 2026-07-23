---
name: Part identity layers
overview: Planning note to untangle “same 100 Ohm” across packages/technologies (resistors, capacitors, diodes, ICs) using kind → subtype → specs → package → catalog part → board usage.
status: draft
version: "0.1.0-plan"
last_updated: "2026-07-23"
related_plans:
  - docs/plans/project-plan.md
  - docs/plans/data-structure.md
  - docs/plans/example-projects.md
  - docs/plans/use-cases.md
todos:
  - id: layers-agreed
    content: "User agrees identity layers are a useful mental model (not yet storage decision)"
    status: in_progress
  - id: storage-mapping
    content: "Later: map layers to Node / Relation / host BOM (depends Q33–Q35, Q45)"
    status: pending
---

# Part identity layers (planning)

> Untangle thoughts like: “same 100 Ω on three boards — SMD 0805, through-hole, shunt with tabs.”  
> This is a **mental model**, not yet a locked storage schema. Open questions stay open.

## The overwhelm

What feels like one thing (“100 Ohm Widerstand”) is actually **several layers** stacked:

| Your examples | What actually differs |
|---------------|----------------------|
| SMD board: 100 Ω, small, **0805** | Package / mounting = SMD |
| Other board: 100 Ω **through-hole** | Package / mounting = THT |
| Other board: 100 Ω **shunt** with two solder tabs | **Subtype** (Shunt) + special Bauform + often different electrical role |

Same **electrical value** (100 Ω). Different **construction / package / subtype**. On each board, also a different **usage** (R12 vs R3 vs shunt sense).

## Six layers (keep separate in your head)

```text
1. Kind          Widerstand | Kondensator | Diode | IC | …
2. Subtype       Shunt | Draht | Metallschicht | Keramik | Zener | OpAmp | …
3. Spec skeleton attributes this kind/subtype always has
                 (Wert, Toleranz, Leistung, …)  — consists_of / quantity / enum
4. Package       0805, 0603, axial THT, shunt-tabs, TO-220, SOIC-8, …
5. Catalog part  orderable concrete part (all attrs filled; often a leaf)
6. Board usage   BOM line: refs R1,R2 + qty + this catalog part  — HOST
```

```text
Kind ─is_a→ Subtype ─has→ Spec skeleton
                              │
                              ├─ filled quantity e.g. Wert = 100 Ω  (unit group k?+Ohm)
                              ├─ Toleranz, Leistung, Material, Series (E24), …
                              └─ Package / Bauform = 0805 | axial | shunt-tabs | …

Catalog part = one Node (or Parameter-set) that binds Spec + Package (+ manufacturer/SKU)
Board usage  = Host BOM line → points at Catalog part (not at “100 Ω” alone)
```

### Layer cheat-sheet

| Layer | Question it answers | Tree vs host |
|-------|---------------------|--------------|
| 1 Kind | What family? | Tree (`is_a`) |
| 2 Subtype | Which specialization? | Tree (`is_a`) |
| 3 Spec skeleton | Which attributes exist? | Tree defs (`consists_of`) |
| 4 Package | How is it built/mounted? | Attr / enum / subtype branch |
| 5 Catalog part | What do I order? | Tree leaf (or host SKU) |
| 6 Board usage | Where is it on *this* PCB? | **Host BOM** |

**Rule of thumb:**  
- If two things differ only by **where** they sit on a board → same catalog part, different BOM lines.  
- If they differ by **package/technology** (0805 vs THT vs shunt tabs) → **different catalog parts**, same Wert attribute.  
- “100 Ω” alone is usually **not** enough as the selectable leaf for procurement.

## Worked: three “100 Ω” resistors

| Board situation | Kind | Subtype | Wert | Package | Catalog part (example name) |
|-----------------|------|---------|------|---------|------------------------------|
| SMD 0805 | Widerstand | (z.B. Dickschicht) | 100 Ω | SMD 0805 | `R 100Ω 0805 0.125W 1%` |
| Through-hole | Widerstand | (z.B. Metallfilm) | 100 Ω | axial THT | `R 100Ω axial 0.25W 1%` |
| Shunt with tabs | Widerstand | **Shunt** | 100 Ω | solder-tab / shunt body | `Shunt 100Ω tabbed …` |

Shared: `quantity` Wert = 100 + unit group `(—, Ohm)`.  
Different: subtype and/or package (± power, material, series).  
On BOMs: three lines can all say “100 Ω” in a column, but they **reference three catalog parts**.

### Where “Reihe” (E12/E24) fits

- **Series** = preferred-value set (often enum or a Definition branch), attribute of the catalog part or of a value template.  
- Not a package. Not a board usage.  
- Example: part participates in E24; Wert still `100 Ω`.

### Where Größe / Leistung / Material fit

| Property | Layer | Type hint |
|----------|-------|-----------|
| Wert | Spec / filled | `quantity` → Ohm (+ prefix if kΩ) |
| Toleranz | Spec | `quantity` (%) or enum |
| Leistungsaufnahme | Spec | `quantity` → Watt |
| Material | Spec | `enum` (single) |
| Reihe | Spec | `enum` or ref to series node |
| Bauform / Package | Package | `enum` (0805, 0603, axial, …) or subtype |
| Montage SMD/THT | Package / subtype | `enum` or derived from package |
| Shunt-Fahnen | Package + subtype Shunt | Bauform + subtype attrs (Strom, …) |

## Same pattern: Kondensatoren

| Situation | Kind | Subtype | Shared “value” | What differs |
|-----------|------|---------|----------------|--------------|
| 100 nF 0805 X7R | Kondensator | Keramik MLCC | 100 nF | Package 0805, Dielektrikum X7R |
| 100 nF THT Folie | Kondensator | Folie | 100 nF | THT package, Folie |
| 100 µF Elko radial | Kondensator | Elektrolyt | 100 µF | Polarized, voltage rating, radial can |

Layers: Wert (Farad + prefix), Nennspannung, Dielektrikum/Material, Polarität, Package — then catalog part — then BOM usage.

## Same pattern: Dioden

| Situation | Kind | Subtype | Shared idea | What differs |
|-----------|------|---------|-------------|--------------|
| 1N4148 signal | Diode | Schalt/Signal | — | Package DO-35 / SOD-123, If, Vr |
| Schottky 1A SMB | Diode | Schottky | — | Package SMB, If, Vf |
| Zener 5V1 0.5W | Diode | **Zener** | Vz ≈ 5.1 V | Package, power, Tol |

Here “value” is often **Vz / Vr / If** (quantities), not one universal “100”.  
Subtype (Zener vs Schottky) changes the **spec skeleton** (which attributes matter).

## Same pattern: ICs

| Situation | Kind | Subtype | Shared idea | What differs |
|-----------|------|---------|-------------|--------------|
| NE555 in DIP-8 | IC | Timer | Function family | Package DIP-8 |
| NE555 in SOIC-8 | IC | Timer | Same function | Package SOIC-8 |
| LM358 OpAmp | IC | **OpAmp** | Dual op-amp | Package, rail voltage, … |

ICs: **function/subtype** dominates; “Wert” is rarely one quantity — more enums + quantities (Vcc, bandwidth) + **package**.  
Same die / same function in DIP vs SOIC = **two catalog parts** (like 100 Ω 0805 vs THT).

## How to “ablegen” (practical leaning — not locked)

### In the tree (taxonomy-tree)

1. **Kind / subtype branches** under Bauteile (`is_a`):  
   `Widerstand → {Metallfilm, Dickschicht, Shunt, …}`  
   `Kondensator → {Keramik, Folie, Elko, …}`  
   `Diode → {Signal, Schottky, Zener, …}`  
   `IC → {OpAmp, Timer, MCU, …}`
2. **Attribute skeletons** per subtype (`consists_of` / defs): Wert, Toleranz, Leistung, Package, …  
3. **Catalog leaves** (or near-leaves): one node per orderable combination you care about.  
4. **Unit group** for quantities: Präfix+Basiseinheit together; value often on relation/props (Q45).

### In the host (BOM)

- Platine A/B/C each have **BOM lines** pointing at the **catalog part**.  
- Columns can still show Wert / Package derived from that part.  
- “Same 100 Ω” search = filter catalog/BOM by quantity Wert, not by collapsing three parts into one node.

### What not to do

- One node “100 Ω Widerstand” as the only leaf for SMD + THT + Shunt.  
- Encode board designators (R1, R2) as tree children of the resistor.  
- Model quantity as Widerstand → 100 → kilo → Ohm chain (use **unit group**).

## Mapping to current domain objects (reminder)

| Layer | Likely object |
|-------|----------------|
| Kind / subtype | `Node` + Relation `is_a` |
| Spec skeleton | `consists_of` / Parameter defs |
| Filled Wert | `quantity` + unit group; maybe Relation.props |
| Package | enum attr or Bauform node |
| Catalog part | `Node` (leaf) |
| Board usage | **Host** BOM line → part Node id |

Storage choice Parameter-as-Node vs Relation vs host SKU remains open (Q33–Q35).  
This note only says: **keep the layers distinct when thinking**.

## Mini decision guide

When stuck, ask:

1. Same **orderable** thing? → one catalog part.  
2. Same Wert, different package/subtype? → two catalog parts, shared attribute pattern.  
3. Only different place on a PCB? → one catalog part, two BOM lines.  
4. Shunt vs normal R? → different **subtype** (and usually package), not only Bauform text.
