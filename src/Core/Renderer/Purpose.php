<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * What a rendering is *for* — and there are three.
 *
 * ⚠️ **The purpose travels in the context; the registry never keys on it** (D-217). Keying on it
 * would mean a node holds three renderers at once, one per purpose, and the owner ruled that
 * out. One lookup by type; display, edit and search are answered — or **declined** — by the same
 * renderer through {@see Renderer::supports()}.
 *
 * ⚠️ **Declining is the whole mechanism behind *not searchable*.** A backward aggregate simply
 * has a renderer that does not support `Search`, and the attribute never appears in the filter —
 * a missing capability, not a missing registration.
 *
 * ```mermaid
 * flowchart LR
 *   P[purpose] --> D["display · a value"]
 *   P --> E["edit · an input"]
 *   P --> S["search · a condition"]
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
enum Purpose: string
{
    /** Show what is there. */
    case Display = 'display';

    /** Offer a way to change it. */
    case Edit = 'edit';

    /**
     * Offer a way to look for it.
     *
     * ⚠️ A search rendering returns a **condition** — operator plus operand — not a value
     * (D-165). The filter's operator field is therefore not a component of its own: it is what a
     * text renderer looks like when its purpose is search.
     */
    case Search = 'search';
}
