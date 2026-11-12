<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Tests\Agents\Support\IntegrationTestCase;
use DDD\Domain\Base\Entities\StaticRegistry;

/**
 * The base of the compaction characterization tests that replace a container service with a double (the
 * model catalog and the purpose registry; the node loaders and the message loaders). No database, no Argus.
 * `EntitySet::getService()` memoizes the resolved service per set class for the whole process, so the memo is emptied
 * before and after each test — otherwise `AIConversationCompactionNodes::getService()` would answer an earlier
 * test's real service instead of this test's double.
 */
abstract class CompactionContainerTestCase extends IntegrationTestCase
{
    use CompactionCharacterizationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        StaticRegistry::$entityServices = [];
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$entityServices = [];
    }
}
