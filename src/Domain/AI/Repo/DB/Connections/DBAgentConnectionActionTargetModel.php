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
#[ORM\Table(name: 'AgentConnectionActionTargets')]
class DBAgentConnectionActionTargetModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnectionActionTarget';

	public const string TABLE_NAME = 'AgentConnectionActionTargets';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnectionActionTarget';

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionActionId;

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'string')]
	public ?string $target;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $doneAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAgentConnectionActionModel::class)]
	#[ORM\JoinColumn(name: 'agentConnectionActionId', referencedColumnName: 'id')]
	public ?DBAgentConnectionActionModel $agentConnectionAction;

}