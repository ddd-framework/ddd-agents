<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationToolCallBatch;
use DDD\Domain\AI\Services\Conversations\AIConversationToolCallBatchesService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The tool calls of ONE model response that run concurrently on the `ai_tool_call` queue:
 * the coordinator row of one emission batch. Every call keeps its own message row (one `ToolCall` in the
 * `toolCall` column, stamped with this batch's {@see $emittedBatchId}); this row knows how many of them were
 * dispatched, the absolute deadline, and the two exactly-once gates: {@see $settledAt} (every call terminal, or the
 * deadline passed — the one resume of the turn) and {@see $consumedAt} (the resumed turn fed the results to the
 * model). The settle is a derived COUNT over the call rows under a lock-first batch-row lock, the delegation
 * batch's shape ({@see AIConversationDelegationBatch}); the transactions live on
 * {@see AIConversationToolCallBatchesService}.
 *
 * The provenance anchors ({@see $sourceUserMessageId}, {@see $loadedCapabilitiesMessageId}) pin the job workers to
 * the context the emitting turn had: the newest rows may have moved on by the time a job runs.
 *
 * @method static AIConversationToolCallBatchesService getService()
 */
// The deadline sweep (settledAt IS NULL AND deadlineAt < :now) and the settled-but-unconsumed sweep (settledAt IS
// NOT NULL AND consumedAt IS NULL) read these two; the emission lookup reads the unique pair.
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['settledAt', 'deadlineAt'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['settledAt', 'consumedAt'])]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['conversationId', 'emittedBatchId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, repoClass: DBAIConversationToolCallBatch::class)]
class AIConversationToolCallBatch extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var int|null Id of the conversation whose turn emitted the batch and waits on it (the resume target). */
    #[NotNull]
    public ?int $conversationId;

    /** @var AIConversation|null The conversation — the resume target AND the read-rights anchor. */
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AIConversation $conversation;

    /** @var string|null The id the loop stamps on every call of one model response (`ToolCall::$emittedBatchId`). */
    #[NotNull]
    #[Length(max: 64)]
    public ?string $emittedBatchId;

    /** @var int The number of call rows dispatched to the queue (final before the first dispatch). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $expectedCount = 0;

    /** @var DateTime|null Absolute deadline: max(declared timeout of the calls) + the queue allowance. */
    public ?DateTime $deadlineAt;

    /** @var DateTime|null Set once when every call is terminal or the deadline passed — the exactly-once resume gate. */
    public ?DateTime $settledAt;

    /** @var DateTime|null Set once the resumed turn fed the results to the model — until then a settled batch is an owed turn. */
    public ?DateTime $consumedAt;

    /** @var int|null The newest USER message the emitting turn worked from (the provenance anchor of the jobs). */
    public ?int $sourceUserMessageId;

    /** @var AIConversationMessage|null The provenance anchor message (inspection). */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AIConversationMessage $sourceUserMessage;

    /** @var int|null The message whose loaded-capabilities snapshot the emitting turn resolved its packages from. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $loadedCapabilitiesMessageId;

    /** @var int|null The newest message id the emitting turn had seen — the accepted-input fence of the resumed turn. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $lastSeenMessageId;

    /** @var string|null The run token of the emitting claim (trace only). */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $runToken;
}
