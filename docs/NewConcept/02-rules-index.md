# Regelverzeichnis — alle Regeln des Projekts

**Diese Datei wird erzeugt.** `php scripts/dev/rules-index.php` schreibt sie neu,
`--check` wird rot, wenn sie veraltet ist. **Nichts hier von Hand ändern** — die Regel selbst
steht in der Datei, auf die verwiesen wird, und nur dort wird sie geändert.

⚠️ **Warum es dieses Verzeichnis gibt.** *Der Eigentümer konnte den Regelsatz nicht übergeben:
«hätte ein Problem den aktuellen Regelsatz zu übergeben, da er Chaos enthält». Gemessen war das
Chaos nicht Altlast — **303 Regeln in 16 Räumen, die meisten davon lebendig zitiert** —,
sondern **Streuung**: sie stehen in 15 verschiedenen Dateien, und **keine Stelle listete sie auf.**
Vor einer Aufgabe konnte niemand wissen, welche Regeln für sie gelten.*

⚠️ **Dieses Verzeichnis sortiert nicht aus.** *Welche zwei Regeln dasselbe sagen und welche
überflüssig ist, entscheidet der Eigentümer (`PR-4`). Hier wird nur sichtbar gemacht, was da ist.*

| Raum | Wofür | Regeln |
|---|---|---|
| **PR** | Prozess — wie gearbeitet wird | 10 |
| **CD** | Code — wie geschrieben wird | 12 |
| **AR** | Architektur — was gebaut wird (braucht je eine Entscheidung) | 2 |
| **DC** | Dokumentation im Code | 5 |
| **V** | Vision und Umfang | 9 |
| **C** | Modellkern | 111 |
| **P** | Speicherung in WordPress | 14 |
| **U** | Bedienung | 5 |
| **R** | Renderer — wie gezeichnet wird | 76 |
| **I** | Sprachen und Übersetzung | 10 |
| **K** | Rechnen | 12 |
| **M** | Migration | 22 |
| **A** | Standardbaum — Aufbau | 5 |
| **B** | Standardbaum — Inhalt | 8 |
| **S** | Speicher | 1 |
| **Q** | Aus der Altlast übernommene Frage | 1 |
| | **Summe** | **303** |

Bei **4** Regeln liess sich keine Definitionszeile erkennen, nur eine Erwähnung — dort steht die Regel
vermutlich im Fliesstext und sollte eine eigene Zeile bekommen.

---

## PR — Prozess — wie gearbeitet wird

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **PR1** | Source of truth is the current package documentation and the active decisions (docs/arbeitsmodell.md §16). docs/NewConcept/ and docs/legacy/ are bo… | [CLAUDE.md:36](../../CLAUDE.md) | 16 |
| **PR2** | There is no lock. A concept is either finished — then it is built — or outdated — then it is replaced, with a reason (D-565). There is no third sta… | [CLAUDE.md:37](../../CLAUDE.md) | 14 |
| **PR3** | Nothing is decided until it is in 90-decision-log.md with a D-<nnn> id. A decision reached in chat and not written down did not happen. | [CLAUDE.md:38](../../CLAUDE.md) | 16 |
| **PR4** | Unclear stays unclear. Anything undecided becomes an entry in docs/neues-konzept-eingang.md — the old question sheet is closed with the concept it … | [CLAUDE.md:39](../../CLAUDE.md) | 74 |
| **PR6** | Documentation style per 98-documentation-style.md: one small mermaid diagram per Sachverhalt, explanation beneath, code only where detail demands i… | [CLAUDE.md:40](../../CLAUDE.md) | 1 |
| **PR7** | Report faithfully. If something is unverified, say so. If a step was skipped, say so. Never present a plausible reconstruction as a finding. | [CLAUDE.md:41](../../CLAUDE.md) | 5 |
| **PR8** | Rule hygiene applies to this file — see the last section. | [CLAUDE.md:42](../../CLAUDE.md) | **nie** |
| **PR9** | What works keeps working: both test runs are green before anything is committed (D-564) — the core run under PHPUnit, which loads no WordPress, and… | [CLAUDE.md:43](../../CLAUDE.md) | 21 |
| **PR10** | Look it up before you say it. No claim about the concept without a quotation from it. Whenever an answer turns on what was decided — a mechanism, a… | [CLAUDE.md:45](../../CLAUDE.md) | 24 |
| **PR13** | The yardstick for a specification is whether a person can check it, not whether the AI can build from it (D-566). If the only reader who can hold t… | [CLAUDE.md:44](../../CLAUDE.md) | 3 |

## CD — Code — wie geschrieben wird

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **CD1** | ). Declares the interfaces it needs; the boundary fulfils them (D-170). | [01-glossary.md:104](01-glossary.md) | 84 |
| **CD2** | Every PHP file starts with <?php declare(strict_types=1); as the first line. No closing ?> in pure-PHP files. | [CLAUDE.md:69](../../CLAUDE.md) | 1 |
| **CD3** | Class loading via Composer PSR-4. No require_once for classes. | [CLAUDE.md:70](../../CLAUDE.md) | 1 |
| **CD4** | Type everything that can be typed: properties, parameters, returns. mixed needs a reason in a comment. | [CLAUDE.md:71](../../CLAUDE.md) | **nie** |
| **CD5** | At the boundary, in this order, every time: capability check → nonce → validate → sanitize → act → escape on output. No exceptions, not even for ad… | [CLAUDE.md:72](../../CLAUDE.md) | 21 |
| **CD6** | Custom tables: $wpdb->prefix . 'taxmod_<name>', created via dbDelta() on activation, guarded by a stored schema version option so upgrades are dete… | [CLAUDE.md:73](../../CLAUDE.md) | 17 |
| **CD7** | No N+1. No SQL inside a loop, no recursive function that queries per level. Tree traversal is solved once, in one place, and every caller uses it. | [CLAUDE.md:74](../../CLAUDE.md) | 129 |
| **CD8** | Presentation code returns strings. No echo inside renderers, loops, shortcodes or hooks. Use ob_start() / ob_get_clean() only when a third-party AP… | [CLAUDE.md:75](../../CLAUDE.md) | 4 |
| **CD9** | Names say what the thing is. Rename when the word lies. No abbreviations that need a lookup, and no data / info / manager / helper as a whole name. | [CLAUDE.md:76](../../CLAUDE.md) | 29 |
| **CD10** | Errors: exceptions inside the core, translated to WP_Error at the boundary. Never a bare false to signal failure. Never silence an exception withou… | [CLAUDE.md:77](../../CLAUDE.md) | 6 |
| **CD11** | Versioning: semantic MAJOR.MINOR.PATCH, starting at 0.0.1. MAJOR moves only for an official release. Plugin header, PHP version constant, package.j… | [CLAUDE.md:78](../../CLAUDE.md) | 3 |
| **CD12** | Gutenberg blocks live in the taxmod/ namespace — taxmod/<slug>, keyword taxmod (D-337). The human-readable block title is not a token: it is a tran… | [CLAUDE.md:79](../../CLAUDE.md) | 7 |

