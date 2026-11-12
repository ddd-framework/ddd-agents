<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One run of a sub-agent: the first run starts with the spawn, every continuation (`message_sub_agent`,
 * admin Continue, the CLI) is a new run; nothing of an earlier run is overwritten. Its status follows the child's run
 * state: QUEUED (no slot yet) → RUNNING (admitted) → COMPLETED / FAILED / CANCELLED / TIMED_OUT; NOT_RUN = the spawn
 * refused it. NO_RESULT_RECORDED exists on legacy records only (a child of an old multi-task call that wrote no answer).
 * Stored inside the record's `runs` map; {@see mapToRepository()} omits empty fields.
 *
 * @phpstan-consistent-constructor
 */
class DelegationSubAgentRun extends ValueObject
{
    public const string STATUS_QUEUED = 'QUEUED';

    public const string STATUS_RUNNING = 'RUNNING';

    public const string STATUS_COMPLETED = 'COMPLETED';

    public const string STATUS_FAILED = 'FAILED';

    public const string STATUS_CANCELLED = 'CANCELLED';

    public const string STATUS_TIMED_OUT = 'TIMED_OUT';

    public const string STATUS_NOT_RUN = 'NOT_RUN';

    /** A legacy child of an old multi-task call without an answer in the call's map — never "running". */
    public const string STATUS_NO_RESULT_RECORDED = 'NO_RESULT_RECORDED';

    /** @var string[] the statuses of a run that has ended */
    public const array ENDED_STATUSES = [
        self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_TIMED_OUT, self::STATUS_NOT_RUN,
        self::STATUS_NO_RESULT_RECORDED,
    ];

