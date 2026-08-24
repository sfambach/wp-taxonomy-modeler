<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The human-readable text of an identity, in one role, one plural category and one locale.
 *
 * ⚠️ **Not the same mechanism as a software string** (AR-2). What the plugin itself says goes
 * through the WordPress text domain; what a user called their node is a label stored in the
 * model. The two never share a mechanism, and confusing them is how a translated interface ends
 * up renaming somebody's data.
 *
 * @see docs/NewConcept/40-i18n.md
 */
final class Label
{
    /**
     * @param int    $ownerId A node **or** an edge — labels hang on an identity (C8).
     * @param string $path    Which thing inside that owner, as a path of edge ids. Empty for
     *                        the owner itself; used to reach one validator of several (D-158).
     * @param int    $roleId  A role **node** (D-151), not a constant.
     * @param string $number  A plural category — `one`, `other`, and where a language needs
     *                        them `zero`, `two`, `few`, `many` (D-216). Sparsely filled.
     * @param string $locale  Empty means locale-neutral.
     */
    public function __construct(
        public readonly int $ownerId,
        public readonly string $path,
        public readonly int $roleId,
        public readonly string $number,
        public readonly string $locale,
        public readonly string $text,
    ) {
    }

    /** The base plural category, and the one every fallback lands on. */
    public const BASE_NUMBER = 'one';
}
