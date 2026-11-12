<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\TraceEvents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Repo\DB\Conversations\TraceEvents\DBAIConversationTraceEvent;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * ONE event that shaped a conversation and is not itself a message: an event the voice provider sent,
 * an event or decision of our voice sideband worker or voice controller, or a step of the device's call start-up
 * timeline. The trace standard says every row and event of a conversation answers writer, trigger, time and context;
 * this table carries what the message rows cannot without slowing the turn — it is written only OFF the turn path
 * (voice workers, voice controller, the `deviceStatus` endpoint) and read only by the admin inspector.
 *
 * Lifecycle: the row belongs to its conversation (ON DELETE CASCADE, so an erasure takes it along), carries
 * the call id for the setup events written before a conversation is attached, and has its own 180-day batched purge
 * ({@see AIConversationTraceEventsService::purgeOlderThanDays()}). Idempotent on {@see $eventKey}: a redelivered
 * attach or a retried flush cannot double an event. The link to a message row is by
 * `(aiConversationId, messageIndex)` value — no foreign key to the 46 GB messages table.
 *
 * @method static AIConversationTraceEventsService getService()
 * @method static DBAIConversationTraceEvent getRepoClassInstance(string $repoType = null)
 * @phpstan-consistent-constructor
 */
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['eventKey'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['aiConversationId', 'occurredAtUnixMs'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentVoiceCallId', 'occurredAtUnixMs'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationTraceEvent::class)]
class AIConversationTraceEvent extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** An event received from the voice provider (OpenAI Realtime / GPT-Live). */
    public const string SOURCE_VOICE_PROVIDER = 'VOICE_PROVIDER';
    /** An event we sent, or a decision taken, by our voice sideband worker or the voice controller. */
    public const string SOURCE_VOICE_WORKER = 'VOICE_WORKER';
    /** A step of the app's call start-up timeline, reported through `deviceStatus`. */
    public const string SOURCE_DEVICE = 'DEVICE';

    public const array SOURCES = [self::SOURCE_VOICE_PROVIDER, self::SOURCE_VOICE_WORKER, self::SOURCE_DEVICE];

    /** The provenance of one transcript row: who spoke it, on which call, its span on the session clock. */
    public const string EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED = 'transcript.sentence_persisted';
    /** A delegated agent turn started — on the Realtime path also the provenance of the a2a task-text row. */
    public const string EVENT_TYPE_TASK_STARTED = 'task.started';
    /** The delegated turn's answer was handed to the voice model. */
    public const string EVENT_TYPE_TASK_OUTCOME = 'task.outcome';
    /** A sentence held back while a turn owns the message index. */
    public const string EVENT_TYPE_TRANSCRIPT_BACKLOG_PUSHED = 'transcript.backlog_pushed';
    /** The completeness marker of a cleanly finished call trace. */
    public const string EVENT_TYPE_TRACE_FLUSHED = 'trace.flushed';

    /** The event types that name the row they produced — the writer chips read only these. */
    public const array PROVENANCE_EVENT_TYPES = [self::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED, self::EVENT_TYPE_TASK_STARTED];

    /**
     * Keys that may carry a caller's credential at the door of a phone call — a PIN or SMS code in tool-call
     * arguments, the spoken PIN in a transcript, a keypad tone. Removed from every payload of a caller who is not
     * authenticated yet ({@see redactCredentials()}).
     */
    public const array CREDENTIAL_PAYLOAD_KEYS = ['arguments', 'transcript', 'text', 'delta', 'event', 'digit', 'digits', 'pin', 'code'];

    /** The `eventKey` column length — a longer natural key is hashed into it ({@see buildEventKey()}). */
    public const int EVENT_KEY_MAX_LENGTH = 128;

    /** The one line the timeline shows. */
    public const int SUMMARY_MAX_LENGTH = 300;

    /** Payloads above this many bytes are cut and marked {@see $truncated}. */
    public const int PAYLOAD_MAX_BYTES = 8192;

    /** @var int|null The conversation; null for a setup event written before the call has one (set on attach). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $aiConversationId;

    /** @var int|null The voice call the event belongs to, when it belongs to one. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $agentVoiceCallId;

    /** @var int|null `indexInConversation` of the message row the event produced or refers to. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $messageIndex;

    /** @var int|null When it happened, in Unix milliseconds (device events: the device's clock). */
    #[NotNull]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $occurredAtUnixMs;

    /** @var int|null When the server received a device event, in Unix milliseconds. */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $receivedAtUnixMs;

    /** @var int|null The position on the provider session's own clock (`offset_ms` / `start_ms`), when the event carries one. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $providerOffsetMs;

    /** @var string|null One of the SOURCE_* constants. */
    #[NotNull]
    #[Choice(choices: self::SOURCES)]
    #[Length(max: 16)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $source;

    /** @var string|null The wire type (`session.delegation.created`, …) or the worker decision (`transcript.sentence_persisted`, …). */
    #[NotNull]
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $eventType;

    /** @var string|null The idempotence key: call id + provider event id, or an id assigned once at receipt ({@see buildEventKey()}). */
    #[NotNull]
    #[Length(max: self::EVENT_KEY_MAX_LENGTH)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)] // the class-level UNIQUE is the only index this column needs
    public ?string $eventKey;

    /** @var int|null How long the recorded thing ran, in ms, when it has a duration. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $durationMs;

    /** @var string|null The one line the timeline shows. */
    #[Length(max: self::SUMMARY_MAX_LENGTH)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $summary;

    /** @var string|null The event as sent or received (audio never), JSON, at most {@see PAYLOAD_MAX_BYTES}; correlation ids sit outside the cut part. */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_JSON)]
    public ?string $payload;

    /** @var bool|null True when the payload was cut to {@see PAYLOAD_MAX_BYTES}. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?bool $truncated;

    /** @var int|null The payload's original length in bytes, when it was cut. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $originalLength;

    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE, onUpdateAction: DatabaseForeignKey::ACTION_CASCADE)]
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AIConversation $aiConversation;

    /** The current time in Unix milliseconds — the one clock every writer stamps with. */
    public static function nowUnixMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }

    /**
     * Build an event (not yet written). `eventKey` must identify the event across retries: the provider's event
     * id where it sends one, otherwise an id assigned once when the event is first seen.
     *
     * @param string $source One of the SOURCE_* constants
     * @param string $eventType The wire type or the worker decision
     * @param string $eventKey The idempotence key
     * @param int|null $aiConversationId
     * @param int|null $agentVoiceCallId
     * @param int|null $occurredAtUnixMs Defaults to now
     * @param string|null $summary The one line the timeline shows
     * @param array<string, mixed> $payload
     * @param array<string, string|int|null> $correlationIds
     * @param int|null $durationMs
     * @param int|null $messageIndex
     * @param int|null $providerOffsetMs
     * @param int|null $receivedAtUnixMs
     * @return static
     */
    public static function create(
        string $source,
        string $eventType,
        string $eventKey,
        ?int $aiConversationId = null,
        ?int $agentVoiceCallId = null,
        ?int $occurredAtUnixMs = null,
        ?string $summary = null,
        array $payload = [],
        array $correlationIds = [],
        ?int $durationMs = null,
        ?int $messageIndex = null,
        ?int $providerOffsetMs = null,
        ?int $receivedAtUnixMs = null
    ): static {
        $traceEvent = new static();
        $traceEvent->source = $source;
        $traceEvent->eventType = mb_substr($eventType, 0, 64);
        $traceEvent->eventKey = self::buildEventKey($eventKey);
        $traceEvent->aiConversationId = $aiConversationId;
        $traceEvent->agentVoiceCallId = $agentVoiceCallId;
        $traceEvent->occurredAtUnixMs = $occurredAtUnixMs ?? self::nowUnixMs();
        $traceEvent->receivedAtUnixMs = $receivedAtUnixMs;
        $traceEvent->providerOffsetMs = $providerOffsetMs;
        $traceEvent->messageIndex = $messageIndex;
        $traceEvent->durationMs = $durationMs;
        $traceEvent->setSummary($summary);
        if ($payload !== [] || $correlationIds !== []) {
            $traceEvent->setPayloadArray($payload, $correlationIds);
        } else {
            $traceEvent->payload = null;
            $traceEvent->truncated = false;
            $traceEvent->originalLength = null;
        }

        return $traceEvent;
    }

    /**
     * The idempotence key of an event. A natural key longer than the column is hashed (its prefix kept readable),
     * so the same event always produces the same key.
     */
    public static function buildEventKey(string ...$keyParts): string
    {
        $naturalKey = implode(':', $keyParts);
        if (mb_strlen($naturalKey) <= self::EVENT_KEY_MAX_LENGTH) {
            return $naturalKey;
        }

        return mb_substr($naturalKey, 0, self::EVENT_KEY_MAX_LENGTH - 41) . '#' . sha1($naturalKey);
    }

    /**
     * Store the event's payload, bounded to {@see PAYLOAD_MAX_BYTES}. The correlation ids (delegation id, function
     * `call_id`, provider `response_id`, run token) stay readable even when the rest is cut: an oversized payload is
     * kept as the first bytes of its JSON text under `cut`, so the column always holds valid JSON.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string|int|null> $correlationIds
     */
    public function setPayloadArray(array $payload, array $correlationIds = []): void
    {
        $correlationIds = array_filter($correlationIds, static fn(mixed $value): bool => $value !== null && $value !== '');
        $document = $correlationIds !== [] ? ['correlationIds' => $correlationIds] + $payload : $payload;
        $encodedOrFalse = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->truncated = false;
        $this->originalLength = null;
        if ($encodedOrFalse === false) {
            // Unencodable (a resource, a recursion) — an empty string would be refused by the JSON column and take
            // the whole batch down with it.
            $this->payload = null;

            return;
        }
        $encoded = $encodedOrFalse;
        if (strlen($encoded) > self::PAYLOAD_MAX_BYTES) {
            $this->truncated = true;
            $this->originalLength = strlen($encoded);
            $cutDocument = ['correlationIds' => $correlationIds, 'cut' => ''];
            $room = self::PAYLOAD_MAX_BYTES - strlen((string)json_encode($cutDocument, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) - 64;
            // The escaping of quotes and backslashes can grow the cut text; shrink until the document fits.
            do {
                $cutDocument['cut'] = mb_strcut($encoded, 0, max(0, $room), 'UTF-8');
                $encoded = (string)json_encode($cutDocument, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $room -= 512;
            } while (strlen($encoded) > self::PAYLOAD_MAX_BYTES && $room > 0);
        }
        $this->payload = $encoded;
    }

    /**
     * The payload with every credential-carrying key removed, at any depth — for events of a caller still at the
     * door of a phone call. The doorman's own records name the tool and its outcome; what the caller keyed or
     * spoke to get through never reaches the trace.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function redactCredentials(array $payload): array
    {
        foreach ($payload as $payloadKey => $payloadValue) {
            if (is_string($payloadKey) && in_array(strtolower($payloadKey), self::CREDENTIAL_PAYLOAD_KEYS, true)) {
                $payload[$payloadKey] = '[redacted]';
                continue;
            }
            if (is_array($payloadValue)) {
                $payload[$payloadKey] = self::redactCredentials($payloadValue);
            }
        }

        return $payload;
    }

    /** Set the one-line summary, cut to {@see SUMMARY_MAX_LENGTH}. */
    public function setSummary(?string $summary): void
    {
        $this->summary = $summary === null ? null : mb_substr(trim($summary), 0, self::SUMMARY_MAX_LENGTH);
    }

    /** @return array<string, mixed> The decoded payload ([] when absent or unreadable). */
    public function getPayloadArray(): array
    {
        $decoded = json_decode((string)($this->payload ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }
}
