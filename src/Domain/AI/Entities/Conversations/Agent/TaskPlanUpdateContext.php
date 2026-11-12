<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTaskPlanRecordService;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\HidePropertyOnSystemSerialization;

/**
 * What an `update_task_plan` call is judged against besides the plan itself: the turn
 * whose initiator stamps the plan, the keys an earlier `update_task_plan` call of the same model response added (R8 names
 * them as such), and — for the status transitions the call sends, which pass the tick application of
 * `set_task_status` ({@see TaskPlan::applyStatusUpdatesWithoutTheTurnStamps()}) — the tick's evidence, the
 * purpose's done criteria and the inputs they read. The live call takes all three from the conversation exactly as
 * `set_task_status` does — read from the conversation only when the call's statuses reach the tick
 * ({@see forStatusTickOfConversation()}, {@see readStatusTickJudgementOver()}): a rejected call, or one that sends no
 * status transition, loads no space and asks no purpose; the tests give them as values ({@see forStatusTick()}). The
 * supervisor replay gives the turn and its messages only ({@see forTurn()}) — no criterion answers, no channel is read,
 * no judgement tick is parked, as in the replay of a tick.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanUpdateContext extends ValueObject
{
    /** The `indexInConversation` of the current turn's initiator message (-1 when unknown). */
    public int $currentTurnInitiatorMessageIndex = -1;

    /** @var string[] The keys an earlier `update_task_plan` call of this model response added. */
    public array $keysAddedEarlierInThisResponse = [];

    /** What the tick application of the call's statuses reads (without messages: the gates that read the transcript are off). */
    public TaskPlanEvidence $taskPlanEvidence;

    /** The purpose's done criteria the call's statuses are judged by (an empty registry: no criterion answers). */
    public TaskPlanDoneCriteria $taskPlanDoneCriteria;

    /**
     * What the criteria read, built once for the call by the record service over the standing plan; null = the domain-free
     * inputs over the call's messages. Pointed at the plan the call changes before the statuses apply
     * ({@see buildDoneCriterionInputsOver()}).
     */
    public ?TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs = null;

    /** The live call's conversation, whose tick judgement is read when a status first needs it; null once read, or when given as values. */
    #[HidePropertyOnSystemSerialization]
    protected ?AIConversation $statusTickConversation = null;

    /** The record service that reads the purpose's criteria and their inputs for {@see $statusTickConversation}. */
    #[HidePropertyOnSystemSerialization]
    protected ?AIConversationTaskPlanRecordService $statusTickRecordService = null;

    /**
     * The turn and, when given, its messages: the tick's gates that read the transcript read them; nothing else is known —
     * no criterion, no channel reader, no locked gate tool, no phone line, no judgement park.
     */
    public static function forTurn(int $currentTurnInitiatorMessageIndex, ?AIConversationMessages $conversationMessages = null): static
    {
        $taskPlanUpdateContext = new static();
        $taskPlanUpdateContext->currentTurnInitiatorMessageIndex = $currentTurnInitiatorMessageIndex;
        $taskPlanUpdateContext->taskPlanEvidence = $conversationMessages !== null
            ? TaskPlanEvidence::forTurn($conversationMessages, $currentTurnInitiatorMessageIndex)
            : TaskPlanEvidence::forTurnWithoutTranscript($currentTurnInitiatorMessageIndex);
        $taskPlanUpdateContext->taskPlanDoneCriteria = TaskPlanDoneCriteria::fromClasses();
        return $taskPlanUpdateContext;
    }

    /**
     * The live call: the statuses are judged by exactly what a `set_task_status` tick of the conversation is judged by —
     * its evidence, the purpose's criteria and their inputs.
     */
    public static function forStatusTick(
        int $currentTurnInitiatorMessageIndex,
        TaskPlanEvidence $taskPlanEvidence,
        TaskPlanDoneCriteria $taskPlanDoneCriteria,
        TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs
    ): static {
        $taskPlanUpdateContext = new static();
        $taskPlanUpdateContext->currentTurnInitiatorMessageIndex = $currentTurnInitiatorMessageIndex;
        $taskPlanUpdateContext->taskPlanEvidence = $taskPlanEvidence;
        $taskPlanUpdateContext->taskPlanDoneCriteria = $taskPlanDoneCriteria;
        $taskPlanUpdateContext->taskPlanDoneCriterionInputs = $taskPlanDoneCriterionInputs;
        return $taskPlanUpdateContext;
    }

    /**
     * The live call: its statuses are judged by what a `set_task_status` tick of `$aIConversation` is
     * judged by, read through `$aIConversationTaskPlanRecordService` only when they reach the tick
     * ({@see readStatusTickJudgementOver()}).
     */
    public static function forStatusTickOfConversation(
        int $currentTurnInitiatorMessageIndex,
        AIConversation $aIConversation,
        AIConversationTaskPlanRecordService $aIConversationTaskPlanRecordService
    ): static {
        $taskPlanUpdateContext = new static();
        $taskPlanUpdateContext->currentTurnInitiatorMessageIndex = $currentTurnInitiatorMessageIndex;
        $taskPlanUpdateContext->statusTickConversation = $aIConversation;
        $taskPlanUpdateContext->statusTickRecordService = $aIConversationTaskPlanRecordService;
        return $taskPlanUpdateContext;
    }

    /**
     * Read the tick judgement of the live call's conversation once, right before its statuses pass the tick: the tick's
     * evidence ({@see AIConversation::buildTaskPlanEvidenceForStatusTick()}), the purpose's criteria and their inputs over
     * `$taskPlanItems`, the plan the call rebuilt. Nothing to read for a context given as values.
     */
    public function readStatusTickJudgementOver(?TaskPlanItems $taskPlanItems): void
    {
        $aIConversation = $this->statusTickConversation;
        $aIConversationTaskPlanRecordService = $this->statusTickRecordService;
        if ($aIConversation === null || $aIConversationTaskPlanRecordService === null) {
            return;
        }
        $this->statusTickConversation = null;
        $this->statusTickRecordService = null;
        $this->taskPlanEvidence = $aIConversation->buildTaskPlanEvidenceForStatusTick();
        $this->taskPlanDoneCriteria = $aIConversationTaskPlanRecordService->findDoneCriteriaForStatusTick($aIConversation);
        $this->taskPlanDoneCriterionInputs = $aIConversationTaskPlanRecordService->buildDoneCriterionInputsForStatusTick($aIConversation, $taskPlanItems);
    }

    /**
     * The criteria's inputs over `$taskPlanItems`, the plan the call changes: a criterion that reads the plan sees it as
     * the call rebuilt it, as a tick's criterion sees the plan its earlier updates mutated. The record service's inputs
     * keep everything else they read (the space, the channel reader).
     */
    public function buildDoneCriterionInputsOver(?TaskPlanItems $taskPlanItems): TaskPlanDoneCriterionInputs
    {
        if ($this->taskPlanDoneCriterionInputs === null) {
            return TaskPlanDoneCriterionInputs::forReplay($this->taskPlanEvidence->conversationMessages, $taskPlanItems);
        }
        $this->taskPlanDoneCriterionInputs->taskPlanItems = $taskPlanItems;
        return $this->taskPlanDoneCriterionInputs;
    }

    public function wasKeyAddedEarlierInThisResponse(string $key): bool
    {
        return in_array($key, $this->keysAddedEarlierInThisResponse, true);
    }
}
