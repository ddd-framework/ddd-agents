<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\TurnEvents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEvent;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * ONE append-only event of an agent turn: a decision-model decision, a preload's outcome, an
 * eviction victim, a forced re-drive attempt, a chunk (the claimed invocation with its usage envelope), the turn-start
 * request's own cost and latency, or the TERMINAL record of a turn. Never loaded into the model context, never part
 * of an entity update — written by ONE raw-SQL writer ({@see AIConversationTurnEventsService::recordEvent()}) that is
 * idempotent on {@see $eventKey} and fenced by the run claim, read by the admin inspector and the decision statistics.
 *
 * Lifecycle: the row belongs to its conversation (ON DELETE CASCADE — the conversation erasure deletes the
 * conversation and the events follow, so this table needs no account column of its own) and to a 180-day retention
 * purge (a console command of the application). `payload` holds the kind-specific facts as JSON; the columns hold only
 * what the readers filter or join on.
 *
 * @method static AIConversationTurnEventsService getService()
 * @method static DBAIConversationTurnEvent getRepoClassInstance(string $repoType = null)
 */
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['eventKey'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['aiConversationId', 'kind', 'initiatorMessageIndex'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationTurnEvent::class)]
class AIConversationTurnEvent extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string KIND_DECISION = 'decision';
    public const string KIND_PRELOAD_OUTCOME = 'preloadOutcome';
    public const string KIND_EVICTION = 'eviction';
    public const string KIND_FORCED_RE_DRIVE = 'forcedReDrive';
    public const string KIND_TURN_END = 'turnEnd';
    public const string KIND_CHUNK = 'chunk';
    public const string KIND_REQUEST = 'request';

    public const array KINDS = [
        self::KIND_DECISION, self::KIND_PRELOAD_OUTCOME, self::KIND_EVICTION, self::KIND_FORCED_RE_DRIVE,
        self::KIND_TURN_END, self::KIND_CHUNK, self::KIND_REQUEST,
    ];

    /** The always-on telemetry kinds — written with every decision site off, exempt from the byte-identical gate. */
    public const array ALWAYS_ON_KINDS = [self::KIND_EVICTION, self::KIND_FORCED_RE_DRIVE, self::KIND_TURN_END, self::KIND_CHUNK];

    public const string OUTCOME_ANSWERED = 'answered';
    public const string OUTCOME_FAILED = 'failed';
    public const string OUTCOME_GAVE_UP = 'gaveUp';
    public const string OUTCOME_EXPIRED = 'expired';
    public const string OUTCOME_CANCELLED = 'cancelled';
    public const string OUTCOME_SUPERSEDED = 'superseded';
    /** A turn sub-agent notifications started ended with `wait_for_sub_agents` — no text, sub-agents still run. */
    public const string OUTCOME_WAITING_FOR_SUB_AGENTS = 'waitingForSubAgents';

    public const string OVERRIDE_SOURCE_NONE = 'none';
    public const string OVERRIDE_SOURCE_CONVERSATION = 'conversation';

    /** The `eventKey` column length — a longer natural key is hashed into it ({@see buildEventKey()}). */
    public const int EVENT_KEY_MAX_LENGTH = 191;

    /** @var int|null The conversation the event belongs to. */
    #[NotNull]
    public ?int $aiConversationId;

    /** @var int|null `indexInConversation` of the turn's INITIATOR (the loop's own predicate). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $initiatorMessageIndex;

    /** @var int|null The message index the event was evaluated at (several A evaluations of one turn are distinct). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $evaluationMessageIndex;

    /** @var string|null One of the KIND_* constants. */
    #[NotNull]
    #[Length(max: 32)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $kind;

    /** @var string|null The decision site (`replyLanguage`, `turnClosing`, `skillPreload`, `planItem`) when the kind has one. */
    #[Length(max: 32)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $site;

    /** @var string|null The event's subject: the victim, the skill, the attempt (run token), the chunk's token — what keeps two events of one evaluation apart. */
    #[Length(max: 191)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $subject;

    /** @var string|null The idempotence key: `conversation:initiator:evaluation:kind:site:subject` ({@see buildEventKey()}). */
    #[NotNull]
    #[Length(max: self::EVENT_KEY_MAX_LENGTH)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)] // the class-level UNIQUE is the only index this column needs
    public ?string $eventKey;

    /** @var string|null The shared turn-start request id, when the event came from one. */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $requestId;

    /** @var string|null The run token the writing worker held (the fence's evidence). */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $runToken;

    /** @var string|null The account's gate 6 arm (`control` / `shadow` / `on`) at write time, when the cohort mode was on. */
    #[Length(max: 16)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $arm;

    /** @var bool|null True when the conversation carries the eval marker ({@see \DDD\Domain\AI\Entities\Conversations\Messages\RunSettings::$evalRun}). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?bool $evalRun;

    /** @var string|null `none` / `conversation` — whether a per-conversation switch overrode the site mode for this event. */
    #[Length(max: 32)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $overrideSource;

    /** @var string|null The catalog snapshot this decision was rendered with ({@see AIConversationTurnEventSnapshot::$hash}). */
    #[Length(max: 40)]
    public ?string $catalogSnapshotHash;

    /** @var string|null The kind-specific facts, JSON. */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_JSON)]
    public ?string $payload;

    /** @var int|null Wall-clock of the thing the event records, in ms (the decision call, the turn), when it has one. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $elapsedMs;

    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE, onUpdateAction: DatabaseForeignKey::ACTION_CASCADE)]
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AIConversation $aiConversation;

    /**
     * The idempotence key of an event — deterministic, so a retried chunk re-inserting the same event hits the
     * UNIQUE index instead of adding a twin. A natural key longer than the column is hashed (its prefix kept
     * readable).
     */
    public static function buildEventKey(int $conversationId, ?int $initiatorMessageIndex, ?int $evaluationMessageIndex, string $kind, ?string $site, ?string $subject): string
    {
        $naturalKey = implode(':', [
            $conversationId,
            $initiatorMessageIndex ?? '-',
            $evaluationMessageIndex ?? '-',
            $kind,
            $site ?? '-',
            $subject ?? '-',
        ]);
        if (mb_strlen($naturalKey) <= self::EVENT_KEY_MAX_LENGTH) {
            return $naturalKey;
        }
        $hash = sha1($naturalKey);
        return mb_substr($naturalKey, 0, self::EVENT_KEY_MAX_LENGTH - 41) . '#' . $hash;
    }

    /** @return array<string, mixed> The decoded payload ([] when absent or unreadable). */
    public function getPayloadArray(): array
    {
        $decoded = json_decode((string) ($this->payload ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    public function setPayloadArray(array $payload): void
    {
        $this->payload = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
