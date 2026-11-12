<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Mandates;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Entities\Mandates\AgentMandateGrantArguments;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Domain\AI\Services\Mandates\Probes\AgentMandatesServiceWithStoredAccounts;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The space of a mandate, in the two places the service decides about it:
 *
 * - the read of the mandates that APPLY to an action: with a space the mandates bound to it and the account-wide
 *   ones, without a space the account-wide ones only — a mandate bound to a space never applies to another space or
 *   to the account level;
 * - the grant: a new mandate is bound to a space only when its account may use that space, and a space that is not
 *   stored is never read as "account-wide".
 *
 * No database: the condition is asserted as the value the statement is built from, and every grant here ends before
 * the approval check and before a mandate is stored.
 */
class AgentMandatesServiceAgentSpaceTest extends UnitTestCase
{
    protected const int ACCOUNT_ID = 12;

    protected const int OTHER_ACCOUNT_ID = 13;

    protected const int AGENT_SPACE_ID = 7;

    protected const string MANDATE_MODEL_ALIAS = 'agentMandate';

    public function testWithASpaceTheMandatesBoundToItAndTheAccountWideOnesApply(): void
    {
        $agentSpaceSqlCondition = $this->buildSqlConditionOfMandatesApplyingTo($this->buildAgentSpace(self::AGENT_SPACE_ID, self::ACCOUNT_ID));

        self::assertTrue($agentSpaceSqlCondition->includesRowsWithoutSpace, 'the mode of the rows that APPLY, not of the rows OF the space');
        self::assertSame(self::AGENT_SPACE_ID, $agentSpaceSqlCondition->agentSpaceId);
        self::assertSame(
            '(agentMandate.agentSpaceId = :agentSpaceId OR agentMandate.agentSpaceId IS NULL)',
            $agentSpaceSqlCondition->getConditionDql(self::MANDATE_MODEL_ALIAS)
        );
        self::assertSame(['agentSpaceId' => self::AGENT_SPACE_ID], $agentSpaceSqlCondition->getParameters());
    }

    public function testWithoutASpaceOnlyTheAccountWideMandatesApply(): void
    {
        $agentSpaceSqlCondition = $this->buildSqlConditionOfMandatesApplyingTo(null);

        self::assertNull($agentSpaceSqlCondition->agentSpaceId);
        self::assertSame(
            'agentMandate.agentSpaceId IS NULL',
            $agentSpaceSqlCondition->getConditionDql(self::MANDATE_MODEL_ALIAS),
            'no space never selects the mandates bound to a space'
        );
        self::assertSame([], $agentSpaceSqlCondition->getParameters());
    }

    public function testASpaceThatIsNotStoredIsAuthorizedOnlyByAnAccountWideMandate(): void
    {
        $agentSpaceSqlCondition = $this->buildSqlConditionOfMandatesApplyingTo(new AgentSpace());

        self::assertSame(
            '(agentMandate.agentSpaceId = :agentSpaceId OR agentMandate.agentSpaceId IS NULL)',
            $agentSpaceSqlCondition->getConditionDql(self::MANDATE_MODEL_ALIAS)
        );
        self::assertSame(
            ['agentSpaceId' => AgentSpaceSqlCondition::AGENT_SPACE_ID_THAT_MATCHES_NO_ROW],
            $agentSpaceSqlCondition->getParameters(),
            'no mandate is bound to a space that is not stored'
        );
    }

    public function testTheConditionAndItsValueAreAddedToTheStatement(): void
    {
        $agentSpaceSqlCondition = $this->buildSqlConditionOfMandatesApplyingTo($this->buildAgentSpace(self::AGENT_SPACE_ID, self::ACCOUNT_ID));
        $queryBuilder = $this->createMock(DoctrineQueryBuilder::class);
        $queryBuilder->expects(self::once())
            ->method('andWhere')
            ->with('(agentMandate.agentSpaceId = :agentSpaceId OR agentMandate.agentSpaceId IS NULL)');
        $queryBuilder->expects(self::once())
            ->method('setParameter')
            ->with('agentSpaceId', self::AGENT_SPACE_ID);

        self::invokeMethod(new AgentMandatesService(), 'applyAgentSpaceSqlCondition', [$queryBuilder, $agentSpaceSqlCondition, self::MANDATE_MODEL_ALIAS]);
    }

    public function testWithoutASpaceTheStatementCarriesTheConditionAndNoValue(): void
    {
        $agentSpaceSqlCondition = $this->buildSqlConditionOfMandatesApplyingTo(null);
        $queryBuilder = $this->createMock(DoctrineQueryBuilder::class);
        $queryBuilder->expects(self::once())
            ->method('andWhere')
            ->with('agentMandate.agentSpaceId IS NULL');
        $queryBuilder->expects(self::never())->method('setParameter');

        self::invokeMethod(new AgentMandatesService(), 'applyAgentSpaceSqlCondition', [$queryBuilder, $agentSpaceSqlCondition, self::MANDATE_MODEL_ALIAS]);
    }

