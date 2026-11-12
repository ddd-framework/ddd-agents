<?php

declare(strict_types=1);

namespace App\Domain\Workshops\Services;

use App\Domain\Workshops\Entities\Workshops\Workshop;
use App\Domain\Workshops\Entities\Workshops\Workshops;
use App\Domain\Workshops\Repo\DB\Workshops\DBWorkshop;
use App\Domain\Workshops\Repo\DB\Workshops\DBWorkshops;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Fixture application: the workshops service.
 *
 * @method Workshop|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method Workshops findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBWorkshop getEntityRepoClassInstance()
 * @method DBWorkshops getEntitySetRepoClassInstance()
 */
class WorkshopsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = Workshop::class;
}
