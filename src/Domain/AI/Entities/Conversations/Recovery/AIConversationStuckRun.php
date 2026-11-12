<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recovery;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One conversation the recovery machinery would act on right now (`findStuckAgentRuns`): which of the
 * seven candidate sets name it ({@see $reasons}), and the row facts an analyst needs to judge it — purpose, run state,
 * heartbeat age, failed attempts, the owed stamps. Read only: nothing here re-drives, expires or delivers.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationStuckRun extends ValueObject
{
    /** RUNNING past the hung threshold or AWAITING_DELEGATES past the fold window — the reaper re-drives it. */
    public const string REASON_NO_HEARTBEAT = 'NO_HEARTBEAT';

    /** The parent of a sub-agent run deadline that passed unfinished — the reaper times the sub-agent out and resumes the parent. */
    public const string REASON_DEADLINE_PASSED = 'DEADLINE_PASSED';

    /** A parent whose finished sub-agents' results were never read (legacy batches) — the reaper resumes it. */
    public const string REASON_UNREAD_CHILD_RESULTS = 'UNREAD_CHILD_RESULTS';

    /**
     * An IDLE purposed conversation quiet for 10 minutes whose close may be owed — the reaper's settle pass re-runs the
     * close.
     */
    public const string REASON_QUIET_IDLE_PURPOSED = 'QUIET_IDLE_PURPOSED';

    /** A report owed and never delivered — the owed-reports pass delivers it. */
    public const string REASON_OWING_REPORT = 'OWING_REPORT';

    /** IDLE with a turn handover older than 5 minutes — the owed-turns pass re-drives it. */
    public const string REASON_OWING_CONTINUATION = 'OWING_CONTINUATION';

    /** IDLE with an inbound message nobody answered, or a failed turn whose marker is gone — the lost-resumes pass re-drives it. */
    public const string REASON_INBOUND_LEFT_IDLE = 'INBOUND_LEFT_IDLE';

    /** @var array<string, string> What each reason means and which pass acts on it. */
    public const array REASON_DESCRIPTIONS = [
        self::REASON_NO_HEARTBEAT => 'no heartbeat (RUNNING > 300 s, AWAITING_DELEGATES > 30 s) — the reaper re-drives',
        self::REASON_DEADLINE_PASSED => 'a sub-agent run deadline passed unfinished — the reaper times it out, resumes the parent',
        self::REASON_UNREAD_CHILD_RESULTS => 'finished sub-agents whose results the parent never read — the reaper resumes the parent',
        self::REASON_QUIET_IDLE_PURPOSED => 'an IDLE purposed conversation, quiet 10 min — the reaper re-runs the settled-turn close (often nothing to do)',
        self::REASON_OWING_REPORT => 'report owed, not delivered — the owed-reports pass delivers it',
        self::REASON_OWING_CONTINUATION => 'turn handover older than 300 s — the owed-turns pass re-drives',
        self::REASON_INBOUND_LEFT_IDLE => 'inbound message unanswered, or a failed turn without marker — the lost-resumes pass re-drives',
    ];

    public int $aiConversationId = 0;

    /** @var string[] Every candidate set that names the conversation. */
    public array $reasons = [];

    public ?int $accountId = null;

    /** The id of the space the conversation works for; null = a conversation at account level (no space). */
    public ?int $agentSpaceId = null;

    public ?string $purposeName = null;

    public ?string $runState = null;

    /** The delegation root, for a sub-agent. */
    public ?int $rootConversationId = null;

    public ?DateTime $runHeartbeatAt = null;

    /** Seconds since the newest heartbeat (null without one). */
    public ?int $heartbeatAgeSeconds = null;

    public ?DateTime $lastInboundMessageAt = null;

    public int $resumeAttempts = 0;

    public ?DateTime $agentTurnOwedAt = null;

    public ?DateTime $reportOwedAt = null;

    /** @var int[] The sub-agent run deadlines (batch ids) that passed, for {@see REASON_DEADLINE_PASSED}. */
    public array $passedDeadlineBatchIds = [];

    public static function forConversation(int $aiConversationId): static
    {
        // the application's class of the same path when it has one (it may add its own facts to the row)
        $stuckRun = static::newInstance();
        $stuckRun->aiConversationId = $aiConversationId;
        return $stuckRun;
    }

    public function addReason(string $reason): void
    {
        if (!in_array($reason, $this->reasons, true)) {
            $this->reasons[] = $reason;
        }
    }

    /**
     * The row facts of the conversation (columns of the conversations table).
     *
     * @param array<string, mixed> $row
     */
    public function applyRow(array $row, DateTime $now): void
    {
        $this->accountId = isset($row['accountId']) ? (int)$row['accountId'] : null;
        $this->agentSpaceId = isset($row['agentSpaceId']) ? (int)$row['agentSpaceId'] : null;
        $this->purposeName = $row['purposeName'] ?? null;
        $this->runState = $row['runState'] ?? null;
        $this->rootConversationId = isset($row['rootConversationId']) ? (int)$row['rootConversationId'] : null;
        $this->runHeartbeatAt = self::toDateTime($row['runHeartbeatAt'] ?? null);
        $this->heartbeatAgeSeconds = $this->runHeartbeatAt !== null ? max(0, $now->getTimestamp() - $this->runHeartbeatAt->getTimestamp()) : null;
        $this->lastInboundMessageAt = self::toDateTime($row['lastInboundMessageAt'] ?? null);
        $this->resumeAttempts = (int)($row['resumeAttempts'] ?? 0);
        $this->agentTurnOwedAt = self::toDateTime($row['agentTurnOwedAt'] ?? null);
        $this->reportOwedAt = self::toDateTime($row['reportOwedAt'] ?? null);
    }

    /** True when only the reaper's settle sweep names the run — quiet, IDLE, purposed: usually nothing to recover. */
    public function isNamedOnlyBySettleSweep(): bool
    {
        return $this->reasons === [self::REASON_QUIET_IDLE_PURPOSED];
    }

    /** The key of a conversation's stuck run: the same for every class derived from this one, so a set finds it by the id. */
    public static function uniqueKeyOfConversationId(int $aiConversationId): string
    {
        return self::class . '_' . $aiConversationId;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyOfConversationId($this->aiConversationId);
    }

    /**
     * The text of the table cell below the set's space column title
     * ({@see AIConversationStuckRuns::AGENT_SPACE_COLUMN_TITLE}): the id of the space, empty for a conversation at
     * account level. An application that names its own term in that title returns its own id of the space here.
     *
     * @return string
     */
    protected function getAgentSpaceCellText(): string
    {
        return (string)($this->agentSpaceId ?? '');
    }

    public function toLlmMarkdownRow(): string
    {
        $cells = [
            (string)$this->aiConversationId,
            implode(', ', $this->reasons) . ($this->passedDeadlineBatchIds !== [] ? ' (deadlines ' . implode(', ', $this->passedDeadlineBatchIds) . ')' : ''),
            (string)($this->purposeName ?? ''),
            (string)($this->runState ?? 'row gone'),
            (string)($this->accountId ?? ''),
            $this->getAgentSpaceCellText(),
            $this->rootConversationId !== null ? (string)$this->rootConversationId : '',
            $this->heartbeatAgeSeconds !== null ? $this->formatAge($this->heartbeatAgeSeconds) : '',
            (string)$this->resumeAttempts,
            $this->agentTurnOwedAt?->format('m-d H:i') ?? '',
            $this->reportOwedAt?->format('m-d H:i') ?? '',
            $this->lastInboundMessageAt?->format('m-d H:i') ?? '',
        ];
        return '| ' . implode(' | ', $cells) . ' |';
    }

    protected function formatAge(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $seconds . ' s',
            $seconds < 7200 => intdiv($seconds, 60) . ' min',
            default => intdiv($seconds, 3600) . ' h',
        };
    }

    protected static function toDateTime(mixed $value): ?DateTime
    {
        return $value === null || $value === '' ? null : new DateTime((string)$value);
    }
}
