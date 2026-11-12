<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the report a run owes at its terminal says: the stop reason it names, whether that reason
 * is an owner-facing sentence translated into the owner's language, whether the writes the cut turn saved ride along,
 * and the plain reply a plan-less stall gives instead of a report. Composed by {@see composeChatMessageText()} only
 * when the report is delivered — AFTER the obligation is stamped owed, so a failing composition leaves an owed report
 * for the late delivery. Pure: no repo, no model call (the {@see AgentRunReport} template).
 *
 * @phpstan-consistent-constructor
 */
class AgentRunTerminalReport extends ValueObject
{
    /** The stop reason the report names — an {@see AgentRunReport} STOP_REASON_* constant. */
    public string $stopReason;

    /** The stop reason is an English sentence translated into the owner's language before it enters the report. */
    public bool $stopReasonIsTranslatedForTheOwner = false;

    /** The report lists the writes the cut turn saved ("Saved in this stretch"), read from the current turn's rows. */
    public bool $listsWritesSavedInTheCutTurn = false;

    /** The translation key of the plain reply sent INSTEAD of the report when the conversation has no task plan. */
    public ?string $replyKeyWithoutTaskPlan = null;

    /** The report of a step / cost / repeat terminal: the loop's stop reason and the writes the cut turn saved. */
    public static function forLoopStopWithSavedWrites(string $stopReason): static
    {
        $agentRunTerminalReport = new static();
        $agentRunTerminalReport->stopReason = $stopReason;
        $agentRunTerminalReport->listsWritesSavedInTheCutTurn = true;
        return $agentRunTerminalReport;
    }

    /** The report of a terminal whose stop reason is an owner-facing sentence (the budget, a provider error). */
    public static function forOwnerFacingStopSentence(string $stopSentence): static
    {
        $agentRunTerminalReport = new static();
        $agentRunTerminalReport->stopReason = $stopSentence;
        $agentRunTerminalReport->stopReasonIsTranslatedForTheOwner = true;
        return $agentRunTerminalReport;
    }

    /**
     * The stall give-up (resume attempts exhausted): with a task plan the report names the plan's state, without one
     * the plain reply asks the owner to rephrase.
     */
    public static function forStallGiveUp(string $replyKeyWithoutTaskPlan): static
    {
        $agentRunTerminalReport = new static();
        $agentRunTerminalReport->stopReason = AgentRunReport::STOP_REASON_NO_PROGRESS_ON_REQUEST;
        $agentRunTerminalReport->replyKeyWithoutTaskPlan = $replyKeyWithoutTaskPlan;
        return $agentRunTerminalReport;
    }

    /** The chat text delivered as the OWNER_REPORT message, composed from the conversation's current state. */
    public function composeChatMessageText(AIConversation $conversation): string
    {
        if ($this->replyKeyWithoutTaskPlan !== null) {
            $hasTaskPlan = $conversation->getTaskPlan() !== null;
            $userLanguageCode = $conversation->resolveUserLanguageCode();
            return $hasTaskPlan
                ? AgentRunReport::composeFromConversation($conversation, $this->stopReason)->toChatMessageText()
                : ___($this->replyKeyWithoutTaskPlan, languageCode: $userLanguageCode);
        }
        if ($this->stopReasonIsTranslatedForTheOwner) {
            return AgentRunReport::composeFromConversation(
                $conversation,
                ___($this->stopReason, languageCode: $conversation->resolveUserLanguageCode())
            )->toChatMessageText();
        }
        $cutTurnRecord = $this->listsWritesSavedInTheCutTurn
            ? AgentPausedTurnRecord::composeFromTurnRows($conversation->messages->buildCurrentTurn()->getRows(), $conversation->getTaskPlan())
            : null;
        return AgentRunReport::composeFromConversation($conversation, $this->stopReason, $cutTurnRecord)->toChatMessageText();
    }
}
