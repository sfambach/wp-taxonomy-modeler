---
name: Blocks lane (Gutenberg)
overview: Implementation slice for taxo/* Gutenberg blocks. Product architecture for presentation surfaces / parity lives in the primary plan (0.7.49); this file is the blocks-zone backlog only.
status: active
version: "0.1.2-plan"
last_updated: "2026-08-08"
related_plans:
  - docs/plans/project-plan.md
  - docs/plans/mvp-requirements.md
related_docs:
  - docs/ARCHITECTURE.md
  - docs/OPEN-QUESTIONS.md
  - .cursor/rules/block-naming.mdc
  - .cursor/rules/agent-lanes.mdc
  - .cursor/rules/reuse-renderers.mdc
  - .cursor/rules/choosers.mdc
  - .cursor/rules/parked-complex-types.mdc
todos:
  - id: object-view-parity
    content: "Same renderer everywhere: admin object view works ⇒ block editor + frontend (WTTObjectRender / Object_Render; Form/Table/Compact, media, multi-value)"
    status: in_progress
  - id: shared-chooser-contract
    content: "Keep ModelTreeChooser / ModelInstancePicker on Q92 rootId+focusId contract; converge with admin TreeChooser later"
    status: pending
  - id: collection-table-legacy
    content: "taxo/collection-table = Q90 legacy Taxo Table view — maintain only; do not extend as product Collection table"
    status: pending
  - id: blocks-design-sync
    content: "When block UX needs a model/product decision, append to project-plan + OPEN-QUESTIONS; do not silently invent domain rules"
    status: in_progress
---

> ## ⚠️ FROZEN — LEGACY DOCUMENT
>
> This file belongs to the **pre-2026-08-22 planning round** and is **no longer maintained**.
>
> - Do **not** edit it. Do **not** treat it as source of truth. Do **not** implement from it.
> - It is kept as a **quarry**: content reaches the new concept only through a reviewed
>   harvest sheet (see [`../../NewConcept/README.md`](../../NewConcept/README.md)).
> - Version numbers, `Q<n>` question ids, status flags and decision-log entries in here
>   describe the **old** model. They carry no authority over the new one.


# Blocks lane

## Role in the architecture

This is **not** a parallel product. It is the **Gutenberg implementation slice** of the architecture already absorbed in the primary plan:

- **[Presentation surfaces](project-plan.md#presentation-surfaces-architecture)** (plan **0.7.49**) — definition tree vs instances vs presentation; parity across admin / block / frontend
- **Q63 / Q91 / Q85 / Q62** — tree = definition; Registry + many renderers; blocks = views; Object View vs Taxo Table view
- **Preferred render / converter / validators** (absorb **0.7.48**) — per-node meta; do not invent a block-only paint stack
- **Q90** — do not grow parked Collection kinds via blocks

Agent process (own zones, append decisions): [`.cursor/rules/agent-lanes.mdc`](../../.cursor/rules/agent-lanes.mdc).

## Parity (one renderer)

**If it paints correctly in admin object/preview chrome, it must paint the same in the block editor and on the frontend** — same shared renderer (`WTTObjectRender` / `Object_Render` / Registry), not a block-only fork. Gaps (raw JSON, missing media controls, wrong Form vs Table) are parity bugs, not “block features.”

## In scope

| Block | Role |
|-------|------|
| **`taxo/object-view`** | Primary — bind structure (+ optional Model_Data instance); paint via shared object renderers |
| **`taxo/collection-table`** | **Taxo Table view** — all instances for a bound host; **Q90 legacy** path for catalog `table`; do not grow Collection semantics |
| **`src/blocks/shared/`** | TreeChooser / instance picker chrome shared by blocks |

PHP: [`includes/class-blocks.php`](../../includes/class-blocks.php) (register, localize, REST used by blocks).  
Build: `npm run build` → `build/blocks/…` (required for registration).

## Out of scope (unless blocked)

- Tree admin UX, Case_Data seeds, Settings screens, attribute panel CRUD
- Reviving parked `enum` / `list` / `table` catalog kinds (Q90)
- Large refactors of `tree-admin.js` / `class-node-type.php` for convenience
- Rewriting presentation-meta / Q95 / Q96 decisions (owned by plan absorb **0.7.48**)

## Design changes

1. Prefer fixing **presentation** in shared renderers so admin preview and blocks stay one chrome.
2. If a **product** rule must change (Q62/Q63/Q85/Q93, bindings, instance SoT) → update [`project-plan.md`](project-plan.md) + living docs in the **same** change (append decision-log).
3. Do not overwrite another agent’s plan rows or owned files wholesale.

## Current product anchors

- **Q62 / Q63:** Tree = definition; page/block = instance values; Object View vs Taxo Table view.
- **Q85:** Composition-first — blocks are **views**, not the domain SoT.
- **Q90:** Do not extend Complex `table`/`list`/`enum` as product types.
- **Q91 / reuse-renderers:** Blocks use `WTTObjectRender` / Registry — no hand-built attribute cells.
- **Q92 / choosers:** `rootId` + caller `focusId` (`model` for Object View / table bind).

## Working notes

- Namespace **`taxo/`**, titles start with **Taxo** ([`block-naming.mdc`](../../.cursor/rules/block-naming.mdc)).
- Prefer additive attrs / REST fields; avoid breaking saved block markup without a migrate note in this file.

## Smoke (2026-08-08) — parity check

| Check | Result |
|-------|--------|
| Platine DTO | 9 attrs; Gerberdatei has `mediaConfig` |
| SSR + sample Platine instance | Values paint; no raw media JSON; `wtt-media` present |
| Bauteilliste | Has Model_Data instances; Mult `0..*` Position → many-table in SSR |
| Kontakt / Platine instances | Often none until Fill Model Data / picker |
| Editor | `edit.js` mounts `WTTObjectRender.mount` (edit only with instance) |
| `build/blocks/object-view` | Present |

**Deferred / notes:** `referenceMode=embed` still stub; Bauteilliste odd **Name (copy)** attr (model lane); media `allowedKinds` empty may limit edit kinds; nested recursive paint beyond depth-1 related tables still shallow (OQ-R8 depth).

**BOM related lines (Q97 ≈ `0.0.372`):** Object View SSR + `mount` paint Mult many Position from `relatedInstances` (collection Table). Block editor edit: **Add line** → REST `POST /wtt/v1/model-data/{parentStructureId}/create-linked`; cell save → `POST /wtt/v1/model-data/{childStructureId}` with child `instanceId` + values. No host-attr JSON blobs.
