<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use App\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use App\Domain\Workshops\Entities\Workshops\Workshop;
use App\Domain\Workshops\Repo\DB\Workshops\DBWorkshopModel;
use App\Domain\Workshops\Services\WorkshopsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;

/**
 * Base class for the database tests of the fixture application (`tests/Fixtures/Application`): they run only in a
 * run that loads that application ({@see PackageTestEnvironment::isRunWithFixtureApplication()}) and skip themselves
 * in the run of the package alone, where no application class can be loaded.
 */
abstract class FixtureApplicationDatabaseTestCase extends DatabaseTestCase
{
    protected function setUp(): void
    {
        if (!PackageTestEnvironment::isRunWithFixtureApplication()) {
            self::markTestSkipped(
                'Test of the fixture application: set ' . PackageTestEnvironment::FIXTURE_APPLICATION_VARIABLE
                . '=1 and run the suites `application,integration`'
            );
        }
        parent::setUp();
    }

    protected function insertAccountRow(int $accountId): void
    {
        $this->insertRow(DBAccountModel::getTableName(), ['id' => $accountId, 'email' => 'account-' . $accountId . '@example.test']);
    }

    protected function insertWorkshopRow(int $workshopId, ?int $accountId, ?string $timezone = null): void
    {
        $this->insertRow(DBWorkshopModel::getTableName(), [
            'id' => $workshopId,
            'name' => 'Workshop ' . $workshopId,
            'accountId' => $accountId,
            'timezone' => $timezone,
        ]);
    }

    /** The stored workshop `$workshopId`, read past the read rights (the test's own look at the row). */
    protected function findWorkshopPastReadRights(int $workshopId): Workshop
    {
        /** @var WorkshopsService $workshopsService */
        $workshopsService = DDDService::instance()->getService(WorkshopsService::class);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $workshop = $workshopsService->find($workshopId, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        self::assertInstanceOf(Workshop::class, $workshop, 'workshop ' . $workshopId . ' is stored');
        return $workshop;
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

    /** The space service as the agent core asks for it: by the package's class name. */
    protected function getAgentSpacesService(): AgentSpacesService
    {
        $agentSpacesService = DDDService::instance()->getService(\DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::class);
        self::assertInstanceOf(AgentSpacesService::class, $agentSpacesService, 'the package\'s service class resolves to the application\'s service');
        return $agentSpacesService;
    }
}
