<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\AIConversation;

/**
 * One writer's hold of a conversation's message-index lock (replaces the
 * callback of `withMessageIndexLock()`): taken by
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::lockMessageIndex()}, given back by
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::unlockMessageIndex()} in the writer's
 * `finally`. The named lock is held by the database session of the process, so the process remembers which names it
 * holds: a writer that asks for a lock its process already holds (the inner write of an open claim-fenced write) gets a
 * hold it did not take, and only the hold that took the lock releases it.
 *
 * On an engine that holds a named lock for one transaction only (PostgreSQL) a hold taken outside a transaction begins
 * the transaction its lock lives in; that hold owns the transaction and ends it when it is given back.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationMessageIndexLock
{
    /** @var array<string, true> the message-index locks this process's database session holds right now */
    protected static array $heldMessageIndexLocks = [];

    /** The named lock this hold took; null when it took none. */
    public ?string $lockName = null;

    /** Whether this hold took the lock and has not given it back yet. */
    protected bool $isReleaseOwed = false;

    /** Whether this hold began the transaction its lock lives in, and so ends it when the hold is given back. */
    protected bool $ownsTransaction = false;

    /** The name of a conversation's message-index lock. */
    public static function buildLockNameForConversation(AIConversation $conversation): string
    {
        return 'aiconv-msg-index-' . (int)$conversation->id;
    }

    /** Whether this process holds the named lock right now. */
    public static function isHeldInThisProcess(string $lockName): bool
    {
        return isset(self::$heldMessageIndexLocks[$lockName]);
    }

    /**
     * The hold of a lock this writer has just taken — the process holds the name from now on. `$ownsTransaction`: the
     * hold began the transaction the lock lives in.
     */
    public static function taken(string $lockName, bool $ownsTransaction = false): static
    {
        $messageIndexLock = new static();
        $messageIndexLock->lockName = $lockName;
        $messageIndexLock->isReleaseOwed = true;
        $messageIndexLock->ownsTransaction = $ownsTransaction;
        self::$heldMessageIndexLocks[$lockName] = true;
        return $messageIndexLock;
    }

    /** A hold that took no lock (an unpersisted conversation, a lock an outer writer of this process holds): nothing to release. */
    public static function notTaken(): static
    {
        return new static();
    }

    /** Whether this hold still has to give its lock back. */
    public function isReleaseOwed(): bool
    {
        return $this->isReleaseOwed;
    }

    /** Whether this hold began the transaction its lock lives in. */
    public function ownsTransaction(): bool
    {
        return $this->ownsTransaction;
    }

    /** The process no longer holds the lock this hold took (the caller releases it on the server). */
    public function markReleased(): void
    {
        if ($this->lockName !== null) {
            unset(self::$heldMessageIndexLocks[$this->lockName]);
        }
        $this->isReleaseOwed = false;
    }

    /**
     * Forget every lock this process holds — a new worker job starts without the locks a previous job left behind.
     *
     * @return string[] the names that were held (the caller releases them on the server)
     */
    public static function forgetLocksHeldInThisProcess(): array
    {
        $heldLockNames = array_keys(self::$heldMessageIndexLocks);
        self::$heldMessageIndexLocks = [];
        return $heldLockNames;
    }
}
