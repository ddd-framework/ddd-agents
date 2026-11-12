<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\A2A;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AIConversationTaskPushNotificationConfigs')]
class DBAIConversationTaskPushNotificationConfigModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversationTaskPushNotificationConfig';

	public const string TABLE_NAME = 'AIConversationTaskPushNotificationConfigs';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfig';

	#[ORM\Column(type: 'integer')]
	public int $aiConversationId;

	#[ORM\Column(type: 'integer')]
	public int $taskUserMessageId;

	#[ORM\Column(type: 'string')]
	public string $url;

	#[ORM\Column(type: 'string')]
	public ?string $token;

	#[ORM\Column(type: 'string')]
	public ?string $lastDeliveredState;

	#[ORM\Column(type: 'integer')]
	public ?int $deliveryAttempts = 0;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $disabledAt;

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