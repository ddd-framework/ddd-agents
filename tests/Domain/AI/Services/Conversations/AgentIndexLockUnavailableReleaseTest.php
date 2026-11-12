<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunRelease;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Lever E at the resume boundary: a turn the message-index lock refused twice is handed to the owed-turn recovery
 * pass — IDLE with the handover marker, one attempt counted — and ends FAILED only once the retry cap is exhausted.
 */
class AgentIndexLockUnavailableReleaseTest extends UnitTestCase
{
    /**
     * The release of a root conversation (no delegation batch), read as today's four facts.
     *
     * @return array{runState: string, resumeAttempts: int, agentTurnOwedAt: DateTime|null, overCap: bool}
     */
    protected function releaseFor(int $resumeAttemptsSoFar): array
    {
        $runRelease = AgentRunRelease::afterIndexLockUnavailable($resumeAttemptsSoFar, false, new DateTime());
        return [
            'runState' => $runRelease->runState,
            'resumeAttempts' => $runRelease->resumeAttempts,
            'agentTurnOwedAt' => $runRelease->agentTurnOwedAt,
            'overCap' => $runRelease->overCap,
        ];
    }

    public function testUnderTheCapTheRunGoesIdleWithTheOwedMarkerAndOneAttemptCounted(): void
    {
        $release = $this->releaseFor(0);

        $this->assertSame(AIConversationRunState::IDLE, $release['runState']);
        $this->assertSame(1, $release['resumeAttempts']);
        $this->assertInstanceOf(DateTime::class, $release['agentTurnOwedAt'], 'the owed-turn pass selects on the marker');
        $this->assertFalse($release['overCap']);
    }

    public function testTheLastAttemptUnderTheCapStillGoesIdle(): void
    {
        $release = $this->releaseFor(AgentRunRelease::MAX_RESUME_ATTEMPTS - 1);

        $this->assertSame(AIConversationRunState::IDLE, $release['runState']);
        $this->assertSame(AgentRunRelease::MAX_RESUME_ATTEMPTS, $release['resumeAttempts']);
        $this->assertNotNull($release['agentTurnOwedAt']);
    }

    public function testOverTheCapTheRunEndsFailedWithoutAMarker(): void
    {
        $release = $this->releaseFor(AgentRunRelease::MAX_RESUME_ATTEMPTS);

        $this->assertSame(AIConversationRunState::FAILED, $release['runState']);
        $this->assertSame(AgentRunRelease::MAX_RESUME_ATTEMPTS + 1, $release['resumeAttempts']);
        $this->assertNull($release['agentTurnOwedAt']);
        $this->assertTrue($release['overCap']);
    }
}
