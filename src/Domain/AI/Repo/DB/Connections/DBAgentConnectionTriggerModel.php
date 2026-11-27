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
#[ORM\Table(name: 'AgentConnectionTriggers')]
class DBAgentConnectionTriggerModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnectionTrigger';

	public const string TABLE_NAME = 'AgentConnectionTriggers';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnectionTrigger';

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'integer')]
	public ?int $accountId;

	#[ORM\Column(type: 'string')]
	public ?string $eventKind;

	#[ORM\Column(type: 'string')]
	public ?string $senderFilter;

	#[ORM\Column(type: 'string')]
	public ?string $subjectFilter;

	#[ORM\Column(type: 'string')]
	public ?string $channelFilter;

	#[ORM\Column(type: 'string')]
	public ?string $purposeName;

	#[ORM\Column(type: 'string')]
	public ?string $missionText;

	#[ORM\Column(type: 'float')]
	public ?float $perRunCostCapUsd;

	#[ORM\Column(type: 'boolean')]
	public ?bool $active = true;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastFiredAt;

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