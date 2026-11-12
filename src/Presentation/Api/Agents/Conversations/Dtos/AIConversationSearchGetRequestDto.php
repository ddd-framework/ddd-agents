<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * OVERALL "search all my chats": semantic search across ALL of the owner's
 * conversations, results GROUPED by conversation. Hybrid recall (message embeddings + RAPTOR compaction-node
 * summaries) tightened by the LLM reranker; account-scoped by the read-rights layer.
 */
class AIConversationSearchGetRequestDto extends RequestDto
{
    /** @var string The free-text search query. */
    #[Parameter(in: Parameter::QUERY, required: true)]
    public string $query = '';

    /** @var int|null Max conversations (groups) to return. */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $limit = null;

    /** @var int|null Excerpt size per hit in characters (0 = full message text). */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $excerptChars = null;
}
