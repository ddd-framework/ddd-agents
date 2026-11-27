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
#[ORM\Table(name: 'AgentConnectionActions')]
class DBAgentConnectionActionModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnectionAction';

	public const string TABLE_NAME = 'AgentConnectionActions';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnectionAction';

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'string')]
	public ?string $capability;

	#[ORM\Column(type: 'string')]
	public ?string $operation;

	#[ORM\Column(type: 'string')]
	public ?string $toolName;

	#[ORM\Column(type: 'integer')]
	public ?int $aiConversationId;

	#[ORM\Column(type: 'integer')]
	public ?int $actingAccountId;

	#[ORM\Column(type: 'string')]
	public ?string $target;

	#[ORM\Column(type: 'string')]
	public ?string $contentHash;

	#[ORM\Column(type: 'string')]
	public ?string $outcome;

	#[ORM\Column(type: 'string')]
	public ?string $outcomeText;

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionApprovalRequestId;

	#[ORM\Column(type: 'float')]
	public ?float $providerFeeUsd;

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