---
name: Use cases
overview: Planning use cases for WP Taxonomy Tree — short structured scenarios, not UML diagrams. Reflect Q64 superseded / Q66 inherit; Q14/Q20/Q51; Q34/Q49 proposal pending confirm.
status: draft
version: "0.1.9-plan"
last_updated: "2026-08-07"
related_plans:
  - docs/plans/project-plan.md
  - docs/plans/mvp-requirements.md
  - docs/plans/data-structure.md
  - docs/plans/planning-phase.md
  - docs/plans/example-projects.md
todos:
  - id: agree-format
    content: "Agree use-case card format with user"
    status: in_progress
  - id: draft-core-admin-ucs
    content: "Draft core admin tree use cases (browse, create, delete, select)"
    status: in_progress
  - id: draft-definition-ucs
    content: "Draft Definitionsbaum / type / relation-oriented use cases (UC-10, UC-14–UC-16)"
    status: in_progress
  - id: draft-bom-host-ucs
    content: "Draft BOM example host use cases (UC-20+) linked to example-projects.md"
    status: in_progress
  - id: map-ucs-to-mvp
    content: "Mark which use cases are MVP vs later"
    status: pending
  - id: sync-q64-decisions
    content: "Align cards with Q64 superseded (typed children), Q14 owner=parent, Q66 inherit, Q34/Q49"
    status: completed
---

# Use cases (planning)

> Planning only. Describe **who does what and why**, not how it is implemented.  
> Open questions in [`docs/OPEN-QUESTIONS.md`](../OPEN-QUESTIONS.md) stay open unless already decided — then Notes may say **decided**.

## Decision snapshot (for writers)

| ID | Status | Meaning for use cases |
|----|--------|------------------------|
| **Q64** | superseded | **No Parameter class** — Eigenschaften = typed child Nodes |
| **Q14** | decided | Slot ownership = parent Node (typed child) |
| **Q20** | decided | Typed PHP DTOs for Project, Node, … — **no Parameter DTO** |
| **Q34** | strong lean | **Plain:** special *behavior* via **config** (not “what type”; not PHP subclass); typing is Q48/`type_id` |
| **Q48** | leaning | Types = Nodes + `type_id` OK; ABC challenged — render-only → key (+ optional `builtin.*`); settings → NodeConfig; attrs → composition |
| **Q49** | strong lean | Simples: config `originate_relations=false` (not hard special kind) |
| **Q51** | decided | allows_prefix + multiplikator; UI derives Ohm/kOhm/… |
| **Q55** / **Q66** | decided | Children inherit property-slot defs along `parent_id`; fills = instance values |

## Format (proposed)

One **use-case card** per scenario. Keep it short — prefer many small cards over one long story.

```markdown
### UC-XX — Short title

| Field | Content |
|-------|---------|
| **Actor** | Who (Admin, Host-plugin developer, System, …) |
| **Goal** | What they want to achieve |
| **Trigger** | What starts this |
| **Preconditions** | What must already be true |
| **Main flow** | Numbered happy path (5–8 steps max) |
| **Variants** | Important alternatives / errors (optional) |
| **Outcome** | Observable result when successful |
| **MVP?** | yes / later / unclear |
| **Touches** | Domain objects (Project, Node, …) |
| **Notes** | Links to open questions or examples — no decisions forced |
```

**Rules of thumb**

- Write from the actor’s view (“Admin selects …”), not from the database.
- One primary goal per card.
- Prefer concrete examples from the Definitionsbaum / Bauteile trees where helpful.
- Do **not** resolve open questions here — only reference them under **Notes** (or mark **decided** when already closed elsewhere).

---

## Draft use cases

### UC-01 — Browse a project tree

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | See the hierarchy of a project’s tree and find a node |
| **Trigger** | Opens the taxonomy tree screen for a project |
| **Preconditions** | A Project exists with at least one root node (e.g. Definitionsbaum) |
| **Main flow** | 1. Admin opens the project tree screen<br>2. System shows the root and expandable children<br>3. Admin expands/collapses branches<br>4. Admin locates the desired node |
| **Variants** | Empty project → empty state / CTA to create root |
| **Outcome** | Admin sees the tree structure and can select a node |
| **MVP?** | yes |
| **Touches** | Project, Node (tree = root + descendants) |
| **Notes** | Screen layout still open (Q3) |

