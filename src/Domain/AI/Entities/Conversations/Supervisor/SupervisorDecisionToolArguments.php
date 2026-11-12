<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SupervisorTools;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The arguments of ONE terminal supervisor call (`silentApprove`, `steer`, `correct`) as a typed value: the checkpoint validates a new call through {@see fromToolCall()}, the history readers
 * read a stored call through {@see fromPersistedToolCall()} — both ask the same getters instead of reading the
 * argument map by key.
 *
 * The argument MAP stays a map in the order the model sent it: the persisted tool-call row is
 * {@see toPersistedArguments()} (the normalized arguments with `evidenceIndexes` appended) and its key order is read
 * back by the admin rail, so it must not move.
 *
 * @phpstan-consistent-constructor
 */
class SupervisorDecisionToolArguments extends ValueObject
{
    /** The argument the evidence's message indexes are persisted under (appended after the model's own arguments). */
    public const string EVIDENCE_INDEXES_KEY = 'evidenceIndexes';

    /** The terminal tool's name as called (a stored downgraded correction reads `steer`). */
    public string $toolName = '';

    /** @var array<string, mixed> The call's arguments in the order they arrived: normalized (strings trimmed) for a new call, as stored for a persisted one. */
    public array $arguments = [];

    /** @var int[] The supervised message indexes the evidence names, unique, in order of appearance. */
    public array $evidenceIndexes = [];

    /** @var string[] The structural validation feedback of a new call; empty = valid (a persisted call is never re-validated). */
    public array $validationFeedback = [];

    /**
     * A NEW terminal call, validated structurally: the instruction/mandate, the owner sentence of a `correct`, the topic,
     * the expected tool's name shape, and the evidence — at least one message index at or below `$coveredMessageIndex`
     * or a record reference (`record:<kind>`) of a kind in `$recordKindsRead`. A `silentApprove` is never refused.
     *
     * @param string[] $recordKindsRead
     */
    public static function fromToolCall(ToolCall $toolCall, int $coveredMessageIndex, array $recordKindsRead): static
    {
        $supervisorDecisionToolArguments = new static();
        $supervisorDecisionToolArguments->toolName = (string)($toolCall->name ?? '');
        $normalized = [];
        foreach ($toolCall->arguments as $key => $value) {
            $normalized[(string)$key] = is_string($value) ? trim($value) : $value;
        }
        $supervisorDecisionToolArguments->arguments = $normalized;
        if ($supervisorDecisionToolArguments->toolName === SupervisorTools::SILENT_APPROVE) {
            return $supervisorDecisionToolArguments;
        }
        $supervisorDecisionToolArguments->validateInterventionArguments($coveredMessageIndex, $recordKindsRead);
        return $supervisorDecisionToolArguments;
    }

    /** A STORED terminal call as the executor persisted it (the evidence indexes read from the stored arguments). */
    public static function fromPersistedToolCall(ToolCall $toolCall): static
    {
        $supervisorDecisionToolArguments = new static();
        $supervisorDecisionToolArguments->toolName = (string)($toolCall->name ?? '');
        $supervisorDecisionToolArguments->arguments = $toolCall->arguments;
        $supervisorDecisionToolArguments->evidenceIndexes = array_map('intval', (array)($toolCall->arguments[self::EVIDENCE_INDEXES_KEY] ?? []));
        return $supervisorDecisionToolArguments;
    }

    /** Whether a terminal call row was ACCEPTED by the executor (its result starts with the accepted marker). */
    public static function wasAccepted(ToolCall $toolCall): bool
    {
        return str_starts_with((string)($toolCall->result ?? ''), SupervisorTools::RESULT_ACCEPTED);
    }

    /**
     * The record part of a topic, lower-cased: `workshopAddress@15` → `workshopaddress`,
     * `bookings@record:workshop` → `bookings`.
     */
    public static function buildTopicRecordKey(string $topic): string
    {
        $record = trim((string)strtok(trim($topic), '@'));
        return mb_strtolower($record);
    }

