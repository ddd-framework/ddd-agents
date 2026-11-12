<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\SystemPrompts;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One inputs version of the base system prompt that has to be renewed: the level it belongs to and the id of the
 * object on that level. While a database transaction is open,
 * {@see \DDD\Domain\AI\Services\SystemPrompts\AgentBaseSystemPromptCacheService} keeps these and renews the versions
 * once the transaction ended.
 *
 * @phpstan-consistent-constructor
 */
class AgentBaseSystemPromptInputsVersionBump extends ValueObject
{
    /** @var string The level the inputs version belongs to */
    public string $level = '';

    /** @var int The id of the object on that level */
    public int $id = 0;

    /**
     * @param string $level
     * @param int $id
     * @return static
     */
    public static function forLevelAndId(string $level, int $id): static
    {
        $inputsVersionBump = new static();
        $inputsVersionBump->level = $level;
        $inputsVersionBump->id = $id;
        return $inputsVersionBump;
    }

    /** @return string `level.id` — two bumps of the same inputs version share it */
    public function buildLevelAndIdKey(): string
    {
        return $this->level . '.' . $this->id;
    }
}
