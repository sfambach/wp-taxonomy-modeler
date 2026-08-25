<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * One block of a node's page: a heading and what is under it.
 *
 * ⚠️ **The title arrives translated, the shape is the renderer's** — the same division as
 * {@see Control}. A heading is a software string and belongs to the text domain (`AR-2`), which the
 * core may not reach for (`CD-1`); *how* a heading looks on a page is not a translation question.
 *
 * ⚠️ **`body` is finished markup**, because a section holds whatever the surface put there — a form,
 * a table of rendered fields, a chooser. *The renderer frames and orders; it does not reach inside.*
 * Which slot a section occupies, and therefore where it lands, is {@see PageSlot} — decided once, in
 * [R20a](../../../docs/NewConcept/30-renderer.md#r20a--the-detail-view-is-not-a-special-screen).
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class Section
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly bool $collapsed = false,
    ) {
    }
}
