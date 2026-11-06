# Bekannte Fallen

**Keine Regeln — Techniken.** Das Arbeitsmodell sagt es selbst
([`arbeitsmodell.md`](arbeitsmodell.md) §12.1): *«Implementierungsdetails sollen nicht unnötig zu
globalen Regeln werden.»*

Jede Falle hier hat mindestens einen Abend gekostet. Sie stehen nicht im Regelsatz, weil sie nichts
über das Modell sagen und kein Konzept verbauen können — sie sagen nur, wo das Werkzeug beisst.

⚠️ *`PR-11` war bis 2026-09-01 eine eigene Regel und ist hierher verschoben. Der Inhalt ist
unverändert; nur der Anspruch, eine globale Regel zu sein, entfällt.*

---

## `preg_replace` gibt `null` zurück, und `null` landet auf der Platte

**Kosten: eine Quelldatei, auf null Byte geleert, vor dem ersten Commit.**

Am 2026-08-25 scheiterte ein `preg_replace` an einer unmaskierten Klammer, gab `null` zurück, und
`file_put_contents($f, null)` leerte `AttributeRenderer.php` vollständig. **`php -l` meldete nichts —
eine leere Datei hat keine Syntaxfehler.**

**Was hilft:** vor dem Schreiben prüfen, dass die Ersetzung überhaupt gegriffen hat
(`if (! str_contains($s, $alt)) { exit(1); }`), und niemals einen möglicherweise leeren Wert an
`file_put_contents` geben. Für Quelltext die Editierwerkzeuge nehmen statt regulärer Ausdrücke.

---

## `sed` frisst PHP-Namensräume

**Kosten: zweimal in einer Sitzung — einmal die PSR-4-Zuordnung in `composer.json`, einmal die
Importe in `NodesScreen.php`.**

GNU sed liest `\C`, `\w`, `\M` als Escape-Sequenzen. Die Backslashes verschwinden aus Muster **und**
Ersetzung: `use Taxmod\Core\Model\Branch;` wird zu `use TaxmodCoreModelBranch;`.

**Der Schaden ist still.** `php -l` läuft durch, weil `use TaxmodCoreModelBranch;` gültiges PHP ist —
es scheitert erst zur Laufzeit, wenn die Klasse nicht gefunden wird.

**Was hilft:** für jede Ersetzung mit einem Backslash — PHP-Namensräume, Windows-Pfade — das
Editierwerkzeug. In `sed` den Trenner mit `.` treffen und als `\x5c` zurückschreiben. Nach jedem
`sed` auf eine PHP-Datei `git diff --stat` ansehen.

---

## `$wpdb` schweigt bei einer kaputten Abfrage

**Kosten: eine falsche Messung, die als Befund in einen Commit, eine offene Frage und eine
Arbeitszeile wanderte.**

Am 2026-08-26 wurde gemeldet, es gebe **null** `hide`-Zeilen in der Datenbank, das Auge im Baum sei
also nie benutzt worden. Die Abfrage wählte `value_bool` — eine Spalte, die es nicht gibt.
**`$wpdb` beantwortete die kaputte Abfrage mit einem leeren Feld statt mit einem Fehler**, und der
Irrtum kam in der Autorität einer Messung an. Die Wahrheit war das Gegenteil: sechs Präfixe waren
verborgen. Dieselbe Falle zehn Minuten später noch einmal, weil `WHERE kind = 'attribute'` nichts
lieferte — die Arten heissen `inheritance`, `composition` und `aggregation`.

**Was hilft:** vor einer Abfrage mit geratener Spalte oder geratenem Wert `SHOW COLUMNS` oder ein
`GROUP BY` laufen lassen. Danach `if ($wpdb->last_error !== '')` prüfen und laut aussteigen.
**Ein leeres Ergebnis und eine gescheiterte Abfrage sehen gleich aus und bedeuten das Gegenteil.**
Wo möglich die Repositories nehmen — eine typisierte Methode kann keine Spalte benennen, die es
nicht gibt.

---

## `datetime` gegen `'0'` vergleichen meldet jede Zeitspalte als leer

**Kosten: ein Befund im Tabellen-Review, der falsch war und im selben Lauf korrigiert werden musste.**

Am 2026-09-01 meldete eine Spaltenmessung `changelog.at`, `records.created_at` und alle vier
`archived_at` als «nie gefüllt». Der Test lautete `<> '' AND <> '0'` — **MySQL wandelt für den
Vergleich um, und jedes Datum wird dabei gleich `'0'`.** Nachgemessen war keine einzige Spalte leer.

**Was hilft:** Zeitspalten mit `IS NOT NULL` prüfen und das Nulldatum `'0000-00-00 00:00:00'`
getrennt zählen. Und der allgemeine Teil: **ein Test, der für einen Spaltentyp gebaut ist, gilt nicht
für alle** — die Zahl «verschiedene Werte» hätte den Widerspruch sofort gezeigt und tat es auch,
sobald jemand hinsah.

---

## `git checkout <datei>` wirft nicht committete Arbeit weg

**Kosten: eine halbe Stunde Umbau an `CLAUDE.md`, am 2026-09-01, durch mich selbst.**

Ein Wächter sollte gegengeprüft werden: eine Datei künstlich aufblähen, sehen, ob er rot wird, dann
zurücksetzen. Das Zurücksetzen war `git checkout CLAUDE.md` — **und die Datei enthielt eine noch
nicht committete Änderung.** Der Wächter hatte richtig gemeldet, die Gegenprobe war richtig gedacht,
und trotzdem war die Arbeit weg.

