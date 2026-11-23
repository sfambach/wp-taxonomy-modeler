<?php declare(strict_types=1);

namespace Taxmod\WordPress;

/**
 * The one table of rights and the roles that carry them (D-1585): each right is a `taxmod_*` capability, a module adds
 * its own through {@see self::FILTER}, and every handler checks its concrete right — the same one its menu entry or
 * action names, so a person sees only what they may do (D-1586).
 *
 * ```mermaid
 * flowchart LR
 *   T["Rights::table() + taxmod_rights"] --> R["shipped roles (ensureRoles)"]
 *   T --> A["administrator: all but app_only (user_has_cap)"]
 *   R --> C["current_user_can(Rights::X)"]
 *   A --> C
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1585, D-1586
 */
final class Rights
{
    /** `apply_filters(FILTER, [])`: a module's own rights, capability => label (D-1585). */
    public const FILTER = 'taxmod_rights';

    /** See taxmod, choose and print — printing is no right of its own, it belongs here (his word 23.11.: «Drucken ist eine Cloud-Funktion»). */
    public const VIEW = 'taxmod_view';
    public const WRITE_DATA = 'taxmod_write_data';
    public const MODEL = 'taxmod_model';
    public const CONFIGURE = 'taxmod_configure';
    public const STOCK_BOOK = 'taxmod_stock_book';
    public const PURCHASE = 'taxmod_purchase';
    public const MANUFACTURE = 'taxmod_manufacture';
    public const SHIP = 'taxmod_ship';
    public const SELL = 'taxmod_sell';

    /** The taxmod user: lands in taxmod in full screen, never in wp-admin. Given per person, never to the administrator by itself. */
    public const APP_ONLY = 'taxmod_app_only';

    /** Who holds every right: the administrator — nobody locks themselves out. */
    private const ADMINISTRATOR = 'manage_options';

    /** Rewritten when the table changes, so the roles follow the code deterministically (CD-6). */
    public const ROLES_VERSION_OPTION = 'taxmod_roles_version';

    public const DATA_WRITER_ROLE = 'taxmod_data_writer';

    /** @var array<string, string>|null */
    private static ?array $all = null;

    /** @return array<string, string> capability => label, the modeller's own */
    public static function table(): array
    {
        return [
            self::VIEW        => __('See, choose and print', 'taxmod'),
            self::WRITE_DATA  => __('Create and change records', 'taxmod'),
            self::MODEL       => __('Shape the model: nodes and fields', 'taxmod'),
            self::CONFIGURE   => __('Configuration, cleanup and backup', 'taxmod'),
            self::STOCK_BOOK  => __('Book stock', 'taxmod'),
            self::PURCHASE    => __('Purchasing and goods arrival', 'taxmod'),
            self::MANUFACTURE => __('Manufacturing', 'taxmod'),
            self::SHIP        => __('Shipping', 'taxmod'),
            self::SELL        => __('Sales', 'taxmod'),
            self::APP_ONLY    => __('Only taxmod, in full screen', 'taxmod'),
        ];
    }

    /** @return array<string, string> the table and what the modules add, capability => label */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $added = apply_filters(self::FILTER, []);
        $all   = self::table();

        foreach (is_array($added) ? $added : [] as $right => $label) {
            if (is_string($right) && str_starts_with($right, 'taxmod_') && ! isset($all[$right])) {
                $all[$right] = (string) $label;
            }
        }

        // Cached only once every module had its chance to add its rights.
        if (did_action('init')) {
            self::$all = $all;
        }

        return $all;
    }

    /**
     * The shipped roles (D-1585, table of `taxmod-benutzer/rollen-vorschlag.md`); several roles add up.
     *
     * @return array<string, array{name: string, rights: list<string>}>
     */
    public static function roles(): array
    {
        return [
            'taxmod_stock'       => ['name' => __('Taxmod: Stock', 'taxmod'), 'rights' => [self::VIEW, self::WRITE_DATA, self::STOCK_BOOK]],
            'taxmod_purchase'    => ['name' => __('Taxmod: Purchasing', 'taxmod'), 'rights' => [self::VIEW, self::WRITE_DATA, self::PURCHASE]],
            'taxmod_manufacture' => ['name' => __('Taxmod: Manufacturing', 'taxmod'), 'rights' => [self::VIEW, self::WRITE_DATA, self::MANUFACTURE]],
            'taxmod_sales'       => ['name' => __('Taxmod: Shipping and sales', 'taxmod'), 'rights' => [self::VIEW, self::WRITE_DATA, self::SELL, self::SHIP]],
            'taxmod_read_only'   => ['name' => __('Taxmod: Read only', 'taxmod'), 'rights' => [self::VIEW]],
            // D-936: the machine user (auto_einkauf); reading came with writing before, so it keeps it.
            self::DATA_WRITER_ROLE => ['name' => __('Taxonomy Modeller: write records', 'taxmod'), 'rights' => [self::VIEW, self::WRITE_DATA]],
        ];
    }

    /**
     * `user_has_cap`: the administrator holds every right of the table and the modules, but not «only taxmod».
     *
     * @param array<string, bool> $allcaps
     * @return array<string, bool>
     */
    public static function grant(array $allcaps): array
    {
        if (empty($allcaps[self::ADMINISTRATOR])) {
            return $allcaps;
        }

        foreach (array_keys(self::all()) as $right) {
            if ($right !== self::APP_ONLY) {
                $allcaps[$right] = true;
            }
        }

        return $allcaps;
    }

    /**
     * The shipped roles stand exactly as the table says, once per change of the table: missing roles are added, a role's
     * `taxmod_*` capabilities follow the table, anything else it carries stays. No role is ever removed (D-1585 gate, point 4).
     */
    public static function ensureRoles(): void
    {
        $roles   = self::roles();
        $version = md5((string) wp_json_encode(array_map(static fn (array $role): array => $role['rights'], $roles)));

        if (get_option(self::ROLES_VERSION_OPTION, '') === $version) {
            return;
        }

        foreach ($roles as $slug => $role) {
            $existing = get_role($slug);

            if ($existing === null) {
                add_role($slug, $role['name'], ['read' => true, ...array_fill_keys($role['rights'], true)]);

                continue;
            }

            $existing->add_cap('read');

            foreach (array_keys($existing->capabilities) as $capability) {
                if (str_starts_with((string) $capability, 'taxmod_') && ! in_array($capability, $role['rights'], true)) {
                    $existing->remove_cap((string) $capability);
                }
            }

            foreach ($role['rights'] as $right) {
                $existing->add_cap($right);
            }
        }

        update_option(self::ROLES_VERSION_OPTION, $version, true);
    }
}
