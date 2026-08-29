# 20 · Interaction — what a person may do

*Status:* `open` · started 2026-08-23 from owner statements
*Companion to* [30 Renderer](30-renderer.md), which answers *how a thing is drawn*. This one
answers *what someone is allowed to do with it*.

---

## Principles

These hold everywhere in the interface. They are listed first because every later section
assumes them, and because the previous project lost most of its consistency by deciding them
case by case.

### U0 · One chooser

```mermaid
flowchart LR
  A["choose a part"] --> W["the same chooser"]
  B["set a default"] --> W
  C["Ziel eines Attributs"] --> W
  W --> P["pick"]
  W --> N["create"]
```

**There is one chooser in this product.** It may take options for different scenarios, but it
**always looks the same**. The owner had to explain this at length to the previous assistant and
states it as a design rule for the whole interface, not as a preference for one screen.

Two things follow that are easy to get wrong separately:

- **It picks and it creates.** A target that does not exist yet has to be enterable, and that is
  true of **aggregation as much as composition** — with composition creating is simply the usual
  path and with aggregation picking is. ⚠️ *An earlier draft of this document split the two into
  different widgets — a list of references for aggregation, a blueprint for composition. The owner
  corrected it: the split is too sharp, and we lose nothing by making composition just as
  enterable.*
- **Inline selection is the exception.** In many places it makes no sense; where the choice is
  genuinely hard, a proper dialogue that helps is the better answer. **The default is therefore the
  dialog**, with inline kept available where the choice really is simple
  ([D-244](90-decision-log.md), flipping the default of [D-108](90-decision-log.md)). The two are
  **separate chooser renderers**, not one renderer with a switch, and which of them applies is a
  setting on the resolution chain. ⚠️ *This concerns the chooser only — what is drawn **after** a
  node has been chosen is the chosen node's own renderer, which is a separate matter.*

### U32 · The dialog closes on Escape and keeps the focus

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
    L["label · opens"] --> C["checkbox holds the open state"]
    C --> P["panel · the focus moves in and stays"]
    P -->|Escape| C
```

**The dialog of U0 stays scriptless in the part that counts.** A nameless checkbox holds the open
state, a `<label>` toggles it and the shadow closes it on a click — *all of that keeps working with
JavaScript switched off*. Script adds the two things a real `<dialog>` element would give and CSS
cannot ([D-491](90-decision-log.md)): **Escape, and a focus that does not wander out of an open
overlay.**

| | |
|---|---|
| **Escape** closes | the **topmost** dialog — the one holding the focus, else the last opened. *Two may be open at once, because the styling makes each independent, and closing all of them on one keypress would take away a state nobody gave up* |
| **the focus returns to** | the **checkbox**, not the opener: the opener is a `<label>` and a label cannot take focus. The ring is drawn on the label when the checkbox has the focus, so it *looks* like the button |
| **a stop of size zero** | is skipped: it is hidden by the styling, and putting the caret at an invisible place is worse than passing it by |

⚠️ **The check written with it does not test the behaviour, and says so in its own text.** *It asserts
that **script and markup speak the same class names**, both ways — because the fallback that really
happens is a renamed class, and then the script **silently** stops biting: no error, no red check,
just a dialog that ignores Escape again ([D-491](90-decision-log.md)). It also asserts that the toggle
is not `display:none`: without a keyboard route **into** the dialog, a focus trap would be the
solution to a problem nobody can reach.*

⚠️ *This leaves [D-244](90-decision-log.md) untouched — it is the same two chooser renderers, with the
dialog behaving as a dialog.*

### U0b · A control's state follows from what is actually choosable

The owner's example: a select with **one** entry is **greyed out** — there is nothing to choose.
With more than one, a choice genuinely exists and the control is live.

**Corrected 2026-08-23: the test counts possibilities, not entries** ([D-227](90-decision-log.md)).

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
    A["one entry · multiplicity 1"] --> G["one outcome → greyed"]
    B["one entry · multiplicity 0..1"] --> L["two outcomes → live"]
```

| Entries offered | Multiplicity | Outcomes the control can produce | State |
|---|---|---|---|
| one | `1` | one — that entry | **greyed** |
| one | `0..1` | two — that entry, or **nothing** | **live** |
| several | any | several | live |

The short form — *a select with one entry is greyed* ([D-198](90-decision-log.md)) — was true only
of the first row, and it collided with [D-056](90-decision-log.md), which had said a single entry
under an **optional** multiplicity must not be greyed. Both stand: at `0..1` the second possibility
is *nothing*, and clearing an optional selection is a real outcome.

> **The test is never *how many rows are in the list* but *how many outcomes can this control
> produce*.**

This is [D-050](90-decision-log.md) — *do not ask what cannot matter* — applied to controls rather
than to dialogues, and it is worth checking **everywhere** rather than deciding per screen. A
control that asks a question with one possible answer is not being helpful; it is making a person
prove they have read it.

### U18 · Automatic is a default, never a fact

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
    A["only one candidate"] --> B["chosen for the person"]
    B --> C["named at the use site"]
    C --> D["revocable there"]
```

U0b removes controls, and that power has a limit ([D-223](90-decision-log.md)). **Two questions get
confused and only one of them is dead:** *which* converter supplies a colour sequence may genuinely
have one answer, so that control disappears; *whether* this use site wants a colour form **at all**
always has two, so that control stays.

The general rule — [D-032](90-decision-log.md)'s two-fold principle applied to automatic choices:

| | |
|---|---|
| **Revocable** | anything the system chooses on a person's behalf can be refused at the use site |
| **Visible** | and it must say that it was chosen |

A field showing red-blue-green with nothing anywhere saying *presentation: colour code*, and no
place to change it, is the kind of magic nobody can switch off later because nobody can find where
it came from.

Concretely: the shorthand control accepting `2k7` appears **automatically wherever an invertible
text converter exists, and can be switched off**; refusing a colour form needs nothing special,
since choosing the ordinary composite renderer at that use site already **is** the refusal.

### U19 · A restriction that collapses to one *is* the fixed value

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
    P["permitted = {Ohm}"] --> O["one possibility"]
    O --> C["control disappears · U0b"]
    C --> F["reads as a fixed value"]
```

