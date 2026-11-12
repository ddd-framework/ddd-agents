<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The FINISHED task plans of a conversation ("ein ObjectSet von TaskPlans, die
 * fertigen abhaken"): every plan whose items all reached a terminal status and whose final answer was delivered is
 * RETIRED into this set instead of being carried in the wire tail step after step. Oldest first, capped at
 * {@see MAX_FINISHED_PLANS} (the oldest drop). Lives on {@see AgentWorkingState::$completedTaskPlans} — the working
 * state is never compacted, so the model can still report what it finished after a compaction, through the
 * `get_task_plans` meta-tool. The ACTIVE plan stays {@see AgentWorkingState::$taskPlan} (at most one).
 *
 * @property TaskPlan[] $elements
 * @method TaskPlan[] getElements()
 * @method TaskPlan|null first()
 * @method TaskPlan|null last()
 * @phpstan-consistent-constructor
 */
class TaskPlans extends ObjectSet
{
    /** How many finished plans a conversation keeps — beyond it the set stops paying for itself in the column. */
    public const int MAX_FINISHED_PLANS = 20;

    /** Append a retired plan, dropping the oldest beyond the cap. ObjectSet::add()/remove() take references — pass variables. */
    public function addFinishedPlan(TaskPlan $taskPlan): void
    {
        $this->add($taskPlan);
        while ($this->count() > self::MAX_FINISHED_PLANS) {
            $oldest = $this->first();
            if ($oldest === null) {
                break;
            }
            $this->remove($oldest);
        }
    }

    /**
     * The finished plans NEWEST first with their 1-based display number (`#1` = the newest).
     *
     * @return TaskPlan[] number → plan
     */
    public function newestFirstNumbered(): array
    {
        $numbered = [];
        $number = 1;
        foreach (array_reverse(array_values($this->getElements())) as $taskPlan) {
            $numbered[$number++] = $taskPlan;
        }
        return $numbered;
    }

    /** The one-line list the chat model reads (`get_task_plans` without a number), newest first. */
    public function toFinishedListText(): string
    {
        $lines = [];
        foreach ($this->newestFirstNumbered() as $number => $taskPlan) {
            $lines[] = $taskPlan->toFinishedLine($number);
        }
        return implode("\n", $lines);
    }

    /** @return array<int, array<string, mixed>> oldest first, every key explicitly present (the RFC-7396 rule) */
    public function toRepositoryArray(): array
    {
        return array_map(static fn (TaskPlan $taskPlan): array => $taskPlan->mapToRepository(), array_values($this->getElements()));
    }

    /** @param array<int, mixed> $repositoryPlans */
    public static function fromRepositoryArray(array $repositoryPlans): static
    {
        $taskPlans = new static();
        foreach ($repositoryPlans as $planFields) {
            if (!is_array($planFields)) {
                continue;
            }
            $taskPlan = new TaskPlan();
            $taskPlan->mapFromRepository($planFields);
            $taskPlans->add($taskPlan);
        }
        return $taskPlans;
    }
}
