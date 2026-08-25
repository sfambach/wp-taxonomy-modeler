<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Where a set of controls submits to, and what has to travel with them.
 *
 * ⚠️ **The two things a renderer cannot invent, and only those.** A form's **URL** and its
 * **nonce** are WordPress facts (`CD-1`, and `CD-5` puts the nonce right after the capability
 * check) — so they arrive as values. **Everything else about the form the renderer builds itself.**
 *
 * ⚠️ *`hidden` is a plain name-to-value map rather than markup, for the same reason {@see Control}
 * is: the boundary states facts and the renderer decides their shape. The nonce field is one of
 * these pairs, which is why no separate slot is needed for it.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class Submission
{
    /** @param array<string, string> $hidden Fields that ride along — ids, the action, the nonce. */
    public function __construct(
        public readonly string $action,
        public readonly array $hidden = [],
    ) {
    }
}
