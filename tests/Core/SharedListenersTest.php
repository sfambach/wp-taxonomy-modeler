<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Event\ChangedTogether;
use Taxmod\Core\Event\JournalListener;
use Taxmod\Core\Event\ModelChanged;
use Taxmod\Core\Event\NodeChanged;
use Taxmod\Core\Event\NodeRecordChanged;
use Taxmod\Core\Event\RecordsMerging;
use Taxmod\Core\Event\SharedListeners;
use Taxmod\Core\Event\SharedMergeParticipant;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/** The one audience of the plugin (renderer plan A8, event concept §8–§12): modules hear model changes after the chronicle. */
final class SharedListenersTest extends TestCase
{
    protected function tearDown(): void
    {
        SharedListeners::reset();
    }

    public function testAModuleHearsAChangeAfterTheChronicleWroteIt(): void
    {
        $heard = [];
        SharedListeners::provider()->listen(NodeRecordChanged::class, static function (NodeRecordChanged $e) use (&$heard): void {
            $heard[] = [$e->ownerId, $e->act()];
        });

        $book  = new RecordedChanges();
        $event = JournalListener::dispatcherFor($book)->dispatch(ModelChanged::of('record', 9, 'created', null, 'x', 1));

        self::assertSame([[9, $event->act()]], $heard);
    }

    public function testAListenerHearsOnlyItsKind(): void
    {
        $heard = 0;
        SharedListeners::provider()->listen(NodeChanged::class, static function () use (&$heard): void {
            ++$heard;
        });

        $dispatcher = JournalListener::dispatcherFor(new RecordedChanges());
        $dispatcher->dispatch(ModelChanged::of('record', 9, 'created', null, 'x', 1));
        $dispatcher->dispatch(ModelChanged::of('node', 3, 'renamed', 'a', 'b', 2));

        self::assertSame(1, $heard);
    }

    public function testChangesWrittenTogetherReachTheModulesToo(): void
    {
        $heard = 0;
        SharedListeners::provider()->listen(ChangedTogether::class, static function () use (&$heard): void {
            ++$heard;
        });
        $row = ['ownerId' => 3, 'ownerKind' => 'node', 'what' => 'moved', 'before' => null, 'after' => 'x', 'version' => 2];

        JournalListener::dispatcherFor(new RecordedChanges())->dispatch(new ChangedTogether([$row]));

        self::assertSame(1, $heard);
    }

    public function testAFailingListenerReachesTheSenderAndTheRowStands(): void
    {
        SharedListeners::provider()->listen(ModelChanged::class, static function (): void {
            throw new \RuntimeException('module refused');
        });
        $book = new RecordedChanges();

        try {
            JournalListener::dispatcherFor($book)->dispatch(ModelChanged::of('node', 3, 'renamed', 'a', 'b', 2));
            self::fail('The exception was swallowed.');
        } catch (\RuntimeException $refused) {
            self::assertSame('module refused', $refused->getMessage());
        }
        self::assertCount(1, $book->entries);
    }

    public function testEveryEventHasItsOwnId(): void
    {
        $a = ModelChanged::of('node', 3, 'renamed', 'a', 'b', 2);
        $b = ModelChanged::of('node', 3, 'renamed', 'a', 'b', 2);

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $a->eventId());
        self::assertSame($a->eventId(), $a->eventId());
        self::assertNotSame($a->eventId(), $b->eventId());
    }

    public function testNobodyListeningChangesNothing(): void
    {
        $book = new RecordedChanges();
        JournalListener::dispatcherFor($book)->dispatch(ModelChanged::of('node', 3, 'renamed', 'a', 'b', 2));

        self::assertCount(1, $book->entries);
    }

    public function testAMergeAsksTheModulesThroughTheEvent(): void
    {
        SharedListeners::provider()->listen(RecordsMerging::class, static function (RecordsMerging $e): void {
            $e->trash(5, 5, 0, 6);
        });
        self::assertSame(['trash' => [5, 6]], (new SharedMergeParticipant())->onMerge(1, 2));

        SharedListeners::provider()->listen(RecordsMerging::class, static function (RecordsMerging $e): void {
            $e->veto('  lot still booked ');
        });
        self::assertSame(['veto' => 'lot still booked', 'trash' => [5, 6]], (new SharedMergeParticipant())->onMerge(1, 2));
    }

    public function testAMergeNobodyAnswersIsEmpty(): void
    {
        self::assertSame([], (new SharedMergeParticipant())->onMerge(1, 2));
    }

    public function testTheListenersAreGatheredOnceBeforeTheFirstEvent(): void
    {
        $asked = 0;
        $heard = 0;
        SharedListeners::gatherWith(static function ($listeners) use (&$asked, &$heard): bool {
            ++$asked;
            $listeners->listen(NodeChanged::class, static function () use (&$heard): void {
                ++$heard;
            });

            return true;
        });
        $dispatcher = JournalListener::dispatcherFor(new RecordedChanges());
        self::assertSame(0, $asked, 'Building a service gathers nothing yet.');

        $dispatcher->dispatch(ModelChanged::of('node', 3, 'renamed', 'a', 'b', 2));
        $dispatcher->dispatch(ModelChanged::of('node', 3, 'renamed', 'b', 'c', 3));

        self::assertSame(1, $asked);
        self::assertSame(2, $heard);
    }

    public function testAGatheringTooEarlyIsTriedAgain(): void
    {
        $ready = false;
        $asked = 0;
        SharedListeners::gatherWith(static function () use (&$ready, &$asked): bool {
            ++$asked;

            return $ready;
        });

        SharedListeners::provider();
        $ready = true;
        SharedListeners::provider();
        SharedListeners::provider();

        self::assertSame(2, $asked);
    }
}
