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

    /** A provider that writes every model change into this chronicle — what a service uses when nobody hands it a dispatcher. */
    public static function dispatcherFor(Changelog $changelog): Dispatcher
    {
        $provider = new ListenerProvider();
        $listener = new self($changelog);

        $provider->listen(ModelChanged::class, $listener->changed(...));
        $provider->listen(ChangedTogether::class, $listener->together(...));

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
