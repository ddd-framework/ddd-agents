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
#[ORM\Table(name: 'AgentConnections')]
class DBAgentConnectionModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnection';

	public const string TABLE_NAME = 'AgentConnections';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnection';

	#[ORM\Column(type: 'string')]
	public ?string $ownership = 'ACCOUNT_GRANTED';

	#[ORM\Column(type: 'string')]
	public ?string $provider;

	#[ORM\Column(type: 'string')]
	public ?string $externalAccountId;

	#[ORM\Column(type: 'string')]
	public ?string $displayName;

	#[ORM\Column(type: 'json')]
	public mixed $scopeBoundaries;

	#[ORM\Column(type: 'boolean')]
	public ?bool $isDefaultForItsCapabilities = false;

	#[ORM\Column(type: 'string')]
	public ?string $externalAddress;

	#[ORM\Column(type: 'integer')]
	public ?int $accountId;

	#[ORM\Column(type: 'integer')]
	public ?int $agentSpaceId;

	#[ORM\Column(type: 'integer')]
	public ?int $connectedByAccountId;

	#[ORM\Column(type: 'string')]
	public ?string $status = 'IN_PROGRESS';

	#[ORM\Column(type: 'string')]
	public ?string $environment;

	#[ORM\Column(type: 'boolean')]
	public ?bool $postAsPersonAllowed = false;

	#[ORM\Column(type: 'string')]
	public ?string $grantedScopes;

	#[ORM\Column(type: 'integer')]
	public ?int $sendsPerHourLimit;

	#[ORM\Column(type: 'integer')]
	public ?int $sendsPerDayLimit;

	#[ORM\Column(type: 'integer')]
	public ?int $sendsPerRecipientPerDayLimit;

	#[ORM\Column(type: 'integer')]
	public ?int $inboundRunsPerSenderPerHourLimit;

	#[ORM\Column(type: 'integer')]
	public ?int $consecutiveErrorCount = 0;

	#[ORM\Column(type: 'string')]
	public ?string $lastErrorText;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastErrorAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastUsedAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\OneToMany(targetEntity: DBAgentConnectionPermissionModel::class, mappedBy: 'agentConnection')]
	public PersistentCollection $permissions;

	#[ORM\OneToMany(targetEntity: DBAgentConnectionReleaseModel::class, mappedBy: 'agentConnection')]
	public PersistentCollection $releases;

	#[ORM\OneToMany(targetEntity: DBAgentConnectionAddressModel::class, mappedBy: 'agentConnection')]
	public PersistentCollection $addresses;

}