# ⚠️ FROZEN — the old plugin

This is the **previous, abandoned implementation** of WP Taxonomy Tree, plugin version
`0.0.563`. It was moved here on 2026-08-23 so that the repository root is free for the rebuild.

## It no longer runs

`wp-content/plugins/wp-taxonomy-tree` is a symlink to the repository root, and WordPress looks
for `wp-taxonomy-tree.php` **there**. Moving that file here takes the plugin out of the
installation — deliberately, because the new plugin needs that same slot.

**The data are untouched.** Terms, options and the custom table stay in the database exactly as
they were. What has gone is the code that read them.

To bring the old plugin back for an afternoon, move `wp-taxonomy-tree.php` and `includes/` back
to the root — and move them away again before building anything new.

## It is evidence, not a source

The whole of this code was read through on 2026-08-23 and every finding was placed in
[`../docs/NewConcept/_harvest/04-legacy-code-inspiration.md`](../docs/NewConcept/_harvest/04-legacy-code-inspiration.md):
60 rows, each carrying a verdict — **decided**, **covered**, **contradicts**, **workaround**, or
**deliberately not taken**.

**So look in that sheet first.** If something seems missing from the new concept, the sheet
already says whether it was considered and what became of it. Come back here only to read a
passage in full — never to find out *whether* something was handled.

⚠️ **[PR-1](../CLAUDE.md) and [PR-1b](../CLAUDE.md) apply:** this is quoted, never inherited. It
is never extended, never used as a template, and nothing here has authority over
[`docs/NewConcept/`](../docs/NewConcept/README.md).

## What was left behind at the root

- `scripts/` — the fixtures are cited by the harvest sheets, so the paths stay as they were.
- `node_modules/` — untracked build residue of this old code; delete it whenever you like.
