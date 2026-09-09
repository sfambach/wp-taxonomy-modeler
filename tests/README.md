# Tests — two runs, and that is the point

**The whole reason there are two** is [D-169](../docs/NewConcept/90-decision-log.md) and
[D-170](../docs/NewConcept/90-decision-log.md): WordPress sits **around** the core, not
underneath it. A rule that is only true because WordPress happens to behave a certain way is a
rule the core cannot be trusted with.

| Run | What it covers | Needs |
|---|---|---|
| **`core`** — `tests/Core/` | the domain: nodes, paths, versions, parking | nothing. No WordPress, no database |
| **boundary** — `scripts/dev/*-check.php` | tables, foreign keys, edges, attributes, `$wpdb`, migrations, and both admin screens | a running WordPress and its database |
| **references** — `scripts/dev/references-check.php` | that every `D-` and `OQ-` the repository cites actually exists | nothing |

```bash
php vendor/phpunit/phpunit/phpunit
```

```bash
for c in scripts/dev/*-check.php; do php "$c" || echo "FAILED: $c"; done
```

⚠️ **The boundary run is a glob and no longer a hand-written list**, because the hand-written
one went stale: `package7`, `unitvalue`, `settings-screen` and `references` all existed while this
file still named seven scripts. *A list of checks that omits checks is worse than no list — it reads
as the whole net.*

