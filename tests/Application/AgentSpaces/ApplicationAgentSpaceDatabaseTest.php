<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Application\AgentSpaces;

use App\Domain\AI\Entities\AgentSpaces\AgentSpace;
use App\Domain\Workshops\Entities\Workshops\Workshop;
use App\Domain\Workshops\Repo\DB\Workshops\DBWorkshopModel;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel;
use DDD\Domain\AI\Repo\DB\Mandates\DBAgentMandateModel;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\FixtureApplicationDatabaseTestCase;

/**
 * The space of an application that subclasses it (the fixture application: a space stands for a workshop and every
 * row carries `workshopId`), against the database on both engines:
 *
 * - the application creates the space of a workshop, one per workshop, and the row carries the application's column;
 * - whatever the package reads through its own class names — the service, a direct read, the space a conversation, a
 *   schedule or a mandate names — is the application's entity with the application's column;
 * - who may read and who may use a space is decided by the application's entity (the owner of the workshop today);
 * - the facts the agent core asks about a space are answered from the workshop;
 * - deleting the workshop deletes its space and the rows of the space, never the rows at account level.
 */
class ApplicationAgentSpaceDatabaseTest extends FixtureApplicationDatabaseTestCase
{
    protected const int ACCOUNT_ID = 11;

    protected const int OTHER_ACCOUNT_ID = 12;

    protected const int WORKSHOP_ID = 501;

    protected const int WORKSHOP_ID_OF_OTHER_ACCOUNT = 502;