### UC-02 — Create a child node

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | Add a new child under a selected node |
| **Trigger** | Chooses “create child” on a selected node |
| **Preconditions** | Node is selected; Admin has capability |
| **Main flow** | 1. Admin selects parent node<br>2. Chooses create child<br>3. Enters name (and optional fields)<br>4. Confirms<br>5. System inserts child under parent |
| **Variants** | Validation failure (empty name); no permission; new child may inherit property defs from parent (Q66) |
| **Outcome** | New Node appears under the parent in the tree |
| **MVP?** | yes |
| **Touches** | Node (inherited defs) |
| **Notes** | Optional fields Q12; ordering Q13; typed children; Q64 superseded |

### UC-03 — Delete a node (promote or cascade)

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | Remove a node and handle its children explicitly |
| **Trigger** | Chooses delete on a selected node |
| **Preconditions** | Node selected; may have children |
| **Main flow** | 1. Admin chooses delete<br>2. If children exist, System asks: promote children to parent **or** delete cascade<br>3. Admin confirms choice<br>4. System applies deletion policy |
| **Variants** | Root delete; last child; cancel; fixed simple-type Node — may be non-deletable |
| **Outcome** | Node gone; children either promoted or removed as chosen |
| **MVP?** | yes |
| **Touches** | Node, Changelog, owned property children |
| **Notes** | Delete UX still to specify in planning-phase; fixed simples Q49 |

### UC-04 — Select a node and see its properties

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | Inspect a node’s details and its property-slot definitions (typed children) |
| **Trigger** | Clicks a node in the tree (e.g. Widerstand) |
| **Preconditions** | Node exists; may have typed children and/or Relations |
| **Main flow** | 1. Admin selects node<br>2. System shows node identity (name, …)<br>3. System shows owned / inherited property children (name + type)<br>4. Admin reviews definitions (and instance values if present) |
| **Variants** | Node without property children yet; inherited slots from parent |
| **Outcome** | Admin understands what this node is and which Eigenschaften it has |
| **MVP?** | unclear |
| **Touches** | Node, Relation? |
| **Notes** | **Q64 superseded**. Display Q42; inherit Q43/Q55/Q66; config Q34 |

### UC-05 — Work with quantity properties (Größe / Wert mit Einheit)

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | Set or read a Größe such as `10 kOhm` or `10 mm` |
| **Trigger** | Edits a property child typed as `quantity` (e.g. Wert, Höhe) |
| **Preconditions** | Template/Definitionsbaum has simples + derived `quantity`; Präfix; Basiseinheit |
| **Main flow** | 1. Admin opens quantity field on the property<br>2. Enters numeric value<br>3. Chooses Präfix (optional) and Basiseinheit<br>4. Saves instance value<br>5. System shows composed reading (e.g. `10 kOhm`) |
| **Variants** | Missing unit; type that forbids prefix (non-quantity / simple scalar) |
| **Outcome** | Quantity (Größe) stored/displayed as value + prefix + unit |
| **MVP?** | later / unclear |
| **Touches** | Node (slot), Type catalog (simple + composed), Präfix, Basiseinheit |
| **Notes** | Name = Größe, not Messung; not BOM Menge. Value storage Q16; numeric_kind Q37; types Q36/Q48 |

### UC-06 — Inherit properties along parent_id

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | See that NPN-Transistor picks up Transistor’s property-slot definitions |
| **Trigger** | Selects a subtype node under a parent type |
| **Preconditions** | Parent has property children; child linked via `parent_id` |
| **Main flow** | 1. Admin selects NPN-Transistor<br>2. System resolves ancestors<br>3. System shows inherited property slots (plus any child-only ones)<br>4. Admin may override or extend (if allowed) |
| **Variants** | No inheritance; conflict override |
| **Outcome** | Subtype shows parent slot defs without redefining them all |
| **MVP?** | later |
| **Touches** | Node, Relation (`is_a`)? |
| **Notes** | **Q66**; Q43 exploratory for `is_a`; override open |

### UC-07 — Host plugin attaches a side panel

| Field | Content |
|-------|---------|
| **Actor** | Host-plugin developer (e.g. wp-electronic-parts) |
| **Goal** | Show domain UI when Admin selects a node |
| **Trigger** | Admin selects a node in the tree environment |
| **Preconditions** | Host plugin registered an extension for this taxonomy/project |
| **Main flow** | 1. Admin selects node<br>2. Tree environment fires selection hook/event<br>3. Host plugin renders its panel (parts list, editor, …)<br>4. Admin works in host UI without leaving the tree screen |
| **Variants** | No host registered → empty/placeholder slot |
| **Outcome** | Tree stays generic; domain UI comes from the host |
| **MVP?** | placeholder slot likely MVP; full contract later |
| **Touches** | Project, Node, extension API |
| **Notes** | Q8, Q9 |

