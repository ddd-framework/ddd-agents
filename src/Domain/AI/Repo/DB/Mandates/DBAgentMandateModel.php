<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Mandates;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AgentMandates')]
class DBAgentMandateModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentMandate';

	public const string TABLE_NAME = 'AgentMandates';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Mandates\AgentMandate';

	#[ORM\Column(type: 'integer')]
	public int $accountId;

	#[DatabaseColumn(onUpdateAction:'`agentSpaceId`')]
	#[ORM\Column(type: 'integer')]
	public ?int $agentSpaceId;

	#[ORM\Column(type: 'string')]
	public string $actionClass;

	#[ORM\Column(type: 'string')]
	public string $scopeType;

	#[ORM\Column(type: 'datetime')]
	public DateTime $validFrom;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $validUntil;

	#[ORM\Column(type: 'string')]
	public string $status = 'ACTIVE';

	#[ORM\Column(type: 'string')]
	public ?string $customerApprovalExcerpt;

	#[ORM\Column(type: 'string')]
	public ?string $constraintsNote;

	#[ORM\Column(type: 'integer')]
	public ?int $sourceConversationId;

	#[ORM\Column(type: 'integer')]
	public ?int $sourceUserMessageId;

	#[ORM\Column(type: 'string')]
	public ?string $coveredToolName;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $revokedAt;

	#[ORM\Column(type: 'integer')]
	public ?int $revokedByMessageId;

	#[ORM\Column(type: 'integer')]
	public ?int $supersededByMandateId;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAccountModel::class)]
	#[ORM\JoinColumn(name: 'accountId', referencedColumnName: 'id')]
	public DBAccountModel $account;

	#[ORM\ManyToOne(targetEntity: DBAgentSpaceModel::class)]
	#[ORM\JoinColumn(name: 'agentSpaceId', referencedColumnName: 'id')]
	public ?DBAgentSpaceModel $agentSpace;

}