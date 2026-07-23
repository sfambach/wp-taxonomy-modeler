# wp-taxonomy-tree

WordPress plugin that will provide a reusable **taxonomy tree environment** for hierarchical taxonomies: admin tree UI, secure APIs, and extension points for host plugins.

> **Planning only — no implementation yet.**  
> Versioning when coding starts: begin at `0.0.1`; change the first digit only for official releases (for example `1.0.0`).

## Documentation

| Document | Purpose |
|----------|---------|
| [`docs/plans/project-plan.md`](docs/plans/project-plan.md) | Project plan (source of truth for intent) |
| [`docs/plans/planning-phase.md`](docs/plans/planning-phase.md) | Active planning checklist |
| [`docs/plans/mvp-requirements.md`](docs/plans/mvp-requirements.md) | MVP requirements & acceptance criteria |
| [`docs/plans/data-structure.md`](docs/plans/data-structure.md) | Node data structure |
| [`docs/OPEN-QUESTIONS.md`](docs/OPEN-QUESTIONS.md) | Decisions still to make |
| [`docs/PRODUCT.md`](docs/PRODUCT.md) | Product overview and scope |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Target architecture |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | Phased delivery roadmap |

When the plan changes, the living docs above must be updated in the same change.

## Relationship to other projects

Domain catalogs such as [`wp-electronic-parts`](https://github.com/sfambach/wp-electronic-parts) may consume this environment later. Part-specific properties stay in those host plugins.

## License

GPLv2 or later (intended; finalize with the first plugin bootstrap commit after planning).
