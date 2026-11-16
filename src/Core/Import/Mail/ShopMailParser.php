<?php declare(strict_types=1);

namespace Taxmod\Core\Import\Mail;

use Taxmod\Core\Import\ProposalKind;

/**
 * What every mail connection says the same way: its key, the shop's name, the kinds it reads, its module's version, and
 * that it knows a mail by the sender's domain. A connection names these once in its constructor and writes only what it
 * reads (plan-dubletten row 4; was the same five methods in each module).
 *
 * @see docs/pakete/import/module-schnitt.md
 */
abstract class ShopMailParser implements OrderMailParser
{
    /**
     * @param non-empty-list<ProposalKind> $kinds
     * @param list<string>                 $domains the sender domains; a subdomain counts too
     */
    public function __construct(
        private readonly string $key,
        private readonly string $shopName,
        private readonly array $kinds,
        private readonly array $domains,
        private readonly string $version,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function shopName(): string
    {
        return $this->shopName;
    }

    public function kinds(): array
    {
        return $this->kinds;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function recognises(MailMessage $mail): bool
    {
        return $this->fromDomain($mail);
    }

    /** The sender is one of the domains or a subdomain of one. */
    protected function fromDomain(MailMessage $mail): bool
    {
        $domain = $mail->senderDomain();

        foreach ($this->domains as $own) {
            if ($domain === $own || str_ends_with($domain, '.' . $own)) {
                return true;
            }
        }

        return false;
    }
}
