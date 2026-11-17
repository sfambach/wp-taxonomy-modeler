<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Hands an event to its listeners, one after the other, and stops where a stoppable event says so (PSR-14,
 * [D-632](../../../docs/NewConcept/90-decision-log.md)). The core's own dispatcher, so the core needs no WordPress hook
 * ([D-627](../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class Dispatcher implements EventDispatcherInterface
{
    public function __construct(private readonly ListenerProviderInterface $provider)
    {
    }

    public function dispatch(object $event): object
    {
        foreach ($this->provider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }
}
