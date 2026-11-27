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
#[ORM\Table(name: 'AgentConnectionCredentials')]
class DBAgentConnectionCredentialsModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnectionCredentials';

	public const string TABLE_NAME = 'AgentConnectionCredentials';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnectionCredentials';

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'string')]
	public ?string $credentialType;

	#[ORM\Column(type: 'string')]
	public ?string $encryptedPayload;

	#[ORM\Column(type: 'string')]
	public ?string $keyFingerprint;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $accessTokenExpiresAt;

	#[ORM\Column(type: 'string')]
	public ?string $environmentVariableName;

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