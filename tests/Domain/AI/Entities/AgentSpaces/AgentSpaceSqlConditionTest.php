<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The modes of the partition condition. "No space" never selects the rows of all spaces: the rows-of-a-space mode
 * answers the account-level rows, the applies-to mode the account-wide rows only, and the mode for the rows one
 * account holds without a space carries that account in the condition itself.
 */
class AgentSpaceSqlConditionTest extends UnitTestCase
{
    public function testTheRowsOfASpaceCompareTheColumnWithItsId(): void
    {
        $agentSpaceSqlCondition = AgentSpaceSqlCondition::forAgentSpace($this->buildStoredAgentSpace(7));

        self::assertSame('`c`.`agentSpaceId` = :agentSpaceId', $agentSpaceSqlCondition->getConditionSql('c'));
        self::assertSame('`agentSpaceId` = :agentSpaceId', $agentSpaceSqlCondition->getConditionSql());
        self::assertSame('conversation.agentSpaceId = :agentSpaceId', $agentSpaceSqlCondition->getConditionDql('conversation'));
        self::assertSame(['agentSpaceId' => 7], $agentSpaceSqlCondition->getParameters());
    }

    public function testWithoutASpaceTheRowsOfASpaceAreTheAccountLevelRows(): void
    {
        $agentSpaceSqlCondition = AgentSpaceSqlCondition::forAgentSpace(null);

        self::assertSame('`c`.`agentSpaceId` IS NULL', $agentSpaceSqlCondition->getConditionSql('c'));
        self::assertSame('conversation.agentSpaceId IS NULL', $agentSpaceSqlCondition->getConditionDql('conversation'));
        self::assertSame([], $agentSpaceSqlCondition->getParameters());
    }

    public function testTheRowsThatApplyToASpaceAreItsOwnAndTheAccountWideOnes(): void
    {
        $agentSpaceSqlCondition = AgentSpaceSqlCondition::forAgentSpaceOrAccountWide($this->buildStoredAgentSpace(7));

        self::assertSame('(`m`.`agentSpaceId` = :agentSpaceId OR `m`.`agentSpaceId` IS NULL)', $agentSpaceSqlCondition->getConditionSql('m'));
        self::assertSame('(mandate.agentSpaceId = :agentSpaceId OR mandate.agentSpaceId IS NULL)', $agentSpaceSqlCondition->getConditionDql('mandate'));
        self::assertSame(['agentSpaceId' => 7], $agentSpaceSqlCondition->getParameters());
    }

    public function testWithoutASpaceOnlyTheAccountWideRowsApply(): void
    {
        $agentSpaceSqlCondition = AgentSpaceSqlCondition::forAgentSpaceOrAccountWide(null);

        self::assertSame('`m`.`agentSpaceId` IS NULL', $agentSpaceSqlCondition->getConditionSql('m'));
        self::assertSame('mandate.agentSpaceId IS NULL', $agentSpaceSqlCondition->getConditionDql('mandate'));
        self::assertSame([], $agentSpaceSqlCondition->getParameters());
    }

    public function testTheRowsAnAccountHoldsWithoutASpaceCarryTheAccountInTheCondition(): void
    {
        $account = new Account();
        $account->id = 12;
        $agentSpaceSqlCondition = AgentSpaceSqlCondition::forAccountWithoutAgentSpace($account);

        self::assertSame('(`schedule`.`agentSpaceId` IS NULL AND `schedule`.`accountId` = :accountIdOfRowsWithoutAgentSpace)', $agentSpaceSqlCondition->getConditionSql('schedule'));
        self::assertSame('(schedule.agentSpaceId IS NULL AND schedule.accountId = :accountIdOfRowsWithoutAgentSpace)', $agentSpaceSqlCondition->getConditionDql('schedule'));
        self::assertSame(['accountIdOfRowsWithoutAgentSpace' => 12], $agentSpaceSqlCondition->getParameters());
    }

    public function testAnAccountThatIsNotStoredIsNeverReadAsEveryAccount(): void
    {
        $this->expectException(InternalErrorException::class);
        AgentSpaceSqlCondition::forAccountWithoutAgentSpace(new Account());
    }

    public function testTheIdFormsBuildTheSameConditionsAsTheObjectForms(): void
    {
        self::assertSame(
            AgentSpaceSqlCondition::forAgentSpace($this->buildStoredAgentSpace(7))->getConditionSql('c'),
            AgentSpaceSqlCondition::forAgentSpaceId(7)->getConditionSql('c')
        );
        self::assertSame(
            AgentSpaceSqlCondition::forAgentSpaceOrAccountWide($this->buildStoredAgentSpace(7))->getConditionSql('m'),
            AgentSpaceSqlCondition::forAgentSpaceIdOrAccountWide(7)->getConditionSql('m')
        );
        self::assertSame('`c`.`agentSpaceId` IS NULL', AgentSpaceSqlCondition::forAgentSpaceId(null)->getConditionSql('c'));
        self::assertSame('`m`.`agentSpaceId` IS NULL', AgentSpaceSqlCondition::forAgentSpaceIdOrAccountWide(null)->getConditionSql('m'));
    }

    public function testAStatementWithTwoConditionsGivesEachItsOwnParameter(): void
    {
        $agentSpaceSqlCondition = AgentSpaceSqlCondition::forAgentSpaceId(7, 'parentAgentSpaceId');

        self::assertSame('`p`.`agentSpaceId` = :parentAgentSpaceId', $agentSpaceSqlCondition->getConditionSql('p'));
        self::assertSame(['parentAgentSpaceId' => 7], $agentSpaceSqlCondition->getParameters());
    }

    public function testASpaceThatIsNotStoredHasNoRowsAndIsNeverReadAsNoSpace(): void
    {
        $agentSpaceSqlCondition = AgentSpaceSqlCondition::forAgentSpace(new AgentSpace());

        self::assertSame('`c`.`agentSpaceId` = :agentSpaceId', $agentSpaceSqlCondition->getConditionSql('c'));
        self::assertSame(['agentSpaceId' => AgentSpaceSqlCondition::AGENT_SPACE_ID_THAT_MATCHES_NO_ROW], $agentSpaceSqlCondition->getParameters());
        self::assertSame(0, AgentSpaceSqlCondition::AGENT_SPACE_ID_THAT_MATCHES_NO_ROW);
    }

    public function testASpaceThatIsNotStoredSelectsOnlyTheAccountWideRowsInTheModeThatIncludesThem(): void
    {
        $agentSpaceSqlCondition = AgentSpaceSqlCondition::forAgentSpaceOrAccountWide(new AgentSpace());

        self::assertSame('(`m`.`agentSpaceId` = :agentSpaceId OR `m`.`agentSpaceId` IS NULL)', $agentSpaceSqlCondition->getConditionSql('m'));
        self::assertSame(['agentSpaceId' => AgentSpaceSqlCondition::AGENT_SPACE_ID_THAT_MATCHES_NO_ROW], $agentSpaceSqlCondition->getParameters());
    }

    protected function buildStoredAgentSpace(int $id): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $id;
        return $agentSpace;
    }
}
