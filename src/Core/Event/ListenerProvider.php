<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Who listens to an event: chosen by class and, for a {@see Named} event, by its name — the narrow subscription of plan §2,
 * after the model of the `RendererRegistry` (a choice by a property, not a filter inside each listener).
 *
 * ```mermaid
 * flowchart LR
 *   E["event"] --> C{"instanceof class?"} --> N{"name, where subscribed by name"} --> L["listeners, in order of subscription"]
 * ```
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /** @var list<array{class: class-string, name: ?string, listener: callable(object): void}> */
    private array $subscriptions = [];

    /**
     * @param class-string             $eventClass
     * @param callable(object): void   $listener
     */
    public function listen(string $eventClass, callable $listener, ?string $name = null): void
    {
        if ($name !== null && ! is_a($eventClass, Named::class, true)) {
            throw new \InvalidArgumentException("{$eventClass} carries no name; subscribe to its class only.");
        }

        $this->subscriptions[] = ['class' => $eventClass, 'name' => $name, 'listener' => $listener];
    }

    /** @return iterable<callable(object): void> */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->subscriptions as $one) {
            if (! $event instanceof $one['class']) {
                continue;
            }

            if ($one['name'] !== null && (! $event instanceof Named || $event->name() !== $one['name'])) {
                continue;
            }

            yield $one['listener'];
        }
    }

    /** Whether anything listens to this event — so a sender can refuse a name nobody handles. */
    public function heard(object $event): bool
    {
        foreach ($this->getListenersForEvent($event) as $ignored) {
            return true;
        }

        return false;
    }
}