### UC-10 — Create project with required Definitionsbaum anchors

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | Start a Project (≈ taxonomy) that already has Definition / Type / Präfix / Basiseinheit and fixed simples |
| **Trigger** | Creates a new Project |
| **Preconditions** | Admin can create projects; defaults strategy chosen (Q50) |
| **Main flow** | 1. Admin creates Project (name, description)<br>2. System either **generates** default Nodes **or** **copies** a template Project (Q50)<br>3. Result includes Definitionsbaum anchors + fixed simples (`int`…`bool`)<br>4. Admin opens the project tree |
| **Variants** | Customize template Project first (option B); regenerate missing anchors (option A repair) |
| **Outcome** | Project ready with Definitionsbaum and simples available |
| **MVP?** | unclear (Project may be post-MVP tree-only) |
| **Touches** | Project (≈ taxonomy), Node, SimpleType Nodes |
| **Notes** | Q18 Project≈taxonomy; **Q50** generate vs template copy; storage Q19 |

### UC-14 — Assign a property slot with a data type

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | Declare that a property slot (e.g. Menge, Stock, Wert) uses a specific type |
| **Trigger** | Adds or edits a typed child on a Node / Composition column |
| **Preconditions** | Owning Node exists; type Nodes available (simple or composed) |
| **Main flow** | 1. Admin selects Node<br>2. Adds property child with **name** (user text) and **type_id** (e.g. `int`, `bool`, `enum`, or `quantity`)<br>3. System stores typed child under the Node<br>4. UI widgets follow the type |
| **Variants** | Change type later; target type hidden/disabled in this project |
| **Outcome** | Slot has a clear type; forms/tables render the matching control |
| **MVP?** | later / unclear |
| **Touches** | Node, Type Node |
| **Notes** | **Q64 superseded**; Q48 binding; Q34 config lean |

### UC-15 — Create / edit an enum (derived type)

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | Define an enum type as a list of values over exactly one simple base type |
| **Trigger** | Edits `enum` under Datentypen (template) or creates a concrete enum |
| **Preconditions** | Template simples exist (`int`…`bool`) |
| **Main flow** | 1. Admin opens enum under Datentypen<br>2. Sets **base_type** to exactly one simple (e.g. `text`)<br>3. Adds value Nodes as children (e.g. `0201`, `0603`)<br>4. Saves; Property children may set `type_id` → this enum |
| **Variants** | Missing base_type; values not conforming to base (validation later) |
| **Outcome** | Closed option list typed by one simple; UI shows select/radio/multi |
| **MVP?** | later / unclear |
| **Touches** | Type Node (enum), SimpleType Nodes, Relation `base_type`, value child Nodes |
| **Notes** | Q36/Q38/Q39 agreed direction; template assignment Q50 |

### UC-16 — Restrict Relations on simple data-type Nodes

| Field | Content |
|-------|---------|
| **Actor** | Admin / System |
| **Goal** | Prevent (or allow by exception) simple types from originating Relations |
| **Trigger** | Tries to add a Relation **from** a simple type Node (e.g. `int` → …) |
| **Preconditions** | Simple type Nodes exist; Relation UI available |
| **Main flow** | 1. Admin selects a simple type Node<br>2. Attempts to add an outgoing Relation<br>3. System blocks **or** allows based on rule/config<br>4. Admin still can use the simple as a slot `type_id` target (e.g. Menge type=`int`) |
| **Variants** | Special Node kind permanently forbids; config flag disables; override for power users |
| **Outcome** | Simples stay “leaf types”; catalog / Composition Nodes own property children and Relations |
| **MVP?** | later / unclear |
| **Touches** | SimpleType Node, Relation, Node.config? |
| **Notes** | **Q49 open** — special kind vs config disable; decide with Q34 |

