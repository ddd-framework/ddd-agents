<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\AI\Entities\Conversations\Recall\AgentConversationRecallTranscript;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The result of `readConversation`: one header line (conversation, space, title, the next page) and the dialogue of
 * the page as one untrusted passage.
 */
class AgentConversationReadMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider
{
    /** @var AgentConversationRecallTranscript The page read */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentConversationRecallTranscript $transcript;

    public static function forTranscript(AgentConversationRecallTranscript $transcript): static
    {
        $responseDto = new static();
        $responseDto->transcript = $transcript;
        return $responseDto;
    }

    public function toLlmMarkdown(): string
    {
        $row = $this->transcript->conversation;
        $headParts = ['conversation #' . $row->conversationId, 'space: ' . $row->agentSpace];
        if ($row->owner !== null) {
            $headParts[] = 'of: ' . $row->owner . ' (not the owner\'s own conversation)';
        }
        if ($row->title !== null && $row->title !== '') {
            $headParts[] = '"' . str_replace(["\n", '"'], [' ', "'"], $row->title) . '"';
        }
        if ($this->transcript->aroundIndex !== null) {
            $headParts[] = 'around message ' . $this->transcript->aroundIndex;
        }
        $headParts[] = $this->transcript->nextFromIndex !== null ? 'more: fromIndex ' . $this->transcript->nextFromIndex : 'end of conversation';
        $lines = [implode(' · ', $headParts)];
        if ($this->transcript->messages->isEmpty()) {
            $lines[] = 'No messages from this index on.';
            return implode("\n", $lines);
        }
        $dialogue = [];
        foreach ($this->transcript->messages->getElements() as $message) {
            $when = $message->created !== null ? ' (' . AgentClock::formatMoment($message->created) . ')' : '';
            $matchMark = $this->transcript->aroundIndex === $message->indexInConversation ? ' (the match)' : '';
            $dialogue[] = '[' . $message->indexInConversation . '] ' . $message->role . $when . $matchMark . ': ' . $message->text;
        }
        $lines[] = ToolResultUntrustedSegments::wrap('conversation #' . $row->conversationId, implode("\n", $dialogue));
        return implode("\n", $lines);
    }

    public function buildPlatformNoteForModel(): ?string
    {
        return !$this->transcript->messages->isEmpty() ? ToolResultUntrustedSegments::READING_RULE : null;
    }
}
