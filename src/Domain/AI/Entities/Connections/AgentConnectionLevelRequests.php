<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A list of {@see AgentConnectionLevelRequest}.
 *
 * @property AgentConnectionLevelRequest[] $elements
 * @method AgentConnectionLevelRequest[] getElements()
 * @method AgentConnectionLevelRequest|null first()
 */
class AgentConnectionLevelRequests extends ObjectSet
{
    /** @return array<string, string> capability => level, the stored form inside a signed connect state */
    public function toLevelsByCapability(): array
    {
        $levelsByCapability = [];
        foreach ($this->getElements() as $levelRequest) {
            $levelsByCapability[$levelRequest->capability] = $levelRequest->level;
        }
        return $levelsByCapability;
    }

    /** @param array<string, string> $levelsByCapability */
    public static function fromLevelsByCapability(array $levelsByCapability): static
    {
        $levelRequests = new static();
        foreach ($levelsByCapability as $capability => $level) {
            $levelRequest = AgentConnectionLevelRequest::of((string)$capability, (string)$level);
            $levelRequests->add($levelRequest);
        }
        return $levelRequests;
    }
}
