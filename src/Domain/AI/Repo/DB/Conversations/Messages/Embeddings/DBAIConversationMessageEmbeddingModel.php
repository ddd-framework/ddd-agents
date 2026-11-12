<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AIConversationMessageEmbeddings')]
class DBAIConversationMessageEmbeddingModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversationMessageEmbedding';

	public const string TABLE_NAME = 'AIConversationMessageEmbeddings';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\Messages\Embeddings\AIConversationMessageEmbedding';

	#[ORM\Column(type: 'integer')]
	public int $aiConversationMessageId;

	#[ORM\Column(type: 'vector', length: 1536)]
	public mixed $embedding;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAIConversationMessageModel::class)]
	#[ORM\JoinColumn(name: 'aiConversationMessageId', referencedColumnName: 'id')]
	public DBAIConversationMessageModel $aiConversationMessage;

}