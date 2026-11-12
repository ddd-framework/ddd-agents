<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Messages;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddingModel;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AIConversationMessages')]
class DBAIConversationMessageModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversationMessage';

	public const string TABLE_NAME = 'AIConversationMessages';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage';

	public static array $virtualColumns = ['virtualToolName' => [ 'createIndex' => false, 'stored' => true, 'as' => '(JSON_UNQUOTE(JSON_EXTRACT(toolCall, \'$.name\')))', 'referenceColumn' => 'toolName', 'referenceColumnStored' => false, ], 'virtualToolResultChars' => [ 'createIndex' => false, 'stored' => true, 'as' => '(CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(toolCall, \'$.result\'))))', 'referenceColumn' => 'toolResultChars', 'referenceColumnStored' => false, ], 'virtualToolArgumentsChars' => [ 'createIndex' => false, 'stored' => true, 'as' => '(CHAR_LENGTH(JSON_EXTRACT(toolCall, \'$.arguments\')))', 'referenceColumn' => 'toolArgumentsChars', 'referenceColumnStored' => false, ], 'virtualCreatedDate' => [ 'createIndex' => false, 'stored' => true, 'as' => '(DATE(created))', 'referenceColumn' => 'createdDate', 'referenceColumnStored' => false, ]];

	#[ORM\Column(type: 'integer')]
	public ?int $supervisorTurnMessageId;

	#[ORM\Column(type: 'integer')]
	public int $aiConversationId;

	#[ORM\Column(type: 'integer')]
	public int $indexInConversation;

	#[ORM\Column(type: 'string')]
	public ?string $status;

	#[ORM\Column(type: 'string')]
	public ?string $messageType = 'TEXT';

	#[ORM\Column(type: 'string')]
	public ?string $signalSource;

	#[ORM\Column(type: 'string')]
	public ?string $authorType;

	#[ORM\Column(type: 'json')]
	public mixed $toolCall;

	#[ORM\Column(type: 'string')]
	public mixed $loadedCapabilities;

	#[DatabaseColumn(isMergableJSONColumn: true)]
	#[ORM\Column(type: 'json')]
	public mixed $delegationResults;

	#[ORM\Column(type: 'json')]
	public mixed $requestSnapshot;

	#[ORM\Column(type: 'json')]
	public mixed $ownerDevice;

	#[ORM\Column(type: 'json')]
	public mixed $taskPlanSnapshot;

	#[ORM\Column(type: 'string')]
	public string $message;

	#[ORM\Column(type: 'integer')]
	public ?int $generationDurationInSeconds;

	#[ORM\Column(type: 'string')]
	public ?string $aiModelName;

	#[ORM\Column(type: 'integer')]
	public ?int $inputTokens;

	#[ORM\Column(type: 'integer')]
	public ?int $outputTokens;

	#[ORM\Column(type: 'integer')]
	public ?int $webSearchCount;

	#[ORM\Column(type: 'float')]
	public ?float $costInUSD = 0;

	#[ORM\Column(type: 'integer')]
	public ?int $cachedInputTokens;

	#[ORM\Column(type: 'integer')]
	public ?int $cacheWriteTokens;

	#[ORM\Column(type: 'float')]
	public ?float $cacheDiscount;

	#[ORM\Column(type: 'integer')]
	public ?int $fixedOverheadTokens;

	#[ORM\Column(type: 'string')]
	public ?string $role;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $truncatedAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $memoryIngestedAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\Column(type: 'string')]
	public string $virtualToolName;

	#[ORM\Column(type: 'integer')]
	public int $virtualToolResultChars;

	#[ORM\Column(type: 'integer')]
	public int $virtualToolArgumentsChars;

	#[ORM\Column(type: 'date')]
	public DateTime $virtualCreatedDate;

	#[ORM\ManyToOne(targetEntity: DBAIConversationModel::class)]
	#[ORM\JoinColumn(name: 'aiConversationId', referencedColumnName: 'id')]
	public DBAIConversationModel $aiConversation;

	#[ORM\OneToMany(targetEntity: DBAIConversationModel::class, mappedBy: 'delegatedFromMessage')]
	public PersistentCollection $delegatedConversations;

	#[ORM\OneToMany(targetEntity: DBAIConversationMessageEmbeddingModel::class, mappedBy: 'aiConversationMessage')]
	public PersistentCollection $messageEmbeddings;

}