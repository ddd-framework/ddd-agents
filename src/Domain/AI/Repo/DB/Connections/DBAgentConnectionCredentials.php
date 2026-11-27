<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionCredentials;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * The credentials: never readable under rights.
 *
 * @method AgentConnectionCredentials find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistrCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentConnectionCredentials update(AgentConnectionCredentials &$entity, int $depth = 1)
 * @property DBAgentConnectionCredentialsModel $ormObject
 */
class DBAgentConnectionCredentials extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentConnectionCredentials::class;
    public const string BASE_ORM_MODEL = DBAgentConnectionCredentialsModel::class;

    /** Never read under rights: only the credentials service reads it, with the rights restrictions switched off. */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $queryBuilder->andWhere('1 = 0');
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
