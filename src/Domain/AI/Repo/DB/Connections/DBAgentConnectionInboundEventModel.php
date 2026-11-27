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
#[ORM\Table(name: 'AgentConnectionInboundEvents')]
class DBAgentConnectionInboundEventModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnectionInboundEvent';

	public const string TABLE_NAME = 'AgentConnectionInboundEvents';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnectionInboundEvent';

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'string')]
	public ?string $providerEventId;

	#[ORM\Column(type: 'string')]
	public ?string $eventKind;

	#[ORM\Column(type: 'string')]
	public ?string $state = 'RECEIVED';

	#[ORM\Column(type: 'string')]
	public ?string $stateText;

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