<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use DDD\Domain\AI\Services\Conversations\AgentMessagePrinterInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * `/copy` — copies the whole conversation as the terminal showed it (every owner and agent message, every tool call
 * with its FULL result, the per-turn token and cost lines, the loaded skills) to the system clipboard. The text comes
 * from the printer the live loop streamed through ({@see AgentMessagePrinterInterface::renderTranscript()}), so there
 * is no second formatter.
 *
 * The clipboard is `pbcopy` (macOS), started as a {@see Process}. Without it the transcript is written to a temporary
 * file and its path printed; without that too, the transcript is printed. Tool results are always copied in full,
 * not as the collapsed terminal summary.
 */
#[Exclude]
class CopySlashCommand extends SlashCommand
{
    /** @var string The prefix of the temporary file the transcript falls back to */
    public const string FALLBACK_FILE_PREFIX = 'agent-chat-';

    public function name(): string
    {
        return 'copy';
    }

    public function description(): string
    {
        return 'Copy the whole conversation (full tool results included) to the system clipboard.';
    }

    public function run(SlashCommandContext $context, string $arguments): void
    {
        $transcript = $context->printer->renderTranscript(
            $context->conversation,
            true,
            $context->debug,
            $context->collapseThreads
        );
        $lineCount = substr_count($transcript, "\n") + 1;
        $charCount = mb_strlen($transcript);

        if ($this->copyToClipboard($transcript)) {
            $context->output->writeln(sprintf('<info>Copied %d lines / %d chars to clipboard.</info>', $lineCount, $charCount));
            return;
        }

        $fallbackPath = tempnam(sys_get_temp_dir(), self::FALLBACK_FILE_PREFIX) . '.txt';
        if (@file_put_contents($fallbackPath, $transcript) !== false) {
            $context->output->writeln(sprintf(
                '<comment>Clipboard unavailable — wrote %d lines / %d chars to:</comment> %s',
                $lineCount,
                $charCount,
                $fallbackPath
            ));
            return;
        }

        $context->output->writeln('<comment>Clipboard and temp file both unavailable — printing the transcript:</comment>');
        $context->output->writeln($transcript);
    }

    /**
     * Pipes the text to `pbcopy`.
     *
     * @param string $text
     * @return bool true on success; false when the tool is missing or the pipe failed
     */
    protected function copyToClipboard(string $text): bool
    {
        try {
            $process = new Process(['pbcopy']);
            $process->setInput($text);
            $process->run();
            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }
}
