<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/** The highest level one capability of a connection may be used at through one release */
class AgentConnectionCapabilityCeiling extends ValueObject
{
    #[Choice(choices: AgentConnectionPermission::CAPABILITIES)]
    public string $capability = AgentConnectionPermission::CAPABILITY_MAIL;

    #[Choice(choices: AgentConnectionPermission::LEVELS)]
    public string $level = AgentConnectionPermission::LEVEL_READ;

    public static function of(string $capability, string $level): static
    {
        $capabilityCeiling = static::newInstance();
        $capabilityCeiling->capability = $capability;
        $capabilityCeiling->level = $level;
        return $capabilityCeiling;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->capability);
    }
}
