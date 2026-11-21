<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * The listeners of the whole plugin, modules included: every model change goes to them right after the chronicle wrote it
 * (renderer plan A8, the one dispatcher; event concept §9–§10; D-1498).
 *
 * ```mermaid
 * flowchart LR
 *   S["service (ModelEditor, DataEntry …)"] --> D["its dispatcher"] --> J["JournalListener (chronicle, act)"]
 *   D --> R["relay"] --> L["SharedListeners: module listeners"]
 * ```
 *
 * ⚠️ *Process-wide on purpose: a service may be built in many places and with its own chronicle (the act counts per chronicle),
 * so the chronicle stays per service and only the audience is shared. No WordPress filter carries the distribution (D-627).*
 * ⚠️ *A listener in the act writes only to the database; what reaches outside (HTTP, print, mail) runs after the act has ended,
 * else undo and rollback cannot take it back (D-1498).*
 * ⚠️ *A listener runs inside the act of the change, so one undo takes back what it wrote too; an exception it throws reaches
 * the sender (PSR-14, concept §12) — nothing is swallowed, nothing is retried (§13), nothing stops the others on purpose (§15).*
 *
 * @see docs/pakete/ereignisse/konzept.md
 */
final class SharedListeners
{
    private static ?ListenerProvider $provider = null;

    /** @var null|callable(ListenerProvider): bool */
    private static $gather = null;

    /**
     * How the listeners are collected, once, before the first event goes out — the edge hands its `taxmod_listen` action in
     * (D-1498), so a module subscribes without naming a modeller class at the wrong moment.
     *
     * The callable answers false while the listeners cannot all be there yet (before every plugin is loaded); then the event
     * goes to whoever subscribed so far and the gathering is tried again for the next one.
     *
     * @param callable(ListenerProvider): bool $gather
     */
    public static function gatherWith(callable $gather): void
    {
        self::$gather = $gather;
    }

    /** The subscriptions, collected on first asking. */
    public static function provider(): ListenerProvider
    {
        if (self::$provider !== null) {
            return self::$provider;
        }

        $provider = new ListenerProvider();

        if (self::$gather === null || (self::$gather)($provider)) {
            self::$provider = $provider;
        }

        return $provider;
    }

    public static function dispatcher(): Dispatcher
    {
        return new Dispatcher(self::$silent ? new ListenerProvider() : self::provider());
    }

    private static bool $silent = false;

    /**
     * D-1561: runs `$run` while no module hears an event — a dry run of a change list is rolled back, so nobody may act on it.
     *
     * @template T
     * @param callable(): T $run
     * @return T
     */
    public static function silenced(callable $run): mixed
    {
        $before       = self::$silent;
        self::$silent = true;

        try {
            return $run();
        } finally {
            self::$silent = $before;
        }
    }

    /** Tests only: forget every subscription and the way they were gathered. */
    public static function reset(): void
    {
        self::$provider = null;
        self::$gather   = null;
    }
}
