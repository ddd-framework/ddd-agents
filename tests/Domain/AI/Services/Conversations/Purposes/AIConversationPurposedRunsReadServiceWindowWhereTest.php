<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Purposes;

use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRunsFilter;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRunsWindowSqlCondition;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposedRunsReadService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The window of a population read ({@see AIConversationPurposedRunsReadService}) and the space: a named space is one
 * condition on the conversation row; without a space id the window is NOT filtered by space — the runs of every space
 * and the runs at account level, never "only the rows without a space".
 */
class AIConversationPurposedRunsReadServiceWindowWhereTest extends UnitTestCase
{
    /**
     * @param int|null $agentSpaceId
     * @return AIConversationPurposedRunsWindowSqlCondition
     */
    protected function buildWindowSqlConditionOfAgentSpaceId(?int $agentSpaceId): AIConversationPurposedRunsWindowSqlCondition
    {
        $now = new DateTime('2026-11-01 12:00:00');
        $filter = AIConversationPurposedRunsFilter::fromRequest(
            purposeNames: [],
            createdFrom: null,
            createdTo: null,
            windowDays: 7,
            accountId: 91,
            agentSpaceId: $agentSpaceId,
            now: $now
        );
        $readService = self::instantiateWithoutConstructor(AIConversationPurposedRunsReadService::class);
        return self::invokeMethod($readService, 'buildWindowSqlCondition', [$filter, $now, false]);
    }

    public function testANamedSpaceIsOneConditionOnTheConversationRow(): void
    {
        $windowSqlCondition = $this->buildWindowSqlConditionOfAgentSpaceId(7);
        self::assertStringContainsString('`c`.`agentSpaceId` = :agentSpaceId', $windowSqlCondition->conditionSql);
        self::assertSame(7, $windowSqlCondition->parameters['agentSpaceId']);
        self::assertSame(91, $windowSqlCondition->parameters['accountId']);
    }

    public function testWithoutASpaceIdTheWindowIsNotFilteredBySpace(): void
    {
        $windowSqlCondition = $this->buildWindowSqlConditionOfAgentSpaceId(null);
        self::assertStringNotContainsString('agentSpaceId', $windowSqlCondition->conditionSql);
        self::assertArrayNotHasKey('agentSpaceId', $windowSqlCondition->parameters);
        self::assertStringContainsString('`c`.`accountId` = :accountId', $windowSqlCondition->conditionSql);
    }
}
