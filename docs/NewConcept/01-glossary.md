---
title: Glossary
status: draft
round: R1 (in progress)
last_updated: 2026-08-23
---

# Glossary

One word per concept. Every term here is used in exactly this sense throughout
`NewConcept/`, and nowhere in a second sense.

**Why this file exists.** The previous round drifted between *Eigenschaft*, *Attribute*,
*Parameter*, *Slot* and *Property* for overlapping ideas, and the model became impossible to
hold in one head. This round has already produced one collision of its own — *Label* was used
both as the umbrella for all display texts and as the name of one particular role
([D-023](90-decision-log.md)) — which is what prompted writing this down.

## The two halves

| Term | Deutsch | Means |
|---|---|---|
| **Model** | Modell | Everything that *describes*: nodes, relations, settings, labels. |
| **Data** | Daten | Everything entered *afterwards*, as a whole. |
| **Record** | Datensatz | One single piece of it ([D-176](90-decision-log.md)). |

The two do **not** share an identity space: model ids and record ids are allocated
independently ([D-164](90-decision-log.md)).

## The model

| Term | Deutsch | Means |
|---|---|---|
| **Identity** | Identität | The common base of everything the model persists. Carries the `id` and a `version` — and nothing else ([D-080](90-decision-log.md)). `Node` and `Relation` are its two shapes and draw ids from **one** space, which is what lets `owner_id` be a single real foreign key ([C11](10-domain-core.md), [D-164](90-decision-log.md)). |
| **Node** | Knoten | A thing in the model. All nodes are fundamentally the same kind ([V5](00-vision-and-scope.md)); differences come from configuration, not from subclassing. Exactly four fixed attributes: `id`, `version`, `name`, `path` ([D-082](90-decision-log.md)). |
| **Relation** | Kante, Verbindung | A directed edge from one node to another. **One construct** — inheritance, composition and aggregation are its *kinds*, not separate classes ([D-012](90-decision-log.md)). |
| **Kind** (of a relation) | Art | Which of the three a relation is. **Never chosen** — it is read off the branch the target sits in ([D-161](90-decision-log.md)). Not to be confused with *type*. |
| **Inheritance** | Vererbung | The relation kind that forms the tree. At most one parent per node, acyclic, protected, and the only kind exempt from edge settings ([C9](10-domain-core.md)). A node's **type** *is* its inheritance branch ([D-041](90-decision-log.md)). |
| **Composition** | Komposition | A relation kind whose target belongs to the whole and **is deleted with it** ([C12](10-domain-core.md)). Requires sole ownership: a record with several users cannot be composed into one of them ([D-162](90-decision-log.md)). |
| **Aggregation** | Aggregation | A relation kind whose target is **independent** and survives its whole ([C13](10-domain-core.md)). |
| **Attribute** | Attribut | **A relation, seen from the node that owns it** ([D-031](90-decision-log.md)). Its `kind` is the connection, its `to` is the type, its name and multiplicity and defaults hang on it as name, labels and settings. There is no separate attribute object — the *wrapper* the author edits is a screen, not a table. |
| **Use site** | Verwendungsstelle | A relation, seen as the place where a shared node is used. Configuration that applies to one use only lives here, not on the node ([C8](10-domain-core.md)). |
| **Binding** | Bindung | A named slot in the installation configuration pointing at a node ([D-120](90-decision-log.md)). The engine asks for the slot and never names an id or a node name, so ids may shift freely. Carries **only** the pointer. |

## The three branches

The branch a node sits in is load-bearing: it decides the relation kind that reaches it
([D-161](90-decision-log.md)) and whether it holds data at all ([D-183](90-decision-log.md)).

| Branch | Holds data | Means |
|---|---|---|
| **`Model`** | yes | The things the installation is actually about — parts, orders, recipes. |
| **`Compositions`** | yes | Things that exist only as part of a whole. A contact without its part does not exist ([D-135](90-decision-log.md)). |
| **`Primitives`** | **no** | What models are built *out of*. Values live **inside** the record, by path ([D-232](90-decision-log.md)). Means to an end, never a place anything is kept ([D-185](90-decision-log.md)). Splits one level further, and that split decides the relation kind ([D-193](90-decision-log.md)). |
| ↳ **Data Types** | no | `int`, `text`. The value lives **in the record** — reached by **composition**. |
| ↳ **Constants** | no | Units, currencies. The value is a **reference to a node** ([D-131](90-decision-log.md)) — reached by **aggregation**. |

