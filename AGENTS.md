# wp-taxonomy-modeler

## Cursor Cloud specific instructions

### What this repo is

`wp-taxonomy-modeler` is a **WordPress plugin** — a taxonomy modeller.

The plugin is being built in packages; see `CLAUDE.md` for where things stand.

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
- `composer install` provides PHPUnit and the PSR-4 autoloader.

### ⚠️ Cloud sync on the source folder (Windows, 2026-08)

A two-way pCloud sync renamed and reverted files mid-session. Full trace: [`docs/bekannte-fallen.md`](docs/bekannte-fallen.md#pcloud-auf-dem-quellordner). **Verify after writing** (grep for your own content), **never delete-and-recreate an existing file**, **read `git status` before committing.**

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
   **Das Protokoll nie ganz lesen:** `php scripts/dev/decision.php D-<nnn>` gibt einen Eintrag, `--grep <Wort>` die Treffer.
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
