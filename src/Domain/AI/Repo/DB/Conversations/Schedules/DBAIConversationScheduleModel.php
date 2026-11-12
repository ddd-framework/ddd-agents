<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Schedules;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AIConversationSchedules')]
class DBAIConversationScheduleModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversationSchedule';

	public const string TABLE_NAME = 'AIConversationSchedules';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule';

	#[ORM\Column(type: 'integer')]
	public int $accountId;

	#[DatabaseColumn(onUpdateAction:'`agentSpaceId`')]
	#[ORM\Column(type: 'integer')]
	public ?int $agentSpaceId;

	#[ORM\Column(type: 'string')]
	public ?string $initiatorType = 'ADMIN';

	#[ORM\Column(type: 'integer')]
	public ?int $createdByConversationId;

	#[ORM\Column(type: 'string')]
	public ?string $title;

	#[ORM\Column(type: 'string')]
	public string $task;

	#[ORM\Column(type: 'string')]
	public ?string $doneCriteria;

	#[ORM\Column(type: 'string')]
	public ?string $schedule;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $oneTimeAt;

	#[ORM\Column(type: 'string')]
	public ?string $status = 'ACTIVE';

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $nextRunAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastFiredAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $scheduleBudgetPotExhaustedNotifiedAt;

	#[ORM\Column(type: 'integer')]
	public ?int $iterationsUsed = 0;

	#[ORM\Column(type: 'integer')]
	public ?int $maxIterations;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $ttlAt;

	#[ORM\Column(type: 'integer')]
	public ?int $failureStreak = 0;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastFailedAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $ownerSawRunsUntilAt;

	#[ORM\Column(type: 'string')]
	public ?string $stateReason;

	#[ORM\Column(type: 'string')]
	public ?string $reportChannel = 'CHAT';

	#[ORM\Column(type: 'json')]
	public mixed $runSettings;

	#[ORM\Column(type: 'float')]
	public ?float $perRunCostCapUsd;

	#[ORM\Column(type: 'string')]
	public ?string $timezone;

	#[ORM\Column(type: 'boolean')]
	public ?bool $timezoneUnverified;

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