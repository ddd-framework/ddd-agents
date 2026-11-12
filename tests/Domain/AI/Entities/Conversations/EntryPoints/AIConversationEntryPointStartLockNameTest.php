<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\EntryPoints;

use DDD\Domain\AI\Entities\Conversations\EntryPoints\AIConversationEntryPointStartLock;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The name of the lock that makes "start or reopen at an entry point" one at a time: one name per account, space and
 * key — a start at account level never shares its lock with a start for a space of the same account.
 */
class AIConversationEntryPointStartLockNameTest extends UnitTestCase
{
    public function testTheSameAccountSpaceAndKeyGiveTheSameName(): void
    {
        self::assertSame(
            AIConversationEntryPointStartLock::buildLockName(12, 7, 'dashboard'),
            AIConversationEntryPointStartLock::buildLockName(12, 7, 'dashboard')
        );
    }

    public function testAnotherAccountSpaceOrKeyGivesAnotherName(): void
    {
        $lockName = AIConversationEntryPointStartLock::buildLockName(12, 7, 'dashboard');

        self::assertNotSame($lockName, AIConversationEntryPointStartLock::buildLockName(13, 7, 'dashboard'));
        self::assertNotSame($lockName, AIConversationEntryPointStartLock::buildLockName(12, 8, 'dashboard'));
        self::assertNotSame($lockName, AIConversationEntryPointStartLock::buildLockName(12, 7, 'reviews'));
    }

    public function testAStartAtAccountLevelHasItsOwnName(): void
    {
        $lockNameAtAccountLevel = AIConversationEntryPointStartLock::buildLockName(12, null, 'dashboard');

        self::assertSame($lockNameAtAccountLevel, AIConversationEntryPointStartLock::buildLockName(12, null, 'dashboard'));
        self::assertNotSame($lockNameAtAccountLevel, AIConversationEntryPointStartLock::buildLockName(12, 7, 'dashboard'));
        self::assertNotSame($lockNameAtAccountLevel, AIConversationEntryPointStartLock::buildLockName(13, null, 'dashboard'));
    }

    public function testTheNameFitsTheLockNameLimitForALongKey(): void
    {
        self::assertLessThanOrEqual(64, strlen(AIConversationEntryPointStartLock::buildLockName(PHP_INT_MAX, PHP_INT_MAX, str_repeat('k', 255))));
    }
}
