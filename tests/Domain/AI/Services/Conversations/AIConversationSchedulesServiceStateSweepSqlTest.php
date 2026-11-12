<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The candidate scan of the state sweep names no table of an application: the package selects the schedules the
 * platform paused, an application's filter adds its join and its condition
 * ({@see AIConversationSchedulesService::buildAutomationStateSweepSqlFilter()}).
 */
class AIConversationSchedulesServiceStateSweepSqlTest extends UnitTestCase
{
    public function testThePackageFilterIsEmptyAndTheStatementReadsTheScheduleTableOnly(): void
    {
        $schedulesService = (new ReflectionClass(AIConversationSchedulesService::class))->newInstanceWithoutConstructor();
        $filter = (new ReflectionMethod(AIConversationSchedulesService::class, 'buildAutomationStateSweepSqlFilter'))->invoke($schedulesService);

        self::assertSame('', $filter->joinSql);
        self::assertSame('', $filter->conditionSql);
        self::assertSame([], $filter->parameters);
        self::assertSame(
            'SELECT `schedule`.`id` FROM `' . DBAIConversationScheduleModel::getTableName() . '` `schedule`'
            . ' WHERE `schedule`.`status` IN (:sweptStatuses) AND (   `schedule`.`stateReason` IS NOT NULL ) ORDER BY `schedule`.`id` ASC LIMIT 25',
            $this->buildSql($filter, 25)
        );
    }

    public function testAnApplicationFilterIsJoinedAndConnectedWithOr(): void
    {
        $sql = $this->buildSql(
            AgentApplicationSqlFilter::of('LEFT JOIN `places` `place` ON `place`.`agentSpaceId` = `schedule`.`agentSpaceId`', '`place`.`state` <> :open', ['open' => 'open']),
            0
        );

        self::assertStringContainsString(' `schedule` LEFT JOIN `places` `place` ON `place`.`agentSpaceId` = `schedule`.`agentSpaceId` WHERE ', $sql);
        self::assertStringContainsString('`schedule`.`stateReason` IS NOT NULL   OR `place`.`state` <> :open )', $sql);
        self::assertStringEndsWith('LIMIT 1', $sql);
    }

    protected function buildSql(AgentApplicationSqlFilter $agentApplicationSqlFilter, int $limit): string
    {
        $schedulesService = (new ReflectionClass(AIConversationSchedulesService::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod(AIConversationSchedulesService::class, 'buildAutomationStateSweepSql'))->invoke($schedulesService, $agentApplicationSqlFilter, $limit);
    }
}