## Configuration and text

| Term | Deutsch | Means |
|---|---|---|
| **Setting** | Einstellung | A configuration value on an identity: `key` + typed `value`. Conceptually an attribute ([D-011](90-decision-log.md)), stored in its own table. **One construct and one mechanism**, with a reserved namespace for engine-owned keys ([D-084](90-decision-log.md)). |
| **Override** | Überschreibung | A setting or label on a use site that replaces the base value. Resettable to *inherited*, which is not the same as storing an empty value ([D-266](90-decision-log.md)). Stored **sparsely** — only what differs — so a change to the base reaches every use site that did not override it ([D-015](90-decision-log.md)). |
| **Resolution chain** | Auflösungskette | Installation → model root → ancestors → node → use site. Walked **key by key**, so a consumer may take a mix ([D-079](90-decision-log.md), [D-093](90-decision-log.md)). |
| **Label** | Bezeichnung | The human-readable text of an identity, in one **role** and one **locale** ([D-019](90-decision-log.md)). Also carries author-written validator messages, addressed by `path` ([D-158](90-decision-log.md)). |
| **Role** (of a label) | Rolle | Which label this is — the seeded set is `form`, `table`, `symbol`, `help` — `select` left it again with [D-264](90-decision-log.md) ([D-196](90-decision-log.md)). Roles are **nodes**: seeded and extensible ([D-151](90-decision-log.md)). Written **without** a prefix ([D-023](90-decision-log.md)). |
| **Icon** | Icon | A glyph chosen from the installation allow-list. A **setting**, language-neutral ([D-252](90-decision-log.md)). Shown in the tree too ([D-251](90-decision-log.md)). Not to be confused with the `symbol` role. |
| **Base name** | Basisname | `Node.name`. Required, locale-neutral, entered at creation. Display of last resort, **never** a lookup key. Not unique ([D-022](90-decision-log.md)). |
| **Owner** | Eigentümer | The single identity a setting, label or changelog item belongs to. One column, because model ids come from one space. |
| **Data Pack** | Datenpaket | A named, installable set of model content and optionally some data ([D-175](90-decision-log.md)). The shipped seed is simply the pack that comes with the product. May **add** to another pack's branch, never **alter** its nodes ([D-177](90-decision-log.md)). |

## Presentation

| Term | Deutsch | Means |
|---|---|---|
| **Renderer** | Renderer | The only thing that produces display ([R1](30-renderer.md)). PHP ([D-021](90-decision-log.md)). Returns strings, never echoes (**CD-8**). A node carries an **ordered list**: one mandatory, further ones appended ([D-236](90-decision-log.md)). |
| **Purpose** | Zweck | What a render is *for*: display, edit, or **search**. Passed to the renderer in the context; a renderer declares which purposes it serves via `supports()`. **Not** part of the registry key ([D-217](90-decision-log.md)). |
| **Registry** | Registrierung | The one place all renderers register. Two jobs: *give me this renderer* at render time, and *which renderers are eligible for this node* at configuration time ([R12–R14](30-renderer.md), [D-217](90-decision-log.md)). |
| **Variant** | Variante | A fundamentally different presentation — field, spinner, slider. **Own renderer each** ([D-018](90-decision-log.md)). |
| **Circumstance** | Umstand | Level (admin / block / frontend), editable or not, hidden. **Option inside** one renderer ([D-018](90-decision-log.md)). |
| **Converter** | Converter | Turns input into a value and a value into output. Runs on input too where it is invertible ([D-077](90-decision-log.md)) — and it is the converter that silently strips leading and trailing whitespace, because nobody ever meant it ([D-166](90-decision-log.md)). |
| **Validator** | Validator | Checks user input, and may **offer a correction** ([V9](00-vision-and-scope.md)). Several per attribute, each with its own message ([D-158](90-decision-log.md)). Where intent is ambiguous — an interior space — it asks rather than acts ([D-166](90-decision-log.md)). |
| **Preview** | Vorschau | Every node has one. Rendered from a **test data pack**, not from empty defaults, because a filled form shows whether it *reads* ([D-160](90-decision-log.md)). |
| **Reference renderer** | Verweisrenderer | Draws the target label plus a link and **does not descend** ([D-105](90-decision-log.md)). The default for aggregation; composition expands instead. |

