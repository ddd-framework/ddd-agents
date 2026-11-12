<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * ONE decision-model call as the admin sees it: the site, WHAT WAS SENT (`state`), WHAT CAME
 * BACK (`answers`, the raw typed answers), the consequence PHP resolved (`outcome`) and whether the loop acted on
 * it. Kept as a bounded log on the working state ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState::$decisions},
 * newest last, capped) so the conversation inspector can show every decision of a conversation under the owner
 * message it belongs to. The typed per-site stamps ({@see AgentReplyLanguageDecision}, {@see AgentTurnClosingDecision})
 * stay the loop's working values; this record is the trace.
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionRecord extends ValueObject
{
    /** A state field longer than this is cut in the record (the directive block can be 30 × 1000 chars; the log is a trace, not the source). */
    public const int MAX_STATE_FIELD_LENGTH = 2000;

    /** @var string|null The site (`replyLanguage`, `turnClosing`). */
    public ?string $site = null;

    /**
     * @var int|null The `indexInConversation` of the owner message the decision belongs to — the inspector anchors the
     * record there.
     */
    public ?int $ownerMessageIndex = null;

    /** @var string|null `Y-m-d H:i:s`. */
    public ?string $decidedAt = null;

    /** @var string|null `jev` / `shadow` / `fallback:*` — who decided and whether the loop applied it. */
    public ?string $decidedBy = null;

    /** @var bool True when the loop acted on the outcome (mode `on` and a usable answer). */
    public bool $applied = false;

    /** @var int|null Wall-clock of the model call in ms (null when no call was made — breaker open). */
    public ?int $elapsedMs = null;

    /** @var string|null The exact model version that answered. */
    public ?string $modelVersion = null;

    /** @var string|null Why there is no answer (transport failure text, our own error), when applicable. */
    public ?string $failureReason = null;

    /** @var mixed The INPUT: the `state` object exactly as sent, long fields cut to {@see MAX_STATE_FIELD_LENGTH}. */
    public mixed $state = null;

    /** @var mixed The OUTPUT: the raw `answers` object the model returned (choice / probabilities / confidence, or noul). */
    public mixed $answers = null;

    /**
     * @var mixed The QUESTIONS this site sent, exactly as built (name → type / instructions / criteria) — the record's
     * "prompt" block.
     */
    public mixed $questions = null;

    /** @var mixed The CONSEQUENCE PHP resolved (e.g. `{languageCode, source}` or `{wouldBounce, announcesProbability}`). */
    public mixed $outcome = null;

    /**
     * The record of ONE decision-model call, decided now: the state is cut ({@see capStateForRecord()}), empty
     * answers and questions are stored as null. A site with a decision stamp builds it through
     * {@see AgentDecision::buildRecord()}.
     *
     * @param array<string, mixed>|null $state the state as sent
     * @param array<string, mixed>|null $answers the raw answers object
     * @param array<string, mixed> $outcome the resolved consequence
     * @param array<string, mixed>|null $questions the questions as sent
     */
    public static function build(
        string $site,
        ?int $ownerMessageIndex,
        ?string $decidedBy,
        bool $applied,
        ?int $elapsedMs,
        ?string $modelVersion,
        ?string $failureReason,
        ?array $state,
        ?array $answers,
        array $outcome,
        ?array $questions = null
    ): static {
        $agentDecisionRecord = new static();
        $agentDecisionRecord->questions = $questions !== null && $questions !== [] ? $questions : null;
        $agentDecisionRecord->site = $site;
        $agentDecisionRecord->ownerMessageIndex = $ownerMessageIndex;
        $agentDecisionRecord->decidedAt = (new DateTime())->format('Y-m-d H:i:s');
        $agentDecisionRecord->decidedBy = $decidedBy;
        $agentDecisionRecord->applied = $applied;
        $agentDecisionRecord->elapsedMs = $elapsedMs;
        $agentDecisionRecord->modelVersion = $modelVersion;
        $agentDecisionRecord->failureReason = $failureReason;
        $agentDecisionRecord->state = $state !== null ? self::capStateForRecord($state) : null;
        $agentDecisionRecord->answers = $answers !== null && $answers !== [] ? $answers : null;
        $agentDecisionRecord->outcome = $outcome;
        return $agentDecisionRecord;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function capStateForRecord(array $state): array
    {
        $capped = [];
        foreach ($state as $key => $value) {
            if (is_string($value) && mb_strlen($value) > self::MAX_STATE_FIELD_LENGTH) {
                $capped[$key] = mb_substr($value, 0, self::MAX_STATE_FIELD_LENGTH) . ' …[cut, ' . mb_strlen($value) . ' chars]';
            } else {
                $capped[$key] = $value;
            }
        }
        return $capped;
    }

    /** @return array<string, mixed> */
    public function mapToRepository(): mixed
    {
        return [
            'site' => $this->site,
            'ownerMessageIndex' => $this->ownerMessageIndex,
            'decidedAt' => $this->decidedAt,
            'decidedBy' => $this->decidedBy,
            'applied' => $this->applied,
            'elapsedMs' => $this->elapsedMs,
            'modelVersion' => $this->modelVersion,
            'failureReason' => $this->failureReason,
            'state' => $this->state,
            'answers' => $this->answers,
            'questions' => $this->questions,
            'outcome' => $this->outcome,
        ];
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $repoArray = is_array($repoObject) ? $repoObject : [];
        $readString = static function (string $key) use ($repoArray): ?string {
            $value = trim((string)($repoArray[$key] ?? ''));
            return $value !== '' ? $value : null;
        };
        $this->site = $readString('site');
        $this->ownerMessageIndex = isset($repoArray['ownerMessageIndex']) && is_numeric($repoArray['ownerMessageIndex']) ? (int)$repoArray['ownerMessageIndex'] : null;
        $this->decidedAt = $readString('decidedAt');
        $this->decidedBy = $readString('decidedBy');
        $this->applied = (bool)($repoArray['applied'] ?? false);
        $this->elapsedMs = isset($repoArray['elapsedMs']) && is_numeric($repoArray['elapsedMs']) ? (int)$repoArray['elapsedMs'] : null;
        $this->modelVersion = $readString('modelVersion');
        $this->failureReason = $readString('failureReason');
        $this->state = $repoArray['state'] ?? null;
        $this->answers = $repoArray['answers'] ?? null;
        $this->questions = $repoArray['questions'] ?? null;
        $this->outcome = $repoArray['outcome'] ?? null;
    }
}
