<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\SignalSources;

/**
 * The shared answers of every {@see ConversationSignalSource} enum: the default of each basic question
 * (a platform source that owes work and nothing else), no metrics entry point and no analysis label, every derived
 * question computed from the basic ones, and the
 * core vocabulary answered for a source that is none of the AI core's named cases. An enum overrides only basic
 * questions — the AI core enum also its vocabulary.
 */
trait ConversationSignalSourceDefaultAnswers
{
    // --- basic questions: the defaults ---

    public function getSpeaker(): ConversationSignalSpeaker
    {
        return ConversationSignalSpeaker::PLATFORM;
    }

    public function startsATurn(): bool
    {
        return false;
    }

    public function isPickedUpByRunningTurn(): bool
    {
        return false;
    }

    public function owesWork(): bool
    {
        return true;
    }

    public function invalidatesEarlierReads(): bool
    {
        return false;
    }

    public function isPostedByTheClient(): bool
    {
        return false;
    }

    public function isConsumedByToolCall(string $toolName): bool
    {
        return false;
    }

    public function carriesAnOwnerStatement(): bool
    {
        return false;
    }

    public function marksOwnerPresence(): bool
    {
        return false;
    }

    // --- presentation properties: the defaults ---

    public function getMetricsEntryPoint(): ?string
    {
        return null;
    }

    public function getAnalysisTranscriptLabel(): ?string
    {
        return null;
    }

    // --- derived questions ---

    public function isOwnerAuthored(): bool
    {
        return $this->getSpeaker() === ConversationSignalSpeaker::OWNER && $this->invalidatesEarlierReads();
    }

    public function isOwnerFrontendAction(): bool
    {
        return $this->getSpeaker() === ConversationSignalSpeaker::OWNER && $this->isPostedByTheClient();
    }

    public function startsAnOwnerTurn(): bool
    {
        return $this->startsATurn() && $this->getSpeaker() === ConversationSignalSpeaker::OWNER;
    }

    public function isWorkOrder(): bool
    {
        return $this->startsATurn() && $this->getSpeaker() === ConversationSignalSpeaker::PLATFORM;
    }

    public function startsASupervisorTurn(): bool
    {
        return $this->startsATurn() && $this->getSpeaker() === ConversationSignalSpeaker::SUPERVISOR;
    }

    public function isSupervisorSignal(): bool
    {
        return $this->getSpeaker() === ConversationSignalSpeaker::SUPERVISOR;
    }

    public function isPhoneCallLifecycle(): bool
    {
        return $this->getSpeaker() === ConversationSignalSpeaker::OWNER && !$this->owesWork();
    }

    public function isOwnerClickOrConfirm(): bool
    {
        return $this->carriesAnOwnerStatement() && !$this->startsATurn();
    }

    public function marksAnOwnerReport(): bool
    {
        return $this->getSpeaker() === ConversationSignalSpeaker::AGENT && !$this->owesWork();
    }

    public function continuesAgentWork(): bool
    {
        return $this->getSpeaker() === ConversationSignalSpeaker::AGENT && $this->owesWork();
    }

    // --- core vocabulary: no source outside the AI core enum is a member (a form submit that starts an owner turn is) ---

    public function isOwnerWidgetEditApplied(): bool
    {
        return false;
    }

    public function quotesOwnerRows(): bool
    {
        return false;
    }

    public function isTaskPlanOpenBounce(): bool
    {
        return false;
    }

    public function isLoopSteeringWithoutProgress(): bool
    {
        return false;
    }

    public function isLoopNudgeOrTaskPlanOpenBounce(): bool
    {
        return false;
    }

    public function isLoopCorrectiveOrWorkContinuation(): bool
    {
        return $this->continuesAgentWork();
    }

    public function isPlatformSteer(): bool
    {
        return false;
    }
}