## Data, search and change

| Term | Deutsch | Means |
|---|---|---|
| **Search column** | Suchspalte | A normalised column per record, written on save from the shown fields ([D-167](90-decision-log.md)). One normalisation function, shared with duplicate detection. |
| **Projection** | Projektion | A flat table per model, one column per attribute, for the reporting case. A **cache, never a place values live** ([D-165](90-decision-log.md)). |
| **Changelog** | Änderungsprotokoll | Every change, with before and after. It **is** the migration script ([D-061](90-decision-log.md)), and `creation_date` is read from it ([D-080](90-decision-log.md)). |
| **Model version** | Modellversion | Stamped on the record ([D-060](90-decision-log.md)). Numbers **order** events; **shape** decides compatibility ([D-172](90-decision-log.md)). |
| **Conflict resolver** | Konfliktlöser | Where a model that no longer fits its data is reported and settled ([D-054](90-decision-log.md)). Reports rather than blocks — except for data entry against a broken model, which stays barred ([D-157](90-decision-log.md)). |
| **Parking** | Papierkorb | Deletion in two stages: park, then purge ([D-123](90-decision-log.md)). A parked record keeps its `unique` values blocked ([D-154](90-decision-log.md)). Undo reaches exactly as far as the trash ([D-172](90-decision-log.md)). |
| **Backward aggregate** | Rückwärtsaggregat | A computed value read from the things that point *at* this one. Calculated at read time, in no index, therefore **not searchable** ([D-140](90-decision-log.md)). |
| **Expression** | Formel | A computation over values ([D-130](90-decision-log.md)). ⚠️ In German **Formel**, never *Ausdruck* — that word is taken by the next row. |
| **Printout** | Ausdruck | A **frozen** rendering of a report, kept as a document ([D-242](90-decision-log.md)). A report is live and recomputes; an invoice is a printout, not a report. |

## Code shape

| Term | Deutsch | Means |
|---|---|---|
| **Core** | Kern | The domain model and everything reasoning about it. Calls no WordPress function (**CD-1**). Declares the interfaces it needs; the boundary fulfils them ([D-170](90-decision-log.md)). |
| **Boundary** | Anschlussschicht | The WordPress-facing layer. **Not underneath the core — around it**, with every arrow pointing inward ([D-171](90-decision-log.md)). It translates; it does not decide ([D-170](90-decision-log.md)). |

## Process

| Term | Means |
|---|---|
| **Owner statement** | Something the project owner said, written down verbatim in meaning and given an id (`V<n>`, `C<n>`, `R<n>`, `P<n>`, `I<n>`, `U<n>`). The raw material of the concept. |
| **Decision** | An entry `D-<nnn>` in [the decision log](90-decision-log.md). **Nothing is decided until it is there.** |
| **Open question** | An entry `OQ-<nnn>` in [open questions](91-open-questions.md). Where anything undecided goes, instead of being invented. |
| **Harvest** | Taking content out of `legacy/` through a reviewed sheet, item by item, with an explicit *take / rework / drop*. Never inheritance. |
| **Seed sketch** | One of the four original restart files. Input, not concept. |

## Rejected words

Kept so a discarded term cannot quietly return under another name.

