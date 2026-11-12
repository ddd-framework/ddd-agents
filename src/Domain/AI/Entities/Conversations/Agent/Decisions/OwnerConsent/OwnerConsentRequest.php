<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a tool asks the owner-consent check: the step it is about to take, named by its TARGET and its
 * CONSEQUENCE. Both lines are built by the tool from what it LOADED (English, never the model's prose), so a yes to
 * another target, another value or two records instead of one is no consent to this step. One threshold, 0.90, for
 * every kind (set after a probe of recorded dialogues: it lies above every trap and below every genuine consent — a
 * higher one for deletions refused part of the owners' clear yeses).
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

    /** Both probabilities must reach this. */
    public float $minimumProbability = self::MINIMUM_PROBABILITY;

    /**
     * A stable key of the bound fields (e.g. the sorted record ids, the new value) — for the use record and the
     * telemetry.
     */
    public string $targetKey = '';

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
        $request->minimumProbability = self::MINIMUM_PROBABILITY;
        return $request;
    }
}
