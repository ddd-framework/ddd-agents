<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\Base\Entities\ValueObject;

/** A level the owner wants for one capability of a connection: applied once the provider granted what it needs. */
class AgentConnectionLevelRequest extends ValueObject
{
    /** @var string One of AgentConnectionPermission::CAPABILITY_* */
    public string $capability = '';

    /** @var string One of AgentConnectionPermission::LEVELS */
    public string $level = AgentConnectionPermission::LEVEL_READ;

    public static function of(string $capability, string $level): static
    {
        $levelRequest = new static();
        $levelRequest->capability = $capability;
        $levelRequest->level = $level;
        return $levelRequest;
    }
}
