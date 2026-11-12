<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The recorded-run comparison's tool results: recorded once, played back without running the tool. */
class ToolResultRecordingTest extends UnitTestCase
{
    public function testARecordedResultIsPlayedForTheSameCallAndAnUnknownCallFailsVisibly(): void
    {
        $recording = ToolResultRecording::forRecording();
        $recording->recordResult('getKeywords', ['shopId' => 1], new ToolResult('20 keywords'));
        $recording->recordResult('getKeywords', ['shopId' => 1, 'noCache' => true], new ToolResult('21 keywords'));
        $refusal = new ToolResult('needs the owner\'s consent', true);
        $refusal->refusalKind = 'consent';
        $recording->recordResult('deleteProject', ['projectId' => 5], $refusal);

        $player = ToolResultRecording::forPlaying($recording->toStoredEntries());
        self::assertSame('21 keywords', $player->playResult('getKeywords', ['shopId' => 1, 'noCache' => true])->content, 'the same arguments first');
        self::assertSame('20 keywords', $player->playResult('getKeywords', ['shopId' => 9])->content, 'else the oldest unused result of the tool');
        self::assertSame([2 => 'deleteProject'], $player->unusedEntries());
        $playedRefusal = $player->playResult('deleteProject', ['projectId' => 5]);
        self::assertTrue($playedRefusal->isError);
        self::assertSame('consent', $playedRefusal->refusalKind);

        $unknown = $player->playResult('getRankings', []);
        self::assertTrue($unknown->isError);
        self::assertStringContainsString('no recorded result', $unknown->content);
    }
}