There is no *fixed value* control ([D-221](90-decision-log.md)). Say **only `Ohm` is permitted** and
that **is** the fixed value: one possibility remains, the control disappears by U0b, and no author
picks the unit again on every part. A separate *fixed* setting beside the permitted set would be the
classic duplicated fact — *fixed = Ohm* against *permitted = {Ohm, Volt}*, with nothing to say which
wins.

**Restrictions narrow downwards and never widen.** A use site further down may restrict further; it
may not reopen, or *only Ohm* guaranteed nothing in the first place. Whoever genuinely needs another
unit does not need this type at all. The model side is
[C117](10-domain-core.md#c117--there-is-no-fixed-value-only-a-restriction).

### U20 · The chooser also works value-first

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
    T["type first"] --> V["then the values"]
    E["value first · 10 kΩ"] --> N["candidate types narrow"]
    N --> R["resistor · resistor bridge"]
```

The concept had only ever assumed one direction — pick a type, then fill in the values. The reverse
works too ([D-239](90-decision-log.md)): entering `10 kΩ` **before** choosing anything leaves the
types that can have such a value, and if only one can, the type follows from the entry.

It is the **same chooser** (U0) and the same search ([D-167](90-decision-log.md)); the only
difference is that the search runs **across types** instead of inside one already chosen.

It is also why U7 has to allow intermediate nodes: a value-first search narrows to *some kind of
resistor* and stops there, and forcing a leaf would demand a decision the data do not contain.

## The tree

The tree is where the model is built. The legacy project had one and, in the owner's words, it
*proved really good* — so this section starts from it rather than from a blank page, and records
what was right, what was crowded, and what is missing.

```mermaid
flowchart LR
  H["header<br/>expand all · collapse all"] --- T["tree"]
  T --- Z["row<br/>name + frequent actions"]
  Z --- M["context menu<br/>everything"]
```

### U1 · The row shows the frequent, the menu holds everything

The legacy row carried seven controls — add, duplicate, up, down, hide, delete, hierarchy — and the
owner's own reading is that it is **a bit overloaded**. The fix is not to remove abilities but to
separate two different jobs:

| | Holds | Reached by |
|---|---|---|
| **The row** | what is used constantly | always visible |
| **The menu** | everything the row can do, plus the rest | right-click, and a `⋯` on the row |

The same action appearing in both places is not duplication to be avoided — it is a second route to
one behaviour. And the `⋯` is not decoration: **touch has no right-click**, so without it the menu
would be unreachable on half the devices this will run on.

### U2 · Up and down stay, even though dragging exists

They look redundant beside drag and drop and are not. Moving one position with a mouse drag is
fiddly, needs a steady hand and a visible drop target; a button press is exact. The two serve
different intents — **reorder by one** versus **move somewhere else** — and only the second is a
dragging job.

### U3 · The header's `+` and the row's `+` mean different things

*Expand all* in the header, *add a child* in the row. The owner felt the header icons wanted
replacing; the reason is not that they are ugly but that **one of them wears the other's sign**.
Whatever replaces them, the two meanings must not share a symbol.

### U4 · Deleting asks one question: the branch, or only this node

```mermaid
flowchart TB
  D["delete node"] --> A["the whole branch"]
  D --> B["this node only"]
  B --> C["children move up to the parent"]
```

The owner: *when the node is deleted, the children get hung on the father*. Both operations are
wanted and neither is the obvious default, so it is asked rather than guessed.

⚠️ **Neither needs new machinery, and the second is not as harmless as it looks.** The tree is
inheritance ([D-041](90-decision-log.md)), so children reattached to the grandparent **lose
whatever they inherited from the deleted node**. That is exactly the move of
[D-155](90-decision-log.md), with orphaned overrides promoted per
[D-156](90-decision-log.md) — the same rules, reached from a different button.

### U5 · Dragging moves whole branches, and several at once

Drop a node on another and its **entire branch** goes with it. Select several and drop them
together and all their branches move. The owner calls this *rather practical*, and it is the
operation the tree exists for — restructuring is the daily work of modelling, and doing it one node
at a time is what makes people avoid it.

Every such move runs through [D-155](90-decision-log.md): moving never loses data, and where the
target branch changes the relation kind it goes through the conflict resolver
([D-162](90-decision-log.md)).

### U6 · Duplicating puts the copy directly beneath, with an indexed name

Not at the end of the list, not in some default position: **immediately under the node it came
from**, named with a trailing index. The reason is the working rhythm the owner described —
duplicate several times in a row, then rename each — and that only works if every copy lands where
the eye already is.

### U7 · What may be picked belongs to the place doing the picking

The legacy `eye` hid a node and went unused. Its aim was to stop some nodes being **selectable** —
when choosing a type, the person should land on a **leaf** rather than an intermediate node. It sat
on the wrong object: the same node is a fair choice when **modelling** (*something from this
branch* is how a type is named) and a mistake when **entering data**. So it is a setting at the use
site, arriving in the render context, inheriting along the chain ([D-181](90-decision-log.md)).

**Corrected 2026-08-23 — the default is everything except the branch root**
([D-238](90-decision-log.md)). An earlier version of this section said *leaves only*; that was
[D-181](90-decision-log.md)'s default and it has been reversed, upholding
[D-110](90-decision-log.md), which had already refused *leaves only* with the owner's departments
counter-case.

| | |
|---|---|
| What stands from [D-181](90-decision-log.md) | selectability belongs to the **use site**, not to the node — the real finding, and the reason the legacy `eye` went unused |
| What is withdrawn | the **default**. *Leaves only* would break the departments case at every new use site and have to be reopened each time |
| What it is now | **everything but the branch root** is selectable; whoever needs leaves enforces it where they need it |

The owner's own example settles it: entering `10 kΩ` may leave both a resistor and a resistor bridge
in play. The data do not support the decision, so an intermediate node is the honest answer, not a
gate to be forced past — see U20.

### U8 · What cannot apply is not shown

Visible in the legacy screenshot: the last child has no *down* arrow, some rows have no hierarchy
control. Not greyed out — **absent**. This is [D-050](90-decision-log.md) applied to a toolbar, and
it is what keeps a seven-control row readable at all.

### U21 · The tree row draws the node's icon

Where a node has an icon set, the tree shows it ([D-251](90-decision-log.md)) — the node renderer in
the tree takes the icon into account when one is present.

Small, and it is the reason the icon exists at all: the tree is where a person scans a hundred rows,
and a glyph is read faster than a word. It costs nothing, since the icon is already resolved with
everything else along the chain.

⚠️ **The icon is not the `symbol`.** An icon is a glyph chosen from the installation's allow-list, a
**setting**, language-neutral and the same in every locale ([D-019](90-decision-log.md),
[D-252](90-decision-log.md)); `symbol` is a very short **text** — `Ω`, `Pos.` — and a translated
label **role** ([D-196](90-decision-log.md)). The legacy detail screen put them side by side in one
panel, which is how they came to be confused once already.

### U33 · The eye hides a placement, and the branch goes with it

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
    E["the eye on the row"] --> P["the inheritance edge · the placement"]
    P --> S["row and subtree never entered"]
    R["the root · no such edge"] -.-> N["cannot be hidden"]
```

**The mechanism and its whole history are owned by
[Hiding](10-domain-core.md#hiding--hide-is-one-column-and-it-is-on-the-edge).** What belongs here is
what a person does and sees: the control writes on **the edge that puts the node into the tree**, so
a hidden row **takes its branch with it** — not by a filter afterwards but because the descent never
enters it ([D-467](90-decision-log.md)).

⚠️ **What changed, and why U1's list of seven stays true.** *The legacy row's `hide` sat on the
**node**, and this document never said otherwise. A node does not carry it any more
([D-467](90-decision-log.md)), so the `hide` counted there is a fact about the legacy row and not a
description of ours. The owner's reason is better than the mechanism: «I do not simply create a model
node and then say I will not draw it — that would be nonsense. Where I would say it is on the
**fields** of a model node, when I only want something in the background, to calculate with.»*

⚠️ **Not to be confused with the legacy `eye` of U7, although the glyph is the same.** *That one was
aimed at **selectability** and its finding was that selectability belongs to the use site
([D-238](90-decision-log.md)). This one is about **drawing a row**, and neither of the two is the
node-level flag that has now gone ([D-467](90-decision-log.md)).*

⚠️ *The root has no inheritance edge and therefore **cannot** be hidden. That is correct rather than a
gap, and the action answers with nothing instead of failing ([D-467](90-decision-log.md)).*

### U34 · The tree opens collapsed, and the fold state is carried forward

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
    F["fresh page · no parameter"] --> C["everything with children closed"]
    C --> A["minus the ancestors of the selected node"]
    A --> L["and every link carries the state on"]
```

*When I open the page afresh it should be collapsed — that gives the best overview*
([D-478](90-decision-log.md)). And once that was built: *the current branch staying open is right too,
**but I would rather the state were carried forward**. As soon as I click on Taxonomy Modeller I get
the reset tree, and when I then work in it, **I** decide which branch is open or closed. Working
between two nodes with the branches closing all the time is rather annoying*
([D-480](90-decision-log.md)).

| | |
|---|---|
| a fresh page | **no parameter at all**, and it shows only the branches — a node directly under the model root is closed as well ([D-478](90-decision-log.md)) |
| the selected node's ancestors | **open, always** — otherwise the detail on the right shows a node the tree beside it does not contain |
| the selected node itself | stays closed. *That is a choice, not a consequence of the one above* ([D-478](90-decision-log.md)) |
| every link on the page | carries the current state on, so the next click does not recompute the default ([D-480](90-decision-log.md)) |
| the menu item | leads to a URL without parameters, and that **is** the reset ([D-480](90-decision-log.md)) |

⚠️ **The second half is needed because the first would otherwise contradict it.** *A carried-forward
state holds the ancestors of the **previously** chosen node and would close the new one — so the
selected node's ancestors are subtracted on every build. **Both wishes hold at the same time**, and
neither is a special case of the other ([D-480](90-decision-log.md)).*

⚠️ **It was broken although the state «lay in the URL».** *Measured: a fresh page carried it in
**zero** links — without a parameter the default was simply recomputed on the next click, so every
branch a person had just opened closed again. The state lay in the URL and nobody wrote it there; it
now travels in **22** links of the same page ([D-480](90-decision-log.md)).*

⚠️ **And the hoped-for saving is measured and is not there: a collapsed page costs one query *more***
— 11 against 10 ([D-478](90-decision-log.md)). *The subtree is loaded whole either way, and loading
less would be the loading question, which is a separate one. The promise was «less disturbance and
less work»; it delivers the first.*

⚠️ *The empty set travels as the word `none`, because an empty URL value cannot be told from a missing
one ([D-478](90-decision-log.md)) — and a missing one is what makes a page fresh. Whether the
**selection** itself survives a reload is a different question and still
[OQ-082](91-open-questions.md).*

---


---

## The detail view

⚠️ **Where it sits: to the right of the tree** ([D-343](90-decision-log.md)). The screen is one
surface in two parts — the tree on the left, the properties of the **selected** node on the
right. There is no separate *open* step and no second screen to navigate to and back from.

```mermaid
flowchart LR
  T["tree · left"] -->|selection| D["properties of that node · right"]
```

**That the tree has a selection at all follows from this**, and it is what makes
[U10](#u10--attributes-show-their-core-and-hide-their-detail)'s loading argument work: the detail
is fetched for **one** node, at the moment it is chosen — not for the whole tree on every view.

*Open: whether the split is resizable, whether the selection survives a reload or can be reached
by URL, and what the right-hand side shows when nothing is selected —
[OQ-082](91-open-questions.md).*

**What it contains, and in which order:**
```mermaid
flowchart TB
  A["actions"] --> B["what cannot change"]
  B --> C["name"]
  C --> D["display"]
  D --> E["attributes · collapsed"]
  E --> F["preview"]
  F --> G["relations · collapsed"]
```

### U9 · The order is the order of dealing with a node

Not taste — the sequence in which a person works. **What acts** sits at the top; **what cannot be
changed** underneath it, as a band of chips; the **name** next, because it is what you change first;
then **display**, which is changed occasionally; then the **attributes**, which are the real work;
then the **preview**, which shows what came of it; and last the **relations**, which are consulted
rather than edited.

### U10 · Attributes show their core and hide their detail

A row shows name, type, multiplicity, kind, default, read-only, hidden, inherited. Everything else
is behind an expander, per attribute.

This is **two arguments pointing the same way**, which is rare enough to record: it keeps the
screen legible, **and** it means the detail of an attribute is only loaded when it is opened — not
the whole node's worth of settings on every page view. Density and loading agree here, so a later
rebuild must not discard it as mere styling.

The same reasoning puts **relations** at the bottom, collapsed, and not even queried until opened.

### U11 · Density is a requirement, not polish

The owner on the legacy screen: *it is all still relatively large; it would be good if it could be
made smaller in area so that more fits on one screen, while still staying clear*. Two rules that
follow, and they cost nothing to keep:

- **Fields are sized to their content, not to the container.** A name of ten characters does not
  need a field spanning the window.
- **Fixed facts are chips, not rows.** The legacy meta band already does this and it is the densest
  part of the screen. It is the pattern to extend, not the exception.

### U12 · Three sections the legacy screen has no place for

They arrived with decisions taken after that screen was built:

| Section | Why | Where |
|---|---|---|
| **Conflicts** | [D-054](90-decision-log.md) reports rather than blocks — but a report nobody sees is a block with extra steps. If this node has an unresolved conflict it belongs at the **top**, because it is actionable. | above the name |
| **Provenance** | Which pack a node came from and whether it has been changed since ([D-174](90-decision-log.md), [D-175](90-decision-log.md)) — it decides whether an update will touch it. | in the fixed band, as chips |
| **History** | Every change with before and after ([D-061](90-decision-log.md)); this is where taking something back lives ([D-172](90-decision-log.md)). *Last modified by* is already there and is one line of it. ⚠️ *What may ever be deleted from it — and the rows that never may — is [U36](#u36--a-changelog-entry-goes-only-where-nothing-hangs-on-it-any-more)* | bottom, collapsed, beside relations |

A parked node ([D-123](90-decision-log.md)) must say so too — but that is a state of the whole
screen, not a section of it.

### U13 · `Used by` — the one direction nothing else shows

The legacy screen ended with `Relations`, collapsed: *all connections to and from the node*. Half of
that is already on the page and nobody had noticed:

| Direction | Where it already is |
|---|---|
| outgoing, non-inheritance | **the attributes table** — an attribute *is* a relation seen from its owner ([D-031](90-decision-log.md)) |
| the parent edge | the fixed band, as a chip |
| the children | the tree |
| **incoming** | **nowhere else** |

So the section holds exactly one direction, and it is renamed for it: **`Used by`** — *Verwendet
von*. Not `Incoming`, which describes the arrow rather than the meaning, and not `Referenced by`,
because *reference* is already carrying three jobs here.

**It is not a listing, it is an impact estimate.** It answers the question a person has before every
larger change:

| Intending to | It says |
|---|---|
| delete | who breaks ([D-122](90-decision-log.md)) |
| move | where the relation kind flips ([D-162](90-decision-log.md)) |
| remove a pack | what points in from outside ([D-177](90-decision-log.md)) |
| rename | who inherits the label ([D-015](90-decision-log.md)) |

The same list the conflict resolver would pull anyway — but beforehand and voluntarily, rather than
afterwards and as a complaint.

⚠️ **The condition, in the owner's words: *as long as that stays so*.** The section is allowed to
hold one direction only because every outgoing edge is currently visible elsewhere. If an outgoing
edge ever appears that is neither an attribute nor inheritance, this section has to grow back —
otherwise it silently stops being complete.

**And model must not blur into data here.** On a **node**, `Used by` lists the **attributes of other
nodes that have this node as their type**. *Which records point at a record* is a different
question and belongs on the record's own screen.

### U14 · A pending review is visible from the outside of a collapsed branch

A value added during data entry is usable at once and reviewed afterwards
([D-204](90-decision-log.md)). The tree has to show that something is waiting.

The marker on the node itself is the easy half and the useless half — the node is normally inside a
collapsed branch, and nobody expands a whole tree on the off-chance. So **it propagates upwards**:

| Marker | Means |
|---|---|
| filled | this node is waiting |
| outline | something beneath it is |

Following the outline downwards is how the node is found without searching for it. **Not red** —
red is deletion and conflict, and a pending review is neither wrong nor destructive, only
unfinished. And distinct from the `Counts` badge ([D-189](90-decision-log.md)), which answers a
different question in the same corner of the row.

### U35 · The node page saves as a whole — settings and texts in one act

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
flowchart TB
    S["settings fields"] --> F["one form · one save button in the page head"]
    L["label fields · they name that form"] --> F
    F --> G["one change group in the log"]
```

*Labels should be saved **with the page** as well* ([D-488](90-decision-log.md)). So the labels area
draws no form of its own any more: its fields name the page's form, and the area's own save button is
gone with them — the owner, asked whether it should go: *yes* ([D-489](90-decision-log.md)).

⚠️ **The texts had to move, and the reason is HTML rather than tidiness.** *A field belongs to exactly
one form and a named form beats the nesting. Keeping the texts in their own form while the page saved
the settings would have thrown the settings beside them **silently** away
([D-488](90-decision-log.md)).*

| | |
|---|---|
| an **empty** field | means *forget the row*, never *store an empty text* — a stored empty string would **end** the fallback chain. Written down long before and only now built ([D-488](90-decision-log.md)) |
| what a text is compared against | **what is stored here**, never the fallback chain: what stands in the field when nothing is set **is** the chain's answer, so comparing against it would write the placeholder down as though a person had typed it ([D-488](90-decision-log.md), [D-489](90-decision-log.md)) |
| the order | settings first, then texts — a refused bound aborts **before** any text is written ([D-488](90-decision-log.md)) |
| the log | a label change is journalled against its owner, exactly as a setting is, and the entry carries the **address** — role and locale, plus the path where there is one. *An entry without an address is not replayable* ([D-489](90-decision-log.md)) |
| one save, one group | a rename, a setting and a text sent together become **one** change group ([D-470](90-decision-log.md)), not three ([D-488](90-decision-log.md)) |

⚠️ **Before this there was not one such line: of 10496 changelog entries, none named a label**
([D-489](90-decision-log.md)). *Journalling had been decided for settings — because 591 rows had no
history at all — and for labels nobody had ever asked, although it is the same kind of row in the same
kind of table.*

⚠️ *A text field **always** submits, and now on every page save. That is why the comparison in the
second row above is the load-bearing part and not a detail ([D-488](90-decision-log.md)).*

⚠️ *Not built with it: a hardening against a **missing** page form — an empty panel emits none, which
would be silent non-saving. Measured: 91 nodes, the poorest resolving 2 keys, **0 empty panels** — the
case does not arise today and stays structurally possible ([D-488](90-decision-log.md)).*

---

## The administration surface

How the configuration screens behave, as opposed to what they show. Dictated 2026-08-23 while going
through the legacy settings page.

### U22 · Settings apply immediately; the save button stays for one named reason

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
    A["change a setting"] --> B["applied at once"]
    B --> C["undo is the net · D-172"]
    A -.host misbehaves.-> S["explicit save"]
```

*Basically it is nicer if I make a setting and it is simply taken; at worst I can undo it*
([D-249](90-decision-log.md)). So immediate is the default.

⚠️ **But the button is not superstition.** In WordPress the page sometimes jumps when a setting is
changed, loses focus, and the person does something else entirely. That is a real failure of the
host, not a preference, so the switch stays — **immediate by default, explicit save where the
environment misbehaves.**

Immediate saving is only defensible **because undo exists** ([D-172](90-decision-log.md)). Without
it, *applied at once* would mean *lost at once*.

### U23 · One mode, not two — test mode folds into developer mode

The legacy settings screen carried both: *Test mode*, which changed other defaults (confirm-delete
off while testing), and *Development only*, which lifted deletion protection. They become one
([D-248](90-decision-log.md)).

Two modes that overlap are two things to explain and two ways to be in a surprising state. The
developer flag already exists as the deliberate escape from protection
([D-122](90-decision-log.md)), and it sits at the head of the resolution chain
([D-079](90-decision-log.md)) — where a posture belongs.

### U24 · `Cleanup` is the repair surface for what deliberate non-tidying leaves behind

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
    A["park then purge · D-123"] --> R["residue"]
    B["orphaned overrides · D-156"] --> R
    C["values whose edge is gone · D-159"] --> R
    R --> CL["Cleanup · shown, removed deliberately"]
```

*Cleanup was meant for tidying — nodes that have no connections any more, or settings that broke
because something was deleted* ([D-247](90-decision-log.md)). It is **not a feature but a repair
surface**, and this concept needs one precisely because so much is derived.

Each of the three sources above was decided as *leave it alone rather than tidy it silently*, which
is the right call at the moment of the change and leaves residue over years. **Cleanup is where the
residue is shown and removed deliberately** — never automatically.

**Built on 2026-08-28 as the third submenu page**, with the three sources above and one button per
row ([D-479](90-decision-log.md)). *It had been decided five days earlier and stood unbuilt while the
three sources went on collecting, until the owner pointed back at his own decision.* **One place
measures and removes, and the command line now uses the same one** — two copies of a query are the
road along which a correction reaches only one of them.

Measured on the running installation the day it was built: **0 orphaned settings, 0 orphaned labels,
7 values whose edge is gone, 0 nodes without connections** ([D-479](90-decision-log.md)). *The seven
are real residue, and they are **shown and not removed** — which is what «deliberately» means.
Orphaned labels are measured but not offered for removal, because [D-247](90-decision-log.md) does not
name them.*

⚠️ **One source has stopped producing since, and it was a fault rather than a policy.** *A record
without its node must not exist, and the final deletion now enforces it: it takes the records and
their values with it ([D-485](90-decision-log.md)), which it never had although its own docblock had
promised exactly that from the beginning. The owner, when a find turned into a rule: «that is exactly
the reference we know and laid down. We said: **as long as a reference is still there, a node cannot
be finally deleted.** So a record without a node would be unthinkable. And if one wants to delete it
and takes the risk, then the data must be deleted **with** it … otherwise nobody knows how that
record is to be read at all.» The **history** deliberately does not go with it: what disappears is
data, not the message that it existed ([D-485](90-decision-log.md)).*

⚠️ *Not built: the tidying of the log itself. Its gate needs the conflict resolver, which is a surface
of its own ([M6](70-migration.md)) and does not exist —
[U36](#u36--a-changelog-entry-goes-only-where-nothing-hangs-on-it-any-more)
([D-479](90-decision-log.md)).*

### U25 · Two legacy constructs retired, with nothing to put in their place

| Retired | Why, and what took over |
|---|---|
| **`Fill Model Data`** | it injected test data into the tree; data packs ([D-175](90-decision-log.md)) and the type's own sample value ([D-240](90-decision-log.md)) do that now, so it dissolves with nothing left over |
| **`node_presentation`** | *you could assign icons or something with it, I am not even sure* — the owner could no longer name its purpose |

Both by [D-250](90-decision-log.md). ⚠️ *A construct nobody can any longer say what it was for is
exactly what should not survive a restart* ([PR-1](../../CLAUDE.md)): legacy is quoted, never
inherited. If a need turns up later it will arrive with a reason attached.

### U36 · A changelog entry goes only where nothing hangs on it any more

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
    D["a cut-off date"] --> F["the filter · which rows are in scope"]
    F --> G["the gate · an unresolved conflict from that period?"]
    G -->|yes| K["the entries stay"]
    G -->|no| X["they may go"]
```

*As long as I have conflicts I may not delete those sentences. **That is a dependency and we have to
obey it.** Otherwise we have a problem there. And only when the old data are on the new version may
the history data be deleted as well … once the conflict is resolved the changelog may go too —
**because then it is not sensitive any more*** ([D-473](90-decision-log.md)).

**So the date is the filter and the dependency is the gate.** Resolving old data against a changed
model needs *the changes*, which is what the log holds — three decisions that had never been put
together as one dependency ([D-473](90-decision-log.md)). And the gate is computable today: on the
running installation **one record of 25** stood on an older version than its node, so for that period
the log may **not** go. *Not a thought experiment.*

| | |
|---|---|
| a whole group | goes only if **every** one of its rows lies at the cut-off date or before it — the owner's own rule, and the only correct shape technically, because a group is read as a whole and half a group would be worse than a deleted one ([D-473](90-decision-log.md)) |
| a **created** row | **stays, permanently.** *I would leave the create in — and not only the create but every update that is run — and **declare it undeletable*** ([D-474](90-decision-log.md)) |
| the same shape elsewhere | a parked node needs its `parked` row or it cannot be fetched back at all ([D-123](90-decision-log.md)). *Measured: 2 rows of 9041.* **A row may go when nothing hangs on it any more** is the general rule that follows from his special one ([D-473](90-decision-log.md)) |

⚠️ **Undeletability is *declared at the row*, not computed when something is deleted, and that is the
load-bearing half** ([D-474](90-decision-log.md)). *A delete condition has to be formed correctly by
every caller; a declaration sits on the row and holds for everyone who reads it. It is the same shape
as the gate above — there the dependency, here the announcement.* **What it buys on the node page:**
the creation date is derived from the first changelog row ([D-080](90-decision-log.md)), so without
this it would one day quietly stop being shown.

⚠️ *What «every update that is run» covers was left open for an hour and then resolved by the owner
himself: he meant the **software's release**, not every model change a person makes
([D-476](90-decision-log.md)). So the 1690 rows about keys that no longer exist are model changes and
are **not** undeletable — and a release entry does not exist yet at all, since the schema steps write
nothing into the log.*

⚠️ *The conflict resolver this gate waits for is a surface of its own ([M6](70-migration.md)) and is
not built, which is why the log tidying is the half of
[U24](#u24--cleanup-is-the-repair-surface-for-what-deliberate-non-tidying-leaves-behind) that is
missing.*

### U37 · Before a release update a backup is compulsory and it is downloaded

*As a boundary condition and rule I would still like: before an update a backup must always be made.
**That is compulsory.** The user is forced to download it* ([D-475](90-decision-log.md)).

**A boundary condition, not a recommendation**, and the owner drew its scope himself: it is about
*changes that come in with a new release*, not about the schema steps of our own development phase,
which are tests and concept work ([D-475](90-decision-log.md)).

| | |
|---|---|
| **downloaded**, not «put on the server» | a backup on the same installation helps against a failed schema step, not against what people really meet — an installation that no longer starts |
| **forced** | the update does not begin before the file is with the user |
| what it replaces | rolling back means *play the backup back in*, never *play the log backwards* ([D-475](90-decision-log.md)) — and a backwards run is impossible anyway, because our own steps destroy what they change |

⚠️ **And it hangs on something that does not exist: there is no export function at all**, measured
([D-475](90-decision-log.md)). *So the export is the **precondition** of every update path, and this
is the first decision that forces it instead of wishing for it. Where export and import live is
[OQ-122](91-open-questions.md), with the owner's own leaning: think about it on the configuration page
first.*

### U38 · An icon button wears a glyph, and a glyph in the name slot is a word

*Icons boxed again and misaligned. Besides, the icons on the settings page could be a little bigger*
([D-486](90-decision-log.md)).

⚠️ **Measured first, and the obvious cause was not the cause.** *Buttons are produced in **one** place
and **0 of 83** icon buttons carried a border, a background or a shadow. The docblock held and the
stylesheet held — there were no new copies, although a new copy was exactly the cause the previous
time ([D-486](90-decision-log.md)).*

**The two boxes belonged to buttons that were not icon buttons.** Five save buttons handed in a
diskette glyph, **four of them in the label slot** rather than the glyph slot — and a glyph in the
label slot is a **word**: drawn as text, rightly without the borderless class, and it made the
accessible name of those buttons the diskette itself. *Repaired structurally rather than by
attention: a named constructor for saving, so the mistake cannot be typed again*
([D-486](90-decision-log.md)).

| | |
|---|---|
| the size | **one place** computes it, and the detail area adds **3px** — an addition and not a factor, so whole pixels come out. The tree stays as it was ([D-486](90-decision-log.md)) |
| the custom property for it | was **dead everywhere**: a hard-wired size with the same weight won, so a control the owner tried out himself moved no glyph at all ([D-486](90-decision-log.md)) |
| the installation page | **has no icons** and does not load the stylesheet. *Its help text about «the glyphs in the tree and on its buttons» was untrue for the buttons until that day* ([D-486](90-decision-log.md)) |

⚠️ *And its check was green in its first version against precisely the places it was written for: the
markup was read in the wrong encoding, so the four bytes of the glyph came back as six letters and
«contains a letter» was the criterion. Corrected, it found **31 boxes and 31 nameless buttons**
against the old state ([D-486](90-decision-log.md)).*

---

## Entering data

### U26 · The test-data mark governs front-end visibility and nothing else

*In the data one can mark that these are test data — not yet visible in the front end, used only for
testing* ([D-241](90-decision-log.md)).

| | A record carrying the mark |
|---|---|
| front end | **not shown** |
| administration | shown like any other record |
| uniqueness | counts, exactly as any other ([D-154](90-decision-log.md)) |
| model changes and migrations | travels like any other |

⚠️ **Deliberately no further special-casing.** A second class of record would have to be known to
every code path, which is the cost this concept has refused everywhere else. **One flag, one
effect.**

The preview reads the marked records as its middle layer — real data, test-marked records, then the
type's own sample ([D-240](90-decision-log.md)) — but that is a *use* of the flag, not a second
effect of it.

### U27 · The editor supplies the data; visitor entry is foreseen without a use case

*Basically the editor provides the data for now. It may be that we also collect user data — I have
no use case for it yet* ([D-258](90-decision-log.md)).

That costs nothing to leave open, because the mechanism already exists: **editable** is a
circumstance of a renderer ([D-018](90-decision-log.md)), so a field can be declared enterable by
the visitor and they get an input. Foreseen, and unused.

⚠️ **What is deliberately not decided with it:** who may, what is checked, how abuse is prevented.
Those questions belong to the first real use case; inventing them now would produce rules nobody can
test against anything.

---

## Blocks

Dictated by the owner on 2026-08-23 from what he already runs on his own site: printed circuit
boards from open-source projects, their parts lists, retro PC components and the tests that belong
to them, manufacturers, recipes.

### U15 · Small blocks, one node each — the reference does the joining

```mermaid
flowchart LR
  B1["block: enter the board"] -->|reference by id| R["parts list"]
  B2["block: show the parts list"] --> R
  B1 -.Link.-> B2
```

The owner: *I would rather not build one huge block that queries all the data for several connected
nodes. I would enter the board, which has the parts list as an attribute — but only an id is set
there. Further down the page, at a suitable place, I would then display the parts list.*

**This is [D-105](90-decision-log.md) arriving in the front end.** A reference is drawn as label plus
link and **does not descend**, so the board block cannot pull the parts list in even if someone
wanted it to. Whether the list appears further down the same page or on another one is then the page
builder's free choice — and in both cases it is **a second block**, never a larger first one.

The link needs no dynamism: it is a label and an address, so *many things can be done after saving*,
as the owner put it.

### U16 · Comparison resolves to the nearest common ancestor

The owner compares mainboards: P4 against P4, but also 286 against 386 against 486. *It is a
comparison of similar data types — similar, because there may be differences in detail. Then I would
have to fall back on the parent node and compare only what they have in common, and show the rest
separately.*

**That is not a workaround, it is the tree doing its job.** A node's type **is** its inheritance line
([D-041](90-decision-log.md)), so the nearest ancestor covering every subject **is** the set of
shared attributes. The block does not have to guess what is comparable:

1. Walk up until all subjects lie beneath one node.
2. Compare the attributes found there, side by side.
3. Show what each subject additionally carries beneath its own column.

**Confirmed 2026-08-23**, with a refinement: what is not comparable is moved **below** the comparison, and may additionally be **hidden behind a disclosure** and shown only on request ([D-207](90-decision-log.md)). The shared attributes are what the block is for; letting specialities interleave would bury the rows someone came to read.

### U17 · A list is a node plus a restriction

*A list of manufacturers — in a larger sense persons, or organisations rather — I would like to show
as a list. So I choose a node, and restrict the data I want to display.*

Two inputs, both already modelled: **which node** supplies the records, and **which of their
attributes** are shown. No new concept — the restriction is a choice of attributes, and the drawing
of each is the ordinary renderer under the display purpose ([D-168](90-decision-log.md)).

### U28 · Three surfaces, three jobs

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
    A["admin · what a thing is"] --> T["our tables"]
    B["block · what this page shows"] --> P["the post"]
    F["front end · only draws"] --> R["renderers from the model"]
```

The owner was unsure where the line ran — *I am not so confident about the boundary between front
end, Gutenberg and the settings page* — and his own description drew it
([D-253](90-decision-log.md)).

| Surface | Decides | Stored in |
|---|---|---|
| **Admin** | what a thing **is** — attributes, types, which renderers, defaults | **our tables** ([AR-1](../../CLAUDE.md)) |
| **Block** | what **this page** shows — which node, which record, which fields are visible | **the post**, WordPress-standard, as the owner asked |
| **Front end** | nothing of its own; it draws what the block names, with the renderers resolved from the model, exactly as the admin does | — |

⚠️ **And this withdraws the last link of [D-226](90-decision-log.md).** The resolution chain had been
extended by an *occurrence in a block*, so that a value and its colour rings could stand side by
side. The owner did not recognise the need — *the data already have a renderer, you would just fetch
it from the registry in the front end too* — and [D-226](90-decision-log.md) itself had already made
*value, plus colour rings* **one setting at the use site**. **So the chain ends at the use site**,
and there is no hole in the block, because nothing belongs there. Listing one attribute twice in a
block is dropped with it: two occurrences would now render identically.

⚠️ *That also makes the storage question harmless.* Visibility is a statement about **this page**, so
losing it when a page is copied is annoying and breaks nothing — where a renderer choice living in
post content could have gone missing from the model.

**The block selects and hides; it does not choose renderers** — with the one deliberate exception in
U30.

### U29 · One block for a node, and the layout follows the content

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
flowchart TB
    N["a node in a block"] --> A["member with one value → a field"]
    N --> B["member with several → a table"]
    A --> C["small fields compact on one line"]
```

Asked whether the parts-list example — a form on top, a table of positions below — was a second kind
of block, the owner said it is **the standard**: *basically we have only one block that can display
a node, and how it is displayed follows from the contents* ([D-255](90-decision-log.md)). Simple
rules: fixed data give a form; a multiplicity gives a table beneath it; small fields go compactly on
one line, the same compact renderer as on the settings screen ([D-245](90-decision-log.md)).

⚠️ **There is exactly *one* compact renderer, and «the same» above is now literal.**
[D-245](90-decision-log.md) had named two — *compact horizontal* and *compact vertical* — and they are
one renderer with two properties: a horizontal/vertical switch and labels that can be turned off, with
**label on** and **horizontal** as the defaults ([D-471](90-decision-log.md)). *Two renderers differing
in one axis are two registrations, two names in the `renderer` key and two places where the same
compactness is maintained.* The renderer and its properties are owned by
[30 Renderer](30-renderer.md); what belongs here is only that a block naming it names **one** thing.

⚠️ **That is a rule of the node renderer, not of the block.** [D-234](90-decision-log.md) stands —
the block selects, the renderer draws — and [D-256](90-decision-log.md) corrected the placement
once: the **composite** renderer draws one composed *value* (`2,7 kΩ ±5 %`), while a form above and a
positions table below is a whole *node* with its attributes. Node renderer and page renderer are the
same renderer ([D-233](90-decision-log.md), [D-091](90-decision-log.md)), used in different places.

**Hiding works one level coarser than assumed:** not only single fields but **whole parts** — leave
the form out and show only the positions, or show one of two multiplicities and not the other. For a
composition that is right in substance too, since it exists only in connection with its whole.

**Other block shapes stay possible and are not now:** the owner named a timeline as something that
would arrange the same data differently.

### U30 · A block may override presentation, and such an override is page-local

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
    M["use site · presentation settings"] --> D["what is normally drawn"]
    B["block override"] -.for this page only.-> D
    B --> H["lives in the post's HTML"]
    H --> Q["nothing can query it"]
```

This reopens what U28 closed, and does so deliberately ([D-257](90-decision-log.md)). The owner:
*hiding matters to me in any case, but I do not see why we should forbid something that gives us
more flexibility later.* The stronger argument is his: the presentation is fixed in the back end and
seen in the preview ([D-231](90-decision-log.md)), and the block is where one adjusts **for this one
page** — forbidding it means changing the model instead, which hits every other page.

⚠️ **The cost is stated rather than hidden.** The override lives in the post's HTML, where **nothing
can query it**. *Where is this attribute shown as a colour code?* becomes unanswerable, and the
conflict resolver ([D-054](90-decision-log.md)) will not find it when the model changes. So it is
allowed **and labelled page-local** — a deliberate choice rather than a trap.

**Open:** *which* settings may be overridden. **Visibility is certain**; the rest is a list to be
drawn up when the block is built, on the owner's own instruction
([D-257](90-decision-log.md)).

### Candidates not yet dictated

- **A test belonging to a component.** The owner: *a card always has a test as well — not sound
  cards, but graphics cards or mainboards.* Whether that is its own block or a section of the
  comparison is open.
- **Nesting.** A recipe resembles a parts list but may contain **sub-recipes**; the nesting is the
  same shape as board → parts list. Whether that needs anything beyond U15 is open.

## Still to be dictated

- How blocks are assembled. *Partly answered by U29 — one block per node, the layout following the
  content — but not the composing itself.*
- ~~What is special in Gutenberg and in the front end.~~ → **answered by U28**
  ([D-253](90-decision-log.md)), with the server-side rendering of blocks decided alongside it
  ([D-254](90-decision-log.md), in [30 Renderer](30-renderer.md)).

### U31 · An explanation lives in a question mark, and it is written for somebody who has not read the concept

The owner, looking at a screen of grey paragraphs: *banish the text into a question-mark icon and
make it user-friendly* — and then: *we can make that a rule, by the way.*

```mermaid
flowchart LR
    H["a heading"] --> Q["? · the explanation, on hover"]
    H --> C["the panel itself, unobstructed"]
```

**Two halves, and the second is the one that matters.**

| | |
|---|---|
| **Hidden** | a panel explains itself in a `?` beside its heading, never in a paragraph above it. Six panels stacked with six paragraphs is a screen where the paragraphs are the content. |
| **Rewritten** | the sentence has to be readable by somebody who has never opened `docs/NewConcept/`. *Each value is drawn by the renderer its key asks for; the chain runs installation → model root → ancestors → node* is **true and it is not an explanation** — it names four mechanisms and answers no question a person has. |

⚠️ **The test for the text: does it answer a question somebody actually has in front of that panel?**
*A value not set here is inherited from further up — the column on the right says where it came from*
passes. *The chain runs installation → model root → ancestors → node* does not, and it was the same
fact.

⚠️ **A `title`, not a disclosure that opens.** Nothing depends on reading it, and a panel that opens
adds a thing to click to every heading on the screen. *Where an explanation is genuinely required
before acting, it is not a hint — it is a step, and it belongs in the flow.*

⚠️ **The icon is a Dashicon and not a `?` character**, for the reason
[D-380](90-decision-log.md) settled for the bin: a punctuation mark takes the weight of the body font
and reads as a hairline beside a 17px glyph.

⚠️ *This is not `R1` being bent. A heading and its hint are **software strings** belonging to the
boundary ([AR-2](../../CLAUDE.md)) — the same class of thing as the word on a button — and no model
data passes through them.*

## Die vierte Rückstandsquelle — Stand 2026-08-29

Gehört zu **U24**, das die Cleanup-Fläche besitzt; hier steht nur, was 2026-08-29 dazukam.

[D-494](90-decision-log.md): **ein Datensatz, dessen Knoten es nicht mehr gibt.** Der Eigentümer,
2026-08-28: *«das wäre genau das, was auf der Cleanup-Seite auch auftauchen müsste … da ist ein
Record, der hat keinen angehörigen Knoten mehr. Und dann muss der Benutzer sagen: **entweder Daten
löschen oder Knoten wiederherstellen**.»*

⚠️ **Sie ist nicht «auch noch so ein Fall».** *Sie ist die einzige der vier, die einen ausdrücklich
**verbotenen** Zustand misst — [D-485](90-decision-log.md): «ein Record ohne Knoten wäre
undenkbar». **Ein Verbot beseitigt keinen Rückstand**, und ohne diese Messung wäre der Satz eine
Behauptung ohne Prüfung. Gemessen auf der Installation des Eigentümers: **genau ein Fall.***

⚠️ **Der zweite Knopf fehlt, und die Seite sagt das hin.** *«Knoten wiederherstellen» wäre der
vorhandene `restore()` — **solange der Knoten geparkt ist**. Dann steht er aber noch in `nodes` und
seine Datensätze sind gar kein Rückstand. **Wer in dieser Liste steht, ist endgültig weg**, und ob
er sich aus dem Changelog zurückbauen lässt, ist [OQ-128](91-open-questions.md).*

⚠️ *Darum trägt die Gruppe den Satz «bringing the node back is not offered: it is gone for good,
not parked». **Ein Schirm, der eine verlangte Wahl verschweigt, sieht fertig aus** — und der
Eigentümer hätte den fehlenden Knopf für ein Versehen halten müssen statt für eine offene Frage.*

---
