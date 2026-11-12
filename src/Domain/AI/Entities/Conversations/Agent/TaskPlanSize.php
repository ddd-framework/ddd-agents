<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The SIZE of the task plans a run carried — a report figure only: nothing rejects, trims or warns on
 * it. The plan rides every model call as the uncached `TASK_PLAN` tail, so its size is measured instead of capped.
 *
 * Fed the run's messages in index order ({@see recordMessage()}), then {@see finish()}: the plan IN FORCE is the newest
 * per-message plan snapshot before the row (the snapshot a row carries is the state AFTER that row's plan-tool call, so
 * the model call on the same row still read the plan before it). A run without a snapshot measures nothing.
 */
class TaskPlanSize extends ValueObject
{
    /** @var int The most nodes any plan in force had ({@see TaskPlanItems::countAllItems()}). */
    public int $nodes = 0;

    /** @var int The deepest level any plan in force reached (top level = 1). */
    public int $maxDepth = 0;

    /** @var int The largest tail render a model call carried, in chars/4 tokens. */
    public int $tailTokensMax = 0;

    /** @var int The tail render summed over the run's model calls, in chars/4 tokens. */
    public int $tailTokensSum = 0;

    /** @var int How many model calls ran with a plan in force. */
    public int $modelCallsWithPlan = 0;

    /** The plan in force while the messages are fed — a working value, never reported. */
    protected ?TaskPlan $planInForce = null;

    /** Whether any plan was in force at all. */
    protected bool $sawPlan = false;

    /**
     * Feed ONE message row of the run, in index order.
     *
     * @param bool $isModelCall the row carries a request snapshot — one model call
     * @param TaskPlan|null $taskPlanSnapshot the plan state the row stamped, if any
     */
    public function recordMessage(bool $isModelCall, ?TaskPlan $taskPlanSnapshot): void
    {
        if ($isModelCall && $this->planInForce !== null) {
            $tailTokens = self::estimateTailTokens($this->planInForce);
            $this->tailTokensMax = max($this->tailTokensMax, $tailTokens);
            $this->tailTokensSum += $tailTokens;
            $this->modelCallsWithPlan++;
        }
        if ($taskPlanSnapshot !== null) {
            $this->planInForce = $taskPlanSnapshot;
            $this->sawPlan = true;
            $this->measureTree($taskPlanSnapshot);
        }
    }

    /** Close the run. Returns whether any plan was measured. */
    public function finish(): bool
    {
        return $this->sawPlan;
    }

    /** The tail render's token estimate — chars/4, rounded up, the `TASK_PLAN` context segment's estimate. */
    public static function estimateTailTokens(TaskPlan $taskPlan): int
    {
        return (int)ceil(mb_strlen($taskPlan->toTailHintText()) / 4);
    }

    /**
     * The figures as the run report and the eval case result carry them.
     *
     * @return array{nodes: int, maxDepth: int, tailTokensMax: int, tailTokensSum: int}
     */
    public function toReportFields(): array
    {
        return [
            'nodes' => $this->nodes,
            'maxDepth' => $this->maxDepth,
            'tailTokensMax' => $this->tailTokensMax,
            'tailTokensSum' => $this->tailTokensSum,
        ];
    }

    protected function measureTree(TaskPlan $taskPlan): void
    {
        if ($taskPlan->items === null) {
            return;
        }
        $this->nodes = max($this->nodes, $taskPlan->items->countAllItems());
        foreach ($taskPlan->items->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $this->maxDepth = max($this->maxDepth, $taskPlanItemAddress->depth + 1);
        }
    }
}
