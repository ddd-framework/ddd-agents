<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAgentTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAwaitedRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateToolArguments;
use DDD\Domain\AI\Entities\Conversations\Messages\DelegationResults;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * ONE sub-agent, stored on its `delegate` call row under the key `subAgent` of the row's
 * `delegationResults` column: the conversation-unique number the model addresses it by, its label and task, who asked
 * for it, and its runs. Every state change is a guarded write of ONE run; nothing is reset. A LEGACY record is read from
 * an old multi-task row ({@see DelegationResults::buildSubAgentRecords()}): it has no number and cannot be addressed.
 *
 * @phpstan-consistent-constructor
 */
class DelegationSubAgentRecord extends ValueObject
{
    /** The longest verdict a status line shows (the drill-down shows it whole). */
    public const int STATUS_LINE_VERDICT_MAX_LENGTH = 300;

    /** The conversation-unique number (`#3`); null on a legacy record. */
    public ?int $number = null;

    /** At most 40 characters. */
    public string $label = '';

    /** The one task; null for a call refused because it named no task shape. */
    public ?AgentDelegationTask $task = null;

    /** The child conversation; null when the run never started (NOT_RUN) or on a legacy sync row (no child id stored). */
    public ?int $childConversationId = null;

    /** One of AgentSubAgentRequest::REQUESTED_BY_*; null on a legacy record. */
    public ?string $requestedBy = null;

    /** The tool call whose result asked for the sub-agent. */
    public ?string $requestedByToolCallId = null;

    /** The name of that tool. */
    public ?string $requestedByToolName = null;

    /** The requester's next step, shown once when the last sub-agent of the request ended (the tool-result key `delegationNextStepWhenAllSubAgentsEnded`). */
    public ?string $nextStepWhenAllSubAgentsOfRequestEnded = null;

    /** The run ends are told to the parent without resuming it. */
    public bool $notifiesParentWithoutResuming = false;

    /** The run the sub-agent is on. */
    public int $currentRunNumber = 1;

    /** Its runs, keyed by run number. */
    public DelegationSubAgentRuns $runs;

    public function __construct()
    {
        parent::__construct();
        $this->runs = new DelegationSubAgentRuns();
    }

    /** A spawned sub-agent: run 1 QUEUED (the admission makes it RUNNING). */
    public static function queued(int $number, AgentSubAgentRequest $agentSubAgentRequest, int $childConversationId, DateTime $queuedAt): self
    {
        $delegationSubAgentRecord = self::fromRequest($number, $agentSubAgentRequest);
        $delegationSubAgentRecord->childConversationId = $childConversationId;
        $delegationSubAgentRun = DelegationSubAgentRun::queued(1, $queuedAt);
        $delegationSubAgentRecord->runs->add($delegationSubAgentRun);
        return $delegationSubAgentRecord;
    }

    /** A sub-agent the spawn refused (skill-less under a rail, an earlier sub-agent of the step failed, …): no child. */
    public static function notRun(int $number, AgentSubAgentRequest $agentSubAgentRequest, string $failureReason, string $corrective, DateTime $now): self
    {
        $delegationSubAgentRecord = self::fromRequest($number, $agentSubAgentRequest);
        $delegationSubAgentRun = DelegationSubAgentRun::notRun($failureReason, $corrective, $now);
        $delegationSubAgentRecord->runs->add($delegationSubAgentRun);
        return $delegationSubAgentRecord;
    }

    /** The model's call that named no task shape: refused, no task. */
    public static function notRunWithoutTask(int $number, DelegateToolArguments $delegateToolArguments, DateTime $now): static
    {
        $delegationSubAgentRecord = new static();
        $delegationSubAgentRecord->number = $number;
        $delegationSubAgentRecord->label = $delegateToolArguments->label ?? 'delegate';
        $delegationSubAgentRecord->requestedBy = AgentSubAgentRequest::REQUESTED_BY_MODEL;
        $delegationSubAgentRun = DelegationSubAgentRun::notRun(
            DelegationSubAgentRun::FAILURE_REASON_REFUSED,
            (string)$delegateToolArguments->buildTaskShapeCorrective(),
            $now
        );
        $delegationSubAgentRecord->runs->add($delegationSubAgentRun);
        return $delegationSubAgentRecord;
    }