### UC-17 — Work with / define a quantity type (Größe)

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | Use the derived `quantity` type (Größe = value × unit group) for slots like Wert |
| **Trigger** | Binds a property child to `quantity` or inspects the template type |
| **Preconditions** | Template has `quantity` under Datentypen; Präfix and Basiseinheit branches exist |
| **Main flow** | 1. Admin selects property child (e.g. Wert)<br>2. Sets `type` → `quantity`<br>3. Enters value; unit select is fed Basiseinheit (e.g. Ohm)<br>4. UI lists derived choices (Ohm, kOhm, … from Vater + `allows_prefix` Präfixe)<br>5. Saves `{value, prefix?, base_unit}`; display e.g. `10 kOhm` |
| **Variants** | Prefix omitted; numeric kind int vs double (Q37); value on Relation.props (Q45) |
| **Outcome** | Slot behaves as Größe; unit options generated — no atomic `kOhm` Nodes |
| **MVP?** | later / unclear |
| **Touches** | Node (slot), Type Node (`quantity`), Präfix, Basiseinheit, Relation `allows_prefix` |
| **Notes** | Renamed from informal `measure`; Q36/Q28/Q45/Q51 |

---

## Example A — BOM (host + tree)

See full story and fit/gap in [`example-projects.md`](example-projects.md).  
Cards below split **tree environment** vs **host BOM**.

### UC-20 — Browse part tree to find a part for a BOM line

| Field | Content |
|-------|---------|
| **Actor** | User (site with projects / BOM) |
| **Goal** | Find a part (Widerstand, IC, …) in the category tree |
| **Trigger** | Adding or editing a BOM line; opens part picker |
| **Preconditions** | Bauteile tree exists with categorized parts |
| **Main flow** | 1. User opens part picker (tree UI)<br>2. Expands categories<br>3. Selects a part node<br>4. Sees part properties / values (Wert, Bauform, Datenblatt, …)<br>5. Confirms selection for the BOM line |
| **Variants** | Part missing → create/request new part (later) |
| **Outcome** | A concrete part node is chosen for the line |
| **MVP?** | Tree browse/select = taxonomy-tree; picker wiring = host |
| **Touches** | Node, Project, extension |
| **Notes** | Example A; UC-01/UC-04; Q64 superseded |

### UC-21 — Build a BOM line with references and Menge in Stück

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Record 1…N board placements (RefDes) and Menge in **Stück** |
| **Trigger** | Edits a BOM line (Position under a PlatinenVersion Bauteilliste) |
| **Preconditions** | Catalog Bauteil available (UC-20); Bauteilliste exists for the version |
| **Main flow** | 1. User enters **Referenz** in compact form (`R1`, `R1,R4,R6`, `R1-R5`, `R1-R5, R8`)<br>2. System expands to canonical **position list** and sets Menge = count(positions) in **Stück** (`int`, Q58)<br>3. User picks **Wert** = Bauteil from catalog<br>4. Optional description, price, stock/status<br>5. Saves line |
| **Variants** | Invalid range; duplicate RefDes in same PlatinenVersion; Menge mismatch |
| **Outcome** | Position with compact UX, stored positions[], catalog Wert, Menge |
| **MVP?** | later — **host** / Composition |
| **Touches** | Position; Bauteil catalog; RefDesListe (Q47) |
| **Notes** | Menge ≠ `quantity` (Größe). Q58. See MODEL-CATALOG Platine planned. |

### UC-21c — Interactive BOM: highlight board positions

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | See where a BOM line’s Bauteil sits on the Platine |
| **Trigger** | Selects a Position line or its catalog Wert in an interactive BOM view |
| **Preconditions** | Positions stored as expanded RefDes list (UC-21 / Q47); board view available for that PlatinenVersion |
| **Main flow** | 1. User selects a BOM line (or Bauteil used on the board)<br>2. System reads stored position list for that line<br>3. UI highlights matching RefDes placements on the board |
| **Outcome** | Visual link line ↔ board placements |
| **MVP?** | later — interactive BOM |
| **Touches** | Position.Referenz canonical store; board view |
| **Notes** | Compact string alone is insufficient for hit-testing — expand-on-save is the design requirement. |

### UC-21b — Fill Name when placing a BOM on a page

| Field | Content |
|-------|---------|
| **Actor** | Editor (WP page) |
| **Goal** | Set the instance **Name** for a BOM on a page |
| **Trigger** | Inserts/edits a BOM block on a page |
| **Preconditions** | Tree has **Compositionen** with property child **Name** (`slot_scope: composition`); structure **BOM** exists (**Q61**/Q63/Q70/Q54) |
| **Main flow** | 1. Editor picks table art under Collection<br>2. Enters **Name** (required composition-scoped instance value)<br>3. Adds rows/Bauteile like Backend (row-scoped columns)<br>4. Title under table shows **`BOM als Bauteilliste – {Name}`** |
| **Outcome** | Instance has Name; tree node still named **BOM** |
| **MVP?** | planning / later block |
| **Touches** | Instance Name; CompositionRows |
| **Notes** | Do not rename the tree structure node to the instance name. Q70: Name is never a table column. |

