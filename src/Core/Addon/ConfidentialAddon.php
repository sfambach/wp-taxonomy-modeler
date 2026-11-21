<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/**
 * «Vertraulich» on a field: its text is stored sealed, in the live row, its history and the chronicle (D-1514). Readers
 * see the text as before; only the database, its dumps and the nightly copy no longer hold it readable.
 *
 * ⚠️ *Chosen at the edge only, never at the target node: the target of a name is the text type, and a choice there
 * would seal every text of the model.* No settings of its own: being chosen is the whole statement.
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class ConfidentialAddon implements Addon
{
    public const NAME = 'confidential';

    public function name(): string
    {
        return self::NAME;
    }

    public function sites(): array
    {
        return [AddonSite::Edge];
    }

    public function requirements(): array
    {
        return [];
    }
}
