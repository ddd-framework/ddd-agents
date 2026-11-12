<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionRecord;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The bounded decision log the inspector reads: long state fields are cut, the list is capped newest-last, the slice round-trips. */
class AgentDecisionRecordTest extends UnitTestCase
{
    public function testLongStateFieldsAreCutAndShortOnesKept(): void
    {
        $capped = AgentDecisionRecord::capStateForRecord(['ownerWordsThisTurn' => 'Bună', 'standingDirectives' => str_repeat('x', 2500), 'openWorkItems' => ['a', 'b']]);
        self::assertSame('Bună', $capped['ownerWordsThisTurn']);
        self::assertStringEndsWith(' …[cut, 2500 chars]', $capped['standingDirectives']);
        self::assertSame(['a', 'b'], $capped['openWorkItems']);
    }

    public function testTheLogIsCappedNewestLastAndRoundTrips(): void
    {
        $agentWorkingState = new AgentWorkingState();
        for ($i = 1; $i <= AgentWorkingState::MAX_DECISION_RECORDS + 3; $i++) {
            $record = new AgentDecisionRecord();
            $record->site = 'replyLanguage';
            $record->ownerMessageIndex = $i;
            $record->decidedBy = 'jev';
            $record->applied = true;
            $record->answers = ['written_language' => ['choice' => 'ro', 'confidence' => 1]];
            $record->outcome = ['languageCode' => 'ro', 'source' => 'WRITTEN'];
            $agentWorkingState->appendDecisionRecord($record);
        }
        self::assertCount(AgentWorkingState::MAX_DECISION_RECORDS, $agentWorkingState->decisions);
        self::assertSame(4, $agentWorkingState->decisions[0]->ownerMessageIndex);
        $restored = new AgentWorkingState();
        $restored->mapFromRepository(json_decode((string)json_encode($agentWorkingState->mapToRepository()), true));
        self::assertCount(AgentWorkingState::MAX_DECISION_RECORDS, $restored->decisions);
        self::assertSame(['languageCode' => 'ro', 'source' => 'WRITTEN'], $restored->decisions[0]->outcome);
        self::assertTrue($restored->decisions[0]->applied);
    }
}