### UC-22 — See BOM Fußzeile (per-column aggregates)

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | See Fußzeile with same columns; each cell may aggregate its column |
| **Trigger** | Views BOM table |
| **Preconditions** | BOM has lines; Composition has Fußzeile (**Q57**); columns have `footer_op` |
| **Main flow** | 1. System renders Fußzeile with **one cell per column**<br>2. For each column: apply `footer_op` (`sum` / `avg` / `min` / `max` / `count` / none\|label) over filled rows<br>3. E.g. Menge → sum in **Stück**; Preis → sum; text → empty/label |
| **Outcome** | Aligned footer row with per-column results |
| **MVP?** | planning / Composition view |
| **Touches** | Composition table + footer; column `config.footer_op` |
| **Notes** | Q57 — same column count; content may deviate via simple math only |

### UC-23 — Find BOMs that share the same parts

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Compare or search lists with overlapping parts |
| **Trigger** | Search/compare from a BOM or part |
| **Preconditions** | Several BOM lists reference part nodes |
| **Main flow** | 1. User starts compare/search<br>2. System finds lists sharing part node ids<br>3. User reviews overlaps |
| **Outcome** | Comparable set of BOMs |
| **MVP?** | later — **host** |
| **Touches** | Host query; Node ids as keys |
| **Notes** | Tree plugin only supplies stable part identity |

### UC-24 — Export supplier request CSV

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Produce a CSV/request sheet for Digikey, Conrad, AliExpress, … |
| **Trigger** | Chooses export for a supplier |
| **Preconditions** | BOM lines have parts (and host knows supplier mapping) |
| **Main flow** | 1. User picks supplier profile<br>2. Host maps part properties / values → supplier columns<br>3. Downloads CSV |
| **Variants** | Missing MPN / supplier sku |
| **Outcome** | File ready for the vendor |
| **MVP?** | later — **host** |
| **Touches** | Host exporters; may read part properties from tree/defs |
| **Notes** | Taxonomy-tree does not own vendor formats |

---

## Example B — Hardware / compare / tests / builds

See [`example-projects.md`](example-projects.md) Example B.

### UC-30 — Browse hardware tree by category

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Find a graphics card, sound card, motherboard, … |
| **Trigger** | Opens hardware catalog |
| **Preconditions** | Category tree with hardware nodes exists |
| **Main flow** | 1. Browse categories<br>2. Select an item<br>3. See type-specific properties / values |
| **Outcome** | Hardware node selected with its properties visible |
| **MVP?** | Tree = taxonomy-tree; rich list = host/later |
| **Touches** | Node, `is_a`? |
| **Notes** | Different property sets per branch (Q43/Q55/Q66); Q64 superseded |

### UC-31 — Compare two hardware items of the same kind

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Compare sound card A vs B (or two GPUs, …) |
| **Trigger** | Chooses compare on two items |
| **Preconditions** | Both nodes share a comparable property schema (same family) |
| **Main flow** | 1. Pick two nodes<br>2. Host loads shared properties / instance values<br>3. Shows side-by-side compare |
| **Variants** | Incomparable types → warn |
| **Outcome** | Difference view of properties |
| **MVP?** | later — **host** UI; schema from tree |
| **Touches** | Node slots; host compare |
| **Notes** | — |

### UC-32 — Record a component performance test

| Field | Content |
|-------|---------|
| **Actor** | User / lab editor |
| **Goal** | Attach a speed/performance test result to a hardware item |
| **Trigger** | Adds a test run for a selected card |
| **Preconditions** | Hardware node exists |
| **Main flow** | 1. Select hardware<br>2. Enter test type + result metrics<br>3. Save run<br>4. View result on the item |
| **Outcome** | Test result stored and visible |
| **MVP?** | later — **host** |
| **Touches** | Host test run; Node id as subject |
| **Notes** | Results are not taxonomy Nodes |

### UC-33 — Build a computer from hardware parts

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Combine GPU, sound card, motherboard, … into one computer |
| **Trigger** | Creates/edits a computer build |
| **Preconditions** | Part nodes exist in the tree |
| **Main flow** | 1. Create computer<br>2. Pick parts from tree (UC-30)<br>3. Host stores build membership (optional: Relations `uses`)<br>4. Shows build bill of hardware |
| **Outcome** | Computer references selected part nodes |
| **MVP?** | later — **host** (+ optional Relations) |
| **Touches** | Node; Relation `uses`?; host build |
| **Notes** | Q35 — composition via Relation vs host-only |

