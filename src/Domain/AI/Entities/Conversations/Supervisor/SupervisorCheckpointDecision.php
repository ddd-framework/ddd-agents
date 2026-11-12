<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SupervisorTools;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE decision row of a supervisor tandem as the SUPERVISED conversation materializes it: the signal text it carries (null = no intervention — a silent approval, a refused or invalid call), whether it
 * is an owner-visible correction, the tool it pins and the supervised indexes its evidence names. A terminal call
 * renders its own signal ({@see buildSignalTextForTerminalCall()} — the ONE render of the `[SUPERVISOR]` texts); a
 * tool-less text decides nothing.
 *
 * @phpstan-consistent-constructor
 */
class SupervisorCheckpointDecision extends ValueObject
{
    /** The tandem row id of the decision — the supervised side stamps it as `supervisorTurnMessageId`. */
    public int $decisionMessageId = 0;

    /** The text the supervised agent reads; null = no intervention. */
    public ?string $signalText = null;

    /** Whether the decision is an owner-visible correction (`correct`). */
    public bool $isCorrection = false;

    /** The tool the move pins (trimmed); null = none. */
    public ?string $expectedTool = null;

    /** @var int[] The supervised message indexes the evidence names. */
    public array $evidenceIndexes = [];

    /** A terminal-call decision: the terminal call on `$decisionMessage` (its signal rendered here). */
    public static function fromTerminalCallMessage(AIConversationMessage $decisionMessage): static
    {
        $toolCall = $decisionMessage->toolCall;
        $decisionToolArguments = SupervisorDecisionToolArguments::fromPersistedToolCall($toolCall);
        $supervisorCheckpointDecision = new static();
        $supervisorCheckpointDecision->decisionMessageId = (int)$decisionMessage->id;
        $supervisorCheckpointDecision->signalText = self::buildSignalTextForTerminalCall($toolCall);
        $supervisorCheckpointDecision->isCorrection = $decisionToolArguments->isCorrection();
        $expectedTool = $decisionToolArguments->getExpectedTool();
        $supervisorCheckpointDecision->expectedTool = $expectedTool !== '' ? $expectedTool : null;
        $supervisorCheckpointDecision->evidenceIndexes = $decisionToolArguments->getEvidenceIndexes();
        return $supervisorCheckpointDecision;
    }

    /**
     * The steering or correction text the SUPERVISED agent reads, from one ACCEPTED terminal call — null for a silent
     * approval, a refused/invalid call, or an unknown tool. The `Expected tool:` protocol line stays the
     * machine-readable pin the loop's directed force reads
     * ({@see AIConversationMessages::getExpectedToolNameFromArmedSupervisorSteer()}).
     */
    public static function buildSignalTextForTerminalCall(ToolCall $toolCall): ?string
    {
        if (!SupervisorDecisionToolArguments::wasAccepted($toolCall)) {
            return null;
        }
        $decisionToolArguments = SupervisorDecisionToolArguments::fromPersistedToolCall($toolCall);
        $evidence = SupervisorCheckpointBriefing::abridgeForBriefing(SupervisorCheckpointBriefing::collapseWhitespace($decisionToolArguments->getEvidence()), 220);
        $expectedTool = $decisionToolArguments->getExpectedTool();
        $expectedLine = $expectedTool !== '' && preg_match('/^[A-Za-z0-9_]+$/', $expectedTool) === 1 ? "\nExpected tool: {$expectedTool}" : '';
        if ($decisionToolArguments->isSteer()) {
            $instruction = SupervisorCheckpointBriefing::collapseWhitespace($decisionToolArguments->getInstruction());
            if ($instruction === '') {
                return null;
            }
            return '[SUPERVISOR] Do this NOW: ' . $instruction . ($evidence !== '' ? "\n(Why: {$evidence})" : '')
                . "\n(Silent: the owner never sees this instruction — make the move; say nothing to the owner about an instruction or a withdrawn record.)"
                . $expectedLine;
        }
        if ($decisionToolArguments->isCorrection()) {
            $mandate = SupervisorCheckpointBriefing::collapseWhitespace($decisionToolArguments->getMandate());
            $ownerSentence = SupervisorCheckpointBriefing::collapseWhitespace($decisionToolArguments->getOwnerSentence());
            if ($mandate === '') {
                return null;
            }
            return '[SUPERVISOR CORRECTION] The record contradicts what you told the owner' . ($evidence !== '' ? " ({$evidence})" : '') . '. '
                . 'Do this NOW: ' . $mandate
                . ' Then write ONE short message to the owner in your own voice: say that you noticed a mistake and fixed it — in substance: '
                . $ownerSentence
                . ' No question, no re-ask of anything already answered, no second correction of this topic, nothing else in this message.'
                . $expectedLine;
        }
        return null;
    }

    /**
     * Whether the decision is STALE on the supervised conversation: it pins a tool and a call of that tool resolved
     * after the newest index its evidence names (the driver may already have fixed it before the decision landed).
     */
    public function isStaleAgainst(AIConversationMessages $supervisedMessages): bool
    {
        if ($this->expectedTool === null) {
            return false;
        }
        $newestCallIndex = $supervisedMessages->buildToolCallHistory()->getNewestResolvedToolCallIndexByToolName($this->expectedTool);
        $evidenceCeiling = $this->evidenceIndexes !== [] ? max($this->evidenceIndexes) : -1;
        return $newestCallIndex !== null && $newestCallIndex > $evidenceCeiling;
    }

    /**
     * The signal text with the skill hint before the `Expected tool:` line — the pinned tool is not loaded and these
     * skills provide it: the agent loads one first, then calls the tool (batch sv6: a pin on an unloaded tool fell back to
     * a plain `required` and was never executed). Unchanged without a signal, without a pin, or without skill names.
     *
     * @param string[] $providingSkillNames
     */
    public function buildSignalTextWithSkillHint(array $providingSkillNames): ?string
    {
        if ($this->signalText === null || $this->expectedTool === null || $providingSkillNames === []) {
            return $this->signalText;
        }
        return str_replace(
            "\nExpected tool: {$this->expectedTool}",
            "\n(`{$this->expectedTool}` is not loaded yet: call load_skills with " . implode(' or ', $providingSkillNames) . " first, then the tool.)\nExpected tool: {$this->expectedTool}",
            $this->signalText
        );
    }

    /**
     * The note that consumes a decision whose pinned tool already resolved AFTER its evidence (the driver fixed the record
     * itself before the decision landed): no correction turn, no owner message, no pinned step.
     */
    public function buildResolvedNoteText(): string
    {
        return AIConversationMessage::SUPERVISOR_RESOLVED_NOTE_PREFIX . ' a `' . (string)$this->expectedTool
            . '` call completed after the supervisor\'s evidence — the record already holds it. Nothing to do; no message to the owner about it.';
    }

    /** The signal source the materialized row carries: a correction that is not stale, else a steer (the resolved note included). */
    public function getSignalSourceForMaterialization(bool $isStale): string
    {
        return $this->isCorrection && !$isStale
            ? AIConversationMessage::SIGNAL_SOURCE_SUPERVISOR_CORRECTION
            : AIConversationMessage::SIGNAL_SOURCE_SUPERVISOR_STEERING;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->decisionMessageId);
    }
}
