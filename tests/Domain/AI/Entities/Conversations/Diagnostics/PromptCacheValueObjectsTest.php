<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\PromptCacheMessageDifference;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\PromptCacheRequestRegions;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The prompt-cache diff reads a request's regions and its first message difference as value objects. */
class PromptCacheValueObjectsTest extends UnitTestCase
{
    public function testAMessageDifferenceKeepsTheReportFormItHadAsAnArray(): void
    {
        $promptCacheMessageDifference = PromptCacheMessageDifference::forPosition(3, 'user', PromptCacheMessageDifference::KIND_CHANGE, 'hello');

        self::assertSame(['position' => 3, 'role' => 'user', 'kind' => 'change', 'head' => 'hello'], $promptCacheMessageDifference->toReportArray());
        self::assertFalse($promptCacheMessageDifference->isAppend());
        self::assertTrue(PromptCacheMessageDifference::forPosition(0, 'assistant', PromptCacheMessageDifference::KIND_APPEND, '')->isAppend());
    }

    public function testTheRegionsKeepTheirMessagesAsAList(): void
    {
        $promptCacheRequestRegions = PromptCacheRequestRegions::forToolsSystemAndMessages('[]', 'system text', [3 => ['role' => 'user', 'content' => 'hi']]);

        self::assertSame('[]', $promptCacheRequestRegions->tools);
        self::assertSame('system text', $promptCacheRequestRegions->system);
        self::assertSame([['role' => 'user', 'content' => 'hi']], $promptCacheRequestRegions->messages);
    }
}
