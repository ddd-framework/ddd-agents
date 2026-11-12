<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Domain port for live-streaming an agent message to a console as the loop produces it. The agent loop depends only
 * on this interface; the concrete renderer ({@see \DDD\Presentation\Mcp\Agent\AgentConsolePrinter}) lives in
 * Presentation. Dependency direction stays inward: Presentation implements the Domain port. (The loop already takes a
 * Symfony `OutputInterface` for its `--output` streaming, so the console coupling is pre-existing, not new.)
 *
 * $conversation is the conversation the message belongs to (a message alone can no longer tell whether it
 * is part of a delegation — the renderer derives the delegation gutter/label from `conversation->isDelegation()` /
 * `delegatedFromMessage`). Null = render without delegation context (legacy callers).
 *
 * Fetched by this interface name (`getService(AgentMessagePrinterInterface::class)`): the id is a public alias of its
 * one implementation, {@see \DDD\Presentation\Mcp\Agent\AgentConsolePrinter} (`#[AsAlias(public: true)]` there). An
 * application replaces the printer by aliasing this id to its own implementation; a second implementation without
 * that alias leaves the id on the package's printer.
 */
interface AgentMessagePrinterInterface
{
    public function renderMessage(
        AIConversationMessage $message,
        OutputInterface $output,
        bool $full = false,
        bool $debug = false,
        bool $collapseThreads = false,
        ?AIConversation $conversation = null
    ): void;

    /**
     * Render the WHOLE conversation (every turn: user/agent messages, tool calls + their FULL results, delegation
     * child steps, and the per-turn token/cost line) into a single plain-text string — the same content the live
     * loop streamed to the terminal, but captured so it can be copied to the clipboard (`/copy`). $full forces
     * un-collapsed tool results (the copy carries the complete tool output, not the "N chars collapsed" summary).
     */
    public function renderTranscript(
        AIConversation $conversation,
        bool $full = true,
        bool $debug = false,
        bool $collapseThreads = false
    ): string;
}
