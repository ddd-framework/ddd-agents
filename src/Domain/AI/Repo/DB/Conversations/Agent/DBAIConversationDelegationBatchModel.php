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
#[ORM\Table(name: 'AIConversationDelegationBatches')]
class DBAIConversationDelegationBatchModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversationDelegationBatch';

	public const string TABLE_NAME = 'AIConversationDelegationBatches';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch';

	#[ORM\Column(type: 'integer')]
	public int $parentConversationId;

	#[ORM\Column(type: 'integer')]
	public ?int $spawnedByMessageId;

	#[ORM\Column(type: 'integer')]
	public int $expectedDelegationCount;

	#[ORM\Column(type: 'string')]
	public ?string $delegateWakeCondition = 'ALL';

	#[ORM\Column(type: 'integer')]
	public ?int $quorumSuccessThreshold;

	#[ORM\Column(type: 'integer')]
	public ?int $timeoutSeconds = 300;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $deadlineAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $settledAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $foldedAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAIConversationModel::class)]
	#[ORM\JoinColumn(name: 'parentConversationId', referencedColumnName: 'id')]
	public DBAIConversationModel $parentConversation;

	#[ORM\ManyToOne(targetEntity: DBAIConversationMessageModel::class)]
	#[ORM\JoinColumn(name: 'spawnedByMessageId', referencedColumnName: 'id')]
	public ?DBAIConversationMessageModel $spawnedByMessage;

	#[ORM\OneToMany(targetEntity: DBAIConversationModel::class, mappedBy: 'delegationBatch')]
	public PersistentCollection $delegationChildren;

}