    /** A read-only record of an old multi-task call: one run, COMPLETED with the stored answer or NO_RESULT_RECORDED. */
    public static function legacy(AgentDelegationTask $task, ?int $childConversationId, ?string $verdict, ?float $costInUsd): static
    {
        $delegationSubAgentRecord = new static();
        $delegationSubAgentRecord->label = $task->buildDefaultLabel();
        $delegationSubAgentRecord->task = $task;
        $delegationSubAgentRecord->childConversationId = $childConversationId;
        $delegationSubAgentRun = new DelegationSubAgentRun();
        $delegationSubAgentRun->status = $verdict === null ? DelegationSubAgentRun::STATUS_NO_RESULT_RECORDED : DelegationSubAgentRun::STATUS_COMPLETED;
        $delegationSubAgentRun->verdict = $verdict;
        $delegationSubAgentRun->costInUsd = $costInUsd;
        $delegationSubAgentRecord->runs->add($delegationSubAgentRun);
        return $delegationSubAgentRecord;
    }

    /** @param array<string, mixed> $storedRecord */
    public static function fromStoredArray(array $storedRecord): static
    {
        $delegationSubAgentRecord = new static();
        $delegationSubAgentRecord->number = is_numeric($storedRecord['number'] ?? null) ? (int)$storedRecord['number'] : null;
        $delegationSubAgentRecord->label = is_string($storedRecord['label'] ?? null) ? $storedRecord['label'] : '';
        $delegationSubAgentRecord->task = is_array($storedRecord['task'] ?? null) ? AgentDelegationTask::fromStoredTaskArray($storedRecord['task']) : null;
        $delegationSubAgentRecord->childConversationId = is_numeric($storedRecord['childConversationId'] ?? null) ? (int)$storedRecord['childConversationId'] : null;
        $delegationSubAgentRecord->requestedBy = is_string($storedRecord['requestedBy'] ?? null) ? $storedRecord['requestedBy'] : null;
        $delegationSubAgentRecord->requestedByToolCallId = is_string($storedRecord['requestedByToolCallId'] ?? null) ? $storedRecord['requestedByToolCallId'] : null;
        $delegationSubAgentRecord->requestedByToolName = is_string($storedRecord['requestedByToolName'] ?? null) ? $storedRecord['requestedByToolName'] : null;
        $delegationSubAgentRecord->nextStepWhenAllSubAgentsOfRequestEnded = is_string($storedRecord['nextStepWhenAllSubAgentsOfRequestEnded'] ?? null)
            ? $storedRecord['nextStepWhenAllSubAgentsOfRequestEnded'] : null;
        $delegationSubAgentRecord->notifiesParentWithoutResuming = ($storedRecord['notifiesParentWithoutResuming'] ?? false) === true;
        $delegationSubAgentRecord->currentRunNumber = is_numeric($storedRecord['currentRunNumber'] ?? null) ? (int)$storedRecord['currentRunNumber'] : 1;
        $delegationSubAgentRecord->runs = DelegationSubAgentRuns::fromStoredArray(is_array($storedRecord['runs'] ?? null) ? $storedRecord['runs'] : []);
        return $delegationSubAgentRecord;
    }

    /** @return array<string, mixed> the stored record, empty fields left out */
    public function mapToRepository(): array
    {
        $storedRecord = [];
        $fields = [
            'number' => $this->number,
            'label' => $this->label,
            'task' => $this->task?->buildStoredTaskArray(),
            'childConversationId' => $this->childConversationId,
            'requestedBy' => $this->requestedBy,
            'requestedByToolCallId' => $this->requestedByToolCallId,
            'requestedByToolName' => $this->requestedByToolName,
            'nextStepWhenAllSubAgentsOfRequestEnded' => $this->nextStepWhenAllSubAgentsOfRequestEnded,
            'notifiesParentWithoutResuming' => $this->notifiesParentWithoutResuming,
            'currentRunNumber' => $this->currentRunNumber,
            'runs' => $this->runs->mapToRepository(),
        ];
        foreach ($fields as $fieldName => $fieldValue) {
            if ($fieldValue !== null) {
                $storedRecord[$fieldName] = $fieldValue;
            }
        }
        return $storedRecord;
    }

    public function isLegacy(): bool
    {
        return $this->number === null;
    }

    public function isToolRun(): bool
    {
        return $this->task instanceof AgentDelegationToolRunTask;
    }

    public function getCurrentRun(): ?DelegationSubAgentRun
    {
        return $this->runs->getRun($this->currentRunNumber);
    }

    /** The current run is QUEUED or RUNNING. */
    public function hasUnfinishedRun(): bool
    {
        $currentRun = $this->getCurrentRun();
        return $currentRun !== null && !$currentRun->hasEnded();
    }

