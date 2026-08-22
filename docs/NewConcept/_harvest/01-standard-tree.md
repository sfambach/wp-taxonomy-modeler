---
title: Harvest sheet 01 — the standard tree
status: awaiting owner review
source: scripts/fixtures/test-template-wtt_fs.json (288 nodes, 15 levels, plugin 0.0.479, exported 2026-08-12)
last_updated: 2026-08-22
---

# Harvest 01 — the standard tree from the previous project

The owner pointed at a saved tree with models, base units and parts, and asked for it to be read
and taken over — **not one to one**, and with anything that contradicts the current concept
raised **before** adoption.

Read, not adopted. Every row needs a decision.

Related files, unread so far: `tree-snapshot-wtt_fs-2026-08-07.json` (114 nodes),
`tree-snapshot-wtt_tree-2026-08-02.json` (142, "BOM Testprojekt"),
`tree-snapshot-category-2026-08-02.json` (328, a WordPress category tree).

## The shape of it

```
Fallstudie
├── Definition          Aggregate · Eigene Datentypen · Konstanten
│                       Simple Datatypes · Complex Datatypes
├── Model               Bauteil · Bauteilliste · Kontakt · Platine
│                       Bauteillisten Position
├── Relationstypen      aggregation · besteht_aus · calc · child_of
│                       defaultvalue_from · has_type · ref_scope
└── Implementation      Bauteile · BOM · Lieferanten
```

**The top-level split already is [D-026](../90-decision-log.md):** *Definition + Model* is the
model layer, *Implementation* is the data layer. That was in place before we decided it — good
independent confirmation, and worth noting that the previous round got this right.

---

## A · Confirms the current concept

| # | Finding | Recommendation | Owner |
|---|---|---|---|
| **A1** | `Complex Datatypes › Unit type` is composed of **Menge + Base unit + Praefix**. | **take** — this is [C44](../10-domain-core.md) exactly, arrived at independently. The owner's mid-thought correction matches what the previous project actually built. | ☐ |
| **A2** | `Complex Datatypes › quantity › Preis` is composed of **Wert + Währung**. | **take** — structurally identical to A1. Strong evidence that the *two-part split* of [C40](../10-domain-core.md) is **two branches of one shape**, not two mechanisms → answers [OQ-040](../91-open-questions.md). | ☐ |
| **A3** | `Basiseinheiten` splits into **`With prefix`** and **`Without prefix`**; Kelvin, Celsius and Stück sit under the latter. Children inherit `Praefix` and `Kuerzel` from the branch. | **take** — a real modelling insight: *takes a prefix* is a property of a family, expressed by inheritance. This is [C43](../10-domain-core.md) working. | ☐ |
| **A4** | Attributes appear as **children** of their owning node (`Bauteilliste › Name, Bauart, Position`). | **take** — consistent with [D-031](../90-decision-log.md), attribute = relation. | ☐ |
| **A5** | `Eigene Datentypen` exists as a branch beside `Simple`/`Complex`. | **take** — confirms [V7](../00-vision-and-scope.md): the author defines types. | ☐ |

---

## B · Contradicts the current concept — decide before adopting

| # | Finding | Conflict | Recommendation | Owner |
|---|---|---|---|---|
| **B1** | **`Relationstypen` is a branch of the tree** — relation kinds stored as **nodes**. | [D-036](../90-decision-log.md) made kinds an enum. | **DROP** ✔ *Owner, 2026-08-22: the kind nodes existed only to show which kinds there are; they do not belong in the tree.* → [D-038](../90-decision-log.md) | ☑ |
| **B2** | Seven kinds, not three: plus `has_type`, `defaultvalue_from`, `calc`, `ref_scope`. | [C10](../10-domain-core.md) named three. | **REWORK** ✔ Three of the four became *fields or settings* in the new concept, not edges — mapping below. The fourth is B7. | ☑ |
| **B3** | **`Kilogramm` listed as a base unit *with prefix*.** | Yields *kilo-kilogram*. | **FIX — `Gramm` is the base, the prefix carries the kilo.** ✔ *Owner, 2026-08-22: the SI unit is the kilogram, but in this data model it is simply sensible to store the gram and let the prefix do the rest. That is what was built; Cursor kept breaking the tree and dragging old material back in.* **My reasoning in the first version of this row was wrong** — the SI base for mass *is* the kilogram. The right reason is the one the owner gives: these are called **Basiseinheiten**, not SI units, and a prefixable base has to be the unprefixed one. → [D-047](../90-decision-log.md) | ☑ |
| **B4** | `Praefix` and `Kuerzel` on parent **and** on every child. | Inheritance ([C43](../10-domain-core.md)) makes redeclaring unnecessary. | **DROP the duplication** ✔ *Owner: the old model was probably wrong here — we do not need a duplication.* The intent was: constants define the prefixes, each with a hidden multiplier field; unit types then use that node as an attribute type. → [C46](../10-domain-core.md) | ☑ |
| **B5** | `Simple Datatypes` contains **`display_node_name`**. | A displayed name is a **label** ([D-019](../90-decision-log.md)), not a type. | **REWORK into a renderer** ✔ *Owner: it was used to manipulate the output — for a prefix the symbol may be `St`, and one could choose which text to use.* That is a renderer over a node reference with a **role setting**, not a data type. → [D-044](../90-decision-log.md) | ☑ |
| **B6** | `Währung` beside `Basiseinheiten`, not under a common root. | [OQ-040](../91-open-questions.md). | **REWORK** ✔ *Owner: a weakness of the old model — Cursor kept trying to make two things of it. There are unit values; sometimes the prefix varies, sometimes the unit.* One notion, one shape. → [D-039](../90-decision-log.md) | ☑ |

