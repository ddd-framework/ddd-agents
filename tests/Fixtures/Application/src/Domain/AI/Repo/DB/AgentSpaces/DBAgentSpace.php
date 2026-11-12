<?php

declare(strict_types=1);

namespace App\Domain\AI\Repo\DB\AgentSpaces;

use App\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;

/**
 * Fixture application: the package's space repository over the application's entity and the application's model of
 * the same table. A space is readable by whoever owns its workshop today.
 *
 * @method AgentSpace find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentSpace update(DefaultObject &$entity, int $depth = 1)
 * @property DBAgentSpaceModel $ormInstance
 */
class DBAgentSpace extends \DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpace
{
    public const string BASE_ENTITY_CLASS = AgentSpace::class;
    public const string BASE_ORM_MODEL = DBAgentSpaceModel::class;

    /**
     * The space is joined to its workshop and the workshop's owner decides. The package rule (the space's own
     * `accountId`) does not apply.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool true = a condition was added (the expand join of a relation to a space merges it only then)
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $agentSpaceAlias = static::getBaseModelAlias();
        /** @var Account|null $authAccount */
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount) {
            $queryBuilder->andWhere("{$agentSpaceAlias}.id is null");
            return true;
        }
        if ($authAccount->isAdminWithAccessToAllResources()) {
            return false;
        }
        $workshopRightsAlias = $agentSpaceAlias . '_workshop_rights';
        $queryBuilder->leftJoin("{$agentSpaceAlias}.workshop", $workshopRightsAlias)
            ->andWhere("{$workshopRightsAlias}.accountId = :agentSpaceRightsAuthAccountId")
            ->setParameter('agentSpaceRightsAuthAccountId', $authAccount->id);
        return true;
    }
}
