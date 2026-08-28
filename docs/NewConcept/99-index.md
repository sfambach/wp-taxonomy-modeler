# 99 · Index — eine Zeile pro Entscheidung und pro Frage

⚠️ **Erzeugt, nicht geschrieben.** `php scripts/dev/concept-index.php` baut diese Datei aus
[`90-decision-log.md`](90-decision-log.md) und [`91-open-questions.md`](91-open-questions.md).
**Sie ist nie die Autorität** — `PR-3` sagt, entschieden ist, was im Log steht, und eine
verkürzte Entscheidung wäre eine neue, der niemand zugestimmt hat. *Hier steht, **wo** etwas
steht, damit man 432 Entscheidungen überblicken kann, ohne 527 KB zu lesen.*

| | |
|---|---|
| Entscheidungen | **487**, davon **31** ersetzt oder teilweise überholt |
| Offene Fragen | **125**, davon **32** noch offen |

## Offene Fragen

⚠️ *Zuerst, weil sie die Arbeit blockieren und Entscheidungen sie nicht.*

| Frage | Stand | Worum es geht |
|---|---|---|
| [OQ-001](91-open-questions.md) | beantwortet | What is in the shared base of node and relation? |
| [OQ-002](91-open-questions.md) | beantwortet | If the tree is inheritance only, what are the other edges? |
| [OQ-003](91-open-questions.md) | beantwortet | Is `Relation.type` a node or an enum? |
| [OQ-004](91-open-questions.md) | beantwortet | Do node subtypes exist at all? |
| [OQ-005](91-open-questions.md) | beantwortet | `RendererRegistry` or `RendererRegister`? |
| [OQ-006](91-open-questions.md) | beantwortet | Renderer contract: what is the actual method set? |
| [OQ-007](91-open-questions.md) | **offen** | Where do renderer, converter and validators attach? |
| [OQ-008](91-open-questions.md) | beantwortet | Must every object have a changelog entry? |
| [OQ-009](91-open-questions.md) | beantwortet | Is the delivery target still a WordPress plugin? |
| [OQ-010](91-open-questions.md) | beantwortet | Is an *attribute* the same thing as an *edge*? |
| [OQ-011](91-open-questions.md) | beantwortet | What is an attribute's *type*? |
| [OQ-012](91-open-questions.md) | beantwortet | Custom tables, or WordPress terms/posts? |
| [OQ-013](91-open-questions.md) | beantwortet | What exactly is a "setting", versus an attribute? |
| [OQ-014](91-open-questions.md) | beantwortet | Where does the renderer run: PHP or JavaScript? |
| [OQ-015](91-open-questions.md) | beantwortet | Where does the content live? |
| [OQ-016](91-open-questions.md) | beantwortet | Is "setting" one thing, or two? |
| [OQ-017](91-open-questions.md) | beantwortet | Which attributes does every node have? |
| [OQ-018](91-open-questions.md) | **offen** | Where does the value of an extended attribute live? |
| [OQ-019](91-open-questions.md) | beantwortet | Cycles and depth in the render descent |
| [OQ-020](91-open-questions.md) | beantwortet | Loading the subgraph without an N+1 |
| [OQ-021](91-open-questions.md) | **offen** | Composition and aggregation: what is the difference here? |
| [OQ-022](91-open-questions.md) | beantwortet | One settings table, or one per owner kind? |
| [OQ-023](91-open-questions.md) | beantwortet | Is inheritance one edge kind, or a separate construct? |
| [OQ-024](91-open-questions.md) | **offen** | How are resolved settings computed without melting down? |
| [OQ-025](91-open-questions.md) | beantwortet | How is a deep override addressed and stored? |
| [OQ-037](91-open-questions.md) | beantwortet | What exactly happens when an override is promoted? |
| [OQ-038](91-open-questions.md) | beantwortet | Is a chooser a renderer? |
| [OQ-039](91-open-questions.md) | beantwortet | Where do installation-wide settings live? |
| [OQ-026](91-open-questions.md) | beantwortet | A part used in only one place: a node, or something smaller? |
| [OQ-027](91-open-questions.md) | **offen** | Does an attribute freeze its definition, or track it? |
| [OQ-028](91-open-questions.md) | beantwortet | Is the set of label roles fixed, or extensible? |
| [OQ-029](91-open-questions.md) | beantwortet | Are the length hints advisory or enforced? |
| [OQ-030](91-open-questions.md) | beantwortet | May a model author write their own validator message? |
| [OQ-031](91-open-questions.md) | **offen** | How does existing data survive a model change? |
| [OQ-032](91-open-questions.md) | **offen** | Is the base name required, and unique anywhere? |
| [OQ-033](91-open-questions.md) | beantwortet | Where does preview test data live? |
| [OQ-034](91-open-questions.md) | beantwortet | Is the preview a renderer, or a caller of one? |
| [OQ-035](91-open-questions.md) | **offen** | Can a relation reach something that is not a model node? |
| [OQ-036](91-open-questions.md) | beantwortet | Do instances share the identity space? |
| [OQ-040](91-open-questions.md) | beantwortet | Is a currency a branch of units, or a separate concept? |
| [OQ-041](91-open-questions.md) | beantwortet | Is a prefix a node or an enum? |
| [OQ-042](91-open-questions.md) | beantwortet | Does an attribute's type name one node, or a branch? |
| [OQ-043](91-open-questions.md) | beantwortet | Is the unit tree shipped, or authored? |
| [OQ-044](91-open-questions.md) | beantwortet | How are calculations modelled? |
| [OQ-045](91-open-questions.md) | beantwortet | What can a calculation expression reach? |
| [OQ-046](91-open-questions.md) | beantwortet | When does a model calculation run? |
| [OQ-047](91-open-questions.md) | beantwortet | What is the expression language, and who writes it? |
| [OQ-048](91-open-questions.md) | beantwortet | How does the tool know where data may be entered? |
| [OQ-049](91-open-questions.md) | beantwortet | Can a label be frozen at the moment of use? |
| [OQ-050](91-open-questions.md) | beantwortet | What does a tool-independent export look like? |
| [OQ-051](91-open-questions.md) | beantwortet | Does staged resolution need the intermediate model versions? |
| [OQ-052](91-open-questions.md) | beantwortet | What can the resolver offer beyond showing a conflict? |
| [OQ-053](91-open-questions.md) | beantwortet | What happens to a model that cannot be satisfied? |
| [OQ-054](91-open-questions.md) | beantwortet | Is a currency amount stored as entered, or normalised? |
| [OQ-055](91-open-questions.md) | beantwortet | Where does an exchange rate come from? |
| [OQ-056](91-open-questions.md) | beantwortet | How many conditions can one query carry? |
| [OQ-057](91-open-questions.md) | beantwortet | Is undo in scope? |
| [OQ-058](91-open-questions.md) | beantwortet | How does a subtype narrow an inherited attribute? |
| [OQ-059](91-open-questions.md) | beantwortet | May an override widen, or only narrow? |
| [OQ-060](91-open-questions.md) | beantwortet | Optimistic or pessimistic locking? |
| [OQ-061](91-open-questions.md) | beantwortet | Does the descent walk the model, the record, or both? |
| [OQ-062](91-open-questions.md) | beantwortet | What does *not computable* look like? |
| [OQ-063](91-open-questions.md) | beantwortet | What identifies a record, for finding duplicates? |
| [OQ-064](91-open-questions.md) | beantwortet | How is a contains-search made fast? |
| [OQ-065](91-open-questions.md) | beantwortet | Does a seed item need a provenance marker? |
| [OQ-066](91-open-questions.md) | beantwortet | What happens to data when a node is moved? |
| [OQ-067](91-open-questions.md) | beantwortet | Does a parked record still hold its unique values? |
| [OQ-068](91-open-questions.md) | beantwortet | Is there a symmetric declaration for aggregation-only? |
| [OQ-069](91-open-questions.md) | beantwortet | Views: deferred, with an entry criterion |
| [OQ-070](91-open-questions.md) | beantwortet | How does renderer resolution stay cheap in a long list? |
| [OQ-071](91-open-questions.md) | **offen** | How is borrowed WordPress marked? |
| [OQ-072](91-open-questions.md) | **offen** | How is the importer told what maps to what? |
| [OQ-073](91-open-questions.md) | beantwortet | What is the branch without data called? |
| [OQ-074](91-open-questions.md) | beantwortet | Is there an enum filled at runtime? |
| [OQ-075](91-open-questions.md) | beantwortet | How does a record have versions? |
| [OQ-076](91-open-questions.md) | beantwortet | Can a reader hand a parameter to a rendering? |
| [OQ-077](91-open-questions.md) | beantwortet | A conversion that depends on the other value |
| [OQ-078](91-open-questions.md) | beantwortet | Where is the *relationship as a node* pattern taught? |
| [OQ-079](91-open-questions.md) | beantwortet | Where does the shape stop being suitable? |
| [OQ-080](91-open-questions.md) | beantwortet | Is there a page per record? |
| [OQ-081](91-open-questions.md) | beantwortet | Which token do the Gutenberg blocks and the text domain use? |
| [OQ-082](91-open-questions.md) | **offen** | How does the split behave? |
| [OQ-083](91-open-questions.md) | beantwortet | Does restoring a node put its promoted children back? |
| [OQ-084](91-open-questions.md) | beantwortet | Does a node's version move when only its path was rewritten? |
| [OQ-085](91-open-questions.md) | **offen** | How much precision does a decimal have? |
| [OQ-086](91-open-questions.md) | **offen** | Where does a subtype's override of an inherited attribute hang? |
| [OQ-087](91-open-questions.md) | **offen** | How does a core renderer produce a word a person reads? |
| [OQ-088](91-open-questions.md) | **offen** | Where does a time of day live? |
| [OQ-089](91-open-questions.md) | beantwortet | Is a field's rule set one setting or three? |
| [OQ-090](91-open-questions.md) | beantwortet | Is a renderer a name, or is it a node? |
| [OQ-091](91-open-questions.md) | **offen** | Is the tree row a renderer of its own, and which role does a surface read labels in? |
| [OQ-092](91-open-questions.md) | beantwortet | Does `settings` need a `path` column, so one owner can hold several defaults? |
| [OQ-093](91-open-questions.md) | **offen** | How does a setting key say which subjects it applies to? |
| [OQ-094](91-open-questions.md) | **offen** | How does a person enter a character that is not on their keyboard? |
| [OQ-095](91-open-questions.md) | beantwortet | May an attribute own labels, or is its name only a column? |
| [OQ-096](91-open-questions.md) | **offen** | Is a subtype substitutable for its parent where a reference is typed? |
| [OQ-097](91-open-questions.md) | beantwortet | Should settings be materialised into the inheriting node instead of resolved? |
| [OQ-098](91-open-questions.md) | **offen** | Is a value that can only live in one place a field rather than a setting? |
| [OQ-099](91-open-questions.md) | beantwortet | A descendant's value for an inherited attribute has no address, and it already broke a decision |
| [OQ-100](91-open-questions.md) | **offen** | Should a setting key's *name* be translatable, even though the key is not? |
| [OQ-101](91-open-questions.md) | beantwortet | Hiding a node and not drawing its fields are two things sharing one key. Where does each belong? |
| [OQ-102](91-open-questions.md) | beantwortet | Should the live tables keep old versions with a delete flag, or should the journal become restorable? |
| [OQ-103](91-open-questions.md) | **offen** | Should the whole model be read once into an identity map, with writes going back per object? |
| [OQ-104](91-open-questions.md) | **offen** | Should a value be passed as an object rather than looked up by edge id? |
| [OQ-105](91-open-questions.md) | beantwortet | `records.model_id` points at a node. Should it not say so? |
| [OQ-106](91-open-questions.md) | beantwortet | Do settings split the same way, into `NodeSetting` and `EdgeSetting`? |
| [OQ-107](91-open-questions.md) | **offen** | What declares a free setting? Today nothing does. |
| [OQ-108](91-open-questions.md) | beantwortet | How does a tree row say how many records a class has? |
| [OQ-109](91-open-questions.md) | **offen** | One key holds one answer, so where does an *ordered list* of renderers live? |
| [OQ-110](91-open-questions.md) | beantwortet | Does hiding a placement hide what hangs below it? |
| [OQ-111](91-open-questions.md) | beantwortet | `hide` and a null renderer say the same thing. Which one owns it? |
| [OQ-112](91-open-questions.md) | beantwortet | Does the renderer descend, and is everything still loaded before it starts? |
| [OQ-113](91-open-questions.md) | beantwortet | D-426 and D-448–D-456 contradict each other about `hide`, and the fault D-426 fixed still reproduces |
| [OQ-114](91-open-questions.md) | beantwortet | Do `read_only` and `persistent` follow `hide` out of the settings? |
| [OQ-115](91-open-questions.md) | **offen** | A write through a non-persistent field: refused, or silently skipped? |
| [OQ-116](91-open-questions.md) | **offen** | A renderer declares its event handlers, the field registers, WordPress dispatches. How exactly? |
| [OQ-117](91-open-questions.md) | **offen** | Should `RenderContext` carry only settings, and the data travel separately? |
| [OQ-118](91-open-questions.md) | beantwortet | «Render no further» — which walk does a node's `hide` stop? |
| [OQ-119](91-open-questions.md) | **offen** | In one table, what tells a setting from a field value? |
| [OQ-120](91-open-questions.md) | **offen** | Deklariert ein Renderer seine Eigenschaften, und gilt derselbe Schnitt für Konverter und Validatoren? |
| [OQ-121](91-open-questions.md) | beantwortet | Was umfasst «Update», und wie wird eine Zeile als unlöschbar angesagt? |
| [OQ-122](91-open-questions.md) | **offen** | Wo wohnen Export und Import: auf der Konfigurationsseite oder auf einer eigenen? |
| [OQ-123](91-open-questions.md) | beantwortet | Wo wohnt «normaler Knoten» in der Supported-Liste, und wie beansprucht ein Renderer einen *bestimmten* Knoten? |
| [OQ-124](91-open-questions.md) | beantwortet | Gibt es im Code spezialisierte Knotenklassen? D-036 hat das delegiert und um Korrektur gebeten |
| [OQ-125](91-open-questions.md) | **offen** | Heissen «der Wert ist ein Zeiger» und «der Wert liegt als Zeiger» weiter fast gleich? |

