<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

use Taxmod\Core\Repository\Changelog;

/**
 * The one listener that writes model changes into the chronicle. The chronicle stays model history, not an event store
 * ([D-631](../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class JournalListener
{
    public function __construct(private readonly Changelog $changelog)
    {
    }

    /** A dispatcher that writes every model change into this chronicle and then hands it to the {@see SharedListeners} — what a service uses when nobody hands it a dispatcher. */
    public static function dispatcherFor(Changelog $changelog): Dispatcher
    {
        $provider = new ListenerProvider();
        $listener = new self($changelog);

        $provider->listen(ModelChanged::class, $listener->changed(...));
        $provider->listen(ChangedTogether::class, $listener->together(...));
        // After the chronicle, so a listener finds the act number on the event and writes inside the same act.
        $shared = SharedListeners::dispatcher();
        $provider->listen(ModelChanged::class, $shared->dispatch(...));
        $provider->listen(ChangedTogether::class, $shared->dispatch(...));

        return new Dispatcher($provider);
    }

    public function changed(ModelChanged $event): void
    {
        $event->settle($this->changelog->record(
            $event->ownerId,
            $event->ownerKind(),
            $event->what,
            $event->before,
            $event->after,
            $event->version,
            $event->changeGroupId,
        ));
    }

    public function together(ChangedTogether $event): void
    {
        $event->settle($this->changelog->recordMany($event->rows, $event->changeGroupId));
    }
}
