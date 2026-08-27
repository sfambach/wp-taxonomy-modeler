# 99 · Index — eine Zeile pro Entscheidung und pro Frage

⚠️ **Erzeugt, nicht geschrieben.** `php scripts/dev/concept-index.php` baut diese Datei aus
[`90-decision-log.md`](90-decision-log.md) und [`91-open-questions.md`](91-open-questions.md).
**Sie ist nie die Autorität** — `PR-3` sagt, entschieden ist, was im Log steht, und eine
verkürzte Entscheidung wäre eine neue, der niemand zugestimmt hat. *Hier steht, **wo** etwas
steht, damit man 432 Entscheidungen überblicken kann, ohne 527 KB zu lesen.*

| | |
|---|---|
| Entscheidungen | **439**, davon **28** ersetzt oder teilweise überholt |
| Offene Fragen | **104**, davon **88** noch offen |

## Offene Fragen

⚠️ *Zuerst, weil sie die Arbeit blockieren und Entscheidungen sie nicht.*

| Frage | Stand | Worum es geht |
|---|---|---|
| [OQ-001](91-open-questions.md) | **offen** | What is in the shared base of node and relation? |
| [OQ-002](91-open-questions.md) | **offen** | If the tree is inheritance only, what are the other edges? |
| [OQ-003](91-open-questions.md) | **offen** | Is `Relation.type` a node or an enum? |
| [OQ-004](91-open-questions.md) | **offen** | Do node subtypes exist at all? |
| [OQ-005](91-open-questions.md) | **offen** | `RendererRegistry` or `RendererRegister`? |
| [OQ-006](91-open-questions.md) | **offen** | Renderer contract: what is the actual method set? |
| [OQ-007](91-open-questions.md) | **offen** | Where do renderer, converter and validators attach? |
| [OQ-008](91-open-questions.md) | **offen** | Must every object have a changelog entry? |
| [OQ-009](91-open-questions.md) | **offen** | Is the delivery target still a WordPress plugin? |
| [OQ-010](91-open-questions.md) | **offen** | Is an *attribute* the same thing as an *edge*? |
| [OQ-011](91-open-questions.md) | **offen** | What is an attribute's *type*? |
| [OQ-012](91-open-questions.md) | beantwortet | Custom tables, or WordPress terms/posts? |
| [OQ-013](91-open-questions.md) | beantwortet | What exactly is a "setting", versus an attribute? |
| [OQ-014](91-open-questions.md) | **offen** | Where does the renderer run: PHP or JavaScript? |
| [OQ-015](91-open-questions.md) | **offen** | Where does the content live? |
| [OQ-016](91-open-questions.md) | **offen** | Is "setting" one thing, or two? |
| [OQ-017](91-open-questions.md) | **offen** | Which attributes does every node have? |
| [OQ-018](91-open-questions.md) | **offen** | Where does the value of an extended attribute live? |
| [OQ-019](91-open-questions.md) | **offen** | Cycles and depth in the render descent |
| [OQ-020](91-open-questions.md) | **offen** | Loading the subgraph without an N+1 |
| [OQ-021](91-open-questions.md) | **offen** | Composition and aggregation: what is the difference here? |
| [OQ-022](91-open-questions.md) | beantwortet | One settings table, or one per owner kind? |
| [OQ-023](91-open-questions.md) | **offen** | Is inheritance one edge kind, or a separate construct? |
| [OQ-024](91-open-questions.md) | **offen** | How are resolved settings computed without melting down? |
| [OQ-025](91-open-questions.md) | **offen** | How is a deep override addressed and stored? |
| [OQ-037](91-open-questions.md) | **offen** | What exactly happens when an override is promoted? |
| [OQ-038](91-open-questions.md) | **offen** | Is a chooser a renderer? |
| [OQ-039](91-open-questions.md) | beantwortet | Where do installation-wide settings live? |
| [OQ-026](91-open-questions.md) | **offen** | A part used in only one place: a node, or something smaller? |
| [OQ-027](91-open-questions.md) | **offen** | Does an attribute freeze its definition, or track it? |
| [OQ-028](91-open-questions.md) | **offen** | Is the set of label roles fixed, or extensible? |
| [OQ-029](91-open-questions.md) | **offen** | Are the length hints advisory or enforced? |
| [OQ-030](91-open-questions.md) | **offen** | May a model author write their own validator message? |
| [OQ-031](91-open-questions.md) | **offen** | How does existing data survive a model change? |
| [OQ-032](91-open-questions.md) | **offen** | Is the base name required, and unique anywhere? |
| [OQ-033](91-open-questions.md) | **offen** | Where does preview test data live? |
| [OQ-034](91-open-questions.md) | **offen** | Is the preview a renderer, or a caller of one? |
| [OQ-035](91-open-questions.md) | **offen** | Can a relation reach something that is not a model node? |
| [OQ-036](91-open-questions.md) | **offen** | Do instances share the identity space? |
| [OQ-040](91-open-questions.md) | **offen** | Is a currency a branch of units, or a separate concept? |
| [OQ-041](91-open-questions.md) | **offen** | Is a prefix a node or an enum? |
| [OQ-042](91-open-questions.md) | **offen** | Does an attribute's type name one node, or a branch? |
| [OQ-043](91-open-questions.md) | **offen** | Is the unit tree shipped, or authored? |
| [OQ-044](91-open-questions.md) | **offen** | How are calculations modelled? |
| [OQ-045](91-open-questions.md) | **offen** | What can a calculation expression reach? |
| [OQ-046](91-open-questions.md) | **offen** | When does a model calculation run? |
| [OQ-047](91-open-questions.md) | **offen** | What is the expression language, and who writes it? |
| [OQ-048](91-open-questions.md) | **offen** | How does the tool know where data may be entered? |
| [OQ-049](91-open-questions.md) | **offen** | Can a label be frozen at the moment of use? |
| [OQ-050](91-open-questions.md) | **offen** | What does a tool-independent export look like? |
| [OQ-051](91-open-questions.md) | **offen** | Does staged resolution need the intermediate model versions? |
| [OQ-052](91-open-questions.md) | **offen** | What can the resolver offer beyond showing a conflict? |
| [OQ-053](91-open-questions.md) | **offen** | What happens to a model that cannot be satisfied? |
| [OQ-054](91-open-questions.md) | **offen** | Is a currency amount stored as entered, or normalised? |
| [OQ-055](91-open-questions.md) | **offen** | Where does an exchange rate come from? |
| [OQ-056](91-open-questions.md) | **offen** | How many conditions can one query carry? |
| [OQ-057](91-open-questions.md) | beantwortet | Is undo in scope? |
| [OQ-058](91-open-questions.md) | **offen** | How does a subtype narrow an inherited attribute? |
| [OQ-059](91-open-questions.md) | **offen** | May an override widen, or only narrow? |
| [OQ-060](91-open-questions.md) | **offen** | Optimistic or pessimistic locking? |
| [OQ-061](91-open-questions.md) | **offen** | Does the descent walk the model, the record, or both? |
| [OQ-062](91-open-questions.md) | **offen** | What does *not computable* look like? |
| [OQ-063](91-open-questions.md) | **offen** | What identifies a record, for finding duplicates? |
| [OQ-064](91-open-questions.md) | **offen** | How is a contains-search made fast? |
| [OQ-065](91-open-questions.md) | **offen** | Does a seed item need a provenance marker? |
| [OQ-066](91-open-questions.md) | **offen** | What happens to data when a node is moved? |
| [OQ-067](91-open-questions.md) | **offen** | Does a parked record still hold its unique values? |
| [OQ-068](91-open-questions.md) | **offen** | Is there a symmetric declaration for aggregation-only? |
| [OQ-069](91-open-questions.md) | beantwortet | Views: deferred, with an entry criterion |
| [OQ-070](91-open-questions.md) | **offen** | How does renderer resolution stay cheap in a long list? |
| [OQ-071](91-open-questions.md) | **offen** | How is borrowed WordPress marked? |
| [OQ-072](91-open-questions.md) | **offen** | How is the importer told what maps to what? |
| [OQ-073](91-open-questions.md) | **offen** | What is the branch without data called? |
| [OQ-074](91-open-questions.md) | **offen** | Is there an enum filled at runtime? |
| [OQ-075](91-open-questions.md) | **offen** | How does a record have versions? |
| [OQ-076](91-open-questions.md) | **offen** | Can a reader hand a parameter to a rendering? |
| [OQ-077](91-open-questions.md) | **offen** | A conversion that depends on the other value |
| [OQ-078](91-open-questions.md) | **offen** | Where is the *relationship as a node* pattern taught? |
| [OQ-079](91-open-questions.md) | **offen** | Where does the shape stop being suitable? |
| [OQ-080](91-open-questions.md) | **offen** | Is there a page per record? |
| [OQ-081](91-open-questions.md) | beantwortet | Which token do the Gutenberg blocks and the text domain use? |
| [OQ-082](91-open-questions.md) | **offen** | How does the split behave? |
| [OQ-083](91-open-questions.md) | beantwortet | Does restoring a node put its promoted children back? |
| [OQ-084](91-open-questions.md) | **offen** | Does a node's version move when only its path was rewritten? |
| [OQ-085](91-open-questions.md) | **offen** | How much precision does a decimal have? |
| [OQ-086](91-open-questions.md) | **offen** | Where does a subtype's override of an inherited attribute hang? |
| [OQ-087](91-open-questions.md) | **offen** | How does a core renderer produce a word a person reads? |
| [OQ-088](91-open-questions.md) | **offen** | Where does a time of day live? |
| [OQ-089](91-open-questions.md) | beantwortet | Is a field's rule set one setting or three? |
| [OQ-090](91-open-questions.md) | beantwortet | Is a renderer a name, or is it a node? |
| [OQ-091](91-open-questions.md) | **offen** | Is the tree row a renderer of its own, and which role does a surface read labels in? |
| [OQ-092](91-open-questions.md) | beantwortet | Does `settings` need a `path` column, so one owner can hold several defaults? |
| [OQ-093](91-open-questions.md) | beantwortet | How does a setting key say which subjects it applies to? |
| [OQ-094](91-open-questions.md) | **offen** | How does a person enter a character that is not on their keyboard? |
| [OQ-095](91-open-questions.md) | beantwortet | May an attribute own labels, or is its name only a column? |
| [OQ-096](91-open-questions.md) | **offen** | Is a subtype substitutable for its parent where a reference is typed? |
| [OQ-097](91-open-questions.md) | beantwortet | Should settings be materialised into the inheriting node instead of resolved? |
| [OQ-098](91-open-questions.md) | **offen** | Is a value that can only live in one place a field rather than a setting? |
| [OQ-099](91-open-questions.md) | **offen** | A descendant's value for an inherited attribute has no address, and it already broke a decision |
| [OQ-100](91-open-questions.md) | **offen** | Should a setting key's *name* be translatable, even though the key is not? |
| [OQ-101](91-open-questions.md) | beantwortet | Hiding a node and not drawing its fields are two things sharing one key. Where does each belong? |
| [OQ-102](91-open-questions.md) | beantwortet | Should the live tables keep old versions with a delete flag, or should the journal become restorable? |
| [OQ-103](91-open-questions.md) | **offen** | Should the whole model be read once into an identity map, with writes going back per object? |
| [OQ-104](91-open-questions.md) | **offen** | Should a value be passed as an object rather than looked up by edge id? |

