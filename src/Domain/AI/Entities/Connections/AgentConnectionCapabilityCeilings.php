<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The ceilings of one release, one per released capability. A capability not named is not released.
 *
 * @property AgentConnectionCapabilityCeiling[] $elements
 * @method AgentConnectionCapabilityCeiling[] getElements()
 */
class AgentConnectionCapabilityCeilings extends ObjectSet
{
    /** @param array<string, string> $levelsByCapability capability => level; unknown entries and OFF are left out */
    public static function fromLevelsByCapability(array $levelsByCapability): static
    {
        $capabilityCeilings = new static();
        foreach ($levelsByCapability as $capability => $level) {
            if (in_array($capability, AgentConnectionPermission::CAPABILITIES, true) && in_array($level, AgentConnectionPermission::LEVELS, true) && $level !== AgentConnectionPermission::LEVEL_OFF) {
                $capabilityCeiling = AgentConnectionCapabilityCeiling::of((string)$capability, $level);
                $capabilityCeilings->add($capabilityCeiling);
            }
        }
        return $capabilityCeilings;
    }

    /** The ceiling of one capability; OFF when it is not released */
    public function getLevelOf(string $capability): string
    {
        foreach ($this->getElements() as $capabilityCeiling) {
            if ($capabilityCeiling->capability === $capability && in_array($capabilityCeiling->level, AgentConnectionPermission::LEVELS, true)) {
                return $capabilityCeiling->level;
            }
        }
        return AgentConnectionPermission::LEVEL_OFF;
    }

    /** Only known capabilities and levels, OFF left out */
    public function normalized(): static
    {
        $levelsByCapability = [];
        foreach ($this->getElements() as $capabilityCeiling) {
            $levelsByCapability[$capabilityCeiling->capability] = $capabilityCeiling->level;
        }
        return static::fromLevelsByCapability($levelsByCapability);
    }

    public function fingerprintText(): string
    {
        $lines = [];
        foreach ($this->getElements() as $capabilityCeiling) {
            $lines[] = $capabilityCeiling->capability . ':' . $capabilityCeiling->level;
        }
        sort($lines);
        return implode("\n", $lines);
    }
}
