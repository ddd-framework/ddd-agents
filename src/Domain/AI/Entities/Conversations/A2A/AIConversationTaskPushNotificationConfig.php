<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\A2A;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Repo\DB\Conversations\A2A\DBAIConversationTaskPushNotificationConfig;
use DDD\Domain\AI\Services\Conversations\AIConversationTaskPushNotificationConfigsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * An A2A push-notification registration: the webhook a CLIENT registered for ONE task — the server
 * POSTs a StreamResponse-wrapped task status update event to {@see $url} on
 * every task state change (spec: `TaskPushNotificationConfig` — our entity id doubles as the wire config `id`).
 *
 * Deliberately its OWN table, never a column on the conversation/message rows: delivery bookkeeping
 * ({@see $lastDeliveredState}, {@see $deliveryAttempts}) is MUTABLE state — mutating it on the conversation row
 * would collide with the atomic run-claim primitives (no full-row updates on the claim row), and the
 * messages table must not carry a >99.9%-NULL column. The task identity is the same pair the wire task id encodes
 * (the host's A2A task id: conversationId + task user-message id).
 *
 * @method static AIConversationTaskPushNotificationConfigsService getService()
 */
// Delivery lookup: every state change asks "which ENABLED configs exist for this conversation?" (indexed EXISTS on
// the hot path, full rows in the worker).
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['aiConversationId', 'disabledAt'])]
#[LazyLoadRepo(LazyLoadRepo::DB, repoClass: DBAIConversationTaskPushNotificationConfig::class)]
class AIConversationTaskPushNotificationConfig extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** Consecutive delivery failures after which the config is disabled (polling stays the fallback). */
    public const int MAX_CONSECUTIVE_DELIVERY_FAILURES = 5;

    /** @var int|null Id of the conversation whose task this webhook watches (the read-rights anchor). */
    #[NotNull]
    public ?int $aiConversationId;

    /** @var AIConversation|null The watched conversation — rights anchor and contextId source. */
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AIConversation $aiConversation;

    /** @var int The task's initiating user-message id (second half of the wire task id). */
    #[NotNull]
    public int $taskUserMessageId;

    /** @var string The client's webhook URL — the server POSTs task update events here. */
    #[NotNull]
    #[DatabaseColumn(varCharLength: 2048)]
    public string $url;

    /** @var string|null Client-chosen verification token (spec `PushNotificationConfig.token`) — echoed on every POST so the client can authenticate the call. */
    public ?string $token;

    /** @var string|null The last TASK_STATE_* successfully delivered — the dedup gate against redeliveries/duplicate ticks. */
    public ?string $lastDeliveredState;

    /** @var int Consecutive FAILED delivery attempts since the last success (reset on success). */
    public int $deliveryAttempts = 0;

    /** @var DateTime|null Set when delivery is permanently disabled ({@see MAX_CONSECUTIVE_DELIVERY_FAILURES} reached, or client-deleted). */
    public ?DateTime $disabledAt;

    /** Whether the delivery worker should still POST to this config. */
    public function isDeliveryEnabled(): bool
    {
        return !isset($this->disabledAt);
    }

    /** Whether $taskState was already delivered (dedup: redelivered ticks and duplicate state changes are no-ops). */
    public function wasStateAlreadyDelivered(string $taskState): bool
    {
        return ($this->lastDeliveredState ?? null) === $taskState;
    }

    /** Record a SUCCESSFUL delivery of $taskState: sets the dedup gate and resets the failure streak. */
    public function recordDeliverySuccess(string $taskState): void
    {
        $this->lastDeliveredState = $taskState;
        $this->deliveryAttempts = 0;
    }

    /**
     * Record a FAILED delivery attempt; disables the config once the consecutive-failure cap is reached
     * (the client keeps polling — a dead webhook must never burn retries forever).
     */
    public function recordDeliveryFailure(): void
    {
        $this->deliveryAttempts++;
        if ($this->deliveryAttempts >= self::MAX_CONSECUTIVE_DELIVERY_FAILURES) {
            $this->disabledAt = new DateTime();
        }
    }
}
