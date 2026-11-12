<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use Doctrine\DBAL\Connection;

/**
 * One claim-fenced message write (replaces the callback of `persistUnderRunClaim()`):
 * opened by {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::openClaimFencedWrite()} and ended
 * by exactly one of {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::commitClaimFencedWrite()}
 * or {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::rollBackClaimFencedWrite()}. It carries
 * what its end needs (whether it is the outermost write, its message-index lock, the connection its transaction runs on).
 *
 * The process state every open write shares lives here too: how many writes are open (a nested write joins the outer
 * one's lock and transaction), the publications that wait for the outermost commit, the undos that run when the
 * outermost write rolls back, and the message sets whose restore the open write has queued. A write of an unpersisted
 * conversation is UNFENCED: no lock, no transaction, its end does nothing.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationMessageWriteScope
{
    /** How many claim-fenced writes of this process are open — the outermost one commits and runs the publications. */
    protected static int $openClaimFencedWriteDepth = 0;

    /** @var AIConversationMessageWritePublication[] publications of rows written inside an open write, run after its outermost commit */
    protected static array $publicationsAfterOutermostCommit = [];

    /** @var AIConversationMessageWriteUndo[] in-memory undos of an open write, run newest first when its outermost write rolls back */
    protected static array $undosOnRollback = [];

    /** @var array<int, true> the message sets (by object id) whose write-state restore the open write has queued */
    protected static array $messageSetsWithRestoreQueued = [];

    /** Whether this write runs under the lock and in a transaction (false: an unpersisted conversation). */
    public bool $isFenced = false;

    /** Whether no other write of this process was open when this one opened. */
    public bool $isOutermostWrite = false;

    /** The message-index lock hold of this write (a hold that took nothing when an outer write holds the lock). */
    public ?AIConversationMessageIndexLock $messageIndexLock = null;

    /** The connection this write's transaction runs on. */
    public ?Connection $connection = null;

    /** Whether this write has been counted out of the open writes. */
    protected bool $isEnded = false;

    /** A write that fences nothing (an unpersisted conversation): its commit and its rollback do nothing. */
    public static function unfenced(): static
    {
        return new static();
    }

    /** A write whose transaction has begun under `$messageIndexLock` — counted as open from now on. */
    public static function fenced(bool $isOutermostWrite, AIConversationMessageIndexLock $messageIndexLock, Connection $connection): static
    {
        $writeScope = new static();
        $writeScope->isFenced = true;
        $writeScope->isOutermostWrite = $isOutermostWrite;
        $writeScope->messageIndexLock = $messageIndexLock;
        $writeScope->connection = $connection;
        self::$openClaimFencedWriteDepth++;
        return $writeScope;
    }

    /** Count this write out of the open writes (once, whichever end comes first). */
    public function markEnded(): void
    {
        if (!$this->isFenced || $this->isEnded) {
            return;
        }
        $this->isEnded = true;
        self::$openClaimFencedWriteDepth--;
    }

    /** Whether this write has been committed or rolled back already. */
    public function isEnded(): bool
    {
        return $this->isEnded;
    }

    /** Whether a claim-fenced write of this process is open right now. */
    public static function isAnyWriteOpen(): bool
    {
        return self::$openClaimFencedWriteDepth > 0;
    }

    /** Queue an undo of the open write; outside a write there is nothing to roll back — dropped. */
    public static function queueUndoOfOpenWrite(AIConversationMessageWriteUndo $undo): void
    {
        if (self::$openClaimFencedWriteDepth > 0) {
            self::$undosOnRollback[] = $undo;
        }
    }

    /**
     * Queue the restore of a loaded message set once per open write (a nested write joins it) — queued before the depth
     * rises, so the first entry runs last, after every finer undo of the write.
     */
    public static function queueMessageSetRestore(AIConversationMessages $messages): void
    {
        $messageSetKey = spl_object_id($messages);
        if (isset(self::$messageSetsWithRestoreQueued[$messageSetKey])) {
            return;
        }
        self::$messageSetsWithRestoreQueued[$messageSetKey] = true;
        self::$undosOnRollback[] = AIConversationMessagesWriteStateUndo::ofMessages($messages);
    }

    /** Queue a publication for the outermost commit; false when no write is open (the caller publishes right away). */
    public static function queuePublicationOfOpenWrite(AIConversationMessageWritePublication $publication): bool
    {
        if (self::$openClaimFencedWriteDepth === 0) {
            return false;
        }
        self::$publicationsAfterOutermostCommit[] = $publication;
        return true;
    }

    /** Drop the undos and the queued message-set restores (the outermost write starts or committed). */
    public static function forgetUndos(): void
    {
        self::$undosOnRollback = [];
        self::$messageSetsWithRestoreQueued = [];
    }

    /** Drop the queued publications (the outermost write rolled back: nothing it wrote is visible). */
    public static function forgetPublications(): void
    {
        self::$publicationsAfterOutermostCommit = [];
    }

    /**
     * The queued undos, newest first, taken out of the queue (with the queued message-set restores).
     *
     * @return AIConversationMessageWriteUndo[]
     */
    public static function takeUndosNewestFirst(): array
    {
        $undosNewestFirst = array_reverse(self::$undosOnRollback);
        self::forgetUndos();
        return $undosNewestFirst;
    }

    /**
     * The publications queued for the outermost commit, taken out of the queue.
     *
     * @return AIConversationMessageWritePublication[]
     */
    public static function takePublications(): array
    {
        $publications = self::$publicationsAfterOutermostCommit;
        self::$publicationsAfterOutermostCommit = [];
        return $publications;
    }

    /** A new worker job starts without anything a previous job's write left behind (open count, publications, undos). */
    public static function forgetOpenWritesOfThisProcess(): void
    {
        self::$openClaimFencedWriteDepth = 0;
        self::$publicationsAfterOutermostCommit = [];
        self::forgetUndos();
    }
}
