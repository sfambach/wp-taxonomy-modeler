# Harvest 02 — the legacy admin: menu and settings page

*Prepared 2026-08-23 from screenshots supplied by the owner. **Nothing here is decided.** This is a
reading of what exists, with observations and questions for the working session that follows.*

---

## The admin menu

| Entry | What it plausibly is |
|---|---|
| **Taxonomy Tree** | the tree screen — harvested in [20 Interaction](../20-interaction.md), U1–U8 |
| **Fill Model Data** | entering records against a model. Named as the target of the count links below |
| **Model versions** | the version stamps of [D-060](../90-decision-log.md) / [D-210](../90-decision-log.md) |
| **Cleanup** | unknown — possibly the trash, possibly orphan repair |
| **Presentation** | labels and roles ([40 I18n](../40-i18n.md)); the detail screen links here as *Open presentation…* |
| **Settings** | the page below |

⚠️ **Two of these have no counterpart in the new concept yet**: *Cleanup* and *Fill Model Data* as
a screen of its own. Ask before assuming either is obsolete.

---

## Settings — General

> *Change settings below, then save. Undo restores the last saved values.*

| Setting | State | What it says |
|---|---|---|
| **Hide root node** | on | Hides the project root; its children appear at top level. The root is still stored as `Fallstudie`; the tree label is `Taxonomy`. |
| **Test mode** | on | *Scaffold test posture (e.g. default confirm-delete off). Applies only after you save.* |
| **Show type in tree** | off | Appends the data type to tree labels (`Wert [double]`). Off because long type paths do not fit the column; the full type is always in the detail panel. |
| **Show Model Data counts in tree** | off | Instance counts on structure hosts (`Bauteilliste (23)`). Same toggle as the tree toolbar switch. **The count links to Fill Model Data for that host.** |
| **Show set child properties** | off | Under a **set node**, also list child (member) properties. *Only for set-typed nodes (e.g. Meter, Abmessung)*. Shows each child's type, fixed value and required flag. |
| **Save via button** | off | Off: edits in the detail panel save immediately. On: they stay local until *Save settings*. |
| **Tree picker** | Popup | How node pickers and the `node_ref` chooser appear. *The reparent dialog always keeps an inline tree.* |
| **Default render depth** | 1 | 0 = meta only; 1 = this node and its direct attributes (recommended); 2+ = nested related objects. **Individual blocks may override.** |

## Settings — Tree icons

An **allow-list** of the icons that may be assigned on a node. 39 icons, most enabled; `Bullet
list`, `Phone`, `Location`, `No`, `Chart`, `Pointer` and `Block` are off.

> *Nodes store their own icon; new children copy the parent icon once. Unchecking an icon here
> hides it from pickers; nodes that already use it show no icon until another allowed icon is
> chosen.*

## Settings — Confirm dialogs

> *Optional popups for risky or soft situations. Turn on only the friction you want.*

| Setting | State | What it says |
|---|---|---|
| **Confirm node delete** | off | Off: delete immediately. *Trash = node only (children move up); networking icon = whole branch.* **Default follows Test mode** — off while testing, on in release. |

## Settings — Development

| Setting | State | What it says |
|---|---|---|
| **All nodes and relations deletable** | on | *Development only.* Catalog/system nodes become deletable and protected relations (including `child_of`) can be removed. The trash itself stays non-deletable. |
| **Reset case tree** | button | Hard-wipes all `Fallstudie` terms including attribute slots, clears catalog bindings and Model Data, reinstalls the blueprint. Requires Development mode. |

## Settings — Catalog bindings

> *Stable term-id links for the shared catalog tree. Rarely changed.*

| Key | Term ID | Node |
|---|---|---|
| `chooser_root` | 4864 | Fallstudie |
| `chooser_focus` | 4867 | Data Types |
| `model` | 4960 | Model |
| `data_types` | 4867 | Data Types — *legacy alias* |
| `simple` · `complex` | 5183 · 4879 | Simple · Complex Datatypes |
| `builtin.int` `.double` `.char` `.bool` `.email` `.date` `.time` `.datetime` `.color` `.media` `.quantity` | 5184 … 4880 | the primitive types |
| `builtin.node_presentation` | 5192 | node_presentation |
| `builtin.node_ref` | *(unbound)* | — |

---

## What this confirms

**The bindings are [D-120](../90-decision-log.md), already built.** Named slots pointing at nodes,
so the engine never names an id or a node name. This screen is the evidence that the mechanism
works in practice, not merely on paper — and it shows the shape a binding list takes once there are
twenty of them.

**Several decisions turn out to describe things that already exist:**

| Screen | Decision |
|---|---|
| *Default render depth … individual blocks may override* | the resolution chain, [D-015](../90-decision-log.md) |
| *Default follows Test mode* | an installation-level entry in that chain, [D-079](../90-decision-log.md) |
| *Development only* lifting protection | the developer flag of [D-122](../90-decision-log.md) |
| *Trash = node only (children move up)* | the delete question of [D-180](../90-decision-log.md) |
| Icon allow-list, non-destructive | narrowing checked where it happens, [D-157](../90-decision-log.md) |

## What contradicts the new concept

| | |
|---|---|
| **Term IDs** | Nodes are WordPress **terms** here. [AR-1](../../../CLAUDE.md) puts the model in the plugin's own tables, so term ids disappear and the bindings point at node ids. |
| **`data_types` as a *legacy alias*** | A second key for the same node, kept for compatibility. Exactly the duplicated fact the rules forbid — worth deciding now whether aliases are ever allowed, before the first one is added. |
| **Tree picker as a global choice** | [U0](../20-interaction.md) says there is **one** chooser. A setting *popup or inline* is fine as an option of that one chooser — but *the reparent dialog always keeps an inline tree* is an exception hard-coded into one screen, and the new concept has no room for that. |

## Questions this raises

1. **What is a *set node*?** *Set-typed nodes (e.g. Meter, Abmessung)* with child members carrying
   type, fixed value and required flag. The new concept has no such construct. Is this a composition
   with multiplicity 1 ([D-133](../90-decision-log.md)), or something the concept is missing?
2. **What does *Cleanup* do?**
3. **Is *Fill Model Data* a screen of its own**, or is it what the record side of the tree becomes?
4. **Immediate save versus explicit save** — the legacy default is immediate. Nothing in the concept
   decides this, and it interacts with undo ([D-172](../90-decision-log.md)).
5. **`node_presentation` and `node_ref` as builtin types** — the second is *unbound*. What was
   `node_presentation` for?
6. **Test mode as a posture that changes other defaults** — a genuinely good idea, and unlike
   anything the concept currently has a word for.
