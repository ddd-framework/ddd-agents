<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Infrastructure\Services\AuthService;
use DDD\Domain\AI\Entities\Connections\AgentConnectionActionTarget;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Targets of a connection's audit, read through the connection's owner.
 *
 * @method AgentConnectionActionTarget find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistrCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentConnectionActionTarget update(AgentConnectionActionTarget &$entity, int $depth = 1)
 * @property DBAgentConnectionActionTargetModel $ormObject
 */
class DBAgentConnectionActionTarget extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentConnectionActionTarget::class;
    public const string BASE_ORM_MODEL = DBAgentConnectionActionTargetModel::class;

    /**
     * Read through its connection: only rows of a connection the signed-in account owns. System work reads with the
     * rights restrictions switched off.
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $account = AuthService::instance()->getAccount();
        $alias = static::getBaseModelAlias();
        $queryBuilder->andWhere(
            "$alias.agentConnectionId IN (SELECT rightsConnection.id FROM " . DBAgentConnectionModel::class . " rightsConnection WHERE rightsConnection.accountId = :rightsAccountId)"
        )->setParameter('rightsAccountId', (int)($account->id ?? 0));
        return true;
    }

    public static function applyUpdateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyReadRightsQuery($queryBuilder);
    }

    public static function applyDeleteRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyReadRightsQuery($queryBuilder);
    }

    /** New rows come only from the package's services (rights switched off); with rights on nothing is created */
    public static function applyCreateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $queryBuilder->andWhere('1 = 0');
        return true;
    }
}
