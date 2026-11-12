<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SupervisorTools;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * A supervisor TANDEM's own past checkpoints, read off its loaded rows: the
 * briefings with their headers ({@see SupervisorCheckpointBriefingHeader} — the only header parse), the decisions after
 * them, the counters the beat caps read, and the duplicate-intervention questions of the acceptance chain. Built by
 * {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages::buildSupervisorCheckpointHistory()};
 * reads nothing beyond the rows it holds.
 *
 * The readers keep today's leniency: the covered index and the step count read ANY USER row that carries the line (a
 * pasted `asOfMessageIndex:` without the header prefix counts), the run count and the projection read only rows that
 * open with the header prefix.
 *
 * @phpstan-consistent-constructor
 */
class SupervisorCheckpointHistory extends ValueObject
{
    /** The in-memory stubs the model context carries for EARLIER checkpoints (the rows stay intact in the database). */
    public const string EARLIER_BRIEFING_STUB = '(the delta of this earlier checkpoint is projected away — its decision follows)';
    public const string EARLIER_LOOKUP_STUB = '(lookup result of an earlier checkpoint — projected away)';

    /** The line that opens the delta of every briefing — everything from it on is the per-checkpoint material. */
    public const string BRIEFING_DELTA_START = '## OWNER (rows in this range, verbatim)';

    /** @var AIConversationMessage[] The tandem's rows in set order (the loaded objects — the projection writes on them). */
    protected array $rows = [];

    /** @param AIConversationMessage[] $rows */
    public static function fromRows(array $rows): static
    {
        $supervisorCheckpointHistory = new static();
        $supervisorCheckpointHistory->rows = $rows;
        return $supervisorCheckpointHistory;
    }

    /** The newest briefing row (a USER row opening with the header prefix), in set order; null without one. */
    public function findNewestBriefingMessage(): ?AIConversationMessage
    {
        foreach (array_reverse($this->rows) as $tandemMessage) {
            if ($tandemMessage->isSupervisorCheckpointBriefing()) {
                return $tandemMessage;
            }
        }
        return null;
    }

    /**
     * The decision rows NEWER than the tandem row `$afterMessageId` (stored rows only — a row without an id never
     * counts): terminal calls and legacy verdict texts, in set order.
     *
     * @return AIConversationMessage[]
     */
    public function getDecisionMessagesAfterMessageId(int $afterMessageId): array
    {
        $decisionMessages = [];
        foreach ($this->rows as $tandemMessage) {
            if (!isset($tandemMessage->id) || (int)$tandemMessage->id <= $afterMessageId || !$tandemMessage->isSupervisorCheckpointDecision()) {
                continue;
            }
            $decisionMessages[] = $tandemMessage;
        }
        return $decisionMessages;
    }

    /**
     * The supervised message index the LAST checkpoint covered: the `asOfMessageIndex:` of the newest USER row that
     * carries one (-1 without one, so the first beat spans the whole supervised conversation).
     */
    public function getNewestCoveredMessageIndex(): int
    {
        $newestCoveredMessageIndex = -1;
        foreach ($this->rows as $tandemMessage) {
            $asOfMessageIndex = SupervisorCheckpointBriefingHeader::parseFrom($tandemMessage)?->asOfMessageIndex;
            if ($asOfMessageIndex !== null) {
                $newestCoveredMessageIndex = $asOfMessageIndex;
            }
        }
        return $newestCoveredMessageIndex;
    }

    /**
     * The step beats the tandem already ran for the supervised owner turn that started at `$turnInitiatorIndex`: USER rows
     * with a `beat: step` line whose covered index is at or after it.
     */
    public function countStepBeatsSinceMessageIndex(int $turnInitiatorIndex): int
    {
        $stepBeatCount = 0;
        foreach ($this->rows as $tandemMessage) {
            $briefingHeader = SupervisorCheckpointBriefingHeader::parseFrom($tandemMessage);
            if ($briefingHeader === null || !$briefingHeader->isStepBeat() || $briefingHeader->asOfMessageIndex === null) {
                continue;
            }
            if ($briefingHeader->asOfMessageIndex >= $turnInitiatorIndex) {
                $stepBeatCount++;
            }
        }
        return $stepBeatCount;
    }

