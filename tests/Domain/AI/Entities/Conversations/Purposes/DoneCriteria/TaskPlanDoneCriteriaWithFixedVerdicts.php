<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;

/**
 * A registry whose every key answers a FIXED verdict — the walk tests' stand-in for a purpose's criteria
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems::applyPreResolutions()}): a verdict per key, else the
 * one for every other key (null = undecided). Records the keys asked, in the order the walk asked them.
 *
 * @property TaskPlanDoneCriterion[] $elements
 * @method TaskPlanDoneCriterion[] getElements()
 * @method TaskPlanDoneCriterion|null first()
 */
class TaskPlanDoneCriteriaWithFixedVerdicts extends TaskPlanDoneCriteria
{
    /** @var array<string, TaskPlanDoneCriterionVerdict|null> */
    public array $verdictsByDoneCriterionKey = [];

    public ?TaskPlanDoneCriterionVerdict $verdictForEveryOtherKey = null;

    /** @var string[] the keys asked, in order */
    public array $askedDoneCriterionKeys = [];

    /** @param array<string, TaskPlanDoneCriterionVerdict|null> $verdictsByDoneCriterionKey */
    public static function answeringByKey(array $verdictsByDoneCriterionKey, ?TaskPlanDoneCriterionVerdict $verdictForEveryOtherKey = null): self
    {
        $taskPlanDoneCriteriaWithFixedVerdicts = new self();
        $taskPlanDoneCriteriaWithFixedVerdicts->verdictsByDoneCriterionKey = $verdictsByDoneCriterionKey;
        $taskPlanDoneCriteriaWithFixedVerdicts->verdictForEveryOtherKey = $verdictForEveryOtherKey;
        return $taskPlanDoneCriteriaWithFixedVerdicts;
    }

    public static function answeringEveryKeyWith(?TaskPlanDoneCriterionVerdict $verdict): self
    {
        return self::answeringByKey([], $verdict);
    }

    public function resolveKey(string $doneCriterionKey, TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict
    {
        $this->askedDoneCriterionKeys[] = $doneCriterionKey;
        return array_key_exists($doneCriterionKey, $this->verdictsByDoneCriterionKey)
            ? $this->verdictsByDoneCriterionKey[$doneCriterionKey]
            : $this->verdictForEveryOtherKey;
    }

    /** The inputs the walk tests pass: no conversation, no messages, the plan under test. */
    public static function inputsFor(?TaskPlanItems $taskPlanItems): TaskPlanDoneCriterionInputs
    {
        return TaskPlanDoneCriterionInputs::forConversation(AIConversation::newInstance(), null, $taskPlanItems);
    }
}
