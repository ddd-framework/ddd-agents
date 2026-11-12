<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\TurnEvents;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AIConversationTurnEventSnapshots')]
class DBAIConversationTurnEventSnapshotModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversationTurnEventSnapshot';

	public const string TABLE_NAME = 'AIConversationTurnEventSnapshots';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEventSnapshot';

	#[ORM\Column(type: 'string')]
	public string $hash;

	#[ORM\Column(type: 'string')]
	public string $payload;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}