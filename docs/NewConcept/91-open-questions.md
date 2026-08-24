---
title: Open questions
status: open
round: running
last_updated: 2026-08-22
---

# Open questions

Anything undecided lives here rather than being invented into a document. An answered
question moves to [the decision log](90-decision-log.md) and is marked `closed → D-<nnn>`
here — it is not deleted.

**Id:** `OQ-<nnn>`, never reused. Independent of the legacy `Q<n>` numbering.

---

## OQ-001 — What is in the shared base of node and relation?

> **Closed 2026-08-22 → [D-080](90-decision-log.md).** `id` and `version`, and nothing else.
> `type` is not in the base — a node's type *is* its inheritance branch, and a relation carries its
> own `kind`. `creation_date` came off and is derived from the changelog.

*Blocks:* [10 Domain core](10-domain-core.md), [OQ-017](#oq-017--which-attributes-does-every-node-have) · *Status:* open · *re-framed 2026-08-22 at the request of the owner*

The first wording of this question was too vague to act on. Concretely, it is **four small
decisions**, and only the last is difficult.

The two seed diagrams disagree:

| | [`TreeMeremaid.md`](TreeMeremaid.md) | [`I18nMeremaid.md`](I18nMeremaid.md) |
|---|---|---|
| name | `WPClassHead` | `Identity` |
| fields | `id`, `version`, `creation_date`, `type` | `id` |

### 1. The name

**Recommendation: `Identity`.** `WPClassHead` puts WordPress into the name of the most central
domain class, and `CD-1` says the core must not know WordPress exists. A name that lies is the
first thing that has to go.

### 2. `type` — does it belong here at all?

**Recommendation: no.** A node has a *type* (integer, e-mail); a relation has a *kind*
(inheritance, composition, aggregation). Those are different concepts that happen to share a
short word. Putting either on the shared base forces the other to pretend it has one.

If `type` was meant as a discriminator — *is this row a node or a relation* — it is redundant as
soon as they live in separate tables.

### 3. `creation_date` — stored, or the first changelog entry?

If every object gets a changelog entry on creation ([OQ-008](#oq-008--must-every-object-have-a-changelog-entry)
asks exactly this), then `creation_date` is that entry's timestamp and storing it separately
records one fact twice. If the changelog is optional, it has to be stored.

**The two questions have to be answered together**, and the cheap answer is: creation is always
logged, and `creation_date` comes off the base.

### 4. `version` — and this is the hard one

[C16](10-domain-core.md) and [C17](10-domain-core.md) may be describing **two different
things**, and the single word `version` is hiding it:

| | Reading | What it answers |
|---|---|---|
| **C16** | a **row change counter** — this node was edited | *has this object changed since I last looked* |
| **C17** | a **model version** — the shape of the model changed, existing data needs migrating | *which model version was this data written against* |

A row counter belongs on `Identity` and is derivable from the changelog. A model version belongs
to the model as a whole, is bumped deliberately rather than on every edit, and is the anchor for
[OQ-031](#oq-031--how-does-existing-data-survive-a-model-change). **They are not the same number
and probably should not share a field.**

Until that is separated, `version` on `Identity` cannot be specified — which is why this question
still blocks the core.

---

## OQ-002 — If the tree is inheritance only, what are the other edges?

> **Closed 2026-08-23 → [D-012](90-decision-log.md), [D-161](90-decision-log.md), [C9](10-domain-core.md).**
> All three sub-questions have been answered for two days and the entry simply never caught up: the
> same class distinguished by kind; edges cross branches by design; the inheritance edge is
> protected.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open

[V1](00-vision-and-scope.md) says *nodes and edges*; [V3](00-vision-and-scope.md) says the
tree is inheritance **only**. So non-inheritance edges exist. Open:

1. Are they the same class `Relation` as the inheritance edge, distinguished by type — or a
   different construct entirely?
2. Can a non-inheritance edge cross between trees / roots?
3. Is the inheritance edge protected (not editable like the others)?

**Partially answered by [C10](10-domain-core.md)** (2026-08-22): the edge kinds named so far are
**inheritance, composition, aggregation** — said with "I believe", so recorded, not locked. What
composition and aggregation differ in is [OQ-021](#oq-021--composition-and-aggregation-what-is-the-difference-here).

The legacy round answered a version of this under *hierarchy vs other relations*; that answer
is a harvest candidate, not an inheritance.

---

## OQ-003 — Is `Relation.type` a node or an enum?

> **Closed 2026-08-22 → [D-036](90-decision-log.md):** Kantenarten sind ein Enum.

> **Owner reading, 2026-08-22:** the owner read the flowchart in
> [10 Domain core](10-domain-core.md) as showing *inheritance* — inheritance, composition and
> aggregation as subtypes of `Relation`. **That was my diagram being ambiguous:** a flowchart
> arrow is not a UML generalization, and it was meant as *kind takes one of these values*.
> Noted because exactly this kind of silent misreading is what the previous round accumulated.

### Recommendation, 2026-08-22 — an enum, and the reason is who needs to know

The criterion is not *data feels more flexible*. It is: **can the set grow without the engine
being changed?**

| | Node types | Relation kinds |
|---|---|---|
| Who may add one | the model author, in the configuration ([V7](00-vision-and-scope.md)) | nobody has said anyone may |
| What a new one needs | a renderer, settings — all data or registered code | **rules the engine enforces**: cascade delete, single parent, cycle check |
| What a new one means if nobody wrote rules for it | a working type with default behaviour | indistinguishable from aggregation |

A relation kind is not a value the model carries; it is a **behaviour the engine implements**.
Inventing a fourth kind in the configuration would produce an edge that no code knows how to
cascade, protect or resolve. So the set is closed, and a closed set that code branches on is an
enum.

**This corrects an earlier claim of mine** that OQ-003 and
[OQ-028](#oq-028--is-the-set-of-label-roles-fixed-or-extensible) must get the *same* answer. They
are the same *shape* of question, and the criterion above decides both — but it can decide them
differently, and here it does.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open

[`TreeMeremaid.md`](TreeMeremaid.md) draws `Node <-- Relation : type`, i.e. the type is
itself a node — types are **data**, editable by the user. [`I18nMeremaid.md`](I18nMeremaid.md)
declares `RelationType` as an `<<enumeration>>` — types are **code**, fixed at build time.

These are opposite answers with very different consequences for extensibility, validation and
storage. [V6/V7](00-vision-and-scope.md) — special nodes are *created in the configuration* —
leans toward types-as-data, but does not settle it for edges.

---

## OQ-004 — Do node subtypes exist at all?

> **Closed 2026-08-22 → [D-036](90-decision-log.md):** eine Knotenklasse, Verhalten in registrierten Strategien.

*Blocks:* [10 Domain core](10-domain-core.md), [40 I18n](40-i18n.md) · *Status:* open

[V5](00-vision-and-scope.md) says all nodes are fundamentally the same.
[`I18nMeremaid.md`](I18nMeremaid.md) draws `DomainNode`, `ValueNode` and `I18nValueNode` as
abstract subclasses. Either the diagram is superseded, or V5 means something narrower than it
sounds. Related: [V6](00-vision-and-scope.md)'s *special nodes for data types and
calculations* — are those subclasses, or ordinary nodes with a particular configuration?

### The owner's position, 2026-08-22

Storage is not in question — the general fields go in the nodes table and the specialities into
settings ([C4/C5](10-domain-core.md)). The question is whether an integer node, a double node or
an e-mail node should *additionally* be its own inheriting node **because it may need its own
functions**, and functions cannot live in settings. The owner leans yes, from experience, and
asked for this to be challenged.

### Challenge, 2026-08-22

**Three things are being treated as one, and they do not have to line up:**

| | | Decided by |
|---|---|---|
| **Storage shape** | one nodes table plus settings | already settled — [C4/C5](10-domain-core.md), [D-011](90-decision-log.md) |
| **Kind identity** | how a type is named and enumerated | open |
| **Behaviour** | where the code that does something type-specific lives | **this is the real question** |

The step *it has behaviour, therefore it must be a subclass* is the one worth refusing. Four
reasons:

**1. [V7](00-vision-and-scope.md) already rules it out as the general answer.** Special nodes are
*created in the configuration* — at run time. A PHP subclass cannot be created at run time. So a
type the model author defines has no class, and the system needs a way to give it behaviour
anyway. Once that way exists, the built-in types can use it too.

**2. Single inheritance cannot carry [V8](00-vision-and-scope.md).** A node has a renderer, a
converter, and one *or more* validators. Subclassing gives one axis of variation. Which class
holds *integer that is also a currency that is also read-only in this context*? Strategies
compose; subclasses multiply.

**3. The mechanism already exists and is already decided.** [R12–R14](30-renderer.md): renderers
register, declare which node types they serve, and a node names one by string. That is exactly
*behaviour looked up by kind, code living elsewhere*. Validators and converters are the same
shape. Nothing new has to be invented — only generalised.

**4. What subclassing genuinely buys can be had without persisting subtypes.** The real benefit
is typed access — `min()` returning `int` rather than a settings lookup. That is a **typed view
over a generic node**, constructed on demand, not a subclass of the stored entity. A
configuration-defined type simply has no view and falls back to generic access.

### Recommendation

- **One node class.** No persisted subtypes, no `DomainNode` / `ValueNode` / `I18nValueNode`.
- **Type-specific behaviour lives in registered strategies**, looked up by the node's type key —
  the renderer mechanism, generalised to converters and validators.
- **Built-in types may have a PHP typed accessor** as a convenience and for type safety. It is a
  view over a node, never a subclass of it, and never required for a type to work.

**Where the owner would be right and this would be wrong:** if the set of node types were small,
closed, and never extensible by the model author. [V7](00-vision-and-scope.md) says it is not —
so if V7 is softened, this recommendation should be re-argued rather than kept.

---

## OQ-005 — `RendererRegistry` or `RendererRegister`?

> **Closed 2026-08-22 → [D-091](90-decision-log.md):** eine Registry, reines Nachschlagen; sie implementiert das Renderer-Interface nicht.

> **Half answered 2026-08-22.** [R12–R14](30-renderer.md): one registry, two lookups — by
> renderer name at render time, by node type at configuration time. It is a lookup, and the
> duplicate class in the seed is redundant. Still open: whether the registry itself implements
> the renderer interface, as the PHP sketch has it.

The original text:

*Blocks:* [30 Renderer](30-renderer.md) · *Status:* open

[`RendererMeremaid.md`](RendererMeremaid.md) declares **both**, with overlapping methods
(`getRendererByName` / `getRendererByType` vs. `getRenderer(NodeType)` /
`getRendereByType(RendererType)`). Almost certainly one class. Also unclear: the PHP sketch
has `RendererRegistry implements IRenderer`, which the class diagram does not show — is the
registry itself a renderer, or only a lookup?

---

## OQ-006 — Renderer contract: what is the actual method set?

> **Closed 2026-08-22 → [D-091](90-decision-log.md):** eine Methode, Subjekt ist eine Identität — Knoten oder Kante.

*Blocks:* [30 Renderer](30-renderer.md) · *Status:* open

The PHP sketch in [`RendererMeremaid.md`](RendererMeremaid.md) declares `render()` twice with
different signatures. **PHP has no method overloading**, so that cannot be built as written.
Needs one decision: distinct method names (`renderSingle` / `renderCollection`), or one method
with a mode argument. The same file mixes both styles plus `renderTable` / `renderForm` on
`IPageRendere`.

Also unresolved here: **R3** — whether a renderer receives one node or a set. The seed file
contains both answers.

---

## OQ-007 — Where do renderer, converter and validators attach?

> **Partly answered 2026-08-22 → [D-077](90-decision-log.md).** Question 2 — *is one converter a
> hard limit* — **no**: a node may carry several, and which applies is a setting with a default and
> a per-use-site override. Question 3 — *does the converter run on input too* — **yes for
> invertible converters**, which is what makes searching by a converted form work
> ([D-076](90-decision-log.md)). Question 1, whether renderer/converter/validators are set on the
> node or inherited and overridable, is answered in the same shape by [D-015](90-decision-log.md).

*Blocks:* [10 Domain core](10-domain-core.md), [30 Renderer](30-renderer.md) · *Status:* open

[V8](00-vision-and-scope.md) says essentially every node has one renderer, one converter and
one-or-more validators. [`TreeMeremaid.md`](TreeMeremaid.md) puts exactly these on
`Configuration`. Open:

1. Are they set on the node itself, or inherited down the tree from a type node — and can a
   descendant override?
2. Is *one* converter a hard limit, or a current simplification?
3. Does the converter run on output only, or also on input (parse/normalise)?

---

## OQ-008 — Must every object have a changelog entry?

> **Closed 2026-08-22 → [D-081](90-decision-log.md).** Yes, at least one: if the changelog is the
> migration script and `creation_date` is read from it, then creation must always be logged. Whether
> that enables undo became [OQ-057](#oq-057--is-undo-in-scope).

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open

[`TreeMeremaid.md`](TreeMeremaid.md) draws `WPClassHead "1" --o "1..*" ChangeLogItem` —
cardinality `1..*` means **no object may exist without at least one changelog item**. If that
is intended (creation is always logged), say so explicitly; if `0..*` was meant, fix it. Also
open: `ChangeLogItem.undo()` — is undo in scope at all, and what is undoable?

---

## OQ-009 — Is the delivery target still a WordPress plugin?

> **Closed 2026-08-23 → [D-169](90-decision-log.md).** Yes — and WordPress is used fully rather than
> kept at a distance. What is borrowed is written down instead; how, is [OQ-071](#oq-071--how-is-borrowed-wordpress-marked).

*Blocks:* [00 Vision and scope](00-vision-and-scope.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open

The legacy round targeted a WP plugin serving host plugins such as `wp-electronic-parts`, and
the seed base class is named `WPClassHead`. The 2026-08-22 statement does not mention
WordPress at all. Confirm the target — and whether the domain core is meant to be
WordPress-independent with WordPress only as a persistence and UI host.

---

## OQ-010 — Is an *attribute* the same thing as an *edge*?

> **Closed 2026-08-22 → [D-031](90-decision-log.md): reading 1.** An attribute *is* a relation.
> The wrapper the owner described is real and lives in the user interface — one dialog writes one
> relation row plus a few settings rows.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open

[C1/C2](10-domain-core.md) describe an attribute as having a name, a type, and a kind of
connection to another node. [V1](00-vision-and-scope.md) describes the model as nodes and
edges. [P3](50-wordpress-persistence.md) provides exactly one relations table.

Three readings, and they are not equivalent:

1. **Attribute = edge.** One construct, one table. An attribute is a named, typed edge; the
   inheritance edge is one particular kind. Matches the legacy *Attribute = Relation* lock.
2. **Attribute uses an edge.** The attribute is its own object (name, type) and *has* an edge
   to the target node. Two constructs, two tables.
3. **Attribute is a slot on the node**, and edges are separate. Would need a place for the
   attribute's value that the relations table does not provide.

This is the single most consequential open question in the concept. Reading 1 is the leanest
and is what the previous round converged on — but it converged there after a long detour, so
it deserves to be re-argued rather than assumed.

---

## OQ-011 — What is an attribute's *type*?

> **Closed 2026-08-22 → [D-025](90-decision-log.md).** The type **is the node the relation points
> at**. `to` is the type, `kind` is the connection — two fields of one edge.


*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open

[C2](10-domain-core.md) lists *type* and *kind of connection* as two separate things. So the
type is presumably not the edge kind. Candidates: a data type (`int`, `string`, …) — which
would tie into [V6](00-vision-and-scope.md)'s *special nodes for data types*; the required
type of the target node; or a domain type from the tree. Also unresolved: where a plain
scalar value is actually stored.

---

## OQ-012 — Custom tables, or WordPress terms/posts?

*Blocks:* [50 Persistence](50-wordpress-persistence.md) · *Status:* **closed for the model → [D-007](90-decision-log.md)**

> **Answered 2026-08-22:** the **model** goes into tables owned by this plugin — nodes,
> settings, relations. Not posts, postmeta, terms or CPTs. Storage of the **content** that a
> model describes is a separate question and stays open → [OQ-015](#oq-015--where-does-the-content-live).

The original text, kept because the trade-off it names still applies to OQ-015:

[P1–P4](50-wordpress-persistence.md) name three base tables: nodes, settings, relations. A
generic settings table and a relations table read like **custom tables** (`$wpdb->prefix`),
not like WP terms + termmeta. Needs to be said outright, because it decides what comes for
free (WP admin list tables, REST, caps, i18n of terms) and what has to be built.

Note the plugin's own history: the legacy scaffold ran on **WP terms** (`wtt_fs`). If custom
tables are the answer, that is a break, not an evolution.

---

## OQ-013 — What exactly is a "setting", versus an attribute?

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* **closed → [D-011](90-decision-log.md)**

> **Answered 2026-08-22:** they are the same kind of thing. A setting is an **additional
> attribute** of a node — see [C3](10-domain-core.md). What follows from it is split into
> [OQ-016](#oq-016--is-setting-one-thing-or-two) (does the word also cover tool behaviour?),
> [OQ-017](#oq-017--which-attributes-does-every-node-have) (what is the fixed set?) and
> [OQ-018](#oq-018--where-does-the-value-of-an-extended-attribute-live) (where does the value
> live?).

The original text:

[P2](50-wordpress-persistence.md) gives nodes optional settings in a generic table.
[C1](10-domain-core.md) gives nodes attributes. [`TreeMeremaid.md`](TreeMeremaid.md) gives
`Configuration` a list of `Setting` objects (name, type, value) *and* fields for renderer,
converter, validators.

Both settings and attributes are name/type/value triples hanging off a node. The distinction
has to be stated in one sentence, or the two will keep collapsing into each other:
**attributes model the user's domain; settings configure the tool's behaviour** is the
obvious candidate — confirm or replace it.

---

## OQ-014 — Where does the renderer run: PHP or JavaScript?

> **Closed 2026-08-22 → [D-021](90-decision-log.md): PHP.** The owner accepted the
> recommendation below in full, including metadata-driven editing controls in the Gutenberg
> editor so that no node type ever needs its own JavaScript.


*Blocks:* [30 Renderer](30-renderer.md), [10 Domain core](10-domain-core.md) · *Status:* open

[R1](30-renderer.md) says display happens **only** through a renderer, and [V8](00-vision-and-scope.md)
puts a renderer on essentially every node. That describes renderers as part of the model.

The rule set inherited from the previous round said the opposite, in §7 of the deleted
`generalWPImplRulse.mdc` (now only in git history): *PHP must not render HTML for tree nodes;
PHP acts strictly as a headless JSON API provider*, with a JavaScript app doing the assembly.

Both cannot hold. Either:

1. **Renderers are PHP** and produce markup; the JS admin app consumes rendered fragments.
2. **Renderers are JS**, and PHP only ships nodes plus a `renderer_key`; then the PHP-side
   renderer contract in [`RendererMeremaid.md`](RendererMeremaid.md) is describing something
   else, or nothing.
3. **Both**, with one contract mirrored on each side — the most expensive option, and it needs
   a stated reason.

This decides how much of the concept is PHP at all, so it is worth settling before the
renderer document is written up.

### Recommendation (2026-08-22) — reading 1, with one qualification

The owner leans PHP and asked for advice. Reading 1 is the right one, and it is also the more
WordPress-standard of the two. What follows is the reasoning, kept here so the decision can be
re-checked later rather than remembered.

**PHP already covers two of the three levels of [R8](30-renderer.md), natively.**

| Level | Who renders it, by WordPress convention |
|---|---|
| Admin module | PHP. A classic admin screen is a PHP page. |
| Frontend | PHP. A theme template is PHP. |
| Gutenberg block **output** | PHP, for a *dynamic block* — the block declares a server-side render callback and the markup is produced per request. This is the standard shape for any block that shows live data. |
| Gutenberg block **editor UI** | JavaScript, unavoidably. The editor is React. |

So only the last row genuinely needs JavaScript, and it needs it for **editing controls**, not
for display.

**Reading 2 would force the duplication [R4](30-renderer.md) exists to prevent.** If PHP only
ships JSON and JavaScript renders, then the frontend needs a JavaScript renderer for every node
type — and the admin module and the block output need one too, or a second PHP one. That is the
second implementation R4 forbids, arrived at by accident.

**The qualification: editing.** [R10](30-renderer.md) requires every renderer to support
*editable*. Display in PHP is straightforward; editing controls inside the Gutenberg editor are
React. Two ways out:

1. **Metadata-driven editing.** The editor does not get a React component per node type. It
   gets the node's attributes with their types and settings, and one generic control set
   renders them. New node types then need no JavaScript at all. This keeps R4 intact.
2. **A React component per node type**, mirroring the PHP renderer. Clean-looking, and exactly
   the duplication R4 forbids. Every new type costs two implementations that must not drift.

Option 1 is the recommendation. It also means the PHP renderer needs to expose the attribute
metadata it used, not only the finished markup — worth carrying into the renderer contract.

**For frontend interactivity** WordPress ships an Interactivity API, which is the current
standard for making block output interactive without a separate application. It is the natural
fit for reading 1: the markup stays server-rendered, behaviour is declared on it.

**Not recommended:** reading 3. Two mirrored renderer stacks is the most expensive option and
nothing in the statements so far demands it.

---

## OQ-015 — Where does the content live?

> **Closed 2026-08-22 → [D-083](90-decision-log.md), refined by [D-133](90-decision-log.md).** In
> tables owned by the plugin, beside the model and not inside it: `records` and `record_values`.
> Where a single value physically sits then follows from relation kind and multiplicity.

*Blocks:* [50 Persistence](50-wordpress-persistence.md) · *Status:* open, deliberately deferred

[D-007](90-decision-log.md) puts the **model** — nodes, settings, relations — into tables owned
by the plugin. It says nothing about the **content** that a model describes: the actual
instances a user creates once a model exists.

Candidates, none evaluated yet: the same node tables (content is just more nodes); separate
instance tables per model; WordPress posts, so that content gets the editor, revisions, search
and permalinks for free.

Deferred on purpose — it cannot be answered before [10 Domain core](10-domain-core.md) says
whether *model* and *instance* are even different kinds of thing.

---

## OQ-016 — Is "setting" one thing, or two?

> **Closed 2026-08-22 → [D-084](90-decision-log.md)**, which supersedes the first answer ([D-078](90-decision-log.md)):
> one mechanism and a reserved namespace, not a scope split. The owner rejected the split as being
> on the wrong axis — renderer, converter and validators are set on the node as initial values and
> overridden at a use site, exactly like `min`.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *Raised by* [C3–C5](10-domain-core.md)

Two different kinds of thing currently share the word:

| | Example | Nature |
|---|---|---|
| **Domain content** | an integer node carrying `min`, `max`, `step` | part of what the user models |
| **Tool behaviour** | `order`, `hide`, `read_only`, `renderer`, `converter`, `validators[]` — the `Configuration` box in [`TreeMeremaid.md`](TreeMeremaid.md) | how this tool treats the node |

C3 says settings *are* attributes, and that reading fits the first row cleanly. It fits the
second row much less well: `hide` is not something the user is modelling about the world.

The boundary is genuinely blurry, which is why it needs deciding rather than assuming:
`min` / `max` are read by the **validator**, `renderer` is read by the **renderer** — both are
consumed by the tool, yet only one of them describes the domain. Candidate answers:

1. **One construct.** Everything is an attribute; tool behaviour is just attributes the tool
   happens to read. Leanest, and consistent with V5.
2. **Two constructs.** Attributes model the domain; configuration steers the tool. Clearer to
   read, at the cost of a second concept and a second place to look.
3. **One construct, two namespaces.** One table, one shape, but a reserved namespace for
   tool-owned keys so they cannot collide with user-defined ones.

---

## OQ-017 — Which attributes does every node have?

> **Closed 2026-08-22 → [D-082](90-decision-log.md).** Four: `id`, `version`, `name`, `path`.
> Everything else that looked like a candidate belongs elsewhere — `type` to the branch, `order` to
> the edge, and the rest to settings.

*Blocks:* [10 Domain core](10-domain-core.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *Raised by* [C4](10-domain-core.md)

C4 puts the attributes common to all nodes on the node itself — i.e. they become **columns**.
That makes the list a schema commitment: adding one later is a migration, not an edit.

So the set has to be enumerated and then frozen. Candidates visible so far, none confirmed:
`id`, `type`, `name`, parent / inheritance edge, `version`, `creation_date`, `renderer_key`.
Note the overlap with [OQ-001](#oq-001--one-base-class-or-two-wpclasshead-vs-identity) — whether
`version` and `creation_date` belong on the node at all, or are derived from the change history.

---

## OQ-018 — Where does the value of an extended attribute live?

> **Half dissolved 2026-08-22 → [D-026](90-decision-log.md).** The question mixed two layers. At
> **model** level there is no value, only a **default**. Values belong to instances, and where
> those live is [OQ-015](#oq-015--where-does-the-content-live). What remains here: where a
> *scalar default* is stored (a setting) versus a *record default*
> ([OQ-035](#oq-035--can-a-relation-reach-something-that-is-not-a-model-node)).


*Blocks:* [10 Domain core](10-domain-core.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *Raised by* [C2 vs C5](10-domain-core.md)

Two statements describe the same concept in two storage locations:

- **C2** — an attribute carries a name, a type, and a **kind of connection to another node**.
  The value is another node; the row lives in the relations table.
- **C5** — extended attributes are stored **generically in the settings table**. The value is
  inline; no other node is involved.

Possible resolutions:

1. **Two forms of one concept.** An attribute pointing at a node is a relation row; an
   attribute holding a scalar is a settings row. Needs a stated rule for which is used when,
   or the same fact can be written in two places — which the code standard forbids.
2. **Values are always nodes.** `min = 0` means an edge to a value node holding `0`. Uniform,
   and it makes C5 a storage optimisation rather than a separate concept. Costs a node per
   scalar.
3. **Values are never nodes.** Then C2's *connection to another node* means only the
   attribute's declared target **type**, not its value — and the relations table holds
   structure only.

This question and [OQ-010](#oq-010--is-an-attribute-the-same-thing-as-an-edge) are the same
question seen from two sides. They should be answered together, in one sitting.

---

## OQ-019 — Cycles and depth in the render descent

> **Closed 2026-08-22 → [D-100](90-decision-log.md).** Cycles are detected and draw a **reference**;
> a depth limit additionally **warns**, because there something really is missing. One guard for both
> the render descent and the calculation walk.

*Blocks:* [30 Renderer](30-renderer.md) · *Status:* open · *Raised by* [R5, R7](30-renderer.md)

R7 descends: node → attributes → target nodes → their renderers → their attributes. Nothing in
the statement bounds that walk. Two ways it does not terminate:

- **A cycle.** Composition and aggregation edges can form one, directly or through several
  hops. Inheritance is a tree and cannot, but the other edge kinds are a graph.
- **Depth.** A deep but finite model can still blow the stack or the page.

Needs a stated rule: detect visited nodes and stop, cap the depth, or both — and what the
renderer emits when it stops (nothing, a placeholder, a link).

---

## OQ-020 — Loading the subgraph without an N+1

> **Answered 2026-08-22 → [D-014](90-decision-log.md).** Load the subgraph and every settings row
> it touches in a small fixed number of batched queries before rendering; neither resolver nor
> renderer touches the database. The ancestor walk is served by an indexed structure, decided as
> a schema shape rather than added later.

*Blocks:* [30 Renderer](30-renderer.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *Raised by* [R7](30-renderer.md) vs `CD-7`

The descent in R7 is the exact shape `CD-7` forbids: if each step loads its target node, its
settings and its edges from the database, rendering one composed node costs one query per node
plus one per settings lookup.

So the walk has to run over an **already-loaded** graph: the subgraph is fetched in a small
fixed number of batched queries before rendering starts, and the renderer never touches the
database. Open: how far the fetch reaches when the depth is not known in advance, and whether
that is one repository call, a lazy batch loader, or a materialised path.

This is not a detail to settle during implementation. It decides whether the renderer takes a
node or a loaded graph, which is the same question as R3.

---

## OQ-021 — Composition and aggregation: what is the difference here?

> **Answered 2026-08-22 → both are needed, and the difference is lifecycle.**
> [C12/C13](10-domain-core.md): a composed part belongs to the whole and is deleted with it; an
> aggregated target is independent and always another node. The follow-on question — whether a
> composed part may be stored inline instead of as a node — is
> [OQ-026](#oq-026--a-part-used-in-only-one-place-a-node-or-something-smaller).

The original text:

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *Raised by* [C10](10-domain-core.md)

C10 names three edge kinds: inheritance, composition, aggregation. In UML the distinction is
lifecycle — a composed part dies with its whole, an aggregated part outlives it and can be
shared. Whether this model needs that distinction has not been said.

Concretely: does deleting a whole delete its composed parts? Can an aggregated part belong to
two wholes at once? If the answers do not differ, the model has one edge kind with two names,
which is the kind of duplication that cost the previous round dearly.

---

## OQ-022 — One settings table, or one per owner kind?

*Blocks:* [10 Domain core](10-domain-core.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* **answered — see the marker below** · *Raised by* [C8](10-domain-core.md)

C8 hangs settings on edges as well as nodes. [P4](50-wordpress-persistence.md) named one
settings table. Options:

1. **One table, polymorphic owner** (`owner_kind` + `owner_id`). One shape, one query path —
   but no database can enforce that foreign key, so integrity moves into the code.
2. **One table per owner kind** — `node_settings`, `relation_settings`. The database enforces
   both keys, at the price of two code paths for one concept.
3. **One id space for everything with identity.** If `Node` and `Relation` draw their ids from
   one sequence — which is what a shared `Identity` base implies
   ([OQ-001](#oq-001--one-base-class-or-two-wpclasshead-vs-identity)) — a single `owner_id`
   points at one table and the foreign key is real again.

Option 3 is the one that makes [OQ-001](#oq-001--one-base-class-or-two-wpclasshead-vs-identity)
matter beyond naming.

> **Answered 2026-08-22 → option 3.** [C11](10-domain-core.md): nodes and edges share
> `Identity`, and drawing ids from one common space is acceptable. A settings row then names one
> `owner_id` into one identity space and the foreign key is real. *The question was badly
> phrased when first written — it was never about which nodes an edge belongs to (an edge
> obviously has a from and a to), but about how a settings row states whether its owner is a
> node or an edge. The shared id space removes the need to state it at all.*

---

## OQ-023 — Is inheritance one edge kind, or a separate construct?

> **Closed 2026-08-22 → [D-012](90-decision-log.md): one construct.** Inheritance is one kind of
> `Relation`, with its special rules carried as invariants rather than as a second class.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *asked by the owner, 2026-08-22*

The owner is unsure whether it is better to define edges generically and give inheritance extra
rules, or to take inheritance out as its own construct.

**Recommendation: one construct, `Relation`, with inheritance as one type carrying extra
invariants.** Reasoning, written down so it can be re-checked rather than remembered:

- **One table, one traversal, one settings owner.** [C8](10-domain-core.md) hangs settings on
  edges; [C11](10-domain-core.md) gives edges identity. Splitting inheritance out means a second
  thing with identity, a second place settings can hang, and a second traversal to keep correct.
  Every query asking *what is connected to this node* would have to union two sources.
- **The special rules survive as invariants, not as a class.** Inheritance needs: at most one
  parent per node, no cycles, not freely deletable. All three are enforceable on a generic edge
  table — the single-parent rule as a unique constraint on (child, type = inheritance), the
  acyclicity by the check any tree needs anyway.
- **The honest counter-argument:** in a generic table nothing *structurally* prevents a second
  parent — it is prevented by a constraint someone has to write and keep. A separate construct
  makes it impossible by shape. That is a real advantage, and it is why this is a question
  rather than an assumption. But the constraint is one line of schema, while the second
  construct is a permanent fork in every traversal. That trade favours one construct.

This does **not** mean inheritance behaves like the others. [C9](10-domain-core.md) already
exempts it from edge settings and [C14](10-domain-core.md) gives it its own resolution walk.
*Same shape, different rules* is the proposal — not *same rules*.

---

## OQ-024 — How are resolved settings computed without melting down?

> **Mostly answered 2026-08-22.** [D-014](90-decision-log.md) takes the batched load and the
> indexed ancestor walk; [D-015](90-decision-log.md) takes sparse overrides with live
> propagation; [D-016](90-decision-log.md) bounds caching to two rules and leaves *where* to
> cache to implementation. What remains open is only the invalidation scheme in detail.

*Blocks:* [10 Domain core](10-domain-core.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *asked by the owner, 2026-08-22*

[C15](10-domain-core.md) resolves attribute settings downwards into the target node and all its
children. The owner asks whether a scheme exists that saves processor time — recompute only when
a child changed, or read once when the attribute is created — and notes that the previous
project hit feasibility limits as trees grew.

**Recommendation: compute on read from the base tables, make the read batched, and put a
droppable cache in front. Do not snapshot.**

1. **The resolved value is derived data, never a second source of truth.** It must be
   reproducible from nodes, settings and relations at any moment, and dropping the entire cache
   must change nothing but speed. The code standard already forbids storing one fact twice.
2. **No resolution inside a loop.** The subgraph and every settings row it touches load in a
   small fixed number of queries, then resolve in memory. Same constraint as
   [OQ-020](#oq-020--loading-the-subgraph-without-an-n1), and the single biggest lever.
3. **Make the ancestor walk indexed.** A materialised path or a closure table turns *give me
   every ancestor of this node* into one indexed query instead of one per level. Worth deciding
   early: it is a schema shape, not an optimisation to add later.
4. **Cache the resolved result keyed by the edge, and invalidate — do not expire.** The cheap
   correct scheme is a generation counter bumped on any structural or settings write; rows from
   an older generation are recomputed on next read. Coarse, always correct, refinable once
   profiling says where the cost actually is.

**Against snapshotting at creation** — the second suggestion — it is fast and wrong by default:
changing a type later would leave existing attributes on the old definition. Unless that is
*wanted*, which is a product question rather than a performance one →
[OQ-027](#oq-027--does-an-attribute-freeze-its-definition-or-track-it).

---

## OQ-025 — How is a deep override addressed and stored?

> **Answered in principle 2026-08-22 → [D-015](90-decision-log.md):** store sparsely, merge
> type defaults then inherited then edge overrides. **But the addressing changed.**
> [D-022](90-decision-log.md) forbids resolving by name and allows duplicate names, so an
> override path made of names is ambiguous. **Override paths are built from edge ids.** Less
> readable, and correct.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [C15](10-domain-core.md)

[C15](10-domain-core.md) says attribute settings must reach into all children of the target
node. Read literally, an attribute would carry a full copy of the configuration of everything
beneath it: storage proportional to the size of the subtree, per attribute, re-copied whenever
the target gains a child.

**Recommendation: store overrides sparsely, addressed by a path relative to the attribute.**
Only what actually differs is stored — `position.quantity.max = 99` as a single row, not the
whole resolved tree. Resolution merges three layers in order: the defaults of the target type,
what is inherited, and the overrides on this edge. Storage then grows with the number of real
overrides, which is small, instead of with the size of the model, which is not.

### What the question actually is — worked out 2026-08-22

The owner asked what this question even means. Concretely:

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
flowchart LR
    B[parts list] -->|#42 positionen| P[Position]
    P -->|#88 menge| I[Integer]
    I -.- S["min · max · step"]
```

`Integer` carries `max` as a base setting. The author now wants: **in this one parts list**,
`menge` may not exceed 99 — here only, not everywhere `Position` is used.

That override row hangs on edge **#42**, but has to name something **two levels deeper**. So:
what is that address made of?

### Answered 2026-08-22 → **a relative path of edge ids**

An earlier draft of this entry wrote the path as `position.quantity.max` — out of **names**.
That contradicts [D-022](90-decision-log.md): names may duplicate and may change, so two
attributes of `Position` both called *Menge* make the path ambiguous. The owner settled it
plainly: always go by the id.

| owner | key | value |
|---|---|---|
| `#42` | `[#88].max` | `99` |

Because an attribute now **is** a relation ([D-031](90-decision-log.md)), every hop already has
an identity — nothing new is invented. Deeper nesting simply lengthens the path: `[#99, #123].max`.

This is the owner's own rule in its purest form: **names for people, ids for the machine.** The
interface shows *Position › Menge › Maximum*; what is stored is `[#88].max`.

### The orphaned override — answered 2026-08-22 → [D-033](90-decision-log.md)

What becomes of an override whose path disappears, because someone deleted the thing it pointed
at? I proposed cascade deletion. **The owner rejected it, and gave the better answer:** the user
decides. Either delete them, or **promote the override into an attribute of its own** at the
level where it was overridden — because if a value was worth overriding, losing its target does
not make the need for it go away.

Either way this needs an index from *referenced edge id* back to the override rows, so that the
affected overrides can be found and shown at all.

---

## OQ-037 — What exactly happens when an override is promoted?

> **Closed 2026-08-22 → [D-156](90-decision-log.md).** Two of the three sub-questions dissolved once
> deletion became two-stage: the parked edge still carries the type and the name. The third —
> *what does promotion do* — depends on how many use the target: **one** means restore on the target,
> **several** means specialise.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [D-033](90-decision-log.md)

Promotion turns `[#88].max = 99` on edge `#42` into a real attribute at that level. Mechanically
that is a new relation plus its settings, built from what the override already held. Nothing new
is required, but several things are unstated:

1. **What type does the promoted attribute point at?** The override knew a setting key, not a
   target node. `max` belonged to an `Integer`, but the override row does not say so — the
   information lived in the path that just disappeared.
2. **What is it called?** [D-022](90-decision-log.md) requires a base name, and nobody has typed
   one.
3. **Is it one operation or several?** Several overrides may be orphaned by one deletion, and
   promoting them individually is tedious — but promoting them together needs them to belong to
   the same new attribute, which cannot be assumed.

The likely shape is that promotion is offered per orphaned override, prefilled from what can be
recovered, with the author naming it. Confirm before assuming.

---

## OQ-038 — Is a chooser a renderer?

> **Closed 2026-08-22 → [D-107](90-decision-log.md): yes.** It renders an identity, takes a context,
> returns markup, and already has its branch and default parameters. Inline versus popup is a
> setting with **inline** as the default; the popup is render-conform because the renderer supplies
> markup and metadata while one generic JS component supplies the behaviour.

*Blocks:* [30 Renderer](30-renderer.md) · *Status:* open · *raised by* [R25–R27](30-renderer.md)

[R1](30-renderer.md) says all display goes through a renderer, and
[R18](30-renderer.md) already made the tree view one. A chooser displays a tree — the same tree,
scoped and expanded differently. So either it *is* a renderer, given a branch node and a default
node as circumstances, or R1 has a second exception.

If it is a renderer, the tree renderer and the chooser are plausibly the **same** renderer with
different options — which would be [D-018](90-decision-log.md) working exactly as intended.

---

## OQ-039 — Where do installation-wide settings live?

> **Closed 2026-08-22 → [D-079](90-decision-log.md).** On a reserved **installation identity**,
> which becomes the first link of the resolution chain. No new mechanism — the installation-wide
> default and the choice in the moment turn out to be the two ends of the walk [D-015](90-decision-log.md)
> already describes.

*Blocks:* [10 Domain core](10-domain-core.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *raised by* [D-032](90-decision-log.md), [R24](30-renderer.md)

[D-032](90-decision-log.md) puts a configured default behaviour in the admin menu — for instance
whether node selection prefers inline or dialog. **That setting belongs to the installation, not
to any node.**

Every settings mechanism decided so far hangs off an `Identity` ([C8](10-domain-core.md),
[D-019](90-decision-log.md)). An installation-wide preference has no identity to hang from.
Candidates:

1. **A WordPress option.** Idiomatic at the boundary, and it keeps the model tables free of rows
   that are not about the model. But then two settings mechanisms exist.
2. **Settings on the model root node.** Reuses everything, and the resolution walk would reach
   every node by inheritance for free. But it conflates *configuration of the tool* with
   *configuration of the model* — which is [OQ-016](#oq-016--is-setting-one-thing-or-two)
   arriving from another direction.
3. **A third store for tool configuration**, distinct from both.

Answer this together with [OQ-016](#oq-016--is-setting-one-thing-or-two): both are the same
question about whether tool behaviour and domain content share a mechanism.

---

## OQ-026 — A part used in only one place: a node, or something smaller?

> **Closed 2026-08-22 → [D-017](90-decision-log.md).** It stays an ordinary node on a composition
> edge, living beneath its whole, with an *add composed child here* action so building one is not
> tedious. Structure is never inlined as a second storage form.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by the owner, 2026-08-22*

The example: a parts list is made of positions. Strictly, a position is its own node, referenced
from the parts list by an attribute with multiplicity `1..*`. But a position is used nowhere
else, so giving it the full weight of a catalogue node feels wrong — it clutters, and it makes
building such a structure tedious.

**Recommendation: keep one structural mechanism. Treat this as ownership and visibility, not as
storage.**

- The position stays an ordinary node, reached by a **composition** edge, so
  [C12](10-domain-core.md) already deletes it together with its whole.
- It lives **beneath** the parts list rather than in the shared catalogue, so it never clutters
  a chooser. *Used in exactly one place* is a fact about where it sits, not a reason for a
  second kind of thing.
- Creation gets an action that makes the composed child in place, so the user never experiences
  having created a separate global object. The tedium is a UI problem and belongs there.

**Against inlining the data into the attribute** — the other idea — it introduces a second form
in which structure can exist. Every renderer, validator, query and migration would then need two
code paths, and one fact stored two ways is what the previous round did not survive.

**What can safely be done later:** if profiling shows a row per position is too expensive, the
repository may serialise a composed leaf subtree into a column. That is a persistence decision
behind the repository boundary, and the domain model must not be able to tell the difference.

---

## OQ-027 — Does an attribute freeze its definition, or track it?

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised while answering* [OQ-024](#oq-024--how-are-resolved-settings-computed-without-melting-down)

If an integer type is later changed from `max = 100` to `max = 1000`, what happens to attributes
created against the old definition?

- **Track it.** They follow the type. Consistent, and what inheritance normally means.
- **Freeze it.** They keep what was true when they were created, the way an order keeps the
  price it was placed at. [C11](10-domain-core.md) putting a **version** on `Identity` suggests
  someone was already thinking along these lines.

A product question, not a performance one — and the answer changes the storage shape. Both may
turn out to be needed, per attribute, which would make it a setting.

> **Closed 2026-08-22 → [D-015](90-decision-log.md): track, do not freeze.**

### What this question was, concretely

*Re-written 2026-08-22 — the original wording was too abstract to act on.*

1. An `Integer` type is defined with `max = 100`.
2. A model uses it; users enter data against it.
3. Later the type is changed to `max = 1000`.

**Does the attribute defined in step 2 now have `max = 1000`, or does it keep `100`?**

- **Track** — it follows the type. Consistent, and what inheritance normally means.
- **Freeze** — it keeps what was true when it was created, the way an order keeps the price it
  was placed at.

**Answered: track.** That is [D-015](90-decision-log.md), and the owner gave the decisive reason:
five nodes using `int` should all follow a changed `step`, except the one that set its own. Only
what actually differs is stored, so a base change reaches everything that did not override it.

**Not covered by that answer, and still open elsewhere:** what happens to **data already
entered** when the model changes → [D-037](90-decision-log.md) says whether that is a break
depends on the data, and the mechanism is
[OQ-031](#oq-031--how-does-existing-data-survive-a-model-change). And the same shape for labels
is [OQ-049](#oq-049--can-a-label-be-frozen-at-the-moment-of-use).

---

## OQ-028 — Is the set of label roles fixed, or extensible?

> **Closed 2026-08-22 → [D-151](90-decision-log.md), with a question mark.** Roles are nodes, a seeded
> base set, extensible. The **numerus** part is [D-153](90-decision-log.md) and is **`provisional`** —
> the owner is not convinced it earns a column, and the trigger for revisiting is written down.
> `long` is mandatory as the fallback anchor and doubles as the tooltip.

*Blocks:* [40 I18n](40-i18n.md) · *Status:* open

[I4](40-i18n.md) names four roles — long, form, table, symbol — plus a locale-neutral icon
([I5](40-i18n.md)). Is that list closed, or may a model author add a role?

Fixed is simpler and lets renderers rely on a role existing. Extensible matches
[V7](00-vision-and-scope.md), where special things are created in the configuration rather than
compiled in, and it is the same *data or code* question as
[OQ-003](#oq-003--is-relationtype-a-node-or-an-enum). Answering those two the same way would be
worth something on its own.

---

## OQ-029 — Are the length hints advisory or enforced?

> **Closed 2026-08-22 → [D-152](90-decision-log.md).** A length hint is a **setting on the role node**,
> advisory by default — a limit set from German bites in Finnish — and enforceable where a real
> constraint exists.

*Blocks:* [40 I18n](40-i18n.md) · *Status:* open

[`I18nMeremaid.md`](I18nMeremaid.md) annotates each text role with a length: long `10`, short
`5`, table `10`, form label `15`, symbol `3`. Whether those are guidance for whoever writes the
label, hard limits a validator rejects, or hints a renderer uses to truncate, is not stated —
and translations routinely run longer than the original, so a hard limit set from German will
bite in Finnish.

---

## OQ-030 — May a model author write their own validator message?

> **Closed 2026-08-22 → [D-158](90-decision-log.md).** Yes — as a **label**, so it joins the existing
> mechanism rather than adding a second one, and **per validator**, which needed a `path` column on
> `labels`. The offered correction stays code.

*Blocks:* [40 I18n](40-i18n.md), [30 Renderer](30-renderer.md) · *Status:* open

A shipped validator message is a software string with placeholders filled from the node
settings. If a model author may replace it with their own wording, that message becomes content
and needs a locale like any other label. Convenient, and it means validator text then exists in
two mechanisms at once.

---

## OQ-031 — How does existing data survive a model change?

> **Mechanism found 2026-08-22 → [D-054](90-decision-log.md): the conflict resolver.**
> [70 Migration](70-migration.md) now holds the design — it lists which models conflict with their
> data, the user resolves, and the check loops until nothing is left. What remains open here is
> narrower: [OQ-051](#oq-051--does-staged-resolution-need-the-intermediate-model-versions) whether
> intermediate model versions must be kept, and
> [OQ-052](#oq-052--what-can-the-resolver-offer-beyond-showing-a-conflict) what moves the resolver
> can offer.

*Blocks:* [10 Domain core](10-domain-core.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *raised by the owner, 2026-08-22*

Stated by the owner as the real reason a version exists at all
([C16–C18](10-domain-core.md)):

- **Best case** — the change is additive, say a field was added. Existing data carries into the
  new model untouched.
- **Worst case** — the change is a break. It becomes a new **model version**, and a **mapping**
  from old to new is required.
- **The constraint** — the user must not have to re-enter data. The discrepancy has to be
  resolvable with suitable means.

Nothing about the mechanism is decided. What has to be settled, at least:

1. What counts as additive, decided by a rule rather than case by case.
2. Whether model versions coexist — old data still readable under version *n* while new data is
   written under *n+1* — or whether a migration is a one-way event.
3. What the mapping is: data the user edits in a UI, or code.
4. Whether this deserves its own concept document rather than a section.

**This cannot be answered before [OQ-015](#oq-015--where-does-the-content-live)** — where the
content lives at all. It is recorded now because it is the reason `version` sits on
[`Identity`](10-domain-core.md), and that changes how `version` should be read: not an audit
marker, but the anchor for data migration.

The owner notes the previous round covered some of this — a harvest candidate, not an
inheritance.

---

## OQ-032 — Is the base name required, and unique anywhere?

*Blocks:* [40 I18n](40-i18n.md), [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [D-020](90-decision-log.md)

[D-020](90-decision-log.md) gives every node a locale-neutral base name that is always present.
Two things follow that were not stated:

1. **Is it mandatory at creation?** If yes, no node can exist without one and the fallback chain
   can never fail. If no, the chain needs one more step and the guarantee is gone.
2. **Is it unique?** Not for identity — [I2](40-i18n.md) settles that. But two sibling nodes
   both called *Value* are confusing in a chooser, and uniqueness among siblings is cheap to
   enforce while global uniqueness is not.

Note this also adds a row to [OQ-017](#oq-017--which-attributes-does-every-node-have): the base
name is now a confirmed member of the fixed set, so it becomes a column.

> **Answered 2026-08-22 → [D-022](90-decision-log.md).** Required: **yes**. Unique: **no** — and
> the sibling-uniqueness idea above is rejected. Duplicate names are a normal modelling outcome,
> not a mistake to prevent: two attributes may share a name, and two different nodes may each
> have a child of the same name. Nothing resolves on a name; references use the id, which is
> stable while the name may change.

---

## OQ-033 — Where does preview test data live?

> **Closed 2026-08-22 → [D-028](90-decision-log.md).** It is content with a flag: rows marked as
> test data. Not a third kind of thing after all — the preview renders the node in the data view
> over those rows and falls back to the defaults.

*Blocks:* [30 Renderer](30-renderer.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *raised by* [R22](30-renderer.md)

[R21–R23](30-renderer.md) give every node a preview driven by sample data, so that switching a
renderer or changing a setting shows its effect immediately.

That sample data is **the first thing in this concept that is neither model nor content.** It is
not part of what the author is modelling, and it is not data an end user entered. Candidates:

1. **Shipped with the plugin, per node type.** A file of sensible samples for the built-in
   types. Nothing to store, nothing to migrate — but a user-defined type gets no preview.
2. **Stored in the model, per node.** The author supplies a sample. Uniform, previews always
   work, at the cost of a fourth kind of thing in the tables.
3. **Generated from the settings.** `min`, `max`, `step` and the type already describe what a
   valid value looks like, so a plausible sample can be derived. No storage at all, and it
   updates itself when the settings change — which is exactly what the preview is demonstrating.

Option 3 is worth examining first precisely because it needs nothing, and it degrades to option
1 for types where generation is not obvious.

---

## OQ-034 — Is the preview a renderer, or a caller of one?

> **Closed 2026-08-22 → [D-096](90-decision-log.md): a caller.** The preview simply invokes render
> twice, once editable and once not — no mode the contract has to know about, and no exception to R1.

*Blocks:* [30 Renderer](30-renderer.md) · *Status:* open · *raised by* [R20 vs R21](30-renderer.md)

[R21](30-renderer.md) says the preview is *assembled from* the chosen renderer, which reads as a
caller. [R20](30-renderer.md) says the settings page around it is itself a page renderer. So the
preview sits between two renderers and its own status is unstated.

It matters because of [R1](30-renderer.md): if the preview is not a renderer, something other
than a renderer is producing display, and R1 has an exception. If it *is* a renderer, it is one
whose input is a node **plus a chosen renderer plus sample data** — a different signature from
every other renderer, which bears on [OQ-006](#oq-006--renderer-contract-what-is-the-actual-method-set).

> **Simplified 2026-08-22 by [D-026](90-decision-log.md).** If the sample data is the node's
> **default instance**, the preview is an ordinary render of a node in the data view. Its
> signature stops being special, and this question reduces to: does the preview call the
> registry like everyone else? Almost certainly yes.

---

## OQ-035 — Can a relation reach something that is not a model node?

> **Narrowed 2026-08-22 → [D-030](90-decision-log.md).** A default is a **setting whose value is
> an identity reference**, and several defaults are several such settings. `from` and `to` keep
> pointing at nodes. What remains: whether that reference may name an instance, which depends on
> [OQ-036](#oq-036--do-instances-share-the-identity-space).

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [C26](10-domain-core.md)

Today `Relation.from` and `Relation.to` both name a `Node`. Two statements push against that:

1. **[C26](10-domain-core.md)** — a default may be a whole record, i.e. something in the **data
   layer**. A model-level object then has to point into the data layer.
2. **Composed defaults.** A default that is itself structured is not a scalar setting value.

Options, none evaluated yet:

- **The default is a setting whose value is an identity reference.** No change to `Relation`.
  Works if instances have ids in the same space ([OQ-036](#oq-036--do-instances-share-the-identity-space)).
- **`from` / `to` widen from `Node` to `Identity`.** More uniform, and it would also allow an edge
  to originate from an edge — which nothing currently needs, so it buys a capability at the price
  of every traversal having to check what it landed on.
- **Defaults get their own reference field on the relation.** Explicit, and one more column that
  is empty on most rows.

The first is the smallest and should be tried first.

---

## OQ-036 — Do instances share the identity space?

> **Closed 2026-08-22 → [D-164](90-decision-log.md).** No. Model and data get separate number spaces;
> nodes and relations keep sharing one, because there the ambiguity is real. The argument for one
> shared space turned out to rest on a mis-reading of [D-131](90-decision-log.md).

*Blocks:* [10 Domain core](10-domain-core.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *raised by* [D-026](90-decision-log.md)

[C11](10-domain-core.md) gave nodes and relations one id space, which is what makes `owner` a
single real foreign key. [D-026](90-decision-log.md) adds a second layer. Does an instance draw
from the same space?

**For:** a default can then be referenced by plain id ([OQ-035](#oq-035--can-a-relation-reach-something-that-is-not-a-model-node)),
and settings, labels and changelog items could hang off an instance with no new mechanism.

**Against:** the two layers scale very differently. A model has hundreds of nodes; instance data
runs to millions of rows. Sharing a space is not the same as sharing a table, but it invites it —
and a model query filtered against a table dominated by instance rows is exactly the wall the
previous project hit.

**Probable shape:** one id space, separate tables. That keeps references simple and keeps the
model tables small. Confirm before it is assumed, and settle it together with
[OQ-015](#oq-015--where-does-the-content-live), which it is half of.

---

## OQ-040 — Is a currency a branch of units, or a separate concept?

> **Closed 2026-08-22 → [D-039](90-decision-log.md):** ein Einheitswert, zwei Zweige derselben Form.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [C40](10-domain-core.md)

[C40](10-domain-core.md) names a two-part split: base units and currencies.

**Evidence from the standard tree** ([harvest 01](_harvest/01-standard-tree.md), A2/B6): the old
project built `Unit type` as *Menge + Base unit + Praefix* and `Preis` as *Wert + Waehrung* —
**the same shape twice**. It then placed `Waehrung` beside `Base units` rather than beneath a
common root.

So the structure is shared and only the behaviour differs, in one specific way worth naming: a
unit conversion factor is a **constant**, an exchange rate is a **time series**. Under
[D-036](90-decision-log.md) that is exactly what a registered strategy is for — same structure,
different converter.

Open: whether they nonetheless share a root node, so that *carries a unit* can be asked as one
question.

---

## OQ-041 — Is a prefix a node or an enum?

> **Closed 2026-08-22 → [D-116](90-decision-log.md): a node.** The engine multiplies by the factor and
> never branches on which prefix it is. And the owner supplied the stronger argument from
> experience: the previous enum type had to be dropped once fixed values turned out to carry further
> properties.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open

The standard tree has `Konstanten › Präfixe` with pico, nano, Micro, Milli, Centi, Kilo, Mega —
**nodes**.

Applying the criterion from [OQ-003](#oq-003--is-relationtype-a-node-or-an-enum): does the engine
branch on a prefix, or only use its value? **Only the factor is used** — nothing in the code has
to know that *kilo* specifically exists. By that criterion a prefix is **data**, and the set may
be extended by the author. This is the opposite answer to relation kinds, from the same rule.

Note the tree also encodes *whether a family takes a prefix at all* by inheritance
(`With prefix` / `Without prefix`), which is a separate and good idea → harvest 01, A3.

---

## OQ-042 — Does an attribute's type name one node, or a branch?

> **Closed 2026-08-22 → [D-041](90-decision-log.md):** der Typ ist ein Ast, polymorph.

*Blocks:* [10 Domain core](10-domain-core.md), [30 Renderer](30-renderer.md) · *Status:* open · **large**

[D-025](90-decision-log.md) says the type of an attribute is the node the relation points at. The
unit example strains that reading.

`Gewicht.einheit` must accept **some** mass unit, picked per instance — Gramm, Kilogramm,
Tonne. So the model-level `to` is not the value; it is the **root of the allowed set**, and the
instance holds a node from beneath it.

If that is right, two things follow, and both are attractive:

1. **The type system and the chooser are the same mechanism.** [R25](30-renderer.md) already
   gives a chooser a *branch node* and a *default node*. An attribute would give exactly the
   same two things — `to` is the branch, the default is the default.
2. **`Konstanten › Bauformen` and friends stop being a special case.** A choice list is simply an
   attribute whose branch happens to be shallow.

Open: whether `to` always means *branch*, or whether some attributes name an exact node — and
whether the allowed set is the branch's whole subtree or only its direct children
([R25](30-renderer.md) expands to *the default node and its children*).

---

## OQ-043 — Is the unit tree shipped, or authored?

> **Closed 2026-08-22 → [D-119](90-decision-log.md): shipped as a seed, then authored.** The scaffold is
> imported once and afterwards belongs to the author; updates offer new items and never overwrite.

*Blocks:* [10 Domain core](10-domain-core.md), [95 Roadmap](95-roadmap.md) · *Status:* open

Metres, grams and euros are the same everywhere. Shipping them saves every user the work and
gives renderers something to rely on. Letting the author build them keeps the engine free of
domain knowledge and matches [V7](00-vision-and-scope.md).

Probable answer: **ship them as a starting set the author may edit**, which is neither and works
for both — but it needs saying, because *editable shipped data* raises its own question of what
happens on plugin update.

---

## OQ-044 — How are calculations modelled?

> **Closed 2026-08-22 → [D-043](90-decision-log.md):** Eigenschaft eines Attributs, keine Kantenart.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · **gap, not legacy debris**

[V6](00-vision-and-scope.md) named *special nodes for data types and for calculations* on the
first day. Data types have been worked out since; **calculations have not been touched.**

The standard tree shows the previous project had gone further: a relation kind `calc`, and a
whole `Definition › Aggregate` branch described as *aggregate operations, with the operation
chosen on each field slot while the type stays the column value type*
([harvest 01](_harvest/01-standard-tree.md), B7).

This is not optional. A parts list that cannot total its positions is not a parts list. What has
to be settled at minimum:

1. Is a calculation a **node**, an **edge**, or a **setting** on an attribute?
2. What may it read — only siblings, the whole subtree, across aggregations?
3. When does it run: on read, on write, or cached like resolved settings
   ([D-016](90-decision-log.md))?
4. How do cycles get prevented, given the graph already needs a guard for rendering
   ([OQ-019](#oq-019--cycles-and-depth-in-the-render-descent))?

Also connected: [C19](10-domain-core.md) said hidden attributes exist precisely so they can feed
calculations — so the two were always meant to work together.

---

## OQ-045 — What can a calculation expression reach?

> **Closed 2026-08-22 → [D-045](90-decision-log.md):** relativer Pfad aus Kanten-IDs.

*Blocks:* [60 Calculation](60-calculation.md) · *Status:* open

Siblings only, descendants across composition, across aggregation to a shared node, upward to an
ancestor? Each step outward makes the invalidation graph larger and the cycle risk higher. The
parts-list case ([K3](60-calculation.md)) needs at least *own siblings* and *aggregate over a
composed collection*; nothing has asked for more yet.

---

## OQ-046 — When does a model calculation run?

> **Answered 2026-08-22 → [D-072](90-decision-log.md): materialised, written on input change.**
> Forced by the search requirement ([D-070](90-decision-log.md)) — a value derived on read cannot
> be filtered on without computing it for every candidate.

*Blocks:* [60 Calculation](60-calculation.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open

On write, on read, or cached with invalidation. The same three options as
[OQ-024](#oq-024--how-are-resolved-settings-computed-without-melting-down), and the answer should
probably match it — one caching story for the whole system rather than two.

---

## OQ-047 — What is the expression language, and who writes it?

> **Closed 2026-08-22 → [D-130](90-decision-log.md): a structured tree, picked not typed.** Arithmetic
> and aggregates from a small closed set; operands are edge ids; a typed formula field may later be
> a second way to author the same structure. Hard cases become registered strategies.

*Blocks:* [60 Calculation](60-calculation.md) · *Status:* open

A picked operation over a picked field (which is what the old `Definition › Aggregate` branch
did — *op chosen per field slot*) is safe, limited and needs no parser. A free expression is
powerful and immediately raises evaluation, safety and validation questions.

The owner is the audience: this is configured in the modeller, not written in code. That argues
strongly for the picked-operation form first, with a free expression only if a real case demands
one.

---

## OQ-048 — How does the tool know where data may be entered?

> **Closed 2026-08-22 → [D-131](90-decision-log.md), [D-132](90-decision-log.md).** The question was mis-framed:
> the bindings do not answer *where data may be entered* — they answer whether a **value reference**
> resolves to a **node** or to a **record**. What is standalone is decided by the edges
> (aggregation versus composition only), and only for nodes whose instances are records at all.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [C55](10-domain-core.md)

[D-042](90-decision-log.md) says type nodes and model nodes are the same construct in different
roles. Nothing structural separates them — so how does the interface know where to offer *enter
data*?

The standard tree answers by **placement**: `Definition`, `Model` and `Implementation` are
branches, not node kinds ([harvest 01](_harvest/01-standard-tree.md)). That is convention, and it
works — but it is unstated convention, which is how the previous round accumulated rules nobody
could find.

Options: leave it as placement and write the convention down; add a marker setting on the node;
or derive it — a node nobody uses as a type is a model. The third is elegant and fragile, since
it changes meaning as soon as someone reuses a node.

---

## OQ-049 — Can a label be frozen at the moment of use?

> **Closed 2026-08-22 → [D-053](90-decision-log.md): keep it current, never freeze.** The owner
> settled it with a sharper distinction than this question was built on — **rename is not
> replace.** A rename touches a label, the reference is unchanged, so the data are unchanged and
> only the wording differs. Replacing a node touches a reference, which is a model change and a
> conflict for the resolver ([70 Migration](70-migration.md)). And the document case that argued
> for freezing dissolves: an exported PDF is **detached** the moment it is produced, so
> regenerating it later giving different wording is expected rather than wrong.

*Blocks:* [40 I18n](40-i18n.md) · *Status:* open · *raised by* [D-049](90-decision-log.md) · *re-written 2026-08-22, the first version was too abstract*

### The concrete case

1. The unit node `Stück` exists. Its `symbol` label is `St`.
2. A user records a line: **5 St**.
3. Months later somebody renames the label to `Stk`.
4. **Every record ever written now displays `5 Stk`** — including the one from step 2.

For a model that is correct: a rename is a correction and should reach everywhere. For a
**document** it is wrong. An invoice, an order, a delivery note is a statement made on a day, and
it should still read the way it read on that day.

### Why it is open

Nothing in the concept yet distinguishes *a record* from *a document*. Everything in the data
layer tracks its definitions, because that is what [D-015](90-decision-log.md) decided and it is
right for almost everything.

Options:

1. **Never freeze.** Simplest, and wrong for anything printed or sent.
2. **Freeze per attribute**, as a setting: *this label is captured when the record is written.*
   Fits the existing mechanism, but the author has to foresee which fields matter.
3. **Freeze when a record is closed.** Right in principle, and it needs a notion of *closed* or
   *issued* that this concept does not have and may not want.

Probably out of scope until documents exist as a concept. Recorded so it is not discovered late.

### The family this belongs to

Three questions of one shape have come up, and it is worth seeing them together — *when a
definition changes, do existing things follow it, or keep what was true?*

| | Answer | |
|---|---|---|
| **Settings** | **track** | [D-015](90-decision-log.md) |
| **Data, when the model changes** | **depends on the data** — breaking or not | [D-037](90-decision-log.md) |
| **Labels** | undecided | this question |

---

## OQ-050 — What does a tool-independent export look like?

> **Closed 2026-08-22 → [D-058](90-decision-log.md), [D-059](90-decision-log.md).** Two exports:
> a **backup** carrying tree and data together, round-tripping, with **id and plain text** side by
> side; and **view exports** (CSV, PDF, interactive list) which are renderers and need not
> round-trip. Import conflicts go to the conflict resolver.

*Blocks:* [70 Migration](70-migration.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *raised by* [M5](70-migration.md)

The owner accepted the readability cost of id references and noted the model structure had already
broken database-level readability anyway. That is true, and it has a consequence worth stating:
**the tool becomes critical infrastructure.** Deactivate the plugin and the data are inaccessible;
a table backup is not a backup anyone can use; recovery needs the tool working first.

So an export that can be read **without** the tool is a requirement, not a convenience. Open:

1. **What form** — one file per model with names resolved, or a full dump that can be reimported?
   Those are different artefacts: one is for humans, one is for recovery.
2. **When is it written** — on demand, on every model change, on a schedule?
3. **Does it round-trip?** A readable export that cannot be imported protects against loss of
   *access* but not against loss of *data*.

Cheap now, expensive to retrofit — the shape of the tables is still open.

Separately and much cheaper: a **read-only resolved view** that joins ids to base names, for
support and debugging. One join, and it turns unreadable rows into readable ones.

---

## OQ-051 — Does staged resolution need the intermediate model versions?

> **Closed 2026-08-22 → [D-060](90-decision-log.md), [D-061](90-decision-log.md).** Neither
> reading, and better than both: **the version is carried by the record.** Records of different
> versions coexist until resolved. And what resolution needs is the **changes**, not the
> snapshots — which the model changelog already is.

*Blocks:* [70 Migration](70-migration.md) · *Status:* open · *raised by* [M8](70-migration.md)

[M8](70-migration.md) resolves a repeatedly changed model **stage by stage**. Two readings, and
they need different storage:

1. **Conflicts accumulate; the model has one current version.** The resolver works against the
   current model and simply has several problems to fix. Nothing historical is kept.
2. **Each version is retained**, and the data are carried v1 → v2 → v3. Necessary if a step only
   makes sense in the presence of the intermediate shape — a field split in v2 and renamed in v3
   cannot be understood from v3 alone.

Reading 1 is far cheaper and probably sufficient for additive change. Reading 2 is what actually
survives a restructuring. Which one is needed depends on how large a change may be between
versions — which nothing has bounded yet.

---

## OQ-052 — What can the resolver offer beyond showing a conflict?

> **Closed 2026-08-22 → [D-062](90-decision-log.md).** Map, map with transformation, bulk fill,
> fill by hand, delete. A transformation is a **converter applied to a column**, so no separate
> transformation language is needed.

*Blocks:* [70 Migration](70-migration.md) · *Status:* open

[V9](00-vision-and-scope.md) already established the pattern that a **validator may offer a
correction**, not merely report a fault. The resolver is the same idea at model scale, so it
should offer moves rather than only listing problems.

Candidates, from the cases named so far: fill a newly mandatory field with a value the author
states once for all old records ([D-037](90-decision-log.md)); map an old value to a new one when
a choice list changed; drop the data for a removed attribute; or **promote** it, the way an
orphaned override is promoted ([D-033](90-decision-log.md)) — the same shape one layer down.

---

## OQ-053 — What happens to a model that cannot be satisfied?

> **Closed 2026-08-22 → [D-157](90-decision-log.md).** Caught where the narrowing happens, reported as
> a model conflict rather than blocked — but data entry against the model stays barred until it is
> resolved.

*Blocks:* [30 Renderer](30-renderer.md), [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [R29–R31](30-renderer.md)

An attribute with multiplicity `1` or `1..*` whose permitted set is **empty** demands an answer
that cannot be given. The natural way to arrive there is an allow-list
([D-046](90-decision-log.md)) narrowed to nothing, or a branch whose only member was deleted.

That is not a control state — it is a broken model, and [D-056](90-decision-log.md) deliberately
does not give it one. Open: is it prevented when the restriction is set, reported as a model
error afterwards, or only noticed when someone tries to enter data? The first is kindest and
needs the check to run at configuration time, where [D-050](90-decision-log.md) is already asking
*does this have consequences yet*.

---

## OQ-054 — Is a currency amount stored as entered, or normalised?

> **Closed 2026-08-22 → [D-064](90-decision-log.md): stored as entered.** The owner confirms euro
> normalisation was not meant — dollars are stored as dollars. Where a price must stay put, the
> **rate of that day is frozen and stored beside the amount**, and the converted figure is derived
> from the two.

*Blocks:* [10 Domain core](10-domain-core.md), [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *needs a yes or no*

[D-051](90-decision-log.md) says a **prefix normalises, a unit does not** — so an amount stays in
the currency it was entered in. A phrase in the owner statement of 2026-08-22 can be read as
*always store in euro with all decimal places*, which is the opposite.

**Recorded as: store in the currency entered.** The reason for not normalising:

- Normalising needs an **exchange rate at the moment of storage**, which freezes that rate into
  the data.
- Two records entered a week apart then hold euro figures produced by different rates, and are no
  longer comparable as what they were.
- The amount actually agreed can no longer be recovered.

If euro normalisation *was* meant, this reverses — and then the rate used must be stored beside
the amount, or the number means nothing later.

---

## OQ-055 — Where does an exchange rate come from?

> **Closed 2026-08-22 → [D-069](90-decision-log.md): a rate table, filled at the boundary.**
> Option 2 and 3 combined, as the owner sketched: fetch once a day for the known currencies, store
> under that date, and the core reads only the table. Daily granularity — intraday is not needed.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [D-064](90-decision-log.md)

[D-064](90-decision-log.md) freezes a rate into a record. It does not say where that number comes
from, and the answer decides whether this concept acquires a dependency on the outside world.

1. **Typed in.** The author states the rate when entering the amount. No dependency, no
   infrastructure, and tedious for anyone entering many.
2. **A rate table in the model.** Rates are ordinary nodes with a date — data authored at
   modelling time ([D-048](90-decision-log.md)) or maintained as content. Fits everything already
   decided and needs no external call.
3. **Fetched from a service.** Convenient and immediately drags in network access, failure
   handling, caching and a third-party dependency at the boundary.

Option 2 is the one that costs nothing and stays inside the model. Option 3 can be added later at
the boundary without disturbing anything, precisely because the rate is *stored* on the record —
whoever supplies the number, the record keeps it.

---

## OQ-056 — How many conditions can one query carry?

> **Closed 2026-08-23 → [D-165](90-decision-log.md).** Any number correctly, about three quickly, and a
> flat per-model projection for the reporting case — a cache, never a second place where values live.

*Blocks:* [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *raised by* [D-070](90-decision-log.md)

A single condition is an indexed range scan on `(edge_id, value)` and is fast. Combining
conditions — over a thousand euro **and** containing part X — means intersecting two such lookups.
Two or three are fine; ten becomes a chain of joins that no index makes free.

This is the price of the shape in [P11](50-wordpress-persistence.md), and every
entity-attribute-value design pays it. What is open is only how far it has to stretch:

1. **How many conditions does a real query have?** If the honest answer is two or three, nothing
   more is needed.
2. **Is there a reporting case** that wants more — and if so, is it served by a **materialised
   view** per model rather than by making the generic query cleverer?
3. **Full-text** across a model is a separate mechanism again, not a harder version of this one.

Worth answering with real queries rather than in the abstract, once a model exists to query.

### Proposal on the table, not decided

Put to the owner on 2026-08-22, **not yet answered** — the session ended on it:

1. **The generic query accepts any number of conditions and always answers correctly.** No hard
   limit in the code: a limit has to be explained in an error message, and it always sits in the
   wrong place.
2. **Speed is promised only up to about three.** Beyond that it is allowed but not guaranteed —
   an honest statement about an attribute-value store, not a weakness to optimise away.
3. **The reporting case gets a stage, not a cleverer query:** a **flat projection per model**, one
   column per attribute, filled from the records and rebuildable from them at any time. Ten joins
   become an ordinary `WHERE`, and the normal case pays nothing for it. It is a **cache, never a
   place where anything is stored** — the same standing as a materialised computed value
   ([D-072](90-decision-log.md)).

---

## OQ-057 — Is undo in scope?

> **Closed 2026-08-23 → [D-172](90-decision-log.md).** Yes, and it is a step forward rather than a
> rewind. Its reach is the trash: takeable back until the trash is emptied.

*Blocks:* nothing yet · *Status:* deferred by [D-081](90-decision-log.md)

The seed sketch gave `ChangeLogItem` an `undo()`. The changelog now exists for a stronger reason —
it is the migration script ([D-061](90-decision-log.md)) — and it would **enable** undo, but
nothing in the concept requires it.

Left deferred deliberately rather than designed. If it is wanted later, the questions are: what is
the unit of undo (one field, one edit, one session), does undoing a model change also undo the data
migration it caused, and what happens when the thing being undone has since been built upon.

---

## OQ-058 — How does a subtype narrow an inherited attribute?

> **Closed 2026-08-22 → [D-087](90-decision-log.md): option 3, and wider than asked.** The owner
> confirmed the override belongs on the **node** — and added that a child may also **hide**
> inherited attributes, not only narrow them. One override shape, two possible owners, same
> addressing, same walk. C9 is untouched.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *found while writing* [D-086](90-decision-log.md)

`Part` has the attribute `lieferant` as edge `#10`, multiplicity `0..1`. `Passiv` inherits from
`Part` and should be able to **narrow** it to `1` — every passive component must name a
supplier.

But the mechanism for narrowing that has been decided is an **override at the use site**
([D-015](90-decision-log.md)), and here there is no use site: `Passiv` is not *using* `Part`, it
**is** a `Part`. The override would have to sit on the inheritance edge — and
[C9](10-domain-core.md) exempts inheritance edges from carrying settings.

So a case exists that neither rule covers. Three ways out:

1. **Let the inheritance edge carry settings after all.** Simple, and it reverses C9 — which was
   stated for a reason (inheritance is not a use site, and there is only ever one).
2. **The subtype declares its own edge, replacing the inherited one.** No new mechanism, but two
   edges now mean the same attribute and every reader has to know which wins.
3. **An override owned by the *node*, addressing the inherited edge by id** —
   `owner = Passiv, key = [#10].multiplicity, value = 1`. Uses the path addressing that already
   exists ([D-045](90-decision-log.md)), needs no change to C9, and keeps one edge per attribute.

**Option 3 looks right**: it is the same override shape, only anchored on a node instead of an
edge, and the resolution walk already passes through both. Confirm before assuming — this is the
first case where an override owner is a node rather than a use site.

---

## OQ-059 — May an override widen, or only narrow?

> **Closed 2026-08-22 → [D-088](90-decision-log.md): both directions, no restriction.** The worry
> was misplaced. Multiplicity applies only to edges — it is a statement about a **use**, not about a
> thing — and every constraint is evaluated where it resolves, so there is no global guarantee that
> loosening could break. The *constraint versus presentation* marking proposed below is therefore
> **dropped**, not answered.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [D-087](90-decision-log.md)

`Passiv` narrows `lieferant` to exactly `1` — every passive component must name a supplier. A use
site of `Passiv` then sets it back to `0..1`. **That breaks the guarantee the subtype just made**,
and the walk as decided would let it, because the use site comes last and last wins.

The likely answer: **constraint-like keys may only be narrowed; presentation-like keys may be set
freely.**

| | Widening breaks something | Example |
|---|---|---|
| **constraint** | yes | `multiplicity`, `min`, `max` — validation guarantees depend on them |
| **presentation** | no | `hide`, `renderer`, `order`, labels — nothing downstream relies on them |

**This is not a second class of setting**, and it must not become one — [D-084](90-decision-log.md)
already rejected splitting the table. It is part of what the key's **owner** declares about it
([D-085](90-decision-log.md)): the type that defines `min` also defines whether a descendant may
lower it. One more property in the key's definition, not a new partition.

Open beyond that: what happens when someone tries — refused at write time with a message, or
accepted and reported as a model conflict the way a breaking model change is
([D-054](90-decision-log.md))?

---

## OQ-060 — Optimistic or pessimistic locking?

> **Closed 2026-08-22 → [D-089](90-decision-log.md).** Optimistic on both layers, plus an advisory
> heartbeat warning on the model side. Parallel work is not forbidden. Note the owner also
> corrected the origin of this question: the statement was *changes are **logged***, not *locked* —
> locking turned out to be a real question regardless.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [C85](10-domain-core.md)

The owner named *changes are locked* as one of the two purposes of the base class. Two different
things go by that name:

| | How | Cost |
|---|---|---|
| **Optimistic** | read `version`, refuse the save if it moved meanwhile | a rejected save now and then; nothing to clean up |
| **Pessimistic** | claim the node while editing; others are blocked | lock lifetime, expiry, and stale locks left by someone who closed their laptop |

**Optimistic is proposed**, because `version` already supports it and it introduces nothing that
can go wrong while nobody is looking. Pessimistic can be added later as `locked_by` / `locked_at`
on the identity without disturbing the model — so choosing optimistic now closes no door.

### What WordPress actually provides

The owner asked whether WordPress has a mechanism. **It does, for posts, and the useful half of it
is reusable.**

- **`wp_set_post_lock()` / `wp_check_post_lock()`** write a `_edit_lock` post meta of the form
  `timestamp:user_id`. When a second user opens the same post the editor says *X is currently
  editing* and offers **take over**. The lock is considered stale after about 150 seconds
  (filterable), so nothing gets stuck permanently.
- It is refreshed by the **Heartbeat API**, which polls every 15–60 seconds from any admin screen
  and can carry arbitrary data through the `heartbeat_received` filter.

So WordPress's own answer is a **pessimistic lease with an expiry and a take-over** — not a hard
lock. Since this plugin does not use posts ([D-007](90-decision-log.md)) the post-lock functions
themselves are not usable, but **the Heartbeat API is**, and it is the part that is awkward to
build oneself.

There is **no general optimistic-locking support** in WordPress; revisions exist for posts only.
A `version` column and a comparison on save is entirely our own, and entirely ordinary.

### The question splits by layer, and should be answered twice

| | Who is editing | Realistic collisions |
|---|---|---|
| **model** | a few people, occasionally, sometimes the same node | plausible |
| **data** | many people, constantly, almost always different records | rare |

**Forbidding parallel work** — the owner's third option — is the simplest of all, and it is
defensible **for the model**: one modeller at a time is a real constraint in a small team, and it
removes the whole problem. It is **not** defensible for the data layer, where concurrent entry is
the normal case and blocking it would make the product unusable.

### Recommendation

1. **Data layer: optimistic, always.** Compare `version` on save, refuse and show what changed.
2. **Model layer: optimistic as the mechanism, plus a heartbeat lease as a *courtesy warning*** —
   *Stefan is currently editing this node* — which is **advisory, not enforcement**. The warning
   comes before the work is done, and nothing jams if a laptop is closed.
3. **Do not forbid parallel work**, but note that with a single modeller none of this has teeth —
   [D-050](90-decision-log.md) applies, and the courtesy warning can wait until there is a second
   person.

---

## OQ-061 — Does the descent walk the model, the record, or both?

> **Closed 2026-08-22 → [D-159](90-decision-log.md), [D-160](90-decision-log.md).** Both inputs, loaded up
> front, one mode. The preview is fed from a test data pack rather than from defaults. The cost of
> resolving renderers in a long list became [OQ-070](#oq-070--how-does-renderer-resolution-stay-cheap-in-a-long-list).

*Blocks:* [30 Renderer](30-renderer.md) · *Status:* open · *raised by* [D-091](90-decision-log.md)

[R38–R41](30-renderer.md) describe the walk over the **model**: node → renderer → own properties →
edges → target node → and round again. That is complete for drawing a *structure*.

Rendering real data walks the same structure, but the **values** come from a record
([D-083](90-decision-log.md)). So the descent has **two inputs**: the model says what to draw, the
record says what is in it — and the two are walked in step, the model edge naming the
`record_values` row that holds its value.

Open only in the details, and they matter:

1. **What does the context carry** — the record's id, or the loaded record tree beside the model
   subgraph? [D-014](90-decision-log.md) argues for loading both up front.
2. **What happens when they disagree** — a record written against an older model version
   ([D-060](90-decision-log.md)) has values for edges the current model no longer has, and lacks
   values for edges it gained. Does the renderer skip them, or is that the conflict resolver's
   business ([D-054](90-decision-log.md))?
3. **Model-only rendering** — the modelling view draws structure with no record at all. Is that a
   third mode, or simply a record that happens to be the defaults
   ([D-052](90-decision-log.md))?

Point 3 is probably the key: if *no record* is really *the default record*, there is one mode, not
two, and the preview stops being special.

---

## OQ-062 — What does *not computable* look like?

> **Closed 2026-08-22 → [D-147](90-decision-log.md).** Three modes per attribute — strict, partial
> (default), substitute — with marking at **both** the value and the aggregate, and *treat as zero*
> explicitly rejected. In a column *not computable* is `NULL`, so it satisfies neither `> 1000` nor
> `< 1000`, and the search interface needs *not computable* as its own filter.

*Blocks:* [60 Calculation](60-calculation.md), [30 Renderer](30-renderer.md) · *Status:* open · *raised by* [D-104](90-decision-log.md)

[D-104](90-decision-log.md) forbids a truncated calculation from producing a number. So a computed
attribute has a state that is **not a value**: *not computable* — because a cycle was hit, an input
is missing, or a dependency is itself not computable.

What is unsettled is how that surfaces:

1. **In a field**, where a number was expected. An empty cell reads as *zero* or as *not entered*,
   both of which are wrong. A marker is needed that reads as *this could not be worked out*.
2. **In a total that aggregates it.** If one position's price is not computable, is the parts list
   total also not computable, or is it the sum of the ones that worked with a note? The first is
   honest; the second is what people usually want. It probably has to be stated per calculation.
3. **In a search.** [D-070](90-decision-log.md) filters on materialised computed values. Does a
   record whose total is not computable match *over a thousand euro*? It must not — and it must not
   silently count as zero either.
4. **Whether the author is told.** This is the same class of thing as the depth warning, so
   [D-101](90-decision-log.md) suggests it belongs in the preview.

Point 3 is the one with teeth: a *not computable* that behaves like `0` in a query is a wrong
answer that nobody will notice.

---

## OQ-063 — What identifies a record, for finding duplicates?

> **Closed 2026-08-22 → [D-112](90-decision-log.md) and [D-114](90-decision-log.md).** The **shown** fields are the
> **searched** fields, with a per-type declaration as a safety net; matching is *contains*. What
> remains open is only whether there is also a **hard uniqueness constraint** on some attribute —
> enforced rather than advisory — and that is a different setting from the identifying set.

*Blocks:* [30 Renderer](30-renderer.md), [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [D-111](90-decision-log.md)

*Before creating something new, check whether it already exists* needs something to check
**against**, and nothing in the concept provides it.

**Two kinds of identity, and they must not merge.**

| | Is | Settled by |
|---|---|---|
| **hard** | the `id` — stable, meaningless, never resolved on | [D-022](90-decision-log.md), [D-055](90-decision-log.md) |
| **soft** | the human-meaningful values by which a person recognises *this is the same part* | **open** |

D-022 stays intact either way: it says the **base name** is not unique and nothing resolves on it.
Soft identity is about *other* attributes — an article number, or manufacturer plus type
designation together — and a duplicate search that **warns** never contradicts it.

Open:

1. **Which attributes.** A setting marking an attribute as **identifying**, used by the duplicate
   search. Plausibly the article number; plausibly two attributes that only identify **together**,
   which means it is a *set*, not a flag on one attribute.
2. **Is there also a hard constraint?** An article number that must genuinely be unique is a
   different setting — enforced rather than advisory. Usually the same attribute, not necessarily:
   *manufacturer + type* may identify without either being unique alone.
3. **What matching means.** Exact, case-insensitive, or tolerant of spacing and punctuation —
   which is where duplicate detection actually earns its keep, since `BC547B` and `BC 547 B` are
   the same part and an exact match will never say so.

Point 3 is the one that decides whether this feature works at all. Exact matching finds only the
duplicates nobody would have created anyway.

---

## OQ-064 — How is a contains-search made fast?

> **Closed 2026-08-23 → [D-167](90-decision-log.md).** A normalised search column, contains by default in
> the quick search with prefix hits ranked first, an explicit operator field in the filter, no
> wildcard character. The growth stage stays deferred until there is real data to look at.

*Blocks:* [50 Persistence](50-wordpress-persistence.md), [30 Renderer](30-renderer.md) · *Status:* open · *raised by* [D-112](90-decision-log.md)

A wildcard on both sides — `LIKE '%x%'` — **cannot use an ordinary index**. On a few hundred parts
nobody notices; on tens of thousands it is a table scan **on every keystroke**, in a field the user
is typing into.

And the same mechanism has to make `BC 547 B` match `BC547B`, which a plain `LIKE` will not do
either.

Both point at the same answer: **a normalised search structure**, written when a record is saved.

1. **A search column per record**, holding the identifying values concatenated and normalised —
   lowercased, spacing and punctuation stripped. `LIKE '%bc547b%'` then matches, and the column can
   at least be prefix-indexed.
2. **A full-text index** on that column, which handles word-wise search well and substring search
   less well.
3. **A token table** — one row per searchable fragment — which is the most flexible and the most
   machinery.

Option 1 is the smallest thing that solves both problems at once and is worth trying first. Note it
is a **derived** structure like every other index here ([D-016](90-decision-log.md)): rebuildable,
never a second source of truth.

---

## OQ-065 — Does a seed item need a provenance marker?

> **Closed 2026-08-23 → [D-174](90-decision-log.md).** Yes, and in two parts: *from the seed* and
> *changed since*. Untouched is updated silently; changed is left alone and reported.

*Blocks:* [70 Migration](70-migration.md) · *Status:* open, low urgency · *raised by* [C97](10-domain-core.md), [D-121](90-decision-log.md)

[D-121](90-decision-log.md) removed the *template* flag's protective job. A different job may remain:
**knowing which items came from the seed**, so that a plugin update offers only what is genuinely
new rather than re-offering everything.

It may need no flag at all. An update carries items with their `unique` machine keys
([D-115](90-decision-log.md)); anything whose key is already present is skipped, anything else is
offered. That works without recording provenance and survives an author renaming or editing the
item.

Where it would not work: a seed item **without** a machine key, and an author who deleted a shipped
item deliberately — an update would offer it again, every time, and there is no way to say *no,
permanently* except by remembering the refusal.

Worth deciding when the update flow is actually built, not now.

---

## OQ-066 — What happens to data when a node is moved?

> **Closed 2026-08-22 → [D-155](90-decision-log.md).** One rule, two subjects — node and attribute —
> and it never loses data, because the edge id is stable and records reference the id. Up is
> additive, down is removing, and a mandatory attribute makes even up a break.

*Blocks:* [10 Domain core](10-domain-core.md), [70 Migration](70-migration.md) · *Status:* open · *raised by* [D-124](90-decision-log.md)

Moving a node between branches changes its **inheritance**, so it may gain attributes it did not
have and lose attributes it did. Records written before the move hold values for the lost ones.

By [D-037](90-decision-log.md) whether that breaks anything **depends on the data**: a move that
only adds attributes changes nothing for existing records; a move that drops one leaves values with
no attribute to belong to.

So a move is a **model change** and goes to the conflict resolver like any other
([D-054](90-decision-log.md)). What is open is only whether the author is warned **before** the move
— [D-063](90-decision-log.md) says a version-creating change warns at the moment it is made, and a
move is exactly such a change.

---

## OQ-067 — Does a parked record still hold its unique values?

> **Closed 2026-08-22 → [D-154](90-decision-log.md): blocked while parked, released on purge.** The
> owner chose the opposite of the proposal below, and better: holding the value means a **restore can
> never collide**, so the conflict this question worried about never arises.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [D-123](90-decision-log.md)

A record with a `unique` article number ([D-114](90-decision-log.md)) is parked
([D-123](90-decision-log.md)). Does its number still block a new record?

| | Consequence |
|---|---|
| **yes, still blocks** | the author cannot reuse a number they just deleted, and the reason is invisible — the blocking record is in the trash |
| **no, released** | **restoring** it creates a collision with whatever took the number meanwhile |

Neither is free. The likely answer is *released, and restoring is a conflict* — consistent with
everything else here, since restoring is a change and conflicts are what the resolver is for. But
it needs saying, because the failure mode of the other choice is a user staring at *this number is
taken* with no way to see by what.

---

## OQ-068 — Is there a symmetric declaration for aggregation-only?

> **Closed 2026-08-22 → [D-161](90-decision-log.md).** No switch — and no question either. The kind is
> derived from the branch the target sits in, so the wrong kind is never on offer.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised while binding the `Kompositionen` node*

`Kompositionen` declares **only composition edges may point at me** ([D-135](90-decision-log.md)).
The symmetric declaration — **only aggregation edges may point at me** — is not obviously useless.

A catalogue node has a case for it: a `Part` composed into a parts list would **die with that
list** ([C12](10-domain-core.md)), which is exactly wrong for a shared catalogue item. Declaring
*aggregation only* would make that mistake impossible instead of merely unlikely.

So there may be three states rather than two:

| | Means |
|---|---|
| under `Kompositionen` | only composition may point at it — **not standalone** |
| under a catalogue root | only aggregation may point at it — **always standalone, never owned** |
| elsewhere | unrestricted, the default |

Open: whether the second is wanted, and whether it is one more declaring node or a setting. The
transcription of the owner statement said *aggregation node*, which may have meant exactly this or
may have meant the compositions node — worth confirming before building either.

---

## OQ-069 — Views: deferred, with an entry criterion

> **Deferred by decision 2026-08-23 → [D-200](90-decision-log.md).** Not answerable today without
> inventing; the entry names the event that reopens it.

*Blocks:* nothing · *Status:* **deferred by decision** · *raised by the owner, 2026-08-22*

The owner asked whether a **view** — a named, reusable computation referenced from several places —
would be the right home for something like an average price.

**For that case it is not needed.** An average purchase price is a statement *about a part*, so it
has a natural home: a computed attribute on `Part` with a backward operand
([D-140](90-decision-log.md)), inherited by every kind of part and referable everywhere. The reuse a
view would provide is already there.

**And a view would be a second place where calculations live** — the kind of second place this
concept has refused throughout. The cost is not the implementation; it is the question the author
would face every single time: *is this an attribute or a view?*

### When it earns its place

> **As soon as the first figure appears that belongs to no node.**

*Turnover per supplier per month* is not a property of a supplier and not a property of an order —
it sits between them, and the owner's own question, *where would I put it?*, has no answer. That is
the moment.

Two further cases would also argue for it: the same computation wanted in several shapes (a list, a
chart), and a computation needing its **own refresh policy** — nightly rather than on every read,
which is exactly [D-140](90-decision-log.md)'s escape hatch and would hang naturally on a view.

---

## OQ-070 — How does renderer resolution stay cheap in a long list?

> **Closed 2026-08-23 → [D-203](90-decision-log.md).** Parked to **Release 2**: at thirty rows the
> lookups are not measurable. One requirement stands now — a row template meeting a value that needs
> a different renderer must fail loudly rather than draw quietly wrong.

> **Deferred by decision 2026-08-23 → [D-200](90-decision-log.md).** Not answerable today without
> inventing; the entry names the event that reopens it.

*Blocks:* [30 Renderer](30-renderer.md) · *Status:* open · *raised by* [D-159](90-decision-log.md)

The owner raised it while accepting [D-159](90-decision-log.md):

> *It is not unreasonable to have both right now, model and data — but later, with larger lists, it
> could take quite a while if I have to look up all the renderers again and again. And basically
> every column is at least similar.*

A table of a thousand rows and twelve columns asks the registry twelve **thousand** times, and
almost every answer is the same one. The obvious fix is to resolve **once per column** and reuse it
down the rows.

**But the owner then doubted his own fix** — *well, maybe not, I think* — and the doubt is the
substantial part. [D-159](90-decision-log.md) has just established that a renderer may **adapt its
output to the value**. A colour-code renderer draws bands; a resistance with no value at all draws
an empty state; a frozen computed value ([D-143](90-decision-log.md)) is not drawn like a live one.
So the renderer down a column is *usually* constant, and *not reliably* constant.

Which leaves the real question: **is the choice of renderer per column, and only its output per
row?** If yes, one lookup per column is correct and the variation lives inside the renderer, where
it costs nothing. If no, there is a class of renderer that must be re-chosen per row, and the
concept should name what puts a renderer in that class rather than leaving every caller to guess.

Not urgent — it is a question about a table that does not exist yet. It becomes urgent the first
time a list is slow, and the answer will be much cheaper to apply if it was written down before
twelve call sites made their own assumption.

---

## OQ-071 — How is borrowed WordPress marked?

*Blocks:* [50 Persistence](50-wordpress-persistence.md) · *Status:* open · *raised by* [D-169](90-decision-log.md)

[D-169](90-decision-log.md) settles *that* the borrowing is recorded. **How** is open. The owner
suggested a **`wp` prefix or suffix** on the code concerned.

**The objection to a name marker:** [CD-1](../../CLAUDE.md) already splits boundary from core, and
a split that means anything is a namespace split. Then the namespace *is* the marker — every class
under it is WordPress-facing by definition — and a `wp` in the class name repeats what the path
already says. [CD-9](../../CLAUDE.md) asks names to say what a thing **is**, not where it lives.
Prefixes stay where WordPress itself demands them: table names, hooks, options.

**But the objection misses what the owner is actually after.** A namespace catches WordPress
**calls**. It does not catch WordPress **assumptions** — the capability model, the block editor's
data shapes, `dbDelta`'s idea of a schema, the i18n mechanism, the shape of an admin screen. Those
are borrowed just as heavily, they are what a port would actually founder on, and no folder
contains them.

**The alternative on the table:** a **short ledger** kept as the code grows — one line per borrowed
capability, what it does for us, and what would have to replace it. Cheap while it is being written,
and the only artefact that makes an honest estimate of a port possible.

**They are not exclusive.** Choose the ledger, the prefix, or both.

> **Closed 2026-08-23 → [D-170](90-decision-log.md).** The namespace marks it, a ledger catches the
> borrowed assumptions the namespace cannot see, and no `wp` prefix on class names.

---

## OQ-072 — How is the importer told what maps to what?

> **Deferred by decision 2026-08-23 → [D-200](90-decision-log.md).** Not answerable today without
> inventing; the entry names the event that reopens it.

*Blocks:* [70 Migration](70-migration.md) · *Status:* open, deferred until the core is locked · *raised by* [D-173](90-decision-log.md)

[D-173](90-decision-log.md) puts an importer for existing WordPress tables in scope as a boundary
tool. What it is *told*, and by whom, is open.

**The question underneath the question:** does the importer **create the model** from the table, or
only **fill a model that already exists**? Guessing a model from a table is where importers usually
fail — a column becomes an attribute, a foreign key is missed, and the result is the relational
shape the product exists to get away from ([V1](00-vision-and-scope.md)).

Three shapes, in rising order of ambition:

1. **A fixed importer per known source** — `posts`, `postmeta`, `terms`. Quick, useful on day one,
   generalises to nothing.
2. **A declarative mapping the user writes** — this table becomes that node, this column that
   attribute — stored as data, reusable, inspectable.
3. **The mapping is itself a model.** The source table is described with the same modelling tools
   as everything else, and the import becomes a conversion between two models rather than a special
   mechanism. Attractive, and exactly the sort of elegance that costs a year if it is wrong.

Worth noting that the owner asked for the tool for **his own** tables first and for other people's
second. Shape 1 for his, shape 2 as the honest general answer, and 3 only if 2 turns out to be
saying the same thing twice.

---

## OQ-073 — What is the branch without data called?

> **Closed 2026-08-23 → [D-185](90-decision-log.md), name corrected by [D-188](90-decision-log.md).**
> `Primitives` — English per [D-187](90-decision-log.md), with `Bausteine` as the German label.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [D-183](90-decision-log.md)

[D-183](90-decision-log.md) settles that `Model` and `Kompositionen` hold data and the rest does
not. The rest is called `Definition` in the legacy tree and the owner wants a better word: *for that
we probably need another term — those have no data, they are only a means to an end.*

What actually lives there, from the legacy tree: data types, own data types, constants, aggregates,
complex data types. They are **what models are built out of**, not places anything is kept.

Candidates, with what each gets wrong:

| | For | Against |
|---|---|---|
| `Definition` | familiar, in use | says *what* they are, not *how they differ* — a model node is a definition too |
| `Bausteine` | true to the job: things you build with | slightly informal |
| `Vokabular` | precise — the words a model is written in | abstract at first sight |
| `Werkzeug` | carries *means to an end* | suggests behaviour, and these are not behaviour |

The word matters more than it looks: it is the one that has to tell a new user, without a sentence
of explanation, why nothing they enter will ever be stored there.

---

## OQ-074 — Is there an enum filled at runtime?

> **Closed 2026-08-23 → [D-204](90-decision-log.md), [D-205](90-decision-log.md).** Declared per branch
> including by whom; usable at once and reviewed afterwards; visible in the tree and propagated up
> through collapsed ancestors.

> **Deferred by decision 2026-08-23 → [D-200](90-decision-log.md).** Not answerable today without
> inventing; the entry names the event that reopens it.

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open, deferred by the owner · *raised 2026-08-23*

The owner: *what is the difference between an enum I define at modelling time and one I want to
fill at runtime, in the front end or Gutenberg? I am not currently sure there is even a use case
for it.*

**Deferred with his own criterion:** note it as a later stage, *and we will notice as soon as we
work with the project* whether it is missing.

Worth recording why it is not free. A modelling-time enum is a branch of nodes, and adding to it is
modelling — an act with a changelog entry, a migration consequence and a permission behind it. An
enum a visitor can extend at runtime is something else entirely: it would let data entry create
**model**, which every rule here so far has kept apart. If it turns out to be needed, that
separation is the thing to be careful with, not the storage.


### A first plausible answer to OQ-074, from the owner, 2026-08-23

> *For constants or enum-like values one could say: extends. The model could actively state it. I am
> sitting in Gutenberg and I need something at this point, and I have no wish to go back into the
> design view and change my model just to make that one entry.*

**This keeps the boundary and opens it only where it was named.** Data entry still cannot create
model on its own; the **model declares in advance** which branches may be extended in place. The
permission is modelled, not assumed — so it can be seen, inherited along the chain
([D-015](90-decision-log.md)), and refused.

Left open deliberately: who may use such an opening, whether the addition carries a provenance mark
like a pack's ([D-174](90-decision-log.md)), and whether an entry made this way is any different
afterwards from one made in the design view.

---

# From the scenario check, 2026-08-23

Six worlds were modelled against the concept before locking the domain core
([96 Scenario check](96-scenario-check.md)). Five carried. These are what did not.

## OQ-075 — How does a record have versions?

> **Closed 2026-08-23 → [D-305](90-decision-log.md).**

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [96](96-scenario-check.md) §1

*There are boards in different versions, which then have different parts lists* — the owner, twice,
setting it aside both times. The model version of [D-060](90-decision-log.md) is a **stamp** saying
which shape a record was written against; it is not something a person edits and it says nothing
about succession.

So `board v1.0` and `v1.1` are two unrelated records today. Missing: that they are **the same
board**, which came first, what changed, and which one is meant when something says just `Board`.

⚠️ **The trap is to answer it with an aggregation called `Vorgänger`.** That records the order and
nothing else — not that they share an identity, and not which one a reference should resolve to.

## OQ-076 — Can a reader hand a parameter to a rendering?

> **Closed 2026-08-23 → [D-309](90-decision-log.md).**

*Blocks:* [30 Renderer](30-renderer.md) · *Status:* open · *raised by* [96](96-scenario-check.md) §2

*Four portions instead of two* multiplies every quantity in a recipe. It is not a stored value and
not a computed attribute, because the input comes from **the person reading**, at that moment.

The render context carries model, record, purpose and settings ([D-159](90-decision-log.md),
[D-217](90-decision-log.md)) — everything the author decided, nothing the reader supplies.

Related but not the same: a filter narrows *which* records are shown; this changes *how one is
computed*.

## OQ-077 — A conversion that depends on the other value

> **Closed 2026-08-23 → [D-306](90-decision-log.md).**

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [96](96-scenario-check.md) §2

[D-274](90-decision-log.md) puts the factor on the **unit** — right for inch and metre. A tablespoon
of flour is 10 g, of sugar 12 g, of honey 21 g: **the factor belongs to the pairing** of unit and
substance, and today that conversion cannot be expressed at all.

Note it is not exotic: cups, spoons, *a box contains 12*, sheets per ream, and every packaging unit
work this way.

## OQ-078 — Where is the *relationship as a node* pattern taught?

> **Closed 2026-08-23 → [D-307](90-decision-log.md).**

*Blocks:* [10 Domain core](10-domain-core.md) · *Status:* open · *raised by* [96](96-scenario-check.md) §4

A relationship carrying its own values — supplier **plus** customer number **plus** since-when —
is modelled as a **composition that aggregates**. Everything needed exists.

⚠️ **What is missing is that anyone would find it.** The move people reach for instead is a
`Supplier` attribute, then a second, then `Supplier2` — which is how a model rots. The concept can
express more than it teaches, and this is the clearest case.

Not a gap in the model. A gap in what the model **says about itself**.

## OQ-079 — Where does the shape stop being suitable?

> **Closed 2026-08-23 → [D-308](90-decision-log.md).**

*Blocks:* [00 Vision and scope](00-vision-and-scope.md) · *Status:* open · *raised by* [96](96-scenario-check.md) §5

A hundred thousand sensor readings become several hundred thousand rows carrying two useful numbers
each. The projection ([D-228](90-decision-log.md)) speeds reading and changes nothing about writing
or size.

**The answer is probably scope rather than optimisation** — this is a modeller, not a time-series
store — but the concept should **say so**, so that nobody discovers it with a full table.

## OQ-080 — Is there a page per record?

> **Closed 2026-08-23 → [D-309](90-decision-log.md).**

*Blocks:* [20 Interaction](20-interaction.md), [30 Renderer](30-renderer.md) · *Status:* open · *raised by* [96](96-scenario-check.md) §6

⚠️ **Downgraded the same evening** after looking at the owner's site: his pattern is data embedded in hand-written posts, which [D-206](90-decision-log.md) covers. Still wanted for a real catalogue; no longer the blocker.  Five hundred parts cannot each get a hand-built Gutenberg
page. A catalogue needs **one template and a route**: `/bauteil/bc547b` finds the record and renders
it through a page designed **once**.

Nothing provides it. [D-206](90-decision-log.md) puts a block on a page **somebody built**;
[D-195](90-decision-log.md) pushed `slug` out as a boundary concern and never answered the boundary
side. ⚠️ **And the link that [D-105](90-decision-log.md)'s reference renderer draws has nowhere to
point**, which means the gap is already load-bearing elsewhere.

---

## OQ-081 — Which token do the Gutenberg blocks and the text domain use?

> **Closed 2026-08-24 → [D-337](90-decision-log.md).** One token everywhere: `taxmod`. The
> blocks become `taxmod/<slug>`, the text domain becomes `taxmod`, and `taxo/` is struck.
> The text domain deliberately does **not** follow the plugin slug — that convention serves
> `wordpress.org` distribution, which is not planned.

[D-336](90-decision-log.md) settled the product name, the repository name and the database
prefix. It left **three tokens standing side by side**, and nobody has said whether that is
intended:

| Where | Token | Set by |
|---|---|---|
| PHP namespace | `Taxmod` | [D-327](90-decision-log.md) |
| Database tables | `taxmod_` | [D-336](90-decision-log.md) |
| Gutenberg blocks | `taxo/` | `CLAUDE.md` **CD-12** — ⚠️ **no decision behind it** |
| Text domain | *unstated* | — |

⚠️ **`taxo/` is inherited, not chosen.** It comes from the old plugin, where the blocks were
`taxo/object-view` and `taxo/table-view`. It survived into `CLAUDE.md` as **CD-12** without a
`D-<nnn>`, which by the repository's own rule-hygiene section means it is a leftover rather than
a rule — and it is the only remaining place where the old project still names something in the
new one.

**The text domain is the harder half.** WordPress tooling — and `wordpress.org` translation
delivery in particular — expects the text domain to equal the **plugin slug**, which would make
it `wp-taxonomy-modeler`. But every other token we chose is `taxmod`, and a fourth spelling is
one more thing to remember. Whether that convention binds us depends on something that has not
been decided either: **whether this plugin is ever submitted to `wordpress.org`.**

⚠️ **Not to be settled in passing.** A text domain is expensive to change once strings exist,
and a block name is expensive to change once a post contains one — a renamed block turns every
page that uses it into an invalid-block warning. Both are cheap **now** and only now.

*Blocks:* [50 Persistence](50-wordpress-persistence.md), [40 I18n](40-i18n.md), `CLAUDE.md` CD-12 · *Status:* **closed** · *raised 2026-08-24 by the rename, closed the same day*

---

## OQ-082 — How does the split behave?

[D-343](90-decision-log.md) settled the shape — tree left, properties of the selected node right.
Three things it deliberately did not settle, because the owner said one sentence and inventing the
rest would be exactly what `PR-4` forbids:

| | |
|---|---|
| **Is the split resizable, and is the width remembered?** | [U11](20-interaction.md) makes density a requirement, which argues for it; nothing says it |
| **Does the selection survive a reload, and can it be reached by URL?** | ⚠️ A URL that names a node makes a link to *this node* possible — which is what [OQ-080](#oq-080--is-there-a-page-per-record)'s page-per-record wanted and could not have |
| **What stands on the right when nothing is selected?** | empty, the root, or the last selection |

*Blocks:* [20 Interaction](20-interaction.md) · *Status:* **open** · *raised 2026-08-24 by [D-343](90-decision-log.md)*

---

## OQ-083 — Does restoring a node put its promoted children back?

> **Closed 2026-08-24 → [D-347](90-decision-log.md).** Yes. The owner settled it by asking whether
> restoring **is** undo — and [D-172](90-decision-log.md) says undo's *reach is the trash*, which
> makes restoring the undo rather than a neighbour of it. Untouched children return; children
> moved, renamed or deleted since are **left where they are and named**.
Found by the owner while trying it: delete **only** a node ([U4](20-interaction.md)), its children
move up to the grandparent — then restore the node, and it comes back **empty**. The children stay
where the promotion put them.

⚠️ **The concept does not answer it, and both readings are defensible.**

| | Reading | Follows from |
|---|---|---|
| **A** | **Restore re-attaches them.** [D-127](90-decision-log.md): *a trash entry is one deletion event, with everything that fell with it — restore puts back the whole event.* Deleting a node **and** promoting its children was one act by one person from one button, so undoing it should undo both | [D-127](90-decision-log.md), and the plain expectation of anyone who clicks *restore* |
| **B** | **Restore leaves them.** [U4](20-interaction.md) calls the promotion *exactly the move of [D-155](90-decision-log.md)* — an ordinary reparenting. Two changes happened; undoing the deletion undoes the deletion | [U4](20-interaction.md), [D-155](90-decision-log.md), and [D-172](90-decision-log.md)'s *undo is a step forward, not a rewind* |

⚠️ **The hard case is neither of those: a child that has moved, been renamed or been deleted
since.** Re-attaching it then overwrites a newer, deliberate decision with an older one. Any
answer has to say what happens to those, not only to the untouched case.

⚠️ **And a related gap in what is built, not in the concept:** [D-127](90-decision-log.md)'s
**deletion event** does not exist yet. Parking currently writes one changelog line per node, so
there is nothing that says *these things fell together*. Reading A cannot be built without it;
reading B can.

*A third possibility, recorded so it is not lost:* the choice could belong to the **restore**
rather than to the concept — *put it back as it was* versus *put back only this node* — which is
the same shape as [U4](20-interaction.md)'s own question, asked from the other end.

*Blocks:* [10 Domain core](10-domain-core.md), [20 Interaction](20-interaction.md) · *Status:* **closed** · *raised and settled 2026-08-24 by the owner, from trying it*
