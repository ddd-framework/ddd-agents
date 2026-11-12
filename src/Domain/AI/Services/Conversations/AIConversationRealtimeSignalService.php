<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfigMemo;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfigs;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Publishes the per-conversation realtime change-signal so a listening frontend refetches the new messages,
 * and fans the same state change out to the conversation's A2A push configs. **Ping, not payload** — a signal carries a
 * monotonic change counter, the `lastMessageId` delta cursor and the `runStatus`; NEVER conversation content
 * (claim-check pattern, keeps content + the rights layer in the backend GET). Generic by design: the same signal drives
 * the admin console and the customer UI — both run the conversation in the worker, so both ping through here.
 *
 * The core carries no realtime transport and no A2A push queue: {@see writeChangeSignal()},
 * {@see publishSummaryUpdatedSignal()}, {@see settleInFlightSignal()} and {@see dispatchTaskPushNotification()} do
 * nothing here. An application with a transport puts a same-path subclass under its own root namespace; the core
 * resolves this service only through `DDDService::instance()->getService(AIConversationRealtimeSignalService::class)`.
 * What stays core: the ephemeral-conversation guard, the A2A push-config COUNT memo (lever B) and the per-publish
 * measurement the agent loop reads.
 */
class AIConversationRealtimeSignalService extends Service
{
    /** runStatus values mirrored on the ping doc (lowercase wire form, distinct from the message STATUS_* enum). */
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_IDLE = 'idle';
    public const string STATUS_FAILED = 'failed';
    /** The run was cancelled ({@see AIConversationRunClaimsService::cancelLiveRun()}); like `idle`, nothing runs any more. */
    public const string STATUS_CANCELLED = 'cancelled';

    /**
     * Lever B: the A2A `hasEnabledConfigsForConversation` COUNT, memoised per conversation id under the initiator message
     * index it was taken under, so a signal under a different initiator (a later turn in the same process, a pre-loop
     * publish) never reuses it. A publish of a run uses the run's memo ({@see AgentRun::$pushConfigMemo}, renewed at
     * loop start); THIS memo serves the publishes outside a run. The service outlives the turn (a worker keeps it
     * across jobs), so it is reset whenever a NEW resume job starts
     * ({@see \DDD\Domain\AI\MessageHandlers\AIConversationResumeHandler}) and again at loop start
     * ({@see self::resetPushConfigMemo()}). A push config enabled MID-turn is therefore seen one turn late — acceptable,
     * the webhook fan-out is best-effort and rides the next state change.
     */
    protected ?AIConversationTaskPushNotificationConfigMemo $pushConfigMemoOutsideARun = null;

    /**
     * Measurement of the LAST {@see self::publishChangeSignal()} call, read by the agent loop's per-call breakdown:
     * `firebase` = ms spent writing the realtime signal ({@see writeChangeSignal()}), `a2aCount` = ms of the A2A COUNT
     * (0 when memoised). The key names are the breakdown's wire names.
     *
     * @var array<string, float>|null
     */
    public ?array $lastPublishBreakdownMs = null;

    /**
     * Signal the change of `$conversation` (run status `$runStatus`): write the realtime signal, then fan the change
     * out to the conversation's A2A push configs. **Best-effort:** a transport outage or a broken queue must never
     * break the agent turn, so every error is swallowed (logged as a warning). No-op when the conversation is
     * ephemeral (no id → no channel to key).
     */
    public function publishChangeSignal(AIConversation $conversation, string $runStatus, ?AIConversationTaskPushNotificationConfigMemo $pushConfigMemo = null): void
    {
        $conversationId = $conversation->id ?? null;
        if ($conversationId === null) {
            return;
        }

        $firebaseStartedAt = microtime(true);
        $this->writeChangeSignal($conversation, $runStatus);
        $firebaseMs = (microtime(true) - $firebaseStartedAt) * 1000;

        // A2A webhook fan-out rides the SAME state-change seam — dispatched ONLY when the
        // conversation has enabled push configs (single indexed COUNT, memoised per conversation per turn; the vast
        // majority of conversations skip). Best-effort like the realtime ping: a broken queue must never break the turn.
        $a2aCountMs = 0.0;
        try {
            $initiatorMessageIndex = isset($conversation->messages)
                ? $conversation->messages->getNewestInitiatorMessageIndex()
                : $this->messagesService()->findNewestTurnInitiatorIndex($conversation);
            // the publishing run's memo, else this service's for the publishes outside a run
            $pushConfigMemo ??= $this->pushConfigMemoOutsideARun ??= new AIConversationTaskPushNotificationConfigMemo();
            $hasEnabledPushConfigs = $pushConfigMemo->findHasEnabledConfigs((int)$conversationId, $initiatorMessageIndex);
            if ($hasEnabledPushConfigs === null) {
                $a2aStartedAt = microtime(true);
                $hasEnabledPushConfigs = $this->hasEnabledPushConfigs((int)$conversationId);
                $a2aCountMs = (microtime(true) - $a2aStartedAt) * 1000;
                $pushConfigMemo->remember((int)$conversationId, $initiatorMessageIndex, $hasEnabledPushConfigs);
            }
            if ($hasEnabledPushConfigs) {
                $this->dispatchTaskPushNotification((int)$conversationId);
            }
        } catch (Throwable $pushDispatchError) {
            DDDService::instance()->getLogger()->warning(
                "AIConversationRealtimeSignalService: A2A push dispatch failed for conversation {$conversationId}: {$pushDispatchError->getMessage()}"
            );
        }
        $this->lastPublishBreakdownMs = ['firebase' => $firebaseMs, 'a2aCount' => $a2aCountMs];
    }

    /**
     * Write the realtime change signal of `$conversation` (a persisted conversation). Best effort — it must swallow its
     * own failures. The core has no realtime transport: nothing is written.
     *
     * @param AIConversation $conversation
     * @param string $runStatus one of the STATUS_* values
     * @return void
     */
    protected function writeChangeSignal(AIConversation $conversation, string $runStatus): void
    {
    }

    /**
     * Queue the A2A push notification of conversation `$conversationId` (it has enabled push configs). The core has no
     * push queue: nothing is dispatched.
     *
     * @param int $conversationId
     * @return void
     */
    protected function dispatchTaskPushNotification(int $conversationId): void
    {
    }

    /**
     * Settle a realtime signal whose response is still outstanding (publishes nothing). Called before the loop forks
     * parallel-safe calls (a child must not inherit a live connection) and at the claim-lost exit. The core sends
     * nothing, so nothing is in flight.
     */
    public function settleInFlightSignal(): void
    {
    }

    /** Forget the A2A push-config memo of the publishes outside a run (lever B); called when a resume job starts and at loop start. */
    public function resetPushConfigMemo(): void
    {
        $this->pushConfigMemoOutsideARun = null;
    }

    /** The A2A COUNT behind the memo; overridable for tests. */
    protected function hasEnabledPushConfigs(int $conversationId): bool
    {
        /** @var AIConversationTaskPushNotificationConfigsService $pushNotificationConfigsService */
        $pushNotificationConfigsService = AIConversationTaskPushNotificationConfigs::getService();
        return $pushNotificationConfigsService->hasEnabledConfigsForConversation($conversationId);
    }

    /**
     * Signal that the display TITLE / SUMMARY of `$conversation` was (re)generated and persisted, so a
     * listening frontend picks up the fresh title without polling. Best effort. The core has no realtime transport:
     * nothing is written.
     */
    public function publishSummaryUpdatedSignal(AIConversation $conversation): void
    {
    }

    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }
}