    /**
     * Whether this sub-agent makes a new tool run of `$toolRunTask` redundant: it runs the same call
     * ({@see AgentDelegationToolRunTask::isSameCallAs()}) and its current run is still QUEUED or RUNNING, or it ran the
     * same input ({@see AgentDelegationToolRunTask::hasSameInputAs()}) and its current run COMPLETED. A failed,
     * timed-out, cancelled or refused run makes nothing redundant.
     */
    public function makesToolRunRedundant(AgentDelegationToolRunTask $toolRunTask): bool
    {
        if (!$this->task instanceof AgentDelegationToolRunTask || !$this->task->isSameCallAs($toolRunTask)) {
            return false;
        }
        if ($this->hasUnfinishedRun()) {
            return true;
        }
        return $this->getCurrentRun()?->status === DelegationSubAgentRun::STATUS_COMPLETED && $this->task->hasSameInputAs($toolRunTask);
    }

    /** The spawn refused the sub-agent (its current run is NOT_RUN). */
    public function isNotRun(): bool
    {
        return $this->getCurrentRun()?->status === DelegationSubAgentRun::STATUS_NOT_RUN;
    }

    /** `#3 «Services page»` (a legacy record: `«…»` only). */
    public function buildName(): string
    {
        $quotedLabel = '«' . $this->label . '»';
        return $this->number === null ? $quotedLabel : '#' . $this->number . ' ' . $quotedLabel;
    }

    /**
     * The record's head line for an operator's view (the CLI inspector): `#3 «Services page» · agent task "…" (skills:
     * Web.Search) · child #812345 · requested by MODEL`. The task text is cut to `$taskTextMaxLength` characters.
     */
    public function buildOperatorHeadLine(int $taskTextMaxLength = 120): string
    {
        $task = $this->task;
        $taskText = match (true) {
            $task instanceof AgentDelegationToolRunTask => 'tool run `' . $task->toolName . '`',
            $task instanceof AgentDelegationAwaitedRunTask => 'awaited conversation #' . $task->awaitedConversationId,
            $task instanceof AgentDelegationAgentTask => 'agent task "' . self::cutText($task->taskText, $taskTextMaxLength) . '"'
                . ($task->skillNames === [] ? '' : ' (skills: ' . implode(', ', $task->skillNames) . ')'),
            default => 'no task (refused at the spawn)',
        };
        $headLineParts = [$this->buildName(), $taskText];
        $headLineParts[] = $this->childConversationId !== null ? 'child #' . $this->childConversationId : 'no child';
        if ($this->requestedBy !== null) {
            $headLineParts[] = 'requested by ' . $this->requestedBy . ($this->requestedByToolName !== null ? ' (' . $this->requestedByToolName . ')' : '');
        }
        if ($this->notifiesParentWithoutResuming) {
            $headLineParts[] = 'its ends do not resume the parent';
        }
        return implode(' · ', $headLineParts);
    }

