<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Activity;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLines;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityTurnFeed;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The turn feed: a per-turn cache entry the voice worker reads every 0.5 s — it survives the cache
 * round trip, covers only turns at or after the reader's task, and tells a reader which lines and steps it has not
 * handled yet, per step AND state (a step is narrated once while it runs and once when it finished).
 */
class AgentActivityTurnFeedTest extends UnitTestCase
{
    public function testTheFeedSurvivesTheCacheRoundTrip(): void
    {
        $agentActivityTurnFeed = AgentActivityTurnFeed::fromCacheArray(self::buildFeed()->toCacheArray());

        self::assertInstanceOf(AgentActivityTurnFeed::class, $agentActivityTurnFeed);
        self::assertSame(983402, $agentActivityTurnFeed->rootConversationId);
        self::assertSame(53, $agentActivityTurnFeed->turnInitiatorIndex);
        self::assertSame(4, $agentActivityTurnFeed->generation);
        self::assertSame(3, $agentActivityTurnFeed->activityLines->count());
        self::assertSame("I'm searching the web for the time in New Rochelle.", $agentActivityTurnFeed->activityLines->first()->line);
        self::assertNull(AgentActivityTurnFeed::fromCacheArray(false), 'a cache miss is no feed');
        self::assertNull(AgentActivityTurnFeed::fromCacheArray(['elements' => []]), 'an entry without its turn is no feed');
    }

    public function testTheFeedCoversOnlyTurnsAtOrAfterTheTask(): void
    {
        $agentActivityTurnFeed = self::buildFeed();

        self::assertTrue($agentActivityTurnFeed->coversTaskAtMessageIndex(53));
        self::assertTrue($agentActivityTurnFeed->coversTaskAtMessageIndex(54), 'the delegation signal after the caller\'s row is inside the turn (998786: row 2 starts the turn, the signal is row 3)');
        self::assertTrue($agentActivityTurnFeed->coversTaskAtMessageIndex(44), 'a work signal opened a newer turn inside the task');
        self::assertFalse($agentActivityTurnFeed->coversTaskAtMessageIndex(60), 'an older turn is never read as the task\'s');
    }

    public function testAReaderGetsOnlyWhatItHasNotHandled(): void
    {
        $agentActivityTurnFeed = self::buildFeed();
        $narratedLines = $agentActivityTurnFeed->getNarratedLinesNotYetHandled([]);
        self::assertCount(2, $narratedLines);

        $handledElementKeys = [AgentActivityTurnFeed::buildElementKey($narratedLines[0])];
        $notYetHandled = $agentActivityTurnFeed->getNarratedLinesNotYetHandled($handledElementKeys);
        self::assertCount(1, $notYetHandled);
        self::assertSame('9299290:finished', AgentActivityTurnFeed::buildElementKey($notYetHandled[0]), 'the finished state is a line of its own');

        $stepsWithoutLine = $agentActivityTurnFeed->getStepsWithoutLineNotYetHandled([]);
        self::assertCount(1, $stepsWithoutLine);
        self::assertSame('get voice calls', $stepsWithoutLine[0]->narratorToolName);
    }

    protected static function buildFeed(): AgentActivityTurnFeed
    {
        $activityLines = new AgentActivityLines();
        $searchRunningLine = self::buildLine(9299290, AgentActivityLine::STATE_RUNNING, "I'm searching the web for the time in New Rochelle.", 'search the web');
        $searchFinishedLine = self::buildLine(9299290, AgentActivityLine::STATE_FINISHED, 'Found it on timeanddate.com.', 'search the web');
        $voiceCallsRunningLine = self::buildLine(9299326, AgentActivityLine::STATE_RUNNING, null, 'get voice calls');
        $activityLines->add($searchRunningLine);
        $activityLines->add($searchFinishedLine);
        $activityLines->add($voiceCallsRunningLine);

        return AgentActivityTurnFeed::buildForTurn(983402, 53, 4, $activityLines, 56);
    }

    protected static function buildLine(int $toolCallMessageId, string $state, ?string $line, string $narratorToolName): AgentActivityLine
    {
        $activityLine = new AgentActivityLine();
        $activityLine->toolCallMessageId = $toolCallMessageId;
        $activityLine->state = $state;
        $activityLine->line = $line;
        $activityLine->narratorToolName = $narratorToolName;
        return $activityLine;
    }
}
