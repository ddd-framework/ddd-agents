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
#[ORM\Table(name: 'AgentConnectionApprovalRequests')]
class DBAgentConnectionApprovalRequestModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnectionApprovalRequest';

	public const string TABLE_NAME = 'AgentConnectionApprovalRequests';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequest';

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'integer')]
	public ?int $accountId;

	#[ORM\Column(type: 'integer')]
	public ?int $aiConversationId;

	#[ORM\Column(type: 'string')]
	public ?string $toolName;

	#[ORM\Column(type: 'string')]
	public ?string $capability;

	#[ORM\Column(type: 'string')]
	public ?string $operation;

	#[ORM\Column(type: 'string')]
	public ?string $summary;

	#[ORM\Column(type: 'string')]
	public ?string $content;

	#[ORM\Column(type: 'string')]
	public ?string $stepArgumentsJson;

	#[ORM\Column(type: 'string')]
	public ?string $contentHash;

	#[ORM\Column(type: 'json')]
	public mixed $options;

	#[ORM\Column(type: 'string')]
	public ?string $status = 'OPEN';

	#[ORM\Column(type: 'string')]
	public ?string $approvalToken;

	#[ORM\Column(type: 'integer')]
	public ?int $answeredByAccountId;

	#[ORM\Column(type: 'string')]
	public ?string $answeredVia;

	#[ORM\Column(type: 'integer')]
	public ?int $answeredThroughAgentConnectionId;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $answeredAt;

	#[ORM\Column(type: 'string')]
	public ?string $postedExternalReference;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $expiresAt;

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