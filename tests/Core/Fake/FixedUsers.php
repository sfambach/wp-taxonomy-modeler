<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Port\Users;

/**
 * Der Rand, soweit ein Kerntest ihn braucht — **und er beweist genau das, was er ersetzt**.
 *
 * ⚠️ *Dass diese Doppelgängerin überhaupt genügt, ist die Zusage: **der Kern kommt mit einer
 * gereichten Antwort aus** und ruft `get_userdata()` nicht ([D-649](../../../docs/NewConcept/90-decision-log.md),
 * `CD-1`). Liefe hier irgendwo WordPress mit, würde dieser Lauf es merken — er lädt keines.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class FixedUsers implements Users
{
    /** @param array<string, string> $names */
    public function __construct(
        private readonly ?string $signedIn = null,
        private readonly array $names = [],
    ) {
    }

    public function signedIn(): ?string
    {
        return $this->signedIn;
    }

    /**
     * @param  list<string>          $ids
     * @return array<string, string>
     */
    public function namesFor(array $ids): array
    {
        // ⚠️ **Sie besteht auf Zeichenketten, weil das Nachlassen darin einmal alles gekostet hat.**
        // *PHP macht aus dem Schlüssel `'17'` beim Ablegen die Zahl 17; die erste Fassung dieser
        // Doppelgängerin verglich mit `array_intersect_key` und sah denselben Wandel auf beiden
        // Seiten — **also blieb sie grün, während am Rand `ctype_digit(17)` jeden Namen wegwarf.**
        // Eine Doppelgängerin, die die Zusage der Naht nicht einfordert, prüft den Rand nicht,
        // sondern sich selbst.*
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new \InvalidArgumentException(
                    'Eine Benutzer-Id faehrt als Zeichenkette (D-171), hier kam ' . get_debug_type($id) . '.'
                );
            }
        }

        return array_intersect_key($this->names, array_flip($ids));
    }
}
