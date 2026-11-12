<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\TurnEvents;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AIConversationTurnEvents')]
class DBAIConversationTurnEventModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversationTurnEvent';

	public const string TABLE_NAME = 'AIConversationTurnEvents';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent';

	#[ORM\Column(type: 'integer')]
	public int $aiConversationId;

	#[ORM\Column(type: 'integer')]
	public ?int $initiatorMessageIndex;

	#[ORM\Column(type: 'integer')]
	public ?int $evaluationMessageIndex;

	#[ORM\Column(type: 'string')]
	public string $kind;

	#[ORM\Column(type: 'string')]
	public ?string $site;

	#[ORM\Column(type: 'string')]
	public ?string $subject;

	#[ORM\Column(type: 'string')]
	public string $eventKey;

	#[ORM\Column(type: 'string')]
	public ?string $requestId;

	#[ORM\Column(type: 'string')]
	public ?string $runToken;

	#[ORM\Column(type: 'string')]
	public ?string $arm;

	#[ORM\Column(type: 'boolean')]
	public ?bool $evalRun;

	#[ORM\Column(type: 'string')]
	public ?string $overrideSource;

	#[ORM\Column(type: 'string')]
	public ?string $catalogSnapshotHash;

	#[ORM\Column(type: 'string')]
	public ?string $payload;

	#[ORM\Column(type: 'integer')]
	public ?int $elapsedMs;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAIConversationModel::class)]
	#[ORM\JoinColumn(name: 'aiConversationId', referencedColumnName: 'id')]
	public DBAIConversationModel $aiConversation;

}