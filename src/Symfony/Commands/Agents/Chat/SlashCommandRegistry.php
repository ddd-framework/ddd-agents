<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The `/` commands of an interactive console chat, by name. The chat loop checks every input line: a line that
 * starts with `/` goes to {@see dispatch()} instead of to the agent. A chat command registers its commands once
 * (`register(new MyCommand())`); `/help` then lists them.
 *
 * A plain object of one chat session; no container service.
 */
#[Exclude]
class SlashCommandRegistry
{
    /** @var SlashCommands The registered commands, one per lowercased name without the slash */
    protected SlashCommands $commands;

    public function __construct()
    {
        $this->commands = new SlashCommands();
    }

    /**
     * Registers a command; one registered earlier under the same name is replaced in its place.
     *
     * @param SlashCommand $command
     * @return void
     */
    public function register(SlashCommand $command): void
    {
        $this->commands->replace($command);
    }

    /** @return SlashCommands every registered command, in registration order */
    public function commands(): SlashCommands
    {
        return $this->commands;
    }

    /**
     * Whether the input line is a slash command (starts with `/` after leading blanks).
     *
     * @param string $line
     * @return bool
     */
    public function isSlashCommand(string $line): bool
    {
        return str_starts_with(ltrim($line), '/');
    }

    /**
     * Runs a slash-command line: splits `/name rest of line` into the (case-insensitive) command name and its
     * argument text, runs the matching command, and reports an unknown command (pointing at `/help`).
     *
     * @param string $line the input line
     * @param SlashCommandContext $context the running session
     * @return bool false only for `/exit` and `/quit` (the loop ends); true otherwise
     */
    public function dispatch(string $line, SlashCommandContext $context): bool
    {
        $line = ltrim($line);
        $lineWithoutSlash = substr($line, 1);
        $lineParts = preg_split('/\s+/', trim($lineWithoutSlash), 2) ?: [];
        $commandName = strtolower($lineParts[0] ?? '');
        $arguments = trim($lineParts[1] ?? '');

        if ($commandName === 'exit' || $commandName === 'quit') {
            return false;
        }

        $command = $this->commands->getByName($commandName);
        if ($command === null) {
            $context->output->writeln("<error>Unknown command: /$commandName</error> <fg=gray>(type /help)</>");
            return true;
        }
        $command->run($context, $arguments);
        return true;
    }
}
