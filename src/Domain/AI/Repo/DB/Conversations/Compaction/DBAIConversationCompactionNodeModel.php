<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Compaction;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AIConversationCompactionNodes')]
class DBAIConversationCompactionNodeModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversationCompactionNode';

	public const string TABLE_NAME = 'AIConversationCompactionNodes';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode';

	#[ORM\Column(type: 'integer')]
	public int $aiConversationId;

	#[ORM\Column(type: 'integer')]
	public int $level;

	#[ORM\Column(type: 'integer')]
	public int $coveredFromMessageIndex;

	#[ORM\Column(type: 'integer')]
	public int $coveredToMessageIndex;

	#[ORM\Column(type: 'string')]
	public string $summaryText;

	#[ORM\Column(type: 'json')]
	public mixed $preservedFacts;

	#[ORM\Column(type: 'vector', length: 1536)]
	public mixed $summaryEmbedding;

	#[ORM\Column(type: 'boolean')]
	public bool $fromRawMessages;

	#[ORM\Column(type: 'string')]
	public ?string $aiModelName;

	#[ORM\Column(type: 'integer')]
	public ?int $summaryTokens;

	#[ORM\Column(type: 'integer')]
	public ?int $coveredMessageCount;

	#[ORM\Column(type: 'integer')]
	public ?int $coveredTokens;

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