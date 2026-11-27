<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionInboundEvent;
use DDD\Domain\AI\Services\Connections\AgentConnectionInboundEventsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/**
 * One inbound delivery of a provider, kept to drop a repeated delivery by the provider's event id (Slack retries,
 * a mail seen twice by a poll). A short summary only; the content stays at the provider.
 *
 * @property AgentConnectionInboundEvents $parent
 * @method AgentConnectionInboundEvents getParent()
 * @method static AgentConnectionInboundEventsService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['agentConnectionId', 'providerEventId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionInboundEvent::class)]
class AgentConnectionInboundEvent extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string STATE_RECEIVED = 'RECEIVED';
    public const string STATE_HANDLED = 'HANDLED';
    public const string STATE_IGNORED = 'IGNORED';
    public const string STATE_FAILED = 'FAILED';

    public ?int $agentConnectionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnection $agentConnection;

    #[Length(max: 128)]
    public ?string $providerEventId = null;

    #[Length(max: 32)]
    public ?string $eventKind = null;

    #[Length(max: 16)]
    public string $state = self::STATE_RECEIVED;

    #[Length(max: 255)]
    public ?string $stateText = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(($this->agentConnectionId ?? 0) . '_' . $this->providerEventId);
    }
}
