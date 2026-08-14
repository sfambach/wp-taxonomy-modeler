---
name: Settings UI parity — Knoten-Walk / Attribut-Walk
overview: "Soll vs Ist for Settings surfaces. One Settings walk. ChildList Preferred = Choices UI (no childNodes box) ≈ 0.0.532. presentationContext/compute → walk next. Node ConfigPage design locked in plan."
status: in_progress
version: "0.1.2"
last_updated: "2026-08-14"
related_docs:
  - docs/DEVELOPER-ATTRIBUTE-MODEL.md
  - docs/plans/q123-migrate-handoff.md
  - docs/plans/relation-vs-object-concept.md
  - .cursor/rules/settings-ui-parity.mdc
  - docs/ARCHITECTURE.md
  - docs/ROADMAP.md
todos:
  - id: matrix-lock
    content: "Publish Soll vs Ist matrix; agent rule = model lock ≠ GUI done"
    status: completed
  - id: dedupe-attr-options
    content: "Attribute Options — suppress legacy Choices + depth-0 Relation-overrides chrome when Settings walk covers the type"
    status: completed
  - id: walk-always-surface
    content: "Settings walk is the Settings surface even for single-node (depth 0 only); rename label to Settings"
    status: completed
  - id: nested-walk-table
    content: "Nested depth≥1 walk rows = Attributes-like table (Name/Type/Default/RO/Hide/Render/Conv/Val)"
    status: completed
  - id: deferred-choices
    content: "Choices checkboxes save on leave (not per tick); structure hosts never list heirs as Choices"
    status: completed
  - id: walk-presentation-compute
    content: "Fold presentationContext + compute into the same Settings walk (not side panels)"
    status: pending
  - id: node-attr-same-chrome
    content: "Plan later: how node ConfigPage collects child Settings vs attribute walk (clarify before implement)"
    status: pending
  - id: uat-praefix-wert
    content: "UAT Praefix / Passiv.Wert / flat CatalogChoice — one Choices, no empty Settings"
    status: pending
---

# Settings UI parity (Knoten-Walk / Attribut-Walk)

## Progress (2026-08-14 ≈ 0.0.532)

- **Done:** Attribute Options Walk; nested table; deferred Choices; **ConfigPage: removed `childNodes` box** — Child-list Choices only when Preferred = `ChildListRenderer` (Währung / Praefix / Konstanten same law); default Preferred for hosts with children → ChildList (not Konstanten-only).
- **Locked:** Preferred default on type node; ChildList + `choiceFilter` = one pick mechanism; factors on prefix leaves.
- **Debt / discuss:** `_wtt_allowed_prefix_ids` parallel SoT vs `choiceFilter`; `fixedMode=catalog` auto-detect without Preferred; name heuristics (`waehrung`); `isKonstantenHost`. **Implementation branch retired** from blueprint + one-shot soft-trash (≈ 0.0.533).
- **Next:** presentationContext/compute into walk; finish allowlist→choiceFilter migrate; UAT.
- **Later:** Node ConfigPage vs attribute walk chrome (design in plan).

## Agent rule (process)

Cursor rule (always apply): [`.cursor/rules/settings-ui-parity.mdc`](../../.cursor/rules/settings-ui-parity.mdc).

When asked “do we still have legacy / Altlasten?”:

1. Answer **SoT / concept** and **GUI-Ist** separately.
2. `OPEN-QUESTIONS` / OQ-W **decided** ≠ admin UI finished.
3. Prefer this matrix + [`q123-migrate-handoff.md`](q123-migrate-handoff.md) debt over “model locked → no debt.”

## Locked concept (unchanged)

| Term | Meaning |
|------|---------|
| **Knoten-Walk** | Open node → Settings of node + attribute targets to leaf |
| **Attribut-Walk** | Attribute Options → same walk from **target type** |
| **Settings** | One capsule `Settings.data` / `Settings.view` — type defaults on node, override deltas on Relation |
| **Not Settings** | Node Identity/Presentation; attribute edge fields (name, Mult, Bindung, RO, Hide, Default seed) |

From the type node downward, Knoten- and Attribut-Walk are the **same search**.  
**Same type ⇒ same Options/Settings paint** on every host that uses that type.

## Soll vs Ist

| Surface | Soll | Ist (scaffold ≈ 0.0.531) | Lücke |
|---------|------|---------------------------|-------|
| Attribut Options — Settings | **One** walk UI (depth 0…leaf) | Walk-only when `attributeSettingsWalkCovers`; legacy = fallback only | Mostly closed |
| Nested scalar children | Attributes-like **one row** table | Table chrome depth≥1 | OK |
| Choices | CatalogChoice only; save on leave | Deferred drafts; no heir-as-choice on structure hosts | OK |
| presentationContext / compute | **Inside** same walk | Still side panels in Options | **Next** |
| Preferred default | On **type node** / simple type config | Type Preferred exists; display-name heuristics = debt | Debt |
| Knoten ConfigPage | Clarify then align | Q126 page; walk widget not shared yet | **Plan later** |
| Labels | “Settings” | Walk title **Settings** | OK |

## Implementation order

1. ~~Dedupe Attribute Options~~
2. ~~Always use walk as Settings surface~~
3. ~~Nested table + deferred Choices + structure-host Choices law~~
4. **Walk: presentationContext + compute**
5. **Remove display-name Preferred/preview heuristics** (type node Preferred / simple config only)
6. **Plan** node ConfigPage vs attribute walk (do not implement until clarified)
7. **UAT** — Praefix, Wert/Unit type, flat Währung

## Out of scope

- Changing hybrid storage (OQ-W2/W3)
- New Settings model for attributes
- **Mass-delete** Form/Table/`set`/enum from code now — Form/Table = Preferred **layouts** (keep); catalog `enum`/`list`/`table` already **Q90 parked**; `set` still needed for units until redesign. If a removal slice comes later: archive the concept first, then strip code.
