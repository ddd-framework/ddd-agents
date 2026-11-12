<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversation;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The partition of the conversation reads (DQL only — no database). A conversation without a space belongs to ONE
 * account: a read without a space selects the account-level conversations (`agentSpaceId IS NULL`) and filters the
 * account in the same statement — never the conversations of all spaces of the account, never the account-level
 * conversations of every account.
 */
class DBAIConversationsAgentSpacePartitionTest extends UnitTestCase
{
    public function testTheConversationsOfASpaceAreFilteredByTheSpace(): void
    {
        $queryBuilder = DBAIConversations::createQueryBuilder(true);
        $baseModelAlias = DBAIConversations::getBaseModelAlias();

        DBAIConversations::restrictToConversationsOfAgentSpace($queryBuilder, $this->buildStoredAgentSpace(7));

        self::assertStringContainsString("$baseModelAlias.agentSpaceId = :agentSpaceId", $queryBuilder->getDQL());
        self::assertSame(7, $queryBuilder->getParameter('agentSpaceId')->getValue());
    }

    public function testAQueryWithTwoSpaceConditionsNamesItsParameter(): void
    {
        $queryBuilder = DBAIConversations::createQueryBuilder(true);
        $baseModelAlias = DBAIConversations::getBaseModelAlias();

        DBAIConversations::restrictToConversationsOfAgentSpace($queryBuilder, $this->buildStoredAgentSpace(7), 'scheduleRunsAgentSpaceId');

        self::assertStringContainsString("$baseModelAlias.agentSpaceId = :scheduleRunsAgentSpaceId", $queryBuilder->getDQL());
        self::assertSame(7, $queryBuilder->getParameter('scheduleRunsAgentSpaceId')->getValue());
    }

    public function testASpaceThatIsNotStoredHasNoConversationsAndIsNeverReadAsNoSpace(): void
    {
        $queryBuilder = DBAIConversations::createQueryBuilder(true);
        $baseModelAlias = DBAIConversations::getBaseModelAlias();

        DBAIConversations::restrictToConversationsOfAgentSpace($queryBuilder, new AgentSpace());

        self::assertStringContainsString("$baseModelAlias.agentSpaceId = :agentSpaceId", $queryBuilder->getDQL());
        self::assertSame(AgentSpaceSqlCondition::AGENT_SPACE_ID_THAT_MATCHES_NO_ROW, $queryBuilder->getParameter('agentSpaceId')->getValue());
    }

    public function testWithoutASpaceTheReadSelectsTheAccountLevelConversationsOfTheOneAccount(): void
    {
        $queryBuilder = DBAIConversations::createQueryBuilder(true);
        $baseModelAlias = DBAIConversations::getBaseModelAlias();

        DBAIConversations::restrictToConversationsOfAccountInAgentSpacePartition($queryBuilder, 12, AgentSpaceSqlCondition::forAgentSpace(null), 'ownAccountId');

        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString("$baseModelAlias.agentSpaceId IS NULL", $dql, 'no space = the account-level conversations, not those of all spaces');
        self::assertStringContainsString("$baseModelAlias.accountId = :ownAccountId", $dql, 'the account is filtered in the same statement');
        self::assertSame(12, $queryBuilder->getParameter('ownAccountId')->getValue());
        self::assertNull($queryBuilder->getParameter('agentSpaceId'), 'no value is bound for a missing space');
    }

    public function testWithASpaceTheReadSelectsTheConversationsOfTheAccountInThatSpace(): void
    {
        $queryBuilder = DBAIConversations::createQueryBuilder(true);
        $baseModelAlias = DBAIConversations::getBaseModelAlias();

        DBAIConversations::restrictToConversationsOfAccountInAgentSpacePartition($queryBuilder, 12, AgentSpaceSqlCondition::forAgentSpaceId(7, 'ownAgentSpaceId'), 'ownAccountId');

        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString("$baseModelAlias.agentSpaceId = :ownAgentSpaceId", $dql);
        self::assertStringContainsString("$baseModelAlias.accountId = :ownAccountId", $dql);
        self::assertStringNotContainsString('IS NULL', $dql);
        self::assertSame(7, $queryBuilder->getParameter('ownAgentSpaceId')->getValue());
        self::assertSame(12, $queryBuilder->getParameter('ownAccountId')->getValue());
    }

    public function testTheConditionThatAlsoMatchesRowsWithoutASpaceIsRefusedForConversations(): void
    {
        $queryBuilder = DBAIConversations::createQueryBuilder(true);

        $this->expectException(InternalErrorException::class);
        DBAIConversations::restrictToConversationsOfAccountInAgentSpacePartition($queryBuilder, 12, AgentSpaceSqlCondition::forAgentSpaceIdOrAccountWide(7));
    }

    public function testTheRestrictionsAlsoApplyToABuilderOfTheEntityRepository(): void
    {
        self::assertSame(DBAIConversation::getBaseModelAlias(), DBAIConversations::getBaseModelAlias());
        $queryBuilder = DBAIConversation::createQueryBuilder(true);
        $baseModelAlias = DBAIConversation::getBaseModelAlias();

        DBAIConversations::restrictToConversationsOfAgentSpace($queryBuilder, $this->buildStoredAgentSpace(7));

        self::assertStringContainsString("$baseModelAlias.agentSpaceId = :agentSpaceId", $queryBuilder->getDQL());
    }

    protected function buildStoredAgentSpace(int $id): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $id;
        return $agentSpace;
    }
}
