# wp-taxonomy-modeler

## Cursor Cloud specific instructions

### What this repo is

`wp-taxonomy-modeler` is a **WordPress plugin** — a taxonomy modeller.

⚠️ **Nothing runs right now, and that is deliberate.** The project restarted its concept
phase; the old plugin was mothballed on 2026-08-23 into [`legacy-code/`](legacy-code/README.md)
and no longer loads. There is no `package.json` at the root and no build. **No production code
until [`10-domain-core.md`](docs/NewConcept/10-domain-core.md) is `locked`** — see `PR-2` in
[`CLAUDE.md`](CLAUDE.md).

The environment notes below describe the layout that **will** be used again once building
starts. They are kept, not current.

### Local WordPress dev environment — Cloud VM (Linux)

A Docker-free WordPress dev site is set up on the VM using PHP's built-in
server + SQLite (no MySQL server needed):

- WordPress core lives in `~/wordpress` (installed via `wp-cli`).
- This repo is symlinked in as a plugin:
  `~/wordpress/wp-content/plugins/wp-taxonomy-modeler -> /workspace`.
- SQLite is provided by the `sqlite-database-integration` plugin used as the
  `wp-content/db.php` drop-in, so there is no database service to start.

Start the site (leave it running):

```bash
cd ~/wordpress && wp server --host=0.0.0.0 --port=8080
```

- Front end: `http://localhost:8080`
- Admin: `http://localhost:8080/wp-admin` — user `admin`, password `admin123`.
- Handy CLI (run from `~/wordpress`): `wp plugin list`, `wp core version`,
  `wp option get siteurl`. Activate with
  `wp plugin activate wp-taxonomy-modeler`.

### Local WordPress dev environment — Windows (Laragon)

Cloud agents **cannot access `C:\` or start Laragon on your PC**. They only
control the Linux VM (`~/wordpress`, `/workspace`). The Windows scripts mirror
that layout locally — you run them on your machine.

| | Cloud VM (agent) | Your Windows PC |
|---|---|---|
| WordPress | `~/wordpress` + SQLite | `C:\devel\wordpress` + Laragon MySQL |
| Plugin source | `/workspace` symlink | `C:\devel\wordpress\source\wp-taxonomy-tree` junction |
| URL | `http://localhost:8080` | `http://devel.test` |

| Role | Path |
|------|------|
| WordPress docroot | `C:\devel\wordpress` |
| GitHub source checkouts | `C:\devel\wordpress\source` |
| This repo | `C:\devel\wordpress\source\wp-taxonomy-tree` |
| Laragon | `C:\laragon` |


⚠️ **The local folder is `wp-taxonomy-tree`, the repository is `wp-taxonomy-modeler`.** The
repository was renamed on 2026-08-24 ([D-336](docs/NewConcept/90-decision-log.md)); the folder
deliberately was **not**. It is cosmetic — WordPress takes the plugin slug from the symlink name
in `wp-content/plugins/`, not from the source folder — and renaming it meant closing the editor
for nothing. ⚠️ **So a fresh `git clone` produces `wp-taxonomy-modeler` and this machine has
`wp-taxonomy-tree`. Both are the same repository.**
**Start setup:** double-click **`scripts/windows/setup-dev.bat`** (not `.ps1`).

`setup-dev.bat` does **not** run `git pull` anymore (that broke `scripts\windows`
on Windows). It only clones if the repo is missing. Use **`recover-repo.bat`**
when you explicitly want to update from GitHub.

See [`scripts/windows/README.md`](scripts/windows/README.md).

### Recreating the Cloud VM environment (only if `~/wordpress` is missing)

System deps (`php-cli` + extensions, `wp-cli`) and the WordPress core install
are one-time setup captured in the VM snapshot, so they are intentionally NOT
in the startup update script. If `~/wordpress` is absent on a fresh VM, recreate
it: install PHP 8.x CLI with the `sqlite3`, `curl`, `gd`, `mbstring`, `xml`,
`zip`, `intl` extensions and `wp-cli`; run `wp core download`,
`wp config create --dbname=wordpress --dbuser=root --skip-check --force`, drop
in the SQLite integration (`sqlite-database-integration` plugin → copy its
`db.copy` to `wp-content/db.php`), then `wp core install` and symlink
`/workspace` into `wp-content/plugins/`.

### Notes for future JS/PHP tooling

- The startup update script runs a guarded `npm install` (only when a
  `package.json` exists). Modern Gutenberg blocks are expected to use
  `@wordpress/scripts` (`npm run build` / `npm run start`), which will add a
  `package.json` and make that install meaningful automatically.
- If a `composer.json` is added later, install Composer and run
  `composer install`; it is not preinstalled.

### ⚠️ Watch out: a cloud client on the source folder

**2026-08-24 — a file vanished mid-session.** `src/WordPress/Admin/NodesScreen.php` was rewritten,
and moments later it lay on disk as `NodesScreen [conflicted].php`. The class stopped loading, the
admin screen died with a fatal error, and a `git add -A` recorded the **deletion** in a commit.

