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
| Plugin source | `/workspace` symlink | `C:\devel\wordpress\source\wp-taxonomy-modeler` junction |
| URL | `http://localhost:8080` | `http://devel.test` |

| Role | Path |
|------|------|
| WordPress docroot | `C:\devel\wordpress` |
| GitHub source checkouts | `C:\devel\wordpress\source` |
| This repo | `C:\devel\wordpress\source\wp-taxonomy-modeler` |
| Laragon | `C:\laragon` |

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
