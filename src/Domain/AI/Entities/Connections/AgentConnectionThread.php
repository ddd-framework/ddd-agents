<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionThread;
use DDD\Domain\AI\Services\Connections\AgentConnectionThreadsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/**
 * One external thread (a Slack thread, a mail's reference chain) is one conversation. Unique per
 * connection, channel and thread, so a repeated event never opens a second conversation.
 *
 * @property AgentConnectionThreads $parent
 * @method AgentConnectionThreads getParent()
 * @method static AgentConnectionThreadsService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['agentConnectionId', 'externalChannelId', 'externalThreadId'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['aiConversationId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionThread::class)]
class AgentConnectionThread extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;


    public ?int $agentConnectionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnection $agentConnection;

    #[Length(max: 128)]
    public ?string $externalChannelId = null;

    /** @var string|null Slack `thread_ts`, the first Message-ID of a mail chain */
    #[Length(max: 255)]
    public ?string $externalThreadId = null;

    public ?int $aiConversationId = null;

    /** @var int|null The linked account that opened the thread (the conversation's owner) */
    public ?int $openedByAccountId = null;

    public ?DateTime $lastInboundAt = null;

    /** @var int The highest message index of the conversation already posted into the thread */
    public int $postedUpToMessageIndex = -1;

    /** @var bool Only its owner reads the thread (a direct message); false = a channel with other readers */
    public bool $readByOwnerOnly = false;

    /** @var bool A turn of this thread waits for its answer to be posted back */
    public bool $answerOwed = false;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(($this->agentConnectionId ?? 0) . '_' . $this->externalChannelId . '_' . $this->externalThreadId);
    }
}
