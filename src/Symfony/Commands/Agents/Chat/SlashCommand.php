<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One `/`-prefixed command of an interactive console chat with the agent. A command describes itself: its name
 * (without the leading slash), a one-line help text, and the work it does against the {@see SlashCommandContext}. A
 * new command extends this class and is registered once in the chat's {@see SlashCommandRegistry}; `/help` then lists
 * it. A command is identified by its lowercased name ({@see SlashCommands}).
 *
 * A plain object of one chat session; no container service.
 */
#[Exclude]
abstract class SlashCommand extends ValueObject
{
    /** The command name WITHOUT the leading slash, e.g. `copy` for `/copy`. Matched case-insensitively. */
    abstract public function name(): string;

    /** One short line shown by `/help`. */
    abstract public function description(): string;

    /**
     * Does the command's work.
     *
     * @param SlashCommandContext $context the running chat session
     * @param string $arguments everything typed after the command name (trimmed), or ''
     * @return void
     */
    abstract public function run(SlashCommandContext $context, string $arguments): void;

    /**
     * @param string $commandName the name as typed, without the slash
     * @return string the key of the command of that name in a {@see SlashCommands}
     */
    public static function uniqueKeyForName(string $commandName): string
    {
        return self::class . '_' . strtolower($commandName);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyForName($this->name());
    }
}
