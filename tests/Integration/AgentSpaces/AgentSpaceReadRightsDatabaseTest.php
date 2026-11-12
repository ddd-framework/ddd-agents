<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use DDD\Tests\Agents\Support\PackageTestEnvironment;

/**
 * The space of an account against the database, on both engines:
 *
 * - a space is stored and read back through its service;
 * - the read rights of the package repository: a space is visible to its own account and to no other;
 * - the space a row names loads past these rights: a mandate of another account's space still knows its space,
 *   while a direct read of that space stays refused.
 */
class AgentSpaceReadRightsDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 11;

    protected const int OTHER_ACCOUNT_ID = 12;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'first@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::OTHER_ACCOUNT_ID, 'email' => 'second@example.test']);
    }

    public function testASpaceIsStoredAndReadBackThroughItsService(): void
    {
        if (PackageTestEnvironment::isRunWithFixtureApplication()) {
            self::markTestSkipped('The package class is not the stored class of an application that subclasses the space: the application creates its spaces (ApplicationAgentSpaceDatabaseTest).');
        }
        $this->authenticateAsAccount(self::ACCOUNT_ID);
        $agentSpace = new AgentSpace();
        $agentSpace->accountId = self::ACCOUNT_ID;
        $agentSpace->timezone = 'Europe/Berlin';
        $agentSpace->update();

        self::assertNotNull($agentSpace->id);
        $storedRow = $this->findStoredRow(DBAgentSpaceModel::getTableName(), (int)$agentSpace->id);
        self::assertNotNull($storedRow);
        self::assertSame(self::ACCOUNT_ID, (int)$storedRow['accountId']);
        self::assertSame('Europe/Berlin', $storedRow['timezone']);

        $readAgentSpace = $this->getAgentSpacesService()->find((int)$agentSpace->id, false);
        self::assertInstanceOf(AgentSpace::class, $readAgentSpace);
        self::assertSame(self::ACCOUNT_ID, $readAgentSpace->accountId);
        self::assertSame('Europe/Berlin', $readAgentSpace->timezone);
    }

    public function testASpaceIsVisibleToItsOwnAccountOnly(): void
    {
        $this->insertAgentSpaceRow(['id' => 21, 'accountId' => self::ACCOUNT_ID]);

        $this->authenticateAsAccount(self::ACCOUNT_ID);
        self::assertInstanceOf(AgentSpace::class, $this->getAgentSpacesService()->find(21, false));

        $this->authenticateAsAccount(self::OTHER_ACCOUNT_ID);
        self::assertNull($this->getAgentSpacesService()->find(21, false));
    }

    public function testTheSpaceARowNamesLoadsForAnAccountThatMayNotReadTheSpace(): void
    {
        $this->insertAgentSpaceRow(['id' => 22, 'accountId' => self::OTHER_ACCOUNT_ID]);
        $this->authenticateAsAccount(self::ACCOUNT_ID);
        $mandate = new AgentMandate();
        $mandate->agentSpaceId = 22;

        $agentSpaceOfTheMandate = $mandate->agentSpace;

        self::assertInstanceOf(AgentSpace::class, $agentSpaceOfTheMandate);
        self::assertSame(22, (int)$agentSpaceOfTheMandate->id);
        self::assertSame(self::OTHER_ACCOUNT_ID, $agentSpaceOfTheMandate->accountId);
        self::assertNull($this->getAgentSpacesService()->find(22, false), 'a direct read keeps the read rights');
    }

    /** Makes the account `$accountId` the authenticated one, without the admin role. */
    protected function authenticateAsAccount(int $accountId): void
    {
        $role = new Role();
        $role->setName('USER');
        $roles = new Roles();
        $roles->add($role);
        $account = new Account();
        $account->id = $accountId;
        $account->roles = $roles;
        AuthService::instance()->setAccount($account);
    }

    protected function getAgentSpacesService(): AgentSpacesService
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService;
    }
}