    protected static function cutText(string $text, int $maxLength): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        return mb_strlen($text) <= $maxLength ? $text : mb_substr($text, 0, $maxLength - 1) . '…';
    }

    /**
     * Why `message_sub_agent` cannot reach this sub-agent; null when the message is delivered (an
     * unfinished run) or opens a continuation run (an ended one).
     */
    public function findRefusalOfMessage(): ?string
    {
        if ($this->isLegacy()) {
            return 'This sub-agent belongs to an earlier delegation without sub-agent numbers — it takes no messages. '
                . 'Start a new delegate call.';
        }
        if ($this->isNotRun()) {
            return 'Sub-agent #' . $this->number . ' never started ('
                . ($this->getCurrentRun()?->failureReason ?? DelegationSubAgentRun::FAILURE_REASON_REFUSED)
                . ') — start a new one with delegate.';
        }
        if ($this->task instanceof AgentDelegationToolRunTask) {
            return 'Sub-agent #' . $this->number . ' is a tool run of `' . $this->task->toolName
                . '` — it takes no messages. Start a new delegate call.';
        }
        if ($this->task instanceof AgentDelegationAwaitedRunTask) {
            return 'Sub-agent #' . $this->number . ' is conversation #' . $this->task->awaitedConversationId
                . ', which runs on its own — it takes no messages. Its end arrives as a notification.';
        }
        return null;
    }

    /**
     * The current run has ended and its end was told to the parent — or it never ran (a refusal has no notification).
     * The notification of the LAST run end of a request names the request's next step; this is how it knows the others
     * were told already ({@see DelegationRunEndNotification::buildFor()}).
     */
    public function hasCurrentRunEndedAndBeenReported(): bool
    {
        $currentRun = $this->getCurrentRun();
        if ($currentRun === null || !$currentRun->hasEnded()) {
            return false;
        }
        return $currentRun->reportedToParentAt !== null || $currentRun->status === DelegationSubAgentRun::STATUS_NOT_RUN;
    }

    /**
     * The `delegate` call's result, fixed at the spawn: started, queued behind the capacity
     * cap, or the refusal corrective.
     *
     * @param int|null $occupiedSlotCountWhenQueued null = a slot was free at the spawn; else the sub-agents already running
     */
    public function buildSpawnAnswerText(?int $occupiedSlotCountWhenQueued): string
    {
        if ($this->isNotRun()) {
            return (string)$this->getCurrentRun()?->failureDetail;
        }
        $spawnState = $occupiedSlotCountWhenQueued === null
            ? 'started (running)'
            : 'queued (' . $occupiedSlotCountWhenQueued . ' sub-agents already run in this conversation)';
        return 'Sub-agent ' . $this->buildName() . ' ' . $spawnState . '. Its result arrives as a notification when it ends.';
    }

    /**
     * The provisional answer of a platform `delegate` row whose child waits for the admission of its request (plan
     * 103b-7): written with the row, so a process that stops before the admission leaves a valid tool result — the
     * child stays queued, and its end arrives as a notification. The admission replaces it with the spawn answer.
     */
    public function buildAnswerTextBeforeAdmission(): string
    {
        return 'Sub-agent ' . $this->buildName() . ' queued. Its result arrives as a notification when it ends.';
    }

    /**
     * The fixed answer of a `delegate` call whose sub-agent ran inline (the sync path): its run has
     * already ended when the call is answered, so the answer states the end — the result follows as the notification
     * appended after the step's calls.
     */
    public function buildInlineRunAnswerText(): string
    {
        $endedRun = $this->getCurrentRun();
        $endState = (string)($endedRun?->status ?? DelegationSubAgentRun::STATUS_FAILED);
        if ($endedRun !== null && $endedRun->failureReason !== null) {
            $endState .= ' (' . $endedRun->failureReason . ')';
        }
        return 'Sub-agent ' . $this->buildName() . ' ran and ended ' . $endState . '. Its result follows as a notification.';
    }

    /**
     * The record's line in the `check_delegations` report, without the live look of a running child:
     * the current run's status, times and verdict or reason, then the older runs in one line each.
     */
    public function buildStatusLine(DateTime $now): string
    {
        $currentRun = $this->getCurrentRun();
        if ($currentRun === null) {
            return $this->buildName() . ' — no run recorded';
        }
        $statusLine = $this->buildName() . ' — ' . $this->buildCurrentRunState($currentRun, $now);
        $olderRunLines = [];
        foreach ($this->runs->getRunsBefore($currentRun->runNumber) as $olderRun) {
            $olderRunLines[] = $olderRun->buildRunEndLine();
        }
        return $olderRunLines === [] ? $statusLine : $statusLine . '; ' . implode('; ', $olderRunLines);
    }

    /**
     * The pure twin of the run-end write: a copy whose current run ended with the given
     * status, verdict, reason and cost. The record itself stays unchanged; an already ended run stays as it is.
     */
    public function withRunEnded(
        string $terminalStatus,
        ?string $verdict,
        ?string $failureReason,
        ?string $failureDetail,
        ?float $costInUsd,
        DateTime $finishedAt
    ): self {
        $recordWithRunEnded = self::fromStoredArray($this->mapToRepository());
        $endedRun = $recordWithRunEnded->getCurrentRun();
        if ($endedRun === null || $endedRun->hasEnded()) {
            return $recordWithRunEnded;
        }
        $endedRun->status = $terminalStatus;
        $endedRun->finishedAt = $finishedAt;
        $endedRun->verdict = $verdict;
        $endedRun->failureReason = $failureReason;
        $endedRun->failureDetail = DelegationSubAgentRun::cutFailureDetail($failureDetail);
        $endedRun->costInUsd = $costInUsd;
        return $recordWithRunEnded;
    }

    /**
     * The spend of the current run (the stored `costInUsd` = THIS run's): the child's whole-subtree spend at the
     * run's end minus what the earlier runs already recorded — a continuation's child carries the earlier runs' work too,
     * and the parent's cost totals add up every run. Never below zero.
     */
    public function buildCostOfCurrentRun(float $childTotalCostInUsd): float
    {
        $earlierRunsCostInUsd = 0.0;
        foreach ($this->runs->getRunsBefore($this->currentRunNumber) as $earlierRun) {
            $earlierRunsCostInUsd += (float)($earlierRun->costInUsd ?? 0.0);
        }
        return max(0.0, $childTotalCostInUsd - $earlierRunsCostInUsd);
    }

    /**
     * A copy whose current run is the continuation `$continuationRun` — the pure twin of the record write
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::openSubAgentContinuationRunRecord()}:
     * only when the current run ended with a recorded end and `$continuationRun` is the next run number; otherwise the
     * copy is unchanged. The record itself stays unchanged.
     */
    public function withContinuationRun(DelegationSubAgentRun $continuationRun): self
    {
        $recordWithContinuation = self::fromStoredArray($this->mapToRepository());
        $currentRun = $recordWithContinuation->getCurrentRun();
        if ($currentRun === null || !$currentRun->isRecordedRunEnd() || $continuationRun->runNumber !== $recordWithContinuation->currentRunNumber + 1) {
            return $recordWithContinuation;
        }
        $continuationRunCopy = DelegationSubAgentRun::fromStoredArray($continuationRun->mapToRepository());
        $recordWithContinuation->runs->add($continuationRunCopy);
        $recordWithContinuation->currentRunNumber = $continuationRun->runNumber;
        return $recordWithContinuation;
    }

    protected function buildCurrentRunState(DelegationSubAgentRun $currentRun, DateTime $now): string
    {
        $runSeconds = $currentRun->getRunSeconds($now);
        if (!$currentRun->hasEnded()) {
            $runFacts = ['run ' . $currentRun->runNumber];
            $continuedByText = $currentRun->buildContinuedByText();
            if ($continuedByText !== null) {
                $runFacts[] = $continuedByText . ($currentRun->openingMessage !== null ? ': "' . $currentRun->openingMessage . '"' : '');
            }
            $runFor = $runSeconds === null ? '' : ' for ' . DelegationSubAgentRun::formatDuration($runSeconds);
            return $currentRun->status . $runFor . ' (' . implode(', ', $runFacts) . ')';
        }
        if ($currentRun->status === DelegationSubAgentRun::STATUS_NOT_RUN) {
            return 'NOT RUN (' . ($currentRun->failureReason ?? DelegationSubAgentRun::FAILURE_REASON_REFUSED) . ')';
        }
        $endedAgo = $currentRun->finishedAt === null
            ? ''
            : ' ' . DelegationSubAgentRun::formatDuration(max(0, $now->getTimestamp() - $currentRun->finishedAt->getTimestamp())) . ' ago';
        $runFacts = 'run ' . $currentRun->runNumber
            . ($runSeconds === null || $currentRun->finishedAt === null ? '' : ', ' . DelegationSubAgentRun::formatDuration($runSeconds));
        $runState = $currentRun->getModelFacingStatus() . $endedAgo . ' (' . $runFacts . ')';
        $failureText = $currentRun->buildFailureText();
        if ($failureText !== null) {
            return $runState . ': ' . $failureText;
        }
        $verdict = trim((string)$currentRun->verdict);
        if ($verdict === '') {
            return $runState . '.';
        }
        $verdictInLine = mb_strlen($verdict) <= self::STATUS_LINE_VERDICT_MAX_LENGTH
            ? $verdict
            : mb_substr($verdict, 0, self::STATUS_LINE_VERDICT_MAX_LENGTH) . ' …';
        return $runState . '. Result: ' . str_replace("\n", ' ', $verdictInLine);
    }

    protected static function fromRequest(int $number, AgentSubAgentRequest $agentSubAgentRequest): static
    {
        $delegationSubAgentRecord = new static();
        $delegationSubAgentRecord->number = $number;
        $delegationSubAgentRecord->label = $agentSubAgentRequest->buildRecordLabel();
        $delegationSubAgentRecord->task = $agentSubAgentRequest->task;
        $delegationSubAgentRecord->requestedBy = $agentSubAgentRequest->requestedBy;
        $delegationSubAgentRecord->requestedByToolCallId = $agentSubAgentRequest->requestedByToolCallId;
        $delegationSubAgentRecord->requestedByToolName = $agentSubAgentRequest->requestedByToolName;
        $delegationSubAgentRecord->nextStepWhenAllSubAgentsOfRequestEnded = $agentSubAgentRequest->nextStepWhenAllSubAgentsOfRequestEnded;
        $delegationSubAgentRecord->notifiesParentWithoutResuming = $agentSubAgentRequest->notifiesParentWithoutResuming;
        return $delegationSubAgentRecord;
    }
}
