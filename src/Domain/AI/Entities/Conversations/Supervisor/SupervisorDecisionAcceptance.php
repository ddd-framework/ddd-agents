<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SupervisorTools;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The ACCEPTANCE CHAIN of one supervisor checkpoint: every terminal call the model
 * returns passes {@see judgeToolCall()} in order — unknown tool, a call after the decision, structural validation, the
 * step-beat rule, the owner quote, the question rule, the duplicate finding, the correction downgrades — and leaves with
 * its result marker, its status and its persisted arguments written on the call. The object carries what the chain
 * counts across the calls of the checkpoint (decided, interventions accepted, a correction accepted).
 *
 * The purpose's rules (the tools a step beat may pin, the question it may demand, the tools never pinned, the quote of
 * the owner, the fact kinds, the record kinds) and the result texts of its refusals are asked from the supervised
 * purpose's {@see SupervisorPolicy}.
 *
 * @phpstan-consistent-constructor
 */
class SupervisorDecisionAcceptance extends ValueObject
{
    /** The beat of the checkpoint ({@see SupervisorCheckpointBriefingHeader::BEAT_STEP} / `BEAT_SETTLED`). */
    public string $beat = SupervisorCheckpointBriefingHeader::BEAT_SETTLED;

    /** The supervised index the briefing covers (evidence beyond it is refused). */
    public int $coveredMessageIndex = PHP_INT_MAX;

    /** The briefing text (its FACTS lines back a step-beat decision). */
    public string $briefingText = '';

    /** The supervised index the current owner interaction started at (the duplicate-finding window). */
    public int $interactionStartIndex = 0;

    /** @var string[] The record kinds a `record:<kind>` evidence may name (the settled briefing carries the state of the space). */
    public array $recordKindsRead = [];

    /** The supervised purpose's rules of the chain. */
    public SupervisorPolicy $supervisorPolicy;

    /** Whether the checkpoint has decided (a terminal call passed the first gate). */
    public bool $decided = false;

    /** The interventions (steer / correct) accepted so far in this checkpoint. */
    public int $acceptedInterventionCount = 0;

    /** Whether an owner-visible correction was accepted in this checkpoint. */
    public bool $correctionAccepted = false;

    /** The chain of one checkpoint over its briefing (its header gives the beat and the covered index) under the purpose's rules. */
    public static function forBriefing(SupervisorCheckpointBriefingHeader $briefingHeader, string $briefingText, int $interactionStartIndex, SupervisorPolicy $supervisorPolicy): static
    {
        $supervisorDecisionAcceptance = new static();
        $supervisorDecisionAcceptance->supervisorPolicy = $supervisorPolicy;
        $supervisorDecisionAcceptance->beat = $briefingHeader->beat;
        $supervisorDecisionAcceptance->coveredMessageIndex = $briefingHeader->asOfMessageIndex ?? PHP_INT_MAX;
        $supervisorDecisionAcceptance->briefingText = $briefingText;
        $supervisorDecisionAcceptance->interactionStartIndex = $interactionStartIndex;
        $supervisorDecisionAcceptance->recordKindsRead = $supervisorDecisionAcceptance->isSettledBeat() ? $supervisorPolicy->getRecordKinds() : [];
        return $supervisorDecisionAcceptance;
    }

    public function isSettledBeat(): bool
    {
        return $this->beat === SupervisorCheckpointBriefingHeader::BEAT_SETTLED;
    }

    public function isStepBeat(): bool
    {
        return $this->beat === SupervisorCheckpointBriefingHeader::BEAT_STEP;
    }

    /**
     * Judge ONE call of the model's response: write its result marker, status and persisted arguments on the call (a
     * downgraded correction also gets the name `steer`), and return the signal text when the call is an ACCEPTED
     * intervention (null otherwise — a silent approval, a refusal). `$tandemHistory` is the tandem's history as it
     * stands now (the calls of this response judged before included).
     */
    public function judgeToolCall(ToolCall $toolCall, SupervisorCheckpointHistory $tandemHistory, ?AIConversation $supervisedConversation): ?string
    {
        $toolName = (string)($toolCall->name ?? '');
        if (!SupervisorTools::isTerminalToolName($toolName)) {
            $toolCall->result = "Unknown tool '{$toolName}' — decide with silentApprove, steer or correct.";
            $toolCall->markFailed();
            return null;
        }
        if ($this->decided && !$this->acceptsAFurtherIntervention($toolName)) {
            $toolCall->result = 'IGNORED — this checkpoint already decided';
            $toolCall->markFailed();
            return null;
        }
        $this->decided = true;
        $decisionToolArguments = SupervisorDecisionToolArguments::fromToolCall($toolCall, $this->coveredMessageIndex, $this->recordKindsRead);
        $toolCall->arguments = $decisionToolArguments->toPersistedArguments();
        $refusalResult = $this->findRefusalResult($toolCall, $decisionToolArguments, $tandemHistory, $supervisedConversation);
        if ($refusalResult !== null) {
            $toolCall->result = $refusalResult;
            $toolCall->markFailed();
            return null;
        }
        if ($decisionToolArguments->isCorrection() && $this->isSettledBeat() && $tandemHistory->hasAcceptedCorrectionOnRecord($decisionToolArguments->getTopicRecordKey())) {
            // ONE owner-visible correction per record and conversation (seen: a correction, then its
            // retraction, then a third): a further fix on the same record is made silently.
            $this->downgradeCorrectionToSteer($toolCall, $decisionToolArguments, SupervisorTools::RESULT_SECOND_CORRECTION_SILENT);
        } elseif ($decisionToolArguments->isCorrection() && $this->isStepBeat()) {
            $this->downgradeCorrectionToSteer($toolCall, $decisionToolArguments, SupervisorTools::RESULT_CORRECT_ON_STEP_BEAT);
        } else {
            $toolCall->result = SupervisorTools::RESULT_ACCEPTED;
        }
        $toolCall->markCompleted();
        $acceptedToolName = (string)($toolCall->name ?? '');
        if ($acceptedToolName !== SupervisorTools::SILENT_APPROVE) {
            $this->acceptedInterventionCount++;
            $this->correctionAccepted = $this->correctionAccepted || $acceptedToolName === SupervisorTools::CORRECT;
        }
        return SupervisorCheckpointDecision::buildSignalTextForTerminalCall($toolCall);
    }

