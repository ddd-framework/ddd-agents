<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Statistics;

use DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversation;

/**
 * A conversation repo of an application that leaves the rows of a marked table of its own out of the customer
 * figures. The test database carries no application table, so the agent space table stands in for it: a space in
 * the time zone {@see self::MARKED_TIMEZONE} is the marked row.
 */
class ConversationRepoThatLeavesOutMarkedAgentSpaces extends DBAIConversation
{
    public const string MARKED_TIMEZONE = 'Etc/GMT+12';

    protected function buildTestAccountsExclusionSqlFilter(): AgentApplicationSqlFilter
    {
        return AgentApplicationSqlFilter::of(
            'LEFT JOIN `' . DBAgentSpaceModel::getTableName() . '` `agentSpace` ON `agentSpace`.`id` = `conversation`.`agentSpaceId`',
            "(`agentSpace`.`id` IS NULL OR `agentSpace`.`timezone` IS NULL OR `agentSpace`.`timezone` <> '" . self::MARKED_TIMEZONE . "')"
        );
    }
}
