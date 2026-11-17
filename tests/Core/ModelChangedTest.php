<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Event\ChangedTogether;
use Taxmod\Core\Event\EdgeChanged;
use Taxmod\Core\Event\EdgeRecordChanged;
use Taxmod\Core\Event\JournalListener;
use Taxmod\Core\Event\ListenerProvider;
use Taxmod\Core\Event\ModelChanged;
use Taxmod\Core\Event\NodeChanged;
use Taxmod\Core\Event\NodeRecordChanged;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/** Plan step 3 (D-1424, D-631): the model sends its changes, one listener writes the chronicle. */
final class ModelChangedTest extends TestCase
{
    public function testFourKindsByTheChroniclesWord(): void
    {
        self::assertSame(NodeChanged::class, ModelChanged::classFor('node'));
        self::assertSame(EdgeChanged::class, ModelChanged::classFor('relation'));
        self::assertSame(NodeRecordChanged::class, ModelChanged::classFor('record'));
        self::assertSame(EdgeRecordChanged::class, ModelChanged::classFor('record_value'));

        $this->expectException(\InvalidArgumentException::class);
        ModelChanged::classFor('label');
    }

    public function testTheListenerWritesTheRowAndNamesTheAct(): void
    {
        $buch  = new RecordedChanges();
        $event = JournalListener::dispatcherFor($buch)->dispatch(ModelChanged::of('node', 7, 'renamed', 'a', 'b', 2));

        self::assertCount(1, $buch->entries);
        self::assertGreaterThan(0, $event->act());
    }

    public function testTogetherIsOneCall(): void
    {
        $buch  = new RecordedChanges();
        $zeile = ['ownerId' => 3, 'ownerKind' => 'node', 'what' => 'moved', 'before' => null, 'after' => 'x', 'version' => 2];
        JournalListener::dispatcherFor($buch)->dispatch(new ChangedTogether([$zeile, ['ownerId' => 4] + $zeile]));

        self::assertCount(2, $buch->entries);
    }

    public function testAChangeNobodyWroteHasNoAct(): void
    {
        $event = (new \Taxmod\Core\Event\Dispatcher(new ListenerProvider()))->dispatch(ModelChanged::of('node', 7, 'renamed', 'a', 'b', 2));

        $this->expectException(\LogicException::class);
        $event->act();
    }
}
