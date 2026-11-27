<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A list of {@see AgentConnectionFakeRecordedStep}.
 *
 * @property AgentConnectionFakeRecordedStep[] $elements
 * @method AgentConnectionFakeRecordedStep[] getElements()
 * @method AgentConnectionFakeRecordedStep|null first()
 */
class AgentConnectionFakeRecordedSteps extends ObjectSet
{
}