**`[conflicted]` is pCloud's naming**, `pCloud.exe` was running, and pCloud's own database lists
`C:\Devel`. The owner has since excluded the source folder. ⚠️ *He also notes he only backs up
and never restores from the cloud — which makes the mechanism less obvious, because a one-way
backup should not rename a local file. What is certain is that the rename happened and that no
other candidate uses that naming.*

### ⚠️ 2026-08-25 — it happened again, and the mechanism is now known

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

### ⚠️ 2026-09-05 — mehrere Agenten in einem Arbeitsbaum

**Fremde Änderungen mit einzuchecken ist nicht das Problem. Sie ungesehen einzuchecken schon.**
Auf sein Wort: *«einchecken ist nicht schlimm, es müssen nur die Regeln beachtet werden —
Änderungen vergleichen und dann einchecken, damit nicht überbügelt wird.»*

Also, vor jedem Commit, ohne Ausnahme:

1. **Ansehen, was im Baum liegt** — `git status` und `git diff` über *alles*, nicht nur über die
   eigenen Dateien. Wer nur seine eigenen kennt, weiss nicht, was er mitnimmt.
2. **Vergleichen statt überschreiben.** Eine fremde Änderung an derselben Stelle wird gelesen und
   zusammengeführt. Sie wegzunehmen, weil sie im Weg steht, ist der Schaden, den diese Regel
   verhindert.
3. **Kein `git stash`**, kein `git checkout`/`reset` auf fremde Dateien. *Ein Stash hat den
   gemeinsamen Baum schon einmal verschluckt.*
4. **Die Nachricht sagt, was drin ist.** Nimmt ein Commit fremde Arbeit mit, steht das darin — sonst
   erzählt die Geschichte es falsch, und das ist der einzige bleibende Schaden.

**Und der Notnagel ist nicht die Lösung.** *Git ist nie für nebenläufige Arbeit in **einem** Baum
gebaut worden; es löst sie über **getrennte Bäume**. Die vier Punkte oben sind Disziplin an einer
Stelle, an der Werkzeug gehörte.* Wo mehrere Agenten gleichzeitig bauen, ist der saubere Weg **ein
eigener Arbeitsbaum je Agent** (`git worktree`), zusammengeführt am Ende. Die Regeln oben gelten
für den Fall, dass es dennoch ein gemeinsamer Baum ist — **nicht als Ersatz für den getrennten.**

### ⚠️ 2026-09-06 — wo die Zeit verlorengeht

**Gemessen, auf seinen Hinweis «der Agent laeuft schon wieder fuenf Minuten fuer einen kleinen
Schritt»:**

| was ein Agent liest oder tut | Kosten |
|---|---|
| `docs/NewConcept/90-decision-log.md` | **1052 KB** |
| `src/WordPress/Admin/NodesScreen.php` | 232 KB |
| `src/Core/Service/Rendering.php` | 171 KB |
| ein **voller** Waechterlauf | ~60 s, und er wird oft mehrfach gefahren |

**Drei Regeln folgen daraus. Sie gelten fuer jeden Auftrag an einen Agenten:**

1. **Zuerst das Verzeichnis, nicht das Protokoll.**
   [`03-entscheidungsverzeichnis.md`](docs/NewConcept/03-entscheidungsverzeichnis.md) sagt auf 150
   Zeilen, **was gilt** — das Protokoll sagt auf 1 MB, was je entschieden wurde. *Der Wortlaut einer
   Entscheidung wird nur dort verlangt, wo es auf ihn ankommt (`PR-10`), und dann mit Nummer.*
2. **Nur die berührten Waechter, der volle Lauf zum Schluss.**
   Vor jedem Commit: Kernlauf (0,5 s) **und** die Waechter, die die geaenderte Stelle betreffen.
   Der volle Randlauf gehoert **an das Ende des Auftrags** und vor jede Wanderung an den Daten —
   nicht nach jedem Zwischenschritt. *Wird einer rot, laeuft sofort der volle Lauf: ein roter
   Waechter kommt selten allein.*
3. **Grosse Dateien werden geteilt, bevor drei Agenten sie gleichzeitig brauchen.**
   `NodesScreen.php` (63 Methoden) und `Rendering.php` (56) sind die zwei, an denen gestern drei
   Baustellen kollidierten. **Eine Datei, die drei Auftraege gleichzeitig anfasst, ist zu gross** —
   das ist die Regel, nicht die Zeilenzahl.

⚠️ **Und nie `git commit --amend` in einem gemeinsamen Baum.** *Am 2026-09-06 hat ein Agent seine
eigene Commit-Nachricht nachgebessert, waehrend ein zweiter committete — `--amend` greift auf
**HEAD**, und HEAD gehoerte in dem Moment dem anderen. **Zweimal hintereinander wurde so ein fremder
Commit umgeschrieben:** einer verlor seine `Co-Authored-By`-Zeile, einer traegt seither die Nachricht
eines anderen. Kein Inhalt ging verloren, die Geschichte erzaehlt es aber falsch, und repariert wird
es nicht, weil darauf schon weitergebaut wurde. **Eine unschoene Nachricht ist billiger als eine
umgeschriebene Geschichte.**
