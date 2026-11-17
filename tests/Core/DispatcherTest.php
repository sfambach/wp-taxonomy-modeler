<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;
use Taxmod\Core\Event\Dispatcher;
use Taxmod\Core\Event\ListenerProvider;
use Taxmod\Core\Event\Named;

/** Plan step 2 (D-1424, D-632): PSR-14 in the core, subscription by class and name. */
final class DispatcherTest extends TestCase
{
    public function testListenersRunInOrderAndOnlyForTheirName(): void
    {
        $provider = new ListenerProvider();
        $gehoert  = [];

        $provider->listen(Pressed::class, static function (Pressed $e) use (&$gehoert): void { $gehoert[] = 'save:' . $e->name(); }, 'save_record');
        $provider->listen(Pressed::class, static function (Pressed $e) use (&$gehoert): void { $gehoert[] = 'any:' . $e->name(); });
        $provider->listen(Pressed::class, static function (Pressed $e) use (&$gehoert): void { $gehoert[] = 'move:' . $e->name(); }, 'move');

        (new Dispatcher($provider))->dispatch(new Pressed('save_record'));

        self::assertSame(['save:save_record', 'any:save_record'], $gehoert);
    }

    public function testAStoppedEventReachesNoFurtherListener(): void
    {
        $provider = new ListenerProvider();
        $gehoert  = [];

        $provider->listen(Pressed::class, static function (Pressed $e) use (&$gehoert): void { $gehoert[] = 1; $e->stop(); });
        $provider->listen(Pressed::class, static function () use (&$gehoert): void { $gehoert[] = 2; });

        $event = (new Dispatcher($provider))->dispatch(new Pressed('x'));

        self::assertSame([1], $gehoert);
        self::assertInstanceOf(Pressed::class, $event);
    }

    public function testAnUnheardNameIsVisible(): void
    {
        $provider = new ListenerProvider();
        $provider->listen(Pressed::class, static fn (): null => null, 'known');

        self::assertTrue($provider->heard(new Pressed('known')));
        self::assertFalse($provider->heard(new Pressed('unknown')));
    }

    public function testAPressedControlCarriesItsAnswerAndWhereToStand(): void
    {
        $provider = new ListenerProvider();
        $provider->listen(\Taxmod\Core\Event\ControlPressed::class, static fn (\Taxmod\Core\Event\ControlPressed $e): mixed => $e->answer($e->stayAt(42)), 'add_child');

        $pressed = (new Dispatcher($provider))->dispatch(new \Taxmod\Core\Event\ControlPressed('add_child', \Taxmod\Core\Event\Address::ofNode(7)));

        self::assertSame(42, $pressed->outcome());
        self::assertSame(42, $pressed->stay());
        self::assertFalse($provider->heard(new \Taxmod\Core\Event\ControlPressed('unknown')));
    }

    public function testTwoListenersForOneActAreAFault(): void
    {
        $provider = new ListenerProvider();
        $provider->listen(\Taxmod\Core\Event\ControlPressed::class, static fn (\Taxmod\Core\Event\ControlPressed $e): mixed => $e->answer(1), 'x');
        $provider->listen(\Taxmod\Core\Event\ControlPressed::class, static fn (\Taxmod\Core\Event\ControlPressed $e): mixed => $e->answer(2), 'x');

        $this->expectException(\LogicException::class);
        (new Dispatcher($provider))->dispatch(new \Taxmod\Core\Event\ControlPressed('x'));
    }

    public function testANameNeedsANamedEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ListenerProvider())->listen(\stdClass::class, static fn (): null => null, 'x');
    }
}

final class Pressed implements Named, StoppableEventInterface
{
    private bool $stopped = false;

    public function __construct(private readonly string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