    /**
     * Whether a call AFTER the decision is still judged: the settled audit finds several HARD lines at once
     * (e.g. a draft claim AND an approval stored as DECLINE) — one call per beat left the second for the next
     * interaction, after the close. A settled beat with an accepted intervention takes more interventions up to the
     * cap, at most one correction; never a silent approval after an intervention.
     */
    protected function acceptsAFurtherIntervention(string $toolName): bool
    {
        return $this->isSettledBeat()
            && $this->acceptedInterventionCount > 0
            && $this->acceptedInterventionCount < SupervisorTools::MAX_INTERVENTIONS_PER_SETTLED_BEAT
            && $toolName !== SupervisorTools::SILENT_APPROVE
            && !($toolName === SupervisorTools::CORRECT && $this->correctionAccepted);
    }

    /** The result marker that refuses the call, in the chain's order; null = the call passes. */
    protected function findRefusalResult(ToolCall $toolCall, SupervisorDecisionToolArguments $decisionToolArguments, SupervisorCheckpointHistory $tandemHistory, ?AIConversation $supervisedConversation): ?string
    {
        if ($decisionToolArguments->hasValidationFeedback()) {
            return SupervisorTools::RESULT_VALIDATION_FAILED . ': ' . implode('; ', $decisionToolArguments->getValidationFeedback());
        }
        if ($decisionToolArguments->isSilentApprove()) {
            return null;
        }
        $expectedTool = (string)($toolCall->arguments['expectedTool'] ?? '');
        $violatedCodes = (array)($toolCall->arguments['violated'] ?? []);
        if ($this->isStepBeat()
            && (!($this->supervisorPolicy->isStepSteerTool(trim($expectedTool))
                    || $this->supervisorPolicy->questionSteerIsAllowedOnAStepBeat($expectedTool, $violatedCodes))
                || !$this->supervisorPolicy->stepDecisionRestsOnAFact($decisionToolArguments->getEvidenceIndexes(), $this->briefingText))
        ) {
            // A step beat sees a few rows of a running turn. Read case by case, its own readings were the
            // main harm (rollbacks of the draft order, repeated questions, sequence orders with a write attached, an
            // owner-given line removed as "ungrounded"); its fact-backed fixes helped. It acts only on a deterministic
            // FACT at its evidence, with a write — everything else is the settled audit's.
            return $this->supervisorPolicy->getStepWithoutFactResult();
        }
        if ($supervisedConversation !== null && !$this->supervisorPolicy->interventionQuotesTheOwnerWhereRequired($toolCall->arguments, $supervisedConversation, $this->coveredMessageIndex)) {
            // A step steer handed a write tool the agent's own question wording one row before the
            // owner answered — the agent stored it as the owner's goal.
            return $this->supervisorPolicy->getOwnerQuoteMissingResult();
        }
        if (!$this->supervisorPolicy->questionSteerIsAllowed($expectedTool, $violatedCodes)) {
            // A settled steer made the agent ask questions that did not fit the space — which
            // questions are asked and when is the contract's; only a MANDATORY question never asked is the
            // supervisor's to demand.
            return $this->supervisorPolicy->getQuestionNotMandatoryResult();
        }
        if ($tandemHistory->hasInterventionOnTheSameFinding($decisionToolArguments, $this->interactionStartIndex, $supervisedConversation)) {
            return SupervisorTools::RESULT_DUPLICATE_TOPIC . ': an intervention on this record already exists in this interaction';
        }
        return null;
    }

    /** A `correct` accepted as a silent `steer`: the mandate becomes the instruction, the result names why. */
    protected function downgradeCorrectionToSteer(ToolCall $toolCall, SupervisorDecisionToolArguments $decisionToolArguments, string $resultSuffix): void
    {
        $toolCall->name = SupervisorTools::STEER;
        $toolCall->arguments = $decisionToolArguments->toPersistedArgumentsDowngradedToSteer();
        $toolCall->result = SupervisorTools::RESULT_ACCEPTED . ' (' . $resultSuffix . ')';
    }
}
