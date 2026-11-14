<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Chat;

use DDD\Symfony\Commands\Agents\Chat\SlashCommand;
use DDD\Symfony\Commands\Agents\Chat\SlashCommandContext;

/**
 * A slash command of a name the test chooses that records the argument text of every run.
 */
class RecordingSlashCommandDouble extends SlashCommand
{
    /** @var string[] The argument text of every run, in order */
    public array $recordedArguments = [];

    public function __construct(protected string $commandName, protected string $commandDescription = 'Record the arguments.')
    {
        parent::__construct();
    }

    public function name(): string
    {
        return $this->commandName;
    }

    public function description(): string
    {
        return $this->commandDescription;
    }

    public function run(SlashCommandContext $context, string $arguments): void
    {
        $this->recordedArguments[] = $arguments;
    }
}
