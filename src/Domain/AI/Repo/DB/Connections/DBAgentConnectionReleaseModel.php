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
#[ORM\Table(name: 'AgentConnectionReleases')]
class DBAgentConnectionReleaseModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnectionRelease';

	public const string TABLE_NAME = 'AgentConnectionReleases';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnectionRelease';

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'string')]
	public ?string $targetType = 'AGENT_SPACE';

	#[ORM\Column(type: 'integer')]
	public ?int $agentSpaceId;

	#[ORM\Column(type: 'json')]
	public mixed $capabilityCeilings;

	#[ORM\Column(type: 'json')]
	public mixed $scopeBoundaries;

	#[ORM\Column(type: 'integer')]
	public ?int $grantedByAccountId;

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