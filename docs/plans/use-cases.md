---
name: Use cases
overview: Planning use cases for WP Taxonomy Tree — short structured scenarios, not UML diagrams. Open questions stay open; use cases drive later decisions.
status: draft
version: "0.1.1-plan"
last_updated: "2026-07-23"
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
    content: "Draft Definitionsbaum / type / relation-oriented use cases"
    status: pending
  - id: draft-bom-host-ucs
    content: "Draft BOM example host use cases (UC-20+) linked to example-projects.md"
    status: in_progress
  - id: map-ucs-to-mvp
    content: "Mark which use cases are MVP vs later"
    status: pending
---

# Use cases (planning)

> Planning only. Describe **who does what and why**, not how it is implemented.  
> Open questions in [`docs/OPEN-QUESTIONS.md`](../OPEN-QUESTIONS.md) stay open unless a use case forces a decision.

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
| **Touches** | Domain objects (Project, Node, Relation, …) |
| **Notes** | Links to open questions or examples — no decisions forced |
```

**Rules of thumb**

- Write from the actor’s view (“Admin selects …”), not from the database.
- One primary goal per card.
- Prefer concrete examples from the Definitionsbaum / Bauteile trees where helpful.
- Do **not** resolve open questions here — only reference them under **Notes**.

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
| **Variants** | Validation failure (empty name); no permission |
| **Outcome** | New Node appears under the parent in the tree |
| **MVP?** | yes |
| **Touches** | Node |
| **Notes** | Optional fields Q12; ordering Q13 |

### UC-03 — Delete a node (promote or cascade)

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | Remove a node and handle its children explicitly |
| **Trigger** | Chooses delete on a selected node |
| **Preconditions** | Node selected; may have children |
| **Main flow** | 1. Admin chooses delete<br>2. If children exist, System asks: promote children to parent **or** delete cascade<br>3. Admin confirms choice<br>4. System applies deletion policy |
| **Variants** | Root delete; last child; cancel |
| **Outcome** | Node gone; children either promoted or removed as chosen |
| **MVP?** | yes |
| **Touches** | Node, Changelog |
| **Notes** | Delete UX still to specify in planning-phase |

### UC-04 — Select a node and see related attributes

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | Inspect a node’s details and related “consists of” attributes |
| **Trigger** | Clicks a node in the tree (e.g. Widerstand) |
| **Preconditions** | Node exists; may have Relations / parameters |
| **Main flow** | 1. Admin selects node<br>2. System shows node identity (name, …)<br>3. System shows related items according to RelationType display (e.g. consists_of as attributes)<br>4. Admin reviews values / definitions |
| **Variants** | Node with no relations; inherited attributes from `is_a` parent |
| **Outcome** | Admin understands what this node is and what it consists of |
| **MVP?** | unclear |
| **Touches** | Node, Relation, RelationType, Parameter? |
| **Notes** | Display Q42; inherit Q43; Parameter-as-Node Q33 — all still open |

### UC-05 — Work with measure attributes (Wert mit Einheit)

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | Set or read a measure such as `10 kOhm` or `10 mm` |
| **Trigger** | Edits an attribute typed as `measure` on a node (e.g. Wert, Höhe) |
| **Preconditions** | Definitionsbaum has Type/`measure`, Präfix, Basiseinheit |
| **Main flow** | 1. Admin opens measure field<br>2. Enters numeric value<br>3. Chooses Präfix (optional) and Basiseinheit<br>4. Saves<br>5. System shows composed reading (e.g. `10 kOhm`) |
| **Variants** | Missing unit; type that forbids prefix (non-measure) |
| **Outcome** | Measure reading stored/displayed as value + prefix + unit |
| **MVP?** | later / unclear |
| **Touches** | Parameter or consists_of Node, Type catalog, Präfix, Basiseinheit |
| **Notes** | Value storage Q16; numeric_kind Q37; core types Q36 |

### UC-06 — Inherit attributes along `is_a`

| Field | Content |
|-------|---------|
| **Actor** | Admin |
| **Goal** | See that NPN-Transistor picks up Transistor’s consists_of attributes |
| **Trigger** | Selects a subtype node that `is_a` a parent type |
| **Preconditions** | Parent has consists_of relations; child linked via `is_a` |
| **Main flow** | 1. Admin selects NPN-Transistor<br>2. System resolves `is_a` ancestors<br>3. System shows inherited consists_of attributes (plus any child-only ones)<br>4. Admin may override or extend (if allowed) |
| **Variants** | No inheritance; conflict override |
| **Outcome** | Subtype shows parent composition attributes without redefining them all |
| **MVP?** | later |
| **Touches** | Node, Relation (`is_a`, `consists_of`), RelationType.inheritable |
| **Notes** | Q43, Q30 — mechanics open |

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
| **Main flow** | 1. User opens part picker (tree UI)<br>2. Expands categories<br>3. Selects a part node<br>4. Sees part attributes (Wert, Bauform, Datenblatt, …)<br>5. Confirms selection for the BOM line |
| **Variants** | Part missing → create/request new part (later) |
| **Outcome** | A concrete part node is chosen for the line |
| **MVP?** | Tree browse/select = taxonomy-tree; picker wiring = host |
| **Touches** | Node, Project, Relation/attributes, extension |
| **Notes** | Example A; UC-01/UC-04 |

### UC-21 — Build a BOM line with references and derived quantity

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | Record 1…N board references and get quantity automatically |
| **Trigger** | Edits a BOM line |
| **Preconditions** | Part selected (UC-20); BOM list exists |
| **Main flow** | 1. User enters references (e.g. R1, R2, R5)<br>2. System sets quantity = count(references)<br>3. User may add description, price, stock flag<br>4. Bauform may come from part or be set on the line<br>5. Saves line |
| **Variants** | Duplicate refs; qty override (if ever allowed) |
| **Outcome** | BOM line with refs, part, qty, meta |
| **MVP?** | later — **host** |
| **Touches** | Host BOM line; part → Node id |
| **Notes** | Out of scope for taxonomy-tree core |

### UC-22 — See BOM totals (part count + price sum)

| Field | Content |
|-------|---------|
| **Actor** | User |
| **Goal** | See how many parts and total price on the list |
| **Trigger** | Views BOM footer |
| **Preconditions** | BOM has lines with prices |
| **Main flow** | 1. System sums quantities / distinct lines (rule TBD by host)<br>2. System sums line prices<br>3. Shows totals at end of BOM |
| **Outcome** | Count + price sum visible |
| **MVP?** | later — **host** |
| **Touches** | Host BOM |
| **Notes** | — |

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
| **Main flow** | 1. User picks supplier profile<br>2. Host maps part attributes → supplier columns<br>3. Downloads CSV |
| **Variants** | Missing MPN / supplier sku |
| **Outcome** | File ready for the vendor |
| **MVP?** | later — **host** |
| **Touches** | Host exporters; may read part attributes from tree/defs |
| **Notes** | Taxonomy-tree does not own vendor formats |

---

## Backlog (titles only — not written yet)

- UC-08 Rename node  
- UC-09 Reparent node  
- UC-10 Create project + required Definitionsbaum anchors  
- UC-11 Manage enum attribute (`selection_mode` single/multiple)  
- UC-12 View relation as directed arrow vs undirected line (graph chrome)  
- UC-13 Template tree → instantiate into project tree  
- UC-25 Create/edit part properties in the tree (Wert, Maße, Datenblatt, …)

Add cards when we pick the next slice.
