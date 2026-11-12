<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;

/**
 * Point-in-time task plan (the supervisor's plan replay): replay a SEED plan through every persisted `update_task_plan` /
 * `set_task_status` call up to (and including) an index, each BY THE OUTCOME THE LIVE LOOP RECORDED
 * ({@see ToolCall::readTaskPlanCallOutcome()}): a call that was refused or changed nothing is skipped — the seeded refusal,
 * stored COMPLETED, included; a keyed call replays through the call's work request
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentToolCallWorkRequest::applyTaskPlanUpdateTo()}), the live
 * path's own code. A call recorded before keys (a full-replace `update_task_plan`, a `set_task_status` row without an
 * outcome) is no longer replayed (its replay left with the supervisor's need for it) — the plan as of
 * such a row is the plan before it.
 *
 * Pure: the caller gathers the inputs — the purpose's seed as the live loop seeds it, the loaded rows, the recorded
 * retirements — and takes the compacted live path (the persisted plan) before it asks for a replay.
 */
class TaskPlanReplay
{
    /**
     * The plan as of `$asOfMessageIndex`; null when there is neither a seed nor a plan call. `$seedTaskPlan` is replayed
     * IN PLACE (the caller hands a fresh one).
     *
     * @param int[] $retirementMessageIndexes the message indexes at which the live loop retired a finished plan
     *                                        (each retired plan's {@see TaskPlan::$completedAtMessageIndex})
     */
    public static function replayUpTo(?TaskPlan $seedTaskPlan, AIConversationMessages $messages, int $asOfMessageIndex, array $retirementMessageIndexes): ?TaskPlan
    {
        $taskPlan = $seedTaskPlan;
        // the message index of the newest plan call the replay applied — a recorded retirement at or after it is the running plan's
        $planChangedAtMessageIndex = -1;
        // Point-in-time message window for the widget/tool gates — the replay must apply exactly the evidence
        // the live loop had AT that index (the full set would back-date later widgets into earlier verdicts).
        $replayedMessages = AIConversationMessages::newInstance();
        foreach ($messages->getElements() as $message) {
            if (($message->indexInConversation ?? 0) > $asOfMessageIndex) {
                break;
            }
            $taskPlan = self::retireReplayedTaskPlanIfRecorded($taskPlan, $retirementMessageIndexes, $planChangedAtMessageIndex, (int)($message->indexInConversation ?? 0));
            $replayedMessages->add($message);
            $toolCall = $message->toolCall ?? null;
            if ($toolCall === null) {
                continue;
            }
            $toolCallMessageIndex = (int)($message->indexInConversation ?? 0);
            // Same initiator index as the live gates — with -1 the delivery gate would accept ANY replayed assistant
            // text and the briefing could show `done` where the live loop rejected the tick.
            $replayedInitiatorMessageIndex = $replayedMessages->getNewestInitiatorMessageIndex() ?? -1;
            if (($toolCall->name ?? '') === UpdateTaskPlanTool::NAME) {
                if ($toolCall->readTaskPlanCallOutcome() === ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED) {
                    $taskPlan = self::replayKeyedUpdateTaskPlanCall($toolCall, $taskPlan, $replayedInitiatorMessageIndex, $replayedMessages);
                    $planChangedAtMessageIndex = $toolCallMessageIndex;
                }
                // refused / unchanged: the live loop changed nothing — neither does the replay; a full replace recorded
                // before keys is not replayed
            } elseif (($toolCall->name ?? '') === SetTaskStatusTool::NAME && $taskPlan !== null) {
                // by the recorded outcome — a call rejected for its addresses changed nothing and is
                // skipped, as is a row without an outcome (recorded before keys); a call the live tool processed (FAILED
                // ones included) replays strictly by key
                $taskPlanCallOutcome = $toolCall->taskPlanCallOutcome ?? null;
                if ($taskPlanCallOutcome === null || $taskPlanCallOutcome === ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED) {
                    continue;
                }
                $planChangedAtMessageIndex = $toolCallMessageIndex;
                // the live tick's own application, with the replay's point-in-time evidence — no criterion is asked (an
                // empty registry) and no channel read, as the replay never judged against the record
                SetTaskStatusTool::workRequest($toolCall)->applyTaskStatusUpdatesTo(
                    $taskPlan,
                    TaskPlanEvidence::forReplay($replayedMessages, $replayedInitiatorMessageIndex),
                    TaskPlanDoneCriteria::fromClasses(),
                    TaskPlanDoneCriterionInputs::forReplay($replayedMessages, $taskPlan->items)
                );
            }
        }
        // a retirement recorded AT the newest index happened after that message — the plan as of it still stands
        return self::retireReplayedTaskPlanIfRecorded($taskPlan, $retirementMessageIndexes, $planChangedAtMessageIndex, $asOfMessageIndex);
    }

    /**
     * The running plan after the recorded retirements before `$beforeMessageIndex`: null when it is finished and a
     * retirement was recorded at or after its newest applied call ($planChangedAtMessageIndex) — the live loop retired it
     * there, after the message at the recorded index (the accepted final answer follows it). Without such a record the
     * plan stands, finished or not, as it stood live.
     *
     * @param int[] $retirementMessageIndexes
     */
    protected static function retireReplayedTaskPlanIfRecorded(?TaskPlan $runningTaskPlan, array $retirementMessageIndexes, int $planChangedAtMessageIndex, int $beforeMessageIndex): ?TaskPlan
    {
        if ($runningTaskPlan === null || !$runningTaskPlan->isFinished()) {
            return $runningTaskPlan;
        }
        foreach ($retirementMessageIndexes as $retirementMessageIndex) {
            if ($retirementMessageIndex >= $planChangedAtMessageIndex && $retirementMessageIndex < $beforeMessageIndex) {
                return null;
            }
        }
        return $runningTaskPlan;
    }

    /**
     * Replay ONE `update_task_plan` call the live loop applied with the keyed contract: the same
     * code as the live path against the same point-in-time window, over the running plan — a plan the live loop retired
     * left the running state at its recorded retirement already ({@see retireReplayedTaskPlanIfRecorded()}), so a call
     * after it creates a new plan. A call the replay cannot apply keeps the running plan. The replay cannot read live
     * channel connections: the decisions that depended on them are in the record the live call produced, and the
     * connected-channel gate is not applied here.
     */
    protected static function replayKeyedUpdateTaskPlanCall(
        ToolCall $toolCall,
        ?TaskPlan $runningTaskPlan,
        int $replayedInitiatorMessageIndex,
        AIConversationMessages $replayedMessages
    ): ?TaskPlan {
        $taskPlanUpdateContext = TaskPlanUpdateContext::forTurn($replayedInitiatorMessageIndex, $replayedMessages);
        $taskPlanUpdateOutcome = UpdateTaskPlanTool::workRequest($toolCall)->applyTaskPlanUpdateTo($runningTaskPlan, $taskPlanUpdateContext);
        return $taskPlanUpdateOutcome->taskPlan ?? $runningTaskPlan;
    }
}
