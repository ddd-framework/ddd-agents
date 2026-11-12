<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the reasoning-effort site asks at one turn start: PHP's precondition verdict
 * (whether the questions ride the turn-start request at all), the active and ceiling effort the decision model is told,
 * and the owner turn it judges. Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentReasoningEffortDecisionsService::buildReasoningEffortRequestInput()}.
 */
class AgentReasoningEffortDecisionQuestion extends ValueObject
{
    /** The site mode for this conversation (`on` applies, `shadow` records only). */
    public string $mode = AgentDecisionModelSettings::MODE_OFF;

    /** The index of the owner message that started this turn. */
    public int $initiatorMessageIndex = 0;

    /** False = the precondition decides structurally; the questions are not sent. */
    public bool $askDecisionModel = false;

    /** The {@see AgentReasoningEffortDecision}::PRECONDITION_* that admitted (or did not admit) the turn. */
    public string $preconditionReason = '';

    /** The turn's ACTIVE effort before any decision; a raise is judged above it. */
    public ?string $floorEffort = null;

    /** Where the active effort comes from (a FLOOR_SOURCE_*). */
    public ?string $floorSource = null;

    /** The catalog ceiling no effort exceeds. */
    public string $ceilingEffort = '';

    public string $ownerMessage = '';

    /** The last assistant text of the previous assistant turn. */
    public string $assistantTextBefore = '';

    /** The stored facts PHP already holds, one per line. */
    public string $storedFacts = '';

    /** The first open item of the task plan ('' = none). */
    public string $nextPlanItem = '';

    /** Two differing stored values for one profile field ('' = none). */
    public string $disagreement = '';
}
