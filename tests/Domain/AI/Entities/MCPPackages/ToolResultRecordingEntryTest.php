<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultRecordingEntry;
use DDD\Tests\Agents\Support\UnitTestCase;

/** One recorded tool result: its stored form is the array a recording file kept before, and it plays back whole. */
class ToolResultRecordingEntryTest extends UnitTestCase
{
    public function testTheStoredFormIsTheFormerArrayAndAnEntryWithoutCostsStoresNone(): void
    {
        $toolResult = new ToolResult('3 hits', true);
        $toolResult->refusalKind = 'consent';
        $toolResult->platformNoteForModel = 'NOTE';
        $toolResult->dictatedWidgetType = 'Weather';
        $toolResult->bindableResult = ['hits' => 3];
        $recording = ToolResultRecording::forRecording();
        $recording->recordResult('searchTheWeb', ['query' => 'bäckerei/köln'], $toolResult);

        self::assertContainsOnlyInstancesOf(ToolResultRecordingEntry::class, $recording->entries);
        self::assertSame([[
            'toolName' => 'searchTheWeb',
            'arguments' => '{"query":"bäckerei/köln"}',
            'content' => '3 hits',
            'isError' => true,
            'refusalKind' => 'consent',
            'platformNoteForModel' => 'NOTE',
            'dictatedWidgetType' => 'Weather',
            'bindableResult' => ['hits' => 3],
        ]], $recording->toStoredEntries());
    }

    public function testAStoredEntryPlaysBackWholeAndAnOlderEntryWithoutTheOptionalKeysPlaysWithout(): void
    {
        $storedEntry = [
            'toolName' => 't', 'arguments' => '[]', 'content' => 'c', 'isError' => false, 'refusalKind' => null,
            'platformNoteForModel' => 'NOTE', 'dictatedWidgetType' => 'W', 'bindableResult' => ['a' => 1],
        ];
        $toolResultRecordingEntry = ToolResultRecordingEntry::fromStoredEntry($storedEntry);
        self::assertSame($storedEntry, $toolResultRecordingEntry->toStoredEntry());

        $playedToolResult = $toolResultRecordingEntry->buildToolResult();
        self::assertSame(['c', false, 'NOTE', 'W', ['a' => 1]], [
            $playedToolResult->content, $playedToolResult->isError, $playedToolResult->platformNoteForModel,
            $playedToolResult->dictatedWidgetType, $playedToolResult->bindableResult,
        ]);
        self::assertNull($playedToolResult->recordedBookedCosts);

        $olderToolResult = ToolResultRecordingEntry::fromStoredEntry(['toolName' => 't', 'arguments' => '[]', 'content' => 'c', 'isError' => false])->buildToolResult();
        self::assertNull($olderToolResult->refusalKind);
        self::assertNull($olderToolResult->dictatedWidgetType);
        self::assertNull($olderToolResult->bindableResult);
    }
}