**Was hilft:** vor einer Gegenprobe, die eine Datei verändert, eine Kopie in den Arbeitsordner legen
und **daraus** zurückstellen, nicht aus git. Oder die Gegenprobe an einer Wegwerfdatei führen statt
an der echten.

⚠️ *Das allgemeine Muster: **ein Rückgängig, das weiter reicht als die Änderung.** `git checkout`
kennt nur den letzten Commit, nicht die letzte Absicht.*

---

## pCloud auf dem Quellordner

*Aus `AGENTS.md` hierher verschoben am 2026-11-06 ([D-1210](NewConcept/90-decision-log.md)): es betrifft nur die frühere Windows-Maschine, und `AGENTS.md` wird vor jeder Aufgabe gelesen. Wortlaut unverändert.*

#### ⚠️ Watch out: a cloud client on the source folder

**2026-08-24 — a file vanished mid-session.** `src/WordPress/Admin/NodesScreen.php` was rewritten,
and moments later it lay on disk as `NodesScreen [conflicted].php`. The class stopped loading, the
admin screen died with a fatal error, and a `git add -A` recorded the **deletion** in a commit.

**`[conflicted]` is pCloud's naming**, `pCloud.exe` was running, and pCloud's own database lists
`C:\Devel`. The owner has since excluded the source folder. ⚠️ *He also notes he only backs up
and never restores from the cloud — which makes the mechanism less obvious, because a one-way
backup should not rename a local file. What is certain is that the rename happened and that no
other candidate uses that naming.*

#### ⚠️ 2026-08-25 — it happened again, and the mechanism is now known

**Twice in one session.** `docs/NewConcept/95-roadmap.md` was written, reported as written, and then
**reverted** — the two most recent additions simply gone, lying beside it as
`95-roadmap (conflicted).md`. Restored, and minutes later the same file was **replaced outright** by
`95-roadmap [conflicted].md`. Both naming styles, round brackets and square, in one session.

**The cause was traced rather than guessed, and the exclusion was never the problem.** Read out of
`%LOCALAPPDATA%\pCloud\data.db`:

| What the database says | |
|---|---|
| `syncfolder` | `localpath=C:\Devel`, `synctype=7` — a **sync pair**, and a two-way one |
| `setting.ignorepaths` | contains `C:\Devel\Wordpress\source;` — ⚠️ **the owner's exclusion is there and is correct** |
| `localfile` | holds `95-roadmap.md`, `90-decision-log.md` and `NodesScreen.php` **twice each** — once under `Wordpress/source/…` and once under `Wordpress/wp-content/plugins/…` |

⚠️ **The exclusion cannot work here, and pCloud says so itself.** Its own
*Backup/Sync Exclusions* dialog carries the sentence *"Items that are already part of a backup or
sync will not be affected."* **`C:\Devel` is already a sync pair**, so adding
`C:\Devel\Wordpress\source` to the list changes nothing about it: the list keeps **new** items out
and removes nothing from an existing sync. **The owner did the right thing in the wrong place, and
the wrongness is not discoverable from the list — which shows the path and looks like it is in
force.**

⚠️ **A second door is open as well, and it would survive a fix to the first.**
`wp-content/plugins/wp-taxonomy-tree` is an **NTFS junction** pointing at
`source/wp-taxonomy-tree`. pCloud's index holds `95-roadmap.md`, `90-decision-log.md` and
`NodesScreen.php` **twice each** — once under `Wordpress/source/…`, once under
`Wordpress/wp-content/plugins/…` — so the same bytes are reachable by a path the exclusion never
names. **Three more junctions in that folder have the same exposure**: `budget-translator`,
`wp-auto-correction`, `wp-changelog`.

**Everything that looked inexplicable falls out of those two:** why excluding `source` changed
nothing, and why *a one-way backup should not rename a local file* — it is not a backup, it is a
**two-way sync pair** (`synctype=7`).

**So the fix is the sync pair, not another exclusion.** Remove or narrow the `C:\Devel` sync;
excluding `wp-content\plugins` as well only closes the junction door and leaves the first one
open. ⚠️ *And the general lesson outlives pCloud: an exclusion list that cannot reach into an
existing sync is a setting that reads as protection and is not one.*

**And the rule that follows is the expensive one, which stands whatever the cause: verify after
writing.** Every documentation edit in that session was re-checked with a `grep` for its own content
before committing, and that is the only reason the loss was caught at all. ⚠️ *Two additions were
already gone by the time the check ran.* A fatal error announces itself; a reverted paragraph does
not.

**Two rules that follow, and they are cheap:**

- **Never delete-and-recreate a file that already exists** — overwrite it. The rapid
  delete/create pair is what looked like a conflict.
- **Read `git status` before committing.** `git add -A` turns a file somebody else renamed into
  a committed deletion, and nothing warns you.

⚠️ **Whether this also hurt the previous round is unknown and probably unknowable.** The history
was searched: no `[conflicted]` file was ever committed, and the deleted-then-re-added paths are
the deliberate rules reorganisation. **But git only sees commits** — a file broken and repaired
before the next one leaves no trace, and that is exactly what *the assistant is hallucinating and
we keep going in circles* would feel like from the outside. Recorded as a possibility, not a
finding.
