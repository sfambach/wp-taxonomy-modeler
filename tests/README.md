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
| `package3` | ⚠️ *`package7` ist am 2026-09-10 in `einstellungen` aufgegangen ([D-706](../docs/NewConcept/90-decision-log.md)); seine 128 Zusagen stehen in [`waechter-bestand.md`](../docs/pakete/modelltabellen/waechter-bestand.md).* ⚠️ *Und seit dem 2026-09-06 steht `pakete` daneben — **ein** Lauf, der den Weg als Ganzes geht, statt vier wiederbelebter.* ⚠️ **Es waren einmal sechs, einer je Paket** ([97 Implementation plan](../docs/NewConcept/97-implementation-plan.md)). *`package1`, `package2`, `package5` und `package6` sind am 2026-09-06 gestrichen — ihre Aussagen stehen verteilt in `shadow-shape`, `label-space`, `id-space`, `path`, `sort-order`, `collapsed-default`, `restore`, `parked-in-shadow`, `record-on-first-write`, `several-values`, `edge-class` und im Kernlauf. **Geblieben sind die zwei mit belegtem Fund.** Was das gekostet hat, steht in [`waechter-bestand.md`](../docs/pakete/modelltabellen/waechter-bestand.md) — auf sein Wort «checks mein ja», und mit seinem Grund: «weniger check dafür aber richtige».* |
| `einstellungen` | **ein Weg durch die Einstellungen, wie ein Mensch ihn geht** ([D-706](../docs/NewConcept/90-decision-log.md), TASK-084; **seit 2026-09-11 auf dem neuen Einstellungsmodell**, [D-712](../docs/NewConcept/90-decision-log.md), TASK-096) — *das Gerüst (Renderer-Kode, die Wurzelkanten, der Bestand der Wahlen in `settings_value`), eine eigene Wiese `__es`; der Vertrag erklärt (die Attribute aus der Klasse unter `taxmod_setting[<attribut>]`, Vorgaben ohne Zeile, Unpassendes abgewiesen, kein Erben vom Vater), wählen (der Renderer aus dem Vertrag als Einstellungsobjekt; zweimal dieselbe Wahl ein Objekt, die zweite ersetzt; jede Wahl zeichnet; jede Verwendung sieht sie, das Kind nicht), überschreiben an der Kante (geerbt gesperrt mit Haken und Herkunft; mit Haken eine Zeile mit Kante; der Knoten bleibt; Renderer an der Kante schaltet das geerbte Glied ab; ein Wert im geerbten Objekt), der Konflikt (unzulässige Wahl zeichnet den Typ-Standard; keine Kette zur Wurzel), der Einstellungsbereich der Feldzeile (aufklappen, setzen an der Kante, nur was der Vertrag des Ziels oder seines Renderers erklärt, «wie oft» geerbt gesperrt, `read_only` als Spalte), Werte und Steuerelemente (hinein als ihr Typ, zurück unverändert, feste Abfragezahl), Konverter (an der Kante mit Haken, am Knoten für alle Verwendungen), die Vorschau, die Kantenart mit Warnung, Datensätze, die Seite selbst (Baum, Detail, Stylesheet, `table` mit `orientation` aus dem Vertrag), Umbenennen. **Ersetzt elf Wächter** — wohin jede Zusage ging, steht in [`waechter-bestand.md`](../docs/pakete/modelltabellen/waechter-bestand.md).* |
| `pakete` | **der Weg von aussen, wie ein Mensch ihn geht** — anlegen, benennen, beschriften, ein Feld daran, ein Datensatz, ein Wert hinein und wieder heraus, umbenennen, verschieben, ordnen, parken, wiederherstellen, löschen, und nach jedem Schritt: steht, was stehen soll, und ist nichts liegengeblieben? ⚠️ *Er ist der **Ersatz für die vier gestrichenen Paketläufe** — nicht ihre Wiederbelebung. `package1/2/5/6` kosteten 127 Zusagen; was mit ihnen verlorenging, war nicht eine einzelne Aussage, sondern **die Stelle, an der ein Paket als Ganzes noch einmal durchgespielt wird** ([`waechter-bestand.md`](../docs/pakete/modelltabellen/waechter-bestand.md)). Er misst am **Ergebnis** und baut sich seine eigene Wiese, Präfix `__pk`.* |
| `scaffold` | the seeded data types and framework nodes |
| `simple-type` | **das Inventar der einfachen Typen** ([D-484](../docs/NewConcept/90-decision-log.md)) — Klassen, Aufzählungsfälle und gesäte Knoten sind **eine** Zahl, jeder Typknoten trägt seine Klasse, keine der elf Optionen ist zurück, und die Validatoren lesen ab, was der Typ kann |
| `unitvalue` | a value with a prefix and a unit — `2.7 kΩ` stored and read back ([D-394](../docs/NewConcept/90-decision-log.md)) |
| `settings-screen` | the installation screen, and that its two sizes reach the stylesheet ([D-397](../docs/NewConcept/90-decision-log.md)) |
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
| `configuration-screen` | **die Konfigurationsseite in ihrer Form**, nicht in ihrem Zustand ([D-695](../docs/NewConcept/90-decision-log.md)): drei Spalten, beide Optionen als Schiebeschalter mit ihrer verborgenen Null, je Zeile eine mittlere Zelle und eine Erklärung rechts. ⚠️ *Diesen Bildschirm zeichnete bis zum 2026-09-09 **kein einziger** Wächter, obwohl fünf Entscheidungen auf ihm liegen.* ⚠️ *Und die Gegenprobe gehört dazu: **hier darf kein Fragezeichen mehr stehen** — die Untergrenze, die `page-blocks` dafür hielt, ist am selben Tag sichtbar dorthin umgezogen und umgedreht worden, weil [D-695](../docs/NewConcept/90-decision-log.md) [D-661](../docs/NewConcept/90-decision-log.md) für diese Seite eingegrenzt hat* |
| `no-model-write` | dass **kein Wächter in das Modell des Eigentümers schreibt** — jeder Lauf mit `wp-load` trägt unmittelbar danach die Klammer aus [`lib/no-write.php`](../scripts/dev/lib/no-write.php), keiner beginnt eine eigene Umklammerung, und jede Ausnahme steht mit Grund. *Siehe die Regel am Fuss dieser Datei* |
| `record-kind` | **vier Satzarten, und die Grenzen kommen aus dem Vertrag des Typs** ([D-704](../docs/NewConcept/90-decision-log.md), [D-712](../docs/NewConcept/90-decision-log.md), TASK-083; Schritt 7 des [Bauplans](../docs/einstellungen-bauplan.md)) — *am Bestand: ein `settings`-Satz trägt nichts anderes, der Satz einer Verwendungsstelle ist `settings`, einer je Adresse; der Vertrag von Integer erklärt min, max, step, die Grenzknoten `integer_min` … `decimal_max` sind gefallen, `Integer` trägt keinen Einstellungssatz mehr. Auf der Wiese (`__rk`): ein frischer Knoten hat keinen Satz.* |
| `field-order` | **ein Kind ordnet geerbte Felder an — an derselben Adresse wie seine anderen Einstellungen** ([D-698](../docs/NewConcept/90-decision-log.md), TASK-087) — *Fassung 45 erklärt an der Wurzel die Einstellung `position` (`0..1` auf `Integer`); an einem Kind hat jede Feldzeile die zwei Knöpfe, die Enden ausgegraut; ein Schritt schreibt die Positionen der ganzen Liste in die Sätze `Kind × Kante` (`settings`); der Besitzer und sein `sort_order` bleiben; der Enkel erbt die Anordnung, sein eigenes Feld kommt danach, und er darf wieder umstellen, ohne das Kind zu ändern; gilt eine Anordnung, geht auch die eigene Zeile über sie; beim Besitzer ohne Anordnung bleibt D-435. Präfix `__fo`* |
| `move-under-primitives` | **Verschieben unter `Primitives` nimmt die Sätze mit** ([D-701](../docs/NewConcept/90-decision-log.md), TASK-082) — *Einträge mit Verwender bleiben Teile; Einträge ohne Verwender werden gezählt und gezeigt, der Umzug wartet auf den Knopf «ich bestätige», und erst dann gehen sie in den Schatten und der Knoten zieht um. Ein Umzug innerhalb von `Model` fragt nicht. Präfix `__mp`* |
| `edge-class` | **drei Werte, drei Klassen** ([D-639](../docs/NewConcept/90-decision-log.md), TASK-032) — jede lebende Kante trägt einen der drei, die Ableitung Wert → Klasse ist vollständig und eindeutig, eine **geladene** Kante kommt als ihre Klasse an, und **kein Datensatz hängt an zwei Besitzern**. *Die Aussage gilt den Daten, nicht dem Modell: ein Kompositionsziel **darf** geteilt sein — 36 von 42 zeigen auf Typknoten, und der Wächter, der das verboten hätte, hätte 36 richtige Zeilen berichtigt. Den Verstoss legt er sich selbst an (Präfix `__`) und räumt ihn weg* |
| `klasse` | **jeder Knoten trägt eine Klasse, und sie kommt beim Anlegen vom Vater** ([D-716](../docs/NewConcept/90-decision-log.md), [D-719](../docs/NewConcept/90-decision-log.md), [D-723](../docs/NewConcept/90-decision-log.md), TASK-092 — Schritt 1 des [Bauplans](../docs/einstellungen-bauplan.md)) — *die Spalte lebend, im Schatten und in der Sicht; jeder Knoten eine Klasse aus dem Inventar; die ausgelieferten Knoten nach K3 (Root, Model, Constants Kategorie; die elf Typknoten ihre Typklasse; Prefixes, Base units, Label roles Auswahl; kilo und die Rollen Konstante; Gramm, Celsius Einheitswert; Einheitenwert); auf der Wiese `__kl`: die Vorwahl des Vaters, eine erlaubte Klasse, eine abgewiesene, Umbenennen und Kopie lassen sie stehen; der Baum schreibt sie an, zeichnet ihr Icon und bietet sie beim Anlegen vorgewählt an* |
| `settings-tables` | **die zwei Tabellen des Einstellungsmodells stehen — leer, mit Schatten, mit Fremdschlüsseln** ([D-712](../docs/NewConcept/90-decision-log.md), [D-717](../docs/NewConcept/90-decision-log.md), TASK-096 a — Schritt 3 des [Bauplans](../docs/einstellungen-bauplan.md)) — *Form von `settings_object` und `settings_value` samt Schatten; fünf Fremdschlüssel; nichts hineingewandert; auf der Wiese `__st`: Objekt und Zeilen jeder Wertsorte, die Kante als Zusatz, lesen nach Träger, ändern hebt auf, wandern ist wandern, ein Objekt nimmt seine Zeilen mit, ein Verweis ins Leere wird abgewiesen, die vier Zusagen der Zeile* |
| `kantenspalten` | **Multiplizität und `read_only` sind Spalten der Kante, keine Einstellungen** ([D-713](../docs/NewConcept/90-decision-log.md), [D-714](../docs/NewConcept/90-decision-log.md), TASK-094/095 — Schritt 2 des [Bauplans](../docs/einstellungen-bauplan.md)) — *die Spalte lebend und im Schatten; kein Attribut im Vertrag, keine lebende Einstellungskante, kein Satz dafür; auf der Wiese `__ks`: der Dienst schreibt die Spalte, die Feldzeile zeichnet sie als Schalter und schickt sie zurück, ein nur lesbares Feld bietet kein Eingabefeld, die Kopie trägt sie mit; Bool nur `1..1` — der Wähler bietet nur das, der Kern weist `0..*` ab, Integer bekommt alle vier* |
| `record-pages` | **die Datensätze eines Knotens in Seiten zu fünf** ([D-763](../docs/NewConcept/90-decision-log.md)) — *fünf Sätze ohne Leiste; ab sechs Seite 1 mit den ersten fünf und einer Leiste; Seite 2, die letzte, `last`, eine Zahl hinter dem Ende und Unsinn in der Adresse; **die Abfragezahl bei sieben und zwölf Sätzen gleich**; die Seitenzahl in den Links der Leiste, nicht in Links auf andere Knoten; «New record» führt auf die letzte Seite, Speichern bleibt auf seiner. **Seit [D-764](../docs/NewConcept/90-decision-log.md):** Einzelabfragen nach Knoten wachsen nicht mit den gezeigten Sätzen (Sätze mit Einheitswert-Teilen, 5 gegen 10). **Seit [D-768](../docs/NewConcept/90-decision-log.md):** der Filter — Filtern trägt ihn in die Adresse und beginnt bei Seite 1, nur passende Sätze stehen da, die Filterzeile trägt die Felder des Satzes, «3 of 13», Zurücksetzen und Speichern unter Filter. **Seit [D-769](../docs/NewConcept/90-decision-log.md):** der Sprung — der Typknoten ist gesät, jeder gezeigte Satz trägt einen Link (die Filterzeile nicht), er führt zum Ziel mit Filter und ohne Seitenzahl, speichert nichts, und am Ziel steht nur der Satz mit «von = dieser Satz». Präfix `__rp`* |
| `seitenlast` | **die Last der Knotenseite** ([D-814](../docs/NewConcept/90-decision-log.md), [D-815](../docs/NewConcept/90-decision-log.md), [D-818](../docs/NewConcept/90-decision-log.md)) — *auf den fünf Bezugsseiten höchstens 150 Abfragen und 1 MB Markup, keine Abfrage öfter als 20-mal (eine Schleife je Knoten wird rot, bevor die Summe es wird), und der gemeinsame Auswahlbaum steht genau einmal da. Er schreibt die gemessenen Zahlen mit. Gemessen am 2026-09-14 lag die schwerste Seite bei 988 Abfragen und 2,4 MB.* |
| `ueberschreiben` | **eine Liste an der Kante: schalten und ordnen, das geerbte Glied bleibt** ([D-712](../docs/NewConcept/90-decision-log.md), TASK-096 d) — *am Knoten ein Glied aus und wieder an, die Zeile bleibt; an der Kante das eigene Glied vor dem geerbten, umordnen, das geerbte wieder an — gezeichnet wird das erste aktive Glied; die Kante gilt über dem Knoten; die Zeile eines fremden Knotens wird abgewiesen.* |
| `backup` | **die Sicherung trägt den ganzen Stand** ([D-908](../docs/NewConcept/90-decision-log.md)) — *je Tabelle dieselbe Zahl im Manifest, in der Datei und in der Datenbank, eine Bauanleitung ohne Präfix der Website, jede verwiesene Mediathek-Datei mit ihrem Fingerabdruck oder als fehlend gemeldet, keine Betreiberoption; und abgewiesen, bevor etwas fällt: eine neuere Schemafassung, eine fremde Bauanleitung, ein Tabellenname mit Sonderzeichen. **Eingespielt wird hier nicht** — das wirft Tabellen weg; der Rundweg ist von Hand gemessen.* |
| `ancestry-depth` | **der Baum bleibt flacher als die Kette, mit der der Vorfahrenweg ohne `WITH RECURSIVE` gerechnet wird** ([D-910](../docs/NewConcept/90-decision-log.md)) — *die tiefste Ebene, in PHP aus `parent_node_id` gezählt, liegt mindestens vier Ebenen unter der Grenze; ein tieferer Knoten verschwände still aus jedem Leser.* |
| `abilities` | **das Modell über die Abilities lesen und ändern** ([D-911](../docs/NewConcept/90-decision-log.md)) — *registriert und öffentlich, ohne Fähigkeit abgewiesen; eine Liste legt Knoten, Kind, Feld, Satz und Wert an und `read-node` liest sie zurück, der Wert unverändert; die Liste hat eine Änderungsnummer; scheitert ein Schritt, bleibt nichts.* |

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
