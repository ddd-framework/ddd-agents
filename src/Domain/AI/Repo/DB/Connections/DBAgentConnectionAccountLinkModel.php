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
#[ORM\Table(name: 'AgentConnectionAccountLinks')]
class DBAgentConnectionAccountLinkModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnectionAccountLink';

	public const string TABLE_NAME = 'AgentConnectionAccountLinks';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnectionAccountLink';

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'string')]
	public ?string $externalUserId;

	#[ORM\Column(type: 'string')]
	public ?string $externalDisplayName;

	#[ORM\Column(type: 'integer')]
	public ?int $accountId;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $verifiedAt;

	#[ORM\Column(type: 'string')]
	public ?string $verificationMethod;

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