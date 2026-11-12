<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\QueryOptions;

use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptionsTrait;
use ReflectionException;

/**
 * {@see DtoQueryOptionsTrait} for a request class of the module whose `#[DtoQueryOptions]` names a class of the
 * module that an application may derive (conversation, schedule, mandate, space).
 *
 * The attribute can only name the module's class. The filters, the order and the expands a request may use are read
 * off that class — so a column or a relation the application's class adds (its own id of the thing a space stands
 * for, kept next to `agentSpaceId` for its clients) would be refused in the request and missing in the generated
 * documentation, although the application's repository reads rows of the application's class. This trait answers the
 * attribute with the class the application uses in the module class's place (its class of the same path under its
 * own root namespace, or the one its container maps); without such a class the attribute is returned as declared.
 *
 * Every reader of the attribute — the validation of a request, the OpenAPI and the tool documentation — asks
 * {@see self::getDtoQueryOptions()}, so one answer covers them all.
 *
 * The application's class has to use `QueryOptionsTrait` itself: the framework looks for the trait on the named
 * class, not on its parents.
 */
trait DtoQueryOptionsOfApplicationClassTrait
{
    use DtoQueryOptionsTrait {
        getDtoQueryOptions as protected getDeclaredDtoQueryOptions;
    }

    /**
     * The `#[DtoQueryOptions]` of this request class, with the base class replaced by the application's class.
     *
     * @return DtoQueryOptions|null null = no class in the hierarchy declares the attribute
     * @throws ReflectionException
     */
    public static function getDtoQueryOptions(): ?DtoQueryOptions
    {
        $declaredDtoQueryOptions = static::getDeclaredDtoQueryOptions();
        if ($declaredDtoQueryOptions === null) {
            return null;
        }
        $applicationClassName = DDDService::instance()->getContainerServiceClassNameForClass($declaredDtoQueryOptions->baseEntity);
        if ($applicationClassName === null || $applicationClassName === $declaredDtoQueryOptions->baseEntity) {
            return $declaredDtoQueryOptions;
        }
        return new DtoQueryOptions($applicationClassName, $declaredDtoQueryOptions->expose);
    }
}
