---
name: Planning phase
overview: Expand and freeze product/technical planning. Early scaffold preview is allowed when the user asks; full domain implementation still waits for sign-off. Fallstudie learnings absorbed into docs (plan 0.7.17) without exiting scaffolding.
status: active
version: "0.1.2-plan"
last_updated: "2026-08-05"
related_plans:
  - docs/plans/project-plan.md
  - docs/plans/mvp-requirements.md
  - docs/plans/case-study.md
todos:
  - id: lock-planning-mode
    content: "Declare planning-only mode in plan, roadmap, and Cursor rule (no implementation yet)"
    status: completed
  - id: clarify-personas
    content: "Confirm primary personas and jobs-to-be-done for admin vs host-plugin developers"
    status: pending
  - id: freeze-mvp-scope
    content: "Agree MVP in/out list and acceptance criteria in mvp-requirements.md"
    status: pending
  - id: define-node-model
    content: "Agree Node fields; tree is derived from root node (not a separate object)"
    status: completed
  - id: define-parameter-model
    content: "SUPERSEDED: Parameter class dropped — Eigenschaften = typed children; Q66 inherit; Q34/Q49 still open"
    status: cancelled
  - id: define-project-model
    content: "Project has name, description, root_nodes (list of root Node)"
    status: completed
  - id: define-changelog-model
    content: "Shared Changelog/Change (timestamp, changer, change) on Project and Node"
    status: completed
  - id: absorb-fallstudie
    content: "Absorb Fallstudie (wtt_fs) into living docs — plan 0.7.17; status stays scaffolding"
    status: completed
  - id: resolve-open-questions
    content: "Resolve or defer OPEN-QUESTIONS — Q54/Q64–Q80 decided or superseded; remaining: Q53, Q81, Q82 lean, transport/storage Qs"
    status: pending
  - id: choose-transport-js
    content: "Decide REST vs Admin-AJAX and vanilla JS vs @wordpress/scripts for MVP"
    status: pending
  - id: define-extension-contract
    content: "Draft host-plugin extension contract (registration, panels, capabilities) as planned API names"
    status: pending
  - id: define-delete-ux
    content: "Specify delete flows (promote vs cascade), confirmations, and edge cases"
    status: pending
  - id: draft-use-cases
    content: "Use-case cards synced to Q64 superseded / typed children; expand further as needed"
    status: in_progress
  - id: planning-signoff
    content: "Mark project plan ready-to-implement only after user sign-off (Fallstudie alone is not enough)"
    status: pending
---

# Planning phase (docs + early scaffold exception)

> Active now for requirements and decisions. **Early scaffold** (admin tree over terms) is allowed when the user asks — see project plan status `scaffolding` and `.cursor/rules/planning-only.mdc`. Full domain coding beyond that scope still waits for sign-off.

## Purpose

Turn the high-level project idea into an agreed MVP plan that another engineer (or agent) can implement later without re-litigating basics. The runnable scaffold and Fallstudie are **previews / evidence**, not sign-off.

## Planning outputs

| Output | File | Done when |
|--------|------|-----------|
| Master plan | `docs/plans/project-plan.md` | Goals, phases, and decisions are current |
| Planning checklist | `docs/plans/planning-phase.md` (this file) | Todos resolved or deferred |
| MVP requirements | `docs/plans/mvp-requirements.md` | Acceptance criteria are testable |
| Data structure (Project + Node) | [`docs/plans/data-structure.md`](data-structure.md) | Tree = root node; Eigenschaften = typed children (Q64 superseded); inherit defs Q66; bands/bindings Q70/Q80 |
| Use cases | [`docs/plans/use-cases.md`](use-cases.md) | Actor/goal/flow cards agreed; MVP mapping started |
| Case study | [`docs/plans/case-study.md`](case-study.md) | Exploratory evidence absorbed into main plan (not sign-off) |
| Open questions | [`docs/OPEN-QUESTIONS.md`](../OPEN-QUESTIONS.md) | Each question answered or deferred with owner |
| Product / architecture / roadmap | living docs | Match the plan |

## Explicitly out of this phase (unless user asks for a scaffold slice)

- Full Composition instance services / custom Relation edge table
- Database migrations or custom tables beyond interim term meta
- Treating scaffold or Fallstudie UX as frozen product decisions
- Integration coding in `wp-electronic-parts`
- Returning to “main” BOM domain implementation without an explicit user ask

## Exit criteria for planning

1. MVP requirements accepted (or explicitly deferred with reasons).
2. Open questions decided or deferred (incl. transport/JS, Q53, Q82).
3. Living docs match the plan (no Parameter / dual-`parent_id` leftovers as current truth).
4. **User sign-off** — Fallstudie completion alone does **not** exit planning / scaffolding.
