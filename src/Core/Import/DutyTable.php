<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * Which source is responsible for what, per platform — a table he can read and change instead of filters between
 * modules (D-953, S4). A platform without a row is open to every source; a responsible source that is not active
 * hands its role to whoever brings it, as tax_mail read eBay purchases before tax_ebay could (D-950).
 *
 * @see docs/pakete/import/konzept.md §3.4
 */
final class DutyTable
{
    /** @var array<string, array<string, string>> platform (lower case) => role => source key */
    private readonly array $rows;

    /** @param array<string, array<string, string>> $rows platform => role value => source key */
    public function __construct(array $rows)
    {
        $clean = [];

        foreach ($rows as $platform => $roles) {
            foreach ((array) $roles as $role => $source) {
                if (DutyRole::tryFrom((string) $role) !== null && trim((string) $source) !== '') {
                    $clean[self::platformKey((string) $platform)][(string) $role] = trim((string) $source);
                }
            }
        }

        $this->rows = $clean;
    }

    /**
     * The starting rows: where a platform has an API source, the API brings new orders and the mail only the status
     * (D-950, generalised by D-965). Everything else is open; an inactive API source hands its roles on.
     */
    public static function initial(): self
    {
        return new self([
            'eBay'   => [DutyRole::Orders->value => 'ebay', DutyRole::Status->value => 'mail'],
            'JLCPCB' => [DutyRole::Orders->value => 'jlcpcb', DutyRole::Status->value => 'mail', DutyRole::Prices->value => 'jlcpcb'],
        ]);
    }

    /** @param list<string> $activeSources keys of the sources registered right now */
    public function allows(string $source, string $platform, DutyRole $role, array $activeSources): bool
    {
        $responsible = $this->rows[self::platformKey($platform)][$role->value] ?? null;

        return $responsible === null || $responsible === $source || ! in_array($responsible, $activeSources, true);
    }

    public function responsible(string $platform, DutyRole $role): ?string
    {
        return $this->rows[self::platformKey($platform)][$role->value] ?? null;
    }

    /** @return array<string, array<string, string>> */
    public function toArray(): array
    {
        return $this->rows;
    }

    private static function platformKey(string $platform): string
    {
        return mb_strtolower(trim($platform));
    }
}
