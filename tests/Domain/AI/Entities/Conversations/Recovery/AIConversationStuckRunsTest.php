<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Recovery;

use DDD\Domain\AI\Entities\Conversations\Recovery\AIConversationStuckRun;
use DDD\Domain\AI\Entities\Conversations\Recovery\AIConversationStuckRuns;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** `findStuckAgentRuns`: one row per conversation with every set that names it; the settle sweep's rows last. */
class AIConversationStuckRunsTest extends UnitTestCase
{
    public function testAConversationNamedByTwoSetsIsOneRowWithBothReasons(): void
    {
        $stuckRuns = new AIConversationStuckRuns();
        $stuckRuns->addReasonToConversations(AIConversationStuckRun::REASON_QUIET_IDLE_PURPOSED, [11, 12]);
        $stuckRuns->addReasonToConversations(AIConversationStuckRun::REASON_INBOUND_LEFT_IDLE, [12, 13]);
        self::assertSame([11, 12, 13], $stuckRuns->getConversationIds());
        self::assertSame([AIConversationStuckRun::REASON_QUIET_IDLE_PURPOSED, AIConversationStuckRun::REASON_INBOUND_LEFT_IDLE], $stuckRuns->findOrAddStuckRun(12)->reasons);
        self::assertSame(2, $stuckRuns->countsByReason[AIConversationStuckRun::REASON_INBOUND_LEFT_IDLE]);
        self::assertTrue($stuckRuns->findOrAddStuckRun(11)->isNamedOnlyBySettleSweep());

        $stuckRuns->findOrAddStuckRun(13)->applyRow(['runState' => 'IDLE', 'runHeartbeatAt' => '2026-11-01 11:00:00', 'resumeAttempts' => 2], new DateTime('2026-11-01 12:00:00'));
        self::assertSame(3600, $stuckRuns->findOrAddStuckRun(13)->heartbeatAgeSeconds);

        $markdown = $stuckRuns->toLlmMarkdown();
        self::assertLessThan(strpos($markdown, '| 11 |'), strpos($markdown, '| 12 |'), 'a row another set names comes before a settle-sweep-only row');
        self::assertStringContainsString('INBOUND_LEFT_IDLE (2)', $markdown);
    }

    public function testARowCarriesTheIdOfItsSpaceAndAnAccountLevelRowCarriesNone(): void
    {
        $now = new DateTime('2026-11-01 12:00:00');
        $stuckRuns = new AIConversationStuckRuns();
        $stuckRuns->addReasonToConversations(AIConversationStuckRun::REASON_NO_HEARTBEAT, [21, 22]);
        $stuckRuns->findOrAddStuckRun(21)->applyRow(['accountId' => '91', 'agentSpaceId' => '7', 'runState' => 'RUNNING'], $now);
        $stuckRuns->findOrAddStuckRun(22)->applyRow(['accountId' => '91', 'agentSpaceId' => null, 'runState' => 'RUNNING'], $now);
        self::assertSame(7, $stuckRuns->findOrAddStuckRun(21)->agentSpaceId);
        self::assertNull($stuckRuns->findOrAddStuckRun(22)->agentSpaceId, 'a conversation at account level has no space id');

        $markdown = $stuckRuns->toLlmMarkdown();
        self::assertStringContainsString('| account | space | root |', $markdown);
        self::assertStringContainsString('| 21 | NO_HEARTBEAT |  | RUNNING | 91 | 7 |', $markdown);
        self::assertStringContainsString('| 22 | NO_HEARTBEAT |  | RUNNING | 91 |  |', $markdown);
    }
}
