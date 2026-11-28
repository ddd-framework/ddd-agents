<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\AI\Entities\Conversations\Recall\AgentConversationRecallResult;
use DDD\Domain\AI\Entities\Conversations\Recall\AgentConversationRecallRow;
use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationSearchGroup;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindableResult;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationSearchResultsConversationWidget;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The result of `searchConversations`: one block per conversation (id, space, dates) with its title, summary and best
 * matching messages as untrusted passages, and one line when conversations of narrower spaces were left out.
 *
 * The conversations with matching messages are also the top-level `groups` (the shape of the chat's own search) and
 * the result names the ready `ConversationSearchResults` block (`conversationSearchResultsWidget`): the model copies
 * the block, the platform fills its groups from this call's stored structured shape (stored next to the markdown, as
 * {@see ComposedViewBindableResult})
 * ({@see \DDD\Domain\AI\Services\Conversations\ComposedViewBindingResolutionService::fillConversationSearchResultsGroupsOfRow()}).
 *
 * Example:
 *   - conversation #31008 · space: Admin-Team · 2026-11-27 · "BMW iX M60 vs. Cayenne Turbo Electric"
 *     <untrusted-content source="conversation #31008">…</untrusted-content>
 */
class AgentConversationsSearchMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider, ComposedViewBindableResult
{
    /** @var int The widget's limit for its `query` field */
    protected const int WIDGET_QUERY_MAX_CHARS = 200;

    /** @var AgentConversationRecallResult The search result */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentConversationRecallResult $result;

    /** @var string The query searched for (what the widget's groups are filled with, beside `groups`) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public string $query = '';

    /** @var AIConversationSearchGroup[] The conversations with matching messages, best first: the groups of the ConversationSearchResults widget */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public array $groups = [];

    /**
     * @var string|null The JSON of the ready `ConversationSearchResults` block (`type`, `query`, `groupsFromToolCallId` =
     * this call); null without matching messages or outside the agent loop
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $conversationSearchResultsWidget = null;

    /**
     * @param string|null $toolCallId The provider's id of this call; null outside the agent loop (no block is offered)
     */
    public static function forResult(AgentConversationRecallResult $result, ?string $toolCallId = null): static
    {
        $responseDto = new static();
        $responseDto->result = $result;
        $responseDto->query = $result->query;
        foreach ($result->rows->getElements() as $row) {
            $group = $row->toConversationSearchGroup();
            if ($group !== null) {
                $responseDto->groups[] = $group;
            }
        }
        if ($responseDto->groups !== [] && $toolCallId !== null && trim($toolCallId) !== '') {
            $responseDto->conversationSearchResultsWidget = static::buildConversationSearchResultsWidgetJson($result->query, $toolCallId);
        }
        return $responseDto;
    }

    /** The block that names this call: the platform writes its groups into it when the answer is stored */
    protected static function buildConversationSearchResultsWidgetJson(string $query, string $toolCallId): ?string
    {
        $widgetJson = json_encode([
            'type' => ConversationSearchResultsConversationWidget::WIDGET_TYPE,
            'query' => mb_substr($query, 0, static::WIDGET_QUERY_MAX_CHARS),
            ConversationSearchResultsConversationWidget::FIELD_GROUPS_FROM_TOOL_CALL_ID => $toolCallId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($widgetJson) ? $widgetJson : null;
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
            $lines[] = 'Read a conversation with readConversation and its id; around one match with aroundIndex = its message number.';
        }
        if ($this->conversationSearchResultsWidget !== null) {
            $lines[] = 'To SHOW the matching conversations (the owner wants to see or open them): one short line in the owner\'s language, then a `widget` block with exactly this JSON (you may add a short `title`; never write `groups`, the platform fills them): '
                . $this->conversationSearchResultsWidget
                . ' On a phone call there is no block: name the conversations. To SUMMARISE what was discussed: prose grounded in the matches, without the block.';
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
        if ($row->owner !== null) {
            $headParts[] = 'of: ' . $row->owner . ' (not the owner\'s own conversation)';
        }
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
