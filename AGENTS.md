# wp-taxonomy-tree

## Cursor Cloud specific instructions

### Current repository state (read this first)

As of this setup, the repository is an empty scaffold: the only tracked file is
`README.md` (a single-line title). There is **no** application code,
dependency manifest (`package.json` / `composer.json`), test suite, or build
config yet. Consequently there is nothing to install, lint, test, build, or run
at this time.

### Intended stack

The project name and repository conventions indicate this is intended to become
a **WordPress plugin** (`wp-taxonomy-tree`). When code is added, expect a modern
WordPress toolchain:

- PHP 8.x, OOP, following the WordPress Coding Standards.
- Gutenberg blocks authored with React/JSX using `block.json` as the single
  source of truth.
- JS/CSS builds via the `@wordpress/scripts` package (i.e. `npm run build` /
  `npm run start`), which will introduce a `package.json`.
- PHP dependencies (if any) via Composer, introducing a `composer.json`.

### Environment notes for future agents

- Node and npm are available on the VM (`node -v`, `npm -v`). PHP, Composer, and
  wp-cli are **not** preinstalled; install them if/when PHP or Composer manifests
  are added, and to run WordPress locally for end-to-end testing.
- The startup update script runs a guarded `npm install` (only when a
  `package.json` exists), so once the plugin's JS build tooling is added,
  dependencies refresh automatically. No manual action is needed for that.
- To actually run the plugin end-to-end you will need a WordPress install
  (e.g. `@wordpress/env` / `wp-env`, or a local WordPress + PHP). This is not
  set up yet because there is no plugin code to load.
