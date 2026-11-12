<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A task plan's items per EFFECTIVE state ({@see TaskPlanItems::countItemsByStatus()}): a `blocked` node counts blocked
 * and prunes its subtree; every other node counts open iff it is open — a `not_applicable` node counts done and its
 * subtree is still counted. The honest summary line the tool results and the finished-plan list read
 * ("Plan now: 4 done, 7 open, 2 blocked").
 */
class TaskPlanItemStatusCounts extends ValueObject
{
    public int $done = 0;

    public int $open = 0;

    public int $blocked = 0;
}
