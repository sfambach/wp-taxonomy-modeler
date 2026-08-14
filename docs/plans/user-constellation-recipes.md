---
name: User constellation recipes (backlog)
overview: End-user step-by-step recipes for common model constellations in admin. Not written yet — seed list only. Distinct from planning fit examples in example-projects.md.
status: backlog
last_updated: "2026-08-11"
related_docs:
  - docs/DEVELOPER-ATTRIBUTE-MODEL.md
  - docs/OPEN-QUESTIONS.md
  - docs/plans/attribute-choice-inheritance.md
  - docs/plans/example-projects.md
  - docs/plans/use-cases.md
  - docs/plans/project-plan.md
todos:
  - id: recipe-si-prefix
    content: "Recipe — SI unit + Praefix allowlist + Menge rescale (Q109)"
    status: pending
  - id: recipe-calc-default-from
    content: "Recipe — calc Relation op=default_from (e.g. Bauart Position→Bauteilliste)"
    status: pending
  - id: recipe-catalogchoice-heir
    content: "Recipe — CatalogChoice on Unit-type attrs + heir C1 Default/choiceFilter"
    status: pending
  - id: recipe-money-iso
    content: "Recipe — Money profile Menge + Währung + ISO 4217 leaf meta (no SI Präfix)"
    status: pending
  - id: recipe-composition-attr
    content: "Recipe — Own attribute via besteht_aus/aggregation (not child_of)"
    status: pending
---

# User constellation recipes (backlog)

**Status: backlog** — write full recipes **later** (user ask 2026-08-11). Do not treat this file as shipped user docs yet.

## Purpose

Show **how an admin builds** known constellations in the tree UI (clicks, panels, Relations, Settings), not only what the model means.

| Doc | Job |
|-----|-----|
| This file (later) | **User recipes** — step-by-step “how do I set up X?” |
| [`example-projects.md`](example-projects.md) | **Planning fit** — does the domain model cover BOM / hardware / recipes? |
| [`use-cases.md`](use-cases.md) | Who / goal / flow cards |
| [`DEVELOPER-ATTRIBUTE-MODEL.md`](../DEVELOPER-ATTRIBUTE-MODEL.md) | Developer SoT for Q123 |

Language: **English** in this repo (chat may be German). Optional DE locale string packs stay separate until release translation pass.

## Seed list (write when asked)

1. **SI quantity** — Base unit leaf, Praefix factors, allowlist (empty = no Präfix), switch Präfix → Menge rescale; refuse cross–Base-unit.
2. **`calc` / default_from** — Add Relation type Calculation; set name = attribute; `op=default_from`; consumer→provider hosts (BOM Bauart seed as reference).
3. **CatalogChoice + heir** — Unit-type attrs typed to Base unit / Praefix; child_of heir overrides Default + `choiceFilter` via host maps.
4. **Money** — Menge + Währung; ISO 4217 on currency leaves; Cent ≠ SI Präfix; FX parked (Q110).
5. **Own attribute** — `besteht_aus` / `aggregation` with Relation.name; never `child_of` for attributes.

Add further recipes when a constellation ships in scaffold and needs a teachable path (affine temp, m↔in, Stück/pack, Walk-Wizard prefix path, …).

## Recipe template (when filling)

For each recipe:

1. **Goal** (one sentence)
2. **Prerequisites** (seeds / panels / capability)
3. **Steps** (numbered admin actions)
4. **Check** (what Preview / instance should show)
5. **Do not** (common mistakes — e.g. SI via `calc`, attribute as tree child)
6. **Related** — Q-ids + code/docs links