    /** @var string[] the statuses a run of a started sub-agent ends with — the ones a continuation run may follow */
    public const array RECORDED_RUN_END_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_TIMED_OUT];

    /** @var string[] the statuses of a run that has not ended */
    public const array UNFINISHED_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    /** @var string[] the statuses a failure reason belongs to */
    public const array FAILED_STATUSES = [self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_TIMED_OUT, self::STATUS_NOT_RUN];

    public const string FAILURE_REASON_CHILD_FAILED = 'childFailed';

    public const string FAILURE_REASON_TIMED_OUT = 'timedOut';

    public const string FAILURE_REASON_CANCELLED_BY_OWNER = 'cancelledByOwner';

    public const string FAILURE_REASON_SUPERSEDED = 'superseded';

    public const string FAILURE_REASON_REFUSED = 'refused';

    public const string FAILURE_REASON_BUDGET_REACHED = 'budgetReached';

    public const string FAILURE_REASON_PROVIDER_ERROR = 'providerError';

    public const string FAILURE_REASON_EARLIER_SUB_AGENT_FAILED = 'earlierSubAgentFailed';

    /** @var string[] the closed set of failure reasons */
    public const array FAILURE_REASONS = [
        self::FAILURE_REASON_CHILD_FAILED, self::FAILURE_REASON_TIMED_OUT, self::FAILURE_REASON_CANCELLED_BY_OWNER,
        self::FAILURE_REASON_SUPERSEDED, self::FAILURE_REASON_REFUSED, self::FAILURE_REASON_BUDGET_REACHED,
        self::FAILURE_REASON_PROVIDER_ERROR, self::FAILURE_REASON_EARLIER_SUB_AGENT_FAILED,
    ];

    /** The longest free-text failure detail a run keeps. */
    public const int FAILURE_DETAIL_MAX_LENGTH = 300;

    /** The longest opening message a continuation run keeps (the child holds the whole message). */
    public const int OPENING_MESSAGE_MAX_LENGTH = 300;

    public const string CONTINUED_BY_PARENT = 'parent';

    public const string CONTINUED_BY_ADMIN = 'admin';

    public const string CONTINUED_BY_OWNER = 'owner';

    public const string CONTINUED_BY_CLI = 'cli';

    /** The run's number on its record, from 1. */
    public int $runNumber = 1;

    /** One of the STATUS_* constants. */
    public string $status = self::STATUS_QUEUED;

    public ?DateTime $queuedAt = null;

    /** The child was admitted (a slot was free). */
    public ?DateTime $startedAt = null;

    public ?DateTime $finishedAt = null;

    /** The child's answer of this run (a tool run: the tool's result). */
    public ?string $verdict = null;

    /** One of FAILURE_REASONS for a failed, cancelled, timed-out or refused run. */
    public ?string $failureReason = null;

    /** Free text beside the reason (the exception text, the refusal corrective). */
    public ?string $failureDetail = null;

    /** The child's whole-subtree spend of THIS run in USD. */
    public ?float $costInUsd = null;

    /** The one notification of this run's end was written into the parent. */
    public ?DateTime $reportedToParentAt = null;

    /** Who opened this continuation run (CONTINUED_BY_*); null for run 1. */
    public ?string $continuedBy = null;

    /** The message that opened this continuation run. */
    public ?string $openingMessage = null;

    public static function queued(int $runNumber, DateTime $queuedAt): static
    {
        $delegationSubAgentRun = new static();
        $delegationSubAgentRun->runNumber = $runNumber;
        $delegationSubAgentRun->status = self::STATUS_QUEUED;
        $delegationSubAgentRun->queuedAt = $queuedAt;
        return $delegationSubAgentRun;
    }

    /** A run the spawn refused: it ended at the moment it was asked for. */
    public static function notRun(string $failureReason, string $corrective, DateTime $now): static
    {
        $delegationSubAgentRun = new static();
        $delegationSubAgentRun->status = self::STATUS_NOT_RUN;
        $delegationSubAgentRun->queuedAt = $now;
        $delegationSubAgentRun->finishedAt = $now;
        $delegationSubAgentRun->failureReason = $failureReason;
        $delegationSubAgentRun->failureDetail = $corrective;
        return $delegationSubAgentRun;
    }

    /**
     * The end of run `$runNumber` as its writer stores it (the child's end, the timeout, the supersede):
     * the terminal status, when it ended, the verdict or the failure reason, the cost. Only the end fields are set — the
     * writer keeps the fields the earlier transitions wrote. The free-text detail is cut to
     * {@see FAILURE_DETAIL_MAX_LENGTH}.
     */
    public static function ended(
        int $runNumber,
        string $terminalStatus,
        DateTime $finishedAt,
        ?string $verdict,
        ?string $failureReason,
        ?string $failureDetail,
        ?float $costInUsd
    ): static {
        $delegationSubAgentRun = new static();
        $delegationSubAgentRun->runNumber = $runNumber;
        $delegationSubAgentRun->status = $terminalStatus;
        $delegationSubAgentRun->finishedAt = $finishedAt;
        $delegationSubAgentRun->verdict = $verdict;
        $delegationSubAgentRun->failureReason = $failureReason;
        $delegationSubAgentRun->failureDetail = self::cutFailureDetail($failureDetail);
        $delegationSubAgentRun->costInUsd = $costInUsd;
        return $delegationSubAgentRun;
    }

    /**
     * A continuation run: QUEUED, opened by `$continuedBy` with `$openingMessage`, cut to
     * {@see OPENING_MESSAGE_MAX_LENGTH} characters (the record shows it in the status report).
     */
    public static function continuation(int $runNumber, string $continuedBy, string $openingMessage, DateTime $queuedAt): self
    {
        $delegationSubAgentRun = self::queued($runNumber, $queuedAt);
        $delegationSubAgentRun->continuedBy = $continuedBy;
        $openingMessage = trim($openingMessage);
        $delegationSubAgentRun->openingMessage = mb_strlen($openingMessage) <= self::OPENING_MESSAGE_MAX_LENGTH
            ? $openingMessage
            : mb_substr($openingMessage, 0, self::OPENING_MESSAGE_MAX_LENGTH - 1) . '…';
        return $delegationSubAgentRun;
    }

    /** The run ended with a status a writer records (not a refusal at the spawn, not a legacy placeholder). */
    public function isRecordedRunEnd(): bool
    {
        return in_array($this->status, self::RECORDED_RUN_END_STATUSES, true);
    }

    /** @param array<string, mixed> $storedRun */
    public static function fromStoredArray(array $storedRun): static
    {
        $delegationSubAgentRun = new static();
        $delegationSubAgentRun->runNumber = is_numeric($storedRun['runNumber'] ?? null) ? (int)$storedRun['runNumber'] : 1;
        $delegationSubAgentRun->status = is_string($storedRun['status'] ?? null) ? $storedRun['status'] : self::STATUS_QUEUED;
        $delegationSubAgentRun->queuedAt = self::readStoredMoment($storedRun['queuedAt'] ?? null);
        $delegationSubAgentRun->startedAt = self::readStoredMoment($storedRun['startedAt'] ?? null);
        $delegationSubAgentRun->finishedAt = self::readStoredMoment($storedRun['finishedAt'] ?? null);
        $delegationSubAgentRun->verdict = is_string($storedRun['verdict'] ?? null) ? $storedRun['verdict'] : null;
        $delegationSubAgentRun->failureReason = is_string($storedRun['failureReason'] ?? null) ? $storedRun['failureReason'] : null;
        $delegationSubAgentRun->failureDetail = is_string($storedRun['failureDetail'] ?? null) ? $storedRun['failureDetail'] : null;
        $delegationSubAgentRun->costInUsd = is_numeric($storedRun['costInUsd'] ?? null) ? (float)$storedRun['costInUsd'] : null;
        $delegationSubAgentRun->reportedToParentAt = self::readStoredMoment($storedRun['reportedToParentAt'] ?? null);
        $delegationSubAgentRun->continuedBy = is_string($storedRun['continuedBy'] ?? null) ? $storedRun['continuedBy'] : null;
        $delegationSubAgentRun->openingMessage = is_string($storedRun['openingMessage'] ?? null) ? $storedRun['openingMessage'] : null;
        return $delegationSubAgentRun;
    }

    /** @return array<string, mixed> the stored run, empty fields left out */
    public function mapToRepository(): array
    {
        $storedRun = ['runNumber' => $this->runNumber, 'status' => $this->status];
        $optionalFields = [
            'queuedAt' => $this->queuedAt?->format(DateTime::SIMPLE),
            'startedAt' => $this->startedAt?->format(DateTime::SIMPLE),
            'finishedAt' => $this->finishedAt?->format(DateTime::SIMPLE),
            'verdict' => $this->verdict,
            'failureReason' => $this->failureReason,
            'failureDetail' => $this->failureDetail,
            'costInUsd' => $this->costInUsd,
            'reportedToParentAt' => $this->reportedToParentAt?->format(DateTime::SIMPLE),
            'continuedBy' => $this->continuedBy,
            'openingMessage' => $this->openingMessage,
        ];
        foreach ($optionalFields as $fieldName => $fieldValue) {
            if ($fieldValue !== null) {
                $storedRun[$fieldName] = $fieldValue;
            }
        }
        return $storedRun;
    }

    public function hasEnded(): bool
    {
        return in_array($this->status, self::ENDED_STATUSES, true);
    }

    /**
     * The run's line for an operator's view (the admin timeline, the CLI inspector): `run 2 COMPLETED · ended … ·
     * reported to the parent …` — the stored status word (TIMED_OUT stays TIMED_OUT), who continued it, the failure, and
     * whether its one notification reached the parent. Never the verdict (operators open the child for it).
     */
    public function buildOperatorLine(): string
    {
        $operatorLineParts = ['run ' . $this->runNumber . ' ' . $this->status];
        $continuedByText = $this->buildContinuedByText();
        if ($continuedByText !== null) {
            $operatorLineParts[] = str_replace('continued by you', 'continued by the parent', $continuedByText);
        }
        if ($this->startedAt !== null) {
            $operatorLineParts[] = 'started ' . $this->startedAt->format(DateTime::SIMPLE);
        }
        if ($this->finishedAt !== null) {
            $operatorLineParts[] = 'ended ' . $this->finishedAt->format(DateTime::SIMPLE);
        }
        $failureText = $this->buildFailureText();
        if ($failureText !== null) {
            $operatorLineParts[] = $failureText;
        }
        if ($this->hasEnded() && $this->status !== self::STATUS_NOT_RUN) {
            $operatorLineParts[] = $this->reportedToParentAt === null
                ? 'not reported to the parent yet'
                : 'reported to the parent ' . $this->reportedToParentAt->format(DateTime::SIMPLE);
        }
        if ($this->costInUsd !== null) {
            $operatorLineParts[] = sprintf('$%.4f', $this->costInUsd);
        }
        return implode(' · ', $operatorLineParts);
    }

    /** Failed, cancelled, timed out or refused — a run end without a usable result. */
    public function hasFailed(): bool
    {
        return in_array($this->status, self::FAILED_STATUSES, true);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    /** The status the model reads: a timed-out run is a FAILED run with the reason `timedOut`. */
    public function getModelFacingStatus(): string
    {
        return match ($this->status) {
            self::STATUS_TIMED_OUT => self::STATUS_FAILED,
            self::STATUS_NOT_RUN => 'NOT RUN',
            self::STATUS_NO_RESULT_RECORDED => 'ENDED WITHOUT A RECORDED RESULT',
            default => $this->status,
        };
    }

    /** The reason and detail of a failed run (`timedOut — no step for 5 min`); null for a run without a failure. */
    public function buildFailureText(): ?string
    {
        if (!$this->hasFailed()) {
            return null;
        }
        $failureReason = $this->failureReason ?? ($this->status === self::STATUS_TIMED_OUT ? self::FAILURE_REASON_TIMED_OUT : self::FAILURE_REASON_CHILD_FAILED);
        $failureDetail = trim((string)$this->failureDetail);
        return $failureDetail === '' ? $failureReason : $failureReason . ' — ' . $failureDetail;
    }

    /** How long the run worked (from its start, else from its queueing) until it ended or until `$now`; null without a start. */
    public function getRunSeconds(DateTime $now): ?int
    {
        $runStartedAt = $this->startedAt ?? $this->queuedAt;
        if ($runStartedAt === null) {
            return null;
        }
        $runEndedAt = $this->finishedAt ?? $now;
        return max(0, $runEndedAt->getTimestamp() - $runStartedAt->getTimestamp());
    }

    /** The run's line in an older-runs list: `run 1 FAILED (childFailed)` / `run 2 COMPLETED`. */
    public function buildRunEndLine(): string
    {
        $runEndLine = 'run ' . $this->runNumber . ' ' . $this->getModelFacingStatus();
        $failureText = $this->buildFailureText();
        return $failureText === null ? $runEndLine : $runEndLine . ' (' . $failureText . ')';
    }

    /** Who continued the run, as the parent's model reads it; null for run 1. */
    public function buildContinuedByText(): ?string
    {
        return match ($this->continuedBy) {
            null => null,
            self::CONTINUED_BY_PARENT => 'continued by you',
            self::CONTINUED_BY_ADMIN => 'continued by an operator',
            self::CONTINUED_BY_OWNER => 'continued by the owner',
            self::CONTINUED_BY_CLI => 'continued from the console',
            default => 'continued by ' . $this->continuedBy,
        };
    }

    /** A duration as the model reads it: `20 s`, `4 min`, `3 h`. */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 7200) {
            return intdiv($seconds, 60) . ' min';
        }
        return intdiv($seconds, 3600) . ' h';
    }

    /** A free-text failure detail cut to {@see FAILURE_DETAIL_MAX_LENGTH} characters. */
    public static function cutFailureDetail(?string $failureDetail): ?string
    {
        if ($failureDetail === null || trim($failureDetail) === '') {
            return null;
        }
        $failureDetail = trim($failureDetail);
        return mb_strlen($failureDetail) <= self::FAILURE_DETAIL_MAX_LENGTH
            ? $failureDetail
            : mb_substr($failureDetail, 0, self::FAILURE_DETAIL_MAX_LENGTH - 1) . '…';
    }

    protected static function readStoredMoment(mixed $storedMoment): ?DateTime
    {
        if (!is_string($storedMoment) || $storedMoment === '') {
            return null;
        }
        $moment = DateTime::fromString($storedMoment);
        return $moment instanceof DateTime ? $moment : null;
    }
}
