# Documentation

Living project documentation for **WP Taxonomy Tree**.

**Current mode: scaffolding** — early runnable admin tree ≈ **`0.0.414`** on **`wtt_fs` (Fallstudie) only** (`wtt_tree` retired). Plan **0.7.97** (Q123 Attribute/Relation model locked — not Phase-1 sign-off).

## Start here

1. [`plans/project-plan.md`](plans/project-plan.md) — plan and decisions (source of truth)
2. [`DEVELOPER-ATTRIBUTE-MODEL.md`](DEVELOPER-ATTRIBUTE-MODEL.md) — **Q123** Attribute = Relation; Settings.data/view; diagrams
3. [`plans/relation-vs-object-concept.md`](plans/relation-vs-object-concept.md) — agreed whiteboard summary
4. [`plans/planning-phase.md`](plans/planning-phase.md) — active planning checklist
5. [`plans/mvp-requirements.md`](plans/mvp-requirements.md) — MVP requirements
6. [`plans/data-structure.md`](plans/data-structure.md) — Project + Node (tree = root; Eigenschaften = typed children)
7. [`plans/use-cases.md`](plans/use-cases.md) — use-case cards (who / goal / flow)
8. [`plans/example-projects.md`](plans/example-projects.md) — concrete host examples (BOM, …)
9. [`plans/part-identity-layers.md`](plans/part-identity-layers.md) — kind / package / catalog part / BOM usage
10. [`plans/case-study.md`](plans/case-study.md) — gold Fallstudie scaffold (`wtt_fs`)
11. [`MODEL-CATALOG.md`](MODEL-CATALOG.md) — Model hosts / attributes snapshot (**update only on explicit ask**)
12. [`OPEN-QUESTIONS.md`](OPEN-QUESTIONS.md) — questions to resolve before coding
13. [`PRODUCT.md`](PRODUCT.md) — what the product is
14. [`ARCHITECTURE.md`](ARCHITECTURE.md) — how it should be built
15. [`ROADMAP.md`](ROADMAP.md) — what ships in which phase

## Keeping docs current

Any change to files under `plans/` must update the living docs in the same change. See `.cursor/rules/plan-docs-sync.mdc` and `.cursor/rules/planning-only.mdc`.

**Exception:** [`MODEL-CATALOG.md`](MODEL-CATALOG.md) is refreshed only when you ask (see `.cursor/rules/model-catalog.mdc`).
