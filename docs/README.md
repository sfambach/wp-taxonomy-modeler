# Documentation

Living project documentation for **WP Taxonomy Tree**.

**Current mode: scaffolding** — early runnable admin tree ≈ **`0.0.270`** (`wtt_tree` + Fallstudie `wtt_fs`); domain planning continues (Parameter class discarded). Plan **0.7.28** (Fallstudie learnings absorbed — not Phase-1 sign-off).

## Start here

1. [`plans/project-plan.md`](plans/project-plan.md) — plan and decisions (source of truth)
2. [`plans/planning-phase.md`](plans/planning-phase.md) — active planning checklist
3. [`plans/mvp-requirements.md`](plans/mvp-requirements.md) — MVP requirements
4. [`plans/data-structure.md`](plans/data-structure.md) — Project + Node (tree = root; Eigenschaften = typed children)
5. [`plans/use-cases.md`](plans/use-cases.md) — use-case cards (who / goal / flow)
6. [`plans/example-projects.md`](plans/example-projects.md) — concrete host examples (BOM, …)
7. [`plans/part-identity-layers.md`](plans/part-identity-layers.md) — kind / package / catalog part / BOM usage
8. [`plans/case-study.md`](plans/case-study.md) — parallel Fallstudie (`wtt_fs`)
8. [`OPEN-QUESTIONS.md`](OPEN-QUESTIONS.md) — questions to resolve before coding
9. [`PRODUCT.md`](PRODUCT.md) — what the product is
10. [`ARCHITECTURE.md`](ARCHITECTURE.md) — how it should be built
11. [`ROADMAP.md`](ROADMAP.md) — what ships in which phase

## Keeping docs current

Any change to files under `plans/` must update the living docs in the same change. See `.cursor/rules/plan-docs-sync.mdc` and `.cursor/rules/planning-only.mdc`.