### UC-34 — Compare computer test results and see stats

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Compare system benchmarks and see summary statistics |
| **Trigger** | Opens compare/stats for computer tests |
| **Preconditions** | Computers have test result sets |
| **Main flow** | 1. Select systems or filter tests<br>2. Host compares result sets<br>3. Shows charts/tables<br>4. Shows aggregated statistics |
| **Outcome** | Comparable results + summaries |
| **MVP?** | later — **host** |
| **Touches** | Host analytics |
| **Notes** | — |

---

## Example C — Rezepte

See [`example-projects.md`](example-projects.md) Example C.

### UC-40 — Browse recipe and ingredient trees

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Find a recipe by category or an ingredient in the catalog |
| **Trigger** | Opens recipes / ingredients |
| **Preconditions** | Category trees exist |
| **Main flow** | 1. Browse recipe or ingredient tree<br>2. Select a node<br>3. See properties (Zeit, Diet, Allergene, …) |
| **Outcome** | Recipe or ingredient node selected |
| **MVP?** | Tree = taxonomy-tree |
| **Touches** | Node |
| **Notes** | Q64 superseded |

### UC-41 — Define recipe ingredient lines with amounts

| Field | Content |
|-------|---------|
| **Actor** | Editor |
| **Goal** | Attach ingredients with amounts (e.g. 200 g Mehl) to a recipe |
| **Trigger** | Edits a recipe’s ingredients |
| **Preconditions** | Recipe node + ingredient nodes; quantity types/units available |
| **Main flow** | 1. Select recipe<br>2. Add ingredient from tree<br>3. Enter quantity (value + unit)<br>4. Save lines |
| **Variants** | Missing unit; kitchen unit vs SI |
| **Outcome** | Recipe lists ingredient lines with quantities |
| **MVP?** | later — **host** editor; optional Relation + `props` |
| **Touches** | Node; Relation `uses`?; quantity; Relation.props? |
| **Notes** | Strong case for edge props (example C); Q45 |

### UC-42 — Scale recipe portions

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Change portions and rescale all ingredient amounts |
| **Trigger** | Changes portion count |
| **Preconditions** | Recipe has ingredient quantities + base portions |
| **Main flow** | 1. Set new portions<br>2. Host recalculates each quantity<br>3. Shows scaled list |
| **Outcome** | Scaled shopping-ready amounts |
| **MVP?** | later — **host** |
| **Touches** | Host calc; quantity values |
| **Notes** | — |

### UC-43 — Build a meal plan and shopping list

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Combine recipes into a plan and aggregate a shopping list |
| **Trigger** | Creates meal plan / “add to shopping list” |
| **Preconditions** | Recipes with ingredient lines |
| **Main flow** | 1. Add recipes to plan<br>2. Host aggregates ingredients<br>3. Converts/sums units where possible<br>4. Shows shopping list |
| **Variants** | Unconvertible units |
| **Outcome** | Plan + aggregated list |
| **MVP?** | later — **host** (+ optional Relations) |
| **Touches** | Host plan; Node ids; unit conversion |
| **Notes** | Same composition pattern as PC builds / BOM |

### UC-44 — Compare recipes and view popularity stats

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Compare two recipes; see site stats (popular, ratings) |
| **Trigger** | Compare or opens stats dashboard |
| **Preconditions** | Recipes + optional ratings data |
| **Main flow** | 1. Pick recipes or open stats<br>2. Host shows side-by-side attrs / shared ingredients<br>3. Shows aggregates (ratings, most-used ingredients) |
| **Outcome** | Compare view and/or statistics |
| **MVP?** | later — **host** |
| **Touches** | Host analytics; tree properties |
| **Notes** | — |

---

## Backlog (titles only — not written yet)

- UC-08 Rename node  
- UC-09 Reparent node  
- UC-11 Manage enum attribute (`selection_mode` single/multiple)  
- UC-12 View relation as directed arrow vs undirected line (graph chrome)  
- UC-13 Template tree → instantiate into project tree  
- UC-25 Create/edit part property children in the tree (Wert, Maße, Datenblatt, …)  
- ~~UC-10~~ → written  
- ~~UC-14–UC-16~~ → written (slot type / enum / simple Relation restrict)  
- ~~UC-17~~ → written (quantity / Größe)

Add cards when we pick the next slice.
