<?php

declare(strict_types=1);

namespace App\Domain\Workshops\Repo\DB\Workshops;

use App\Domain\Workshops\Entities\Workshops\Workshop;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;

/**
 * Fixture application: the workshop repository. A workshop is readable by the account that owns it today; an admin
 * with access to all resources reads every workshop.
 *
 * @method Workshop find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method Workshop update(DefaultObject &$entity, int $depth = 1)
 * @property DBWorkshopModel $ormInstance
 */
class DBWorkshop extends DBEntity
{
    public const string BASE_ENTITY_CLASS = Workshop::class;
    public const string BASE_ORM_MODEL = DBWorkshopModel::class;

    /**
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool true = a condition was added
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $workshopAlias = static::getBaseModelAlias();
        /** @var Account|null $authAccount */
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount) {
            $queryBuilder->andWhere("{$workshopAlias}.id is null");
            return true;
        }
        if ($authAccount->isAdminWithAccessToAllResources()) {
            return false;
        }
        $queryBuilder->andWhere("{$workshopAlias}.accountId = :workshopRightsAuthAccountId")
            ->setParameter('workshopRightsAuthAccountId', $authAccount->id);
        return true;
    }
}
