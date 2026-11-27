<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a tool asks the owner-consent check: the step it is about to take, named by its TARGET and its
 * CONSEQUENCE. Both lines are built by the tool from what it LOADED (English, never the model's prose), so a yes to
 * another target, another value or two records instead of one is no consent to this step. The thresholds come from
 * `AI.ownerConsent` ({@see OwnerConsentSettings}), one for each probability, the same for every kind of step; the
 * default 0.90 was set after a probe of recorded dialogues: it lies above every trap and below every genuine consent
 * (a higher one for deletions refused part of the owners' clear yeses).
 *
 * @phpstan-consistent-constructor
 */
class OwnerConsentRequest extends ValueObject
{
    public const float MINIMUM_PROBABILITY = 0.90;

    /** The tool that asks (`deleteProject`, `updateBusinessAddress`, …) — the use record and the telemetry key on it. */
    public string $toolName = '';

    /** The kind of step, named by the tool that asks (`PROJECT_DELETE`, `ADDRESS_CHANGE`, …) — telemetry only. */
    public string $actionKind = '';

    /** The step with its target, e.g. "change the business address from Hauptstraße 26, 10827 Berlin to Kaiserdamm 4, 14057 Berlin". */
    public string $targetLine = '';

    /** What the step means for the owner, e.g. "Google verifies the Business Profile again; until then the listing can lose visibility". */
    public string $consequenceLine = '';

    /** The probability that the agent explained the step must reach this ({@see OwnerConsentSettings::$minimumStepExplainedProbability}). */
    public float $minimumStepExplainedProbability = self::MINIMUM_PROBABILITY;

    /** The probability that the owner agreed to the step must reach this ({@see OwnerConsentSettings::$minimumOwnerAgreedProbability}). */
    public float $minimumOwnerAgreedProbability = self::MINIMUM_PROBABILITY;

    /**
     * The higher of the two thresholds — for display and telemetry only; the verdict reads
     * {@see $minimumStepExplainedProbability} and {@see $minimumOwnerAgreedProbability}.
     *
     * @deprecated since 0.7.7: read the two thresholds
     */
    public float $minimumProbability = self::MINIMUM_PROBABILITY;

    /**
     * A stable key of the bound fields (e.g. the sorted record ids, the new value) — for the use record and the
     * telemetry.
     */
    public string $targetKey = '';

    /**
     * @var int|null The agent's own text row that showed the step's exact content (checked by the tool), or null. The
     *     consent dialogue then carries that row as the explanation, so an owner message that orders sending what was
     *     shown is a yes in one pass; an unrelated message stays no. Evidence only: the decision model still decides.
     */
    public ?int $shownContentMessageIndex = null;

    /**
     * The request for one step from free lines — the replay command's entry (the probe corpus). The tools build theirs
     * from what they loaded through their package's own factory (a method of the application's tool package,
     * outside the AI core).
     */
    public static function forAction(string $toolName, string $actionKind, string $targetLine, string $consequenceLine, string $targetKey): static
    {
        $request = new static();
        $request->toolName = $toolName;
        $request->actionKind = $actionKind;
        $request->targetLine = $targetLine;
        $request->consequenceLine = $consequenceLine;
        $request->targetKey = $targetKey;
        $request->applyOwnerConsentSettings(OwnerConsentSettings::current());
        return $request;
    }

    /** Takes the thresholds of the settings. */
    public function applyOwnerConsentSettings(OwnerConsentSettings $ownerConsentSettings): static
    {
        $this->minimumStepExplainedProbability = $ownerConsentSettings->minimumStepExplainedProbability;
        $this->minimumOwnerAgreedProbability = $ownerConsentSettings->minimumOwnerAgreedProbability;
        $this->minimumProbability = max($this->minimumStepExplainedProbability, $this->minimumOwnerAgreedProbability);
        return $this;
    }
}
