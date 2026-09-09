<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * A setting after the walk: what it holds, **and where that came from**.
 *
 * ⚠️ **The origin is not decoration.** *Inherited* and *set here* must look different on screen
 * (D-266), and *inherited* and *deliberately nothing here* must look different again — the
 * second stops later changes at the base from arriving, and nobody can see that from the value.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class ResolvedSetting
{
    public function __construct(
        public readonly string $key,
        public readonly TypedValue $value,
        /** The link of the chain that won — an installation, a node or an relation. */
        public readonly int $fromOwnerId,
        /** Whether the winning link is the one that was asked about. */
        public readonly bool $setHere,
        /**
         * Ob das System diesen Wert gewählt hat, weil der geerbte hier nicht zulässig war.
         *
         * ⚠️ **[D-688](../../../docs/NewConcept/90-decision-log.md), sein Wort: «ersten zulässigen als
         * Vorgabe aber nur wenn der vererbte nicht mehr zulässig ist».** *Eine Vorgabe, nie eine
         * Tatsache (R33c): nicht geschrieben, in der Tafel als **automatisch** sichtbar — der vierte
         * Zustand neben gesetzt, geerbt und «niemand hat es gesagt» ([D-689](../../../docs/NewConcept/90-decision-log.md)).*
         */
        public readonly bool $automatic = false,
        /** Der geerbte Wert, der hier nicht zulässig war — damit die Tafel sagen kann, **warum** gewählt wurde. */
        public readonly string $insteadOf = '',
    ) {
    }

    public function isInherited(): bool
    {
        return ! $this->setHere;
    }

    /**
     * Geerbt von einem Glied der Kette — und darum in der Tafel **gesperrt**, bis jemand «hier
     * überschreibe ich» sagt ([D-689](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Nicht dasselbe wie {@see isInherited()}: das sagt auch «niemand hat es gesagt» (`fromOwnerId`
     * 0), und diese Zeile ist frei, nicht gesperrt. Eine automatische Wahl ist ebenfalls frei.*
     */
    public function isLocked(): bool
    {
        return ! $this->setHere && $this->fromOwnerId !== 0 && ! $this->automatic;
    }
}
