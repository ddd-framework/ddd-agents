<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;

/**
 * The schedules service as an application extends it: the state sweep gets a join and a condition of its own (here
 * over the package's space table: the schedules of the spaces of one account stand in for "a space that is no longer
 * active"), and the once-a-month budget notice is called directly.
 */
class SchedulesServiceWithStateSweepFilterAndBudgetNotice extends AIConversationSchedulesService
{
    /** @var int The account whose spaces the application's condition names */
    public const int SWEPT_ACCOUNT_ID = 11;

    /** @var bool true = the sweep carries the join and the condition below; false = the package's empty filter */
    public bool $usesStateSweepFilter = false;

    protected function buildAutomationStateSweepSqlFilter(): AgentApplicationSqlFilter
    {
        if (!$this->usesStateSweepFilter) {
            return parent::buildAutomationStateSweepSqlFilter();
        }
        return AgentApplicationSqlFilter::of(
            'LEFT JOIN `' . DBAgentSpaceModel::getTableName() . '` `space` ON `space`.`id` = `schedule`.`agentSpaceId`',
            '`space`.`accountId` = :sweptAccountId',
            ['sweptAccountId' => self::SWEPT_ACCOUNT_ID]
        );
    }

    public function notifyOwnerOncePerMonthThatScheduleBudgetPotIsExhaustedNow(AIConversationSchedule $schedule): void
    {
        $this->notifyOwnerOncePerMonthThatScheduleBudgetPotIsExhausted($schedule);
    }
}
