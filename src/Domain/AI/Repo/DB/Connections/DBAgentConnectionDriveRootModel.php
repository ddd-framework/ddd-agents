<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AgentConnectionDriveRoots')]
class DBAgentConnectionDriveRootModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnectionDriveRoot';

	public const string TABLE_NAME = 'AgentConnectionDriveRoots';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnectionDriveRoot';

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'string')]
	public ?string $itemId;

	#[ORM\Column(type: 'string')]
	public ?string $itemName;

	#[ORM\Column(type: 'string')]
	public ?string $releaseWay = 'PERSON_ROOT';

	#[ORM\Column(type: 'string')]
	public ?string $status = 'ACTIVE';

	#[ORM\Column(type: 'string')]
	public ?string $providerRole;

	#[ORM\Column(type: 'integer')]
	public ?int $grantedByAccountId;

	#[ORM\Column(type: 'integer')]
	public ?int $sourceAgentConnectionId;

	#[ORM\Column(type: 'string')]
	public ?string $providerPermissionId;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $revokedAt;

	#[ORM\Column(type: 'integer')]
	public ?int $revokedByAccountId;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAgentConnectionModel::class)]
	#[ORM\JoinColumn(name: 'agentConnectionId', referencedColumnName: 'id')]
	public ?DBAgentConnectionModel $agentConnection;

}