<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AIConversations')]
class DBAIConversationModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversation';

	public const string TABLE_NAME = 'AIConversations';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\AIConversation';

	#[ORM\Column(type: 'integer')]
	public ?int $accountId;

	#[DatabaseColumn(onUpdateAction:'`agentSpaceId`')]
	#[ORM\Column(type: 'integer')]
	public ?int $agentSpaceId;

	#[ORM\Column(type: 'json')]
	public mixed $summary;

	#[ORM\Column(type: 'integer')]
	public ?int $aiPromptExtensionId;

	#[ORM\Column(type: 'boolean')]
	public ?bool $budgetBypass = false;

	#[ORM\Column(type: 'string')]
	public ?string $aiModelNameOverride;

	#[ORM\Column(type: 'json')]
	public mixed $compactionSettings;

	#[ORM\Column(type: 'json')]
	public mixed $contextRetentionSettings;

	#[ORM\Column(type: 'json')]
	public mixed $runSettings;

	#[DatabaseColumn(isMergableJSONColumn: true)]
	#[ORM\Column(type: 'json')]
	public mixed $agentWorkingState;

	#[ORM\Column(type: 'string')]
	public ?string $runState = 'IDLE';

	#[ORM\Column(type: 'string')]
	public ?string $runToken;

	#[ORM\Column(type: 'integer')]
	public ?int $rootConversationId;

	#[ORM\Column(type: 'integer')]
	public ?int $resumeAttempts = 0;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $runHeartbeatAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastInboundMessageAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $finalizedAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $finalizeCompactedAt;

	#[ORM\Column(type: 'string')]
	public ?string $initiatorType;

	#[ORM\Column(type: 'string')]
	public ?string $entryPointKey;

	#[ORM\Column(type: 'integer')]
	public ?int $scheduleId;

	#[ORM\Column(type: 'string')]
	public ?string $timezone;

	#[ORM\Column(type: 'boolean')]
	public ?bool $timezoneUnverified;

	#[ORM\Column(type: 'string')]
	public ?string $purposeName;

	#[ORM\Column(type: 'integer')]
	public ?int $supervisedConversationId;

	#[ORM\Column(type: 'string')]
	public ?string $orchestratorModelNameOverride;

	#[ORM\Column(type: 'integer')]
	public ?int $orchestratorRunCapOverride;

	#[ORM\Column(type: 'string')]
	public ?string $reportChannel;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $agentTurnOwedAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $taskPlanOpenAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $taskPlanRetryableAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $reportOwedAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $reportDeliveredAt;

	#[ORM\Column(type: 'string')]
	public ?string $suppressionReason;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $suppressedAt;

	#[DatabaseColumn(onUpdateAction:'`overheadCostsInUSD`')]
	#[ORM\Column(type: 'float')]
	public ?float $overheadCostsInUSD = 0;

	#[DatabaseColumn(onUpdateAction:'`overheadBookedCosts`')]
	#[ORM\Column(type: 'json')]
	public mixed $overheadBookedCosts;

	#[ORM\Column(type: 'string')]
	public ?string $episodicHistory;

	#[ORM\Column(type: 'integer')]
	public ?int $delegatedFromMessageId;

	#[ORM\Column(type: 'integer')]
	public ?int $delegationBatchId;

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
	public ?DBAccountModel $account;

	#[ORM\ManyToOne(targetEntity: DBAgentSpaceModel::class)]
	#[ORM\JoinColumn(name: 'agentSpaceId', referencedColumnName: 'id')]
	public ?DBAgentSpaceModel $agentSpace;

	#[ORM\ManyToOne(targetEntity: DBAIConversationScheduleModel::class)]
	#[ORM\JoinColumn(name: 'scheduleId', referencedColumnName: 'id')]
	public ?DBAIConversationScheduleModel $schedule;

	#[ORM\ManyToOne(targetEntity: DBAIConversationMessageModel::class)]
	#[ORM\JoinColumn(name: 'delegatedFromMessageId', referencedColumnName: 'id')]
	public ?DBAIConversationMessageModel $delegatedFromMessage;

	#[ORM\ManyToOne(targetEntity: DBAIConversationDelegationBatchModel::class)]
	#[ORM\JoinColumn(name: 'delegationBatchId', referencedColumnName: 'id')]
	public ?DBAIConversationDelegationBatchModel $delegationBatch;

	#[ORM\OneToMany(targetEntity: DBAIConversationMessageModel::class, mappedBy: 'aiConversation')]
	public PersistentCollection $messages;

	#[ORM\OneToMany(targetEntity: DBAIConversationCompactionNodeModel::class, mappedBy: 'aiConversation')]
	public PersistentCollection $compactionNodes;

	#[ORM\OneToMany(targetEntity: DBAIConversationDelegationBatchModel::class, mappedBy: 'parentConversation')]
	public PersistentCollection $forkedDelegationBatches;

}