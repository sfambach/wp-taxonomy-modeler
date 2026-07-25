# wp-taxonomy-tree

## Cursor Cloud specific instructions

### What this repo is

`wp-taxonomy-tree` is intended to be a **WordPress plugin** (hierarchical
taxonomy tree). As of this setup the repo is still an early scaffold: planning
docs, prototypes, and setup scripts — no plugin PHP yet, no `package.json`/
`composer.json`, no test suite, and no build step. The plugin model mirrors the
sibling repo `wp-electronic-parts`: a pure PHP plugin loaded into WordPress via
`wp-content/plugins/`.

### Local WordPress dev environment — Cloud VM (Linux)

A Docker-free WordPress dev site is set up on the VM using PHP's built-in
server + SQLite (no MySQL server needed):

- WordPress core lives in `~/wordpress` (installed via `wp-cli`).
- This repo is symlinked in as a plugin:
  `~/wordpress/wp-content/plugins/wp-taxonomy-tree -> /workspace`.
- SQLite is provided by the `sqlite-database-integration` plugin used as the
  `wp-content/db.php` drop-in, so there is no database service to start.

Start the site (leave it running):

```bash
cd ~/wordpress && wp server --host=0.0.0.0 --port=8080
```

- Front end: `http://localhost:8080`
- Admin: `http://localhost:8080/wp-admin` — user `admin`, password `admin123`.
- Handy CLI (run from `~/wordpress`): `wp plugin list`, `wp core version`,
  `wp option get siteurl`. Once plugin code exists, activate with
  `wp plugin activate wp-taxonomy-tree`.

### Local WordPress dev environment — Windows (Laragon)

Cloud agents **cannot access `C:\`**. Run the setup locally on the developer
machine.

| Role | Path |
|------|------|
| WordPress docroot | `C:\devel\wordpress` |
| GitHub source checkouts | `C:\devel\wordpress\source` |
| This repo | `C:\devel\wordpress\source\wp-taxonomy-tree` |
| Laragon | `C:\laragon` |
| Site URL (after junction) | `http://devel.test` |

**Wichtig (Windows):** `.ps1` per Doppelklick öffnet oft nur den Editor.
Stattdessen **`setup-dev.bat`** oder **`install-wordpress.bat`** doppelklicken
(siehe [`scripts/windows/README.md`](scripts/windows/README.md)).

**WordPress install only** (Laragon + MySQL, fully automated):

```powershell
cd C:\devel\wordpress\source\wp-taxonomy-tree\scripts\windows
.\install-wordpress.bat
```

Creates DB `wordpress`, downloads core to `C:\devel\wordpress`, runs
`wp core install`, site URL `http://devel.test`, admin `admin` / `admin123`.

**Full dev setup** (checkout + plugin link + Laragon junction + WP install):

```powershell
cd C:\devel\wordpress\source\wp-taxonomy-tree\scripts\windows
.\setup-dev.bat
```

In PowerShell-Terminal geht auch `-File .\setup-dev.ps1` (nicht per Explorer-Doppelklick).

**Checkout only (no Laragon / WP setup):**

```powershell
powershell -ExecutionPolicy Bypass -File C:\devel\wordpress\source\wp-taxonomy-tree\scripts\windows\checkout-only.ps1
```

**Manual git checkout (first time, before scripts exist):**

```powershell
mkdir C:\devel\wordpress\source -Force
git clone https://github.com/sfambach/wp-taxonomy-tree.git C:\devel\wordpress\source\wp-taxonomy-tree
```

Then run `setup-dev.ps1` from the cloned repo.

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
