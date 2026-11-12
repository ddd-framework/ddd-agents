<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services;

use DDD\Domain\AI\Repo\DB\Conversations\A2A\DBAIConversationTaskPushNotificationConfig;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatch;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationToolCallBatch;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBConversationCompactionNode;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbedding;
use DDD\Domain\AI\Repo\DB\Conversations\TraceEvents\DBAIConversationTraceEvent;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEvent;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventSnapshot;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Infrastructure\Services\AuthService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The account read rights of the agent's tables (DQL only — no database): the default rule of
 * {@see AgentAccountRightsService} (nobody authenticated reads nothing, an admin reads everything without a join, any
 * other account reads its own rows) and the shape every core repo hands to it (which relation leads to the owning
 * conversation). The accounts here are framework accounts without an application hierarchy, so an application's
 * subclass (resolved by the repos through the container) answers like the default.
 */
class AgentAccountRightsServiceTest extends TestCase
{
    protected ?Account $accountBeforeTest = null;
    protected bool $rightsRestrictionsBeforeTest = true;

    protected function setUp(): void
    {
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        $this->rightsRestrictionsBeforeTest = DBEntity::getApplyRightsRestrictions();
        DBEntity::setApplyRightsRestrictions(true);
    }

    protected function tearDown(): void
    {
        static::authenticate($this->accountBeforeTest);
        DBEntity::setApplyRightsRestrictions($this->rightsRestrictionsBeforeTest);
    }

    public function testNobodyAuthenticatedReadsNoRow(): void
    {
        static::authenticate(null);
        $queryBuilder = DBAIConversationTraceEvent::createQueryBuilder(true);
        $baseModelAlias = DBAIConversationTraceEvent::getBaseModelAlias();
        self::assertTrue((new AgentAccountRightsService())->applyAccountReadRights($queryBuilder, $baseModelAlias, 'aiConversation'));
        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString("$baseModelAlias.id is null", $dql);
        self::assertStringNotContainsString('_rights', $dql, 'no join without an account');
    }

    public function testAnAdminReadsEveryRowWithoutAJoin(): void
    {
        static::authenticate(static::buildAccount(7, true));
        $queryBuilder = DBAIConversationTraceEvent::createQueryBuilder(true);
        $dqlBefore = $queryBuilder->getDQL();
        self::assertTrue((new AgentAccountRightsService())->applyAccountReadRights($queryBuilder, DBAIConversationTraceEvent::getBaseModelAlias(), 'aiConversation'));
        self::assertSame($dqlBefore, $queryBuilder->getDQL());
    }

    public function testAnAccountReadsTheRowsOfItsOwnConversations(): void
    {
        static::authenticate(static::buildAccount(7, false));
        $queryBuilder = DBAIConversationTraceEvent::createQueryBuilder(true);
        $baseModelAlias = DBAIConversationTraceEvent::getBaseModelAlias();
        self::assertTrue((new AgentAccountRightsService())->applyAccountReadRights($queryBuilder, $baseModelAlias, 'aiConversation'));
        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString("LEFT JOIN $baseModelAlias.aiConversation {$baseModelAlias}_aiConversation_rights", $dql);
        self::assertStringContainsString("{$baseModelAlias}_aiConversation_rights.accountId = :rights_user_id", $dql);
        self::assertSame(7, $queryBuilder->getParameter('rights_user_id')->getValue());
    }

    public function testAnAccountReadsTheRowsCarryingItsOwnAccountId(): void
    {
        static::authenticate(static::buildAccount(7, false));
        $queryBuilder = DBAIConversations::createQueryBuilder(true);
        $baseModelAlias = DBAIConversations::getBaseModelAlias();
        self::assertTrue((new AgentAccountRightsService())->applyAccountReadRights($queryBuilder, $baseModelAlias));
        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString("$baseModelAlias.accountId = :rights_user_id", $dql);
        self::assertStringNotContainsString('_rights ', $dql, 'no join when the row carries the account id');
    }

