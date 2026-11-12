<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\HidePropertyOnSystemSerialization;

/**
 * What a platform closing or a `set_task_status` tick reads besides the plan itself: the
 * conversation's messages, the turn whose initiator floors the delivery gates, the candidate answer text the checked
 * stop judges, the done-gate tools the account cannot run, whether the purpose's criteria decide every tick, and — for
 * the tick — the phone line. Gathered once per closing pass or tick
 * by {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildTaskPlanEvidence()} /
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildTaskPlanEvidenceForStatusTick()}; the supervisor's
 * replay of a tick builds it with {@see forReplay()}, tests with {@see forTurn()} or {@see forTurnWithoutTranscript()}. Values only — except the locked gate tools, which the conversation answers when a reader asks
 * ({@see getEntitlementLockedToolNames()}); the purpose's done criteria and their inputs are NOT carried — a criterion is
 * evaluated lazily over the live plan where its gate is reached, so the registry and the inputs travel
 * as their own parameters ({@see TaskPlan::applyStatusUpdates()}, {@see TaskPlanItems::applyPreResolutions()}).
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanEvidence extends ValueObject
{
    /**
     * The conversation's loaded messages — the SAME set the conversation holds, never a copy: a closing that runs after a
     * row was appended (the newest message index a platform closing stamps) reads the set as it is at that moment.
     */
    public AIConversationMessages $conversationMessages;

    /**
     * Tick only: false = gathered WITHOUT the conversation's messages ({@see forTurnWithoutTranscript()}) — the tick's
     * gates that read the transcript are not applied and {@see $conversationMessages} is an empty set.
     */
    public bool $hasTranscript = true;

    /** The `indexInConversation` of the current turn's initiator message (-1 when unknown) — the delivery gates' floor. */
    public int $currentTurnInitiatorMessageIndex = -1;

    /**
     * The final text the checked stop is judging, not appended yet (null outside the checked stop): a widget it carries
     * and an owner-visible answer in it count as delivered.
     */
    public ?string $candidateAnswerText = null;

    /**
     * @var string[]|null The plan's done-gate tools no entitled skill offers this account — such a gate is waived, never
     * waited for. Given as a value ({@see forTurn()}), or null: then read from {@see $entitlementLocksConversation} when
     * asked ({@see getEntitlementLockedToolNames()}).
     */
    protected ?array $entitlementLockedToolNames = [];

    /** The conversation the locked gate tools are read from when asked (set by {@see readEntitlementLocksFromConversation()}). */
    #[HidePropertyOnSystemSerialization]
    protected ?AIConversation $entitlementLocksConversation = null;

    /** @var string[] The plan's done-gate tools at gathering time — the set the conversation is asked about. */
    #[HidePropertyOnSystemSerialization]
    protected array $planGateToolNames = [];

    /**
     * The purpose's criteria decide every tick of a criterion leaf
     * ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::enforcesDoneCriterionOnTicks()}): the tick's
     * enforced-criterion gates apply ({@see TaskPlanItemTickGate}), and the platform closings leave such a leaf to
     * its criterion.
     */
    public bool $criteriaDecideTicks = false;

    /**
     * The key the conversation's widget blocks hydrate themselves from (the tick's fence example carries it); null
     * when the conversation has no space or its space gives none.
     */
    public ?int $widgetSelfHydrationKey = null;

    /** Tick only: the owner is on a call over the phone line — a widget gate answers for the phone, never a fence. */
    public bool $ownerIsOnThePhoneLine = false;

    /** @param string[] $entitlementLockedToolNames */
    public static function forTurn(
        AIConversationMessages $conversationMessages,
        int $currentTurnInitiatorMessageIndex = -1,
        ?string $candidateAnswerText = null,
        bool $criteriaDecideTicks = false,
        array $entitlementLockedToolNames = []
    ): static {
        $taskPlanEvidence = new static();
        $taskPlanEvidence->conversationMessages = $conversationMessages;
        $taskPlanEvidence->currentTurnInitiatorMessageIndex = $currentTurnInitiatorMessageIndex;
        $taskPlanEvidence->candidateAnswerText = $candidateAnswerText;
        $taskPlanEvidence->criteriaDecideTicks = $criteriaDecideTicks;
        $taskPlanEvidence->entitlementLockedToolNames = $entitlementLockedToolNames;
        return $taskPlanEvidence;
    }

    /**
     * The supervisor's REPLAY of a recorded `set_task_status` call: the point-in-time messages and the
     * replayed turn's initiator only — no widget self-hydration key, no locked gate tools, no phone line; the purpose's
     * criteria are not asked. The live call's evidence is {@see AIConversation::buildTaskPlanEvidenceForStatusTick()}.
     */
    public static function forReplay(AIConversationMessages $replayedMessages, int $replayedTurnInitiatorMessageIndex): self
    {
        return self::forTurn($replayedMessages, $replayedTurnInitiatorMessageIndex);
    }

    /**
     * A tick judged WITHOUT the conversation's messages: the plan rules alone (the echo check, the cascade cap, the record
     * criterion, the transient obstacle, the transition) — every gate that reads the transcript stays off.
     *
     * @param string[] $entitlementLockedToolNames
     */
    public static function forTurnWithoutTranscript(int $currentTurnInitiatorMessageIndex = -1, array $entitlementLockedToolNames = []): self
    {
        $taskPlanEvidence = self::forTurn(AIConversationMessages::newInstance(), $currentTurnInitiatorMessageIndex, entitlementLockedToolNames: $entitlementLockedToolNames);
        $taskPlanEvidence->hasTranscript = false;
        return $taskPlanEvidence;
    }

    /**
     * The locked gate tools are read from `$aIConversation` when a reader asks, for the gate-tool set of the plan as it is
     * now ({@see AIConversation::findEntitlementLockedToolNamesAmongPlanGateTools()} — memoized there): a closing or a
     * tick that never asks never sweeps the skill catalog.
     *
     * @param string[] $planGateToolNames
     */
    public function readEntitlementLocksFromConversation(AIConversation $aIConversation, array $planGateToolNames): void
    {
        $this->entitlementLocksConversation = $aIConversation;
        $this->planGateToolNames = $planGateToolNames;
        $this->entitlementLockedToolNames = null;
    }

    /**
     * The plan's done-gate tools this account cannot run: the given value, else asked of the conversation now.
     *
     * @return string[]
     */
    public function getEntitlementLockedToolNames(): array
    {
        if ($this->entitlementLockedToolNames !== null) {
            return $this->entitlementLockedToolNames;
        }
        return $this->entitlementLocksConversation?->findEntitlementLockedToolNamesAmongPlanGateTools($this->planGateToolNames) ?? [];
    }

    /** Whether one of `$doneGateToolNames` is a tool the account cannot run (its gate is waived). @param string[] $doneGateToolNames */
    public function locksAnyOfTools(array $doneGateToolNames): bool
    {
        return array_intersect($doneGateToolNames, $this->getEntitlementLockedToolNames()) !== [];
    }

    /** The newest row's index as a platform closing stamps it, read now (0 without rows). */
    public function getNewestMessageIndexOrZero(): int
    {
        return $this->conversationMessages->getNewestMessageIndex() ?? 0;
    }
}
