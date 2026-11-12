<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Agent;

use DDD\Domain\AI\Services\Conversations\AgentMessagePrinterInterface;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Console-streaming plumbing for one agent loop execution: the CLI output sink, its render flags, and the printer
 * port — bundled as ONE parameter threaded through the loop (generateAgentReply → runConversationLoop →
 * appendTurn/delegate → persistMessage). Null = no streaming (HTTP / Messenger / unit tests).
 *
 * Deliberately a PLAIN class next to the console printer (no `Services` namespace: it is no service), not a DDD
 * ValueObject and not an Entity: it is presentation/IO
 * plumbing, never part of the domain model, never persisted, never serialized (loop runtime, not
 * domain — the very content whose presence on the former AgentRun disqualified it as a domain object).
 * Built with `new` per run, so `#[Exclude]` keeps it out of the container (its console output cannot be autowired).
 */
#[Exclude]
class AgentConsoleStream
{
    public function __construct(
        public readonly OutputInterface $output,
        public readonly AgentMessagePrinterInterface $printer,
        public readonly bool $full = false,
        public readonly bool $debug = false,
        public readonly bool $collapseThreads = false,
        public readonly bool $timings = false,
    ) {
    }

    /** Render a freshly-persisted message to the live console ($conversation = delegation context for the gutter). */
    public function streamMessage(AIConversationMessage $message, ?AIConversation $conversation = null): void
    {
        $this->printer->renderMessage($message, $this->output, $this->full, $this->debug, $this->collapseThreads, $conversation);
    }

    /**
     * Emit a per-operation wall-clock timing line (e.g. "model call (step 2)  2.104s", "getReputation  2.831s") under
     * the message gutter — a no-op unless --timings is active. Lets the operator see WHERE a turn spends its time: the
     * model round-trip (re-prefilling the full system prompt + history each step) vs. a tool's I/O (Argus/LLM).
     */
    public function writeTiming(string $label, float $seconds): void
    {
        if (!$this->timings) {
            return;
        }
        $this->output->writeln(sprintf('         <fg=gray>⏱  %s  %.3fs</>', $label, $seconds));
    }
}
