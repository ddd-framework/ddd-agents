<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Agent;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AIConversationToolCallBatches')]
class DBAIConversationToolCallBatchModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversationToolCallBatch';

	public const string TABLE_NAME = 'AIConversationToolCallBatches';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\Agent\AIConversationToolCallBatch';

	#[ORM\Column(type: 'integer')]
	public int $conversationId;

	#[ORM\Column(type: 'string')]
	public string $emittedBatchId;

	#[ORM\Column(type: 'integer')]
	public ?int $expectedCount = 0;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $deadlineAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $settledAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $consumedAt;

	#[ORM\Column(type: 'integer')]
	public ?int $sourceUserMessageId;

	#[ORM\Column(type: 'integer')]
	public ?int $loadedCapabilitiesMessageId;

	#[ORM\Column(type: 'integer')]
	public ?int $lastSeenMessageId;

	#[ORM\Column(type: 'string')]
	public ?string $runToken;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAIConversationModel::class)]
	#[ORM\JoinColumn(name: 'conversationId', referencedColumnName: 'id')]
	public DBAIConversationModel $conversation;

	#[ORM\ManyToOne(targetEntity: DBAIConversationMessageModel::class)]
	#[ORM\JoinColumn(name: 'sourceUserMessageId', referencedColumnName: 'id')]
	public ?DBAIConversationMessageModel $sourceUserMessage;

}