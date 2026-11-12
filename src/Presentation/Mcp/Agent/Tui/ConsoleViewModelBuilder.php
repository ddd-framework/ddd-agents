<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Agent\Tui;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentTurn;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Environment\AgentApplicationNames;
use DDD\Presentation\Mcp\Agent\ConsoleMarkdownRenderer;

/**
 * Builds a {@see ConsoleViewModel} from an {@see AIConversation}: one block per message. Tool-call blocks are
 * collapsible (header = the call line, body = the result, collapsed by default — the click-to-expand target);
 * assistant text is Markdown-rendered to PLAIN lines (no ANSI) so the blocks stay substrate-neutral and the TUI
 * shell owns styling. Pure (reads the conversation, no repo/terminal) → unit-tested.
 */
class ConsoleViewModelBuilder
{
    protected ConsoleMarkdownRenderer $markdownRenderer;

    public function __construct(?ConsoleMarkdownRenderer $markdownRenderer = null)
    {
        $this->markdownRenderer = $markdownRenderer ?? new ConsoleMarkdownRenderer();
    }

    /** @param bool $expandToolResults start tool-result blocks expanded (the `--full` flavor) instead of collapsed */
    public function fromConversation(AIConversation $conversation, bool $expandToolResults = false): ConsoleViewModel
    {
        $model = new ConsoleViewModel();
        // Iterate per TURN so each turn can be followed by its own metrics line (tokens/cost/steps/tools/
        // delegations) — the per-turn accounting the line console only printed transiently.
        foreach ($conversation->buildAgentTurns() as $turnIndex => $turn) {
            foreach ($turn->messages as $message) {
                $block = $this->blockFor($message, $expandToolResults);
                if ($block !== null) {
                    $model->append($block);
                }
            }
            $model->append($this->metricsBlock($turn, $turnIndex));
        }
        return $model;
    }

    /** A dim, non-collapsible per-turn accounting line: tokens · cost · steps · tool calls · delegations. */
    protected function metricsBlock(AgentTurn $turn, int $turnIndex): ConsoleViewBlock
    {
        $toolCalls = 0;
        foreach ($turn->messages as $message) {
            if ($message->messageType === AIConversationMessage::MESSAGE_TOOL_CALL) {
                $toolCalls++;
            }
        }
        $line = sprintf(
            '· turn: %d tok · $%.4f · %d steps · %d tool calls · %d delegations',
            $turn->totalTokens(),
            $turn->totalCostUsd(),
            $turn->steps(),
            $toolCalls,
            count($turn->delegations()),
        );
        return new ConsoleViewBlock('metrics-' . $turnIndex, $line);
    }

    protected function blockFor(AIConversationMessage $message, bool $expandToolResults): ?ConsoleViewBlock
    {
        $id = $message->id !== null ? 'm' . $message->id : 'i' . ($message->indexInConversation ?? 0);

        return match ($message->messageType) {
            AIConversationMessage::MESSAGE_TEXT => $this->textBlock($message, $id),
            AIConversationMessage::MESSAGE_TOOL_CALL => $this->toolBlock($message, $id, $expandToolResults),
            AIConversationMessage::MESSAGE_ERROR => new ConsoleViewBlock($id, '✗ ' . ($message->message ?? '')),
            default => null,
        };
    }

    protected function textBlock(AIConversationMessage $message, string $id): ConsoleViewBlock
    {
        if (($message->role ?? null) === AIConversationMessage::ROLE_USER) {
            return new ConsoleViewBlock($id, 'you> ' . ($message->message ?? ''));
        }
        // Assistant: Markdown → plain lines (the shell styles); first line follows the assistant label.
        $lines = $this->markdownRenderer->render((string)($message->message ?? ''), decorated: false);
        $first = array_shift($lines) ?? '';
        return new ConsoleViewBlock($id, AgentApplicationNames::getConsoleAssistantLabel() . '> ' . $first, $lines, collapsible: false);
    }

    protected function toolBlock(AIConversationMessage $message, string $id, bool $expandToolResults): ConsoleViewBlock
    {
        $name = $message->toolCall->name ?? '?';
        $args = $message->toolCall?->argumentsAsJson() ?? '';
        $status = $message->toolCall->status ?? '';
        $result = (string)($message->toolCall->result ?? '');
        $resultLines = $result === '' ? [] : explode("\n", $result);
        $collapsible = $resultLines !== [];

        $marker = !$collapsible ? '' : '  ▸ ' . mb_strlen($result) . ' chars (click to expand)';
        $header = sprintf('→ %s(%s) [%s]%s', $name, $args, $status, $marker);

        return new ConsoleViewBlock($id, $header, $resultLines, collapsible: $collapsible, expanded: $collapsible && $expandToolResults);
    }
}
