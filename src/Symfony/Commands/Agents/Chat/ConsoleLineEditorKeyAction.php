<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The editing action one key stands for in the {@see ConsoleLineEditor}: its type (`insert`, `left`, `submit`, …,
 * `noop` for a key without meaning) and, for an insert, the character to insert.
 *
 * A plain object of one chat session; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class ConsoleLineEditorKeyAction
{
    /** @var string The action type of a printable key */
    public const string TYPE_INSERT = 'insert';

    /**
     * @param string $type the action type
     * @param string|null $insertedCharacter the UTF-8 character an insert adds; null for every other type
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $insertedCharacter = null,
    ) {
    }

    /**
     * @param string $character
     * @return static the insert of one character
     */
    public static function forInsertedCharacter(string $character): static
    {
        return new static(self::TYPE_INSERT, $character);
    }
}
