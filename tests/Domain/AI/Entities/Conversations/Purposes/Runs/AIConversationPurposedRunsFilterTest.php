<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\Runs;

use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRunsFilter;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The space of a population read's filter: a named space is kept and echoed in the scope line, no id names no space. */
class AIConversationPurposedRunsFilterTest extends UnitTestCase
{
    protected function filterOfAgentSpaceId(?int $agentSpaceId): AIConversationPurposedRunsFilter
    {
        return AIConversationPurposedRunsFilter::fromRequest(
            purposeNames: [],
            createdFrom: null,
            createdTo: null,
            windowDays: 7,
            accountId: 91,
            agentSpaceId: $agentSpaceId,
            now: new DateTime('2026-11-01 12:00:00')
        );
    }

    public function testANamedSpaceIsKeptAndEchoedInTheScopeLine(): void
    {
        $filter = $this->filterOfAgentSpaceId(7);
        self::assertSame(7, $filter->agentSpaceId);
        self::assertStringContainsString('account 91; space 7;', $filter->describeScope());
    }

    public function testWithoutASpaceIdTheFilterNamesNoSpace(): void
    {
        $filter = $this->filterOfAgentSpaceId(null);
        self::assertNull($filter->agentSpaceId);
        self::assertStringContainsString('account 91; eval runs left out', $filter->describeScope());
        self::assertStringNotContainsString('space', $filter->describeScope());
    }
}
