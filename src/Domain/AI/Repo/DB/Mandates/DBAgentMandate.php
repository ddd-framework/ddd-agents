<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Mandates;

use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;

/**
 * @method AgentMandate find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentMandate update(DefaultObject &$entity, int $depth = 1)
 * @property DBAgentMandateModel $ormInstance
 */
class DBAgentMandate extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentMandate::class;
    public const string BASE_ORM_MODEL = DBAgentMandateModel::class;

    /**
     * A mandate is the OWNER's authorization record: non-admins see only mandates of their own account.
     * Admins with access to all resources see everything (support/debug).
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $alias = static::getBaseModelAlias();
        /** @var Account|null $authAccount */
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount) {
            $queryBuilder->andWhere("{$alias}.id is null");
            return true;
        }
        if ($authAccount->isAdminWithAccessToAllResources()) {
            return false;
        }
        $queryBuilder->andWhere("{$alias}.accountId = :mandateRightsAuthAccountId")
            ->setParameter('mandateRightsAuthAccountId', $authAccount->id);
        return true;
    }
}
