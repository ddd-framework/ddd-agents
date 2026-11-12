<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Conversations;

use DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversation;

/**
 * A conversation repo of an application that marks test accounts in a table of its own.
 */
class ApplicationTablesConversationRepoStub extends DBAIConversation
{
    protected function buildTestAccountsExclusionSqlFilter(): AgentApplicationSqlFilter
    {
        return AgentApplicationSqlFilter::of(
            'LEFT JOIN members member ON member.id = conversation.accountId',
            '(member.id IS NULL OR member.isTestMember = 0)'
        );
    }
}