| Check | What it guards |
|---|---|
| `package3`, `package7` | ⚠️ *Und seit dem 2026-09-06 steht `pakete` daneben — **ein** Lauf, der den Weg als Ganzes geht, statt vier wiederbelebter.* ⚠️ **Es waren einmal sechs, einer je Paket** ([97 Implementation plan](../docs/NewConcept/97-implementation-plan.md)). *`package1`, `package2`, `package5` und `package6` sind am 2026-09-06 gestrichen — ihre Aussagen stehen verteilt in `shadow-shape`, `label-space`, `id-space`, `path`, `sort-order`, `collapsed-default`, `restore`, `parked-in-shadow`, `record-on-first-write`, `several-values`, `edge-class` und im Kernlauf. **Geblieben sind die zwei mit belegtem Fund.** Was das gekostet hat, steht in [`waechter-bestand.md`](../docs/pakete/modelltabellen/waechter-bestand.md) — auf sein Wort «checks mein ja», und mit seinem Grund: «weniger check dafür aber richtige».* |
| `pakete` | **der Weg von aussen, wie ein Mensch ihn geht** — anlegen, benennen, beschriften, ein Feld daran, ein Datensatz, ein Wert hinein und wieder heraus, umbenennen, verschieben, ordnen, parken, wiederherstellen, löschen, und nach jedem Schritt: steht, was stehen soll, und ist nichts liegengeblieben? ⚠️ *Er ist der **Ersatz für die vier gestrichenen Paketläufe** — nicht ihre Wiederbelebung. `package1/2/5/6` kosteten 127 Zusagen; was mit ihnen verlorenging, war nicht eine einzelne Aussage, sondern **die Stelle, an der ein Paket als Ganzes noch einmal durchgespielt wird** ([`waechter-bestand.md`](../docs/pakete/modelltabellen/waechter-bestand.md)). Er misst am **Ergebnis** und baut sich seine eigene Wiese, Präfix `__pk`.* |
| `scaffold` | the seeded data types and framework nodes |
| `simple-type` | **das Inventar der einfachen Typen** ([D-484](../docs/NewConcept/90-decision-log.md)) — Klassen, Aufzählungsfälle und gesäte Knoten sind **eine** Zahl, jeder Typknoten trägt seine Klasse, keine der elf Optionen ist zurück, und die Validatoren lesen ab, was der Typ kann |
| `unitvalue` | a value with a prefix and a unit — `2.7 kΩ` stored and read back ([D-394](../docs/NewConcept/90-decision-log.md)) |
| `settings-screen` | the installation screen, and that its two sizes reach the stylesheet ([D-397](../docs/NewConcept/90-decision-log.md)) |
| `preview` | the preview, and that `hide` and `read_only` actually **do** something ([D-160](../docs/NewConcept/90-decision-log.md), [D-399](../docs/NewConcept/90-decision-log.md)) |
| `path` | dass **`path` gefallen ist und nicht wiederkommt** — an `labels` (TASK-003), an `relation_records` (TASK-002) und an `nodes` (TASK-001), in der Datenbank **und** in der `CREATE TABLE`-Anweisung, aus der `dbDelta` sie sonst klaglos wieder anlegte. **Und dass die Vorfahren ohne sie dieselbe Kette liefern:** der gerechnete Weg und der Aufstieg über `parent_node_id` sind zwei Rechnungen mit einem Ergebnis, und die Wanderung hat ihren Rückweg hinterlassen — je Zeile ihr alter Weg, ihre Version, eine Änderungsgruppe. ⚠️ *An `relation_records` stand die Spalte am längsten, mit Grund: sie trug die Adresse einer Einstellung an einer Verwendungsstelle und war **kein** Spiegel von `relation_id` (`INF-051`). **Seit dem 2026-09-06 trägt der Satz diese Adresse** ([D-667](../docs/NewConcept/90-decision-log.md)) — Fassung 37 hat sie umgehängt, Fassung 39 hat die Spalte gestrichen, und **der Schatten behält sie**: die drei zweiteiligen Adressen stehen dort und nirgends sonst. Er hiess einmal umgekehrt herum: er prüfte `settings.path` ([D-413](../docs/NewConcept/90-decision-log.md)) und ist mit der Tabelle gefallen ([D-579](../docs/NewConcept/90-decision-log.md)), ohne Ersatz* ⚠️ **Seit dem 2026-09-06 sagt er dasselbe auch über `nodes.field_type`** — *`field-type-gone-check` ist mit ihm zusammengelegt: zwei Läufe, eine Aussage, «eine gefallene Spalte kommt nicht zurück, und `dbDelta` legt sie nicht wieder an». Keine seiner zwölf Zusagen ist dabei weggefallen* |
| `labels-page-save` | that the texts travel with the page save — the fields name the page's form, an unchanged one writes nothing, an emptied one loses its row ([D-384](../docs/NewConcept/90-decision-log.md), [D-392](../docs/NewConcept/90-decision-log.md)) |
| `journal-address` | that a journal entry carries its **address** and not only its value — key, path, type and value, readable back out of the column ([D-427](../docs/NewConcept/90-decision-log.md)) — **and that *every* row already in the table still parses**, with the reader it replaced compared against on every one written in the old order. ⚠️ *Hier standen bis zum 2026-09-06 zwei feste Zahlen aus seinem Bestand — «10918 Zeilen», «2904 in der alten Reihenfolge». Der Wächter selbst trägt sie nicht, er zählt sie; die Zusage tat so, als wäre der Bestand von damals der Vertrag.* |
| `references` | that no file cites a `D-` or `OQ-` id that was never written ([`PR-3`](../CLAUDE.md)) |
| `rules-index` | that [`02-rules-index.md`](../docs/NewConcept/02-rules-index.md) still lists **every** rule the project has — it goes red the moment a rule is introduced without appearing there |
| `concept-drift` | that a **model document** never changes without a reason standing as a decision ([`PR-2`](../CLAUDE.md), [D-565](../docs/NewConcept/90-decision-log.md)) — either the decision log changes with it, or the new lines name an existing decision |
| `confirmed-quote` | that a decision calling itself **confirmed** actually carries a sentence of the owner's ([D-571](../docs/NewConcept/90-decision-log.md)) — otherwise it is `INFERRED` and needs his yes |
| `always-on` | that what must be read **before every task** — `CLAUDE.md`, the working model, `AGENTS.md` — stays under its measured ceiling ([D-573](../docs/NewConcept/90-decision-log.md)). *The previous rule set died at 82 KB, «most of it always-on». Today: 42 KB.* |
| `silent-query` | dass eine **kaputte Abfrage wirft, statt leer zu antworten** — alle vier Lesewege, dazu die leere `prepare()`-Anweisung, der Gegenbeweis, dass ein wirklich leeres Ergebnis leer bleibt, und die Messung, dass die Speicher kein `$wpdb->get_*` mehr direkt aufrufen |
| `seed-twice` | dass **eine Saat, die zweimal läuft, nichts verdoppelt** — Saat und alle vier Gerüste ein zweites Mal, an `importOnce()` vorbei, Knoten und Kanten vorher und nachher gezählt. ⚠️ **Und seit dem 2026-09-06 die Gegenprobe dazu: dass aus dem Abzug derselbe Baum entsteht** ([D-600](../docs/NewConcept/90-decision-log.md), TASK-064) — *das Modell im `SAVEPOINT` geleert, `data/saat.json` eingespielt, und **Zählung und Prüfsumme** müssen Zeile für Zeile und Feld für Feld dieselben sein. **Die Zusage spricht in Zahlen und nicht in Namen**, und das ist der Punkt: die vier Gerüste suchen am Namen, und genau so kam dreimal eine deutsche `Adresse` in seinen Bestand.* |
| `parked-in-shadow` | dass **Parken wandern heisst** ([D-575](../docs/NewConcept/90-decision-log.md), [D-619](../docs/NewConcept/90-decision-log.md), TASK-013) — die Kante mit ihrer Änderungsgruppe in den Schatten, **ihre Wertzeilen mit ihr**, und Zurückholen als Umkehrung. *Er legt sich seinen eigenen Fall an (Präfix `__`) und räumt ihn weg, weil heute keine geparkte Kante eine Wertzeile trägt* |
| `no-model-write` | dass **kein Wächter in das Modell des Eigentümers schreibt** — jeder Lauf mit `wp-load` trägt unmittelbar danach die Klammer aus [`lib/no-write.php`](../scripts/dev/lib/no-write.php), keiner beginnt eine eigene Umklammerung, und jede Ausnahme steht mit Grund. *Siehe die Regel am Fuss dieser Datei* |
| `setting-lock` | **geerbt ist gesperrt, unzulässig geerbt ist ein Konflikt, und die Sperre fällt im Konflikt von selbst** ([D-687](../docs/NewConcept/90-decision-log.md), [D-688](../docs/NewConcept/90-decision-log.md), [D-689](../docs/NewConcept/90-decision-log.md), TASK-078) — *am Markup: die gesperrte Zeile, der Haken, «inherited from ‹Name›» in Worten; am Bestand: ohne Haken schreibt das Speichern nichts, mit Haken einen eigenen Wert; und an einer Zahl unter `Integer`, deren Eltern `compact` wählen: die Zeile steht offen, der Haken ist gesetzt, der Typ-Standard zeichnet. **An beiden Adressen** (D-685): Wertspalte am Knoten und Tafel unter der Feldzeile. Baut sich seine Wiese selbst, Präfix `__lk`* |
| `field-kind` | **die Kantenart ist in der eigenen Feldzeile änderbar, und die Werte wandern mit** ([D-618](../docs/NewConcept/90-decision-log.md), [D-690](../docs/NewConcept/90-decision-log.md), TASK-066) — *dasselbe Auswahlfeld wie beim Anlegen, an der geerbten Zeile nur das Wort; Feld → Einstellung schiebt den einen Wert in den `default`-Satz, Einstellung → Feld lässt ihn dort, zwei verschiedene Werte sind ein Konflikt und nichts wandert. Präfix `__fk`* |
| `edge-class` | **drei Werte, drei Klassen** ([D-639](../docs/NewConcept/90-decision-log.md), TASK-032) — jede lebende Kante trägt einen der drei, die Ableitung Wert → Klasse ist vollständig und eindeutig, eine **geladene** Kante kommt als ihre Klasse an, und **kein Datensatz hängt an zwei Besitzern**. *Die Aussage gilt den Daten, nicht dem Modell: ein Kompositionsziel **darf** geteilt sein — 36 von 42 zeigen auf Typknoten, und der Wächter, der das verboten hätte, hätte 36 richtige Zeilen berichtigt. Den Verstoss legt er sich selbst an (Präfix `__`) und räumt ihn weg* |

