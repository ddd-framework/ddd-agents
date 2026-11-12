<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a meta tool that refuses under a purpose-seeded task plan reads to decide its refusal:
 * whether the conversation's purpose seeds its plan, whether the plan exists, whether the plan's next item belongs to the
 * purpose's closing act, and whether the conversation may delegate at all. The dispatch gathers it only for a tool that
 * declares the refusal ({@see AgentSeededPlanRefusingMetaTool}); the tool decides.
 *
 * @phpstan-consistent-constructor
 */
class AgentSeededPlanPurposeFacts extends ValueObject
{
    /** The conversation's purpose seeds its task plan (e.g. an intake, a nightly digest). */
    public bool $purposeSeedsItsTaskPlan = false;

    /** The conversation carries a task plan; false when the purpose seeds none. */
    public bool $hasTaskPlan = false;

    /** The plan's next item belongs to the purpose's closing act; false when the purpose seeds no plan. */
    public bool $nextPlanItemIsInClosingAct = false;

    /** The conversation may start delegation children (a delegation child may not). */
    public bool $conversationMayDelegate = false;

    /** A conversation whose purpose seeds no task plan: the plan facts are not read. */
    public static function withoutSeededPlan(bool $conversationMayDelegate): static
    {
        $agentSeededPlanPurposeFacts = new static();
        $agentSeededPlanPurposeFacts->conversationMayDelegate = $conversationMayDelegate;
        return $agentSeededPlanPurposeFacts;
    }

    /** A conversation whose purpose seeds its task plan. */
    public static function withSeededPlan(bool $hasTaskPlan, bool $nextPlanItemIsInClosingAct, bool $conversationMayDelegate): self
    {
        $agentSeededPlanPurposeFacts = self::withoutSeededPlan($conversationMayDelegate);
        $agentSeededPlanPurposeFacts->purposeSeedsItsTaskPlan = true;
        $agentSeededPlanPurposeFacts->hasTaskPlan = $hasTaskPlan;
        $agentSeededPlanPurposeFacts->nextPlanItemIsInClosingAct = $nextPlanItemIsInClosingAct;
        return $agentSeededPlanPurposeFacts;
    }
}
