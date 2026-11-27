<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionAction;
use DDD\Domain\AI\Services\Connections\AgentConnectionActionsService;
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
 * The audit: one row per call through a connection, the refused ones included. Append-only. Reads are logged as
 * metadata, never content; a customer's address is stored as a hash.
 *
 * @property AgentConnectionActions $parent
 * @method AgentConnectionActions getParent()
 * @method static AgentConnectionActionsService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentConnectionId', 'created'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentConnectionId', 'operation', 'outcome', 'created'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionAction::class)]
class AgentConnectionAction extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string OPERATION_LIST = 'LIST';
    public const string OPERATION_SEARCH = 'SEARCH';
    public const string OPERATION_READ = 'READ';
    public const string OPERATION_DRAFT = 'DRAFT';
    public const string OPERATION_SEND = 'SEND';
    public const string OPERATION_CREATE = 'CREATE';
    public const string OPERATION_UPDATE = 'UPDATE';
    public const string OPERATION_DELETE = 'DELETE';
    public const string OPERATION_MERGE = 'MERGE';
    public const string OPERATION_LINK = 'LINK';
    public const string OPERATION_UNLINK = 'UNLINK';
    public const string OPERATION_CONNECT = 'CONNECT';
    public const string OPERATION_DISCONNECT = 'DISCONNECT';
    public const string OPERATION_INBOUND = 'INBOUND';
    /** @var string A setting changed: visibility, a level, a name, boundaries */
    public const string OPERATION_VISIBILITY = 'VISIBILITY';
    public const string OPERATION_SETTINGS = 'SETTINGS';
    public const string OPERATION_APPROVAL = 'APPROVAL';

    /** @var string[] The operations that reach people or change data at the provider */
    public const array OUTWARD_OPERATIONS = [self::OPERATION_SEND, self::OPERATION_CREATE, self::OPERATION_UPDATE, self::OPERATION_DELETE, self::OPERATION_MERGE, self::OPERATION_LINK, self::OPERATION_UNLINK];

    public const string OUTCOME_DONE = 'DONE';
    public const string OUTCOME_REFUSED_BY_GUARD = 'REFUSED_BY_GUARD';
    public const string OUTCOME_REFUSED_BY_LIMIT = 'REFUSED_BY_LIMIT';
    public const string OUTCOME_AWAITING_APPROVAL = 'AWAITING_APPROVAL';
    public const string OUTCOME_FAILED = 'FAILED';

    public ?int $agentConnectionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnection $agentConnection;

    #[Length(max: 20)]
    public ?string $capability = null;

    #[Length(max: 20)]
    public ?string $operation = null;

    /** @var string|null The tool or route that acted */
    #[Length(max: 100)]
    public ?string $toolName = null;

    public ?int $aiConversationId = null;

    public ?int $actingAccountId = null;

    /** @var string|null The target: an internal address in clear, an external one as a hash */
    #[Length(max: 255)]
    public ?string $target = null;

    /** @var string|null sha256 of the exact content sent or created */
    #[Length(max: 64)]
    public ?string $contentHash = null;

    #[Length(max: 24)]
    public ?string $outcome = null;

    /** @var string|null Why refused or failed, without secrets */
    #[Length(max: 500)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $outcomeText = null;

    public ?int $agentConnectionApprovalRequestId = null;

    public ?float $providerFeeUsd = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
