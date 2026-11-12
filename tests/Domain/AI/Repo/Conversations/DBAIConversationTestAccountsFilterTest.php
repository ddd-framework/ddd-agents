<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Conversations;

use DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversation;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The statistics statements of the conversation repo carry no table of an application: the test account filter is
 * the empty one in the package, and an application's subclass supplies its join and condition
 * ({@see DBAIConversation::buildTestAccountsExclusionSqlFilter()}).
 */
class DBAIConversationTestAccountsFilterTest extends UnitTestCase
{
    public function testThePackageStatementsJoinNoTableWhenTestAccountsAreExcluded(): void
    {
        foreach ($this->buildStatements(DBAIConversation::class, excludeTestUsers: true) as $builderName => $sql) {
            self::assertStringNotContainsString('is_testuser', $sql, $builderName);
            self::assertStringNotContainsString(' u.', $sql, $builderName);
            self::assertSame(
                $this->buildStatements(DBAIConversation::class, excludeTestUsers: false)[$builderName],
                $sql,
                $builderName . ': without an application filter the switch changes nothing'
            );
        }
    }

    public function testAnApplicationSubclassAddsItsJoinAndCondition(): void
    {
        foreach ($this->buildStatements(ApplicationTablesConversationRepoStub::class, excludeTestUsers: true) as $builderName => $sql) {
            self::assertStringContainsString('LEFT JOIN members member ON member.id = conversation.accountId', $sql, $builderName);
            self::assertStringContainsString('AND (member.id IS NULL OR member.isTestMember = 0)', $sql, $builderName);
        }
        foreach ($this->buildStatements(ApplicationTablesConversationRepoStub::class, excludeTestUsers: false) as $builderName => $sql) {
            self::assertStringNotContainsString('members', $sql, $builderName);
        }
    }

    public function testTheFilterConnectsOnlyAPresentCondition(): void
    {
        self::assertSame('', AgentApplicationSqlFilter::none()->getConditionSqlConnectedByAnd());
        self::assertSame('', AgentApplicationSqlFilter::none()->getConditionSqlConnectedByOr());
        self::assertSame([], AgentApplicationSqlFilter::none()->parameters);

        $agentApplicationSqlFilter = AgentApplicationSqlFilter::of('LEFT JOIN a ON a.id = b.aId', 'a.state = :state', ['state' => 'open']);
        self::assertSame('AND a.state = :state', $agentApplicationSqlFilter->getConditionSqlConnectedByAnd());
        self::assertSame('OR a.state = :state', $agentApplicationSqlFilter->getConditionSqlConnectedByOr());
        self::assertSame(['state' => 'open'], $agentApplicationSqlFilter->parameters);
    }

    /**
     * @param class-string<DBAIConversation> $repoClassName
     * @return string[] The three statistics statements by builder name
     */
    protected function buildStatements(string $repoClassName, bool $excludeTestUsers): array
    {
        $repo = (new ReflectionClass($repoClassName))->newInstanceWithoutConstructor();
        return [
            'buildConversationCountsPerBucketSql' => (new ReflectionMethod($repoClassName, 'buildConversationCountsPerBucketSql'))
                ->invoke($repo, false, true, $excludeTestUsers),
            'buildEarliestConversationDateSql' => (new ReflectionMethod($repoClassName, 'buildEarliestConversationDateSql'))
                ->invoke($repo, true, $excludeTestUsers),
            'buildActiveAccountCountsPerBucketSql' => (new ReflectionMethod($repoClassName, 'buildActiveAccountCountsPerBucketSql'))
                ->invoke($repo, false, true, $excludeTestUsers),
        ];
    }
}
