<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The replays of one conversation through the activity narrator, one per compared model, in the order the models were
 * named (the activity-lines console command).
 *
 * @property AgentActivityLineModelReplay[] $elements
 * @method AgentActivityLineModelReplay[] getElements()
 * @method AgentActivityLineModelReplay|null first()
 * @method AgentActivityLineModelReplay|null getByUniqueKey(string $uniqueKey)
 */
class AgentActivityLineModelReplays extends ObjectSet
{
}
