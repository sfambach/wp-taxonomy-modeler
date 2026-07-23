---
name: Data structure — Nodes and Parameters
overview: Define the core domain objects for WP Taxonomy Tree. First object Node (parent/children → trees/forests). Second object Parameter. Planning artifact only — no implementation.
status: draft
version: "0.4.0-plan"
last_updated: "2026-07-23"
related_plans:
  - docs/plans/project-plan.md
  - docs/plans/mvp-requirements.md
  - docs/plans/planning-phase.md
todos:
  - id: define-node-core
    content: "Define Node as the first core entity (parent, children, trees, forests)"
    status: completed
  - id: define-parameter-core
    content: "Define Parameter as the second core entity"
    status: in_progress
  - id: define-node-parameter-link
    content: "Define how Parameter relates to Node"
    status: pending
  - id: map-storage
    content: "Decide how Node and Parameter map to WordPress storage"
    status: pending
  - id: decide-optional-fields
    content: "Confirm optional Node and Parameter fields"
    status: pending
---

# Data structure: Nodes and Parameters

> Planning only. This document defines the conceptual data model. No plugin code yet.

## Core objects

| # | Object | Role |
|---|--------|------|
| 1 | **Node** | Builds trees and forests via parent/child links |
| 2 | **Parameter** | Second core object (definition in progress) |

```mermaid
flowchart LR
  N[Node] -->|optional parent| N
  N -->|several children| N
  P[Parameter] -. relates to .-> N
```

Exact Node↔Parameter cardinality is still being defined (see open questions).

---

## 1. Node

### Core idea

1. **One node can have a parent node** (or no parent).
2. **One node can have several child nodes** (or none).
3. From those parent/child links, nodes are used to **build trees**.
4. Several trees together form a **forest**.

```mermaid
flowchart TB
  subgraph Forest
    subgraph TreeA[Tree A]
      R1[Root node A]
      R1 --> C1[Child node]
      R1 --> C2[Child node]
      C1 --> G1[Grandchild node]
    end
    subgraph TreeB[Tree B]
      R2[Root node B]
      R2 --> C3[Child node]
    end
  end
```

### Parent and children

| Rule | Meaning |
|------|---------|
| Optional parent | A node may have **one** parent node, or none |
| At most one parent | Never multiple parents |
| Several children | A node may have **zero or more** child nodes |
| Root | A node with **no parent** is a root |
| Child | A node whose parent is set is a child of that parent |
| Same container | Parent and children belong to the same taxonomy/forest context |

```text
Node ──(optional)──► parent Node
Node ◄──(many)────── child Nodes
```

### Trees and forests

| Concept | Meaning |
|---------|---------|
| Tree | All nodes under one root via parent/child links |
| Forest | One or more trees (typical: all roots in one taxonomy) |

### Node fields

| Field | Required | Type (conceptual) | Meaning |
|-------|----------|-------------------|---------|
| `id` | yes | identifier | Stable identity of the node |
| `parent_id` | yes* | identifier \| `null` | Parent node; `null` = root |
| `name` | yes | string | Display name of the node |
| `taxonomy` | yes | string | Which hierarchical taxonomy / forest this node belongs to |

\* `parent_id` is always present as a value: either a valid parent id or `null`.

#### Planned optional Node fields (not decided yet)

| Field | Meaning | Status |
|-------|---------|--------|
| `slug` | URL/machine slug | open |
| `description` | Longer text | open |
| `count` | Assigned object count | open |
| `position` / `menu_order` | Explicit sibling order | open |
| `meta` | Extensible key/value bag | open |

### Node invariants

1. A node’s `parent_id`, when not `null`, must reference an existing node in the **same** `taxonomy`.
2. A node must not be its own ancestor (no cycles).
3. Structure remains a forest (disjoint trees), never a general multi-parent graph.
4. Delete policies for children: **promote** or **cascade**.

### How nodes build trees and forests

Flat list (parent links rebuild structure):

```text
[
  { id: 1, parent_id: null, name: "Passive Components", taxonomy: "part_category" },
  { id: 2, parent_id: 1,    name: "Resistors",          taxonomy: "part_category" },
  { id: 4, parent_id: null, name: "Semiconductors",     taxonomy: "part_category" }
]
```

- `1 → 2` = Tree A; `4` = Tree B; together = forest for `part_category`.
- Nested `children` is a **view** derived from parent links, not a second source of truth.

---

## 2. Parameter

### Core idea

**Parameter** is the second core object in this data structure.

Parameters describe configurable attributes in the taxonomy-tree environment (names, types, and related definition data). They are distinct from Nodes: nodes form the hierarchy; parameters describe attributes associated with that hierarchy.

```mermaid
flowchart TB
  N[Node] --- P1[Parameter]
  N --- P2[Parameter]
  N --- P3[Parameter]
```

> Cardinality sketch above is a **working assumption** (a node may relate to several parameters). Confirm in planning (Q14).

### Parameter fields (initial — to refine)

| Field | Required | Type (conceptual) | Meaning |
|-------|----------|-------------------|---------|
| `id` | yes | identifier | Stable identity of the parameter |
| `key` | likely | string | Machine key (stable in code/APIs) |
| `label` | likely | string | Human-readable name |
| `type` | likely | string / enum | Parameter type (exact type set TBD) |

#### Fields still to define

| Topic | Status |
|-------|--------|
| Link to Node (`node_id` or similar) | open — Q14 |
| Required / default / validation rules | open |
| Inheritance to child nodes | open |
| Allowed type list (text, number, measure, …) | open |
| Storage (term meta vs custom table vs host-owned) | open — Q15 |
| Whether parameter *values* live in this plugin or only in hosts | open — Q16 |

### What a Parameter is not (until decided otherwise)

- Not a Node (no parent/child tree of parameters unless we explicitly add that later).
- Not necessarily a filled-in value on a part/post — that may be a separate “value” concern.
- Not a replacement for WordPress core term fields (`name`, `slug`, …).

---

## Object summary

| Object | Primary job | Structural link |
|--------|-------------|-----------------|
| Node | Hierarchy | Optional one parent; several children → trees/forests |
| Parameter | Attribute definition | Relates to Node(s) — exact relation TBD |

## Storage mapping (leaning, not final)

| Conceptual field | Likely WordPress mapping |
|------------------|--------------------------|
| Node `id` | term id |
| Node `parent_id` | `wp_term_taxonomy.parent` (`0` ↔ `null`) |
| Node `name` | `wp_terms.name` |
| Node `taxonomy` | `wp_term_taxonomy.taxonomy` |
| Parameter | term meta JSON, custom table, or host storage — **TBD (Q15)** |

## Open points

See [`docs/OPEN-QUESTIONS.md`](../OPEN-QUESTIONS.md) (Q11–Q16).

## Next planning step

Define how Parameter attaches to Node (one node → several parameters?), then parameter types and values. Still planning only — no implementation.