## AR — Architektur — was gebaut wird (braucht je eine Entscheidung)

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **AR1** | The model is stored in tables owned by this plugin, not in WordPress posts, postmeta, terms or CPTs. Measured 2026-09-01: wp_insert_post, get_post_… | [CLAUDE.md:103](../../CLAUDE.md) | 22 |
| **AR2** | Nothing user-visible is hard-coded. Software strings go through the WordPress text domain; the names of user-created nodes are labels stored in the… | [CLAUDE.md:104](../../CLAUDE.md) | 87 |

## DC — Dokumentation im Code

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **DC1** | Comments explain why, not what. If a comment restates the code, delete one of them — usually the comment. | [CLAUDE.md:120](../../CLAUDE.md) | **nie** |
| **DC2** | Every class and interface gets a short docblock: one sentence of purpose, plus the concept document it implements (@see docs/NewConcept/10-domain-c… | [CLAUDE.md:121](../../CLAUDE.md) | **nie** |
| **DC3** | @param / @return only where the type declaration cannot say it — array shapes, generics, units, ranges. Never as an echo of the signature. | [CLAUDE.md:122](../../CLAUDE.md) | **nie** |
| **DC4** | For a flow that is genuinely hard to see from one file — a registry lookup, a resolution walk, a graph traversal — put a small mermaid diagram in t… | [CLAUDE.md:123](../../CLAUDE.md) | **nie** |
| **DC5** | Each top-level source folder has a README.md: what lives here, what it must not depend on, where its concept document is. Short. This is the docume… | [CLAUDE.md:124](../../CLAUDE.md) | **nie** |

## V — Vision und Umfang

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **V1** | The model consists of nodes and edges. | [00-vision-and-scope.md:26](00-vision-and-scope.md) | 15 |
| **V2** | The nodes sit in a tree. | [00-vision-and-scope.md:27](00-vision-and-scope.md) | 1 |
| **V3** | The tree represents the inheritance hierarchy only — nothing else. | [00-vision-and-scope.md:28](00-vision-and-scope.md) | 8 |
| **V4** | The root node has no parent. Every other node inherits from its ancestors. | [00-vision-and-scope.md:29](00-vision-and-scope.md) | 1 |
| **V5** | Fundamentally all nodes are the same. Confirmed 2026-08-23 with a nuance from the owner: there are specialisations, but that statement was about th… | [00-vision-and-scope.md:30](00-vision-and-scope.md) | 23 |
| **V6** | There are a few special nodes, for data types and for calculations. | [00-vision-and-scope.md:31](00-vision-and-scope.md) | 6 |
| **V7** | Those special nodes are created in the configuration. | [00-vision-and-scope.md:32](00-vision-and-scope.md) | 15 |
| **V8** | Essentially every node has: one renderer (responsible for display), one converter (may manipulate the output), and one or more validators (check wh… | [00-vision-and-scope.md:33](00-vision-and-scope.md) | 20 |
| **V9** | The validator concept is deliberately special: a validator can, at the same time, offer a way to correct the invalid data. | [00-vision-and-scope.md:34](00-vision-and-scope.md) | 30 |

## C — Modellkern

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **C1** | Model contains Bauteil (Passiv, Halbleiter, Elektromechanik, Sonstige), Bauteilliste, Kontakt, Platine, Bauteillisten Position. | [01-standard-tree.md:88](01-standard-tree.md) | 10 |
| **C2** | Implementation holds Bauteile, BOM, Lieferanten — actual instances. | [01-standard-tree.md:89](01-standard-tree.md) | 19 |
| **C3** | Konstanten also holds Bauformen and Bauteil Monatge Typen (sic). | [01-standard-tree.md:90](01-standard-tree.md) | 7 |
| **C4** | The file's own notes list four known live inconsistencies, including a typo and a soft-trashed node. | [01-standard-tree.md:91](01-standard-tree.md) | 10 |
| **C5** | The attributes that appear only through specialisation — an integer node carrying min, max, step — are stored generically, in the settings table. | [10-domain-core.md:1135](10-domain-core.md) | 9 |
| **C6** | For display the two sets are re-joined: the fixed ones first, the extended ones beneath. How exactly is a renderer concern and is not settled here. | [10-domain-core.md:1136](10-domain-core.md) | 1 |
| **C7** | Every child node inherits the attributes of its parent and either takes them over unchanged, or overrides them. | [10-domain-core.md:1195](10-domain-core.md) | 1 |
| **C8** | To record an override, the describing data has to live somewhere. Therefore settings are also hung on the edges — on the connection itself, not onl… | [10-domain-core.md:1196](10-domain-core.md) | 16 |
| **C9** | For the inheritance edge this is not needed; other rules already apply there. | [10-domain-core.md:1197](10-domain-core.md) | 11 |
| **C10** | For composition and aggregation edges it is needed. Currently those are believed to be the only edge kinds that need settings. stated with "I believe" | [10-domain-core.md:1198](10-domain-core.md) | 6 |
| **C11** | Identity is shared by nodes and edges and carries more than an id — a version number among other things. Drawing ids from one common space is accep… | [10-domain-core.md:1229](10-domain-core.md) | 25 |
| **C12** | Composition means the part is part of the model and is deleted with it. | [10-domain-core.md:1230](10-domain-core.md) | 18 |
| **C13** | Aggregation always points at another node. Composition may point at another node too — but since a composed part is firmly bound to its whole, its … | [10-domain-core.md:1231](10-domain-core.md) | 11 |
| **C14** | Inheritance resolution walks from the child up to the last ancestor, in case of doubt to the root. It may stop as soon as no ancestor contributes a… | [10-domain-core.md:1232](10-domain-core.md) | 4 |
| **C15** | Attribute settings resolve downwards: an attribute references a node, that node may have children, and the settings have to reach into all of them. | [10-domain-core.md:1233](10-domain-core.md) | 6 |
| **C16** | a row change counter — this node was edited | [91-open-questions.md:69](91-open-questions.md) | 4 |
| **C17** | It also matters for the data later. If the model changes and data already exists: in the best case the old data carries into the new model — a fiel… | [10-domain-core.md:1303](10-domain-core.md) | 2 |
| **C18** | The user must not have to re-enter data. A model change creates a discrepancy, and the user has to be able to resolve it with suitable means. | [10-domain-core.md:1304](10-domain-core.md) | 1 |
| **C19** | An attribute can be set read-only and hidden. Hidden matters in its own right: hidden fields can be created and used later for calculation. | [10-domain-core.md:1315](10-domain-core.md) | 4 |
| **C20** | A node needs a name, and that name is the model author's own. | [10-domain-core.md:1316](10-domain-core.md) | 2 |
| **C21** | Names are not unique, and duplicates are expected. Two attributes may share a name; two different nodes may each have a child of the same name. | [10-domain-core.md:1317](10-domain-core.md) | 1 |
| **C22** | A decision is never made on the basis of a name. References always use the id. Searching by name is fine; resolving by name is not. | [10-domain-core.md:1318](10-domain-core.md) | 1 |
| **C23** | The id stays the same — that is the point of it. The name may change. For a child, the name is only a textual description so the user can recognise… | [10-domain-core.md:1319](10-domain-core.md) | 1 |
| **C24** | Modelling view and data view are separate. The model and an instance of it are different things. | [10-domain-core.md:1333](10-domain-core.md) | 1 |
| **C25** | An attribute carries a type, and that type is the node the relation points to. | [10-domain-core.md:1334](10-domain-core.md) | 1 |
| **C26** | In the modelling view a default may be given, and that default may be a whole record — the data of an instance, not only a scalar. | [10-domain-core.md:1335](10-domain-core.md) | 3 |
| **C27** | There are three layers, not two: the model, the data entered into it, and the presentation — the latter being what the renderer concept covers. | [10-domain-core.md:1382](10-domain-core.md) | **nie** |
| **C28** | Test data is ordinary data, marked as such. Rows can be flagged as test data, and the preview renderer uses those. The default value is checked at … | [10-domain-core.md:1383](10-domain-core.md) | 6 |
| **C29** | A default says how data is filled by default. An integer with default 10 means a new record starts at 10. | [10-domain-core.md:1384](10-domain-core.md) | **nie** |
| **C30** | Defaults work with multiplicity: several defaults, several pre-filled rows. | [10-domain-core.md:1385](10-domain-core.md) | 12 |
| **C31** | A default may be a node reference. Given an attribute that chooses from four options, options one and two can be the defaults — and those options a… | [10-domain-core.md:1386](10-domain-core.md) | 1 |
| **C32** | The same holds for an aggregation: take the target node, look at which values get filled, and pre-fill them. | [10-domain-core.md:1387](10-domain-core.md) | 2 |
| **C33** | The default is entered in the attribute, but it looks exactly like entering data into the model. Same display, same interaction. | [10-domain-core.md:1388](10-domain-core.md) | 1 |
| **C34** | Two-foldness. For a whole class of behaviours there are two levels: a default behaviour configured in the admin menu, and the choice the user makes… | [10-domain-core.md:1434](10-domain-core.md) | 2 |
| **C35** | An override whose path has disappeared — the parent deleted the thing it pointed at — is not cascade-deleted. The user decides. Either delete them,… | [10-domain-core.md:1435](10-domain-core.md) | 2 |
| **C36** | An attribute contains a hierarchy of its own. There are settings for the target; beneath them, settings for each of the target's attributes; beneat… | [10-domain-core.md:1480](10-domain-core.md) | 1 |
| **C37** | Sharing a node requires an identical definition. A parts list and a quotation would not share a position — their positions differ. They would share… | [10-domain-core.md:1481](10-domain-core.md) | 1 |
| **C38** | When overrides are orphaned, a dialog shows where they are defined and asks whether to keep them. Keeping means taking the override over as an attr… | [10-domain-core.md:1482](10-domain-core.md) | 1 |
| **C39** | A good deal is fixed through inheritance. A weight is not a bare double — it has its own type node that takes a numeric value and a unit, prefix in… | [10-domain-core.md:1517](10-domain-core.md) | 2 |
| **C40** | There are several kinds of unit value: base units, and currencies. A two-part split. | [10-domain-core.md:1518](10-domain-core.md) | 3 |
| **C41** | Composed types are defined and reused elsewhere; models are then built on top of them. | [10-domain-core.md:1519](10-domain-core.md) | 2 |
| **C42** | A type already anticipates part of how it is composed, and its settings. | [10-domain-core.md:1520](10-domain-core.md) | 2 |
| **C43** | Because a child inherits its parent's attributes, using a node means looking only at that node — not walking back up to the parent. This makes the … | [10-domain-core.md:1521](10-domain-core.md) | 4 |
| **C44** | Correction, made by the owner mid-thought: the numeric field does not belong to the base unit. A unit value is its own composed type — a value fiel… | [10-domain-core.md:1522](10-domain-core.md) | 8 |
| **C45** | The nodes describing relation kinds do not belong in the tree. They were only there to show which kinds exist. | [10-domain-core.md:1618](10-domain-core.md) | **nie** |
| **C46** | The duplication of Praefix and Kuerzel on parent and child in the old tree was wrong. The intent was: constants define the prefixes, each with a hi… | [10-domain-core.md:1619](10-domain-core.md) | 2 |
| **C47** | There is one notion: the unit value. It is composed of a value, an optional prefix, and a unit. | [10-domain-core.md:1620](10-domain-core.md) | **nie** |
| **C48** | Which part varies is determined by the sense of the unit value: converting euro to dollar changes the unit; metre to kilometre changes the prefix. | [10-domain-core.md:1621](10-domain-core.md) | 3 |
| **C49** | When defining a unit, the author wants to say which prefixes are permitted — no gigametres. This must be generic, definable in the modeller, not a … | [10-domain-core.md:1622](10-domain-core.md) | 5 |
| **C50** | If the type of an attribute is a plain node, it is clear — there is no branch behind it. | [10-domain-core.md:1623](10-domain-core.md) | 3 |
| **C51** | If the type is a branch, the type is of branch kind: polymorphic, one substitutable for another — and at data entry a node from that branch must be… | [10-domain-core.md:1624](10-domain-core.md) | **nie** |
| **C52** | Some types need only the node chosen, because they have no attributes. Where the chosen node has attributes, the user must then fill them. | [10-domain-core.md:1625](10-domain-core.md) | **nie** |
| **C53** | Hence a multi-step input: first choose the node, then enter data if needed. | [10-domain-core.md:1626](10-domain-core.md) | 2 |
| **C54** | The recurring question is: when do I have a model node — a finished model the user enters data into — and when do I define a node as a type, simple… | [10-domain-core.md:1715](10-domain-core.md) | **nie** |
| **C55** | The two do not really differ. They are all nodes. A single type can be used exactly like a model type. What differs is that the input becomes neste… | [10-domain-core.md:1716](10-domain-core.md) | 5 |
| **C56** | That is why the renderer concept exists — to display nested data simply, and to let the user shape the output by choosing a renderer and refining i… | [10-domain-core.md:1717](10-domain-core.md) | 1 |
| **C57** | Pure data-type nodes hold no data. Nodes that are used hold data. (The owner deliberately avoids the word model node here.) | [10-domain-core.md:1768](10-domain-core.md) | 1 |
| **C58** | There will also be data types that are filled — a choice list, an enumeration node. What is stored in them can then be used in other models. | [10-domain-core.md:1769](10-domain-core.md) | **nie** |
| **C59** | The difference: for those, the contents are fixed at modelling time. For the other kind, the contents are decided at input time. | [10-domain-core.md:1770](10-domain-core.md) | 1 |
| **C60** | A permitted-prefix restriction was set up by activating and deactivating the inheriting sub-nodes at the attribute, and that worked well in practice. | [10-domain-core.md:1771](10-domain-core.md) | 1 |
| **C61** | Whether permitted sub-nodes are handled by activating or by deactivating should be the user's choice, made when a new sub-node is created. | [10-domain-core.md:1836](10-domain-core.md) | **nie** |
| **C62** | But if the type is not used anywhere yet, the question is pointless and would only get in the way. It should be suppressed. | [10-domain-core.md:1837](10-domain-core.md) | 1 |
| **C63** | The same principle applies to model versioning. With no data present, a new version causes no break, whatever it changes. | [10-domain-core.md:1838](10-domain-core.md) | 1 |
| **C64** | Test data are a possible exception, and the lean is: do not take them into account, but warn that they may need adjusting. | [10-domain-core.md:1839](10-domain-core.md) | 2 |
| **C65** | How test data come about is open — a checkbox is test data / is default value would do it. | [10-domain-core.md:1840](10-domain-core.md) | 12 |
| **C66** | Storage of a unit value is undecided. Either always store the base unit and keep the prefix for output, or bind value and prefix one to one — in wh… | [10-domain-core.md:1841](10-domain-core.md) | 2 |
| **C67** | Numbers are whole numbers or decimals. The underlying data type does not matter — if floating point is unsuitable, it is left out. | [10-domain-core.md:1935](10-domain-core.md) | 1 |
| **C68** | Money is the special case again. Full precision is stored, including places that are never shown. | [10-domain-core.md:1936](10-domain-core.md) | **nie** |
| **C69** | Below the visible cent, rounding rules take over. An amount smaller than one cent must still show that something is owed — not zero, but a minimum … | [10-domain-core.md:1937](10-domain-core.md) | 3 |
| **C70** | Hence: calculate and store at full precision, display in a form a person can read. | [10-domain-core.md:1938](10-domain-core.md) | 2 |
| **C71** | Euro normalisation was not meant. An amount may perfectly well be stored in dollars. | [10-domain-core.md:1978](10-domain-core.md) | 1 |
| **C72** | There is an exchange rate, and sometimes it has to be frozen. Ordering for ten dollars, which is eight euros fifty that day, means that price has t… | [10-domain-core.md:1979](10-domain-core.md) | **nie** |
| **C73** | So either convert to euro on that day and keep the result, or store the rate of that day alongside. | [10-domain-core.md:1980](10-domain-core.md) | 1 |
| **C74** | The freezing could be an additional attribute, so it hangs on the record: a price of ten dollars plus a hidden field conversion rate dollar to euro. | [10-domain-core.md:2043](10-domain-core.md) | 3 |
| **C75** | "But then which currency do I hold? Do I always convert dollars and store dollars plus the day rate to euro — or, being in euro anyway, need no con… | [10-domain-core.md:2044](10-domain-core.md) | 2 |
| **C76** | The day rate would have to be fetched from the internet — and that is where the rate table comes in: ask once per day, for the currencies already k… | [10-domain-core.md:2045](10-domain-core.md) | 1 |
| **C77** | Whether intraday fluctuations need to be captured is an open question the owner has no basis to judge. | [10-domain-core.md:2046](10-domain-core.md) | 2 |
| **C78** | This implies a type of its own for money — a currency value — with additional functionality. | [10-domain-core.md:2146](10-domain-core.md) | 3 |
| **C79** | Which currencies exist is held in the model. | [10-domain-core.md:2147](10-domain-core.md) | 1 |
| **C80** | And things like conversion into cents or other smallest units belong to it. | [10-domain-core.md:2148](10-domain-core.md) | 2 |
| **C81** | There is no separate type-definition view, because a type is a node like any other. | [10-domain-core.md:2308](10-domain-core.md) | 1 |
| **C82** | Overrides sit on the node anyway — practically the same as at an attribute. | [10-domain-core.md:2309](10-domain-core.md) | 1 |
| **C83** | And a child node may hide inherited attributes or override their properties. | [10-domain-core.md:2310](10-domain-core.md) | 3 |
| **C84** | 1 and 0..1 apply only to edges, that is to attributes. Overriding 1 with 0..1 makes it optional for that node; overriding 0..1 with 1 tightens it, … | [10-domain-core.md:2359](10-domain-core.md) | 1 |
| **C85** | The purpose was twofold: realise the shared ids, and pin down the versions so that changes are logged — including which employee they came from. ✏️… | [10-domain-core.md:2240](10-domain-core.md) | 4 |
| **C86** | A parent class is not strictly necessary for that — but it is simpler if it carries all the attributes that relations and nodes have in common. | [10-domain-core.md:2241](10-domain-core.md) | 16 |
| **C87** | An attribute such as an article number may carry a unique setting: it must be unique. If someone then enters another record with an article number … | [10-domain-core.md:2398](10-domain-core.md) | **nie** |
| **C88** | Could an attribute carry a flag is primary key, so that duplicates are checked against it? | [10-domain-core.md:2464](10-domain-core.md) | 2 |
| **C89** | The previous project had an enum type, introduced early because fixed values seemed to be needed. | [10-domain-core.md:2524](10-domain-core.md) | 2 |
| **C90** | It turned out that fixed values sometimes carry further properties — not just the one value an enum member has, but several. So the enum type was d… | [10-domain-core.md:2525](10-domain-core.md) | 1 |
| **C91** | The same for a set or table type: it is only a node with several attributes, practically a row — and that it is one is expressed through the render… | [10-domain-core.md:2526](10-domain-core.md) | 4 |
| **C92** | A base scaffold tree must be installed — the simple data types and so on have to be there. | [10-domain-core.md:2582](10-domain-core.md) | 1 |
| **C93** | Base units are sensible too, a few currencies, and the general settings. Everything else the user defines. | [10-domain-core.md:2583](10-domain-core.md) | 1 |
| **C94** | On insert the node ids may shift — one can of course supply them, but they need not stay the same. | [10-domain-core.md:2584](10-domain-core.md) | 3 |
| **C95** | In the admin configuration every special node was additionally defined as a constant, with the corresponding node assigned to it. | [10-domain-core.md:2585](10-domain-core.md) | 2 |
| **C96** | One could also set the renderer or the defaults there — but that is not actually needed, since the node itself already carries its default settings. | [10-domain-core.md:2586](10-domain-core.md) | 1 |
| **C97** | In the previous project the corresponding nodes were marked as template, so that the user cannot delete them. | [10-domain-core.md:2650](10-domain-core.md) | 1 |
| **C98** | An inexperienced user can break more by deleting. Fundamental things such as Integer must not be deletable — they are part of the framework, as goo… | [10-domain-core.md:2707](10-domain-core.md) | 1 |
| **C99** | The previous project had a developer flag: with it set, one may delete and move anything. | [10-domain-core.md:2708](10-domain-core.md) | 1 |
| **C100** | Moving is unproblematic, since everything acts by id. The id is unique within the tree, and whether a node sits under data types or somewhere else … | [10-domain-core.md:2709](10-domain-core.md) | 1 |
| **C101** | Deletion is two-stage: deleted nodes and relations are first marked deleted and parked in a separate node under the root. Only from there can they … | [10-domain-core.md:2710](10-domain-core.md) | 3 |
| **C102** | The reference check must apply equally to nodes that are referenced — nodes an edge points at. They may not be deleted while the dependencies are u… | [10-domain-core.md:2787](10-domain-core.md) | 9 |
| **C103** | If the author says delete anyway, there must be an active confirmation — not yes / no / OK / cancel, but the user actively confirming. The connecti… | [10-domain-core.md:2788](10-domain-core.md) | 2 |
| **C104** | Example: a node has an attribute of type mein int, itself a child of int and therefore deletable. Deleting mein int means the attribute must be mar… | [10-domain-core.md:2789](10-domain-core.md) | 2 |
| **C105** | The connection is still there, but the edge is marked deleted. How to show that well to the user is not yet clear. | [10-domain-core.md:2790](10-domain-core.md) | 3 |
| **C106** | Pairing compositions with all the other models feels wrong. Better: a Compositions branch, and all compositions live under it. | [10-domain-core.md:2863](10-domain-core.md) | 2 |
| **C107** | When a composition with a higher multiplicity is created, the tool should make an entry there automatically. | [10-domain-core.md:2864](10-domain-core.md) | **nie** |
| **C108** | If a composition later becomes an aggregation, the node can simply be moved from the compositions branch into the normal model branch. | [10-domain-core.md:2865](10-domain-core.md) | 1 |
| **C109** | The reverse — aggregation to composition — is only possible if the aggregation is used by one model. | [10-domain-core.md:2866](10-domain-core.md) | 2 |
| **C110** | A composition edge may point at a node under Compositions — and at simple data types that have no data. | [10-domain-core.md:2929](10-domain-core.md) | 2 |
| **C111** | A node that lies neither in the model branch nor in the composition branch has no data of its own. Its values live in the model that uses it. | [10-domain-core.md:2930](10-domain-core.md) | 3 |

## P — Speicherung in WordPress

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **P1** | The nodes are to be stored relationally. | [50-wordpress-persistence.md:26](50-wordpress-persistence.md) | 4 |
| **P2** | In addition, settings per node, stored generically in a settings table — a node may have settings. | [50-wordpress-persistence.md:27](50-wordpress-persistence.md) | 2 |
| **P3** | Likewise a table for the relations. | [50-wordpress-persistence.md:28](50-wordpress-persistence.md) | 1 |
| **P4** | These are the base tables for storing the model data later: nodes, settings, relations. → amended to nodes, settings, labels, relations by D-019. | [50-wordpress-persistence.md:29](50-wordpress-persistence.md) | 13 |
| **P5** | There will not be a database table per model. The data have to be stored some other way. | [50-wordpress-persistence.md:109](50-wordpress-persistence.md) | 9 |
| **P6** | How exactly is not yet defined. | [50-wordpress-persistence.md:110](50-wordpress-persistence.md) | 1 |
| **P7** | And it does not greatly matter: "I define my model, and with the model I also know how the data are to be interpreted. How they are stored efficien… | [50-wordpress-persistence.md:111](50-wordpress-persistence.md) | 3 |
| **P8** | Such queries must be possible, and as fast as can be managed. This is hereby settled rather than left open. | [50-wordpress-persistence.md:158](50-wordpress-persistence.md) | 3 |
| **P9** | Concretely: all BOMs over a thousand euro; all BOMs containing a particular part; all parts that appear in a particular BOM — and so on. | [50-wordpress-persistence.md:159](50-wordpress-persistence.md) | **nie** |
| **P10** | Almost exactly what a relational database can do, only finer-grained here. | [50-wordpress-persistence.md:160](50-wordpress-persistence.md) | **nie** |
| **P11** | The picture: as if all values lay in one row of a table, and a selection were assembled through well-chosen categories and the ids of type assignme… | [50-wordpress-persistence.md:161](50-wordpress-persistence.md) | 5 |
| **P12** | Type safety matters. That may well mean separate nodes for whole numbers and for decimals, each defined by its own node. | [50-wordpress-persistence.md:363](50-wordpress-persistence.md) | 1 |
| **P13** | And those nodes are stored differently, so that selection stays efficient. | [50-wordpress-persistence.md:364](50-wordpress-persistence.md) | 1 |
| **P14** | Calculation needs numeric fields — nothing can be computed otherwise. | [50-wordpress-persistence.md:365](50-wordpress-persistence.md) | 2 |

## U — Bedienung

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **U1** | the row shows the frequent, a ⋯ menu holds everything — touch has no right-click | [97-implementation-plan.md:188](97-implementation-plan.md) | 6 |
| **U5** | dragging moves whole branches, and several at once | [97-implementation-plan.md:189](97-implementation-plan.md) | 2 |
| **U6** | duplicating puts the copy directly beneath, with an indexed name | [97-implementation-plan.md:190](97-implementation-plan.md) | 2 |
| **U21** | the tree row draws the node's icon | [97-implementation-plan.md:191](97-implementation-plan.md) | 3 |
| **U24** | , das die Cleanup-Fläche besitzt; hier steht nur, was 2026-08-29 dazukam. | [20-interaction.md:1128](20-interaction.md) | 7 |

## R — Renderer — wie gezeichnet wird

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **R1** | Display happens only through a renderer. No other path produces output. This is a hard rule. | [30-renderer.md:78](30-renderer.md) | 112 |
| **R2** | There are several renderers, producing different representations of the same thing. | [30-renderer.md:79](30-renderer.md) | 4 |
| **R3** | A renderer always receives a node — or possibly a set of nodes. Explicitly stated as not yet certain. | [30-renderer.md:80](30-renderer.md) | 8 |
| **R4** | There is a renderer for every kind of display. The point is that no display logic is implemented twice. | [30-renderer.md:91](30-renderer.md) | 8 |
| **R5** | A renderer may work with trees and call other renderers. | [30-renderer.md:92](30-renderer.md) | 2 |
| **R6** | A node is handed to the registry, not to a renderer directly. The registry looks up the node's default renderer and that renderer renders the node. | [30-renderer.md:93](30-renderer.md) | 3 |
| **R7** | The renderer then reads the node's attributes. Attributes point at nodes; those nodes have their own renderers, which render them. The descent repe… | [30-renderer.md:94](30-renderer.md) | 6 |
| **R8** | Three display levels: the admin module (where models are rendered), Gutenberg blocks (fill data, make it available to the site), and the frontend (… | [30-renderer.md:95](30-renderer.md) | 6 |
| **R9** | A renderer — an integer renderer, say — must carry options for these different circumstances. | [30-renderer.md:96](30-renderer.md) | 3 |
| **R10** | Every renderer must support editable / not editable. | [30-renderer.md:97](30-renderer.md) | 3 |
| **R11** | A renderer must honour whether a node is visible (hide), as set on the attributes. Held, and by a mechanism that makes the renderer's part of it em… | [30-renderer.md:98](30-renderer.md) | 9 |
| **R12** | The registry is the one place where all renderers are registered. | [30-renderer.md:295](30-renderer.md) | 8 |
| **R13** | A node carries only the name of its renderer. Handing the node to the registry means: look up that name, fetch the renderer. | [30-renderer.md:296](30-renderer.md) | 1 |
| **R14** | Every renderer records which node types it is responsible for when it registers — so the settings UI can offer a choice. | [30-renderer.md:297](30-renderer.md) | 17 |
| **R15** | One renderer per presentation variant. An integer node can be shown as a plain field, a spinner, or a slider: three renderers, not one renderer wit… | [30-renderer.md:298](30-renderer.md) | 17 |
| **R16** | Creating an attribute means choosing: the target node, composition or aggregation, a name, and optionally a different default renderer — the render… | [30-renderer.md:299](30-renderer.md) | 2 |
| **R17** | Integer and double nodes need min, max and step as settings. The same renderers serve both, with small deviations. | [30-renderer.md:300](30-renderer.md) | 16 |
| **R18** | The tree view consists of nodes too, so a node can be drawn in the tree by a renderer. Another renderer role. | [30-renderer.md:495](30-renderer.md) | 17 |
| **R19** | The modelling admin screen is split in two: the tree on the left, the settings of the selected node on the right. Concept taken from the predecesso… | [30-renderer.md:496](30-renderer.md) | 3 |
| **R20** | The settings side is itself a page renderer, and it follows special steps. Also described in the old concept, same caveat. | [30-renderer.md:497](30-renderer.md) | 11 |
| **R21** | Every node has a preview, assembled from its chosen renderer, with an edit view and a display view. | [30-renderer.md:498](30-renderer.md) | 7 |
| **R22** | The preview runs on test data, which has to be stored somewhere — for instance a separate test-data source holding sample data per node type, which… | [30-renderer.md:499](30-renderer.md) | 3 |
| **R23** | Switching a node's renderer, or changing a setting on the node or on its attributes, changes the preview accordingly — multiplicity, type, read-onl… | [30-renderer.md:500](30-renderer.md) | 6 |
| **R24** | Input interactions are unified. Selecting a node happens either inline in the settings or through a dialog. Which is preferred is a setting in the … | [30-renderer.md:813](30-renderer.md) | 3 |
| **R25** | A chooser is given two nodes: a branch node, whose subtree it shows, and a default node, down to whose children the tree is expanded. | [30-renderer.md:814](30-renderer.md) | 7 |
| **R26** | The user picks from those children — but may also move into any other branch that is on screen. | [30-renderer.md:815](30-renderer.md) | 1 |
| **R27** | The branch node is what scopes the choice: picking any node means the whole tree; picking a model means the models branch is put in front. | [30-renderer.md:816](30-renderer.md) | 3 |
| **R28** | If a selection has zero or one entry, then only that one entry or nothing can be the answer. | [30-renderer.md:860](30-renderer.md) | 50 |
| **R29** | Whether nothing is allowed follows from the multiplicity: 0..1 and 0.. may be empty; 1 and 1.. must always have a selection. | [30-renderer.md:861](30-renderer.md) | 14 |
| **R30** | So with multiplicity 1 or 1.. and exactly one available entry, that entry is selected and the field greyed out. | [30-renderer.md:862](30-renderer.md) | 11 |
| **R31** | With no available entry there is nothing to choose and the control is disabled. | [30-renderer.md:863](30-renderer.md) | 7 |
| **R32** | This principle is to be held for all inputs, not only this one. | [30-renderer.md:864](30-renderer.md) | 38 |
| **R33** | A node can have several converters. | [30-renderer.md:958](30-renderer.md) | 7 |
| **R34** | One thing they could do: show a number as binary, hexadecimal, octal or in Roman numerals. | [30-renderer.md:959](30-renderer.md) | 10 |
| **R35** | "Whether this form is really hung on as a converter, I am not sure — but we should keep it in mind. Storing the twelve is one thing, showing it as … | [30-renderer.md:960](30-renderer.md) | 2 |
| **R36** | And: "if I say greater than Roman twelve, values greater than that should be shown — which ought to be no obstacle if it is stored as a decimal num… | [30-renderer.md:961](30-renderer.md) | 25 |
| **R37** | The registry's render is only an entry point. The registry itself neither represents nor renders anything. | [30-renderer.md:1381](30-renderer.md) | 1 |
| **R38** | A basic renderer simply receives a node, and renders it down to the leaves. | [30-renderer.md:1382](30-renderer.md) | 3 |
| **R39** | The sequence: from the node take the renderer name → via the registry get the renderer → call it for this node → it renders the node's own properti… | [30-renderer.md:1383](30-renderer.md) | **nie** |
| **R40** | Each edge is rendered the same way: take the edge, see what renderer is there, render it. So both nodes and edges must be renderable — the owner ca… | [30-renderer.md:1384](30-renderer.md) | 1 |
| **R41** | If the edge carries no renderer, take the one from the connected node, because nothing was overridden. The highest override wins — for the renderer… | [30-renderer.md:1385](30-renderer.md) | 7 |
| **R42** | The preview shows both: once editable and once not. That is a special case — everywhere else the mode is given by the caller. | [30-renderer.md:1541](30-renderer.md) | 2 |
| **R43** | If the attribute is read-only there is no input. That has to be taken into account here too. | [30-renderer.md:1542](30-renderer.md) | 2 |
| **R44** | When rendering, always honour every attribute. A ground rule — no special arrangements, the same everywhere. | [30-renderer.md:1543](30-renderer.md) | 3 |
| **R45** | Rendering a type that has int as an attribute with max, min and step overridden but not the renderer: the edge has no renderer, so go one deeper to… | [30-renderer.md:1544](30-renderer.md) | 1 |
| **R46** | With multiplicity another renderer can be named — a container: a compact row or column, a table renderer, a form renderer. | [30-renderer.md:1610](30-renderer.md) | 17 |
| **R47** | It then takes the table's render function first, but for the individual field functions it looks one level deeper again. | [30-renderer.md:1611](30-renderer.md) | 2 |
| **R48** | The data say what is in it — the two-input reading of the descent is right. | [30-renderer.md:1612](30-renderer.md) | **nie** |
| **R49** | The preview needs no special arrangement. Simply call render twice — once editable, once not. | [30-renderer.md:1613](30-renderer.md) | 3 |
| **R50** | A container renderer is not only chosen on the attribute. A model — say Parts list — is first of all a node, and one can say on the node that it sh… | [30-renderer.md:1656](30-renderer.md) | 1 |
| **R51** | Choosing these renderers for data-type nodes makes no sense — they all have their own. The grouping renderers are for nodes that do not inherit fro… | [30-renderer.md:1733](30-renderer.md) | 4 |
| **R52** | Detecting cycles is right, and the same node should not be rendered twice. The reference is exactly the right answer. | [30-renderer.md:1868](30-renderer.md) | 1 |
| **R53** | A depth limit can be done, but it can cause errors: if the depth really is greater than expected, something simply is not shown and the user does n… | [30-renderer.md:1869](30-renderer.md) | 3 |
| **R54** | The warning could be shown in the preview, because the preview is exactly what renders it in advance. | [30-renderer.md:2010](30-renderer.md) | 1 |
| **R55** | In the preview the author sees how the model will later appear on the page — once for input and once for output. | [30-renderer.md:2011](30-renderer.md) | **nie** |
| **R56** | It is not yet clear where rendering — or other functions — stop. | [30-renderer.md:2066](30-renderer.md) | 2 |
| **R57** | In Gutenberg a depth could be given for the table blocks — which the old model already provided for. | [30-renderer.md:2067](30-renderer.md) | 1 |
| **R58** | One possibility is a manual stop at an aggregation, by having a reference renderer: it does not render further, it only shows a reference. | [30-renderer.md:2183](30-renderer.md) | 3 |
| **R59** | There are still cases where an aggregation is to be directly selected and filled in — the part in a parts list, for instance. | [30-renderer.md:2250](30-renderer.md) | 1 |
| **R60** | A chooser is in principle also a renderer, but it has more functions — at least two render forms: inline, and button plus popup. | [30-renderer.md:2430](30-renderer.md) | 1 |
| **R61** | "The popup is, I think, not quite render-conform — unless we make it a part of it, an inline/popup context option." | [30-renderer.md:2431](30-renderer.md) | 2 |
| **R62** | Two separate renderers — an inline chooser and a popup chooser — and the author states which makes sense in a given place. | [30-renderer.md:2476](30-renderer.md) | 1 |
| **R63** | If the selectable set has no children — only one level — it is really a selection list, and the renderer should show it that way. One level → list,… | [30-renderer.md:2477](30-renderer.md) | 4 |
| **R64** | The initial node handed in — the branch root — does not normally count as a choice. Open whether that must be configurable or is a general rule. | [30-renderer.md:2478](30-renderer.md) | 2 |
| **R65** | The multi-step renderer: the user first chooses a node, then has to enter data for that node. Changing the selection calls the tree chooser again. | [30-renderer.md:2655](30-renderer.md) | 5 |
| **R66** | The row of the model to be filled in then appears, driven by JavaScript. | [30-renderer.md:2656](30-renderer.md) | 1 |
| **R67** | Two possibilities at that point: enter, or search among what already exists. Before creating something new one must always check whether it is alre… | [30-renderer.md:2657](30-renderer.md) | 3 |
| **R68** | The search runs on the human-readable values — enter 10 kilo and find the resistor that already has 10 kilo, and it need not be entered again. | [30-renderer.md:2701](30-renderer.md) | 1 |
| **R69** | The input is treated with a wildcard before and after — a contains search. | [30-renderer.md:2702](30-renderer.md) | 1 |
| **R70** | A part may hold many more attributes, but not all of them need to be visible. Which fields of an aggregation or composition are shown is a choice, … | [30-renderer.md:2703](30-renderer.md) | 2 |
| **R71** | And those visible fields are the general search criteria. | [30-renderer.md:2704](30-renderer.md) | 2 |
| **R72** | Open: what happens to the remaining fields when it comes to real input — and there a popup is probably unavoidable, or should be planned for anyway… | [30-renderer.md:2705](30-renderer.md) | 3 |
| **R73** | There were rules for when what is rendered — in which order a node's properties appear. The same rules hold for the admin background, Gutenberg and… | [30-renderer.md:2904](30-renderer.md) | 1 |
| **R74** | First the single values, then those with higher multiplicity. | [30-renderer.md:2905](30-renderer.md) | **nie** |
| **R75** | Within the single values: first the fixed values the user cannot change — not shown at every level, but at least in the admin; then the ordinary fi… | [30-renderer.md:2906](30-renderer.md) | 14 |
| **R76** | The booleans run along a row as far as it fits, then the next row — but still column-aligned, so it looks tidy. | [30-renderer.md:2907](30-renderer.md) | 2 |

## I — Sprachen und Übersetzung

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **I1** | As little hard-coded text as possible — ideally none. | [40-i18n.md:31](40-i18n.md) | 3 |
| **I2** | A node name is text too. Identity runs over the id, not over the name. | [40-i18n.md:32](40-i18n.md) | 5 |
| **I3** | Every text should be translatable. | [40-i18n.md:33](40-i18n.md) | **nie** |
| **I4** | A node carries several labels: a long description, a form label, a table label, and a symbol of roughly three characters or fewer. | [40-i18n.md:34](40-i18n.md) | 1 |
| **I5** | A node also carries an icon, which is not language-dependent but must be changeable in a suitable place. | [40-i18n.md:35](40-i18n.md) | 6 |
| **I6** | Validators and dialogs contain texts that must be translatable too. | [40-i18n.md:36](40-i18n.md) | 2 |
| **I7** | WordPress standard is preferred, including for multilingual operation. | [40-i18n.md:37](40-i18n.md) | 1 |
| **I8** | Modelling translations as nodes is not sensible — they are not really translations in that sense. | [40-i18n.md:38](40-i18n.md) | 2 |
| **I9** | Configuration values such as an upper bound, a lower bound and a step are a different thing from labels and should be kept apart, possibly in their… | [40-i18n.md:39](40-i18n.md) | 1 |
| **I10** | A node must always have a name by default, even when no translation was entered. | [40-i18n.md:40](40-i18n.md) | 5 |

## K — Rechnen

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **K1** | Switching a prefix — gram to kilogram — has to change the value with it. An internal conversion, handled differently again in the interface. | [60-calculation.md:28](60-calculation.md) | 4 |
| **K2** | There are computed values assembled from other fields. Hidden fields may be added up and the result shown in another field. | [60-calculation.md:29](60-calculation.md) | 6 |
| **K3** | A parts list has a total price, which comes from the prices of its positions, each of which comes from quantity × unit price. | [60-calculation.md:30](60-calculation.md) | 16 |
| **K4** | Elsewhere, averages or sums are wanted. | [60-calculation.md:31](60-calculation.md) | 1 |
| **K5** | In the old concept a calculation could also be a transformation — a text transformation, say. The owner asks for this to be questioned. | [60-calculation.md:32](60-calculation.md) | 4 |
| **K6** | There is a difference between calculations in the model and calculations for display. A parts list may get a frontend footer that sums quantity and… | [60-calculation.md:33](60-calculation.md) | 6 |
| **K7** | If a parts list should always show the average price, it is recalculated each time rather than snapshotted — and the calculation feeds from another… | [60-calculation.md:440](60-calculation.md) | 1 |
| **K8** | A backward-read value is computed at read time, not materialised. It is not a stored value; it is worked out afresh on every display. | [60-calculation.md:441](60-calculation.md) | 1 |
| **K9** | A value could be frozen, and regenerated on request when it has drifted too far — noticed while editing the parts list anyway. | [60-calculation.md:504](60-calculation.md) | **nie** |
| **K10** | In principle it is only an approximation. | [60-calculation.md:505](60-calculation.md) | 1 |
| **K11** | Parts get dearer and cheaper year on year, so the average may simply not be the right thing — perhaps the value of stock on hand, falling back to w… | [60-calculation.md:506](60-calculation.md) | 1 |
| **K12** | Every time a parts list is saved, the values could be recalculated: it has been touched anyway, and then the figures are current. | [60-calculation.md:507](60-calculation.md) | 2 |

## M — Migration

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **M1** | Changing a unit or a description changes only the output. The node is the same one that was used; the data stay the same. | [70-migration.md:31](70-migration.md) | 3 |
| **M2** | Therefore the data must hold a reference to the node id, never to the name. Rename the node and every output changes — the data do not. | [70-migration.md:32](70-migration.md) | 1 |
| **M3** | Documents are not part of this concept. An exported PDF is detached from the model; regenerating it later would simply look different. There is no … | [70-migration.md:33](70-migration.md) | 2 |
| **M4** | Replacing a node with a different one is another matter. That changes the model version and produces a conflict in existing data, which the user ha… | [70-migration.md:34](70-migration.md) | 4 |
| **M5** | Data made of id references are not human-readable. One cannot look into the database the way one can with a relational one. That is already true of… | [70-migration.md:35](70-migration.md) | 3 |
| **M6** | Two surfaces follow: data entry in the admin — choose a model, see its data — and the conflict resolver. | [70-migration.md:36](70-migration.md) | 13 |
| **M7** | The conflict resolver shows which models have conflicts with their data and lets the user resolve them. Afterwards the model has no conflicts and t… | [70-migration.md:37](70-migration.md) | 1 |
| **M8** | Resolution can run in several stages. If the model changed repeatedly and structural problems accumulated, they are resolved one after another unti… | [70-migration.md:38](70-migration.md) | 7 |
| **M9** | All data must be exportable and re-importable — and that includes the tree. With the tree present, the assignment is present too. | [70-migration.md:134](70-migration.md) | 7 |
| **M10** | Alternatively, or in addition, the data may be written in plain text: store Stück as the word as well, so that it can be resolved back afterwards. | [70-migration.md:135](70-migration.md) | 1 |
| **M11** | The one problem: if Stück no longer exists as a unit value, the import has to resolve it — either map it to another node, or create a node and bind… | [70-migration.md:136](70-migration.md) | 5 |
| **M12** | CSV, PDF, an interactive parts list — those are views of the data, a different thing from backup. | [70-migration.md:137](70-migration.md) | 7 |
| **M13** | Every record belongs to a model version. Resolving carries data from one version to the next, so the version on the record advances and the record … | [70-migration.md:138](70-migration.md) | 4 |
| **M14** | Until everything is resolved, records of different versions coexist. When all is resolved, only records of the current version remain. | [70-migration.md:139](70-migration.md) | 4 |
| **M15** | The resolver offers mapping from one field to another — and not only one to one, but with a transformation: move everything from the old Stück colu… | [70-migration.md:140](70-migration.md) | 1 |
| **M16** | For new fields it offers filling them by hand across all records, or a bulk change — set them all to this value. Only needed at all when the field … | [70-migration.md:141](70-migration.md) | 1 |
| **M17** | A change that creates a new version — deleting a field, say — must warn the user at the moment of the change: the old records still hold that field… | [70-migration.md:142](70-migration.md) | 5 |
| **M18** | Before an update there must always be a backup. «Das ist Pflicht. Der Benutzer wird dazu gezwungen, es herunterzuladen.» | [70-migration.md:589](70-migration.md) | 2 |
| **M19** | ?einfach nur einen Logeintrag machen für die Installation und für ein Folgeupdate, mit einer Referenz auf eine Versionsnummer … und dann vielleicht… | [70-migration.md:590](70-migration.md) | 4 |
| **M20** | ?Zum einen müssen wir sicherstellen, dass die Daten, die schon im Baum sind, nicht kaputtgemacht werden. Und zum anderen, dass wir verlustfrei neue… | [70-migration.md:591](70-migration.md) | 2 |
| **M21** | ?Solange ich Konflikte habe, darf ich die Sätze nicht löschen. Das ist eine Abhängigkeit, und die müssen wir befolgen. … Ist der Konflikt aufgelöst… | [70-migration.md:592](70-migration.md) | 3 |
| **M22** | ?ich würde das Create drinne lassen — und nicht nur das Create, sondern jedes Update, das gefahren wird. Das würde ich drinnen lassen im Changelog … | [70-migration.md:593](70-migration.md) | 3 |

## A — Standardbaum — Aufbau

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **A1** | Complex Datatypes › Unit type is composed of Menge + Base unit + Praefix. | [01-standard-tree.md:43](01-standard-tree.md) | 2 |
| **A2** | Complex Datatypes › quantity › Preis is composed of Wert + Währung. | [01-standard-tree.md:44](01-standard-tree.md) | 3 |
| **A3** | Basiseinheiten splits into With prefix and Without prefix; Kelvin, Celsius and Stück sit under the latter. Children inherit Praefix and Kuerzel fro… | [01-standard-tree.md:45](01-standard-tree.md) | 2 |
| **A4** | Attributes appear as children of their owning node (Bauteilliste › Name, Bauart, Position). | [01-standard-tree.md:46](01-standard-tree.md) | **nie** |
| **A5** | Eigene Datentypen exists as a branch beside Simple/Complex. | [01-standard-tree.md:47](01-standard-tree.md) | **nie** |

## B — Standardbaum — Inhalt

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **B1** | Relationstypen is a branch of the tree — relation kinds stored as nodes. | [01-standard-tree.md:55](01-standard-tree.md) | 4 |
| **B2** | Seven kinds, not three: plus has_type, defaultvalue_from, calc, ref_scope. | [01-standard-tree.md:56](01-standard-tree.md) | 5 |
| **B3** | Kilogramm listed as a base unit with prefix. | [01-standard-tree.md:57](01-standard-tree.md) | 2 |
| **B4** | Praefix and Kuerzel on parent and on every child. | [01-standard-tree.md:58](01-standard-tree.md) | **nie** |
| **B5** | Simple Datatypes contains display_node_name. | [01-standard-tree.md:59](01-standard-tree.md) | 2 |
| **B6** | Währung beside Basiseinheiten, not under a common root. | [01-standard-tree.md:60](01-standard-tree.md) | 2 |
| **B7** | calc has no counterpart in the new concept. V6 mentions special nodes for calculations and nothing had been designed since. The old tree also has a… | [01-standard-tree.md:79](01-standard-tree.md) | 5 |
| **B8** | The old concept let a calculation also be a transformation — text transformation and the like. | [01-standard-tree.md:80](01-standard-tree.md) | **nie** |

## S — Speicher

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **S6** | with rows 5 and 8, which were blocked on OQ-092's column until D-413 delivered it. | [90-decision-log.md:661](90-decision-log.md) | 5 |

## Q — Aus der Altlast übernommene Frage

| Regel | Was sie sagt | Steht in | Zitiert |
|---|---|---|---|
| **Q59** | )"; OQ Q18, Q3 | [03-legacy-inspiration.md:60](03-legacy-inspiration.md) | **nie** |

