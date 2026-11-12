<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The admin-written marker on the conversation's working state:
 * the owner-role rows an admin typed through the admin console are recorded by index, persisted in the
 * working-state JSON beside the security-filtered indexes, and never feed a website build. Beside them the
 * voice-transcript marker: the owner rows the voice session persisted (spoken, never screened).
 */
class AgentWorkingStatePlatformWrittenMarkerTest extends UnitTestCase
{
    public function testMarkingARowTwiceRecordsItOnce(): void
    {
        $agentWorkingState = new AgentWorkingState();
        self::assertSame([], $agentWorkingState->platformWrittenOwnerMessageIndexes);
        $agentWorkingState->markOwnerMessageWrittenByPlatform(4);
        $agentWorkingState->markOwnerMessageWrittenByPlatform(7);
        $agentWorkingState->markOwnerMessageWrittenByPlatform(4);
        self::assertSame([4, 7], $agentWorkingState->platformWrittenOwnerMessageIndexes, 'idempotent per index');
    }

    public function testTheMarkerRoundTripsBesideTheSecurityFilteredIndexes(): void
    {
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->markOwnerMessageWrittenByPlatform(4);
        $agentWorkingState->markOwnerMessageWrittenByPlatform(7);
        $agentWorkingState->markOwnerMessageSecurityFiltered(2);
        $agentWorkingState->markOwnerMessageAsVoiceTranscript(9);

        $repositoryArray = $agentWorkingState->mapToRepository();
        self::assertSame([4, 7], $repositoryArray['platformWrittenOwnerMessageIndexes']);
        self::assertSame([2], $repositoryArray['securityFilteredOwnerMessageIndexes']);
        self::assertSame([9], $repositoryArray['voiceTranscriptOwnerMessageIndexes']);

        // the working state lives in a JSON column: encode → decode as an array is the repo round-trip
        $rehydratedWorkingState = new AgentWorkingState();
        $rehydratedWorkingState->mapFromRepository(json_decode(json_encode($repositoryArray), true));
        self::assertSame([4, 7], $rehydratedWorkingState->platformWrittenOwnerMessageIndexes);
        self::assertSame([2], $rehydratedWorkingState->securityFilteredOwnerMessageIndexes, 'the security marker still round-trips beside it');
        self::assertTrue($rehydratedWorkingState->isOwnerMessageSecurityFiltered(2));
        self::assertSame([9], $rehydratedWorkingState->voiceTranscriptOwnerMessageIndexes, 'the voice-transcript marker round-trips beside both');
    }

    public function testMarkingARowAsVoiceTranscriptTwiceRecordsItOnce(): void
    {
        $agentWorkingState = new AgentWorkingState();
        self::assertSame([], $agentWorkingState->voiceTranscriptOwnerMessageIndexes);
        $agentWorkingState->markOwnerMessageAsVoiceTranscript(5);
        $agentWorkingState->markOwnerMessageAsVoiceTranscript(9);
        $agentWorkingState->markOwnerMessageAsVoiceTranscript(5);
        self::assertSame([5, 9], $agentWorkingState->voiceTranscriptOwnerMessageIndexes, 'idempotent per index');
        self::assertSame([], $agentWorkingState->platformWrittenOwnerMessageIndexes, 'a spoken row is not a platform-written row');
    }

    public function testTheVoiceTranscriptMarkerReadsIntsOnlyAndDefaultsToEmpty(): void
    {
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->mapFromRepository(['voiceTranscriptOwnerMessageIndexes' => [3, '5', 'x', null, 8.0]]);
        self::assertSame([3, 5, 8], $agentWorkingState->voiceTranscriptOwnerMessageIndexes, 'numeric values become ints, the rest is dropped');

        $agentWorkingState->mapFromRepository(['platformWrittenOwnerMessageIndexes' => [1]]);
        self::assertSame([], $agentWorkingState->voiceTranscriptOwnerMessageIndexes, 'a state persisted before the marker existed reads as none');

        $agentWorkingState->mapFromRepository(null);
        self::assertSame([], $agentWorkingState->voiceTranscriptOwnerMessageIndexes);
    }

    public function testTheMarkerReadsIntsOnlyAndDefaultsToEmpty(): void
    {
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->mapFromRepository(['platformWrittenOwnerMessageIndexes' => [3, '5', 'x', null, 8.0]]);
        self::assertSame([3, 5, 8], $agentWorkingState->platformWrittenOwnerMessageIndexes, 'numeric values become ints, the rest is dropped');

        $agentWorkingState->mapFromRepository(['securityFilteredOwnerMessageIndexes' => [1]]);
        self::assertSame([], $agentWorkingState->platformWrittenOwnerMessageIndexes, 'a state persisted before the marker existed reads as none');
        self::assertSame([1], $agentWorkingState->securityFilteredOwnerMessageIndexes);

        $agentWorkingState->mapFromRepository(null);
        self::assertSame([], $agentWorkingState->platformWrittenOwnerMessageIndexes);
    }
}