### B2 in detail — what the four extra kinds actually are

| Old relation type | In the new concept | |
|---|---|---|
| `child_of` | inheritance | a kind ✓ |
| `besteht_aus` | composition | a kind ✓ |
| `aggregation` | aggregation | a kind ✓ |
| `has_type` | **`Relation.to`** — [D-025](../90-decision-log.md) | a *field*, not a kind |
| `defaultvalue_from` | **a setting holding an identity reference** — [D-030](../90-decision-log.md) | a *setting*, not a kind |
| `ref_scope` | **the chooser's branch node** — [D-035](../90-decision-log.md) | a *parameter*, not a kind |
| `calc` | **nothing yet** | ⚠️ see B7 |

So the seven-versus-three gap is mostly the new concept having moved three of them out of the
edge table — which is a simplification, not a loss. **Except one.**

| # | Finding | Recommendation | Owner |
|---|---|---|---|
| **B7** | **`calc` has no counterpart in the new concept.** [V6](../00-vision-and-scope.md) mentions *special nodes for calculations* and nothing had been designed since. The old tree also has a `Definition › Aggregate` branch — *"aggregate operations. Op is chosen on each field slot; type stays the column value type."* | **ADDRESSED** ✔ The owner restated the requirement from scratch and asked whether a calculation is a relation or another concept. Answer designed in [60 Calculation](../60-calculation.md), recorded as [D-043](../90-decision-log.md): **a property of an attribute, not a relation kind.** The old `Aggregate` branch stays a cross-check for [OQ-047](../91-open-questions.md). | ☑ |
| **B8** | The old concept let a calculation also be a **transformation** — text transformation and the like. | — | **REFUSED** ✔ The owner asked for this to be questioned. A calculation produces a value that did not exist; a **converter** changes the form of one that did. Unit conversion and text transformation are both converters, and [V8](../00-vision-and-scope.md) already provides one per node. → [D-043](../90-decision-log.md) | ☑ |

---

## C · Read but not yet judged

| # | Finding | Note |
|---|---|---|
| **C1** | `Model` contains `Bauteil` (Passiv, Halbleiter, Elektromechanik, Sonstige), `Bauteilliste`, `Kontakt`, `Platine`, `Bauteillisten Position`. | Useful as a **test case** for any core model — especially `Bauteillisten Position`, which is the node from [OQ-026](../91-open-questions.md) made real. |
| **C2** | `Implementation` holds `Bauteile`, `BOM`, `Lieferanten` — actual instances. | The data layer of [D-026](../90-decision-log.md), populated. Worth keeping as sample data once [OQ-015](../91-open-questions.md) is settled. |
| **C3** | `Konstanten` also holds `Bauformen` and `Bauteil Monatge Typen` (sic). | Enumerated choice lists as nodes. Bears on [OQ-042](../91-open-questions.md) — an attribute whose type is a branch and whose value is a node beneath it. |
| **C4** | The file's own `notes` list four known live inconsistencies, including a typo and a soft-trashed node. | The export knew it was not clean. Anything taken from it needs checking, not trusting. |

---

## What this sheet is not

It does not adopt anything. Per [D-003](../90-decision-log.md) and [D-006](../90-decision-log.md),
content leaves `legacy/` only through an explicit *take / rework / drop* per row, decided by the
owner. Rows in **B** additionally need a decision *before* the related part of the concept can be
locked.
