<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Which surface is asking — a **circumstance**, not a purpose (R15).
 *
 * ⚠️ **An option inside one renderer, never a renderer of its own.** Keeping this off the
 * registry key is what stops the renderer count from multiplying: three variants × three levels
 * × two edit modes would be eighteen classes instead of three.
 *
 * The three have different jobs (D-253): the admin says what a thing **is**, the block says what
 * this page **shows**, and the front end has nothing of its own — it draws what the block names.
 *
 * @see docs/NewConcept/30-renderer.md
 */
enum Level: string
{
    /** What a thing is — attributes, types, which renderers, defaults. Stored in our tables. */
    case Admin = 'admin';

    /** What this page shows — which node, which record, which fields. Stored in the post. */
    case Block = 'block';

    /** Nothing of its own; it draws what the block names. */
    case FrontEnd = 'front-end';
}