    public function testAdminOnlyRowsAreHiddenFromEveryoneElse(): void
    {
        $agentAccountRightsService = new AgentAccountRightsService();
        $baseModelAlias = DBAIConversationTurnEventSnapshot::getBaseModelAlias();
        foreach ([null, static::buildAccount(7, false)] as $account) {
            static::authenticate($account);
            $queryBuilder = DBAIConversationTurnEventSnapshot::createQueryBuilder(true);
            self::assertTrue($agentAccountRightsService->applyAdminOnlyReadRights($queryBuilder, $baseModelAlias));
            self::assertStringContainsString("$baseModelAlias.id is null", $queryBuilder->getDQL());
        }
        static::authenticate(static::buildAccount(7, true));
        $queryBuilder = DBAIConversationTurnEventSnapshot::createQueryBuilder(true);
        $dqlBefore = $queryBuilder->getDQL();
        self::assertTrue($agentAccountRightsService->applyAdminOnlyReadRights($queryBuilder, $baseModelAlias));
        self::assertSame($dqlBefore, $queryBuilder->getDQL());
    }

    /**
     * @param class-string<DBEntity> $repositoryClass
     * @param string $accountIdExpressionSuffix the expected account id expression after the base model alias
     */
    #[DataProvider('repositoriesOverTheirConversation')]
    public function testEveryRepoRestrictsByTheAccountOfItsConversation(string $repositoryClass, string $accountIdExpressionSuffix): void
    {
        static::authenticate(static::buildAccount(7, false));
        $queryBuilder = $repositoryClass::createQueryBuilder(true);
        self::assertTrue($repositoryClass::applyReadRightsQuery($queryBuilder));
        $baseModelAlias = $repositoryClass::getBaseModelAlias();
        self::assertStringContainsString("$baseModelAlias$accountIdExpressionSuffix = :rights_user_id", $queryBuilder->getDQL());
        self::assertSame(7, $queryBuilder->getParameter('rights_user_id')->getValue());
    }

    /** @return iterable<string, array{class-string<DBEntity>, string}> */
    public static function repositoriesOverTheirConversation(): iterable
    {
        yield 'push notification config' => [DBAIConversationTaskPushNotificationConfig::class, '_aiConversation_rights.accountId'];
        yield 'delegation batch' => [DBAIConversationDelegationBatch::class, '_parentConversation_rights.accountId'];
        yield 'tool call batch' => [DBAIConversationToolCallBatch::class, '_conversation_rights.accountId'];
        yield 'compaction node' => [DBConversationCompactionNode::class, '_aiConversation_rights.accountId'];
        yield 'message' => [DBAIConversationMessage::class, '_aiConversation_rights.accountId'];
        yield 'message embedding' => [DBAIConversationMessageEmbedding::class, '_aiConversationMessage_rights_aiConversation_rights.accountId'];
        yield 'trace event' => [DBAIConversationTraceEvent::class, '_aiConversation_rights.accountId'];
        yield 'turn event' => [DBAIConversationTurnEvent::class, '_aiConversation_rights.accountId'];
    }

    public function testTheEmbeddingDecidesUnauthenticatedAndAdminReadsOnItself(): void
    {
        $baseModelAlias = DBAIConversationMessageEmbedding::getBaseModelAlias();
        static::authenticate(null);
        $queryBuilder = DBAIConversationMessageEmbedding::createQueryBuilder(true);
        self::assertTrue(DBAIConversationMessageEmbedding::applyReadRightsQuery($queryBuilder));
        self::assertStringContainsString("$baseModelAlias.id is null", $queryBuilder->getDQL());
        self::assertStringNotContainsString('_rights', $queryBuilder->getDQL());
        static::authenticate(static::buildAccount(7, true));
        $queryBuilder = DBAIConversationMessageEmbedding::createQueryBuilder(true);
        $dqlBefore = $queryBuilder->getDQL();
        self::assertTrue(DBAIConversationMessageEmbedding::applyReadRightsQuery($queryBuilder));
        self::assertSame($dqlBefore, $queryBuilder->getDQL());
    }

