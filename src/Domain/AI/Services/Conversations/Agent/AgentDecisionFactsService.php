<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionFacts;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Infrastructure\Services\Service;

/**
 * Facts about the space the decision questions read beside the conversation (e.g. whether a result exists) —
 * the application's knowledge, contributed by name. The default contributes none (every question tolerates a missing
 * fact). An application puts a same-path subclass under its own root namespace; the core resolves this service only
 * through `DDDService::instance()->getService(AgentDecisionFactsService::class)`.
 */
class AgentDecisionFactsService extends Service
{
    /**
     * The facts of the skill-preload question for `$conversation`. A fact the application cannot resolve is left out.
     *
     * @param AIConversation $conversation
     * @return AgentDecisionFacts
     */
    public function collectSkillPreloadFacts(AIConversation $conversation): AgentDecisionFacts
    {
        return new AgentDecisionFacts();
    }
}
