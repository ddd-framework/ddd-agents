<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionDriveRoot;
use DDD\Domain\AI\Services\Connections\AgentConnectionDriveRootsService;
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
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/**
 * One root of a drive connection and how it came: the agent's own identity (an item shared with it), a release to the
 * agent by a person with their own connection (the person's share), a root a person picked on their own connection, a
 * Picker selection. ACTIVE roots are the connection's FOLDER boundary lines; UNASSIGNED items were shared with the
 * agent's identity and are visible to no one until a holder of the system right assigns them; REVOKED roots stay for
 * the audit.
 *
 * @property AgentConnectionDriveRoots $parent
 * @method AgentConnectionDriveRoots getParent()
 * @method static AgentConnectionDriveRootsService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['agentConnectionId', 'itemId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionDriveRoot::class)]
class AgentConnectionDriveRoot extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string WAY_OWN_IDENTITY = 'OWN_IDENTITY';
    public const string WAY_RELEASED_TO_AGENT = 'RELEASED_TO_AGENT';
    public const string WAY_PERSON_ROOT = 'PERSON_ROOT';
    public const string WAY_PICKER = 'PICKER';
    public const array WAYS = [self::WAY_OWN_IDENTITY, self::WAY_RELEASED_TO_AGENT, self::WAY_PERSON_ROOT, self::WAY_PICKER];

    public const string STATUS_ACTIVE = 'ACTIVE';
    public const string STATUS_UNASSIGNED = 'UNASSIGNED';
    public const string STATUS_REVOKED = 'REVOKED';
    public const array STATUSES = [self::STATUS_ACTIVE, self::STATUS_UNASSIGNED, self::STATUS_REVOKED];

    public ?int $agentConnectionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnection $agentConnection;

    /** @var string|null The provider's folder, shared drive or file id */
    #[Length(max: 128)]
    public ?string $itemId = null;

    #[Length(max: 255)]
    public ?string $itemName = null;

    #[Length(max: 24)]
    #[Choice(choices: self::WAYS)]
    public ?string $releaseWay = self::WAY_PERSON_ROOT;

    #[Length(max: 16)]
    #[Choice(choices: self::STATUSES)]
    public ?string $status = self::STATUS_ACTIVE;

    /** @var string|null The provider's role of the agent's identity on the item (reader, commenter, writer, fileOrganizer) */
    #[Length(max: 24)]
    public ?string $providerRole = null;

    public ?int $grantedByAccountId = null;

    /** @var int|null Release to the agent: the releasing person's own connection whose token created the share */
    public ?int $sourceAgentConnectionId = null;

    /** @var string|null Release to the agent: the provider's permission id of the share (to take it back) */
    #[Length(max: 128)]
    public ?string $providerPermissionId = null;

    public ?DateTime $revokedAt = null;

    public ?int $revokedByAccountId = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(($this->agentConnectionId ?? 0) . '_' . $this->itemId);
    }
}
