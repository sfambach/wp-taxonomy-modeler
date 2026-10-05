<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Die Zusatzfunktionen, die es gibt ([D-845](../../../docs/NewConcept/90-decision-log.md)) — neben Renderern und Konvertern die dritte
 * Registratur; die eigene des Validators ist in ihr aufgegangen.
 */
final class AddonRegistry
{
    /** Der Name der Liste am Knoten, in der die gewählten Zusatzfunktionen stehen (`NodeAttributes`). */
    public const ATTRIBUTE = 'addons';

    /** @var array<string, Addon> */
    private array $byName = [];

    public function add(Addon $addon): void
    {
        $this->byName[$addon->name()] = $addon;
    }

    public function knows(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /** @return class-string<Addon>|null */
    public function classFor(string $name): ?string
    {
        $addon = $this->byName[$name] ?? null;

        return $addon === null ? null : $addon::class;
    }

    public function byName(string $name): Addon
    {
        return $this->byName[$name] ?? throw NotAPossibleTarget::thereIsNoSuchAddon($name);
    }

    /** Die Funktion hinter einer Klasse — `null`, wenn sie mit einem Plugin verschwand. */
    public function byClass(string $klasse): ?Addon
    {
        foreach ($this->byName as $addon) {
            if ($addon::class === $klasse) {
                return $addon;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    /**
     * Die Funktionen, die an einer Stelle gewählt werden dürfen.
     *
     * @param  list<AddonSite> $sites Was die Stelle ist — eine Kante kann zugleich mehrfach und auf Sätze sein.
     * @return list<Addon>
     */
    public function forSites(array $sites): array
    {
        $aus = [];

        foreach ($this->byName as $addon) {
            foreach ($addon->sites() as $ort) {
                if (in_array($ort, $sites, true)) {
                    $aus[] = $addon;

                    break;
                }
            }
        }

        return $aus;
    }

    /**
     * Was die gewählten Prüfungen an einem Wert auszusetzen haben — alle laufen, keine hört beim ersten Treffer auf (D-158).
     *
     * @param  list<ChosenAddon>          $chosen
     * @param  array<string, TypedValue> $settings Die Grenzen und Muster, die an der Stelle gelten.
     * @return list<Complaint>
     */
    public function complaintsAbout(TypedValue $value, ?SimpleType $type, array $chosen, array $settings): array
    {
        $aus = [];

        foreach ($chosen as $gewaehlt) {
            $addon = $this->byClass($gewaehlt->klasse);

            if (! $addon instanceof ChecksOnSave) {
                continue;
            }

            $handles = $addon->handles();

            if ($type !== null && $handles !== [] && ! in_array($type, $handles, true)) {
                continue;
            }

            foreach ($addon->check($value, $type, $gewaehlt->settings + $settings) as $complaint) {
                $aus[] = $complaint;
            }
        }

        return $aus;
    }
}