| Word | Why not |
|---|---|
| *Eigenschaft*, *Parameter*, *Slot*, *Property* | All meant roughly *attribute* in the previous round, in slightly different and drifting senses. Use **attribute**. |
| *Translation* | Rejected by the owner ([I8](40-i18n.md)): these are not translations in the software sense, they are the same name in another language. Use **label**. |
| *`label.form`* as a role name | The `label.` prefix made *label* look like both the umbrella and the role ([D-023](90-decision-log.md)). The role is **`form`**. |
| *Type node* / *domain node* / *value node* | Presume node subtypes, which [V5](00-vision-and-scope.md) argues against. |
| *Primary key* for an attribute | The primary key is the **`id`** ([D-055](90-decision-log.md)). Use **`unique`** ([D-115](90-decision-log.md)). |
| *Bestand* | Proposed as a collective for the data half and rejected by the owner as unnecessary ([D-176](90-decision-log.md)). **Daten** already reads perfectly well. |
| *Definition* as a branch name | A model node is a definition too, so the word separates nothing. The branch is **`Primitives`** ([D-185](90-decision-log.md)). |
| *hide* as a flag on a node | The legacy control that went unused because it sat on the wrong object. What may be picked belongs to the **use site** ([D-181](90-decision-log.md)). ⚠️ **Built as a node column on 2026-08-27 and removed again on 2026-08-28** — the owner: *«I do not simply create a model node and then say I will not draw it»*. It is one column on the **edge**: [Hiding](10-domain-core.md#hiding--hide-is-one-column-and-it-is-on-the-edge) ([D-467](90-decision-log.md)). |
| *View* as a catch-all for anything reusable | A **view** is a deferred *calculation* belonging to no node ([OQ-069](91-open-questions.md)); a **report** is prepared *output* — an exported parts list, an invoice — and belongs to the renderer side. Two concepts, two homes, never one word ([D-201](90-decision-log.md)). |

## Referenz — drei Fragen, ein Wort

**Der Eigentümer hat es gerochen, bevor es jemand aufgeschrieben hatte:** *«du verwendest immer andere Begriffe `***_ref` — das zeigt, dass da Unklarheiten bestehen. Wollen wir die mal auflösen?»* Diese Stelle besitzt die Familie ([D-469](90-decision-log.md)); jede andere Erwähnung zeigt hierher.

⚠️ **Die Unklarheit ist keine Nachlässigkeit, sie ist ein Zusammenfallen: drei verschiedene Fragen tragen dasselbe Wort, und zwei ihrer Antworten treffen sich am Zweig `Constants`.**

### 1 · Was für ein Wert ist das? — `SimpleType`

| Fall | Bedeutung | Stand |
|---|---|---|
| `NodeRef` (`node_ref`) | der Wert **ist** ein Zeiger auf einen Knoten im Modell | entsteht, wenn ein Feld in den Zweig `Constants` zeigt ([D-232](90-decision-log.md)); drei Renderer lesen ihn — `reference` zum Zeigen, die zwei Chooser zum Wählen. **Gemessen: 4 von 32 Feldern** |
| `UserRef` (`user_ref`) | ein Zeiger auf einen **WordPress-Benutzer**, als Text gespeichert (`P4d`, [D-171](90-decision-log.md)) | ⚠️ **kein Renderer** — gemessen fällt er auf den Auffang (`TypedFieldsTest`). Von den 11 Typen ist er der einzige ohne einen; **das ist die Abweichung, die [D-484](90-decision-log.md) sichtbar machen soll** |

### 2 · Wo liegt der Wert? — `Storage`, entschieden vom **Zweig**

| Zweig | Speicherort |
|---|---|
| `Model` | `ExternalReference` |
| `Compositions` | `OwnRecords` |
| `DataTypes` | `InsideTheRecord` |
| `Constants` | `NodeRef` |

⚠️ *`ExternalReference` hat **einen** Leser, und der ist ein Test. Der Zweig `Model` soll so speichern und nichts tut es bisher — kein Fehler, aber es heisst, dass die Bedeutung dieses Falls unerprobt ist.*

### 3 · Was wird gezeichnet?

| Name | Was er wirklich hält |
|---|---|
| `TypedValue::ofReference()`, Spalte `value_ref` | **der gespeicherte Zeiger selbst** — eine Id |
| `ReferenceRenderer` | zeichnet einen Zeiger, und zwar als **Label des Ziels** ([D-105](90-decision-log.md)) |
| `Surroundings::$refersTo` | ⚠️ **ein Label, kein Zeiger.** *Hier lügt das Wort, und `CD-9` verlangt umbenennen. Vorschlag: `targetLabel`* |
| `Surroundings::$refersToARecord` | ein `bool` über die **Art des Ziels** — ein Record statt eines Knotens ([D-445](90-decision-log.md)) |

### Jede Stelle im Schema, die eine Knoten-Id hält — gemessen 2026-08-28

**Der Eigentümer hat den Fall gestellt und nach den übrigen gefragt:** *«wir haben eine Knotenreferenz, die sagt: ich bin ein Attribut und ich habe als Datentyp einen Knoten. … Nenn mir doch einfach mal die anderen Fälle, in denen das vorkommt.»*

⚠️ **Zwei meiner Sortierungen hat er verworfen, beide zu Recht — und die zweite war schlimmer.**

*Zuerst hatte ich nach **Bedeutung** getrennt («wem gehört es» gegen «wie hängt es zusammen»), und er: «warum trennst Du die `relations.to_id` von der `from_id`? **Das sind alles Referenzen auf Knoten.**» Richtig — das war eine Erzählung über die Spalte, keine Eigenschaft von ihr.*

*Dann schrieb ich eine Spalte «Zeile ohne ihr Ziel» mit den Werten «bedeutungslos» und «veraltet», und er: **«Du machst Dir wieder Schlussfolgerungen, die wir hier gar nicht beschliessen.»** Auch richtig, und das ist der schwerere Fehler: **ich habe eine Wertung als Messung ausgegeben.** Die Spalte ist entfernt. Was bleibt, ist gemessen.*

#### Die Spalten

| Spalte | hält die Id von | Fremdschlüssel |
|---|---|---|
| `relations.from_id` | einem Knoten | → `identities` |
| `relations.to_id` | einem Knoten — *dem **Typ** des Feldes* | → `identities` |
| `settings.owner_id` | einem Knoten **oder** einer Kante | → `identities` |
| `labels.owner_id` | einem Knoten oder einer Kante | → `identities` |
| `changelog.owner_id` | einem Knoten oder einer Kante | → `identities` |
| `records.node_id` | einem Knoten | **keiner** |
| `record_values.edge_id` | einer Kante | **keiner** |
| `labels.role_id` | einem Knoten — *die **Rolle** eines Labels ist ein Knoten* | **keiner** |
| `settings.value_ref` | einem Knoten — *der **Wert** ist die Id* | **keiner** |
| `record_values.value_ref` | einem Knoten — *der **Wert** ist die Id* | **keiner** |
| `nodes.path` | einer **Kette** von Knoten-Ids, gepunktet | Text — kann keinen haben |
| `settings.path`, `labels.path` | einer Kette von **Kanten**-Ids | Text — kann keinen haben |

#### ⚠️ Was ein Fremdschlüssel hier **nicht** leistet, und das ist der Kern

**Jeder der sieben zeigt auf `identities`, nicht auf `nodes`.** *Gemessen: `identities` hat **28670** Zeilen, `nodes` hat **91** — eine Id wird einmal vergeben und nie wieder ([D-340](90-decision-log.md)). **Ein Fremdschlüssel garantiert also nur, dass diese Id einmal ausgegeben wurde. Er garantiert nicht, dass der Knoten noch existiert.***

*Damit ist der Unterschied zwischen «abgesichert» und «nicht abgesichert» viel kleiner, als er aussieht — und die Frage des Eigentümers, «wo ist denn das Loch für Knoten, die genutzt werden», hat die Antwort: **es gibt keins, weil der Fremdschlüssel benutzt und gelöscht nicht unterscheidet.***

#### Wie es heute wirklich steht — Zeilen, deren Ziel es nicht mehr gibt

| Spalte | solche Zeilen |
|---|---|
| `relations.to_id`, `relations.from_id` | 0 |
| `settings.value_ref` | 0 |
| `labels.role_id` | 0 |
| **`records.node_id`** | **1** |
| **`record_values.edge_id`** | **7** — dieselben, die die Cleanup-Seite zeigt ([D-479](90-decision-log.md)) |

⚠️ *Die vier oberen sind sauber, aber **nicht weil** ein Fremdschlüssel sie hält.*

⚠️ **Und die acht Zeilen sind eine einzige Leiche, zweimal gezählt — der Eigentümer hat es erraten, bevor es gemessen war.** *Er: «wir haben eine Menge Datenmüll noch da, dadurch dass der Baum gewachsen ist. Ist es vielleicht das Problem, dass die einfach nicht richtig gefüllt sind und Du jetzt denkst, das werden irgendwelche Regeln?» **Genau so war es.** Gemessen: alle acht gehören zu **Record 879**, angelegt am 2026-08-28 um 08:30, der auf Knoten 23878 zeigt — und zu diesem Knoten gibt es **keinen einzigen Changelog-Eintrag**, er wurde also von Hand aus der Tabelle gelöscht und nicht über die Anwendung. Die Werte lauten `42`, `4k7`, `a@b.example`, `#663399`: die Testdaten-Signatur von `package7-check.php`. **Es ist Abfall aus einem Prüflauf, und ich habe daraus Struktur gelesen.***

⚠️ *Was das für die Fremdschlüssel heisst: **der Beleg dafür, dass es «in der Praxis vorkommt», ist weg.** Es kam vor, weil jemand — ich — Knoten unter der Anwendung wegräumte. Ob die drei Spalten trotzdem einen bekommen sollten, bleibt eine Frage, aber ohne diesen Beleg und damit kleiner, als sie aussah.*

#### Sein Fall, geschlossen

*«Ein Attribut hat als Datentyp einen Knoten»* steht in `relations.to_id`. Die Spalte existiert, sie hält die Id, und sie hat einen Fremdschlüssel auf `identities`. *Sie gilt für **jedes** Feld, nicht nur für eines mit einem Konstanten-Ziel — der Typ eines Feldes ist immer ein Knoten ([D-011](90-decision-log.md): ein Attribut ist eine Relation).* **Gemessen zeigt heute keine davon ins Leere.**

#### Ein Wort, überall

**Seine Regel:** *«immer wenn es eine Knotenreferenz ist, also auf eine Id eines Knotens, dann sollte es auch gleich heissen. Aber nicht dass eines `NodeRef` heisst und das andere `NodeReference`.»*

| hiess | heisst |
|---|---|
| `Storage::NodeReference` (`node-reference`) | **`Storage::NodeRef` (`node-ref`)** — 3 Stellen, eine davon ein Test; der Wert ist nirgends gespeichert, geprüft |
| `SimpleType::NodeRef` (`node_ref`) | **bleibt** — `node_ref` steht als Zeichenkette in der Datenbank |

⚠️ *Schreibweise, nicht Bedeutung (`CD-9`). Die Bedeutungsfrage liegt in [OQ-125](91-open-questions.md).*

---

### Die zwei Stolperstellen, die bleiben

⚠️ **`SimpleType::NodeRef` gegen `Storage::NodeRef`.** *Fast dasselbe Wort für «der Wert **ist** ein Zeiger» und «der Wert **liegt** als Zeiger». Beide werden am Zweig `Constants` wahr, weshalb man sie nie auseinander lesen muss und deshalb nie merkt, dass sie Verschiedenes sagen.* **Was zu klären ist:** [OQ-125](91-open-questions.md).

⚠️ *Und was hier **nicht** hingehört, damit es nicht wieder mitwandert: `records.node_id` ist kein Referenztyp, sondern die Spalte, mit der ein Datensatz seinen Knoten nennt ([D-460](90-decision-log.md)s Umbenennung, Schema 9).*

## Dictation notes

The owner statements were dictated, and speech recognition produces a few recurring substitutions.
Recorded so that a later reader — or a fresh session quoting the raw statements — does not stumble
over them.

| Heard | Means |
|---|---|
| *Rennrad*, *Renntrainer*, *Rennerad*, *Ränderer*, *Intranderer*, *eränderer* | **Renderer** |
| *Renderengten* | **Renderer registry** |
| *ohne IT* | **owner_id** |
| *gelockt* | **geloggt** — logged, not locked ([C85](10-domain-core.md)) |
| *Blogs* | **blocks** |
| *Hutknoten* | **root node** |
| *Bombe*, *Bombes* | **BOM** — parts list |
| *OML* | **UML** |
| *Beschichtung* | **Schachtelung** — nesting |
| *vermisst* | **vermischst** — mixing, not missing |
| *schonenswert*, *wieder schonenswert* | **Resistance** — resistance value |
| *Waldkatz* | **wildcard** |
| *Fahrrad*, *Nanofahrrad* | **Farad**, nanofarad |
| *Lebensraum* | **Namensraum** — namespace |
| *Applikation* | **Aggregation** |
| *Heid* | **hide** |
| *Rennrad*, *Ränderer*, *Nottreff* | **Renderer**, **Renderer**, **`node_ref`** — *seine eigene Ansage: «Rennrad ist übrigens gleich Renderer immer»* |
| *Track and Drop* | **drag and drop** |
| *Andofall* | **Undo-Fall** |
| *Inumwerte* | **Enum-Werte** |
| *aufplänen* | **aufblähen** — to bloat |

## ⚠️ Attribute and setting — moved out

The distinction between the two, the truth table, the worked example and the storage places used to
live here. They are now [`02-field-and-setting.md`](02-field-and-setting.md), because they had
grown to three hundred lines and **a glossary is where a word is looked up, not where an argument is
made.**

**The one line worth keeping here:** *an attribute is a question the model asks its users; a setting is
a decision the modeller makes about a question.*
