<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionDriveRoot;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionDriveRoot find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistrCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentConnectionDriveRoot update(AgentConnectionDriveRoot &$entity, int $depth = 1)
 * @property DBAgentConnectionDriveRootModel $ormObject
 */
class DBAgentConnectionDriveRoot extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentConnectionDriveRoot::class;
    public const string BASE_ORM_MODEL = DBAgentConnectionDriveRootModel::class;

    /** System rows: read and written only with the rights restrictions switched off (services check the access) */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $queryBuilder->andWhere(static::getBaseModelAlias() . '.id IS NULL');
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
