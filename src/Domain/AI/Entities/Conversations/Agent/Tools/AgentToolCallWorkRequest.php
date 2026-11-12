<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentTaskPlanTickOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateContext;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateOutcome;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;
use LogicException;

/**
 * The work a meta tool's call asks for: `load_skills` loads skills through the gates,
 * `load_tool` loads tools (a sub-skill first when the tool is one of its), `update_task_plan` and `set_task_status`
 * apply their call to the plan. The meta tool builds it from its call ({@see AgentMetaTool::workRequest()}); the
 * dispatch executes it through the owning service instead of branching on the tool's name. The plan requests carry the
 * arguments parsed once ({@see UpdateTaskPlanArguments}, {@see SetTaskStatusArguments}) and apply them to a plan
 * themselves — the live call and the supervisor's point-in-time replay run this same application.
 *
 * @phpstan-consistent-constructor
 */
class AgentToolCallWorkRequest extends ValueObject
{
    /** Load the named skills through the entitlement, vision and purpose gates. */
    public const string TYPE_LOAD_SKILLS = 'loadSkills';

    /** Load the named tools, a not-yet-loaded sub-skill first when a tool belongs to one. */
    public const string TYPE_LOAD_TOOLS = 'loadTools';

    /** Apply an `update_task_plan` call to the standing plan (or create the plan). */
    public const string TYPE_APPLY_TASK_PLAN_UPDATE = 'applyTaskPlanUpdate';

    /** Apply a `set_task_status` call's status updates to the plan. */
    public const string TYPE_APPLY_TASK_STATUS_UPDATES = 'applyTaskStatusUpdates';

    #[Choice(choices: [self::TYPE_LOAD_SKILLS, self::TYPE_LOAD_TOOLS, self::TYPE_APPLY_TASK_PLAN_UPDATE, self::TYPE_APPLY_TASK_STATUS_UPDATES])]
    public string $type = self::TYPE_LOAD_SKILLS;

    /** @var array<string, mixed> the call's arguments as the model sent them */
    public array $toolArguments = [];

    /** The `update_task_plan` arguments, parsed once; null for every other request. */
    public ?UpdateTaskPlanArguments $updateTaskPlanArguments = null;

    /** The `set_task_status` arguments, parsed once; null for every other request. */
    public ?SetTaskStatusArguments $setTaskStatusArguments = null;

    /** @param array<string, mixed> $toolArguments the `load_skills` call's arguments */
    public static function loadSkills(array $toolArguments): static
    {
        $agentToolCallWorkRequest = new static();
        $agentToolCallWorkRequest->toolArguments = $toolArguments;
        return $agentToolCallWorkRequest;
    }

    /** @param array<string, mixed> $toolArguments the `load_tool` call's arguments */
    public static function loadTools(array $toolArguments): self
    {
        $agentToolCallWorkRequest = self::loadSkills($toolArguments);
        $agentToolCallWorkRequest->type = self::TYPE_LOAD_TOOLS;
        return $agentToolCallWorkRequest;
    }

    /** @param array<string, mixed> $toolArguments the `update_task_plan` call's arguments */
    public static function applyTaskPlanUpdate(array $toolArguments): self
    {
        $agentToolCallWorkRequest = self::loadSkills($toolArguments);
        $agentToolCallWorkRequest->type = self::TYPE_APPLY_TASK_PLAN_UPDATE;
        $agentToolCallWorkRequest->updateTaskPlanArguments = UpdateTaskPlanArguments::fromArguments($toolArguments);
        return $agentToolCallWorkRequest;
    }

    /** @param array<string, mixed> $toolArguments the `set_task_status` call's arguments */
    public static function applyTaskStatusUpdates(array $toolArguments): self
    {
        $agentToolCallWorkRequest = self::loadSkills($toolArguments);
        $agentToolCallWorkRequest->type = self::TYPE_APPLY_TASK_STATUS_UPDATES;
        $agentToolCallWorkRequest->setTaskStatusArguments = SetTaskStatusArguments::fromToolArguments($toolArguments);
        return $agentToolCallWorkRequest;
    }

    public function loadsSkills(): bool
    {
        return $this->type === self::TYPE_LOAD_SKILLS;
    }

    public function loadsTools(): bool
    {
        return $this->type === self::TYPE_LOAD_TOOLS;
    }

    public function appliesTaskPlanUpdate(): bool
    {
        return $this->type === self::TYPE_APPLY_TASK_PLAN_UPDATE;
    }

    public function appliesTaskStatusUpdates(): bool
    {
        return $this->type === self::TYPE_APPLY_TASK_STATUS_UPDATES;
    }

    /** The request applies a call to the task plan: an `update_task_plan` or a `set_task_status` call. */
    public function appliesTaskPlanCall(): bool
    {
        return $this->appliesTaskPlanUpdate() || $this->appliesTaskStatusUpdates();
    }

    /** The parsed arguments of a `set_task_status` request. */
    public function getSetTaskStatusArguments(): SetTaskStatusArguments
    {
        if ($this->setTaskStatusArguments === null) {
            throw new LogicException('Only a set_task_status work request carries set_task_status arguments, not ' . $this->type);
        }
        return $this->setTaskStatusArguments;
    }

    /** The parsed arguments of an `update_task_plan` request. */
    public function getUpdateTaskPlanArguments(): UpdateTaskPlanArguments
    {
        if ($this->updateTaskPlanArguments === null) {
            throw new LogicException('Only an update_task_plan work request carries update_task_plan arguments, not ' . $this->type);
        }
        return $this->updateTaskPlanArguments;
    }

    /**
     * Apply this `update_task_plan` call to the standing plan: changed when it has items, created otherwise
     * ({@see TaskPlan::applyUpdate()} / {@see TaskPlan::createFromUpdate()}). The standing plan is never changed in place.
     */
    public function applyTaskPlanUpdateTo(?TaskPlan $standingTaskPlan, TaskPlanUpdateContext $taskPlanUpdateContext): TaskPlanUpdateOutcome
    {
        $updateTaskPlanArguments = $this->getUpdateTaskPlanArguments();
        return $standingTaskPlan !== null && $standingTaskPlan->hasItems()
            ? $standingTaskPlan->applyUpdate($updateTaskPlanArguments, $taskPlanUpdateContext)
            : TaskPlan::createFromUpdate($updateTaskPlanArguments, $taskPlanUpdateContext);
    }

    /** Apply this `set_task_status` call's updates to $taskPlan IN PLACE ({@see TaskPlan::applyStatusUpdates()}). */
    public function applyTaskStatusUpdatesTo(
        TaskPlan $taskPlan,
        TaskPlanEvidence $taskPlanEvidence,
        TaskPlanDoneCriteria $taskPlanDoneCriteria,
        TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs
    ): AgentTaskPlanTickOutcome {
        return $taskPlan->applyStatusUpdates($this->getSetTaskStatusArguments(), $taskPlanEvidence, $taskPlanDoneCriteria, $taskPlanDoneCriterionInputs);
    }
}
