<?php

declare(strict_types=1);

namespace App\Domain\Workshops\Entities\Workshops;

use App\Domain\Workshops\Repo\DB\Workshops\DBWorkshops;
use App\Domain\Workshops\Services\WorkshopsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * Fixture application: the workshops.
 *
 * @property Workshop[] $elements
 * @method Workshop getByUniqueKey(string $uniqueKey)
 * @method Workshop[] getElements()
 * @method Workshop|null first()
 * @method static WorkshopsService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBWorkshops::class)]
class Workshops extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = WorkshopsService::class;
}
