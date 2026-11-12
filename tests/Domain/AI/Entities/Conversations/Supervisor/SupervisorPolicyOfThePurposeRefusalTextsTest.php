<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SupervisorTools;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointBriefingHeader;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointHistory;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorDecisionAcceptance;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorPolicy;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The acceptance chain of a supervisor checkpoint refuses an intervention in the words of the supervised purpose's
 * policy and accepts a record reference of the kinds that policy names. The module's own policy knows the task plan
 * and no purpose: its three refusal texts name no fact kind, no criterion code and no tool.
 */
class SupervisorPolicyOfThePurposeRefusalTextsTest extends UnitTestCase
{
    public function testTheModuleKnowsThePlanRecordAndNamesNoPurposeInItsRefusals(): void
    {
        $supervisorPolicy = new SupervisorPolicy();

        self::assertSame(['plan'], $supervisorPolicy->getRecordKinds());
        $recordConstantNames = [];
        foreach (array_keys((new ReflectionClass(SupervisorTools::class))->getConstants()) as $constantName) {
            if (str_starts_with($constantName, 'RECORD_')) {
                $recordConstantNames[] = $constantName;
            }
        }
        self::assertSame(['RECORD_PLAN'], $recordConstantNames);
        foreach ([$supervisorPolicy->getStepWithoutFactResult(), $supervisorPolicy->getOwnerQuoteMissingResult(), $supervisorPolicy->getQuestionNotMandatoryResult()] as $refusalResult) {
            self::assertStringStartsWith('NO INTERVENTION — ', $refusalResult);
            self::assertDoesNotMatchRegularExpression('/C\d|addGoal|Handover|UNGROUNDED|COUNT CLAIM|SILENT DROP/', $refusalResult);
        }
    }

    public function testASettledBeatAcceptsARecordReferenceOfTheKindsThePolicyNames(): void
    {
        $evidenceOfAStockRecord = ['instruction' => 'Correct the stock.', 'evidence' => 'record:stock', 'topic' => 'stock@12', 'expectedTool' => 'updateStock'];

        $underTheModulePolicy = $this->judgeSteer(new SupervisorPolicy(), SupervisorCheckpointBriefingHeader::BEAT_SETTLED, $evidenceOfAStockRecord, null);
        $underThePurposePolicy = $this->judgeSteer(new SupervisorPolicyOfAStockIntake(), SupervisorCheckpointBriefingHeader::BEAT_SETTLED, $evidenceOfAStockRecord, null);

        self::assertStringStartsWith(SupervisorTools::RESULT_VALIDATION_FAILED, (string)$underTheModulePolicy->result);
        self::assertSame(SupervisorTools::RESULT_ACCEPTED, $underThePurposePolicy->result);
    }

    public function testTheThreeRefusalsAreWrittenInTheWordsOfThePolicy(): void
    {
        $steerOnAMessage = ['instruction' => 'Ask for the delivery day.', 'evidence' => '[12]', 'topic' => 'delivery@12', 'expectedTool' => 'askOwner'];
        /** @var AIConversation $supervisedConversation */
        $supervisedConversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();

        $stepUnderTheModulePolicy = $this->judgeSteer(new SupervisorPolicy(), SupervisorCheckpointBriefingHeader::BEAT_STEP, $steerOnAMessage, null);
        $stepUnderThePurposePolicy = $this->judgeSteer(new SupervisorPolicyOfAStockIntake(), SupervisorCheckpointBriefingHeader::BEAT_STEP, $steerOnAMessage, null);
        $quoteUnderThePurposePolicy = $this->judgeSteer(new SupervisorPolicyOfAStockIntake(), SupervisorCheckpointBriefingHeader::BEAT_SETTLED, $steerOnAMessage, $supervisedConversation);
        $questionUnderThePurposePolicy = $this->judgeSteer(new SupervisorPolicyOfAStockIntake(), SupervisorCheckpointBriefingHeader::BEAT_SETTLED, $steerOnAMessage, null);

        self::assertSame(SupervisorPolicy::DEFAULT_STEP_WITHOUT_FACT_RESULT, $stepUnderTheModulePolicy->result);
        self::assertSame('NO INTERVENTION — a step beat acts only on a STOCK MISMATCH line', $stepUnderThePurposePolicy->result);
        self::assertSame('NO INTERVENTION — a delivery day is quoted from an owner row', $quoteUnderThePurposePolicy->result);
        self::assertSame('NO INTERVENTION — only the delivery day (D1) is a mandatory question', $questionUnderThePurposePolicy->result);
    }

    /**
     * @param array<string, string> $steerArguments
     */
    protected function judgeSteer(SupervisorPolicy $supervisorPolicy, string $beat, array $steerArguments, ?AIConversation $supervisedConversation): ToolCall
    {
        $briefingHeader = SupervisorCheckpointBriefingHeader::create($beat, 'Stock.Intake', 20, null, 'test');
        $supervisorDecisionAcceptance = SupervisorDecisionAcceptance::forBriefing($briefingHeader, '', 0, $supervisorPolicy);
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_1';
        $toolCall->name = SupervisorTools::STEER;
        $toolCall->arguments = $steerArguments;
        $supervisorDecisionAcceptance->judgeToolCall($toolCall, SupervisorCheckpointHistory::fromRows([]), $supervisedConversation);
        return $toolCall;
    }
}

/** The policy of a purpose that takes stock: its own record kind and its own words for the three refusals. */
class SupervisorPolicyOfAStockIntake extends SupervisorPolicy
{
    public function getRecordKinds(): array
    {
        return array_merge(['stock'], parent::getRecordKinds());
    }

    public function getStepWithoutFactResult(): string
    {
        return 'NO INTERVENTION — a step beat acts only on a STOCK MISMATCH line';
    }

    public function getOwnerQuoteMissingResult(): string
    {
        return 'NO INTERVENTION — a delivery day is quoted from an owner row';
    }

    public function getQuestionNotMandatoryResult(): string
    {
        return 'NO INTERVENTION — only the delivery day (D1) is a mandatory question';
    }

    public function interventionQuotesTheOwnerWhereRequired(array $decisionArguments, AIConversation $supervisedConversation, int $coveredMessageIndex): bool
    {
        return false;
    }

    public function questionSteerIsAllowed(string $expectedTool, array $violatedCodes): bool
    {
        return $expectedTool !== 'askOwner';
    }
}
