<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkerJobReceipt;
use DDD\Domain\AI\MessageHandlers\AIConversationResumeMessage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The turn-start stamps of a worker run: the parts that split `preFirstStep` and the
 * microsecond owner-row origin that carries the "append → send" gap without the second-precision bias of `created`.
 */
class AgentRunTurnStartStampsTest extends UnitTestCase
{
    public function testThePartsSplitThePhaseWithoutMovingThePhaseStamps(): void
    {
        $agentRun = AgentRun::forWorkerResume(AgentWorkerJobReceipt::receivedNow(null));
        $agentRun->stampTurnStartPhase('systemPrompt');
        $agentRun->stampPreFirstStepPart('decisionInputs');
        $agentRun->stampPreFirstStepPart('decisionBatch');
        $agentRun->stampPreFirstStepPart('decisionInputs');
        $agentRun->stampTurnStartPhase('preFirstStep');

        $turnStartStamps = $agentRun->turnTimings->turnStartStamps;
        self::assertSame(['decisionInputs', 'decisionBatch'], array_keys($turnStartStamps['preFirstStepParts']));
        self::assertIsInt($turnStartStamps['preFirstStepParts']['decisionInputs']);
        self::assertLessThanOrEqual(
            $turnStartStamps['preFirstStep'] + 1,
            array_sum($turnStartStamps['preFirstStepParts']),
            'the parts lie inside the phase they split'
        );
        self::assertArrayHasKey('systemPrompt', $turnStartStamps);
    }

    public function testARunWithoutTurnStartStampsRecordsNoPart(): void
    {
        $agentRun = AgentRun::forTurnEntry();
        $agentRun->stampPreFirstStepPart('decisionInputs');

        self::assertNull($agentRun->turnTimings->turnStartStamps);
    }

    public function testTheOwnerRowStampGivesTheAppendToSendAndToJobGapsInMilliseconds(): void
    {
        $agentWorkerJobReceipt = AgentWorkerJobReceipt::receivedNow(1700000000.250000, 1700000000.100000);
        $agentWorkerJobReceipt->receivedWallUs = 1700000000.283000;
        $agentRun = AgentRun::forWorkerResume($agentWorkerJobReceipt);

        $turnStartStamps = $agentRun->turnTimings->buildTurnStartStampsForEvent(null);

        self::assertSame(150, $turnStartStamps['ownerRowToSend']);
        self::assertSame(183, $turnStartStamps['ownerRowToJob']);
        self::assertSame(33, $turnStartStamps['sendToJob']);
        self::assertArrayNotHasKey('ownerRowSavedWallUs', $turnStartStamps, 'the wall-clock origin stays off the event');
    }

    public function testWithoutAnOwnerRowStampTheTwoGapsAreNull(): void
    {
        $agentRun = AgentRun::forWorkerResume(AgentWorkerJobReceipt::receivedNow(1700000000.250000));

        $turnStartStamps = $agentRun->turnTimings->buildTurnStartStampsForEvent(null);

        self::assertNull($turnStartStamps['ownerRowToSend']);
        self::assertNull($turnStartStamps['ownerRowToJob']);
    }

    public function testTheReceiptOfTheLastJobCarriesTheOwnerRowStamp(): void
    {
        $agentWorkerJobReceipt = AgentWorkerJobReceipt::receivedNow(1.5, 1.25);

        self::assertSame(1.25, $agentWorkerJobReceipt->ownerRowSavedWallUs);
        self::assertSame(1.5, $agentWorkerJobReceipt->dispatchedAtWallUs);
    }

    public function testTheResumeMessageCarriesNoOwnerRowStampUntilTheDispatcherSetsOne(): void
    {
        $aIConversationResumeMessage = new AIConversationResumeMessage(7);

        self::assertNull($aIConversationResumeMessage->ownerRowSavedWallUs);
        self::assertNotNull($aIConversationResumeMessage->dispatchedAtWallUs);
    }
}
