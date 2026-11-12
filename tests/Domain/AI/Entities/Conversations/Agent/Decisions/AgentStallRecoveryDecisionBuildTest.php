<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentStallRecoveryDecision;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The assembly the stall-recovery site moved onto its objects — the decision before the model
 * answered, its outcome record, and the message the stalled turn answers.
 */
class AgentStallRecoveryDecisionBuildTest extends UnitTestCase
{
    public function testTheDecisionOffersOnlyTheMovesPhpAdmits(): void
    {
        $decision = AgentStallRecoveryDecision::build(7, AgentStallRecoveryDecision::SHAPE_EMPTY, true, 2, false, true);

        self::assertSame(7, $decision->ownerMessageIndex);
        self::assertSame(AgentStallRecoveryDecision::SHAPE_EMPTY, $decision->stallShape);
        self::assertTrue($decision->noSkillLoaded);
        self::assertSame(2, $decision->stallCount);
        self::assertNotNull($decision->decidedAt);
        self::assertSame([
            AgentStallRecoveryDecision::MOVE_LOAD_REQUIRED_CAPABILITY,
            AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT,
            AgentStallRecoveryDecision::MOVE_NONE,
        ], $decision->offeredMoves);
    }

    public function testTheOutcomeCarriesTheConsequenceAndTheThresholds(): void
    {
        $decision = AgentStallRecoveryDecision::build(3, AgentStallRecoveryDecision::SHAPE_PRE_CALL_ANNOUNCE, false, 2, true, false);
        $decision->move = AgentStallRecoveryDecision::MOVE_CALL_A_WORK_TOOL;
        $decision->moveConfidence = 0.9;
        $decision->applied = true;

        $outcome = $decision->buildOutcome('on', AgentStallRecoveryDecision::MOVE_CALL_A_WORK_TOOL);

        self::assertSame([
            'applied', 'mode', 'stallShape', 'noSkillLoaded', 'stallCount', 'offeredMoves', 'move', 'moveConfidence',
            'moveToApply', 'probabilities', 'minimumForcedCallProbability', 'minimumForcedCallMargin',
        ], array_keys($outcome));
        self::assertTrue($outcome['applied']);
        self::assertSame('on', $outcome['mode']);
        self::assertSame(AgentStallRecoveryDecision::MOVE_CALL_A_WORK_TOOL, $outcome['moveToApply']);
        self::assertSame(AgentStallRecoveryDecision::MINIMUM_FORCED_CALL_PROBABILITY, $outcome['minimumForcedCallProbability']);
    }

    public function testTheNewestInitiatorMessageIsTheOwnersNewestMessageNotTheAgentsSignal(): void
    {
        $messages = new AIConversationMessages();
        $messages->add($this->message(1, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'first ask'));
        $messages->add($this->message(2, AIConversationMessage::ROLE_ASSISTANT, null, 'an answer'));
        $messages->add($this->message(3, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'second ask'));
        $messages->add($this->message(4, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_AGENT, 'a nudge'));

        self::assertSame('second ask', $messages->getNewestInitiatorMessage()?->message);
        self::assertSame(3, $messages->getNewestInitiatorMessageIndex());
        self::assertNull((new AIConversationMessages())->getNewestInitiatorMessage());
    }

    protected function message(int $index, string $role, ?string $authorType, string $text): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->message = $text;
        return $message;
    }
}
