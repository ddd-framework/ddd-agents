<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * IN-conversation semantic search: find the messages of ONE conversation that match a free
 * text query — the in-conversation search box. Hybrid recall (message embeddings + RAPTOR compaction-node summaries)
 * tightened by the LLM reranker.
 */
class AIConversationMessageSearchGetRequestDto extends RequestDto
{
    /** @var int The conversation to search within. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;

    /** @var string The free-text search query. */
    #[Parameter(in: Parameter::QUERY, required: true)]
    public string $query = '';

    /** @var int|null Max hits to return (the service caps 1–N; default applied server-side). */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $limit = null;

    /** @var int|null Excerpt size per hit in characters (0 = full message text). */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $excerptChars = null;
}