⚠️ **`doc-reach-check` ist am 2026-09-01 stillgelegt** ([D-574](../docs/NewConcept/90-decision-log.md))
und heisst jetzt `doc-reach-stillgelegt.php` — damit ist es aus dem Muster oben heraus. *Es mass, ob
eine Entscheidung das Dokument erreicht, das sie als betroffen nennt; [D-568](../docs/NewConcept/90-decision-log.md)
hat dieses Konzept für veraltet erklärt, und es zu erfüllen hiesse, in den Steinbruch zu schreiben.
**Ein dauerhaft roter Wächter erzieht dazu, rote Wächter zu übersehen** — deshalb sichtbar
stillgelegt statt stillschweigend ignoriert.*

⚠️ **`concept-drift-check` belongs to a rule of the owner's, and the rule replaced a term of mine.**
*He rejected «locked» on 2026-09-01: «ich wollte nie Sachen locken … es gibt nur Konzept ist fertig
und kann jetzt umgesetzt werden. Oder Konzept ist veraltet und wird durch neues ersetzt», plus
«Modelle die beschlossen sind nicht einfach ohne Grund aufgeweicht werden».* **What it lets through is
measured, not guessed:** *of the last 80 commits, 12 changed a model document and **one** did so
without touching the decision log — and that one's added lines **name** `D-183` and `D-522`, because
the decision had landed a commit earlier. Hence two acceptable proofs instead of one. Without the
second, the guard would have been wrong in the only historical case it had — and a guard that flags
correct work gets switched off.*

⚠️ **`rules-index-check` exists because the owner could not hand the rule set over.** *His words on
2026-09-01: «hätte ein Problem den aktuellen Regelsatz zu übergeben, da er Chaos enthält, deswegen
hatte ich gebeten den aufzuräumen, aber das ist nie passiert.» **Measured, the chaos was not dead
weight — 306 rules in 16 namespaces, and 272 of them cited somewhere.** It was scatter: they live in
15 different files, `CLAUDE.md` holds about 8 % of them, and **no single place listed them**. Nobody
could know, before starting a task, which rules bore on it — which is how `R1` came to be broken
repeatedly and the road from multiplicity to a control got invented four times over.*

