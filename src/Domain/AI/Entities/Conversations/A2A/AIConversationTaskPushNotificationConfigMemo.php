<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\A2A;

/**
 * Whether a conversation has enabled A2A push configs, remembered per conversation id under the initiator message
 * index it was read under (lever B of the realtime publish): a publish under a different
 * initiator (a later turn, a pre-loop publish) reads again. A plain runtime object, never persisted — a run holds one
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRun::$pushConfigMemo}), the realtime service one for the
 * publishes outside a run.
 */
class AIConversationTaskPushNotificationConfigMemo
{
    /** @var array<int, int|null> conversation id => the initiator message index its answer was read under */
    protected array $initiatorMessageIndexByConversationId = [];

    /** @var bool[] conversation id => whether it had enabled push configs */
    protected array $hasEnabledConfigsByConversationId = [];

    /** The remembered answer for the conversation under this initiator; null when none was read under it. */
    public function findHasEnabledConfigs(int $conversationId, ?int $initiatorMessageIndex): ?bool
    {
        if (!array_key_exists($conversationId, $this->hasEnabledConfigsByConversationId)
            || $this->initiatorMessageIndexByConversationId[$conversationId] !== $initiatorMessageIndex
        ) {
            return null;
        }
        return $this->hasEnabledConfigsByConversationId[$conversationId];
    }

    /** Remember the answer read for the conversation under this initiator (replaces an answer of another initiator). */
    public function remember(int $conversationId, ?int $initiatorMessageIndex, bool $hasEnabledConfigs): void
    {
        $this->initiatorMessageIndexByConversationId[$conversationId] = $initiatorMessageIndex;
        $this->hasEnabledConfigsByConversationId[$conversationId] = $hasEnabledConfigs;
    }
}
