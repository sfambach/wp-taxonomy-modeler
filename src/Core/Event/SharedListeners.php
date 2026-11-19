<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * The listeners of the whole plugin, modules included: every model change goes to them right after the chronicle wrote it
 * (renderer plan A8, the one dispatcher; event concept §9–§10).
 *
 * ```mermaid
 * flowchart LR
 *   S["service (ModelEditor, DataEntry …)"] --> D["its dispatcher"] --> J["JournalListener (chronicle, act)"]
 *   D --> R["relay"] --> L["SharedListeners: module listeners"]
 * ```
 *
 * ⚠️ *Process-wide on purpose: a service may be built in many places and with its own chronicle (the act counts per chronicle),
 * so the chronicle stays per service and only the audience is shared. No WordPress filter carries the distribution (D-627).*
 * ⚠️ *A listener runs inside the act of the change, so one undo takes back what it wrote too; an exception it throws reaches
 * the sender (PSR-14, concept §12) — nothing is swallowed, nothing is retried (§13), nothing stops the others on purpose (§15).*
 *
 * @see docs/pakete/ereignisse/konzept.md
 */
final class SharedListeners
{
    private static ?ListenerProvider $provider = null;

    /** Where a module subscribes: `SharedListeners::provider()->listen(NodeRecordChanged::class, $listener)`. */
    public static function provider(): ListenerProvider
    {
        return self::$provider ??= new ListenerProvider();
    }

    public static function dispatcher(): Dispatcher
    {
        return new Dispatcher(self::provider());
    }

    /** Tests only: forget every subscription. */
    public static function reset(): void
    {
        self::$provider = null;
    }
}
