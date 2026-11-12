<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\TraceEvents;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AIConversationTraceEvents')]
class DBAIConversationTraceEventModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversationTraceEvent';

	public const string TABLE_NAME = 'AIConversationTraceEvents';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent';

	#[ORM\Column(type: 'integer')]
	public ?int $aiConversationId;

	#[ORM\Column(type: 'integer')]
	public ?int $agentVoiceCallId;

	#[ORM\Column(type: 'integer')]
	public ?int $messageIndex;

	#[ORM\Column(type: 'integer')]
	public int $occurredAtUnixMs;

	#[ORM\Column(type: 'integer')]
	public ?int $receivedAtUnixMs;

	#[ORM\Column(type: 'integer')]
	public ?int $providerOffsetMs;

	#[ORM\Column(type: 'string')]
	public string $source;

	#[ORM\Column(type: 'string')]
	public string $eventType;

	#[ORM\Column(type: 'string')]
	public string $eventKey;

	#[ORM\Column(type: 'integer')]
	public ?int $durationMs;

	#[ORM\Column(type: 'string')]
	public ?string $summary;

	#[ORM\Column(type: 'string')]
	public ?string $payload;

	#[ORM\Column(type: 'boolean')]
	public ?bool $truncated;

	#[ORM\Column(type: 'integer')]
	public ?int $originalLength;

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
	public ?DBAIConversationModel $aiConversation;

}