    protected const int AGENT_SPACE_ID = 21;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertAccountRow(self::ACCOUNT_ID);
        $this->insertAccountRow(self::OTHER_ACCOUNT_ID);
        $this->insertWorkshopRow(self::WORKSHOP_ID, self::ACCOUNT_ID, 'Europe/Vienna');
        $this->insertWorkshopRow(self::WORKSHOP_ID_OF_OTHER_ACCOUNT, self::OTHER_ACCOUNT_ID);
    }

    public function testTheApplicationCreatesOneSpacePerWorkshopAndTheRowCarriesItsColumn(): void
    {
        $this->authenticateAsAccount(self::ACCOUNT_ID);
        $workshop = $this->findWorkshopPastReadRights(self::WORKSHOP_ID);

        $agentSpace = $this->getAgentSpacesService()->findOrCreateAgentSpaceOfWorkshop($workshop);

        self::assertInstanceOf(AgentSpace::class, $agentSpace);
        self::assertNotNull($agentSpace->id ?? null);
        $storedAgentSpace = $this->findStoredRow(DBAgentSpaceModel::getTableName(), (int)$agentSpace->id);
        self::assertNotNull($storedAgentSpace);
        self::assertSame(self::WORKSHOP_ID, (int)$storedAgentSpace['workshopId']);
        self::assertNull($storedAgentSpace['accountId'], 'the owner is the workshop\'s, never a copy on the space');

        $agentSpaceOfTheSecondCall = $this->getAgentSpacesService()->findOrCreateAgentSpaceOfWorkshop($workshop);
        self::assertSame((int)$agentSpace->id, (int)$agentSpaceOfTheSecondCall->id);
        self::assertSame(1, $this->countStoredAgentSpaces());
    }

    public function testASpaceReadThroughThePackageClassesIsTheApplicationsEntity(): void
    {
        $this->insertAgentSpaceRowOfWorkshop(self::AGENT_SPACE_ID, self::WORKSHOP_ID);
        $this->authenticateAsAccount(self::ACCOUNT_ID);

        $agentSpace = \DDD\Domain\AI\Entities\AgentSpaces\AgentSpace::getService()->find(self::AGENT_SPACE_ID, false);

        self::assertInstanceOf(AgentSpace::class, $agentSpace);
        self::assertSame(self::WORKSHOP_ID, $agentSpace->workshopId);
        self::assertInstanceOf(Workshop::class, $agentSpace->workshop);
        self::assertSame('Workshop ' . self::WORKSHOP_ID, $agentSpace->workshop->name);
        self::assertInstanceOf(AgentSpace::class, \DDD\Domain\AI\Entities\AgentSpaces\AgentSpace::newInstance());
    }

    public function testTheSpaceAConversationAScheduleAndAMandateNameIsTheApplicationsEntity(): void
    {
        $this->insertAgentSpaceRowOfWorkshop(self::AGENT_SPACE_ID, self::WORKSHOP_ID_OF_OTHER_ACCOUNT);
        $this->authenticateAsAccount(self::ACCOUNT_ID);
        $conversation = new AIConversation();
        $conversation->agentSpaceId = self::AGENT_SPACE_ID;
        $schedule = new AIConversationSchedule();
        $schedule->agentSpaceId = self::AGENT_SPACE_ID;
        $mandate = new AgentMandate();
        $mandate->agentSpaceId = self::AGENT_SPACE_ID;

        foreach ([$conversation->agentSpace, $schedule->agentSpace, $mandate->agentSpace] as $agentSpaceOfTheRow) {
            self::assertInstanceOf(AgentSpace::class, $agentSpaceOfTheRow);
            self::assertSame(self::AGENT_SPACE_ID, (int)$agentSpaceOfTheRow->id);
            self::assertSame(self::WORKSHOP_ID_OF_OTHER_ACCOUNT, $agentSpaceOfTheRow->workshopId);
        }
        self::assertNull(
            $this->getAgentSpacesService()->find(self::AGENT_SPACE_ID, false),
            'a direct read keeps the read rights: the workshop belongs to another account'
        );
    }

    public function testASpaceIsReadableByWhoeverOwnsItsWorkshopToday(): void
    {
        $this->insertAgentSpaceRowOfWorkshop(self::AGENT_SPACE_ID, self::WORKSHOP_ID);

        $this->authenticateAsAccount(self::ACCOUNT_ID);
        self::assertInstanceOf(AgentSpace::class, $this->getAgentSpacesService()->find(self::AGENT_SPACE_ID, false));
        $this->authenticateAsAccount(self::OTHER_ACCOUNT_ID);
        self::assertNull($this->getAgentSpacesService()->find(self::AGENT_SPACE_ID, false));

        $this->handWorkshopOverToAccount(self::WORKSHOP_ID, self::OTHER_ACCOUNT_ID);

        self::assertInstanceOf(AgentSpace::class, $this->getAgentSpacesService()->find(self::AGENT_SPACE_ID, false));
        $this->authenticateAsAccount(self::ACCOUNT_ID);
        self::assertNull($this->getAgentSpacesService()->find(self::AGENT_SPACE_ID, false));
    }

    public function testAnAccountMayUseASpaceWhileItOwnsTheWorkshop(): void
    {
        $this->insertAgentSpaceRowOfWorkshop(self::AGENT_SPACE_ID, self::WORKSHOP_ID);
        // the question is asked for the account a row is stored for, whoever is authenticated
        $this->authenticateAsAccount(self::OTHER_ACCOUNT_ID);
        $agentSpace = new AgentSpace();
        $agentSpace->id = self::AGENT_SPACE_ID;
        $agentSpace->workshopId = self::WORKSHOP_ID;

        $this->getAgentSpacesService()->requireAgentSpaceUsableByAccount($agentSpace, $this->buildAccount(self::ACCOUNT_ID));

        $this->expectException(ForbiddenException::class);
        $this->getAgentSpacesService()->requireAgentSpaceUsableByAccount($agentSpace, $this->buildAccount(self::OTHER_ACCOUNT_ID));
    }

    public function testTheOwnerAndTheTimeZoneOfASpaceAreAnsweredFromItsWorkshop(): void
    {
        $this->insertAgentSpaceRowOfWorkshop(self::AGENT_SPACE_ID, self::WORKSHOP_ID);
        $this->insertRow(DBAgentSpaceModel::getTableName(), ['id' => 22, 'workshopId' => self::WORKSHOP_ID_OF_OTHER_ACCOUNT, 'timezone' => 'Europe/Berlin']);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentSpace = $this->getAgentSpacesService()->find(self::AGENT_SPACE_ID, false);
            $agentSpaceWithAStoredZone = $this->getAgentSpacesService()->find(22, false);
            self::assertInstanceOf(AgentSpace::class, $agentSpace);
            self::assertInstanceOf(AgentSpace::class, $agentSpaceWithAStoredZone);

            $ownerAccount = $this->getAgentSpacesService()->findOwnerAccountOfAgentSpace($agentSpace);
            $timezoneOfTheWorkshop = $agentSpace->findTimezone();
            $storedTimezone = $agentSpaceWithAStoredZone->findTimezone();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        self::assertInstanceOf(Account::class, $ownerAccount);
        self::assertSame(self::ACCOUNT_ID, (int)$ownerAccount->id);
        self::assertNotNull($timezoneOfTheWorkshop);
        self::assertSame('Europe/Vienna', $timezoneOfTheWorkshop->identifier);
        self::assertTrue($timezoneOfTheWorkshop->unverified, 'nobody confirmed the workshop\'s zone for the agent');
        self::assertNotNull($storedTimezone);
        self::assertSame('Europe/Berlin', $storedTimezone->identifier);
        self::assertFalse($storedTimezone->unverified);
    }

    public function testDeletingAWorkshopDeletesItsSpaceAndTheRowsOfTheSpaceButNoRowAtAccountLevel(): void
    {
        $this->insertAgentSpaceRowOfWorkshop(self::AGENT_SPACE_ID, self::WORKSHOP_ID);
        $conversationOfTheAccount = ['accountId' => self::ACCOUNT_ID, 'runState' => AIConversationRunState::IDLE];
        $this->insertRow(DBAIConversationModel::getTableName(), $conversationOfTheAccount + ['id' => 100, 'agentSpaceId' => self::AGENT_SPACE_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), $conversationOfTheAccount + ['id' => 101, 'agentSpaceId' => null]);
        $this->insertRow(DBAIConversationScheduleModel::getTableName(), [
            'id' => 31,
            'accountId' => self::ACCOUNT_ID,
            'agentSpaceId' => self::AGENT_SPACE_ID,
            'task' => 'a task for the workshop',
            'status' => AIConversationScheduleStatus::ACTIVE,
            'iterationsUsed' => 0,
            'failureStreak' => 0,
        ]);
        $this->insertRow(DBAgentMandateModel::getTableName(), [
            'id' => 41,
            'accountId' => self::ACCOUNT_ID,
            'agentSpaceId' => self::AGENT_SPACE_ID,
            'actionClass' => AgentMandate::ACTION_CLASSES[0],
            'scopeType' => AgentMandate::SCOPE_UNLIMITED,
            'status' => AgentMandate::STATUS_ACTIVE,
            'validFrom' => '2026-01-01 00:00:00',
        ]);

        $sqlDialect = $this->getSqlDialect();
        $this->getDatabaseConnection()->executeStatement(
            'DELETE FROM ' . $sqlDialect->quote(DBWorkshopModel::getTableName()) . ' WHERE ' . $sqlDialect->quote('id') . ' = ?',
            [self::WORKSHOP_ID]
        );

        self::assertNull($this->findStoredRow(DBAgentSpaceModel::getTableName(), self::AGENT_SPACE_ID));
        self::assertNull($this->findStoredRow(DBAIConversationModel::getTableName(), 100));
        self::assertNull($this->findStoredRow(DBAIConversationScheduleModel::getTableName(), 31));
        self::assertNull($this->findStoredRow(DBAgentMandateModel::getTableName(), 41));
        self::assertNotNull($this->findStoredRow(DBAIConversationModel::getTableName(), 101), 'the conversation at account level stays');
    }

    /** A stored space row that stands for the workshop `$workshopId`. */
    protected function insertAgentSpaceRowOfWorkshop(int $agentSpaceId, int $workshopId): void
    {
        $this->insertRow(DBAgentSpaceModel::getTableName(), ['id' => $agentSpaceId, 'workshopId' => $workshopId]);
    }

    protected function handWorkshopOverToAccount(int $workshopId, int $accountId): void
    {
        $sqlDialect = $this->getSqlDialect();
        $this->getDatabaseConnection()->executeStatement(
            'UPDATE ' . $sqlDialect->quote(DBWorkshopModel::getTableName()) . ' SET ' . $sqlDialect->quote('accountId') . ' = ? WHERE ' . $sqlDialect->quote('id') . ' = ?',
            [$accountId, $workshopId]
        );
    }

    protected function countStoredAgentSpaces(): int
    {
        return (int)$this->getDatabaseConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->getSqlDialect()->quote(DBAgentSpaceModel::getTableName()));
    }

    /** An account object with the id `$accountId` and no role. */
    protected function buildAccount(int $accountId): Account
    {
        $account = new Account();
        $account->id = $accountId;
        return $account;
    }
}
