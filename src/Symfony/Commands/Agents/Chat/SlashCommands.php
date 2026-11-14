<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use DDD\Domain\Base\Entities\ObjectSet;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The slash commands of a chat session ({@see SlashCommandRegistry::commands()}), one per lowercased name, in the
 * order they were registered.
 *
 * A plain object of one chat session; no container service.
 *
 * @property SlashCommand[] $elements
 * @method SlashCommand[] getElements()
 * @method SlashCommand|null first()
 * @method SlashCommand|null getByUniqueKey(string $uniqueKey)
 */
#[Exclude]
class SlashCommands extends ObjectSet
{
    /**
     * @param string $commandName the name as typed, without the slash; matched case-insensitively
     * @return SlashCommand|null the command of that name
     */
    public function getByName(string $commandName): ?SlashCommand
    {
        return $this->getByUniqueKey(SlashCommand::uniqueKeyForName($commandName));
    }

    /** @return string[] the lowercased command names, in registration order */
    public function getNames(): array
    {
        $commandNames = [];
        foreach ($this->getElements() as $slashCommand) {
            $commandNames[] = strtolower($slashCommand->name());
        }
        return $commandNames;
    }
}
