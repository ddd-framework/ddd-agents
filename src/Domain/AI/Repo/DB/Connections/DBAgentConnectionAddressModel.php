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
#[ORM\Table(name: 'AgentConnectionAddresses')]
class DBAgentConnectionAddressModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnectionAddress';

	public const string TABLE_NAME = 'AgentConnectionAddresses';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnectionAddress';

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'string')]
	public ?string $externalAddress;

	#[ORM\Column(type: 'string')]
	public ?string $displayName;

	#[ORM\Column(type: 'string')]
	public ?string $role = 'OPERATED_ON_BEHALF';

	#[ORM\Column(type: 'string')]
	public ?string $audience = 'INTERNAL';

	#[ORM\Column(type: 'string')]
	public ?string $behaviour = 'ANSWER_MENTIONS';

	#[ORM\Column(type: 'string')]
	public ?string $postingUse = 'NONE';

	#[ORM\Column(type: 'string')]
	public ?string $requiredAccountRight;

	#[ORM\Column(type: 'string')]
	public ?string $purposeName;

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