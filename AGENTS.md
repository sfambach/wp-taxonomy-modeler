# wp-taxonomy-tree

## Cursor Cloud specific instructions

### What this repo is

`wp-taxonomy-tree` is intended to be a **WordPress plugin** (hierarchical
taxonomy tree). As of this setup the repo is still an early scaffold: the only
tracked files are `README.md` and this `AGENTS.md`. There is no plugin PHP yet,
no `package.json`/`composer.json`, no test suite, and no build step. The plugin
model mirrors the sibling repo `wp-electronic-parts`: a pure PHP plugin that
is loaded into a WordPress install by dropping/symlinking the repo folder into
`wp-content/plugins/`.

### Local WordPress dev environment (how to run)

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

### Recreating the environment (only if `~/wordpress` is missing)

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
