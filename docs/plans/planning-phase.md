---
name: Planning phase
overview: Expand and freeze product/technical planning before any plugin implementation. No code until this phase is accepted.
status: active
version: "0.1.0-plan"
last_updated: "2026-07-23"
related_plans:
  - docs/plans/project-plan.md
  - docs/plans/mvp-requirements.md
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
    content: "Agree Node fields, relations, and storage mapping in data-structure.md"
    status: completed
  - id: define-parameter-model
    content: "Parameter object + node can have several parameters; single-owner (?) deferred via Q14"
    status: in_progress
  - id: resolve-open-questions
    content: "Resolve or explicitly defer every item in docs/OPEN-QUESTIONS.md"
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
  - id: planning-signoff
    content: "Mark project plan ready-to-implement only after user sign-off"
    status: pending
---

# Planning phase (no implementation)

> Active now. Produce clear requirements and decisions only. Do **not** write plugin code in this phase.

## Purpose

Turn the high-level project idea into an agreed MVP plan that another engineer (or agent) can implement later without re-litigating basics.

## Planning outputs

| Output | File | Done when |
|--------|------|-----------|
| Master plan | `docs/plans/project-plan.md` | Goals, phases, and decisions are current |
| Planning checklist | `docs/plans/planning-phase.md` (this file) | Todos resolved or deferred |
| MVP requirements | `docs/plans/mvp-requirements.md` | Acceptance criteria are testable |
| Data structure (Nodes + Parameters) | `docs/plans/data-structure.md` | Node rules agreed; node→several parameters agreed; parameter→one node marked ? (Q14) |
| Open questions | `docs/OPEN-QUESTIONS.md` | Each question answered or deferred with owner |
| Product / architecture / roadmap | living docs | Match the plan |

## Explicitly out of this phase

- Plugin PHP/JS/CSS implementation
- Package scaffolding for runtime code
- Database migrations or custom tables
- Integration coding in `wp-electronic-parts`

## Exit criteria for planning

Planning ends only when:

1. MVP requirements are accepted.
2. Open questions are answered or deferred with a reason.
3. Transport and JS-stack choices for MVP are recorded in the decision log.
4. The user asks to leave planning mode / start implementation.
5. Project plan status changes away from `planning`.
