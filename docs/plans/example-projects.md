---
name: Example projects
overview: Concrete host projects used to validate that the WP Taxonomy Tree domain model still fits. Open questions stay open unless an example forces a decision.
status: draft
version: "0.1.1-plan"
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
    content: "Add second example project (Hardware / benchmarks) and re-check model fit"
    status: completed
  - id: example-cross-check
    content: "Summarize shared fit across Example A + B"
    status: completed
---

# Example projects (planning)

> Walk concrete host projects along the model.  
> **WP Taxonomy Tree** = tree environment + definitions (types, relations, …).  
> **Host plugin** = domain apps (BOM lists, hardware benchmarks, builds, stats, …).

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

## Example B — Hardware catalog, compare, tests, builds

### Story (user wording, condensed)

Hardware such as **graphics cards, sound cards, motherboards**, … Each family has **different properties**.

| Aspect | Detail |
|--------|--------|
| Properties | Per hardware type (GPU ≠ sound card ≠ motherboard) |
| Lists | Show hardware and its properties in lists |
| Compare | Compare two items of the same kind (e.g. sound card A vs B) |
| Component tests | Run/show tests (e.g. how fast a card is) with results |
| Build / combine | Combine hardware into a **computer** |
| System tests | Tests on the combined computer → results that can be compared |
| Stats | Aggregated / summary statistics from tests |

### Fit vs current model

| Need | Fits in **WP Taxonomy Tree**? | Where it lives |
|------|-------------------------------|----------------|
| Category tree (Grafikkarten, Soundkarten, Mainboards, …) | **Yes** | `Project` + Nodes |
| Different property sets per category | **Yes (definitions)** | `is_a` / category nodes + `consists_of` attribute sets (inherit Q43) |
| Property types (measure, string, enum, url, …) | **Yes** | Type catalog + Präfix/Basiseinheit |
| List UI of hardware + properties | **Partial** | Tree/list of nodes = environment; rich list columns = **host** or later UI |
| Compare two sound cards | **Partial** | Shared attribute definitions from tree; **compare UI + result layout = host** |
| Benchmark / speed tests on one device | **No — host** | Test runs, scores, charts |
| Computer as combination of parts | **Maybe relations** | Logical `uses` / `consists_of` between Computer↔parts could be Relations; **build entity + UX = host** |
| Tests on a computer + compare systems | **No — host** | System under test, result sets |
| Summary statistics | **No — host** | Analytics / rollups |

### Boundary sketch

```text
┌──────────────────────────────────────────────┐
│ WP Taxonomy Tree                             │
│  Hardware category tree (Nodes)              │
│  Per-type attribute defs (consists_of / …)   │
│  Types: measure, enum, url, …                │
│  Optional: Relation uses/consists_of for     │
│            “PC uses GPU / Mainboard / …”     │
│  Tree UI + selection hooks                   │
└──────────────────┬───────────────────────────┘
                   │ node ids + attribute defs/values
                   ▼
┌──────────────────────────────────────────────┐
│ Host: hardware review / lab                  │
│  Lists & compare views                       │
│  Component test runs + results               │
│  Computer builds (selected part nodes)       │
│  System tests + compare + statistics          │
└──────────────────────────────────────────────┘
```

### What this example stresses (still open)

| Topic | Why Hardware cares | Open ref |
|-------|--------------------|----------|
| Different attrs per branch | GPU vs Soundkarte property sets | Q42, Q43, `is_a` inherit |
| Compare needs same schema | Two sound cards share consists_of set | UC-04, UC-06 |
| Test results are not Nodes | Time-series / scores ≠ taxonomy | host |
| Computer composition | Relation `uses` vs host-only build table | Q35, Q41–Q44 |
| Stats | Aggregate over host result store | host |

### Verdict for Example B

**Still fits.** Same split as BOM: taxonomy-tree = **catalog tree + typed properties (+ optional composition relations)**; host = **lists/compare UX, benchmarks, builds, system tests, statistics**.  
Stronger pressure than BOM on **per-type attribute sets** and optional **`uses`/`consists_of` for builds** — not a break, but Relations become more useful.

Related use-case cards: UC-30… in [`use-cases.md`](use-cases.md).

---

## Cross-check: Example A + B

| Concern | BOM (A) | Hardware (B) | Model still OK? |
|---------|---------|--------------|-----------------|
| Browse/select from tree | parts | hardware items | **Yes** |
| Typed properties on items | Wert, Maße, … | GPU clocks, I/O, … | **Yes** |
| Domain lists | BOM lines | hardware lists | **Host** |
| Compare | BOM lists by parts | devices / systems | **Host** (reads tree attrs) |
| Money / stock / CSV | yes | no | **Host** |
| Tests / benchmarks / stats | no | yes | **Host** |
| Composition of items | BOM refs on a board | PC from parts | **Host** (+ optional Relations) |

**Overall verdict:** Both examples support keeping WP Taxonomy Tree as a **reusable tree + definition environment**. Neither requires the core plugin to own BOM math, vendor CSV, benchmarks, or statistics.

---

## Example C — (optional later)

Add further examples only if they threaten the boundary (e.g. if something truly needs to live inside the tree plugin).
