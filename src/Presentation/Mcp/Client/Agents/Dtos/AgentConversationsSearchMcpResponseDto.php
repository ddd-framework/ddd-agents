<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\AI\Entities\Conversations\Recall\AgentConversationRecallResult;
use DDD\Domain\AI\Entities\Conversations\Recall\AgentConversationRecallRow;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The result of `searchConversations`: one block per conversation (id, space, dates) with its title, summary and best
 * matching messages as untrusted passages, and one line when conversations of narrower spaces were left out.
 *
 * Example:
 *   - conversation #31008 · space: Admin-Team · 2026-11-27 · "BMW iX M60 vs. Cayenne Turbo Electric"
 *     <untrusted-content source="conversation #31008">…</untrusted-content>
 */
class AgentConversationsSearchMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider
{
    /** @var AgentConversationRecallResult The search result */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentConversationRecallResult $result;

    public static function forResult(AgentConversationRecallResult $result): static
    {
        $responseDto = new static();
        $responseDto->result = $result;
        return $responseDto;
    }

    public function toLlmMarkdown(): string
    {
        $lines = [];
        if ($this->result->rows->isEmpty()) {
            $lines[] = $this->result->query !== ''
                ? 'No earlier conversation matches "' . $this->result->query . '" in the spaces searched. Try other words, a wider date range or no space filter.'
                : 'No earlier conversation found.';
        }
        foreach ($this->result->rows->getElements() as $row) {
            $lines[] = $this->renderRow($row);
        }
        if ($this->result->leftOutForAudienceCount > 0) {
            $lines[] = $this->result->leftOutForAudienceCount . ' matching conversation(s) of private spaces are left out: this conversation is read by others. The owner can search them from their personal space.';
        }
        if (!$this->result->rows->isEmpty()) {
            $lines[] = 'Read a conversation with readConversation and its id.';
        }
        return implode("\n", $lines);
    }

    public function buildPlatformNoteForModel(): ?string
    {
        return !$this->result->rows->isEmpty() ? ToolResultUntrustedSegments::READING_RULE : null;
    }

    protected function renderRow(AgentConversationRecallRow $row): string
    {
        $headParts = ['conversation #' . $row->conversationId, 'space: ' . $row->agentSpace];
        if ($row->updated !== null) {
            $headParts[] = 'last active ' . AgentClock::formatMoment($row->updated);
        }
        $passage = [];
        if ($row->title !== null && $row->title !== '') {
            $passage[] = 'Title: ' . $row->title;
        }
        if ($row->summary !== null && $row->summary !== '') {
            $passage[] = 'Summary: ' . $row->summary;
        }
        foreach ($row->hits->getElements() as $hit) {
            $when = $hit->created !== null ? ' (' . AgentClock::formatMoment($hit->created) . ')' : '';
            $passage[] = '[message ' . ($hit->indexInConversation ?? '?') . '] ' . ($hit->role ?? 'message') . $when . ': ' . $hit->excerpt;
        }
        $text = '- ' . implode(' · ', $headParts);
        if ($passage !== []) {
            $text .= "\n" . ToolResultUntrustedSegments::wrap('conversation #' . $row->conversationId, implode("\n", $passage));
        }
        return $text;
    }
}
