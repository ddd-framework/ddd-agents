<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Infrastructure\Services\AuthService;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequest;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Approval requests of an account.
 *
 * @method AgentConnectionApprovalRequest find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistrCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentConnectionApprovalRequest update(AgentConnectionApprovalRequest &$entity, int $depth = 1)
 * @property DBAgentConnectionApprovalRequestModel $ormObject
 */
class DBAgentConnectionApprovalRequest extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentConnectionApprovalRequest::class;
    public const string BASE_ORM_MODEL = DBAgentConnectionApprovalRequestModel::class;

    /**
     * Read only by the account that owns the row; system work (handlers, commands, the tools after their own
     * check) reads with the rights restrictions switched off. An application widens it in a subclass at the same path.
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $account = AuthService::instance()->getAccount();
        $alias = static::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.accountId = :rightsAccountId")->setParameter('rightsAccountId', (int)($account->id ?? 0));
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
