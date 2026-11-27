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
#[ORM\Table(name: 'AgentConnectionThreads')]
class DBAgentConnectionThreadModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentConnectionThread';

	public const string TABLE_NAME = 'AgentConnectionThreads';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Connections\AgentConnectionThread';

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'string')]
	public ?string $externalChannelId;

	#[ORM\Column(type: 'string')]
	public ?string $externalThreadId;

	#[ORM\Column(type: 'integer')]
	public ?int $aiConversationId;

	#[ORM\Column(type: 'integer')]
	public ?int $openedByAccountId;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastInboundAt;

	#[ORM\Column(type: 'integer')]
	public ?int $postedUpToMessageIndex = -1;

	#[ORM\Column(type: 'boolean')]
	public ?bool $readByOwnerOnly = false;

	#[ORM\Column(type: 'boolean')]
	public ?bool $answerOwed = false;

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