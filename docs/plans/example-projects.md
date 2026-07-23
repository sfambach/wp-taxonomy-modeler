---
name: Example projects
overview: Concrete host projects used to validate that the WP Taxonomy Tree domain model still fits. Open questions stay open unless an example forces a decision.
status: draft
version: "0.1.2-plan"
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
  - id: example-recipes
    content: "Add third example project (Rezepte) and re-check model fit"
    status: completed
  - id: example-cross-check
    content: "Summarize shared fit across Example A + B + C"
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
**No model break yet** — confirms the plugin should stay a **tree environment**, not a BOM app.  
For “same 100 Ω, different package/shunt” confusion see [`part-identity-layers.md`](part-identity-layers.md).

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

## Example C — Rezepte (recipes)

### Story (planning walkthrough)

A cooking / recipe site. Users browse **recipes** and **ingredients**, cook with scaled amounts, plan meals, and shop.

| Aspect | Detail |
|--------|--------|
| Category tree | Rezepte by kind (Vorspeise, Hauptgericht, Dessert), cuisine, diet (vegan, …) |
| Ingredient catalog | Tree of ingredients (Gemüse, Gewürze, Milchprodukte, …) with properties |
| Recipe | Title, time, difficulty, portions; **consists of** ingredient lines (amount + unit + ingredient); steps (ordered text) |
| Amounts | Classic **measure**: `200 g`, `1 EL`, `½ l` → number + Präfix? + Basiseinheit (or kitchen units) |
| Scale | Change portions → rescale all measures |
| Lists | Recipe index; filter by category / diet / ingredient |
| Compare | Two recipes side by side (time, calories, shared ingredients) |
| Meal plan | Combine recipes into a week plan (composition of recipes) |
| Shopping list | Aggregate ingredient measures across planned recipes |
| Stats | Popular recipes, average ratings, “most used ingredients” |

### Fit vs current model

| Need | Fits in **WP Taxonomy Tree**? | Where it lives |
|------|-------------------------------|----------------|
| Recipe / ingredient **category trees** | **Yes** | `Project` + Nodes |
| Ingredient properties (Allergen, Saison, …) | **Yes (definitions)** | Type catalog + `consists_of` |
| Recipe attributes (Zeit, Schwierigkeit, Portionen) | **Yes (definitions)** | measure / enum / integer on recipe node |
| Ingredient line: amount + unit + ingredient ref | **Partial** | measure types + `uses`/`consists_of` toward ingredient node; **line list UX = host** or rich editor |
| Ordered cooking steps | **Weak / host** | Ordered text/steps are content — host CPT or block editor, not core tree |
| Scale portions | **No — host** | Recalculate measure values |
| Recipe index / filters | **Partial** | Tree browse = environment; faceted index = host |
| Compare two recipes | **Partial** | Shared schema from tree; compare UI = host |
| Meal plan (recipes → week) | **Like Hardware builds** | Optional Relations; **plan entity = host** |
| Shopping list aggregation | **No — host** | Sum measures across recipes (needs unit conversion — host) |
| Ratings / popularity stats | **No — host** | Analytics |

### Boundary sketch

```text
┌──────────────────────────────────────────────┐
│ WP Taxonomy Tree                             │
│  Recipe category tree                        │
│  Ingredient catalog tree                     │
│  Types: measure (g, ml, EL…), enum, integer  │
│  Recipe/ingredient attribute defs            │
│  Optional Relations: recipe uses ingredient  │
│  Tree UI + selection hooks                   │
└──────────────────┬───────────────────────────┘
                   │ node ids + measures/attrs
                   ▼
┌──────────────────────────────────────────────┐
│ Host: recipes app                            │
│  Recipe content (steps, photos, portions)    │
│  Ingredient lines editor + scaling           │
│  Meal plans, shopping lists                  │
│  Compare, ratings, statistics                │
│  Unit conversion for aggregates              │
└──────────────────────────────────────────────┘
```

### What this example stresses (still open)

| Topic | Why Recipes care | Open ref |
|-------|------------------|----------|
| Kitchen units as Basiseinheit | g, ml, EL, TL, Stück, … | Definitionsbaum units |
| measure everywhere | amounts, time, calories | Q36–Q37 |
| Recipe→ingredient links | `uses` / `consists_of` + amount on the edge? | Q35 — **edge props** for quantity |
| Unit conversion for shopping lists | 1 EL Butter → g | **host** (not tree core) |
| Steps as content | not taxonomy | host |
| Same pattern as BOM lines / PC builds | composition + quantities | A/B/C alignment |

### Special note: amount on the Relation?

Recipes push **`props` on Relation** harder than BOM/Hardware:  
`Rezept ─[uses]→ Mehl` may need `{ value: 200, unit_group: (prefix?, g) }` on the **edge**.

Same spin for parts: `Widerstand ─[wert]→` with `{ value: 100 }` + **unit group** `(k, Ohm)` → `"100 kOhm"`.  
**Präfix + Basiseinheit always form a group** — not a chain Widerstand→100→kilo→Ohm.

That does **not** break the model — we already sketched `Relation.props` as optional — and it strengthens keeping edge properties + the measure composite (Q35, Q45).

### Verdict for Example C

**Still fits.** Tree + types + optional Relations for recipe/ingredient structure; host for steps, scaling, meal plans, shopping aggregation, ratings, stats.  
Closest cousin to **BOM lines** (quantity + referenced item) and **PC builds** (composition), with extra pressure on **measure** and **Relation.props**.

Related use-case cards: UC-40… in [`use-cases.md`](use-cases.md).

---

## Cross-check: Example A + B + C

| Concern | BOM (A) | Hardware (B) | Rezepte (C) | Model still OK? |
|---------|---------|--------------|-------------|-----------------|
| Browse/select from tree | parts | hardware | recipes / ingredients | **Yes** |
| Typed properties | Wert, Maße | clocks, I/O | time, diet, allergens | **Yes** |
| measure + units | Ohm, mm | MHz, W | g, ml, EL | **Yes** (kitchen units) |
| Domain lists / lines | BOM lines | hardware lists | ingredient lines | **Host** (+ optional Relations) |
| Quantity on link | refs→qty | — | amount on recipe↔ingredient | **Host** / **Relation.props?** |
| Compare | BOM lists | devices/systems | recipes | **Host** |
| Composition | board refs | PC from parts | meal plan from recipes | **Host** (+ Relations) |
| Aggregates | price sum | test stats | shopping list / ratings | **Host** |
| Exports / vendors | CSV | — | — | **Host** |
| Rich content | — | — | steps, photos | **Host** |

**Overall verdict:** Three different domains, **same boundary**. WP Taxonomy Tree stays a **reusable tree + definition (+ relation) environment**. Apps own lists, math, content, analytics.

---

## Example D — (optional later)

Add further examples only if they threaten the boundary.