## Entscheidungen nach Sachgebiet

⚠️ *Kein neues Merkmal — das ist die Dokumentspalte jeder Log-Zeile, nur lesbar gemacht.
Eine Entscheidung steht in mehreren Gebieten, wenn sie mehrere betrifft.*

| Sachgebiet | Entscheidungen | davon überholt |
|---|---|---|
| [10-domain-core](10-domain-core.md) | **216** | 19 |
| [30-renderer](30-renderer.md) | **192** | 11 |
| [20-interaction](20-interaction.md) | **98** | 4 |
| [50-wordpress-persistence](50-wordpress-persistence.md) | **90** | 4 |
| [70-migration](70-migration.md) | **35** | 1 |
| [40-i18n](40-i18n.md) | **32** | 2 |
| [01-glossary](01-glossary.md) | **18** | 1 |
| [60-calculation](60-calculation.md) | **17** | — |
| [02-field-and-setting](02-field-and-setting.md) | **12** | — |
| [00-vision-and-scope](00-vision-and-scope.md) | **9** | — |

## Entscheidungen

⚠️ *Eine überholte Entscheidung steht hier mit ihrem Nachfolger, weil sie
sich sonst wie eine gültige liest — der Fehler, der an einem Tag drei falsche Antworten kostete.*

| Nr. | Datum | Stand | Sachgebiet | Worum es geht |
|---|---|---|---|---|
| [D-001](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | Restart the concept. `docs/NewConcept/` is the single source of truth; the pre-2026-08-22 planning round moves to `docs/legacy/`, frozen, quarry only. |
| [D-002](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | English |
| [D-003](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | Content leaves `legacy/` only through a reviewed harvest sheet with an explicit *take / rework / drop* per item. No silent inheritance. |
| [D-004](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | No production code until 10 Domain core is `locked`. Throwaway spikes allowed if marked throwaway. |
| [D-005](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | Documentation style: |
| [D-006](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | Order of work: |
| [D-007](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence | The model is stored in tables owned by this plugin |
| [D-008](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | Rule reset. |
| [D-009](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | Layered code style. |
| [D-010](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | One file per topic. |
| [D-011](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | A setting is an attribute. |
| [D-012](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | Inheritance is an edge — one construct, its own kind. |
| [D-013](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | The two resolutions must never be mixed. |
| [D-014](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence, 30-renderer | Load in one step. |
| [D-015](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | Base settings live on the referenced node; the using attribute overrides sparsely. |
| [D-016](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence | Caching is an implementation concern, bounded by two rules: |
| [D-017](90-decision-log.md) | 2026-08-22 | agreed`, placement corrected by [D-135](#) | 10-domain-core | A part used in exactly one place stays an ordinary node |
| [D-018](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | Renderer split: a completely different presentation is a separate renderer; a parameterisable detail is a renderer setting. |
| [D-019](90-decision-log.md) | 2026-08-22 | agreed` (owner objected to the single-table draft; reasoning in [40 I18n](40-i18n.md)) | 40-i18n, 50-wordpress-persistence | Labels get their own table, separate from settings. |
| [D-020](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt durch D-209, D-386 | 40-i18n, 10-domain-core | Überholt durch D-209 und D-386 |
| [D-021](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | Renderers are PHP. |
| [D-022](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 40-i18n | A node name is required and explicitly not unique. |
| [D-023](90-decision-log.md) | 2026-08-22 | agreed | 01-glossary, 10-domain-core, 40-i18n | `Label` keeps its name; roles lose the prefix. |
| [D-024](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | The concept is also published as a rendered page |
| [D-025](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | The type of an attribute is the node the relation points to. |
| [D-026](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | Modelling view and data view are separate layers. |
| [D-027](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | Three layers, not two: |
| [D-028](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer, 50-wordpress-persistence | Test data is ordinary data, flagged. |
| [D-029](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer, 10-domain-core | The default editor is the data editor. |
| [D-030](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | A default may be a reference, and there may be several. |
| [D-031](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 01-glossary | An attribute *is* a relation. |
| [D-032](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | The two-fold principle. |
| [D-033](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | Orphaned overrides are never cascade-deleted. |
| [D-034](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | Node selection is one unified interaction |
| [D-035](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | A chooser takes two nodes: |
| [D-036](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt durch D-484 | 10-domain-core | ⚠️ Die Repräsentationshälfte ist ersetzt durch D-484: der Eigentümer will |
| [D-037](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | A model change is breaking or not depending on the existing data, not on the kind of change. |
| [D-038](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | Relation kinds do not belong in the tree. |
| [D-039](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | A unit value is one notion |
| [D-040](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | Permitted prefixes are an allow-list setting on the attribute |
| [D-041](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 30-renderer | The type of an attribute is a branch, and the branch is polymorphic. |
| [D-042](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | Type node and model node are the same construct in different roles. |
| [D-043](90-decision-log.md) | 2026-08-22 | agreed` (design proposal, owner asked for it) | 60-calculation | A calculation is a property of an attribute, not a relation kind. |
| [D-044](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer, 40-i18n | `display_node_name` is a renderer, not a data type. |
| [D-045](90-decision-log.md) | 2026-08-22 | agreed` (design proposal) | 60-calculation | A calculation names its inputs by relative edge-id path |
| [D-046](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | Permitted sub-nodes are a setting holding a mode plus a list |
| [D-047](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | `Gramm` is the base unit; the prefix carries the kilo. |
| [D-048](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 01-glossary | The useful axis is *when is the content determined*, not *type versus model*. |
| [D-049](90-decision-log.md) | 2026-08-22 | agreed | 40-i18n, 30-renderer | A unit reference is data; the text for it is a label. |
| [D-050](90-decision-log.md) | 2026-08-22 | agreed | ⚠️ **keins** | Do not ask what cannot matter. |
| [D-051](90-decision-log.md) | 2026-08-22 | agreed` (design proposal; owner: *the data must go in correctly and come back out correctly*) | 10-domain-core, 50-wordpress-persistence | A unit value is stored in its base unit, with the chosen prefix beside it as the display form. |
| [D-052](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | Test data have three sources, in fallback order: |
| [D-053](90-decision-log.md) | 2026-08-22 | agreed | 70-migration, 40-i18n | Rename is not replace. |
| [D-054](90-decision-log.md) | 2026-08-22 | agreed | 70-migration | The conflict resolver is the mechanism for model change. |
| [D-055](90-decision-log.md) | 2026-08-22 | agreed | 70-migration, 50-wordpress-persistence | Data reference node ids, never names |
| [D-056](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | A control offers only real choices. |
| [D-057](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | Numbers are whole or decimal, never floating point. |
| [D-058](90-decision-log.md) | 2026-08-22 | agreed | 70-migration, 30-renderer | Two exports, kept apart. |
| [D-059](90-decision-log.md) | 2026-08-22 | agreed | 70-migration | Import resolution is the conflict resolver. |
| [D-060](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt durch D-210 | 70-migration, 10-domain-core | Überholt durch D-210 |
| [D-061](90-decision-log.md) | 2026-08-22 | agreed | 70-migration, 10-domain-core | Migration needs the changes, not the snapshots — and the changelog is the migration script. |
| [D-062](90-decision-log.md) | 2026-08-22 | agreed | 70-migration | The resolver offers: map, map with transformation, bulk fill, fill by hand, delete. |
| [D-063](90-decision-log.md) | 2026-08-22 | agreed | 70-migration | A change that creates a new version warns at the moment it is made |
| [D-064](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | An amount is stored in its own currency; a frozen rate is stored beside it; the converted figure is derived. |
| [D-065](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 40-i18n | Track what describes, freeze what was agreed. |
| [D-066](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence | No table per model. The model is the schema. |
| [D-067](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | The model declares one reference currency; a frozen rate always points at it. |
| [D-068](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | The frozen rate belongs to the money type, not to a hand-made hidden field. |
| [D-069](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | Rates are fetched at the boundary into a rate table; the core only reads the table. |
| [D-070](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence | Search is a requirement, not an aspiration. |
| [D-071](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence | Values are stored in typed columns, never in one stringly `value`. |
| [D-072](90-decision-log.md) | 2026-08-22 | agreed | 60-calculation, 50-wordpress-persistence | Computed values are materialised |
| [D-073](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | A currency value is an ordinary composed node with registered behaviour |
| [D-074](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence | Separate typed value columns, including whole numbers apart from decimals |
| [D-075](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | A numeral-system change is a converter |
| [D-076](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer, 50-wordpress-persistence | Converters divide into invertible and lossy, and only invertible ones may serve input or search. |
| [D-077](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | A node may carry several converters; which one applies is a setting. |
| [D-078](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt durch D-084 | ⚠️ **keins** | Veraltet, ersetzt durch D-084. |
| [D-079](90-decision-log.md) | 2026-08-22 | agreed` (confirmed by the owner 2026-08-22) | 50-wordpress-persistence | An installation-wide default is a setting on a reserved installation identity |
| [D-080](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt durch D-436 | 10-domain-core | Überholt durch D-436 |
| [D-081](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | Every object has at least one changelog item — the seed's `1..*` was right. |
| [D-082](90-decision-log.md) | 2026-08-22 | agreed` (proposal) | 10-domain-core, 50-wordpress-persistence | Every node has exactly four fixed attributes: `id`, `version`, `name`, `path`. |
| [D-083](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt durch D-133 | 50-wordpress-persistence | Überholt durch D-133 |
| [D-084](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence, 10-domain-core | Settings are one construct with one mechanism and a reserved namespace. Supersedes D-078. |
| [D-085](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence | The distinction is engine-owned versus type-owned keys, not *does the engine branch on it*. |
| [D-086](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence, 10-domain-core | Edge-only settings exist; node-only settings do not. |
| [D-087](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | An override is the same thing wherever it sits; only its owner differs. |
| [D-088](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt | 10-domain-core | An override may narrow *and* widen. No monotonicity rule. |
| [D-089](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | Concurrency, answered per layer. |
| [D-090](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence | Every foreign key column ends in `_id`. |
| [D-091](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | The render descent, and one contract for both. |
| [D-092](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | R3 answered: a node renderer renders exactly one node; an edge renderer handles one value or many. |
| [D-093](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer, 10-domain-core | Every setting resolves on its own key. |
| [D-094](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | Ground rule: when rendering, honour every attribute — no special arrangements, the same everywhere. |
| [D-095](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt durch D-096 | 30-renderer | Überholt durch D-096 |
| [D-096](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | The preview needs no special arrangement — it calls render twice |
| [D-097](90-decision-log.md) | 2026-08-22 | **corrected by [D-098](#)** — that invented a special case | 30-renderer | at the edge |
| [D-098](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | A container renderer is chosen like every other renderer. Corrects D-097. |
| [D-099](90-decision-log.md) | 2026-08-22 | agreed` (the declared-root part is a proposal) | 30-renderer | A second eligibility rule: grouping renderers are not offered for data-type nodes |
| [D-100](90-decision-log.md) | 2026-08-22 | agreed` (confirmed by the owner 2026-08-23) | 30-renderer, 60-calculation | The descent stops in two different ways, and they are not the same event. |
| [D-101](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | The preview is the pre-flight check — its third and most important job. |
| [D-102](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 30-renderer | Modelling guidance: a symmetric relationship is better modelled as membership in a group than as pairwise links. |
| [D-103](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | Depth belongs to the rendering, not to the node. The caller decides and the context carries it. |
| [D-104](90-decision-log.md) | 2026-08-22 | agreed | 60-calculation, 30-renderer | A depth limit is a rendering concern only. Calculations must never be truncated. |
| [D-105](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | A reference renderer: it draws the target's label and a link, and does not descend. |
| [D-106](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | Display and input are independent, and the display is not binary. Clarifies D-105. |
| [D-107](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt durch D-108 | 30-renderer | Überholt durch D-108 |
| [D-108](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | Inline and popup are two separate chooser renderers, not one with a setting. Supersedes that part of D-107. |
| [D-109](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | List or tree is derived from the branch, not chosen. |
| [D-110](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | The branch root is excluded from the choice by default, with a setting to include it. |
| [D-111](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | The multi-step input, and the create form is the search field. |
| [D-112](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | The shown fields are the searched fields. |
| [D-113](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | Inline for searching, popup for creating. |
| [D-114](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 30-renderer | An attribute may carry a `unique` setting — enforced, not advisory. |
| [D-115](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 01-glossary | `unique` may name a group, forming a composite constraint — and it is not called *primary key*. |
| [D-116](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | A prefix is a node, not an enum — and the general rule is that a fixed value which might one day carry properties is a node. |
| [D-117](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 30-renderer | What a structure *looks like* is a renderer; what it *is* is a node with attributes. |
| [D-118](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | A node lays its attributes out in four groups, the same on every level. |
| [D-119](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 70-migration | A base scaffold ships and is imported once; afterwards it is ordinary authored content. |
| [D-120](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | A binding is a named slot in the installation configuration that points at a node — and nothing else. |
| [D-121](90-decision-log.md) | 2026-08-22 | **corrected by [D-122](#)** | 10-domain-core | ~~Deletion protection is derived from references, not from a *template* flag.~~ The reference half stands and is kept by D-122; the rejection of a ma… |
| [D-122](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt durch D-194 | 10-domain-core | Überholt durch D-194 |
| [D-123](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | Deletion is two-stage: park, then purge. |
| [D-124](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | Moving a node is referentially free and semantically a model change. |
| [D-125](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | Deleting a referenced node parks every edge that points at it, and that is *an attribute was deleted* for each owning node |
| [D-126](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 30-renderer | The confirmation names the consequences and requires an act that cannot be reflexive. |
| [D-127](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | A trash entry is one deletion event, with everything that fell with it — and restore puts back the whole event. |
| [D-128](90-decision-log.md) | 2026-08-22 | agreed` (proposal answering C105) | 10-domain-core, 30-renderer | Three surfaces for a deletion, and they are different. |
| [D-129](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 70-migration | A demo pack ships beside the scaffold: an example tree with data, optional and importable. |
| [D-130](90-decision-log.md) | 2026-08-22 | agreed | 60-calculation | The expression is a structured tree, built with a picker rather than typed. |
| [D-131](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | A value reference resolves either to a |
| [D-132](90-decision-log.md) | 2026-08-22 | agreed`, derivation demoted | 10-domain-core | Standalone-versus-composed applies only to nodes whose instances are records. |
| [D-133](90-decision-log.md) | 2026-08-22 | ⚠️ ersetzt durch D-232 | 50-wordpress-persistence, 10-domain-core | Überholt durch D-232 |
| [D-134](90-decision-log.md) | 2026-08-22 | agreed | 50-wordpress-persistence | `record_values` keys on a path, with the last edge kept in `edge_id`. |
| [D-135](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | Composed-only nodes live under a `Kompositionen` node. Corrects D-017. |
| [D-136](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 30-renderer | A multi-valued composition creates its node automatically. |
| [D-137](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 70-migration | Converting between the kinds is a move, with one asymmetry. |
| [D-138](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | The `Kompositionen` node gets a binding, like every other root the engine must find. |
| [D-139](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | What has data of its own is decided by placement, visibly. |
| [D-140](90-decision-log.md) | 2026-08-22 | agreed | 60-calculation | An operand may be a backward path, and a backward aggregate is computed on read, never materialised. |
| [D-141](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 70-migration | Durability is a lifecycle question, not a storage-shape question. |
| [D-142](90-decision-log.md) | 2026-08-22 | agreed | 60-calculation | Contagion: a calculation that depends on a backward aggregate is itself computed on read, and is therefore not searchable. |
| [D-143](90-decision-log.md) | 2026-08-22 | agreed | 60-calculation | A computed value has three states, not two — and freezing dissolves the contagion problem. |
| [D-144](90-decision-log.md) | 2026-08-22 | agreed | 60-calculation, 30-renderer | Recalculation is an event, and staleness is information. |
| [D-145](90-decision-log.md) | 2026-08-22 | agreed | 60-calculation, 10-domain-core | A valuation method is a registered calculation strategy, and several valuations coexist as separate named attributes. |
| [D-146](90-decision-log.md) | 2026-08-22 | agreed | 60-calculation | A tracking list recalculates on save; a frozen list never does. |
| [D-147](90-decision-log.md) | 2026-08-22 | agreed | 60-calculation, 30-renderer | A computed value handles a missing input in one of three modes, set per attribute. |
| [D-148](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | Converters are a few *kinds* parameterised by data, not one hard-coded class per case. |
| [D-149](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | Notation is not structure. |
| [D-150](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer, 10-domain-core | `Widerstandswert` is a composed type — value plus tolerance — and tolerance defaults to 10%. |
| [D-151](90-decision-log.md) | 2026-08-22 | agreed | 40-i18n | Label roles are nodes: a seeded base set, extensible by the author. |
| [D-152](90-decision-log.md) | 2026-08-22 | agreed | 40-i18n | Length hints are settings on the role node. |
| [D-153](90-decision-log.md) | 2026-08-22 | agreed` — the doubt was resolved by [D-216](#) on 2026-08-23 | 40-i18n | Number is a column, not a role — `provisional`. |
| [D-154](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | A parked record keeps its `unique` values blocked; purging releases them. |
| [D-155](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 70-migration | Moving is one rule with two subjects, and it never loses data. |
| [D-156](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 70-migration | Promotion has everything it needs, because deletion is two-stage — and what it does depends on how many use the target. |
| [D-157](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 30-renderer | An unsatisfiable model is caught where it is created, reported rather than blocked — but data entry against it is barred. |
| [D-158](90-decision-log.md) | 2026-08-22 | agreed | 40-i18n, 30-renderer | A model author may replace a validator message, and it lives as a label — per validator. |
| [D-159](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | The descent has two inputs, both loaded before it starts, and there is only one mode. |
| [D-160](90-decision-log.md) | 2026-08-22 | agreed | 30-renderer | The preview renders a test data pack, not an empty shell. |
| [D-161](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core | The relation kind is not asked, it is read off the target's branch. |
| [D-162](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 70-migration | Moving a node between the branches rewrites every edge that points at it, and the data comes along. |
| [D-163](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | At multiplicity 1 the author no longer chooses inline versus own record — the branch chooses for them, and they must be told. |
| [D-164](90-decision-log.md) | 2026-08-22 | agreed | 10-domain-core, 50-wordpress-persistence | Records do not share the model's identity space. Model objects keep theirs. |
| [D-165](90-decision-log.md) | 2026-08-23 | agreed | 50-wordpress-persistence | Correctness has no limit, speed is promised to about three conditions, and the reporting case gets a flat projection rather than a cleverer query. |
| [D-166](90-decision-log.md) | 2026-08-23 | agreed` (confirmed by the owner 2026-08-23) | 10-domain-core, 30-renderer | What cannot have been meant is removed by the converter; what might have been meant is questioned by the validator. |
| [D-167](90-decision-log.md) | 2026-08-23 | agreed | 50-wordpress-persistence, 30-renderer | Contains is the default in the quick search, an operator field carries the filter, and there is no wildcard character. |
| [D-168](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt durch D-217 | 30-renderer | Überholt durch D-217 |
| [D-169](90-decision-log.md) | 2026-08-23 | agreed | 00-vision-and-scope, 50-wordpress-persistence | Yes, a WordPress plugin — and WordPress is used to the full, not held at arm's length. What is borrowed gets written down. |
| [D-170](90-decision-log.md) | 2026-08-23 | agreed` (confirmed by the owner 2026-08-23) | 50-wordpress-persistence, 00-vision-and-scope | The namespace is the marker, a ledger catches what the namespace cannot, and a second boundary is the intended shape of a port. |
| [D-171](90-decision-log.md) | 2026-08-23 | agreed | 00-vision-and-scope | WordPress is not underneath the core, it is around it, and every arrow points inward. |
| [D-172](90-decision-log.md) | 2026-08-23 | agreed | 70-migration, 10-domain-core | Undo is in scope. It is a step forward, not a rewind, and its reach is the trash. |
| [D-173](90-decision-log.md) | 2026-08-23 | agreed | 70-migration | Migration is not an undo case, and importing existing WordPress tables is its own tool. |
| [D-174](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 70-migration | A seeded node carries two marks: *came from the seed* and *changed since*. |
| [D-175](90-decision-log.md) | 2026-08-23 | agreed` (confirmed by the owner 2026-08-23) | 00-vision-and-scope, 10-domain-core | Content packs: named sets of model content that can be installed and removed again. The seed is simply the pack that ships. |
| [D-176](90-decision-log.md) | 2026-08-23 | agreed | 01-glossary | The pair is *Modell* and *Daten*, single unit *Datensatz*. *Bestand* is rejected. |
| [D-177](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core | A pack declares what it requires and marks what it contributes. It may add to another pack's branch and never change another pack's nodes. |
| [D-178](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | Pack authorship is a recording mode, not a label per node. |
| [D-179](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | The tree keeps everything the legacy tree did — the crowding is answered by a menu, not by removing abilities. |
| [D-180](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | Deleting a node asks whether the branch goes too; dragging moves whole branches, several at once; duplicating lands directly beneath with an indexed … |
| [D-181](90-decision-log.md) | 2026-08-23 | agreed`, default reversed by [D-238](#) | 20-interaction, 30-renderer | What may be picked is a property of the use site, not of the node. The legacy *hide* control is retired. |
| [D-182](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | Branch deletion stops being its own button and becomes the second answer in the delete dialog. |
| [D-183](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core | Having data is read off the branch: `Model` and `Kompositionen` have data, everything else is means to an end. |
| [D-184](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 10-domain-core | Small lists do not get a second storage mechanism; they get a cheaper editor. |
| [D-185](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt durch D-187, D-188 | 10-domain-core, 01-glossary | Überholt durch D-187 und D-188 |
| [D-186](90-decision-log.md) | 2026-08-23 | agreed | 00-vision-and-scope | V1–V9 are confirmed, and V5 gains a nuance that changes what it forbids. |
| [D-187](90-decision-log.md) | 2026-08-23 | agreed | 01-glossary | English is the standard for every term. German exists as a translation, where one is needed. |
| [D-188](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 01-glossary | The three branches are `Model`, `Compositions` and `Primitives`. |
| [D-189](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | Counts counts what there are several of, and the branch says what that is. |
| [D-190](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | The detail view's order is the order of dealing with a node, and it is not a special screen. |
| [D-191](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | Attributes show their core and load their detail on demand — for two reasons that agree. |
| [D-192](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | Three sections the legacy detail screen has no place for, because they were decided after it was built. |
| [D-193](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 01-glossary | Inside `Primitives` the sub-branch decides: data types yield a composition, constants yield an aggregation. Closes a gap in D-161. |
| [D-194](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 20-interaction | `Is template` is retired: provenance says what it said. Protection is separated out and narrowed. |
| [D-195](90-decision-log.md) | 2026-08-23 | agreed | 50-wordpress-persistence | `slug` is not ours. |
| [D-196](90-decision-log.md) | 2026-08-23 | agreed | 40-i18n, 01-glossary | The seeded label roles are `form`, `table`, `select`, `symbol` and `help`, offered together on the node. |
| [D-197](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | There is one chooser in the product. It picks and it creates, and it always looks the same. |
| [D-198](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | A control's state follows from what is actually choosable. |
| [D-199](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | The relations section holds one direction and is renamed `Used by`. |
| [D-200](90-decision-log.md) | 2026-08-23 | agreed | ⚠️ **keins** | The four remaining questions are deferred deliberately, each with the event that reopens it. |
| [D-201](90-decision-log.md) | 2026-08-23 | agreed | 60-calculation, 30-renderer | A *view* and a *report* are different things, and only one of them was ever the deferred question. |
| [D-202](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 60-calculation | Correction to D-201: a report does compute, at output time, and it joins. Both halves of my characterisation were wrong. |
| [D-203](90-decision-log.md) | 2026-08-23 | agreed | ⚠️ **keins** | Views, reports and the list-rendering optimisation are Release 2. |
| [D-204](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 20-interaction | Runtime extension: declared per branch including by whom, usable at once, reviewed afterwards. Closes OQ-074. |
| [D-205](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | A pending review shows in the tree, and it propagates up through collapsed ancestors. |
| [D-206](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | The front end is composed of small blocks, one node each, and the reference does the joining. |
| [D-207](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | A comparison block resolves to the nearest common ancestor, and that is the tree doing its job. |
| [D-208](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | A list block is a node plus a restriction. |
| [D-209](90-decision-log.md) | 2026-08-23 | agreed | 40-i18n, 01-glossary | `long` is gone. It was renamed `help`, and the fallback chain ends there. |
| [D-210](90-decision-log.md) | 2026-08-23 | agreed | 70-migration | A record keeps its version stamp. Resolving touches only what actually conflicted. |
| [D-211](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt durch D-229 | 50-wordpress-persistence, 10-domain-core | Überholt durch D-229 |
| [D-212](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | A project fact sheet is not a new block — it is D-206 under the display purpose. |
| [D-213](90-decision-log.md) | 2026-08-23 | agreed | 70-migration | An update corrects what the author never touched. D-119 is precised, not overturned. |
| [D-214](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 70-migration | Only one composing edge may point at a node — and my objection to that rule rested on my own misuse of a term. |
| [D-215](90-decision-log.md) | 2026-08-23 | agreed | 01-glossary | The term is `Data Pack`, not `Pack`. |
| [D-216](90-decision-log.md) | 2026-08-23 | agreed | 40-i18n | The `number` column holds a plural |
| [D-217](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt durch D-236 | 30-renderer | Überholt durch D-236 |
| [D-218](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 10-domain-core | Read-only renders under the display purpose — and a read-only field with neither calculation nor default is a model conflict. |
| [D-219](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 10-domain-core | A representation is a converter plus a renderer, and the mapping is model data. The only axis is invertible or not. |
| [D-220](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 10-domain-core | Whether something is one field or several is decided by the model, not by the display. The composed type is the unit of rendering. |
| [D-221](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 20-interaction | There is no *fixed value*. There is a restriction that collapses to one. |
| [D-222](90-decision-log.md) | 2026-08-23 | agreed | ⚠️ **keins** | A decision is the record of a choice, not a wall. Revising one is normal work. |
| [D-223](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 20-interaction | Automatic is a default, never a fact. |
| [D-224](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt | 30-renderer | A decorator is a renderer, and one layer of decoration is allowed. |
| [D-225](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt durch D-226 | 30-renderer, 20-interaction | Überholt durch D-226 |
| [D-226](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 20-interaction | Invertibility decides only whether a form can be written into. Replace or decorate is a free choice. Supersedes D-225. |
| [D-227](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | The rule counts possibilities, not entries. Refines D-198 and resolves its clash with D-056. |
| [D-228](90-decision-log.md) | 2026-08-23 | agreed | 50-wordpress-persistence | P5 stands untouched; a projection is not storage, and there are few of them by design. |
| [D-229](90-decision-log.md) | 2026-08-23 | agreed | 50-wordpress-persistence, 30-renderer, 10-domain-core | A medium is an ordinary type under `Model`, not a special construct — and it knows its own MIME type. |
| [D-230](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer | A medium is drawn along two axes: kind is detected, degree is configured — one renderer, not their product. |
| [D-231](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 20-interaction | The preview shows what the front end will show. Its only permitted deviation is bounding the size. |
| [D-232](90-decision-log.md) | 2026-08-23 | agreed | 50-wordpress-persistence, 10-domain-core | The branch decides where a value is stored, not the multiplicity. Supersedes D-133. |
| [D-233](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer | A page renderer is fine; D-091 rejected an interface, not the idea. |
| [D-234](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 30-renderer | The block decides *what* is shown; the renderer decides *how* it is drawn. |
| [D-235](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer | Grouping-renderer eligibility keys on the `data_types` binding — the proposal in D-099 is now buildable. |
| [D-236](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer | A node carries an ordered list of renderers: one mandatory, the rest optional additions. Supersedes the single-renderer half of D-217 and replaces D-… |
| [D-237](90-decision-log.md) | 2026-08-23 | agreed | 50-wordpress-persistence, 30-renderer | The identifying fields belong to the type; the columns a table happens to show do not. Resolves the D-112 / D-167 clash. |
| [D-238](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 30-renderer | Everything except the branch root is selectable by default. Reverses D-181's default and keeps its insight. |
| [D-239](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | The chooser also works value-first: entering a value narrows the candidate types. |
| [D-240](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 10-domain-core | Three layers feed a preview: real data, records marked as test data, and the type's own sample value. Closes the D-052 / D-160 gap. |
| [D-241](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 20-interaction | The test-data mark governs front-end visibility and nothing else. |
| [D-242](90-decision-log.md) | 2026-08-23 | agreed | 01-glossary, 60-calculation | `Ausdruck` means *printout*, not *expression* — and a printout is a frozen report. |
| [D-243](90-decision-log.md) | 2026-08-23 | agreed | 60-calculation, 30-renderer | A report is a wider bracket around the tools that already exist. The expression language does not grow. |
| [D-244](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 20-interaction | The chooser defaults to the dialog; inline stays available for the simple case. Flips the default in D-108. |
| [D-245](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 10-domain-core | `set` and `table` were the legacy conflation of a thing with its drawing. D-117 was right, and its own sentence is the resolution. |
| [D-246](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 30-renderer | `set` and `table` are retired as constructs. Nothing is lost, because D-245 had already moved their work elsewhere. |
| [D-247](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 70-migration | `Cleanup` stays, as maintenance for what deletion leaves behind. |
| [D-248](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | One mode, not two: test mode folds into developer mode. |
| [D-249](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | Settings apply immediately; undo is the safety net. The save button stays available for one concrete reason. |
| [D-250](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 10-domain-core | `Fill Model Data` and `node_presentation` are retired. |
| [D-251](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 30-renderer | The tree shows a node's icon where one is set. |
| [D-252](90-decision-log.md) | 2026-08-23 | agreed | 40-i18n, 01-glossary | `icon` and `symbol` are two different things that merely sit next to each other. |
| [D-253](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 30-renderer | Three surfaces, three jobs: the admin configures the model, the block composes a page, the front end only draws. |
| [D-254](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer | Blocks render on the server, on every request — including inside the editor. |
| [D-255](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 30-renderer | One block for a node. The layout follows the content, and whole parts can be hidden. |
| [D-256](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer | The node renderer is named, and it is the same thing as the page renderer. Corrects the placement in D-255. |
| [D-257](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 30-renderer | A block may override presentation settings, and such an override is page-local by declaration. |
| [D-258](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | The editor supplies the data. Entry by visitors is foreseen, has no use case, and needs no new concept. |
| [D-259](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 40-i18n | A renderer is told which label role to display. The concept had no place for this. |
| [D-260](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 40-i18n | A unit's short form is a label in the `symbol` role. The modelled `symbol` attribute of C44 goes. |
| [D-261](90-decision-log.md) | 2026-08-23 | agreed | 40-i18n | A label carries a *translatable* mark; `symbol` defaults to |
| [D-262](90-decision-log.md) | 2026-08-23 | agreed | 40-i18n, 20-interaction | Of two possible defaults, take the one whose failure is noticed. `symbol` therefore defaults to *not translatable*. |
| [D-263](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt durch D-264 | 30-renderer, 40-i18n, 20-interaction | Überholt durch D-264 |
| [D-264](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 40-i18n | A renderer is told a |
| [D-265](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 30-renderer | A table footer aggregate is a block setting, and it totals what is shown — not what exists. |
| [D-266](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 20-interaction | An override can be reset to *inherited*, and that is not the same as storing an empty value. |
| [D-267](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core | The `id` stays as it is. The legacy running number splits into three things, and only one of them is missing. |
| [D-268](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core | A number circle is a type of its own, and it is the first thing in the model whose state changes without anyone editing it. |
| [D-269](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 30-renderer | A row number is a reading aid, never an identifier. |
| [D-270](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | Row numbers are switched on in the block, and off by default. |
| [D-271](90-decision-log.md) | 2026-08-23 | agreed | ⚠️ **keins** | A parking lot, with a rule that keeps it from becoming a graveyard. |
| [D-272](90-decision-log.md) | 2026-08-23 | agreed | ⚠️ **keins** | The parking lot is walked at every release planning. |
| [D-273](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 20-interaction | One tree for all subject areas. No separate model roots, no projects. |
| [D-274](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core | A unit carries its factor to the reference unit of its parent. Closes a gap in D-039. |
| [D-275](90-decision-log.md) | 2026-08-23 | agreed | ⚠️ **keins** | Converting between peer units moves from the parking lot into Release 2. |
| [D-276](90-decision-log.md) | 2026-08-23 | agreed | 00-vision-and-scope, 30-renderer | There is no second audience. The registries are internal. |
| [D-277](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer | Renderer and converter form a pair. The converter belongs to the list entry, not to the node. |
| [D-278](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer | The *level* is retired. What it did is purpose, depth and visibility — all of which already exist. Resolves the last contradiction. |
| [D-279](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 20-interaction | Preview what you cannot see while configuring. The search view belongs in it; the tree row does not. |
| [D-280](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | A node search sits above the tree and inside every chooser. |
| [D-281](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core | A duplicate edge is refused, and the name is part of what makes it a duplicate. |
| [D-282](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 70-migration | An unchanged save does not raise the version. |
| [D-283](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | Nobody loses their place. This is craft, not a feature. |
| [D-284](90-decision-log.md) | 2026-08-23 | agreed` (my call, no owner opinion) | 20-interaction | Keyboard and screen-reader behaviour is a baseline requirement. |
| [D-285](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer | A converter that cannot represent a value falls back to the canonical form and says so at the field. |
| [D-286](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer | The compact renderer factors out a shared unit and names the members in the label. |
| [D-287](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 20-interaction | Excluding a choice excludes its subtree; a media attribute restricts the kinds it accepts. Both are D-221. |
| [D-288](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer, 10-domain-core | An error blocks, a warning stays visible and does not. |
| [D-289](90-decision-log.md) | 2026-08-23 | agreed | 50-wordpress-persistence, 20-interaction | Uninstalling offers an export first, and deleting the data is a choice. |
| [D-290](90-decision-log.md) | 2026-08-23 | agreed | 30-renderer | Sample values that appear together are coherent. Refines D-240. |
| [D-291](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 50-wordpress-persistence | One date-and-time type with a precision setting. Stored in UTC — except a plain date, which has no timezone at all. |
| [D-292](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 10-domain-core | There are roles, they are built in from the start, and an administrator may practically everything. |
| [D-293](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core, 20-interaction | A field can take its default — or its restriction — from the record that encloses it. |
| [D-294](90-decision-log.md) | 2026-08-23 | agreed | 50-wordpress-persistence | A mirrored file is fetched once on saving, refreshed only when asked, and never silently on reading. |
| [D-295](90-decision-log.md) | 2026-08-23 | agreed | 70-migration, 20-interaction | Duplicating an attribute copies the definition, not the data. Copying values is a separate, named operation. |
| [D-296](90-decision-log.md) | 2026-08-23 | agreed | 70-migration, 10-domain-core | A machine change is recorded as the machine, never as a person. |
| [D-297](90-decision-log.md) | 2026-08-23 | agreed | 40-i18n, 30-renderer | Locale-dependent formatting follows the reader, not the installation. |
| [D-298](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt durch D-299 | 20-interaction | Überholt durch D-299 |
| [D-299](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction | Commit-on-leave was a crutch, not a principle. Supersedes D-298. |
| [D-300](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 10-domain-core | An allow-list is *one* value that happens to be a set. The group argument stands — with a third reason, and this one holds. |
| [D-301](90-decision-log.md) | 2026-08-23 | agreed | 20-interaction, 50-wordpress-persistence | Where the data are must be visible in the product. |
| [D-302](90-decision-log.md) | 2026-08-23 | agreed | 70-migration, 50-wordpress-persistence | Export, backup and restore belong to the product, not beside it. A backup replaces; a pack merges. |
| [D-303](90-decision-log.md) | 2026-08-23 | agreed | 70-migration | The old data stay, and here is when they would be used. |
| [D-304](90-decision-log.md) | 2026-08-23 | agreed | 70-migration | Transforming the existing site content is the first thing that delivers value, not an afterthought. |
| [D-305](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core | Versions of a board are modelled, not built. Closes OQ-075. |
| [D-306](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core | A conversion is a record, not a property of a unit. Extends D-274. Closes OQ-077. |
| [D-307](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core | A pattern book, because the concept can express more than it teaches. Closes OQ-078. |
| [D-308](90-decision-log.md) | 2026-08-23 | agreed | 00-vision-and-scope | Where the shape stops fitting, said out loud. Closes OQ-079. |
| [D-309](90-decision-log.md) | 2026-08-23 | agreed | ⚠️ **keins** | Reader-supplied parameters are parked; a page per record is Release 2. Closes OQ-076 and OQ-080. |
| [D-310](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt durch D-312 | 10-domain-core | Überholt durch D-312 |
| [D-311](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt durch D-405 | 10-domain-core | Überholt durch D-405 |
| [D-312](90-decision-log.md) | 2026-08-23 | ⚠️ ersetzt durch D-411 | 10-domain-core | Überholt durch D-411 |
| [D-313](90-decision-log.md) | 2026-08-23 | agreed | ⚠️ **keins** | Implementation proceeds in self-contained packages: thin vertical slices, each ending in something the owner can operate, each followed by a list of … |
| [D-314](90-decision-log.md) | 2026-08-23 | agreed | 10-domain-core | The test for `locked` is whether a person can *check* what was built, not whether a person could build from it. And the core fits on one page. |
| [D-315](90-decision-log.md) | 2026-08-24 | agreed | 50-wordpress-persistence | A boolean is stored as an integer — and a missing row is not `false`. |
| [D-316](90-decision-log.md) | 2026-08-24 | agreed | 50-wordpress-persistence | Long text is `MEDIUMTEXT` in `record_values`. And we store no binary data at all. |
| [D-317](90-decision-log.md) | 2026-08-24 | agreed | 50-wordpress-persistence, 40-i18n | Data can be translatable too. The gap: we had translation for labels and none for values. |
| [D-318](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core, 30-renderer | `textarea` is retired as a type. One `text` type; the differences are a validator and a renderer. |
| [D-319](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core | A type earns its place through storage, rendering or ordering — never through validation alone. |
| [D-320](90-decision-log.md) | 2026-08-24 | ⚠️ ersetzt durch D-328 | 10-domain-core | Überholt durch D-328 |
| [D-321](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core | `version` is a type, and it earns that through ordering. |
| [D-322](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core, 50-wordpress-persistence | `Medium` becomes `Resource`, the copy is optional, and `url` and `link` are dropped. An email stays its own type with a clickable renderer. |
| [D-323](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core, 50-wordpress-persistence | It is called `Link` — a link with extras. Names D-322. |
| [D-324](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core | `tolerance` is a composed type of two magnitudes; symmetric is the case where they are equal. |
| [D-325](90-decision-log.md) | 2026-08-24 | agreed`, the `ratio` half revised by [D-326](#) | 10-domain-core | Percent is a unit, not a type — and an enumerable ratio is a constant, not a type. No `ratio` type. |
| [D-326](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core | `ratio` is a composed type after all — numerator and denominator — and the constants use it. Revises the `ratio` half of D-325. |
| [D-327](90-decision-log.md) | 2026-08-24 | agreed | 00-vision-and-scope | The namespace is `Taxmod`, and the version starts at `0.0.1`. |
| [D-328](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core | `range` is a type after all: the member type is chosen |
| [D-329](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core | `char` stays. My filter failed it wrongly. |
| [D-330](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core, 50-wordpress-persistence | There is a type for a reference to a user. |
| [D-331](90-decision-log.md) | 2026-08-24 | agreed`, named `markup` by [D-335](#) | 10-domain-core | Formatted text and source code are one type: text plus a declared interpretation. |
| [D-332](90-decision-log.md) | 2026-08-24 | agreed | 30-renderer | A barcode is an additional renderer, not a type — and it is the first real use of the renderer list. |
| [D-333](90-decision-log.md) | 2026-08-24 | agreed | 30-renderer, 50-wordpress-persistence | Masking is a converter, and the real value must never leave the server. |
| [D-334](90-decision-log.md) | 2026-08-24 | agreed | ⚠️ **keins** | Maps and `JSON` go to the parking lot, with the owner's own criteria. |
| [D-335](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core, 01-glossary | The type of D-331 is called `markup`. |
| [D-336](90-decision-log.md) | 2026-08-24 | agreed | 00-vision-and-scope, 50-wordpress-persistence | The project is called Taxonomy Modeller, the repository `wp-taxonomy-modeler`, and the database prefix is `taxmod_`. |
| [D-337](90-decision-log.md) | 2026-08-24 | agreed | 40-i18n, 50-wordpress-persistence | One token everywhere: `taxmod`. |
| [D-338](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core | 10 Domain core is `locked`. |
| [D-339](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core, 50-wordpress-persistence | `Identity` becomes a table: `identities(id)`, append-only, and `owner_id` becomes a real foreign key. |
| [D-340](90-decision-log.md) | 2026-08-24 | agreed | 70-migration, 50-wordpress-persistence | A migration may never reissue an id that was once handed out. |
| [D-341](90-decision-log.md) | 2026-08-24 | agreed | 50-wordpress-persistence | A reset is all or nothing, and it is command line only. |
| [D-342](90-decision-log.md) | 2026-08-24 | agreed | ⚠️ **keins** | Every package adds to a regression net, and both runs are green before anything is committed. |
| [D-343](90-decision-log.md) | 2026-08-24 | agreed | 20-interaction, 30-renderer | Recorded in error as new — it was already R19. |
| [D-344](90-decision-log.md) | 2026-08-24 | agreed | 30-renderer | The provisional admin screen is scaffolding. It is thrown away, not grown into the real one. |
| [D-345](90-decision-log.md) | 2026-08-24 | agreed | ⚠️ **keins** | The scaffolding keeps getting just enough surface to be operated — sparingly. |
| [D-346](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core, 30-renderer | Restoring from the trash is built, and the owner's collapse bug is kept as the evidence for R18. |
| [D-347](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core, 20-interaction | Restoring is undo, so a promoted child goes back with the node. OQ-083 closed by the owner's own reasoning: |
| [D-348](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core, 50-wordpress-persistence | Changes made in one act are written under one bracket. The changelog gains a change-group id, and D-127's deletion event becomes one case of it. |
| [D-349](90-decision-log.md) | 2026-08-24 | agreed | 10-domain-core, 50-wordpress-persistence, 20-interaction | The node counter moves on every write to its row, including a path rewritten because an ancestor moved. And it is a collision guard, not a version an… |
| [D-350](90-decision-log.md) | 2026-08-24 | agreed | 30-renderer | The scaffolding gets one raw data-entry surface, and it is the last one. D-344 is bent here deliberately, not quietly. |
| [D-351](90-decision-log.md) | 2026-08-25 | agreed | 10-domain-core, 50-wordpress-persistence | Multiplicity is one key with four constants, not two number fields. |
| [D-352](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer | Nine of the simple types get a renderer that comes in the box, and *which one is the default* is a fact the registry holds — not a convention a calle… |
| [D-353](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer | A renderer that declines a purpose yields *nothing*, and the caller decides what that means — because the answer differs by purpose. Corrects the con… |
| [D-354](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer | D-350 is closed on its own terms: the raw entry field is deleted, not grown. |
| [D-355](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer, 10-domain-core | The date granularity setting is called `date_precision`, it is a free key rather than a reserved one, and a time with no date is parked against the e… |
| [D-356](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer, 10-domain-core | What a control accepts comes from the *converter*, never from the validator — and where no converter is in effect it is the type's own storage shape.… |
| [D-357](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer, 10-domain-core | Field rules are stored as three keys and configured as one group. OQ-089 is answered in its shape and stays open in its details. |
| [D-358](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer | A renderer, a converter and a validator are always |
| [D-359](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer, 10-domain-core | `step` is an engine setting on the node, `range_step` — and the *homeless setting* that D-358 worried about was never a thing. Both corrections come … |
| [D-360](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer | The eligible set is what a person is *offered*, not a fence around what may be *stored*. A registration declares two things and forbids nothing. |
| [D-361](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer | The field-rule panel has three sections, each with its own control — and the |
| [D-362](90-decision-log.md) | 2026-08-25 | agreed | 10-domain-core, 30-renderer | A rule list is stored as |
| [D-363](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer, 10-domain-core | A renderer learns about a node it is not drawing through the context, resolved before the descent — and the reference renderer is the first thing tha… |
| [D-364](90-decision-log.md) | 2026-08-25 | agreed | 10-domain-core, 30-renderer | A free setting key is configuration about the *model*, never a place to keep data about the *thing* — and it is therefore not offered as an authoring… |
| [D-365](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer, 10-domain-core | Renderer, converter and validator are three orthogonal jobs assigned per *data type* — and the per-type table is where that is written down. A valida… |
| [D-366](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer | A container renderer lays out parts the descent already drew; it does not draw them. And the first one built — the form renderer — found that three o… |
| [D-367](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer, 20-interaction | Two renderers, not one: the tree renderer walks the hierarchy, the node renderer draws the node — and the point of the split is that the node rendere… |
| [D-368](90-decision-log.md) | 2026-08-25 | agreed | 20-interaction, 30-renderer | The glyphs on the controls, named — and *icon* stops meaning two things. |
| [D-369](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer, 40-i18n | The modelling tree shows a node's own |
| [D-370](90-decision-log.md) | 2026-08-25 | agreed | 20-interaction, 30-renderer | A control that cannot be used now is |
| [D-371](90-decision-log.md) | 2026-08-25 | agreed | 10-domain-core, 50-wordpress-persistence, 20-interaction | An attribute can be removed at last, and what was missing was storage: `relations.parked_by_group_id`. |
| [D-372](90-decision-log.md) | 2026-08-25 | ⚠️ ersetzt durch D-373, D-377 | 10-domain-core, 50-wordpress-persistence | Überholt durch D-373 und D-377 |
| [D-373](90-decision-log.md) | 2026-08-25 | ⚠️ ersetzt | 10-domain-core, 50-wordpress-persistence | A prefix's exponent is an |
| [D-374](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer, 40-i18n, 10-domain-core | A `node_label` data type was proposed, built, and withdrawn within the hour: what a record stores is the |
| [D-375](90-decision-log.md) | 2026-08-25 | agreed | 10-domain-core, 30-renderer, 50-wordpress-persistence | D-039's composed type exists at last: `Einheitenwert` — `wert` · `prefix` `0..1` · `einheit` — and its preview renders `2.7 kΩ`. Storing one is refus… |
| [D-376](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer, 20-interaction | An attribute is a drawn subject like any other: the `attribute` renderer, with the name editable where it is declared. `1..1` reads `1`. And the mult… |
| [D-377](90-decision-log.md) | 2026-08-25 | agreed | 10-domain-core, 50-wordpress-persistence, 30-renderer | `persistent` — an attribute may declare that its value is |
| [D-378](90-decision-log.md) | 2026-08-25 | agreed | 10-domain-core, 30-renderer | A prefix's exponent is an attribute of `Prefixes`, declared non-persistent — and the reason it beats a reserved key is the owner's own question. |
| [D-379](90-decision-log.md) | 2026-08-25 | ⚠️ ersetzt durch D-434 | 10-domain-core, 20-interaction | Überholt durch D-434 |
| [D-380](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer | R28–R32 is implemented, and it is one renderer: the chooser. Its test is outcomes, never rows. |
| [D-381](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer, 20-interaction | One settings panel, for a node and for an attribute alike — and it closed a hole rather than only tidying. |
| [D-382](90-decision-log.md) | 2026-08-25 | agreed | 20-interaction, 30-renderer | The icon is a mark of a node, not one of its settings — it moves to the head of the Display band. Its |
| [D-383](90-decision-log.md) | 2026-08-25 | agreed | 30-renderer | A renderer is always resolved, and the panel must show *which* one — shown, never written. |
| [D-384](90-decision-log.md) | 2026-08-26 | agreed | 40-i18n, 30-renderer, 20-interaction | The labels panel goes through a renderer, and it is enterable — locale at the top, the short roles on one line, `help` on its own row. |
| [D-385](90-decision-log.md) | 2026-08-26 | ⚠️ ersetzt durch D-390 | 30-renderer, 10-domain-core | Überholt durch D-390 |
| [D-386](90-decision-log.md) | 2026-08-26 | agreed | 40-i18n | `help` leaves the fallback chain. A role nobody wrote falls back to the |
| [D-387](90-decision-log.md) | 2026-08-26 | agreed | 40-i18n, 20-interaction | One locale is declared *the neutral one*; «neutral» stops being something a person picks. |
| [D-388](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction | An explanation lives in a question mark beside its heading, and it is written for somebody who has not read the concept. U31. |
| [D-389](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction, 30-renderer | Developer mode is not a setting on a node. It is a WordPress option and a |
| [D-390](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer, 10-domain-core | A setting's category is *whose it is*, and where it belongs to a type the group is named after that type. `validator` joins `renderer` and `converter… |
| [D-391](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer | The screen's paint moves out of PHP into `assets/admin.css`, enqueued on that screen's own hook. |
| [D-392](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction, 30-renderer | The page saves, not the field. One form around a whole panel and the save button in the page head — and stage two, saving on leaving a field, is swit… |
| [D-393](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer | A record is drawn by a renderer, and its subject is the *model* node — the fourth hand-built panel to go through R1. |
| [D-394](90-decision-log.md) | 2026-08-26 | agreed | 50-wordpress-persistence | A decimal loses its storage padding at the boundary, on read — not in the core, and not in the check that had enshrined it. |
| [D-395](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer, 20-interaction | Choosing a node is done in a tree, never in a flat list — the chooser cell is the second cell of the one walker. |
| [D-396](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction, 01-glossary | `hide` on a node hides it from the tree, and the tree carries a *show hidden* switch that is off by default. |
| [D-397](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction | The installation gets its own screen, under the modeller — and D-079 already decided which of its facts may live there. |
| [D-398](90-decision-log.md) | 2026-08-26 | ⚠️ ersetzt durch D-399 | 30-renderer, 10-domain-core | Veraltet, ersetzt durch D-399. |
| [D-399](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 30-renderer | `hide` and `read_only` are freely settable on any node, whatever an ancestor says — they leave the bounding category. ~~And `hide` puts the renderer … |
| [D-400](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer, 10-domain-core | A constant is drawn as a *reference to it*, resolved to the label role the referring edge asks for — and a renderer that cannot serve a purpose is no… |
| [D-401](90-decision-log.md) | 2026-08-26 | ⚠️ ersetzt durch D-404 | 10-domain-core, 30-renderer | Überholt durch D-404 |
| [D-402](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 01-glossary | A subtype has its ancestor's settings until it says otherwise, and «otherwise» is said *per key*, never per node. Stated as fact by the owner; it con… |
| [D-403](90-decision-log.md) | 2026-08-26 | agreed | 50-wordpress-persistence, 10-domain-core | Every setting write is journalled, recorded against its |
| [D-404](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 01-glossary | A setting key's own default is a setting written at the installation identity. Nowhere new, and it supersedes what D-401 asked for. |
| [D-405](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 02-field-and-setting | `mandatory` is removed as a setting key. The multiplicity already says it: a floor of one |
| [D-406](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer, 10-domain-core | `hide` and `read_only` become free in both directions in the code — the half of D-399 that was written and never built. |
| [D-407](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 50-wordpress-persistence | The `order` setting key is removed. Ordering is the `position` column on `relations`, which is the only place that ever held it. |
| [D-408](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction | The modelling screen gets its first script — eight lines that keep the tree where it was, and nothing else. |
| [D-409](90-decision-log.md) | 2026-08-26 | agreed | 02-field-and-setting | A setting has no multiplicity. One key, one answer, per place — and «edge-only» stops being a property of `multiplicity` because an attribute *is* th… |
| [D-410](90-decision-log.md) | 2026-08-26 | agreed | 02-field-and-setting, 40-i18n | An attribute has a name in every language — it carries labels, like a node. Answers OQ-095. |
| [D-411](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 02-field-and-setting | ⚠️ Narrowed back for `min` and `max` by D-468: the owner — *«it would contradict the contract I gave earlier at the node … those two we can forbid a … |
| [D-412](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 30-renderer | A `bool` may not have a floor of zero. Two states means it is always answered. |
| [D-413](90-decision-log.md) | 2026-08-26 | agreed | 50-wordpress-persistence, 10-domain-core | `settings` gains a `path` column — the address a setting needs to say |
| [D-414](90-decision-log.md) | 2026-08-26 | agreed | 50-wordpress-persistence, 30-renderer | The prefix exponent is connected — D-378 works four days after it was decided, and the column has a consumer instead of a claim. |
| [D-415](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction | The tree row carries an eye: hiding a node is one click, in front of the plus, and only while *show hidden nodes* is on. |
| [D-416](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction | One place assembles the address a person returns to after an act, and it carries every circumstance. |
| [D-417](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction | The message after an act is an overlay, not a band at the top — and that is what was making the page jump. |
| [D-418](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction, 30-renderer | The attribute's target is picked in the tree, and a chooser's dialog id carries its field name. |
| [D-419](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction | The saved scroll offset is read once, before anything can scroll — and the `#fragment` is gone. That was the bug that made the whole script useless. |
| [D-420](90-decision-log.md) | 2026-08-26 | agreed | 20-interaction | The script saves what actually scrolls — the |
| [D-421](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 30-renderer | The three composed types the concept names as its own test now exist — and measured at three rungs, C116's *works immediately* is true of the model a… |
| [D-422](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer, 10-domain-core | ⚠️ Dropped by D-456: the owner, 2026-08-27 — *«we do not currently need the no-render renderer, `hide` at the edge does that»*. *Everything this row … |
| [D-423](90-decision-log.md) | 2026-08-26 | agreed | 02-field-and-setting, 10-domain-core, 30-renderer | Settings are materialised into the inheriting node and into the attribute, `reset` becomes a *pull*, and — measured — this changes nothing on the rea… |
| [D-424](90-decision-log.md) | 2026-08-26 | agreed | 02-field-and-setting, 30-renderer | The confirmation for pushing a change downwards is *one* list for the whole panel — every affected setting named, each with a yes/no switch. |
| [D-425](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core | A `path` survives inheriting and must be *remapped* on duplicating — because inheritance keeps the edge ids and a copy does not. |
| [D-426](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 50-wordpress-persistence, 30-renderer | ⚠️ Extended by D-457, not overturned: the column half stands, and it applies to an |
| [D-427](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 50-wordpress-persistence | History stays in one home: the journal becomes restorable, and the live tables keep exactly one row per thing. Answers OQ-102. |
| [D-428](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core | A simple type's node is called by a spelled-out name — `Integer`, not `int` — while the enum value stays the identifier. |
| [D-429](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer, 02-field-and-setting | A `bool` setting has exactly two controls: the switch, and `reset`. There is no bin. |
| [D-430](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer | A simple type gets a preview of *itself* — one field, drawn both ways. The branch check was answering the wrong question. |
| [D-431](90-decision-log.md) | 2026-08-26 | agreed | 02-field-and-setting, 30-renderer | A setting is the system's. No screen creates one, so nothing has to delete one — and `reset` therefore only ever pulls. Closes row 48. |
| [D-432](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer, 10-domain-core | `Identity` becomes real: everything renderable is one, and `render()` takes it instead of a union. Implements D-091, which said so and was built othe… |
| [D-433](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer, 02-field-and-setting | Choosing several registered names in order gets its own renderer — a `` cannot express either half. |
| [D-434](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 02-field-and-setting | The standard multiplicity becomes `1`. Supersedes the value in D-379, which keeps everything else it says. |
| [D-435](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 20-interaction | An attribute is reordered with the same two buttons a node has — and `position` turns out to belong to the |
| [D-436](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 40-i18n | `name` belongs on `Identity` too: a node and an edge both have one, and both are translatable the same way. Supersedes the «only» in D-080. |
| [D-437](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer, 10-domain-core | `Identity` and *renderable* are two things, and D-091 merged them. The seam is named now; the signature moves when there is a second implementor. |
| [D-438](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer | A setting is a renderable object too — and it is the second implementor the contract needed. |
| [D-439](90-decision-log.md) | 2026-08-26 | agreed | 30-renderer | Everything that is displayed implements `Renderable`. Measured: three of twelve do. |
| [D-440](90-decision-log.md) | 2026-08-26 | agreed | 10-domain-core, 30-renderer | A node is a *class*, a record is an *object*. `content()` being empty on a node is correct, not a gap — and that resolves the knot. |
| [D-441](90-decision-log.md) | 2026-08-26 | agreed | 50-wordpress-persistence, 10-domain-core | `Record` becomes `NodeRecord`, `RecordValue` becomes `EdgeRecord` — and `records.model_id` becomes `node_id`. Answers OQ-105. |
| [D-442](90-decision-log.md) | 2026-08-27 | agreed | 10-domain-core, 50-wordpress-persistence | A setting is a class and a stored setting is its instance: `Setting` becomes `SettingRecord`, and `SettingKey` is the blueprint. Settings do |
| [D-443](90-decision-log.md) | 2026-08-27 | agreed | 10-domain-core | `NOT NULL` is an attribute of a setting's shape, asked in the core — and exactly one shape has it. The column stays nullable. |
| [D-444](90-decision-log.md) | 2026-08-27 | agreed | 30-renderer | There is one renderer contract, not a class renderer and a record renderer: a renderer is handed the object, and where a |
| [D-445](90-decision-log.md) | 2026-08-27 | agreed | 30-renderer | The input is always the class, and what it needs is prepared and handed in beside it — the preview included. It does not fetch. Refines D-444. |
| [D-446](90-decision-log.md) | 2026-08-27 | agreed | 30-renderer | The tree's node renderer may show a record count, because counting what was handed in is not fetching. Answers OQ-108. |
| [D-447](90-decision-log.md) | 2026-08-27 | agreed | 30-renderer | Converters are built, and the four things the concept did not say are settled here. |
| [D-448](90-decision-log.md) | 2026-08-27 | ⚠️ ersetzt durch D-449 | 30-renderer, 10-domain-core | ⚠️ Superseded the same day by D-449, which puts `hide` on the |
| [D-449](90-decision-log.md) | 2026-08-27 | ⚠️ ersetzt durch D-453 | 30-renderer, 10-domain-core | ⚠️ Superseded by D-453: the owner — *we never talked about a |
| [D-450](90-decision-log.md) | 2026-08-27 | agreed | 30-renderer, 10-domain-core | `hide` on an edge is an abort criterion for the descent: from there down, nothing is rendered. And a renderer that draws nothing is a legitimate thin… |
| [D-451](90-decision-log.md) | 2026-08-27 | agreed | 30-renderer, 10-domain-core | ⚠️ Its reading is corrected by D-452: the owner — *that has nothing to do with inheritance* — and he is right that the chain is a |
| [D-452](90-decision-log.md) | 2026-08-27 | agreed | 30-renderer, 10-domain-core | ⚠️ One claim in this row is withdrawn: «a renderer has no registry» is |
| [D-453](90-decision-log.md) | 2026-08-27 | agreed | 30-renderer, 10-domain-core | `hide` belongs to |
| [D-454](90-decision-log.md) | 2026-08-27 | agreed | 30-renderer, 50-wordpress-persistence | An id lookup — load once, fetch objects by id — and the contradiction between `CD-7`'s up-front loading and the abort disappears without either side … |
| [D-455](90-decision-log.md) | 2026-08-27 | agreed | 50-wordpress-persistence, 20-interaction | The whole-model store stays a thought model. The requirement is the smaller one: |
| [D-456](90-decision-log.md) | 2026-08-27 | agreed | 30-renderer, 10-domain-core | `hide` means one thing in both places — «render no further» — and the no-render renderer is not needed, because `hide` on the edge does it. Answers O… |
| [D-457](90-decision-log.md) | 2026-08-27 | agreed | 10-domain-core, 30-renderer, 50-wordpress-persistence | `hide` is a |
| [D-458](90-decision-log.md) | 2026-08-27 | agreed | 10-domain-core, 50-wordpress-persistence | Settings and attribute values share one table. Answers the question left open by D-442 and OQ-106. |
| [D-459](90-decision-log.md) | 2026-08-27 | agreed | 10-domain-core, 30-renderer | «Attribute» becomes «field». What we define are fields in a record. |
| [D-460](90-decision-log.md) | 2026-08-28 | agreed | 10-domain-core, 50-wordpress-persistence | `persistent` stays a setting: it needs the chain, and the use site overrides the node |
| [D-461](90-decision-log.md) | 2026-08-28 | agreed | 10-domain-core, 02-field-and-setting | `read_only` stays a setting and is freely settable at the field, in both directions. The tightening rule stays gone — D-411 is untouched. And the wor… |
| [D-462](90-decision-log.md) | 2026-08-28 | agreed | 10-domain-core, 50-wordpress-persistence, 30-renderer | The three renames are built — and the four collisions they exposed are the content of this entry. |
| [D-463](90-decision-log.md) | 2026-08-28 | agreed | 30-renderer | The renderer design, written down at last — in the owner's words, and it was already built. |
| [D-464](90-decision-log.md) | 2026-08-28 | agreed | 10-domain-core, 50-wordpress-persistence, 30-renderer | `hide` is built as a column with an abort — schema 10, and the setting key is gone. |
| [D-465](90-decision-log.md) | 2026-08-28 | agreed | 30-renderer | `RenderResult::htmlTag()` — one place knows how an element is spelled. Builds D-463's rule, and the owner's naming convention is what makes it a prom… |
| [D-466](90-decision-log.md) | 2026-08-28 | agreed | 10-domain-core, 30-renderer | `range_min`, `range_max` and `range_step` become `min`, `max` and `step` — schema 11. The rename only; what «no value» means is |
| [D-467](90-decision-log.md) | 2026-08-28 | agreed | 10-domain-core, 30-renderer, 20-interaction | `hide` lives on the |
| [D-468](90-decision-log.md) | 2026-08-28 | agreed | 10-domain-core, 02-field-and-setting | Widening stays refused for `min` and `max`. The narrowing rule does |
| [D-469](90-decision-log.md) | 2026-08-28 | agreed | ⚠️ **keins** | One topic, one owning place — and every other mention points at it. The owner asked for it and the first application shows why. |
| [D-470](90-decision-log.md) | 2026-08-28 | agreed | 10-domain-core, 50-wordpress-persistence | An act is bracketed, and the bracket is what gives a change its number. Builds D-348 at last — the column had been there for two days and grouped not… |
| [D-471](90-decision-log.md) | 2026-08-28 | agreed | 30-renderer, 20-interaction | Der Kompaktrenderer ist |
| [D-472](90-decision-log.md) | 2026-08-28 | agreed | 50-wordpress-persistence, 10-domain-core | Eine eigene Spalte sagt, ob eine Zeile ein Setting oder ein Feldwert ist. Schliesst die erste Hälfte von OQ-119. |
| [D-473](90-decision-log.md) | 2026-08-28 | agreed | 70-migration, 20-interaction, 10-domain-core | Changelog-Einträge zu löschen ist an eine |
| [D-474](90-decision-log.md) | 2026-08-28 | agreed | 20-interaction, 70-migration | Die Anlage-Zeile bleibt, und Unlöschbarkeit ist etwas, das man einer Zeile |
| [D-475](90-decision-log.md) | 2026-08-28 | agreed | 70-migration, 20-interaction | Vor einem Release-Update ist ein Backup Pflicht, und der Benutzer wird zum Herunterladen gezwungen. Randbedingung, keine Empfehlung. |
| [D-476](90-decision-log.md) | 2026-08-28 | agreed | 70-migration, 50-wordpress-persistence | Das Update-Log ist minimal — eine Zeile je Installation und je Folge-Update, mit Version und Verweis. Der Rückbau kommt aus dem Backup (D-475), nicht… |
| [D-477](90-decision-log.md) | 2026-08-28 | agreed | 70-migration, 50-wordpress-persistence | Ein Release-Update muss zwei Dinge leisten, und der Eigentümer hat sie benannt: bestehende Daten dürfen nicht kaputtgehen, und neue Knoten aus dem Te… |
| [D-478](90-decision-log.md) | 2026-08-28 | agreed | 20-interaction | Der Baum startet eingeklappt, und die Vorfahren des ausgewählten Knotens sind offen. Gebaut, Zeile 60. |
| [D-479](90-decision-log.md) | 2026-08-28 | agreed | 20-interaction, 50-wordpress-persistence | `Cleanup` ist gebaut — dritte Untermenüseite, die drei Quellen aus D-247, ein Knopf je Zeile. Zeile 65 zur Hälfte. |
| [D-480](90-decision-log.md) | 2026-08-28 | agreed | 20-interaction | Der Faltzustand wird fortgeschrieben: eine frische Seite schreibt ihre Vorgabe in jeden Link, und der ausgewählte Ast bleibt trotzdem offen. Verfeine… |
| [D-481](90-decision-log.md) | 2026-08-28 | agreed | 30-renderer | Zwei Gruppen von Knoten bestimmen, welche Renderer angeboten werden — und die vorhandene «Supported»-Liste des Renderers trägt das, wenn «normaler Kn… |
| [D-482](90-decision-log.md) | 2026-08-28 | agreed | 30-renderer, 10-domain-core | Ein Renderer ist Code, also muss sein Anspruch auch Code sein: wer einen eigenen Renderer will, wird ein |
| [D-483](90-decision-log.md) | 2026-08-28 | agreed | 30-renderer | Der Container eines Knotens kommt aus der Kette — gebaut. Und ein Knoten sagt |
| [D-484](90-decision-log.md) | 2026-08-28 | agreed | 10-domain-core, 30-renderer | Spezialisierte Knotenklassen im Code — auf sein Wort, und es korrigiert die delegierte Hälfte von D-036. Schliesst OQ-124. |
| [D-485](90-decision-log.md) | 2026-08-28 | agreed | 10-domain-core, 20-interaction | Ein Datensatz ohne seinen Knoten darf es nicht geben, und der Löschpfad setzt das jetzt durch. C102 gebaut. |
| [D-486](90-decision-log.md) | 2026-08-28 | agreed | 20-interaction | Die Kästen um die Icons kamen von einem Positionsargument, nicht von einer CSS-Regel — und `--taxmod-icon` war überall tot. |
| [D-487](90-decision-log.md) | 2026-08-28 | agreed | ⚠️ **keins** | Ein Dachboden für ganz überholte Entscheidungen — 92 Veraltete Entscheidungen. Und ein |