    public function testAMandateIsNeverBoundToASpaceOfAnotherAccount(): void
    {
        $agentMandatesService = new AgentMandatesServiceWithStoredAccounts();
        $agentMandatesService->storedAccountsById[self::ACCOUNT_ID] = $this->buildAccount(self::ACCOUNT_ID);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(AgentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        $agentMandatesService->grantMandateFromDialogue(
            self::ACCOUNT_ID,
            $this->buildAgentSpace(9, self::OTHER_ACCOUNT_ID),
            $this->buildGrantArguments(),
            new AIConversationMessages()
        );
    }

    public function testAMandateIsNeverBoundToASpaceWhenItsAccountCannotBeRead(): void
    {
        $agentMandatesService = new AgentMandatesServiceWithStoredAccounts();

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(AgentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        $agentMandatesService->grantMandateFromDialogue(
            self::ACCOUNT_ID,
            $this->buildAgentSpace(self::AGENT_SPACE_ID, self::ACCOUNT_ID),
            $this->buildGrantArguments(),
            new AIConversationMessages()
        );
    }

    public function testASpaceThatIsNotStoredNeverBecomesAnAccountWideGrant(): void
    {
        $agentMandatesService = new AgentMandatesServiceWithStoredAccounts();
        $agentMandatesService->storedAccountsById[self::ACCOUNT_ID] = $this->buildAccount(self::ACCOUNT_ID);

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('A mandate cannot be bound to an agent space without an id.');
        $agentMandatesService->grantMandateFromDialogue(self::ACCOUNT_ID, new AgentSpace(), $this->buildGrantArguments(), new AIConversationMessages());
    }

    public function testASpaceOfTheAccountPassesTheCheckAndTheGrantGoesOnToTheDialogue(): void
    {
        $agentMandatesService = new AgentMandatesServiceWithStoredAccounts();
        $agentMandatesService->storedAccountsById[self::ACCOUNT_ID] = $this->buildAccount(self::ACCOUNT_ID);

        try {
            $agentMandatesService->grantMandateFromDialogue(
                self::ACCOUNT_ID,
                $this->buildAgentSpace(self::AGENT_SPACE_ID, self::ACCOUNT_ID),
                $this->buildGrantArguments(),
                new AIConversationMessages()
            );
            self::fail('a grant without customer dialogue is refused');
        } catch (BadRequestException $badRequestException) {
            self::assertStringContainsString('no customer dialogue', $badRequestException->getMessage());
        }
        self::assertSame([self::ACCOUNT_ID], $agentMandatesService->accountIdsAskedFor, 'the account is read once, for the check of the space');
    }

    public function testAnAccountWideGrantRunsNoCheckOfASpace(): void
    {
        $agentMandatesService = new AgentMandatesServiceWithStoredAccounts();

        try {
            $agentMandatesService->grantMandateFromDialogue(self::ACCOUNT_ID, null, $this->buildGrantArguments(), new AIConversationMessages());
            self::fail('a grant without customer dialogue is refused');
        } catch (BadRequestException $badRequestException) {
            self::assertStringContainsString('no customer dialogue', $badRequestException->getMessage());
        }
        self::assertSame([], $agentMandatesService->accountIdsAskedFor, 'without a space there is nothing to check the account against');
    }

    /** The condition {@see AgentMandatesService::findActiveMandateFor()} puts into its statement next to the account. */
    protected function buildSqlConditionOfMandatesApplyingTo(?AgentSpace $agentSpace): AgentSpaceSqlCondition
    {
        /** @var AgentSpaceSqlCondition $agentSpaceSqlCondition */
        $agentSpaceSqlCondition = self::invokeMethod(new AgentMandatesService(), 'buildSqlConditionOfMandatesApplyingTo', [$agentSpace]);
        return $agentSpaceSqlCondition;
    }

    /** A grant that reaches the dialogue check without a statement: its scope is not the one-instruction one. */
    protected function buildGrantArguments(): AgentMandateGrantArguments
    {
        return AgentMandateGrantArguments::build(AgentMandate::ACTION_STANDING_AUTOMATION, AgentMandate::SCOPE_UNLIMITED, null, null);
    }

    protected function buildAgentSpace(int $id, int $accountId): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $id;
        $agentSpace->accountId = $accountId;
        return $agentSpace;
    }

    protected function buildAccount(int $id): Account
    {
        $role = new Role();
        $role->setName(Role::LOGIN);
        $roles = new Roles();
        $roles->add($role);
        $account = new Account();
        $account->id = $id;
        $account->roles = $roles;
        return $account;
    }
}
