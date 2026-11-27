<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\AI\Entities\Conversations\Recall\SemanticSearchHits;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The result of `searchThisConversation`: the matching messages of the conversation the call runs in, best first, as
 * one untrusted passage, and how to read the dialogue around a hit.
 *
 * Example:
 *   Matches in this conversation (#31008) for "second location":
 *   <untrusted-content source="conversation #31008">[message 14] user (2026-11-27 10:02): The second shop is in Graz …</untrusted-content>
 *   Read the dialogue around a match with readConversation, conversationId 31008 and aroundIndex = its message number.
 */
class AgentConversationSearchThisMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider
{
    /** @var string The query searched for */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $query = '';

    /** @var int The conversation searched (the conversation the owner talks in) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $conversationId = 0;

    /** @var SemanticSearchHits The matching messages, best first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public SemanticSearchHits $hits;

    /** @var bool True when the search could not run (the index did not answer); the hits are then empty */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public bool $searchFailed = false;

    public function __construct()
    {
        parent::__construct();
        $this->hits = new SemanticSearchHits();
    }

    public static function forHits(string $query, int $conversationId, SemanticSearchHits $hits): static
    {
        $responseDto = new static();
        $responseDto->query = $query;
        $responseDto->conversationId = $conversationId;
        $responseDto->hits = $hits;
        return $responseDto;
    }

    public static function forFailedSearch(string $query, int $conversationId): static
    {
        $responseDto = new static();
        $responseDto->query = $query;
        $responseDto->conversationId = $conversationId;
        $responseDto->searchFailed = true;
        return $responseDto;
    }

    public function toLlmMarkdown(): string
    {
        if ($this->searchFailed) {
            return 'This conversation could not be searched right now. Answer from the context you have, or ask the owner to restate the detail.';
        }
        if ($this->hits->isEmpty()) {
            return 'No message of this conversation matches "' . $this->query . '". Only this conversation was searched: something the owner says was discussed BEFORE is in an earlier conversation, search it with searchConversations before you say nothing was found. Here a differently worded query may still find it.';
        }
        $passage = [];
        foreach ($this->hits->getElements() as $hit) {
            $when = $hit->created !== null ? ' (' . AgentClock::formatMoment($hit->created) . ')' : '';
            $passage[] = '[message ' . ($hit->indexInConversation ?? '?') . '] ' . ($hit->role ?? 'message') . $when . ': ' . $hit->excerpt;
        }
        return implode("\n", [
            'Matches in this conversation (#' . $this->conversationId . ') for "' . $this->query . '", best first:',
            ToolResultUntrustedSegments::wrap('conversation #' . $this->conversationId, implode("\n", $passage)),
            'Read the dialogue around a match with readConversation, conversationId ' . $this->conversationId . ' and aroundIndex = its message number.',
        ]);
    }

    public function buildPlatformNoteForModel(): ?string
    {
        return !$this->hits->isEmpty() ? ToolResultUntrustedSegments::READING_RULE : null;
    }
}
