<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * What the loop must do after a meta tool's call was dispatched: the plan tools ask for the
 * task-plan column persist, a completed `send_owner_report` for the send, a completed `wait_for_sub_agents` for the end
 * of the turn without a text. The loop executes the request OUTSIDE the
 * dispatch's catch on purpose: a claim-lost throw must reach the loop's single claim-lost exit, never become a FAILED
 * tool result.
 *
 * @phpstan-consistent-constructor
 */
class AgentAfterStepRequest extends ValueObject
{
    /** Persist the task-plan column (every status), then the purpose's consequences and the snapshot. */
    public const string TYPE_PERSIST_TASK_PLAN = 'persistTaskPlan';

    /** Send the owner report (claim → visible report message → mail dispatch). */
    public const string TYPE_SEND_OWNER_REPORT = 'sendOwnerReport';

    /** End the turn after this step without an assistant text: the sub-agents still run, their ends resume the conversation. */
    public const string TYPE_END_TURN_WITHOUT_TEXT = 'endTurnWithoutText';

    #[Choice(choices: [self::TYPE_PERSIST_TASK_PLAN, self::TYPE_SEND_OWNER_REPORT, self::TYPE_END_TURN_WITHOUT_TEXT])]
    public string $type = self::TYPE_PERSIST_TASK_PLAN;

    public static function persistTaskPlan(): static
    {
        return new static();
    }

    public static function sendOwnerReport(): static
    {
        $afterStepRequest = new static();
        $afterStepRequest->type = self::TYPE_SEND_OWNER_REPORT;
        return $afterStepRequest;
    }

    public static function endTurnWithoutText(): static
    {
        $afterStepRequest = new static();
        $afterStepRequest->type = self::TYPE_END_TURN_WITHOUT_TEXT;
        return $afterStepRequest;
    }

    public function persistsTaskPlan(): bool
    {
        return $this->type === self::TYPE_PERSIST_TASK_PLAN;
    }

    public function sendsOwnerReport(): bool
    {
        return $this->type === self::TYPE_SEND_OWNER_REPORT;
    }

    public function endsTurnWithoutText(): bool
    {
        return $this->type === self::TYPE_END_TURN_WITHOUT_TEXT;
    }
}