⚠️ **`references-check` exists because seven decisions were cited in code and never written**, all
on 2026-08-26 — `D-392` through `D-397`, each sitting in a docblock as if it had authority. *A
dangling id is not a typo: it is a rule that nobody agreed to, quoted as though somebody had.*

⚠️ **A check reports `all green` or a count of failures and exits non-zero**, so the loop above is
the whole boundary run.

⚠️ **A WordPress call that drifts into `Taxmod\Core` fails on the first run**, immediately,
because nothing is there to answer it. That is a mechanical check on `CD-1`, not a promise.

## Ein Wächter schreibt nicht in das Modell des Eigentümers

⚠️ **Ein Wächter darf lesen, so viel er will; was er schreibt, überlebt ihn nicht.** Jeder Lauf, der
WordPress lädt, lädt unmittelbar danach [`scripts/dev/lib/no-write.php`](../scripts/dev/lib/no-write.php):
`START TRANSACTION`, und ein `ROLLBACK` am Herunterfahren des Prozesses. Wer innerhalb eines Laufs
selbst zurückdrehen will, nimmt einen `SAVEPOINT` — ein zweites `START TRANSACTION` **bestätigt** in
MySQL stillschweigend alles Bisherige und schriebe genau den Rückstand fest, den die Klammer
verhindert. Bewacht von `no-model-write-check.php`.

⚠️ **Und ein Lauf zur Zeit — seit dem 2026-09-09 holt die Klammer vor der Transaktion eine benannte
Sperre** (TASK-073). *Gemessen: `cleanup-screen-check` starb im vollen Randlauf an einem Deadlock, weil
eine zweite Sitzung in derselben Sekunde Knoten unter demselben Elternknoten anlegte — und meldete
rot, obwohl seine Aussage stimmte. **Ein zweiter Lauf bekommt die Sperre nicht, sagt es und geht mit
Rückgabewert 3**, weder grün noch rot, und ohne auf das Ende des ersten zu warten. Die Sperre hängt an
der Verbindung und fällt mit dem Prozess, auch nach einem Abbruch. Abschnitt 4 desselben Wächters
misst es an zwei echten Prozessen; die Probe dazu liegt in `lib/klammer-probe.php`.* **Die Schleife
oben bleibt seriell — die Sperre schützt den Fall, der dort nicht steht: zwei Fenster, zwei
Sitzungen.**

⚠️ **Der Befund, der die Regel gekostet hat — gemessen am 2026-09-06, alle drei am selben Tag:** *der
Eigentümer sah die Einstellung `read_only` **zweimal** an seinem `Integer`; die zweite Kante
`Integer --read_only--> Constants` stammte aus `package7-check.php`. Drei Knoten `__cv Zahl` aus
`converter-check.php` lagen unter demselben `Integer`. `seed-twice-check.php` hatte dreimal eine
deutsche `Adresse` gesät, weil er sie in `Address` umbenannt hatte und die Saat am Namen sucht.*

⚠️ **Und die Zahl, die zeigt, dass es kein Einzelfall war: von 73 Wächterläufen veränderten
**42** den Bestand, 17 davon an Knoten, Kanten, Sätzen, Wertzeilen oder Beschriftungen. Nach der
Klammer schreibt kein Lauf mehr ins Modell.**

⚠️ **Aufräumen am Ende bleibt richtig und wird nicht entfernt — es ist nur nicht mehr das, worauf
der Bestand sich verlässt.** *Genau daran sind alle drei Funde entstanden: ein Lauf, der in Zeile 200
rot wird, erreicht seine Zeile 400 nie, und ein `finally` läuft an einem `exit(1)` vorbei.*

## Fakes, not mocks

`tests/Core/Fake/` holds small real implementations — an array-backed repository, a counter, a
list of logged changes. They **do the thing**, so a test asserts an outcome rather than that a
method was called. Where a fake and the database could drift apart, the boundary run is what
catches it.

## The rule

⚠️ **What works keeps working: both runs are green before anything is committed**
([D-564](../docs/NewConcept/90-decision-log.md)). And **every package adds its checks to the
net** — a package whose behaviour nothing guards is a package the next one may quietly break.

⚠️ **But a check guards the *current* target state, never a past one.** *The owner added that half
himself: «klar sollen neue Entwicklungen keine alten kaputt machen aber Konzeptänderungen müssen
möglich sein und umbauen». **Without it a green check can veto a concept change** — it then guards a
target state nobody wants any more. When the concept changes, the check changes with it — **and
changing or deleting a check is a visible part of that change**, never something that happens in
passing. That second half is the counter-safety: without it «Konzeptänderung» becomes the word that
clears away any red check, and then nothing guards anything. [D-342](../docs/NewConcept/90-decision-log.md)
said only the first half and is superseded.*
