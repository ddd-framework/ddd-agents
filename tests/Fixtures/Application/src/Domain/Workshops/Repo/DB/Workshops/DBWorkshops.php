<?php

declare(strict_types=1);

namespace App\Domain\Workshops\Repo\DB\Workshops;

use App\Domain\Workshops\Entities\Workshops\Workshops;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Fixture application: the workshop set repository.
 *
 * @method Workshops find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBWorkshops extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBWorkshop::class;
    public const string BASE_ENTITY_SET_CLASS = Workshops::class;
}
