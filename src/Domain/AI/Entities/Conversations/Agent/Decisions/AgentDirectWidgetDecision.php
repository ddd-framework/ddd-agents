<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The DIRECT-WIDGET decision of ONE owner turn: is the owner's message answered by one widget
 * the model writes directly, without a skill or a tool first — a `Clock`, a `WeatherForecast`, a `WritingDraft`, or a
 * `ComposedView` of a layout the owner named? The answer set is every widget class with a
 * {@see \DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget::DIRECT_WIDGET_WHEN} line, plus `none`; the
 * question rides the folded turn-start request. Measured cause: on byte-identical input the model
 * either wrote the block after its line or stopped after the line — a typed fact of the turn, not more wording.
 *
 * The rule is the skill preload's ({@see AgentSkillPreloadDecision::resolvePreloadFromDistribution()}): over a validated
 * distribution the best widget is the pick at ≥ {@see MINIMUM_TOP_PROBABILITY} while `none` stays ≤
 * {@see MAXIMUM_NONE_PROBABILITY}. The pick DECIDES the turn's widget only when the skill preload of the same turn
 * abstained ({@see $skillPreloaded}): a loaded skill brings its own widgets and its own steps.
 *
 * A decided widget ({@see getDecidedWidgetType()}, only under `on`) puts the `[SIGNAL:widget]` line after the plan
 * directive at the wire tail ({@see toSignalText()}) and arms the DIRECT_WIDGET_MISSING stall
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStallClassification}).
 *
 * @phpstan-consistent-constructor
 */
class AgentDirectWidgetDecision extends AgentDecision
{
    /** The abstention token of the `direct_widget` question. */
    public const string ANSWER_NONE = 'none';

    /** The best widget's probability floor — the skill preload's. */
    public const float MINIMUM_TOP_PROBABILITY = AgentSkillPreloadDecision::MINIMUM_TOP_PROBABILITY;

    /** The `none` probability ceiling — the skill preload's. */
    public const float MAXIMUM_NONE_PROBABILITY = AgentSkillPreloadDecision::MAXIMUM_NONE_PROBABILITY;

    public const string SKIP_ABSTAINED = 'abstained';
    public const string SKIP_LOW_CONFIDENCE = 'lowConfidence';
    public const string SKIP_INVALID_DISTRIBUTION = 'invalidDistribution';
    /** The skill preload of the same turn loaded a skill: its widgets and steps answer the turn. */
    public const string SKIP_SKILL_PRELOADED = 'skillPreloaded';

    /** @var array<string, string> The lowercased answer choice → widget type as sent (the wire lowercases choices). */
    public array $widgetTokens = [];

    /** @var string|null The widget type the distribution picks; null = `none` or no usable answer. */
    public ?string $pickedWidgetType = null;

    /** @var array<string, float> The validated distribution over the tokens and `none`. */
    public array $probabilities = [];

    /** @var bool True when the distribution picks a widget under the thresholds. */
    public bool $wouldShow = false;

    /** @var bool True when the skill preload of the same turn loaded a skill (the pick then decides nothing). */
    public bool $skillPreloaded = false;

    /** @var string|null Why no widget was decided (SKIP_* or the request's fallback), null when one was. */
    public ?string $skipReason = null;

    /** The decision of an owner turn's start before the model answered, decided now. */
    public static function build(int $ownerMessageIndex): static
    {
        $decision = new static();
        $decision->ownerMessageIndex = $ownerMessageIndex;
        $decision->decidedAt = (new DateTime())->format('Y-m-d H:i:s');
        return $decision;
    }

    /** True when the loop acts on the pick (site `on` and a usable answer). */
    public function isApplied(): bool
    {
        return $this->decidedBy === self::DECIDED_BY_DECISION_MODEL;
    }

    /** The widget the turn's answer is written as: the applied pick of a turn whose skill preload abstained; null otherwise. */
    public function getDecidedWidgetType(): ?string
    {
        return $this->isApplied() && $this->wouldShow && !$this->skillPreloaded ? $this->pickedWidgetType : null;
    }

    /**
     * The reported distribution validated against the answer set: the widget tokens (lowercased types) and `none`;
     * null when unusable.
     *
     * @param array<string, mixed> $reportedProbabilities
     * @param string[] $widgetTokens
     */
    public static function validateReportedProbabilities(array $reportedProbabilities, array $widgetTokens): ?AgentDecisionAnswerProbabilities
    {
        return AgentDecisionAnswerProbabilities::fromReported($reportedProbabilities, array_merge($widgetTokens, [self::ANSWER_NONE]));
    }

