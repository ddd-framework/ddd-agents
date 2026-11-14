<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AgentMessagePrinterInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What a {@see SlashCommand} needs, handed in at invocation time so the commands keep no state: the live
 * conversation, the console output, the transcript printer of the session (so `/copy` reproduces exactly what was
 * shown), the render switches of the running session, and the registry itself (so `/help` can list the other
 * commands).
 *
 * A plain object of one chat session; no container service.
 */
#[Exclude]
class SlashCommandContext
{
    /**
     * @param AIConversation $conversation the conversation of the session
     * @param OutputInterface $output the console output of the session
     * @param AgentMessagePrinterInterface $printer the printer the session streams through
     * @param SlashCommandRegistry $registry the commands of the session
     * @param bool $full whether tool results are printed in full
     * @param bool $debug whether the request payload of every model call is printed
     * @param bool $collapseThreads whether the inner steps of delegated work are hidden
     */
    public function __construct(
        public readonly AIConversation $conversation,
        public readonly OutputInterface $output,
        public readonly AgentMessagePrinterInterface $printer,
        public readonly SlashCommandRegistry $registry,
        public readonly bool $full,
        public readonly bool $debug,
        public readonly bool $collapseThreads,
    ) {
    }
}