## Entscheidungen

⚠️ *Eine überholte Entscheidung steht hier mit ihrem Nachfolger, weil sie
sich sonst wie eine gültige liest — der Fehler, der an einem Tag drei falsche Antworten kostete.*

| Nr. | Datum | Stand | Worum es geht |
|---|---|---|---|
| [D-001](90-decision-log.md) | 2026-08-22 | agreed | Restart the concept. `docs/NewConcept/` is the single source of truth; the pre-2026-08-22 planning round moves to `docs/legacy/`, frozen, quarry only. |
| [D-002](90-decision-log.md) | 2026-08-22 | agreed | English |
| [D-003](90-decision-log.md) | 2026-08-22 | agreed | Content leaves `legacy/` only through a reviewed harvest sheet with an explicit *take / rework / drop* per item. No silent inheritance. |
| [D-004](90-decision-log.md) | 2026-08-22 | agreed | No production code until 10 Domain core is `locked`. Throwaway spikes allowed if marked throwaway. |
| [D-005](90-decision-log.md) | 2026-08-22 | agreed | Documentation style: |
| [D-006](90-decision-log.md) | 2026-08-22 | agreed | Order of work: |
| [D-007](90-decision-log.md) | 2026-08-22 | agreed | The model is stored in tables owned by this plugin |
| [D-008](90-decision-log.md) | 2026-08-22 | agreed | Rule reset. |
| [D-009](90-decision-log.md) | 2026-08-22 | agreed | Layered code style. |
| [D-010](90-decision-log.md) | 2026-08-22 | agreed | One file per topic. |
| [D-011](90-decision-log.md) | 2026-08-22 | agreed | A setting is an attribute. |
| [D-012](90-decision-log.md) | 2026-08-22 | agreed | Inheritance is an edge — one construct, its own kind. |
| [D-013](90-decision-log.md) | 2026-08-22 | agreed | The two resolutions must never be mixed. |
| [D-014](90-decision-log.md) | 2026-08-22 | agreed | Load in one step. |
| [D-015](90-decision-log.md) | 2026-08-22 | agreed | Base settings live on the referenced node; the using attribute overrides sparsely. |
| [D-016](90-decision-log.md) | 2026-08-22 | agreed | Caching is an implementation concern, bounded by two rules: |
| [D-017](90-decision-log.md) | 2026-08-22 | agreed`, placement corrected by [D-135](#) | A part used in exactly one place stays an ordinary node |
| [D-018](90-decision-log.md) | 2026-08-22 | agreed | Renderer split: a completely different presentation is a separate renderer; a parameterisable detail is a renderer setting. |
| [D-019](90-decision-log.md) | 2026-08-22 | agreed` (owner objected to the single-table draft; reasoning in [40 I18n](40-i18n.md)) | Labels get their own table, separate from settings. |
| [D-020](90-decision-log.md) | 2026-08-22 | ⚠️ D-209, die Kette dann durch D-386 | Every node carries a locale-neutral base name |
| [D-021](90-decision-log.md) | 2026-08-22 | agreed | Renderers are PHP. |
| [D-022](90-decision-log.md) | 2026-08-22 | agreed | A node name is required and explicitly not unique. |
| [D-023](90-decision-log.md) | 2026-08-22 | agreed | `Label` keeps its name; roles lose the prefix. |
| [D-024](90-decision-log.md) | 2026-08-22 | agreed | The concept is also published as a rendered page |
| [D-025](90-decision-log.md) | 2026-08-22 | agreed | The type of an attribute is the node the relation points to. |
| [D-026](90-decision-log.md) | 2026-08-22 | agreed | Modelling view and data view are separate layers. |
| [D-027](90-decision-log.md) | 2026-08-22 | agreed | Three layers, not two: |
| [D-028](90-decision-log.md) | 2026-08-22 | agreed | Test data is ordinary data, flagged. |
| [D-029](90-decision-log.md) | 2026-08-22 | agreed | The default editor is the data editor. |
| [D-030](90-decision-log.md) | 2026-08-22 | agreed | A default may be a reference, and there may be several. |
| [D-031](90-decision-log.md) | 2026-08-22 | agreed | An attribute *is* a relation. |
| [D-032](90-decision-log.md) | 2026-08-22 | agreed | The two-fold principle. |
| [D-033](90-decision-log.md) | 2026-08-22 | agreed | Orphaned overrides are never cascade-deleted. |
| [D-034](90-decision-log.md) | 2026-08-22 | agreed | Node selection is one unified interaction |
| [D-035](90-decision-log.md) | 2026-08-22 | agreed | A chooser takes two nodes: |
| [D-036](90-decision-log.md) | 2026-08-22 | agreed` (delegated) | Relation kinds are an enum |
| [D-037](90-decision-log.md) | 2026-08-22 | agreed | A model change is breaking or not depending on the existing data, not on the kind of change. |
| [D-038](90-decision-log.md) | 2026-08-22 | agreed | Relation kinds do not belong in the tree. |
| [D-039](90-decision-log.md) | 2026-08-22 | agreed | A unit value is one notion |
| [D-040](90-decision-log.md) | 2026-08-22 | agreed | Permitted prefixes are an allow-list setting on the attribute |
| [D-041](90-decision-log.md) | 2026-08-22 | agreed | The type of an attribute is a branch, and the branch is polymorphic. |
| [D-042](90-decision-log.md) | 2026-08-22 | agreed | Type node and model node are the same construct in different roles. |
| [D-043](90-decision-log.md) | 2026-08-22 | agreed` (design proposal, owner asked for it) | A calculation is a property of an attribute, not a relation kind. |
| [D-044](90-decision-log.md) | 2026-08-22 | agreed | `display_node_name` is a renderer, not a data type. |
| [D-045](90-decision-log.md) | 2026-08-22 | agreed` (design proposal) | A calculation names its inputs by relative edge-id path |
| [D-046](90-decision-log.md) | 2026-08-22 | agreed | Permitted sub-nodes are a setting holding a mode plus a list |
| [D-047](90-decision-log.md) | 2026-08-22 | agreed | `Gramm` is the base unit; the prefix carries the kilo. |
| [D-048](90-decision-log.md) | 2026-08-22 | agreed | The useful axis is *when is the content determined*, not *type versus model*. |
| [D-049](90-decision-log.md) | 2026-08-22 | agreed | A unit reference is data; the text for it is a label. |
| [D-050](90-decision-log.md) | 2026-08-22 | agreed | Do not ask what cannot matter. |
| [D-051](90-decision-log.md) | 2026-08-22 | agreed` (design proposal; owner: *the data must go in correctly and come back out correctly*) | A unit value is stored in its base unit, with the chosen prefix beside it as the display form. |
| [D-052](90-decision-log.md) | 2026-08-22 | agreed | Test data have three sources, in fallback order: |
| [D-053](90-decision-log.md) | 2026-08-22 | agreed | Rename is not replace. |
| [D-054](90-decision-log.md) | 2026-08-22 | agreed | The conflict resolver is the mechanism for model change. |
| [D-055](90-decision-log.md) | 2026-08-22 | agreed | Data reference node ids, never names |
| [D-056](90-decision-log.md) | 2026-08-22 | agreed | A control offers only real choices. |
| [D-057](90-decision-log.md) | 2026-08-22 | agreed | Numbers are whole or decimal, never floating point. |
| [D-058](90-decision-log.md) | 2026-08-22 | agreed | Two exports, kept apart. |
| [D-059](90-decision-log.md) | 2026-08-22 | agreed | Import resolution is the conflict resolver. |
| [D-060](90-decision-log.md) | 2026-08-22 | ⚠️ D-210 | The model version is carried by the record, not by `Identity`. |
| [D-061](90-decision-log.md) | 2026-08-22 | agreed | Migration needs the changes, not the snapshots — and the changelog is the migration script. |
| [D-062](90-decision-log.md) | 2026-08-22 | agreed | The resolver offers: map, map with transformation, bulk fill, fill by hand, delete. |
| [D-063](90-decision-log.md) | 2026-08-22 | agreed | A change that creates a new version warns at the moment it is made |
| [D-064](90-decision-log.md) | 2026-08-22 | agreed | An amount is stored in its own currency; a frozen rate is stored beside it; the converted figure is derived. |
| [D-065](90-decision-log.md) | 2026-08-22 | agreed | Track what describes, freeze what was agreed. |
| [D-066](90-decision-log.md) | 2026-08-22 | agreed | No table per model. The model is the schema. |
| [D-067](90-decision-log.md) | 2026-08-22 | agreed | The model declares one reference currency; a frozen rate always points at it. |
| [D-068](90-decision-log.md) | 2026-08-22 | agreed | The frozen rate belongs to the money type, not to a hand-made hidden field. |
| [D-069](90-decision-log.md) | 2026-08-22 | agreed | Rates are fetched at the boundary into a rate table; the core only reads the table. |
| [D-070](90-decision-log.md) | 2026-08-22 | agreed | Search is a requirement, not an aspiration. |
| [D-071](90-decision-log.md) | 2026-08-22 | agreed | Values are stored in typed columns, never in one stringly `value`. |
| [D-072](90-decision-log.md) | 2026-08-22 | agreed | Computed values are materialised |
| [D-073](90-decision-log.md) | 2026-08-22 | agreed | A currency value is an ordinary composed node with registered behaviour |
| [D-074](90-decision-log.md) | 2026-08-22 | agreed | Separate typed value columns, including whole numbers apart from decimals |
| [D-075](90-decision-log.md) | 2026-08-22 | agreed | A numeral-system change is a converter |
| [D-076](90-decision-log.md) | 2026-08-22 | agreed | Converters divide into invertible and lossy, and only invertible ones may serve input or search. |
| [D-077](90-decision-log.md) | 2026-08-22 | agreed | A node may carry several converters; which one applies is a setting. |
| [D-078](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt | ~~Settings are one construct with two scopes — a `scope` column separating model-scope keys (`min`, `max`, `step`) from system-scope keys (`hide`, `r… |
| [D-079](90-decision-log.md) | 2026-08-22 | agreed` (confirmed by the owner 2026-08-22) | An installation-wide default is a setting on a reserved installation identity |
| [D-080](90-decision-log.md) | 2026-08-22 | ⚠️ D-436 — `name` gehoert dazu, weil Knoten und Kante beide einen tragen und beide ihn uebersetzbar machen (D-410) | `Identity` carries `id` and `version` only. |
| [D-081](90-decision-log.md) | 2026-08-22 | agreed | Every object has at least one changelog item — the seed's `1..*` was right. |
| [D-082](90-decision-log.md) | 2026-08-22 | agreed` (proposal) | Every node has exactly four fixed attributes: `id`, `version`, `name`, `path`. |
| [D-083](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt | Seven tables. |
| [D-084](90-decision-log.md) | 2026-08-22 | agreed | Settings are one construct with one mechanism and a reserved namespace. Supersedes D-078. |
| [D-085](90-decision-log.md) | 2026-08-22 | agreed | The distinction is engine-owned versus type-owned keys, not *does the engine branch on it*. |
| [D-086](90-decision-log.md) | 2026-08-22 | agreed | Edge-only settings exist; node-only settings do not. |
| [D-087](90-decision-log.md) | 2026-08-22 | agreed | An override is the same thing wherever it sits; only its owner differs. |
| [D-088](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt | An override may narrow *and* widen. No monotonicity rule. |
| [D-089](90-decision-log.md) | 2026-08-22 | agreed | Concurrency, answered per layer. |
| [D-090](90-decision-log.md) | 2026-08-22 | agreed | Every foreign key column ends in `_id`. |
| [D-091](90-decision-log.md) | 2026-08-22 | agreed | The render descent, and one contract for both. |
| [D-092](90-decision-log.md) | 2026-08-22 | agreed | R3 answered: a node renderer renders exactly one node; an edge renderer handles one value or many. |
| [D-093](90-decision-log.md) | 2026-08-22 | agreed | Every setting resolves on its own key. |
| [D-094](90-decision-log.md) | 2026-08-22 | agreed | Ground rule: when rendering, honour every attribute — no special arrangements, the same everywhere. |
| [D-095](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt | `read_only` removes the input, not the field |
| [D-096](90-decision-log.md) | 2026-08-22 | agreed | The preview needs no special arrangement — it calls render twice |
| [D-097](90-decision-log.md) | 2026-08-22 | **corrected by [D-098](#)** — that invented a special case | at the edge |
| [D-098](90-decision-log.md) | 2026-08-22 | agreed | A container renderer is chosen like every other renderer. Corrects D-097. |
| [D-099](90-decision-log.md) | 2026-08-22 | agreed` (the declared-root part is a proposal) | A second eligibility rule: grouping renderers are not offered for data-type nodes |
| [D-100](90-decision-log.md) | 2026-08-22 | agreed` (confirmed by the owner 2026-08-23) | The descent stops in two different ways, and they are not the same event. |
| [D-101](90-decision-log.md) | 2026-08-22 | agreed | The preview is the pre-flight check — its third and most important job. |
| [D-102](90-decision-log.md) | 2026-08-22 | agreed | Modelling guidance: a symmetric relationship is better modelled as membership in a group than as pairwise links. |
| [D-103](90-decision-log.md) | 2026-08-22 | agreed | Depth belongs to the rendering, not to the node. The caller decides and the context carries it. |
| [D-104](90-decision-log.md) | 2026-08-22 | agreed | A depth limit is a rendering concern only. Calculations must never be truncated. |
| [D-105](90-decision-log.md) | 2026-08-22 | agreed | A reference renderer: it draws the target's label and a link, and does not descend. |
| [D-106](90-decision-log.md) | 2026-08-22 | agreed | Display and input are independent, and the display is not binary. Clarifies D-105. |
| [D-107](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt | A chooser is a renderer |
| [D-108](90-decision-log.md) | 2026-08-22 | agreed | Inline and popup are two separate chooser renderers, not one with a setting. Supersedes that part of D-107. |
| [D-109](90-decision-log.md) | 2026-08-22 | agreed | List or tree is derived from the branch, not chosen. |
| [D-110](90-decision-log.md) | 2026-08-22 | agreed | The branch root is excluded from the choice by default, with a setting to include it. |
| [D-111](90-decision-log.md) | 2026-08-22 | agreed | The multi-step input, and the create form is the search field. |
| [D-112](90-decision-log.md) | 2026-08-22 | agreed | The shown fields are the searched fields. |
| [D-113](90-decision-log.md) | 2026-08-22 | agreed | Inline for searching, popup for creating. |
| [D-114](90-decision-log.md) | 2026-08-22 | agreed | An attribute may carry a `unique` setting — enforced, not advisory. |
| [D-115](90-decision-log.md) | 2026-08-22 | agreed | `unique` may name a group, forming a composite constraint — and it is not called *primary key*. |
| [D-116](90-decision-log.md) | 2026-08-22 | agreed | A prefix is a node, not an enum — and the general rule is that a fixed value which might one day carry properties is a node. |
| [D-117](90-decision-log.md) | 2026-08-22 | agreed | What a structure *looks like* is a renderer; what it *is* is a node with attributes. |
| [D-118](90-decision-log.md) | 2026-08-22 | agreed | A node lays its attributes out in four groups, the same on every level. |
| [D-119](90-decision-log.md) | 2026-08-22 | agreed | A base scaffold ships and is imported once; afterwards it is ordinary authored content. |
| [D-120](90-decision-log.md) | 2026-08-22 | agreed | A binding is a named slot in the installation configuration that points at a node — and nothing else. |
| [D-121](90-decision-log.md) | 2026-08-22 | **corrected by [D-122](#)** | ~~Deletion protection is derived from references, not from a *template* flag.~~ The reference half stands and is kept by D-122; the rejection of a ma… |
| [D-122](90-decision-log.md) | 2026-08-22 | ⚠️ D-194 | Framework types are marked and undeletable; the reference check stands beside it. Corrects D-121. |
| [D-123](90-decision-log.md) | 2026-08-22 | agreed | Deletion is two-stage: park, then purge. |
| [D-124](90-decision-log.md) | 2026-08-22 | agreed | Moving a node is referentially free and semantically a model change. |
| [D-125](90-decision-log.md) | 2026-08-22 | agreed | Deleting a referenced node parks every edge that points at it, and that is *an attribute was deleted* for each owning node |
| [D-126](90-decision-log.md) | 2026-08-22 | agreed | The confirmation names the consequences and requires an act that cannot be reflexive. |
| [D-127](90-decision-log.md) | 2026-08-22 | agreed | A trash entry is one deletion event, with everything that fell with it — and restore puts back the whole event. |
| [D-128](90-decision-log.md) | 2026-08-22 | agreed` (proposal answering C105) | Three surfaces for a deletion, and they are different. |
| [D-129](90-decision-log.md) | 2026-08-22 | agreed | A demo pack ships beside the scaffold: an example tree with data, optional and importable. |
| [D-130](90-decision-log.md) | 2026-08-22 | agreed | The expression is a structured tree, built with a picker rather than typed. |
| [D-131](90-decision-log.md) | 2026-08-22 | agreed | A value reference resolves either to a |
| [D-132](90-decision-log.md) | 2026-08-22 | agreed`, derivation demoted | Standalone-versus-composed applies only to nodes whose instances are records. |
| [D-133](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt | Where a value is stored follows from the relation kind and the multiplicity. Supersedes the storage half of D-083. |
| [D-134](90-decision-log.md) | 2026-08-22 | agreed | `record_values` keys on a path, with the last edge kept in `edge_id`. |
| [D-135](90-decision-log.md) | 2026-08-22 | agreed | Composed-only nodes live under a `Kompositionen` node. Corrects D-017. |
| [D-136](90-decision-log.md) | 2026-08-22 | agreed | A multi-valued composition creates its node automatically. |
| [D-137](90-decision-log.md) | 2026-08-22 | agreed | Converting between the kinds is a move, with one asymmetry. |
| [D-138](90-decision-log.md) | 2026-08-22 | agreed | The `Kompositionen` node gets a binding, like every other root the engine must find. |
| [D-139](90-decision-log.md) | 2026-08-22 | agreed | What has data of its own is decided by placement, visibly. |
| [D-140](90-decision-log.md) | 2026-08-22 | agreed | An operand may be a backward path, and a backward aggregate is computed on read, never materialised. |
| [D-141](90-decision-log.md) | 2026-08-22 | agreed | Durability is a lifecycle question, not a storage-shape question. |
| [D-142](90-decision-log.md) | 2026-08-22 | agreed | Contagion: a calculation that depends on a backward aggregate is itself computed on read, and is therefore not searchable. |
| [D-143](90-decision-log.md) | 2026-08-22 | agreed | A computed value has three states, not two — and freezing dissolves the contagion problem. |
| [D-144](90-decision-log.md) | 2026-08-22 | agreed | Recalculation is an event, and staleness is information. |
| [D-145](90-decision-log.md) | 2026-08-22 | agreed | A valuation method is a registered calculation strategy, and several valuations coexist as separate named attributes. |
| [D-146](90-decision-log.md) | 2026-08-22 | agreed | A tracking list recalculates on save; a frozen list never does. |
| [D-147](90-decision-log.md) | 2026-08-22 | agreed | A computed value handles a missing input in one of three modes, set per attribute. |
| [D-148](90-decision-log.md) | 2026-08-22 | agreed | Converters are a few *kinds* parameterised by data, not one hard-coded class per case. |
| [D-149](90-decision-log.md) | 2026-08-22 | agreed | Notation is not structure. |
| [D-150](90-decision-log.md) | 2026-08-22 | agreed | `Widerstandswert` is a composed type — value plus tolerance — and tolerance defaults to 10%. |
| [D-151](90-decision-log.md) | 2026-08-22 | agreed | Label roles are nodes: a seeded base set, extensible by the author. |
| [D-152](90-decision-log.md) | 2026-08-22 | agreed | Length hints are settings on the role node. |
| [D-153](90-decision-log.md) | 2026-08-22 | agreed` — the doubt was resolved by [D-216](#) on 2026-08-23 | Number is a column, not a role — `provisional`. |
| [D-154](90-decision-log.md) | 2026-08-22 | agreed | A parked record keeps its `unique` values blocked; purging releases them. |
| [D-155](90-decision-log.md) | 2026-08-22 | agreed | Moving is one rule with two subjects, and it never loses data. |
| [D-156](90-decision-log.md) | 2026-08-22 | agreed | Promotion has everything it needs, because deletion is two-stage — and what it does depends on how many use the target. |
| [D-157](90-decision-log.md) | 2026-08-22 | agreed | An unsatisfiable model is caught where it is created, reported rather than blocked — but data entry against it is barred. |
| [D-158](90-decision-log.md) | 2026-08-22 | agreed | A model author may replace a validator message, and it lives as a label — per validator. |
| [D-159](90-decision-log.md) | 2026-08-22 | agreed | The descent has two inputs, both loaded before it starts, and there is only one mode. |
| [D-160](90-decision-log.md) | 2026-08-22 | agreed | The preview renders a test data pack, not an empty shell. |
| [D-161](90-decision-log.md) | 2026-08-22 | agreed | The relation kind is not asked, it is read off the target's branch. |
| [D-162](90-decision-log.md) | 2026-08-22 | agreed | Moving a node between the branches rewrites every edge that points at it, and the data comes along. |
| [D-163](90-decision-log.md) | 2026-08-22 | agreed | At multiplicity 1 the author no longer chooses inline versus own record — the branch chooses for them, and they must be told. |
| [D-164](90-decision-log.md) | 2026-08-22 | agreed | Records do not share the model's identity space. Model objects keep theirs. |
| [D-165](90-decision-log.md) | 2026-08-23 | agreed | Correctness has no limit, speed is promised to about three conditions, and the reporting case gets a flat projection rather than a cleverer query. |
| [D-166](90-decision-log.md) | 2026-08-23 | agreed` (confirmed by the owner 2026-08-23) | What cannot have been meant is removed by the converter; what might have been meant is questioned by the validator. |
| [D-167](90-decision-log.md) | 2026-08-23 | agreed | Contains is the default in the quick search, an operator field carries the filter, and there is no wildcard character. |
| [D-168](90-decision-log.md) | 2026-08-23 | ⚠️ D-217 | Purpose is part of the render context, and searching is the third one. The registry is keyed by type *and* purpose. |
| [D-169](90-decision-log.md) | 2026-08-23 | agreed | Yes, a WordPress plugin — and WordPress is used to the full, not held at arm's length. What is borrowed gets written down. |
| [D-170](90-decision-log.md) | 2026-08-23 | agreed` (confirmed by the owner 2026-08-23) | The namespace is the marker, a ledger catches what the namespace cannot, and a second boundary is the intended shape of a port. |
| [D-171](90-decision-log.md) | 2026-08-23 | agreed | WordPress is not underneath the core, it is around it, and every arrow points inward. |
| [D-172](90-decision-log.md) | 2026-08-23 | agreed | Undo is in scope. It is a step forward, not a rewind, and its reach is the trash. |
| [D-173](90-decision-log.md) | 2026-08-23 | agreed | Migration is not an undo case, and importing existing WordPress tables is its own tool. |
| [D-174](90-decision-log.md) | 2026-08-23 | agreed | A seeded node carries two marks: *came from the seed* and *changed since*. |
| [D-175](90-decision-log.md) | 2026-08-23 | agreed` (confirmed by the owner 2026-08-23) | Content packs: named sets of model content that can be installed and removed again. The seed is simply the pack that ships. |
| [D-176](90-decision-log.md) | 2026-08-23 | agreed | The pair is *Modell* and *Daten*, single unit *Datensatz*. *Bestand* is rejected. |
| [D-177](90-decision-log.md) | 2026-08-23 | agreed | A pack declares what it requires and marks what it contributes. It may add to another pack's branch and never change another pack's nodes. |
| [D-178](90-decision-log.md) | 2026-08-23 | agreed | Pack authorship is a recording mode, not a label per node. |
| [D-179](90-decision-log.md) | 2026-08-23 | agreed | The tree keeps everything the legacy tree did — the crowding is answered by a menu, not by removing abilities. |
| [D-180](90-decision-log.md) | 2026-08-23 | agreed | Deleting a node asks whether the branch goes too; dragging moves whole branches, several at once; duplicating lands directly beneath with an indexed … |
| [D-181](90-decision-log.md) | 2026-08-23 | agreed`, default reversed by [D-238](#) | What may be picked is a property of the use site, not of the node. The legacy *hide* control is retired. |
| [D-182](90-decision-log.md) | 2026-08-23 | agreed | Branch deletion stops being its own button and becomes the second answer in the delete dialog. |
| [D-183](90-decision-log.md) | 2026-08-23 | agreed | Having data is read off the branch: `Model` and `Kompositionen` have data, everything else is means to an end. |
| [D-184](90-decision-log.md) | 2026-08-23 | agreed | Small lists do not get a second storage mechanism; they get a cheaper editor. |
| [D-185](90-decision-log.md) | 2026-08-23 | ⚠️ D-187 und D-188 | The branch without data is called `Bausteine`. |
| [D-186](90-decision-log.md) | 2026-08-23 | agreed | V1–V9 are confirmed, and V5 gains a nuance that changes what it forbids. |
| [D-187](90-decision-log.md) | 2026-08-23 | agreed | English is the standard for every term. German exists as a translation, where one is needed. |
| [D-188](90-decision-log.md) | 2026-08-23 | agreed | The three branches are `Model`, `Compositions` and `Primitives`. |
| [D-189](90-decision-log.md) | 2026-08-23 | agreed | Counts counts what there are several of, and the branch says what that is. |
| [D-190](90-decision-log.md) | 2026-08-23 | agreed | The detail view's order is the order of dealing with a node, and it is not a special screen. |
| [D-191](90-decision-log.md) | 2026-08-23 | agreed | Attributes show their core and load their detail on demand — for two reasons that agree. |
| [D-192](90-decision-log.md) | 2026-08-23 | agreed | Three sections the legacy detail screen has no place for, because they were decided after it was built. |
| [D-193](90-decision-log.md) | 2026-08-23 | agreed | Inside `Primitives` the sub-branch decides: data types yield a composition, constants yield an aggregation. Closes a gap in D-161. |
| [D-194](90-decision-log.md) | 2026-08-23 | agreed | `Is template` is retired: provenance says what it said. Protection is separated out and narrowed. |
| [D-195](90-decision-log.md) | 2026-08-23 | agreed | `slug` is not ours. |
| [D-196](90-decision-log.md) | 2026-08-23 | agreed | The seeded label roles are `form`, `table`, `select`, `symbol` and `help`, offered together on the node. |
| [D-197](90-decision-log.md) | 2026-08-23 | agreed | There is one chooser in the product. It picks and it creates, and it always looks the same. |
| [D-198](90-decision-log.md) | 2026-08-23 | agreed | A control's state follows from what is actually choosable. |
| [D-199](90-decision-log.md) | 2026-08-23 | agreed | The relations section holds one direction and is renamed `Used by`. |
| [D-200](90-decision-log.md) | 2026-08-23 | agreed | The four remaining questions are deferred deliberately, each with the event that reopens it. |
| [D-201](90-decision-log.md) | 2026-08-23 | agreed | A *view* and a *report* are different things, and only one of them was ever the deferred question. |
| [D-202](90-decision-log.md) | 2026-08-23 | agreed | Correction to D-201: a report does compute, at output time, and it joins. Both halves of my characterisation were wrong. |
| [D-203](90-decision-log.md) | 2026-08-23 | agreed | Views, reports and the list-rendering optimisation are Release 2. |
| [D-204](90-decision-log.md) | 2026-08-23 | agreed | Runtime extension: declared per branch including by whom, usable at once, reviewed afterwards. Closes OQ-074. |
| [D-205](90-decision-log.md) | 2026-08-23 | agreed | A pending review shows in the tree, and it propagates up through collapsed ancestors. |
| [D-206](90-decision-log.md) | 2026-08-23 | agreed | The front end is composed of small blocks, one node each, and the reference does the joining. |
| [D-207](90-decision-log.md) | 2026-08-23 | agreed | A comparison block resolves to the nearest common ancestor, and that is the tree doing its job. |
| [D-208](90-decision-log.md) | 2026-08-23 | agreed | A list block is a node plus a restriction. |
| [D-209](90-decision-log.md) | 2026-08-23 | agreed | `long` is gone. It was renamed `help`, and the fallback chain ends there. |
| [D-210](90-decision-log.md) | 2026-08-23 | agreed | A record keeps its version stamp. Resolving touches only what actually conflicted. |
| [D-211](90-decision-log.md) | 2026-08-23 | ⚠️ D-229 | A medium has two locations on purpose, carries its provenance, and lives in the WordPress media library. |
| [D-212](90-decision-log.md) | 2026-08-23 | agreed | A project fact sheet is not a new block — it is D-206 under the display purpose. |
| [D-213](90-decision-log.md) | 2026-08-23 | agreed | An update corrects what the author never touched. D-119 is precised, not overturned. |
| [D-214](90-decision-log.md) | 2026-08-23 | agreed | Only one composing edge may point at a node — and my objection to that rule rested on my own misuse of a term. |
| [D-215](90-decision-log.md) | 2026-08-23 | agreed | The term is `Data Pack`, not `Pack`. |
| [D-216](90-decision-log.md) | 2026-08-23 | agreed | The `number` column holds a plural |
| [D-217](90-decision-log.md) | 2026-08-23 | ⚠️ D-236 | One node, one renderer. The purpose is passed to it, not keyed on. Supersedes the registry-key half of D-168 and resolves contradiction 1. |
| [D-218](90-decision-log.md) | 2026-08-23 | agreed | Read-only renders under the display purpose — and a read-only field with neither calculation nor default is a model conflict. |
| [D-219](90-decision-log.md) | 2026-08-23 | agreed | A representation is a converter plus a renderer, and the mapping is model data. The only axis is invertible or not. |
| [D-220](90-decision-log.md) | 2026-08-23 | agreed | Whether something is one field or several is decided by the model, not by the display. The composed type is the unit of rendering. |
| [D-221](90-decision-log.md) | 2026-08-23 | agreed | There is no *fixed value*. There is a restriction that collapses to one. |
| [D-222](90-decision-log.md) | 2026-08-23 | agreed | A decision is the record of a choice, not a wall. Revising one is normal work. |
| [D-223](90-decision-log.md) | 2026-08-23 | agreed | Automatic is a default, never a fact. |
| [D-224](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt | A decorator is a renderer, and one layer of decoration is allowed. |
| [D-225](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt | Invertible replaces, non-invertible decorates — and that settles when a second occurrence is needed. |
| [D-226](90-decision-log.md) | 2026-08-23 | agreed | Invertibility decides only whether a form can be written into. Replace or decorate is a free choice. Supersedes D-225. |
| [D-227](90-decision-log.md) | 2026-08-23 | agreed | The rule counts possibilities, not entries. Refines D-198 and resolves its clash with D-056. |
| [D-228](90-decision-log.md) | 2026-08-23 | agreed | P5 stands untouched; a projection is not storage, and there are few of them by design. |
| [D-229](90-decision-log.md) | 2026-08-23 | agreed | A medium is an ordinary type under `Model`, not a special construct — and it knows its own MIME type. |
| [D-230](90-decision-log.md) | 2026-08-23 | agreed | A medium is drawn along two axes: kind is detected, degree is configured — one renderer, not their product. |
| [D-231](90-decision-log.md) | 2026-08-23 | agreed | The preview shows what the front end will show. Its only permitted deviation is bounding the size. |
| [D-232](90-decision-log.md) | 2026-08-23 | agreed | The branch decides where a value is stored, not the multiplicity. Supersedes D-133. |
| [D-233](90-decision-log.md) | 2026-08-23 | agreed | A page renderer is fine; D-091 rejected an interface, not the idea. |
| [D-234](90-decision-log.md) | 2026-08-23 | agreed | The block decides *what* is shown; the renderer decides *how* it is drawn. |
| [D-235](90-decision-log.md) | 2026-08-23 | agreed | Grouping-renderer eligibility keys on the `data_types` binding — the proposal in D-099 is now buildable. |
| [D-236](90-decision-log.md) | 2026-08-23 | agreed | A node carries an ordered list of renderers: one mandatory, the rest optional additions. Supersedes the single-renderer half of D-217 and replaces D-… |
| [D-237](90-decision-log.md) | 2026-08-23 | agreed | The identifying fields belong to the type; the columns a table happens to show do not. Resolves the D-112 / D-167 clash. |
| [D-238](90-decision-log.md) | 2026-08-23 | agreed | Everything except the branch root is selectable by default. Reverses D-181's default and keeps its insight. |
| [D-239](90-decision-log.md) | 2026-08-23 | agreed | The chooser also works value-first: entering a value narrows the candidate types. |
| [D-240](90-decision-log.md) | 2026-08-23 | agreed | Three layers feed a preview: real data, records marked as test data, and the type's own sample value. Closes the D-052 / D-160 gap. |
| [D-241](90-decision-log.md) | 2026-08-23 | agreed | The test-data mark governs front-end visibility and nothing else. |
| [D-242](90-decision-log.md) | 2026-08-23 | agreed | `Ausdruck` means *printout*, not *expression* — and a printout is a frozen report. |
| [D-243](90-decision-log.md) | 2026-08-23 | agreed | A report is a wider bracket around the tools that already exist. The expression language does not grow. |
| [D-244](90-decision-log.md) | 2026-08-23 | agreed | The chooser defaults to the dialog; inline stays available for the simple case. Flips the default in D-108. |
| [D-245](90-decision-log.md) | 2026-08-23 | agreed | `set` and `table` were the legacy conflation of a thing with its drawing. D-117 was right, and its own sentence is the resolution. |
| [D-246](90-decision-log.md) | 2026-08-23 | agreed | `set` and `table` are retired as constructs. Nothing is lost, because D-245 had already moved their work elsewhere. |
| [D-247](90-decision-log.md) | 2026-08-23 | agreed | `Cleanup` stays, as maintenance for what deletion leaves behind. |
| [D-248](90-decision-log.md) | 2026-08-23 | agreed | One mode, not two: test mode folds into developer mode. |
| [D-249](90-decision-log.md) | 2026-08-23 | agreed | Settings apply immediately; undo is the safety net. The save button stays available for one concrete reason. |
| [D-250](90-decision-log.md) | 2026-08-23 | agreed | `Fill Model Data` and `node_presentation` are retired. |
| [D-251](90-decision-log.md) | 2026-08-23 | agreed | The tree shows a node's icon where one is set. |
| [D-252](90-decision-log.md) | 2026-08-23 | agreed | `icon` and `symbol` are two different things that merely sit next to each other. |
| [D-253](90-decision-log.md) | 2026-08-23 | agreed | Three surfaces, three jobs: the admin configures the model, the block composes a page, the front end only draws. |
| [D-254](90-decision-log.md) | 2026-08-23 | agreed | Blocks render on the server, on every request — including inside the editor. |
| [D-255](90-decision-log.md) | 2026-08-23 | agreed | One block for a node. The layout follows the content, and whole parts can be hidden. |
| [D-256](90-decision-log.md) | 2026-08-23 | agreed | The node renderer is named, and it is the same thing as the page renderer. Corrects the placement in D-255. |
| [D-257](90-decision-log.md) | 2026-08-23 | agreed | A block may override presentation settings, and such an override is page-local by declaration. |
| [D-258](90-decision-log.md) | 2026-08-23 | agreed | The editor supplies the data. Entry by visitors is foreseen, has no use case, and needs no new concept. |
| [D-259](90-decision-log.md) | 2026-08-23 | agreed | A renderer is told which label role to display. The concept had no place for this. |
| [D-260](90-decision-log.md) | 2026-08-23 | agreed | A unit's short form is a label in the `symbol` role. The modelled `symbol` attribute of C44 goes. |
| [D-261](90-decision-log.md) | 2026-08-23 | agreed | A label carries a *translatable* mark; `symbol` defaults to |
| [D-262](90-decision-log.md) | 2026-08-23 | agreed | Of two possible defaults, take the one whose failure is noticed. `symbol` therefore defaults to *not translatable*. |
| [D-263](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt | A chooser is told two roles: one for the open list, one for the closed field. |
| [D-264](90-decision-log.md) | 2026-08-23 | agreed | A renderer is told a |
| [D-265](90-decision-log.md) | 2026-08-23 | agreed | A table footer aggregate is a block setting, and it totals what is shown — not what exists. |
| [D-266](90-decision-log.md) | 2026-08-23 | agreed | An override can be reset to *inherited*, and that is not the same as storing an empty value. |
| [D-267](90-decision-log.md) | 2026-08-23 | agreed | The `id` stays as it is. The legacy running number splits into three things, and only one of them is missing. |
| [D-268](90-decision-log.md) | 2026-08-23 | agreed | A number circle is a type of its own, and it is the first thing in the model whose state changes without anyone editing it. |
| [D-269](90-decision-log.md) | 2026-08-23 | agreed | A row number is a reading aid, never an identifier. |
| [D-270](90-decision-log.md) | 2026-08-23 | agreed | Row numbers are switched on in the block, and off by default. |
| [D-271](90-decision-log.md) | 2026-08-23 | agreed | A parking lot, with a rule that keeps it from becoming a graveyard. |
| [D-272](90-decision-log.md) | 2026-08-23 | agreed | The parking lot is walked at every release planning. |
| [D-273](90-decision-log.md) | 2026-08-23 | agreed | One tree for all subject areas. No separate model roots, no projects. |
| [D-274](90-decision-log.md) | 2026-08-23 | agreed | A unit carries its factor to the reference unit of its parent. Closes a gap in D-039. |
| [D-275](90-decision-log.md) | 2026-08-23 | agreed | Converting between peer units moves from the parking lot into Release 2. |
| [D-276](90-decision-log.md) | 2026-08-23 | agreed | There is no second audience. The registries are internal. |
| [D-277](90-decision-log.md) | 2026-08-23 | agreed | Renderer and converter form a pair. The converter belongs to the list entry, not to the node. |
| [D-278](90-decision-log.md) | 2026-08-23 | agreed | The *level* is retired. What it did is purpose, depth and visibility — all of which already exist. Resolves the last contradiction. |
| [D-279](90-decision-log.md) | 2026-08-23 | agreed | Preview what you cannot see while configuring. The search view belongs in it; the tree row does not. |
| [D-280](90-decision-log.md) | 2026-08-23 | agreed | A node search sits above the tree and inside every chooser. |
| [D-281](90-decision-log.md) | 2026-08-23 | agreed | A duplicate edge is refused, and the name is part of what makes it a duplicate. |
| [D-282](90-decision-log.md) | 2026-08-23 | agreed | An unchanged save does not raise the version. |
| [D-283](90-decision-log.md) | 2026-08-23 | agreed | Nobody loses their place. This is craft, not a feature. |
| [D-284](90-decision-log.md) | 2026-08-23 | agreed` (my call, no owner opinion) | Keyboard and screen-reader behaviour is a baseline requirement. |
| [D-285](90-decision-log.md) | 2026-08-23 | agreed | A converter that cannot represent a value falls back to the canonical form and says so at the field. |
| [D-286](90-decision-log.md) | 2026-08-23 | agreed | The compact renderer factors out a shared unit and names the members in the label. |
| [D-287](90-decision-log.md) | 2026-08-23 | agreed | Excluding a choice excludes its subtree; a media attribute restricts the kinds it accepts. Both are D-221. |
| [D-288](90-decision-log.md) | 2026-08-23 | agreed | An error blocks, a warning stays visible and does not. |
| [D-289](90-decision-log.md) | 2026-08-23 | agreed | Uninstalling offers an export first, and deleting the data is a choice. |
| [D-290](90-decision-log.md) | 2026-08-23 | agreed | Sample values that appear together are coherent. Refines D-240. |
| [D-291](90-decision-log.md) | 2026-08-23 | agreed | One date-and-time type with a precision setting. Stored in UTC — except a plain date, which has no timezone at all. |
| [D-292](90-decision-log.md) | 2026-08-23 | agreed | There are roles, they are built in from the start, and an administrator may practically everything. |
| [D-293](90-decision-log.md) | 2026-08-23 | agreed | A field can take its default — or its restriction — from the record that encloses it. |
| [D-294](90-decision-log.md) | 2026-08-23 | agreed | A mirrored file is fetched once on saving, refreshed only when asked, and never silently on reading. |
| [D-295](90-decision-log.md) | 2026-08-23 | agreed | Duplicating an attribute copies the definition, not the data. Copying values is a separate, named operation. |
| [D-296](90-decision-log.md) | 2026-08-23 | agreed | A machine change is recorded as the machine, never as a person. |
| [D-297](90-decision-log.md) | 2026-08-23 | agreed | Locale-dependent formatting follows the reader, not the installation. |
| [D-298](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt | A list of tick boxes commits when it is left, not on every tick. |
| [D-299](90-decision-log.md) | 2026-08-23 | agreed | Commit-on-leave was a crutch, not a principle. Supersedes D-298. |
| [D-300](90-decision-log.md) | 2026-08-23 | agreed | An allow-list is *one* value that happens to be a set. The group argument stands — with a third reason, and this one holds. |
| [D-301](90-decision-log.md) | 2026-08-23 | agreed | Where the data are must be visible in the product. |
| [D-302](90-decision-log.md) | 2026-08-23 | agreed | Export, backup and restore belong to the product, not beside it. A backup replaces; a pack merges. |
| [D-303](90-decision-log.md) | 2026-08-23 | agreed | The old data stay, and here is when they would be used. |
| [D-304](90-decision-log.md) | 2026-08-23 | agreed | Transforming the existing site content is the first thing that delivers value, not an afterthought. |
| [D-305](90-decision-log.md) | 2026-08-23 | agreed | Versions of a board are modelled, not built. Closes OQ-075. |
| [D-306](90-decision-log.md) | 2026-08-23 | agreed | A conversion is a record, not a property of a unit. Extends D-274. Closes OQ-077. |
| [D-307](90-decision-log.md) | 2026-08-23 | agreed | A pattern book, because the concept can express more than it teaches. Closes OQ-078. |
| [D-308](90-decision-log.md) | 2026-08-23 | agreed | Where the shape stops fitting, said out loud. Closes OQ-079. |
| [D-309](90-decision-log.md) | 2026-08-23 | agreed | Reader-supplied parameters are parked; a page per record is Release 2. Closes OQ-076 and OQ-080. |
| [D-310](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt | A use site is an attribute, and it may do everything the node may. D-088 stands; the *never widen* sentence falls. |
| [D-311](90-decision-log.md) | 2026-08-23 | ⚠️ D-405 | What an ancestor declares mandatory stays mandatory for every descendant. A targeted exception to D-310. |
| [D-312](90-decision-log.md) | 2026-08-23 | ⚠️ D-411 | Bounding settings may only be tightened downwards; choosing settings are free. Supersedes D-310 and the widening half of D-088. |
| [D-313](90-decision-log.md) | 2026-08-23 | agreed | Implementation proceeds in self-contained packages: thin vertical slices, each ending in something the owner can operate, each followed by a list of … |
| [D-314](90-decision-log.md) | 2026-08-23 | agreed | The test for `locked` is whether a person can *check* what was built, not whether a person could build from it. And the core fits on one page. |
| [D-315](90-decision-log.md) | 2026-08-24 | agreed | A boolean is stored as an integer — and a missing row is not `false`. |
| [D-316](90-decision-log.md) | 2026-08-24 | agreed | Long text is `MEDIUMTEXT` in `record_values`. And we store no binary data at all. |
| [D-317](90-decision-log.md) | 2026-08-24 | agreed | Data can be translatable too. The gap: we had translation for labels and none for values. |
| [D-318](90-decision-log.md) | 2026-08-24 | agreed | `textarea` is retired as a type. One `text` type; the differences are a validator and a renderer. |
| [D-319](90-decision-log.md) | 2026-08-24 | agreed | A type earns its place through storage, rendering or ordering — never through validation alone. |
| [D-320](90-decision-log.md) | 2026-08-24 | ⚠️ D-328 | Four composed types are added: `range`, `link`, `period`, `address`. |
| [D-321](90-decision-log.md) | 2026-08-24 | agreed | `version` is a type, and it earns that through ordering. |
| [D-322](90-decision-log.md) | 2026-08-24 | agreed | `Medium` becomes `Resource`, the copy is optional, and `url` and `link` are dropped. An email stays its own type with a clickable renderer. |
| [D-323](90-decision-log.md) | 2026-08-24 | agreed | It is called `Link` — a link with extras. Names D-322. |
| [D-324](90-decision-log.md) | 2026-08-24 | agreed | `tolerance` is a composed type of two magnitudes; symmetric is the case where they are equal. |
| [D-325](90-decision-log.md) | 2026-08-24 | agreed`, the `ratio` half revised by [D-326](#) | Percent is a unit, not a type — and an enumerable ratio is a constant, not a type. No `ratio` type. |
| [D-326](90-decision-log.md) | 2026-08-24 | agreed | `ratio` is a composed type after all — numerator and denominator — and the constants use it. Revises the `ratio` half of D-325. |
| [D-327](90-decision-log.md) | 2026-08-24 | agreed | The namespace is `Taxmod`, and the version starts at `0.0.1`. |
| [D-328](90-decision-log.md) | 2026-08-24 | agreed | `range` is a type after all: the member type is chosen |
| [D-329](90-decision-log.md) | 2026-08-24 | agreed | `char` stays. My filter failed it wrongly. |
| [D-330](90-decision-log.md) | 2026-08-24 | agreed | There is a type for a reference to a user. |
| [D-331](90-decision-log.md) | 2026-08-24 | agreed`, named `markup` by [D-335](#) | Formatted text and source code are one type: text plus a declared interpretation. |
| [D-332](90-decision-log.md) | 2026-08-24 | agreed | A barcode is an additional renderer, not a type — and it is the first real use of the renderer list. |
| [D-333](90-decision-log.md) | 2026-08-24 | agreed | Masking is a converter, and the real value must never leave the server. |
| [D-334](90-decision-log.md) | 2026-08-24 | agreed | Maps and `JSON` go to the parking lot, with the owner's own criteria. |
| [D-335](90-decision-log.md) | 2026-08-24 | agreed | The type of D-331 is called `markup`. |
| [D-336](90-decision-log.md) | 2026-08-24 | agreed | The project is called Taxonomy Modeller, the repository `wp-taxonomy-modeler`, and the database prefix is `taxmod_`. |
| [D-337](90-decision-log.md) | 2026-08-24 | agreed | One token everywhere: `taxmod`. |
| [D-338](90-decision-log.md) | 2026-08-24 | agreed | 10 Domain core is `locked`. |
| [D-339](90-decision-log.md) | 2026-08-24 | agreed | `Identity` becomes a table: `identities(id)`, append-only, and `owner_id` becomes a real foreign key. |
| [D-340](90-decision-log.md) | 2026-08-24 | agreed | A migration may never reissue an id that was once handed out. |
| [D-341](90-decision-log.md) | 2026-08-24 | agreed | A reset is all or nothing, and it is command line only. |
| [D-342](90-decision-log.md) | 2026-08-24 | agreed | Every package adds to a regression net, and both runs are green before anything is committed. |
| [D-343](90-decision-log.md) | 2026-08-24 | agreed | Recorded in error as new — it was already R19. |
| [D-344](90-decision-log.md) | 2026-08-24 | agreed | The provisional admin screen is scaffolding. It is thrown away, not grown into the real one. |
| [D-345](90-decision-log.md) | 2026-08-24 | agreed | The scaffolding keeps getting just enough surface to be operated — sparingly. |
| [D-346](90-decision-log.md) | 2026-08-24 | agreed | Restoring from the trash is built, and the owner's collapse bug is kept as the evidence for R18. |
| [D-347](90-decision-log.md) | 2026-08-24 | agreed | Restoring is undo, so a promoted child goes back with the node. OQ-083 closed by the owner's own reasoning: |
| [D-348](90-decision-log.md) | 2026-08-24 | agreed | Changes made in one act are written under one bracket. The changelog gains a change-group id, and D-127's deletion event becomes one case of it. |
| [D-349](90-decision-log.md) | 2026-08-24 | agreed | The node counter moves on every write to its row, including a path rewritten because an ancestor moved. And it is a collision guard, not a version an… |
| [D-350](90-decision-log.md) | 2026-08-24 | agreed | The scaffolding gets one raw data-entry surface, and it is the last one. D-344 is bent here deliberately, not quietly. |
| [D-351](90-decision-log.md) | 2026-08-25 | agreed | Multiplicity is one key with four constants, not two number fields. |
| [D-352](90-decision-log.md) | 2026-08-25 | agreed | Nine of the simple types get a renderer that comes in the box, and *which one is the default* is a fact the registry holds — not a convention a calle… |
| [D-353](90-decision-log.md) | 2026-08-25 | agreed | A renderer that declines a purpose yields *nothing*, and the caller decides what that means — because the answer differs by purpose. Corrects the con… |
| [D-354](90-decision-log.md) | 2026-08-25 | agreed | D-350 is closed on its own terms: the raw entry field is deleted, not grown. |
| [D-355](90-decision-log.md) | 2026-08-25 | agreed | The date granularity setting is called `date_precision`, it is a free key rather than a reserved one, and a time with no date is parked against the e… |
| [D-356](90-decision-log.md) | 2026-08-25 | agreed | What a control accepts comes from the *converter*, never from the validator — and where no converter is in effect it is the type's own storage shape.… |
| [D-357](90-decision-log.md) | 2026-08-25 | agreed | Field rules are stored as three keys and configured as one group. OQ-089 is answered in its shape and stays open in its details. |
| [D-358](90-decision-log.md) | 2026-08-25 | agreed | A renderer, a converter and a validator are always |
| [D-359](90-decision-log.md) | 2026-08-25 | agreed | `step` is an engine setting on the node, `range_step` — and the *homeless setting* that D-358 worried about was never a thing. Both corrections come … |
| [D-360](90-decision-log.md) | 2026-08-25 | agreed | The eligible set is what a person is *offered*, not a fence around what may be *stored*. A registration declares two things and forbids nothing. |
| [D-361](90-decision-log.md) | 2026-08-25 | agreed | The field-rule panel has three sections, each with its own control — and the |
| [D-362](90-decision-log.md) | 2026-08-25 | agreed | A rule list is stored as |
| [D-363](90-decision-log.md) | 2026-08-25 | agreed | A renderer learns about a node it is not drawing through the context, resolved before the descent — and the reference renderer is the first thing tha… |
| [D-364](90-decision-log.md) | 2026-08-25 | agreed | A free setting key is configuration about the *model*, never a place to keep data about the *thing* — and it is therefore not offered as an authoring… |
| [D-365](90-decision-log.md) | 2026-08-25 | agreed | Renderer, converter and validator are three orthogonal jobs assigned per *data type* — and the per-type table is where that is written down. A valida… |
| [D-366](90-decision-log.md) | 2026-08-25 | agreed | A container renderer lays out parts the descent already drew; it does not draw them. And the first one built — the form renderer — found that three o… |
| [D-367](90-decision-log.md) | 2026-08-25 | agreed | Two renderers, not one: the tree renderer walks the hierarchy, the node renderer draws the node — and the point of the split is that the node rendere… |
| [D-368](90-decision-log.md) | 2026-08-25 | agreed | The glyphs on the controls, named — and *icon* stops meaning two things. |
| [D-369](90-decision-log.md) | 2026-08-25 | agreed | The modelling tree shows a node's own |
| [D-370](90-decision-log.md) | 2026-08-25 | agreed | A control that cannot be used now is |
| [D-371](90-decision-log.md) | 2026-08-25 | agreed | An attribute can be removed at last, and what was missing was storage: `relations.parked_by_group_id`. |
| [D-372](90-decision-log.md) | 2026-08-25 | ⚠️ D-373 und D-377 | Prefixes and base units are seeded under `Constants` — `Gramm` and not `Kilogramm`, and a prefix is stored as a power of ten. |
| [D-373](90-decision-log.md) | 2026-08-25 | ⚠️ ersetzt | A prefix's exponent is an |
| [D-374](90-decision-log.md) | 2026-08-25 | agreed | A `node_label` data type was proposed, built, and withdrawn within the hour: what a record stores is the |
| [D-375](90-decision-log.md) | 2026-08-25 | agreed | D-039's composed type exists at last: `Einheitenwert` — `wert` · `prefix` `0..1` · `einheit` — and its preview renders `2.7 kΩ`. Storing one is refus… |
| [D-376](90-decision-log.md) | 2026-08-25 | agreed | An attribute is a drawn subject like any other: the `attribute` renderer, with the name editable where it is declared. `1..1` reads `1`. And the mult… |
| [D-377](90-decision-log.md) | 2026-08-25 | agreed | `persistent` — an attribute may declare that its value is |
| [D-378](90-decision-log.md) | 2026-08-25 | agreed | A prefix's exponent is an attribute of `Prefixes`, declared non-persistent — and the reason it beats a reserved key is the owner's own question. |
| [D-379](90-decision-log.md) | 2026-08-25 | ⚠️ D-434 — der Standard ist `1` | Multiplicity is never nothing: unset means `0..1`. |
| [D-380](90-decision-log.md) | 2026-08-25 | agreed | R28–R32 is implemented, and it is one renderer: the chooser. Its test is outcomes, never rows. |
| [D-381](90-decision-log.md) | 2026-08-25 | agreed | One settings panel, for a node and for an attribute alike — and it closed a hole rather than only tidying. |
| [D-382](90-decision-log.md) | 2026-08-25 | agreed | The icon is a mark of a node, not one of its settings — it moves to the head of the Display band. Its |
| [D-383](90-decision-log.md) | 2026-08-25 | agreed | A renderer is always resolved, and the panel must show *which* one — shown, never written. |
| [D-384](90-decision-log.md) | 2026-08-26 | agreed | The labels panel goes through a renderer, and it is enterable — locale at the top, the short roles on one line, `help` on its own row. |
| [D-385](90-decision-log.md) | 2026-08-26 | ⚠️ D-390 | A setting carries a category, and the panel groups by it: display · rules · internal. |
| [D-386](90-decision-log.md) | 2026-08-26 | agreed | `help` leaves the fallback chain. A role nobody wrote falls back to the |
| [D-387](90-decision-log.md) | 2026-08-26 | agreed | One locale is declared *the neutral one*; «neutral» stops being something a person picks. |
| [D-388](90-decision-log.md) | 2026-08-26 | agreed | An explanation lives in a question mark beside its heading, and it is written for somebody who has not read the concept. U31. |
| [D-389](90-decision-log.md) | 2026-08-26 | agreed | Developer mode is not a setting on a node. It is a WordPress option and a |
| [D-390](90-decision-log.md) | 2026-08-26 | agreed | A setting's category is *whose it is*, and where it belongs to a type the group is named after that type. `validator` joins `renderer` and `converter… |
| [D-391](90-decision-log.md) | 2026-08-26 | agreed | The screen's paint moves out of PHP into `assets/admin.css`, enqueued on that screen's own hook. |
| [D-392](90-decision-log.md) | 2026-08-26 | agreed | The page saves, not the field. One form around a whole panel and the save button in the page head — and stage two, saving on leaving a field, is swit… |
| [D-393](90-decision-log.md) | 2026-08-26 | agreed | A record is drawn by a renderer, and its subject is the *model* node — the fourth hand-built panel to go through R1. |
| [D-394](90-decision-log.md) | 2026-08-26 | agreed | A decimal loses its storage padding at the boundary, on read — not in the core, and not in the check that had enshrined it. |
| [D-395](90-decision-log.md) | 2026-08-26 | agreed | Choosing a node is done in a tree, never in a flat list — the chooser cell is the second cell of the one walker. |
| [D-396](90-decision-log.md) | 2026-08-26 | agreed | `hide` on a node hides it from the tree, and the tree carries a *show hidden* switch that is off by default. |
| [D-397](90-decision-log.md) | 2026-08-26 | agreed | The installation gets its own screen, under the modeller — and D-079 already decided which of its facts may live there. |
| [D-398](90-decision-log.md) | 2026-08-26 | ⚠️ ersetzt | A bounding boolean is a *choice* and not a switch, because a two-state switch cannot express a one-way bound. |
| [D-399](90-decision-log.md) | 2026-08-26 | agreed | `hide` and `read_only` are freely settable on any node, whatever an ancestor says — they leave the bounding category. And `hide` puts the renderer ou… |
| [D-400](90-decision-log.md) | 2026-08-26 | agreed | A constant is drawn as a *reference to it*, resolved to the label role the referring edge asks for — and a renderer that cannot serve a purpose is no… |
| [D-401](90-decision-log.md) | 2026-08-26 | ⚠️ D-404 — eine Zeile an der Installationsidentität | A `bool` setting has exactly two states, and «not set» is not one of them: the control shows the stored value if there is one, otherwise the key's de… |
| [D-402](90-decision-log.md) | 2026-08-26 | agreed | A subtype has its ancestor's settings until it says otherwise, and «otherwise» is said *per key*, never per node. Stated as fact by the owner; it con… |
| [D-403](90-decision-log.md) | 2026-08-26 | agreed | Every setting write is journalled, recorded against its |
| [D-404](90-decision-log.md) | 2026-08-26 | agreed | A setting key's own default is a setting written at the installation identity. Nowhere new, and it supersedes what D-401 asked for. |
| [D-405](90-decision-log.md) | 2026-08-26 | agreed | `mandatory` is removed as a setting key. The multiplicity already says it: a floor of one |
| [D-406](90-decision-log.md) | 2026-08-26 | agreed | `hide` and `read_only` become free in both directions in the code — the half of D-399 that was written and never built. |
| [D-407](90-decision-log.md) | 2026-08-26 | agreed | The `order` setting key is removed. Ordering is the `position` column on `relations`, which is the only place that ever held it. |
| [D-408](90-decision-log.md) | 2026-08-26 | agreed | The modelling screen gets its first script — eight lines that keep the tree where it was, and nothing else. |
| [D-409](90-decision-log.md) | 2026-08-26 | agreed | A setting has no multiplicity. One key, one answer, per place — and «edge-only» stops being a property of `multiplicity` because an attribute *is* th… |
| [D-410](90-decision-log.md) | 2026-08-26 | agreed | An attribute has a name in every language — it carries labels, like a node. Answers OQ-095. |
| [D-411](90-decision-log.md) | 2026-08-26 | agreed | The narrowing rule is gone. An attribute may reopen anything a node said. Supersedes D-312's bounding/choosing split. |
| [D-412](90-decision-log.md) | 2026-08-26 | agreed | A `bool` may not have a floor of zero. Two states means it is always answered. |
| [D-413](90-decision-log.md) | 2026-08-26 | agreed | `settings` gains a `path` column — the address a setting needs to say |
| [D-414](90-decision-log.md) | 2026-08-26 | agreed | The prefix exponent is connected — D-378 works four days after it was decided, and the column has a consumer instead of a claim. |
| [D-415](90-decision-log.md) | 2026-08-26 | agreed | The tree row carries an eye: hiding a node is one click, in front of the plus, and only while *show hidden nodes* is on. |
| [D-416](90-decision-log.md) | 2026-08-26 | agreed | One place assembles the address a person returns to after an act, and it carries every circumstance. |
| [D-417](90-decision-log.md) | 2026-08-26 | agreed | The message after an act is an overlay, not a band at the top — and that is what was making the page jump. |
| [D-418](90-decision-log.md) | 2026-08-26 | agreed | The attribute's target is picked in the tree, and a chooser's dialog id carries its field name. |
| [D-419](90-decision-log.md) | 2026-08-26 | agreed | The saved scroll offset is read once, before anything can scroll — and the `#fragment` is gone. That was the bug that made the whole script useless. |
| [D-420](90-decision-log.md) | 2026-08-26 | agreed | The script saves what actually scrolls — the |
| [D-421](90-decision-log.md) | 2026-08-26 | agreed | The three composed types the concept names as its own test now exist — and measured at three rungs, C116's *works immediately* is true of the model a… |
| [D-422](90-decision-log.md) | 2026-08-26 | agreed | `don't-render` has no purpose, no variants and no say over the tree — it is one renderer that means *this is not drawn*, everywhere. |
| [D-423](90-decision-log.md) | 2026-08-26 | agreed | Settings are materialised into the inheriting node and into the attribute, `reset` becomes a *pull*, and — measured — this changes nothing on the rea… |
| [D-424](90-decision-log.md) | 2026-08-26 | agreed | The confirmation for pushing a change downwards is *one* list for the whole panel — every affected setting named, each with a yes/no switch. |
| [D-425](90-decision-log.md) | 2026-08-26 | agreed | A `path` survives inheriting and must be *remapped* on duplicating — because inheritance keeps the edge ids and a copy does not. |
| [D-426](90-decision-log.md) | 2026-08-26 | agreed | Hiding a node is a |
| [D-427](90-decision-log.md) | 2026-08-26 | agreed | History stays in one home: the journal becomes restorable, and the live tables keep exactly one row per thing. Answers OQ-102. |
| [D-428](90-decision-log.md) | 2026-08-26 | agreed | A simple type's node is called by a spelled-out name — `Integer`, not `int` — while the enum value stays the identifier. |
| [D-429](90-decision-log.md) | 2026-08-26 | agreed | A `bool` setting has exactly two controls: the switch, and `reset`. There is no bin. |
| [D-430](90-decision-log.md) | 2026-08-26 | agreed | A simple type gets a preview of *itself* — one field, drawn both ways. The branch check was answering the wrong question. |
| [D-431](90-decision-log.md) | 2026-08-26 | agreed | A setting is the system's. No screen creates one, so nothing has to delete one — and `reset` therefore only ever pulls. Closes row 48. |
| [D-432](90-decision-log.md) | 2026-08-26 | agreed | `Identity` becomes real: everything renderable is one, and `render()` takes it instead of a union. Implements D-091, which said so and was built othe… |
| [D-433](90-decision-log.md) | 2026-08-26 | agreed | Choosing several registered names in order gets its own renderer — a `` cannot express either half. |
| [D-434](90-decision-log.md) | 2026-08-26 | agreed | The standard multiplicity becomes `1`. Supersedes the value in D-379, which keeps everything else it says. |
| [D-435](90-decision-log.md) | 2026-08-26 | agreed | An attribute is reordered with the same two buttons a node has — and `position` turns out to belong to the |
| [D-436](90-decision-log.md) | 2026-08-26 | agreed | `name` belongs on `Identity` too: a node and an edge both have one, and both are translatable the same way. Supersedes the «only» in D-080. |
| [D-437](90-decision-log.md) | 2026-08-26 | agreed | `Identity` and *renderable* are two things, and D-091 merged them. The seam is named now; the signature moves when there is a second implementor. |
| [D-438](90-decision-log.md) | 2026-08-26 | agreed | A setting is a renderable object too — and it is the second implementor the contract needed. |
| [D-439](90-decision-log.md) | 2026-08-26 | agreed | Everything that is displayed implements `Renderable`. Measured: three of twelve do. |
