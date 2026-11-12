<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DateTimeImmutable;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The query the two summary reads share (DQL only — no database): it selects the root conversations with a summary
 * that were active since a moment and carries NO condition on the account or the space. Each read adds the one it is
 * named after: the read of one space (or of the account level) the account and the partition, the read across the
 * spaces of an account the account alone.
 */
class AIConversationsServiceSummaryReadsQueryTest extends UnitTestCase
{
    public function testTheSharedQueryCarriesNoConditionOnTheAccountOrTheSpace(): void
    {
        $baseModelAlias = DBAIConversations::getBaseModelAlias();

        $dql = $this->buildSharedQueryBuilder(12, null, [])->getDQL();

        self::assertStringContainsString("$baseModelAlias.delegatedFromMessageId IS NULL", $dql, 'root conversations only');
        self::assertStringContainsString("$baseModelAlias.summary IS NOT NULL", $dql);
        self::assertStringContainsString("$baseModelAlias.lastInboundMessageAt >= :sessionsSince", $dql);
        self::assertStringNotContainsString("$baseModelAlias.accountId", $dql, 'the caller adds the account');
        self::assertStringNotContainsString("$baseModelAlias.agentSpaceId", $dql, 'the caller adds the space, or none for a read across spaces');
    }

    public function testTheLimitStaysBetweenOneAndTwentyFive(): void
    {
        self::assertSame(1, $this->buildSharedQueryBuilder(0, null, [])->getMaxResults());
        self::assertSame(12, $this->buildSharedQueryBuilder(12, null, [])->getMaxResults());
        self::assertSame(25, $this->buildSharedQueryBuilder(400, null, [])->getMaxResults());
    }

    public function testTheConversationTheQuestionIsAskedInAndTheNamedPurposesAreLeftOut(): void
    {
        $baseModelAlias = DBAIConversations::getBaseModelAlias();

        $queryBuilder = $this->buildSharedQueryBuilder(12, 31, ['First.Purpose', 'Second.Purpose']);

        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString("$baseModelAlias.id != :sessionsExcludeId", $dql);
        self::assertSame(31, $queryBuilder->getParameter('sessionsExcludeId')->getValue());
        self::assertStringContainsString("$baseModelAlias.purposeName NOT IN (:sessionsExcludedPurposeNames)", $dql);
        self::assertSame(['First.Purpose', 'Second.Purpose'], $queryBuilder->getParameter('sessionsExcludedPurposeNames')->getValue());
    }

    public function testWithoutAnExcludedConversationOrPurposeNoSuchConditionIsAdded(): void
    {
        $queryBuilder = $this->buildSharedQueryBuilder(12, null, []);

        self::assertStringNotContainsString('sessionsExcludeId', $queryBuilder->getDQL());
        self::assertStringNotContainsString('sessionsExcludedPurposeNames', $queryBuilder->getDQL());
    }

    public function testTheReadAcrossTheSpacesOfAnAccountRefusesAnAccountThatIsNotStored(): void
    {
        $this->expectException(InternalErrorException::class);
        (new AIConversationsService())->findConversationsWithSummariesOfAccountAcrossAgentSpacesSince(new Account(), new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    /**
     * @param int $limit
     * @param int|null $excludeConversationId
     * @param string[] $excludedPurposeNames
     * @return DoctrineQueryBuilder
     */
    protected function buildSharedQueryBuilder(int $limit, ?int $excludeConversationId, array $excludedPurposeNames): DoctrineQueryBuilder
    {
        return self::invokeMethod(
            new AIConversationsService(),
            'buildConversationsWithSummariesSinceQueryBuilder',
            [new DateTimeImmutable('2026-01-01 00:00:00'), $limit, $excludeConversationId, $excludedPurposeNames]
        );
    }
}
