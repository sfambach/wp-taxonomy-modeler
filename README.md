# wp-taxonomy-tree

WordPress plugin that provides a reusable **taxonomy tree environment** for hierarchical taxonomies: admin tree UI, secure APIs, and extension points for host plugins.

> **Scaffolding ≈ `0.0.296`** — runnable admin tree on **`wtt_fs` (Fallstudie)** only (`wtt_tree` / BOM **retired**). Taxonomy = **structures**; **Fill Model Data** = instance store. Attribute-host Preview: Form(1)+Table(n) via `WTTObjectRender`. Samples: name→then type map (`Sample_Data`). Catalog chooser: **`chooser_root` + `chooser_focus`**. Gutenberg **`taxo/object-view`** (current); **`taxo/collection-table`** = **Q90 legacy**.  
> Full Project / Node domain still planning. **Fallstudie (`wtt_fs`) = gold scaffold** — not Phase-1 model sign-off. **Q83–Q92** as in living docs; **CatalogChoice** under Q90 (plan **0.7.32**). Status stays **scaffolding**.  
> Major version digit changes only for official releases (first release → `1.0.0`).

## Try the scaffold (local)

1. Activate **WP Taxonomy Tree** under Plugins.
2. Open **Taxonomy Tree** in the wp-admin menu (seed/reset demo via settings / `scripts/windows/seed-test-tree.ps1` if needed).
3. Expand/collapse, create/copy/move/delete; assign types; explore Basiseinheit units and preview panels.
4. Optional: `npm install && npm run build` then insert blocks **Taxo Collection table** or **Taxo Object view** (search `taxo`) on a page.

Plugin entry: [`wp-taxonomy-tree.php`](wp-taxonomy-tree.php)  
Plan: [`docs/plans/project-plan.md`](docs/plans/project-plan.md) · Roadmap: [`docs/ROADMAP.md`](docs/ROADMAP.md)

## Documentation

| Document | Purpose |
|----------|---------|
| [`docs/plans/project-plan.md`](docs/plans/project-plan.md) | Project plan (source of truth for intent) |
| [`docs/plans/planning-phase.md`](docs/plans/planning-phase.md) | Active planning checklist |
| [`docs/plans/mvp-requirements.md`](docs/plans/mvp-requirements.md) | MVP requirements & acceptance criteria |
| [`docs/plans/data-structure.md`](docs/plans/data-structure.md) | Data structure + class diagram (Eigenschaften = typed children; Q66) |
| [`docs/plans/use-cases.md`](docs/plans/use-cases.md) | Use-case cards (actor / goal / flow) |
| [`docs/plans/example-projects.md`](docs/plans/example-projects.md) | Example host projects (BOM, …) — model fit checks |
| [`docs/plans/part-identity-layers.md`](docs/plans/part-identity-layers.md) | Part identity layers (resistor/cap/diode/IC) |
| [`docs/plans/case-study.md`](docs/plans/case-study.md) | Gold Fallstudie scaffold (`wtt_fs`) — Definition / Implementation tree |
| [`prototypes/tree-split/`](prototypes/tree-split/) | Static tree UI prototype (not the WP plugin) |
| [`docs/OPEN-QUESTIONS.md`](docs/OPEN-QUESTIONS.md) | Decisions still to make |
| [`docs/PRODUCT.md`](docs/PRODUCT.md) | Product overview and scope |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Target architecture |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | Phased delivery roadmap |

When the plan changes, the living docs above must be updated in the same change.

## Local Windows layout

| Role | Path |
|------|------|
| WordPress (docroot) | `C:\devel\wordpress` |
| GitHub sources | `C:\devel\wordpress\source` |
| This repo | `C:\devel\wordpress\source\wp-taxonomy-tree` |
| Web server (Laragon, …) | e.g. `C:\laragon` |

```powershell
mkdir C:\devel\wordpress\source -Force
cd C:\devel\wordpress\source
git clone https://github.com/sfambach/wp-taxonomy-tree.git
cd wp-taxonomy-tree
git checkout cursor/bom-konzept-konfiguration-de76
```

Open `C:\devel\wordpress\source\wp-taxonomy-tree` in Cursor Desktop.

## Relationship to other projects

Domain catalogs such as [`wp-electronic-parts`](https://github.com/sfambach/wp-electronic-parts) may consume this environment later. Part-specific properties stay in those host plugins.

## Local development (Windows + Laragon)

Source checkout: `C:\devel\wordpress\source\wp-taxonomy-tree`  
WordPress docroot: `C:\devel\wordpress` → served as `http://devel.test` via Laragon.

After Laragon is installed, run once (also safe before a reboot):

**Doppelklick auf `setup-dev.bat`** (nicht auf `.ps1` — Windows öffnet die sonst im Editor).

Pfad: `C:\devel\wordpress\source\wp-taxonomy-tree\scripts\windows\setup-dev.bat`

Erstes Mal (Repo noch nicht da) in **cmd** oder PowerShell:

```powershell
mkdir C:\devel\wordpress\source -Force
git clone -b cursor/laragon-setup-f17e https://github.com/sfambach/wp-taxonomy-tree.git C:\devel\wordpress\source\wp-taxonomy-tree
cd C:\devel\wordpress\source\wp-taxonomy-tree\scripts\windows
.\setup-dev.bat
```

**Nur WordPress:** `install-wordpress.bat` doppelklicken.

Site: `http://devel.test/wp-admin` — user `admin`, password `admin123`.

Details: [`scripts/windows/README.md`](scripts/windows/README.md)

See [`AGENTS.md`](AGENTS.md) and [`scripts/windows/setup-dev.ps1`](scripts/windows/setup-dev.ps1) for details.

## FAQ (settings)

| Setting | Where | Notes |
|---------|--------|--------|
| **Tree picker** (`popup` / `inline`) | Settings → Taxonomy Tree | Controls both the taxonomy **tree picker** and the **`node_ref` catalog chooser** in Form/Table preview **and** the Collection table block editor. Default **popup**. Reparent stays inline. |
| **`node_ref` create** | Preview / block cell → Choose → Add new… | Needs **Catalog root (`ref_scope`)** on the field. Mini-form = Name + scalar catalog slots (e.g. Lieferanten: Url, Suchstring, Bewertung). |
| **Collection table block** | `npm run build` after JS changes | Editor uses `WTTNodeRender` for `node_ref` columns; frontend shows name chips (not raw ids). |
| **Clear except Datentypen** | WP-CLI `eval-file scripts/clear-except-datatypes.php` (+ optional `reset` / `fs`) | Keeps Datentypen (+ ancestors) and Relationstypen on **`wtt_fs`**. Legacy `tree`/`reset` args still target retired `wtt_tree` / `Demo_Data` helpers. Prefer **Reset case tree** in admin for Fallstudie. |
| **Attributes (Q87 trial)** | Node detail → Attributes | Name + Type + Mult. → stored as `besteht_aus` member. Default Mult. `1`. |
| **Fill Model Data** | Taxonomy Tree → Fill Model Data | No extra settings. Pick a structure host with attributes, then add/edit instance rows (option `wtt_model_instances`). Taxonomy = structures; this page = filled data. |

## License

GPLv2 or later (intended; finalize with the first plugin bootstrap commit after planning).
