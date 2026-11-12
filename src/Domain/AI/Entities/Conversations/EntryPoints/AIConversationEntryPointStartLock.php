<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\EntryPoints;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One start's hold of the entry-point start lock: a MySQL named lock per (account, agent space, entry-point key),
 * taken by {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::lockEntryPointStart()} before the
 * look-up of the recent conversation and given back by
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::unlockEntryPointStart()} in the starter's `finally`
 * — after the new conversation is stored with its key. Two starts with the same key at the same moment run one after
 * the other: the second finds the conversation the first created.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEntryPointStartLock extends ValueObject
{
    /** @var string The prefix of every entry-point start lock name. */
    public const string LOCK_NAME_PREFIX = 'aiconv-entry-point-';

    /** The named lock (`GET_LOCK`) this start asked for. */
    public string $lockName;

    /** Whether this start holds the lock and has not given it back yet. */
    protected bool $isReleaseOwed = false;

    /**
     * The lock name of a (account, space, key) triple — hashed: MariaDB caps a lock name at 64 characters and the
     * key alone may be 255. A start without a space (account level) locks under 0, which no space has as its id.
     */
    public static function buildLockName(int $accountId, ?int $agentSpaceId, string $entryPointKey): string
    {
        return self::LOCK_NAME_PREFIX . md5($accountId . '|' . ($agentSpaceId ?? 0) . '|' . $entryPointKey);
    }

    /** The hold of a lock this start has just taken. */
    public static function taken(string $lockName): static
    {
        $aIConversationEntryPointStartLock = new static();
        $aIConversationEntryPointStartLock->lockName = $lockName;
        $aIConversationEntryPointStartLock->isReleaseOwed = true;
        return $aIConversationEntryPointStartLock;
    }

    /** A lock this start did not get within the wait: it runs unserialised and has nothing to give back. */
    public static function notTaken(string $lockName): static
    {
        $aIConversationEntryPointStartLock = new static();
        $aIConversationEntryPointStartLock->lockName = $lockName;
        return $aIConversationEntryPointStartLock;
    }

    /** Whether this start still has to give its lock back. */
    public function isReleaseOwed(): bool
    {
        return $this->isReleaseOwed;
    }

    /** The lock was given back on the server. */
    public function markReleased(): void
    {
        $this->isReleaseOwed = false;
    }
}
