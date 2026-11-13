<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBConversationCompactionNode;
use DDD\Domain\AI\Services\Conversations\ConversationCompactionNodesService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One node of the hierarchical compaction tree. A node summarizes a contiguous range of the conversation's
 * message-index space: level 0 = raw messages, a node at level 1 summarizes one raw segment, level N+1 rolls up K
 * level-N nodes. Immutable + append-only — written once, never mutated; cover membership is derived at read time from
 * `level` + the covered range + the ChangeHistory `created` time (the point-in-time key), so `updated` always equals
 * `created`. Replaced the earlier per-message compaction value object (since removed).
 *
 * @method static ConversationCompactionNodesService getService()
 * @method static DBConversationCompactionNode getRepoClassInstance(string $repoType = null)
 */
// Dedup guard for the at-least-once async roll-up: a redelivered Messenger job that
// re-rolls the same K children over the same range hits this UNIQUE instead of inserting a twin node. Mirrors the
// exactly-once UNIQUE(aiConversationId, indexInConversation) on AIConversationMessage.
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['aiConversationId', 'level', 'coveredFromMessageIndex', 'coveredToMessageIndex'])]
// The cover-read hot path: equality columns FIRST (aiConversationId, coveredFromMessageIndex), then the
// point-in-time range column (created) — so the per-position lookup uses the index, not a filesort.
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['aiConversationId', 'coveredFromMessageIndex', 'created'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBConversationCompactionNode::class)]
class AIConversationCompactionNode extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    // NO `= null`/value defaults on persisted columns — a default makes the property always-initialized, so
    // the upsert (DoctrineEntityManager::upsert skips UNINITIALIZED props) would always write it, defeating targeted
    // single-column persists AND turning a forgotten-set on a NOT-NULL column into a NULL-violation instead of a clear
    // uninitialized-property error. The factories/service set every column explicitly before persist.

    /** @var int|null Id of the conversation this node belongs to. */
    #[NotNull]
    public ?int $aiConversationId;

    /** @var int|null 0 = summary over RAW messages (a leaf-tier segment); 1+ = roll-up over K nodes of the level below. The resolution dimension: higher = coarser/older. */
    #[NotNull]
    public ?int $level;

    /** @var int|null Inclusive lower bound of the AIConversationMessage `indexInConversation` range this node ultimately covers. */
    #[NotNull]
    public ?int $coveredFromMessageIndex;

    /** @var int|null Inclusive upper bound of the covered `indexInConversation` range. The node's range is the union of its children's ranges (contiguous, no holes). */
    #[NotNull]
    public ?int $coveredToMessageIndex;

    /**
     * @var string|null The gist of the covered range. Structured slots (IDs / decisions / status / next-steps). No
     * index: the text is read with its row, never searched.
     */
    #[NotNull]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $summaryText;

    /** @var AIConversationCompactionPreservedFacts|null Verbatim identifiers + standing constraints — preserved across the tree, never summarized. One JSON column (VO→JSON convention; a bare array is unsupported by the model generator). */
    public ?AIConversationCompactionPreservedFacts $preservedFacts;

    /**
     * @var TextEmbedding|null The semantic embedding of {@see $summaryText} (`text-embedding-3-small`@1536) — the
     * per-node key for the RAPTOR collapsed-tree "search within compactions". Generated ONCE at node
     * creation by the compaction service,
     * before persist — a node is immutable append-only; only the zero-vector repair re-embeds a node whose embed failed
     * (unlike the async-embedded message leaf). Searched across ALL levels (L0/L1/L2) so a conceptual query hits a node at
     * its resolution. **NO `= null` default ON PURPOSE** (same EM trap as {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::$messageEmbedding}):
     * left uninitialized the EM skips the column when the best-effort embed failed, and the column's database default
     * applies: the ZERO vector (`vector(1536) NOT NULL` — there is no SQL NULL). Such a node is stored on purpose; the
     * node search filters `!= zero vector`, and the application's zero-embedding repair re-embeds it. Reads guard
     * with `isset()`. {@see HideProperty}: never serialize the 1536-float vector.
     * Owner applies the VECTOR(1536)+HNSW DDL on EntityAIConversationCompactionNodes.
     */
    #[DatabaseColumn(vectorDimensions: Vector::DIMENSION_OPENAI_EMBEDDING_SMALL)]
    #[HideProperty]
    public ?TextEmbedding $summaryEmbedding;

    /** @var bool|null true = built from RAW messages (a leaf-tier segment summary); false = rolled up from K child nodes. */
    #[NotNull]
    public ?bool $fromRawMessages;

    /** @var string|null The summarizer model that produced this node (stamped for provenance / cost). References AIModel::$name. */
    public ?string $aiModelName;

    /** @var int|null Output tokens of the summary. */
    public ?int $summaryTokens;

    /** @var int|null Count of RAW messages ultimately covered (for metrics / cost). */
    public ?int $coveredMessageCount;

    /** @var int|null Model-counted token footprint of the covered RAW content (a roll-up sums its children's; the savings this node buys). */
    public ?int $coveredTokens;

    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE, onUpdateAction: DatabaseForeignKey::ACTION_CASCADE)]
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AIConversation $aiConversation;

    /**
     * The level-0 leaf over `$leafRange` of `$conversation` (the node builds itself): the summarizer's
     * gist and its provenance, the preserved facts of the range, and the raw rows the leaf covers — their count and
     * their model-counted footprint ({@see AIConversationMessageFootprints::sumOverMessages()}: the savings the leaf
     * buys; the admin tree view and the leaf-size metric read it). The conversation's id is the node's (null on
     * a transient conversation); the `created` write-time key is stamped by the entity manager at the INSERT. Pure over
     * the conversation's loaded rows.
     */
    public static function buildLeafFor(
        AIConversation $conversation,
        AIConversationCompactionLeafRange $leafRange,
        AIConversationCompactionSummary $compactionSummary,
        AIConversationMessageFootprints $messageFootprints,
        AIConversationCompactionPreservedFacts $preservedFacts
    ): AIConversationCompactionNode {
        $leafNode = AIConversationCompactionNode::newInstance();
        $leafNode->aiConversationId = $conversation->id ?? null;
        $leafNode->level = 0;
        $leafNode->coveredFromMessageIndex = $leafRange->fromIndex;
        $leafNode->coveredToMessageIndex = $leafRange->toIndex;
        $leafNode->summaryText = $compactionSummary->summaryText;
        $leafNode->fromRawMessages = true;
        $leafNode->aiModelName = $compactionSummary->aiModelName;
        $leafNode->summaryTokens = $compactionSummary->outputTokens;
        $leafNode->preservedFacts = $preservedFacts;
        $rawMessagesInRange = $conversation->getRawMessagesInRange($leafRange->fromIndex, $leafRange->toIndex);
        $leafNode->coveredMessageCount = count($rawMessagesInRange);
        $leafNode->coveredTokens = $messageFootprints->sumOverMessages($rawMessagesInRange);
        return $leafNode;
    }

    /**
     * The node one level above `$rollUpGroup` that consolidates its children (the node builds itself):
     * the summarizer's gist over the children's union range, the children's preserved facts merged verbatim, their
     * covered message counts and raw-token costs summed. The level and the range read the first and the last child only
     * ({@see AIConversationCompactionRollUpGroup}); the conversation id is the children's
     * ({@see AIConversationCompactionRollUpGroup::getAIConversationId()}). Pure.
     */
    public static function buildRollUpOf(AIConversationCompactionRollUpGroup $rollUpGroup, AIConversationCompactionSummary $compactionSummary): AIConversationCompactionNode
    {
        $firstChild = $rollUpGroup->getChildNodes()[0];
        $rollUpNode = AIConversationCompactionNode::newInstance();
        $rollUpNode->aiConversationId = $rollUpGroup->getAIConversationId();
        $rollUpNode->level = ($firstChild->level ?? 0) + 1;
        $rollUpNode->coveredFromMessageIndex = $rollUpGroup->getCoveredFromMessageIndex();
        $rollUpNode->coveredToMessageIndex = $rollUpGroup->getCoveredToMessageIndex();
        $rollUpNode->summaryText = $compactionSummary->summaryText;
        $rollUpNode->fromRawMessages = false;
        $rollUpNode->aiModelName = $compactionSummary->aiModelName;
        $rollUpNode->summaryTokens = $compactionSummary->outputTokens;
        $rollUpNode->preservedFacts = AIConversationCompactionPreservedFacts::mergeOf($rollUpGroup->getChildNodes());
        $rollUpNode->coveredMessageCount = $rollUpGroup->sumCoveredMessageCount();
        $rollUpNode->coveredTokens = $rollUpGroup->sumCoveredTokens();
        return $rollUpNode;
    }

    /** True if $messageIndex falls within this node's covered range (the in-memory cover primitive; the DB path uses the index). */
    public function coversMessageIndex(int $messageIndex): bool
    {
        return $this->coveredFromMessageIndex !== null
            && $this->coveredToMessageIndex !== null
            && $messageIndex >= $this->coveredFromMessageIndex
            && $messageIndex <= $this->coveredToMessageIndex;
    }

    /**
     * @return string `level:from:to` — the level and the covered message range as one text; two nodes with the same
     *      text sit on the same level and cover the same messages (a missing value is an empty part)
     */
    public function buildLevelAndRangeKey(): string
    {
        return ($this->level ?? '') . ':' . ($this->coveredFromMessageIndex ?? '') . ':' . ($this->coveredToMessageIndex ?? '');
    }
}
