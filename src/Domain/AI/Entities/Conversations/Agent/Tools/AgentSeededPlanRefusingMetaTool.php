<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

/**
 * A meta tool that refuses its call in a conversation whose purpose seeds the task plan: the
 * tool answers the refusal itself from the facts the dispatch gathers for it; each tool has its own condition.
 */
interface AgentSeededPlanRefusingMetaTool extends AgentMetaTool
{
    /** The refusal of this tool's call under $agentSeededPlanPurposeFacts; null when the call proceeds. */
    public static function findSeededPlanRefusal(AgentSeededPlanPurposeFacts $agentSeededPlanPurposeFacts): ?AgentMetaToolCallAnswer;
}
