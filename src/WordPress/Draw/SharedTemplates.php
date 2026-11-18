<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\Drawer;

/**
 * The shared dialog bodies of a page as templates, one per body (D-866); a large one only as an address the script fetches (D-898).
 *
 * ⚠️ *Outside every form, because the buttons in them have no name yet.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class SharedTemplates
{
    /**
     * @param array<string, Describes|string>                                  $bodies    key ⇒ body
     * @param (\Closure(string $schluessel, string $koerper): ?string)|null $auslagern where a large body goes; `null`: all in the page
     */
    public static function of(array $bodies, Drawer $drawer, ?\Closure $auslagern = null): string
    {
        $html = '';

        foreach ($bodies as $schluessel => $koerper) {
            $markup  = is_string($koerper) ? $koerper : $drawer->draw($koerper);
            $adresse = $auslagern === null ? null : $auslagern((string) $schluessel, $markup);

            $html .= $adresse === null
                ? '<template class="taxmod-shared-body" data-taxmod-body="' . $schluessel . '">' . $markup . '</template>'
                : '<template class="taxmod-shared-body" data-taxmod-body="' . $schluessel . '" data-taxmod-src="'
                    . htmlspecialchars($adresse, ENT_QUOTES) . '"></template>';
        }

        return $html;
    }
}