    /**
     * The checks of a `steer` / `correct` (validation of {@see fromToolCall()}): fills the feedback lines and the
     * evidence indexes, and writes the trimmed `expectedTool` back into the arguments (appended when the model sent none).
     *
     * @param string[] $recordKindsRead
     */
    protected function validateInterventionArguments(int $coveredMessageIndex, array $recordKindsRead): void
    {
        $instructionKey = $this->toolName === SupervisorTools::CORRECT ? 'mandate' : 'instruction';
        if (trim((string)($this->arguments[$instructionKey] ?? '')) === '') {
            $this->validationFeedback[] = "'{$instructionKey}' is required";
        }
        if ($this->toolName === SupervisorTools::CORRECT && trim((string)($this->arguments['ownerSentence'] ?? '')) === '') {
            $this->validationFeedback[] = "'ownerSentence' is required";
        }
        if (trim((string)($this->arguments['topic'] ?? '')) === '') {
            $this->validationFeedback[] = "'topic' is required";
        }
        $expectedTool = trim((string)($this->arguments['expectedTool'] ?? ''));
        if ($expectedTool !== '' && preg_match('/^[A-Za-z0-9_]+$/', $expectedTool) !== 1) {
            $this->validationFeedback[] = "'expectedTool' must be a tool name (letters, digits, underscore) or empty";
        }
        $this->arguments['expectedTool'] = $expectedTool;
        $evidence = trim((string)($this->arguments['evidence'] ?? ''));
        if ($evidence === '') {
            $this->validationFeedback[] = "'evidence' is required: name the message index(es) in brackets or a record:<kind> you read";
            return;
        }
        preg_match_all('/\[(\d+)\]/', $evidence, $indexMatches);
        $evidenceIndexes = [];
        foreach ($indexMatches[1] as $indexText) {
            $evidenceIndexes[] = (int)$indexText;
        }
        $this->evidenceIndexes = array_values(array_unique($evidenceIndexes));
        $futureIndexes = array_filter($this->evidenceIndexes, static fn (int $index): bool => $index > $coveredMessageIndex);
        preg_match_all('/record:([a-z]+)/', $evidence, $recordMatches);
        $recordReferences = array_values(array_unique($recordMatches[1]));
        $unreadRecords = array_filter($recordReferences, static fn (string $kind): bool => !in_array($kind, $recordKindsRead, true));
        if ($this->evidenceIndexes === [] && $recordReferences === []) {
            $this->validationFeedback[] = "'evidence' names neither a message index in brackets ([12]) nor a record:<kind> — the briefing's indexes and your readRecord lookups are the only valid references";
        }
        if ($futureIndexes !== []) {
            $this->validationFeedback[] = "'evidence' names message index(es) beyond the covered index {$coveredMessageIndex}: " . implode(', ', $futureIndexes);
        }
        if ($unreadRecords !== []) {
            $this->validationFeedback[] = "'evidence' references record(s) this checkpoint did not read with readRecord: " . implode(', ', $unreadRecords);
        }
    }

    /** @return string[] the validation feedback lines; empty = the call is structurally valid */
    public function getValidationFeedback(): array
    {
        return $this->validationFeedback;
    }

    public function hasValidationFeedback(): bool
    {
        return $this->validationFeedback !== [];
    }

    /**
     * The arguments the executor persists on the call row: the normalized arguments with `evidenceIndexes` appended
     * (an `evidenceIndexes` the model sent itself stays in its place and wins, as the array union has always done).
     *
     * @return array<string, mixed>
     */
    public function toPersistedArguments(): array
    {
        return $this->arguments + [self::EVIDENCE_INDEXES_KEY => $this->evidenceIndexes];
    }

    /**
     * The persisted arguments of a `correct` the executor turns into a silent `steer`: the mandate becomes the
     * instruction (in its place when the call carried an `instruction`, else appended after `evidenceIndexes`).
     *
     * @return array<string, mixed>
     */
    public function toPersistedArgumentsDowngradedToSteer(): array
    {
        $persistedArguments = $this->toPersistedArguments();
        $persistedArguments['instruction'] = (string)($persistedArguments['mandate'] ?? '');
        return $persistedArguments;
    }

    public function isSilentApprove(): bool
    {
        return $this->toolName === SupervisorTools::SILENT_APPROVE;
    }

    public function isSteer(): bool
    {
        return $this->toolName === SupervisorTools::STEER;
    }

    public function isCorrection(): bool
    {
        return $this->toolName === SupervisorTools::CORRECT;
    }

    /** Whether the call intervenes: a `steer` or a `correct`. */
    public function isIntervention(): bool
    {
        return $this->isSteer() || $this->isCorrection();
    }

    /** The tool the move pins, trimmed ('' = none). */
    public function getExpectedTool(): string
    {
        return trim((string)($this->arguments['expectedTool'] ?? ''));
    }

    /** @return string[] the violated criteria codes, trimmed and upper-cased, empty ones dropped */
    public function getViolatedCodes(): array
    {
        return array_values(array_filter(array_map(static fn ($code): string => strtoupper(trim((string)$code)), (array)($this->arguments['violated'] ?? []))));
    }

    /** Whether the call names this criteria code among its violated codes (compared trimmed and upper-cased). */
    public function namesViolatedCode(string $code): bool
    {
        return in_array(strtoupper(trim($code)), $this->getViolatedCodes(), true);
    }

    public function getTopic(): string
    {
        return (string)($this->arguments['topic'] ?? '');
    }

    /** The record part of the topic ({@see buildTopicRecordKey()}); '' without a topic. */
    public function getTopicRecordKey(): string
    {
        return self::buildTopicRecordKey($this->getTopic());
    }

    public function getInstruction(): string
    {
        return (string)($this->arguments['instruction'] ?? '');
    }

    public function getMandate(): string
    {
        return (string)($this->arguments['mandate'] ?? '');
    }

    public function getOwnerSentence(): string
    {
        return (string)($this->arguments['ownerSentence'] ?? '');
    }

    public function getEvidence(): string
    {
        return (string)($this->arguments['evidence'] ?? '');
    }

    /** The optional reason of a `silentApprove`; null when the call gave none. */
    public function getReason(): ?string
    {
        return isset($this->arguments['reason']) ? (string)$this->arguments['reason'] : null;
    }

    /** @return int[] */
    public function getEvidenceIndexes(): array
    {
        return $this->evidenceIndexes;
    }

    /** The text a goal quote is searched in: the instruction and the mandate, joined by one space. */
    public function buildInstructionAndMandateText(): string
    {
        return $this->getInstruction() . ' ' . $this->getMandate();
    }
}
