<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * `/help` — lists every registered slash command with its description, and the input keys of the line editor
 * (multi-line entry, history). Reads the registry of the context, so it lists whatever is registered.
 */
#[Exclude]
class HelpSlashCommand extends SlashCommand
{
    public function name(): string
    {
        return 'help';
    }

    public function description(): string
    {
        return 'List the available slash commands.';
    }

    public function run(SlashCommandContext $context, string $arguments): void
    {
        $output = $context->output;
        $output->writeln('<comment>Slash commands:</comment>');
        foreach ($context->registry->commands()->getElements() as $command) {
            $output->writeln(sprintf('  <info>/%-8s</info> %s', $command->name(), $command->description()));
        }
        $output->writeln('  <info>/exit    </info> Quit the chat (or /quit, or an empty line).');
        $output->writeln('');
        $output->writeln('<comment>Input:</comment>');
        $output->writeln('  End a line with <info>\\</info> to continue on the next line (multi-line message).');
        $output->writeln('  Ctrl+←/Ctrl+→ jump words; ↑/↓ recall earlier input (session history).');
    }
}
