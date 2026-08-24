# Taxonomy Modeller

A WordPress plugin for building **data models** — modelled the way the world is shaped, as
objects and their relationships, not as relational tables. The user builds a model by building
a **tree**.

---

> ## ⚠️ Concept phase — nothing here runs
>
> The project **restarted its planning** on 2026-08-22. The old plugin was mothballed on
> 2026-08-23 into [`legacy-code/`](legacy-code/README.md) and no longer loads; there is no build
> and no entry file at the root.
>
> **No production code until [`10-domain-core.md`](docs/NewConcept/10-domain-core.md) is
> `locked`** — rule `PR-2` in [`CLAUDE.md`](CLAUDE.md).
>
> Where it stands: **337 decisions** recorded, **81 open questions** — all answered —
> and **0 lines** of new code.

---

## Start here

Three doors, depending on why you came:

| If you want to … | Read |
|---|---|
| **understand the model in ten minutes** | [The core, on one page](docs/NewConcept/10-domain-core.md#the-core-on-one-page) — fourteen sentences everything else follows from |
| **work in this repository** | [`CLAUDE.md`](CLAUDE.md) — the rules that bind every agent and human here |
| **know why something is the way it is** | [The decision log](docs/NewConcept/90-decision-log.md) — every `D-<nnn>`, with the reasoning |

⚠️ **The source of truth is [`docs/NewConcept/`](docs/NewConcept/README.md) and nothing else.**
[`docs/legacy/`](docs/legacy/README.md) is frozen: quote it, never inherit from it.

## The concept

| Document | What is in it | Status |
|---|---|---|
| [00 Vision and scope](docs/NewConcept/00-vision-and-scope.md) | What the product is, what it is not | `agreed` |
| [01 Glossary](docs/NewConcept/01-glossary.md) | The words, and what each one means here | `draft` |
| [10 Domain core](docs/NewConcept/10-domain-core.md) | The model: nodes, relations, branches, settings, storage | `draft` |
| [20 Interaction](docs/NewConcept/20-interaction.md) | What a person may do — tree, detail view, blocks | `open` |
| [30 Renderer](docs/NewConcept/30-renderer.md) | How anything becomes visible | `draft` |
| [40 I18n](docs/NewConcept/40-i18n.md) | Labels, roles, locales — and why they are not the text domain | `draft` |
| [50 WordPress persistence](docs/NewConcept/50-wordpress-persistence.md) | The seven tables, and the boundary around them | `draft` |
| [60 Calculation](docs/NewConcept/60-calculation.md) | Computed attributes | `draft` |
| [70 Migration](docs/NewConcept/70-migration.md) | Getting existing content in | `draft` |
| [90 Decision log](docs/NewConcept/90-decision-log.md) | **Nothing is decided until it is here** | running |
| [91 Open questions](docs/NewConcept/91-open-questions.md) | Anything undecided, never invented away | running |
| [95 Roadmap](docs/NewConcept/95-roadmap.md) | What ships in which order, plus the parking lot | `draft` |
| [96 Scenario check](docs/NewConcept/96-scenario-check.md) | Six worlds modelled against the concept before locking | `draft` |
| [97 Implementation plan](docs/NewConcept/97-implementation-plan.md) | Packages, not sprints | `draft` |
| [98 Documentation style](docs/NewConcept/98-documentation-style.md) | How these documents are written | `agreed` |

The [`_harvest/`](docs/NewConcept/_harvest/README.md) folder holds the sweeps of the old project
— documentation and code — each finding marked *covered*, *contradicts*, *missing*, *workaround*
or *deliberately dropped*. **The old material is evidence, not a source.**

## Names

One name for people, one for machines:

| | |
|---|---|
| Product name | **Taxonomy Modeller** |
| Repository, plugin folder | `wp-taxonomy-modeler` |
| PHP namespace | `Taxmod\Core\…` · `Taxmod\WordPress\…` |
| Database tables | `{$wpdb->prefix}taxmod_…` |
| Gutenberg blocks | `taxmod/<slug>` |
| Text domain | `taxmod` |
| Version | starts at `0.0.1` |

## Local layout (Windows + Laragon)

| Role | Path |
|------|------|
| WordPress docroot | `C:\devel\wordpress` — served as `http://devel.test` |
| Source checkouts | `C:\devel\wordpress\source` |
| This repository | `C:\devel\wordpress\source\wp-taxonomy-modeler` |

```bash
git clone https://github.com/sfambach/wp-taxonomy-modeler.git
```

Details, including the cloud VM with SQLite: [`AGENTS.md`](AGENTS.md).

## License

GPLv2 or later — to be finalised with the first plugin bootstrap commit.
