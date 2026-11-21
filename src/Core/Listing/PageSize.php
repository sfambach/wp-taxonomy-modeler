<?php declare(strict_types=1);

namespace Taxmod\Core\Listing;

/**
 * How many entries one page of a list shows: the choice on the page beats the user's setting, which beats the site's setting.
 * A value that is none of the offered sizes counts as not set, so the next level speaks.
 *
 * ```mermaid
 * flowchart LR
 *   P["choice on the page"] -->|not set| U["user setting"] -->|not set| S["site setting"] -->|not set| D["DEFAULT"]
 * ```
 *
 * His word 2026-11-21 06:51: «eine einstellung auf der seite bestimmt wieviele items pro seite … in den einstellungen und
 * benutzer einstellungen können ein wert vorgegeben werden benutzer einstellunge schlägt einstellung».
 *
 * @see /mnt/project-files/taxmod-paginierung/vorschlag.md
 */
final class PageSize
{
    /** @var list<int> */
    public const SIZES = [10, 20, 50, 100, 200];

    public const DEFAULT = 50;

    public static function resolve(?int $page, ?int $user, ?int $site): int
    {
        foreach ([$page, $user, $site] as $size) {
            if (self::offered($size)) {
                return $size;
            }
        }

        return self::DEFAULT;
    }

    public static function offered(?int $size): bool
    {
        return $size !== null && in_array($size, self::SIZES, true);
    }
}
