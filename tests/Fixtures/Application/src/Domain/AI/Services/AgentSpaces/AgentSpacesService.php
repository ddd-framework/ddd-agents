<?php

declare(strict_types=1);

namespace App\Domain\AI\Services\AgentSpaces;

use App\Domain\AI\Entities\AgentSpaces\AgentSpace;
use App\Domain\AI\Entities\AgentSpaces\AgentSpaces;
use App\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpace;
use App\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaces;
use App\Domain\Workshops\Entities\Workshops\Workshop;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Fixture application: what the agent core asks about a space, answered for workshops, and the one place a space is
 * created — the package never creates one, because only the application can fill its column.
 *
 * @method AgentSpace|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentSpaces findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAgentSpace getEntityRepoClassInstance()
 * @method DBAgentSpaces getEntitySetRepoClassInstance()
 */
class AgentSpacesService extends \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService
{
    public const DEFAULT_ENTITY_CLASS = AgentSpace::class;

    /**
     * The space of `$workshop`; null when the workshop has none yet. Read under the caller's rights.
     *
     * @param Workshop $workshop
     * @param bool $useEntityRegistryCache false = a fresh read of the row
     * @return AgentSpace|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAgentSpaceOfWorkshop(Workshop $workshop, bool $useEntityRegistryCache = true): ?AgentSpace
    {
        if (!isset($workshop->id)) {
            return null;
        }
        $agentSpaceRepo = $this->getEntityRepoClassInstance();
        $agentSpaceAlias = $agentSpaceRepo::getBaseModelAlias();
        $queryBuilder = $agentSpaceRepo::createQueryBuilder(true);
        $queryBuilder
            ->andWhere("{$agentSpaceAlias}.workshopId = :agentSpaceWorkshopId")
            ->setParameter('agentSpaceWorkshopId', (int)$workshop->id);
        $agentSpace = $agentSpaceRepo->find($queryBuilder, $useEntityRegistryCache);
        return $agentSpace instanceof AgentSpace ? $agentSpace : null;
    }

    /**
     * The space of `$workshop`, created when the workshop has none yet. Call it only with a workshop the caller read
     * under the rights of the account it acts for: the lookup and the insert run with the rights restrictions lifted,
     * because the row is the agent's own record of the workshop and not a resource an account creates.
     *
     * @param Workshop $workshop a stored workshop
     * @return AgentSpace
     * @throws BadRequestException when the workshop has no id
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findOrCreateAgentSpaceOfWorkshop(Workshop $workshop): AgentSpace
    {
        if (!isset($workshop->id)) {
            throw new BadRequestException('A space can only be created for a stored workshop.');
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentSpace = $this->findAgentSpaceOfWorkshop($workshop);
            if ($agentSpace === null) {
                $agentSpace = $this->insertAgentSpaceOfWorkshop($workshop) ?? $this->findAgentSpaceOfWorkshop($workshop, false);
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if ($agentSpace === null) {
            throw new InternalErrorException("The space of workshop {$workshop->id} could neither be created nor read.");
        }
        $agentSpace->workshop = $workshop;
        return $agentSpace;
    }

    /**
     * An account may use a space when it owns the workshop of the space today (an admin with access to all resources
     * may use every space). The workshop is read past the read rights: the question is asked for the account a row is
     * stored for, which need not be the authenticated one.
     *
     * @param \DDD\Domain\AI\Entities\AgentSpaces\AgentSpace $agentSpace
     * @param Account $account the account the row is stored for
     * @return void
     * @throws ForbiddenException
     */
    public function requireAgentSpaceUsableByAccount(\DDD\Domain\AI\Entities\AgentSpaces\AgentSpace $agentSpace, Account $account): void
    {
        if ($account->isAdminWithAccessToAllResources()) {
            return;
        }
        $workshop = null;
        if ($agentSpace instanceof AgentSpace) {
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $workshop = $agentSpace->workshop ?? null;
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        }
        if ($workshop !== null && isset($workshop->accountId, $account->id) && (int)$workshop->accountId === (int)$account->id) {
            return;
        }
        throw new ForbiddenException(static::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
    }

    /**
     * Inserts the space of `$workshop` as a new row.
     *
     * @param Workshop $workshop
     * @return AgentSpace|null null when another process inserted the space of this workshop in the meantime
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function insertAgentSpaceOfWorkshop(Workshop $workshop): ?AgentSpace
    {
        $newAgentSpace = new AgentSpace();
        $newAgentSpace->workshopId = (int)$workshop->id;
        try {
            $insertedAgentSpace = $this->insert($newAgentSpace);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        return $insertedAgentSpace instanceof AgentSpace ? $insertedAgentSpace : null;
    }
}
