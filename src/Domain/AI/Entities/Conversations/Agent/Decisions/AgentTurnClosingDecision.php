<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;


/**
 * The turn-CLOSING decision of ONE owner turn: when the model wants to end the turn with a
 * final text, zero work tool calls, and plan items that still owe tool / widget evidence, does that text ANNOUNCE
 * the work instead of delivering it? Today's deterministic guard ({@see
 * \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::shouldBounceCheckedStop()}) can only count
 * announce-only owner windows and by construction fires one owner turn late; this decision judges the FIRST such
 * text. Persisted as its own slice on the conversation's
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState} (ONE writer: the checked-stop step, via
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}).
 *
 * Only `announces_instead_of_delivers` bounces ({@see resolveWouldBounce()}); `reports_completed_writes` is recorded
 * for the gate measurement — an answer that summarizes a PREVIOUS turn's writes after "thanks" reports completed
 * writes legitimately, so it can never be a trigger on its own. Bounded blast radius: a false negative = today's
 * behaviour; a false positive = one discarded answer and a bounce signal, which is what the deterministic guard
 * already does — under the same per-chunk cap.
 *
 * A stamp with {@see $decidedBy} = {@see DECIDED_BY_DECISION_MODEL} is APPLIED by the loop; every other value is a record only.
 */
class AgentTurnClosingDecision extends AgentDecision
{
    /**
     * P(true) at or above which an announce verdict bounces. `noul` answers measured UNDER-confident and quantised
     * to 0.01 (the dossier): the real announce-only texts scored 0.84–0.94, genuine deliveries 0.03.
     */
    public const float MINIMUM_ANNOUNCE_PROBABILITY = 0.8;

    /** @var float|null P(the answer announces the open work instead of delivering it). */
    public ?float $announcesInsteadOfDeliversProbability = null;

    /** @var float|null P(the answer reports changes as already made this turn). */
    public ?float $reportsCompletedWritesProbability = null;

    /** @var bool True when the chain said "bounce" — applied only when {@see isApplied()}. */
    public bool $wouldBounce = false;

    /** @var int The number of open items owing evidence the answer was judged against. */
    public int $openWorkItemCount = 0;

    /** @var int The character length of the judged answer text (the admin trace's size cue). */
    public int $answerLength = 0;

    /** @var string|null The turn-closing mode the checked stop ran with when this decision was made (`deterministicCounter` / `decisionModel`). */
    public ?string $turnClosingMode = null;

    /** @var string|null The AgentTurnClosingOutcome::REASON_* when the loop fell back to the ladder under `decisionModel`. */
    public ?string $fallbackReason = null;

    /**
     * @var bool True when this decision's verdict CAUSED the bounce (the decision record's cue; the cap-1 rule reads
     * the signal).
     */
    public bool $bounced = false;

    /** @var string|null The resolver's AgentFinalTextAcceptanceResult::RULE_* that decided the text this verdict was asked for (`jevVetoedCounter`, `jevConfirmedCounter`, …). */
    public ?string $decidingRule = null;

    /** True when the loop must act on {@see $wouldBounce} instead of the deterministic guard alone. */
    public function isApplied(): bool
    {
        return $this->decidedBy === self::DECIDED_BY_DECISION_MODEL;
    }

    /**
     * The consequence, pure: bounce when the announce probability is a real answer at or above the floor. The
     * reports-completed-writes fact never bounces on its own (see the class doc).
     */
    public static function resolveWouldBounce(?float $announcesInsteadOfDeliversProbability, float $minimumProbability = self::MINIMUM_ANNOUNCE_PROBABILITY): bool
    {
        return $announcesInsteadOfDeliversProbability !== null && $announcesInsteadOfDeliversProbability >= $minimumProbability;
    }

    /** @return array<string, mixed> */
    public function mapToRepository(): mixed
    {
        return [
            'ownerMessageIndex' => $this->ownerMessageIndex,
            'decidedAt' => $this->decidedAt,
            'decidedBy' => $this->decidedBy,
            'announcesInsteadOfDeliversProbability' => $this->announcesInsteadOfDeliversProbability,
            'reportsCompletedWritesProbability' => $this->reportsCompletedWritesProbability,
            'wouldBounce' => $this->wouldBounce,
            'openWorkItemCount' => $this->openWorkItemCount,
            'answerLength' => $this->answerLength,
            'modelVersion' => $this->modelVersion,
            'turnClosingMode' => $this->turnClosingMode,
            'fallbackReason' => $this->fallbackReason,
            'bounced' => $this->bounced,
            'decidingRule' => $this->decidingRule,
        ];
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $repoArray = is_array($repoObject) ? $repoObject : [];
        $readString = static function (string $key) use ($repoArray): ?string {
            $value = trim((string)($repoArray[$key] ?? ''));
            return $value !== '' ? $value : null;
        };
        $readFloat = static function (string $key) use ($repoArray): ?float {
            return isset($repoArray[$key]) && is_numeric($repoArray[$key]) ? (float)$repoArray[$key] : null;
        };
        $readInt = static function (string $key) use ($repoArray): ?int {
            return isset($repoArray[$key]) && is_numeric($repoArray[$key]) ? (int)$repoArray[$key] : null;
        };
        $this->ownerMessageIndex = $readInt('ownerMessageIndex');
        $this->decidedAt = $readString('decidedAt');
        $this->decidedBy = $readString('decidedBy');
        $this->announcesInsteadOfDeliversProbability = $readFloat('announcesInsteadOfDeliversProbability');
        $this->reportsCompletedWritesProbability = $readFloat('reportsCompletedWritesProbability');
        $this->wouldBounce = (bool)($repoArray['wouldBounce'] ?? false);
        $this->openWorkItemCount = $readInt('openWorkItemCount') ?? 0;
        $this->answerLength = $readInt('answerLength') ?? 0;
        $this->modelVersion = $readString('modelVersion');
        $this->turnClosingMode = $readString('turnClosingMode');
        $this->fallbackReason = $readString('fallbackReason');
        $this->bounced = (bool)($repoArray['bounced'] ?? false);
        $this->decidingRule = $readString('decidingRule');
    }
}
