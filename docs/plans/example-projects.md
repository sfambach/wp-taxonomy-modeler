---
name: Example projects
overview: Concrete host projects used to validate that the WP Taxonomy Tree domain model still fits. Open questions stay open unless an example forces a decision.
status: draft
version: "0.1.0-plan"
last_updated: "2026-07-23"
related_plans:
  - docs/plans/project-plan.md
  - docs/plans/use-cases.md
  - docs/plans/data-structure.md
  - docs/plans/mvp-requirements.md
todos:
  - id: example-bom
    content: "Document BOM example project and fit/gap vs taxonomy-tree model"
    status: completed
  - id: example-second
    content: "Add second example project from user and re-check model fit"
    status: pending
---

# Example projects (planning)

> Walk concrete host projects along the model.  
> **WP Taxonomy Tree** = tree environment + definitions (types, relations, …).  
> **Host plugin** (e.g. future BOM / `wp-electronic-parts`) = lists, prices, stock, exports, filled part instances.

Open questions stay open — examples only show **fit**, **host territory**, or **gaps**.

---

## Example A — BOM (Bill of Materials)

### Story (user wording, condensed)

Website with projects. A user wants to build **BOM lists** of electronic parts.

| Aspect | Detail |
|--------|--------|
| BOM line | 1…N **references** (designators on a board) + a chosen **part** (Widerstand, Kondensator, IC, Buchse, …) + **Bauform** (SMD, TH, …) + **quantity** (from reference count) + **description** + **price** + **in stock?** |
| Part pick | From a **category tree** of possible parts |
| Part data | Parts already carry properties: Größe, Wert, Datenblatt-Link, Leistung, … |
| BOM footer | Part count + **price sum** |
| Compare | Find/compare lists that share the same parts |
| Export | Request sheets / CSV for Digikey, Conrad, AliExpress, … |

### Fit vs current model

| Need | Fits in **WP Taxonomy Tree**? | Where it lives |
|------|-------------------------------|----------------|
| Category / part **tree** to browse and select | **Yes** | `Project` + Nodes (Bauteile tree / Definitionsbaum branches) |
| Part **properties** (Wert, Maße, Leistung, URL Datenblatt, Bauform enum, …) | **Mostly yes (definitions)** | Type catalog (`measure`, `url`, `enum`, …); Relations `consists_of` / attributes; filled values still Q16 |
| Selecting a part from the tree into a BOM line | **Yes (extension)** | Tree selection + host listens (UC-07 style side panel / action) |
| BOM **list** entity (one list per project/board) | **No — host** | Host CPT/table (out of scope for early taxonomy-tree versions) |
| BOM **line**: references, qty, price, stock flag, description | **No — host** | Host line model; qty derived from reference count |
| Price sum / line totals | **No — host** | Host calculation |
| Compare lists by shared parts | **No — host** | Host search/query |
| Supplier CSV (Digikey, Conrad, …) | **No — host** | Host exporters / adapters |
| Definitionsbaum Type / Präfix / Basiseinheit for measures | **Yes** | Required Project anchors |

### Boundary sketch

```text
┌─────────────────────────────────────────────┐
│ WP Taxonomy Tree (this plugin)              │
│  Project                                    │
│  └─ Definitionsbaum / Bauteile tree (Nodes) │
│  └─ Types, Präfix, Basiseinheit            │
│  └─ Part nodes + consists_of attributes     │
│  └─ Tree UI: browse / select / manage       │
│  └─ Hooks: “node selected” → host           │
└──────────────────┬──────────────────────────┘
                   │ selection / part id
                   ▼
┌─────────────────────────────────────────────┐
│ Host: BOM / electronic parts                │
│  BOM list(s) per website project            │
│  BOM lines: refs[], part→Node/part,         │
│             bauform, qty, desc, price, stock│
│  Totals, compare lists, supplier CSV        │
└─────────────────────────────────────────────┘
```

### What this example stresses (still open — do not decide here)

| Topic | Why BOM cares | Open ref |
|-------|---------------|----------|
| Part pick from tree | Core UX of the environment | UC-01, UC-04, UC-07 |
| Measure + URL + enum on parts | Need Type catalog | Q36–Q39 |
| Attributes on part nodes | consists_of vs Parameter-as-Node | Q33–Q35, Q42 |
| Filled values on the part | BOM shows Wert/Maße already on the part | Q16 |
| Project vs WP taxonomy | One website “project” vs our Project | Q18–Q19 |
| Host extension contract | Add-to-BOM on select | Q8–Q9 |

### Verdict for Example A

**Still fits** the intended split: taxonomy-tree owns the **part tree + property definitions (+ selection UX)**; BOM lists, money, stock, compare, and supplier CSV stay in the **host**.  
No model break yet — confirms the plugin should stay a **tree environment**, not a BOM app.

Related use-case cards: UC-20… in [`use-cases.md`](use-cases.md).

---

## Example B — (pending)

User will provide a second simple example. Re-check the same fit/gap table afterward.