    /**
     * The pick over a validated distribution (the preload's rule): whether a widget is shown, why not, and the best
     * non-`none` token — the pick at ≥ the floor while `none` stays ≤ the ceiling. The margin is not read.
     *
     * @param AgentDecisionAnswerProbabilities|null $distribution the validated distribution incl. `none`
     * @param float $minimumTopProbability
     * @param float $maximumNoneProbability
     * @return AgentDecisionDistributionPick
     */
    public static function resolveFromDistribution(
        ?AgentDecisionAnswerProbabilities $distribution,
        float $minimumTopProbability = self::MINIMUM_TOP_PROBABILITY,
        float $maximumNoneProbability = self::MAXIMUM_NONE_PROBABILITY
    ): AgentDecisionDistributionPick {
        if ($distribution === null) {
            return AgentDecisionDistributionPick::forOutcome(false, self::SKIP_INVALID_DISTRIBUTION, null);
        }
        $candidates = $distribution->withoutAnswer(self::ANSWER_NONE);
        if ($candidates->isEmpty()) {
            return AgentDecisionDistributionPick::forOutcome(false, self::SKIP_ABSTAINED, null);
        }
        $topToken = (string)$candidates->getTopAnswer();
        if (($distribution->getProbabilityOf(self::ANSWER_NONE) ?? 0.0) > $maximumNoneProbability) {
            return AgentDecisionDistributionPick::forOutcome(false, self::SKIP_ABSTAINED, $topToken);
        }
        if ($candidates->getTopProbability() < $minimumTopProbability) {
            return AgentDecisionDistributionPick::forOutcome(false, self::SKIP_LOW_CONFIDENCE, $topToken);
        }
        return AgentDecisionDistributionPick::forOutcome(true, null, $topToken);
    }

    /**
     * The decided widget's `[SIGNAL:widget]` line at the wire tail — the class's own sentence
     * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget::DIRECT_WIDGET_SIGNAL}) or the one that
     * names the block; null when no widget is decided.
     */
    public function toSignalText(): ?string
    {
        $decidedWidgetType = $this->getDecidedWidgetType();
        if ($decidedWidgetType === null) {
            return null;
        }
        $widgetClass = ConversationWidgetClasses::getWidgetClassForWidgetType($decidedWidgetType);
        $classSentence = $widgetClass !== null ? $widgetClass::DIRECT_WIDGET_SIGNAL : '';
        return '[SIGNAL:widget] ' . ($classSentence !== ''
            ? $classSentence
            : 'This question is answered by the `' . $decidedWidgetType . '` block you write directly: one short line, then the block.');
    }

    /**
     * The resolved consequence, for the decision record and the turn event.
     *
     * @return array<string, mixed>
     */
    public function buildOutcome(string $siteMode): array
    {
        return [
            'applied' => $this->getDecidedWidgetType() !== null,
            'mode' => $siteMode,
            'pick' => $this->pickedWidgetType,
            'wouldShow' => $this->wouldShow,
            'skillPreloaded' => $this->skillPreloaded,
            'skipReason' => $this->skipReason,
            'decidedWidgetType' => $this->getDecidedWidgetType(),
            'minimumTopProbability' => self::MINIMUM_TOP_PROBABILITY,
            'maximumNoneProbability' => self::MAXIMUM_NONE_PROBABILITY,
        ];
    }

    /** @return array<string, mixed> */
    public function mapToRepository(): mixed
    {
        return [
            'ownerMessageIndex' => $this->ownerMessageIndex,
            'decidedAt' => $this->decidedAt,
            'decidedBy' => $this->decidedBy,
            'widgetTokens' => $this->widgetTokens,
            'pickedWidgetType' => $this->pickedWidgetType,
            'probabilities' => $this->probabilities,
            'wouldShow' => $this->wouldShow,
            'skillPreloaded' => $this->skillPreloaded,
            'skipReason' => $this->skipReason,
            'modelVersion' => $this->modelVersion,
        ];
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $repoArray = is_array($repoObject) ? $repoObject : [];
        $this->ownerMessageIndex = isset($repoArray['ownerMessageIndex']) && is_numeric($repoArray['ownerMessageIndex']) ? (int)$repoArray['ownerMessageIndex'] : null;
        $this->decidedAt = self::readNonEmptyString($repoArray, 'decidedAt');
        $this->decidedBy = self::readNonEmptyString($repoArray, 'decidedBy');
        $this->widgetTokens = is_array($repoArray['widgetTokens'] ?? null) ? array_map('strval', $repoArray['widgetTokens']) : [];
        $this->pickedWidgetType = self::readNonEmptyString($repoArray, 'pickedWidgetType');
        $this->probabilities = is_array($repoArray['probabilities'] ?? null) ? array_map('floatval', $repoArray['probabilities']) : [];
        $this->wouldShow = (bool)($repoArray['wouldShow'] ?? false);
        $this->skillPreloaded = (bool)($repoArray['skillPreloaded'] ?? false);
        $this->skipReason = self::readNonEmptyString($repoArray, 'skipReason');
        $this->modelVersion = self::readNonEmptyString($repoArray, 'modelVersion');
    }

    /** @param array<string, mixed> $repoArray */
    protected static function readNonEmptyString(array $repoArray, string $key): ?string
    {
        $value = trim((string)($repoArray[$key] ?? ''));
        return $value !== '' ? $value : null;
    }
}