    public function testTheMessageKeepsItsTruncatedFilterWithAndWithoutRights(): void
    {
        $baseModelAlias = DBAIConversationMessage::getBaseModelAlias();
        DBEntity::setApplyRightsRestrictions(false);
        $queryBuilder = DBAIConversationMessage::createQueryBuilder(true);
        self::assertTrue(DBAIConversationMessage::applyReadRightsQuery($queryBuilder));
        self::assertStringContainsString("$baseModelAlias.truncatedAt is null", $queryBuilder->getDQL());
        self::assertStringNotContainsString('_rights', $queryBuilder->getDQL());
        DBEntity::setApplyRightsRestrictions(true);
        static::authenticate(static::buildAccount(7, true));
        $queryBuilder = DBAIConversationMessage::createQueryBuilder(true);
        self::assertTrue(DBAIConversationMessage::applyReadRightsQuery($queryBuilder));
        self::assertStringContainsString("$baseModelAlias.truncatedAt is null", $queryBuilder->getDQL());
    }

    /** @param class-string<DBEntity> $repositoryClass */
    #[DataProvider('everyRightsRepositoryWithoutAlwaysOnFilter')]
    public function testEveryRepoAddsNothingWhileRightsAreLifted(string $repositoryClass): void
    {
        DBEntity::setApplyRightsRestrictions(false);
        static::authenticate(null);
        $queryBuilder = $repositoryClass::createQueryBuilder(true);
        $dqlBefore = $queryBuilder->getDQL();
        self::assertFalse($repositoryClass::applyReadRightsQuery($queryBuilder));
        self::assertSame($dqlBefore, $queryBuilder->getDQL());
    }

    /** @return iterable<string, array{class-string<DBEntity>}> */
    public static function everyRightsRepositoryWithoutAlwaysOnFilter(): iterable
    {
        foreach (static::repositoriesOverTheirConversation() as $repositoryName => [$repositoryClass]) {
            if ($repositoryClass === DBAIConversationMessage::class) {
                continue;
            }
            yield $repositoryName => [$repositoryClass];
        }
        yield 'turn event snapshot' => [DBAIConversationTurnEventSnapshot::class];
    }

    public function testOnlyProductionAdoptsUnstampedConversations(): void
    {
        self::assertStringContainsString('IS NULL', DBAIConversations::buildEnvironmentPredicate('c', 'prod'));
        self::assertStringNotContainsString('IS NULL', DBAIConversations::buildEnvironmentPredicate('c', 'workspace-a'));
    }

    /** Sets the authenticated account directly (an application's `setAccount()` may expect its own account class). */
    protected static function authenticate(?Account $account): void
    {
        (new ReflectionProperty(AuthService::class, 'account'))->setValue(null, $account);
    }

    /** A framework account with the LOGIN role, plus ADMIN when `$isAdmin`. */
    protected static function buildAccount(int $accountId, bool $isAdmin): Account
    {
        /** @var Account $account */
        $account = (new ReflectionClass(Account::class))->newInstanceWithoutConstructor();
        $account->id = $accountId;
        /** @var Roles $roles */
        $roles = (new ReflectionClass(Roles::class))->newInstanceWithoutConstructor();
        $roleElements = [];
        $roleElementsByUniqueKey = [];
        foreach ($isAdmin ? [Role::LOGIN, Role::ADMIN] : [Role::LOGIN] as $roleName) {
            /** @var Role $role */
            $role = (new ReflectionClass(Role::class))->newInstanceWithoutConstructor();
            $role->name = $roleName;
            $role->isAdminRole = $roleName !== Role::LOGIN;
            $roleElements[] = $role;
            $roleElementsByUniqueKey[$roleName] = $role;
        }
        static::setProperty($roles, 'elements', $roleElements);
        static::setProperty($roles, 'elementsByUniqueKey', $roleElementsByUniqueKey);
        static::setProperty($roles, 'elementCount', count($roleElements));
        $account->roles = $roles;
        return $account;
    }

    protected static function setProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflectionClass = new ReflectionClass($object);
        while (!$reflectionClass->hasProperty($propertyName)) {
            $reflectionClass = $reflectionClass->getParentClass();
        }
        $reflectionClass->getProperty($propertyName)->setValue($object, $value);
    }
}
