<?php declare(strict_types=1);

/**
 * The rules of the design guard that the whole Taxmod code already keeps, as a failing check (D-1186, PR-9): a page
 * that asks with `window.confirm` instead of the shared dialog turns this red. A rule joins the list once its last
 * finding is fixed (docs/audit-gestaltung-2026-11-06.md).
 *
 *     php scripts/dev/design-guard-check.php   (this repository and every wp_tax_* beside it)
 */

const CLEAN_RULES = ['G1', 'G2'];

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/design-guard.php') . ' --fail --only=' . implode(',', CLEAN_RULES), $code);

exit($code);
