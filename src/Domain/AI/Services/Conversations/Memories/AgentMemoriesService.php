<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingRepairTargets;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemoryHits;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentStandingDirectives;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * What the agent core asks of the durable memory (the owner's standing directives, curated facts and solutions) — the
 * interim seam of the optional memory component: the directives of a scope, the curation after a turn, a similarity
 * search, and the memory table the embedding repair covers.
 *
 * Every default answers "no memory". An application with a memory component puts a same-path subclass under its own root
 * namespace; the core resolves this service only through `DDDService::instance()->getService(AgentMemoriesService::class)`.
 */
class AgentMemoriesService extends Service
{
    /**
     * The currently valid standing directives of account `$accountId` that apply to `$agentSpace` (null = the
     * account-wide ones only). The default: null (no directives).
     *
     * @param int $accountId
     * @param AgentSpace|null $agentSpace
     * @return AgentStandingDirectives|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findStandingDirectivesForAgentSpace(int $accountId, ?AgentSpace $agentSpace): ?AgentStandingDirectives
    {
        return null;
    }

    /**
     * Curates the memory from the turn `$conversation` just answered (`$async` = in the background). Best effort: a
     * curation failure must never fail the answered turn. The default does nothing.
     *
     * @param AIConversation $conversation
     * @param bool $async
     * @return void
     */
    public function curateConversationAfterTurn(AIConversation $conversation, bool $async): void
    {
    }

    /**
     * The memories of account `$accountId` that apply to `$agentSpace` (null = the account-wide ones only) most
     * similar to `$query`, at most `$limit`. The default: none.
     *
     * @param string $query
     * @param int $accountId
     * @param AgentSpace|null $agentSpace
     * @param int $limit
     * @return AgentMemoryHits
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findSimilarMemories(string $query, int $accountId, ?AgentSpace $agentSpace, int $limit): AgentMemoryHits
    {
        return new AgentMemoryHits();
    }

    /**
     * The memory tables whose zero vectors the embedding repair re-embeds. The default: none.
     *
     * @return AgentEmbeddingRepairTargets
     */
    public function getEmbeddingRepairTargets(): AgentEmbeddingRepairTargets
    {
        return new AgentEmbeddingRepairTargets();
    }
}