    /**
     * How many checkpoints count against the run cap: every decision row (a terminal call, or a legacy verdict text) under a
     * SETTLED briefing — step beats are capped per owner turn instead and never consume the cap. A briefing without a
     * beat line (a legacy briefing) is settled.
     */
    public function countSettledDecisions(): int
    {
        $settledDecisionCount = 0;
        $currentBeat = SupervisorCheckpointBriefingHeader::BEAT_SETTLED;
        foreach ($this->rows as $tandemMessage) {
            if (($tandemMessage->role ?? null) === AIConversationMessage::ROLE_USER) {
                if ($tandemMessage->isSupervisorCheckpointBriefing()) {
                    $currentBeat = SupervisorCheckpointBriefingHeader::parseFrom($tandemMessage)->beat ?? SupervisorCheckpointBriefingHeader::BEAT_SETTLED;
                }
                continue;
            }
            if ($currentBeat === SupervisorCheckpointBriefingHeader::BEAT_SETTLED && $tandemMessage->isSupervisorCheckpointDecision()) {
                $settledDecisionCount++;
            }
        }
        return $settledDecisionCount;
    }

    /**
     * Whether an ACCEPTED owner-visible `correct` on the same record already stands anywhere in this tandem — the owner
     * reads one correction per record; a further fix of it is made silently. An empty record key has none.
     */
    public function hasAcceptedCorrectionOnRecord(string $recordKey): bool
    {
        if ($recordKey === '') {
            return false;
        }
        foreach ($this->rows as $tandemMessage) {
            $earlierCall = $tandemMessage->toolCall ?? null;
            if ($earlierCall !== null
                && (string)($earlierCall->name ?? '') === SupervisorTools::CORRECT
                && SupervisorDecisionToolArguments::wasAccepted($earlierCall)
                && SupervisorDecisionToolArguments::buildTopicRecordKey((string)($earlierCall->arguments['topic'] ?? '')) === $recordKey
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an ACCEPTED intervention on the same finding already stands, so a new one would repeat it:
     *  - NON-CONVERGENCE (a measured run had eight interventions on the goal horizon, each under a new topic name): an
     *    intervention sharing a criteria code with the same expected tool was accepted twice anywhere in the tandem —
     *    two attempts that did not hold mean the next one will not;
     *  - the same RECORD (the topic's record key) in the current supervised interaction (decisions under a briefing that
     *    covered an index at or after `$interactionStartIndex`) — unless the codes are DISJOINT (another finding on the
     *    record), or the earlier intervention's expected tool never ran on the supervised conversation after the
     *    briefing it stood under (it was not executed: it is still owed).
     */
    public function hasInterventionOnTheSameFinding(SupervisorDecisionToolArguments $decisionToolArguments, int $interactionStartIndex, ?AIConversation $supervisedConversation): bool
    {
        $newCodes = $decisionToolArguments->getViolatedCodes();
        if ($newCodes !== [] && $this->countAcceptedInterventionsSharingACode($newCodes, $decisionToolArguments->getExpectedTool()) >= 2) {
            return true;
        }
        $normalizedTopic = $decisionToolArguments->getTopicRecordKey();
        if ($normalizedTopic === '') {
            return false;
        }
        $currentAsOf = -1;
        foreach ($this->rows as $tandemMessage) {
            $asOfMessageIndex = SupervisorCheckpointBriefingHeader::parseFrom($tandemMessage)?->asOfMessageIndex;
            if ($asOfMessageIndex !== null) {
                $currentAsOf = $asOfMessageIndex;
                continue;
            }
            $toolCall = $tandemMessage->toolCall ?? null;
            if ($toolCall === null || $currentAsOf < $interactionStartIndex || !SupervisorDecisionToolArguments::wasAccepted($toolCall)) {
                continue;
            }
            $earlierDecisionToolArguments = SupervisorDecisionToolArguments::fromPersistedToolCall($toolCall);
            if (!$earlierDecisionToolArguments->isIntervention() || $earlierDecisionToolArguments->getTopicRecordKey() !== $normalizedTopic) {
                continue;
            }
            $earlierCodes = $earlierDecisionToolArguments->getViolatedCodes();
            if ($earlierCodes !== [] && $newCodes !== [] && array_intersect($earlierCodes, $newCodes) === []) {
                continue; // the same record, another finding
            }
            // A steer whose expected tool NEVER ran on the supervised conversation after its decision was not
            // executed (batch sv6: forget without the Memory skill) — it may be repeated.
            $earlierExpectedTool = $earlierDecisionToolArguments->getExpectedTool();
            if ($earlierExpectedTool !== '' && $supervisedConversation !== null) {
                $newestCallIndex = $supervisedConversation->messages->buildToolCallHistory()->getNewestResolvedToolCallIndexByToolName($earlierExpectedTool) ?? -1;
                if ($newestCallIndex <= $currentAsOf) {
                    continue; // not executed yet → not a duplicate, the intervention is still owed
                }
            }
            return true;
        }
        return false;
    }

    /**
     * The ACCEPTED interventions anywhere in the tandem with this expected tool that share a criteria code with `$codes`.
     *
     * @param string[] $codes the new decision's codes, trimmed and upper-cased, none empty
     */
    protected function countAcceptedInterventionsSharingACode(array $codes, string $expectedTool): int
    {
        $sameFindingCount = 0;
        foreach ($this->rows as $tandemMessage) {
            $earlierCall = $tandemMessage->toolCall ?? null;
            if ($earlierCall === null || !SupervisorDecisionToolArguments::wasAccepted($earlierCall)) {
                continue;
            }
            $earlierDecisionToolArguments = SupervisorDecisionToolArguments::fromPersistedToolCall($earlierCall);
            if (!$earlierDecisionToolArguments->isIntervention() || $earlierDecisionToolArguments->getExpectedTool() !== trim($expectedTool)) {
                continue;
            }
            if (array_intersect($earlierDecisionToolArguments->getViolatedCodes(), $codes) !== []) {
                $sameFindingCount++;
            }
        }
        return $sameFindingCount;
    }

    /**
     * The tandem's CONTEXT PROJECTION: before a checkpoint's model calls, every
     * EARLIER briefing is cut to its header plus the static block of the first one (the contract, the tool digest — the
     * cache prefix), and every earlier lookup result becomes a stub; the decisions stay whole. IN MEMORY ONLY — the
     * persisted rows keep everything for the admin console and the replay (nothing re-persists a row that has an id).
     * Without it the tandem wire grew past 70k tokens by the 17th checkpoint (batch sv5). Returns the number of rows
     * projected.
     */
    public function projectEarlierCheckpointsForTheModel(): int
    {
        $newestBriefingIndex = null;
        $firstBriefingIndex = null;
        foreach ($this->rows as $tandemMessage) {
            if ($tandemMessage->isSupervisorCheckpointBriefing()) {
                $index = (int)($tandemMessage->indexInConversation ?? 0);
                $newestBriefingIndex = max($newestBriefingIndex ?? $index, $index);
                $firstBriefingIndex = min($firstBriefingIndex ?? $index, $index);
            }
        }
        if ($newestBriefingIndex === null) {
            return 0;
        }
        $projectedRowCount = 0;
        foreach ($this->rows as $tandemMessage) {
            $index = (int)($tandemMessage->indexInConversation ?? 0);
            if ($index >= $newestBriefingIndex || !isset($tandemMessage->id)) {
                continue; // the newest briefing and anything after it ride whole; a transient row is never a stub
            }
            if ($tandemMessage->isSupervisorCheckpointBriefing()) {
                $briefingText = (string)($tandemMessage->message ?? '');
                $deltaStart = strpos($briefingText, self::BRIEFING_DELTA_START);
                if ($deltaStart === false) {
                    continue;
                }
                $head = $index === $firstBriefingIndex
                    ? rtrim(substr($briefingText, 0, $deltaStart)) // the first briefing keeps its static block
                    : (SupervisorCheckpointBriefingHeader::parseFromText($briefingText)?->renderProjectedHead() ?? '');
                $tandemMessage->message = rtrim($head) . "\n\n" . self::EARLIER_BRIEFING_STUB;
                $projectedRowCount++;
                continue;
            }
            $toolCall = $tandemMessage->toolCall ?? null;
            if ($toolCall !== null && SupervisorTools::isLookupToolName((string)($toolCall->name ?? '')) && (string)($toolCall->result ?? '') !== self::EARLIER_LOOKUP_STUB) {
                $toolCall->result = self::EARLIER_LOOKUP_STUB;
                $projectedRowCount++;
            }
        }
        return $projectedRowCount;
    }
}
