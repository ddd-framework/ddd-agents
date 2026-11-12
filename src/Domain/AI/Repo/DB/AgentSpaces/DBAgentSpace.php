<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * @method AgentSpace find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentSpace update(DefaultObject &$entity, int $depth = 1)
 * @property DBAgentSpaceModel $ormInstance
 */
class DBAgentSpace extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentSpace::class;
    public const string BASE_ORM_MODEL = DBAgentSpaceModel::class;

    /**
     * Loads the space a row names — a conversation, a schedule, a mandate — when the row's relation to its space is
     * first read. This load passes the read rights: whoever holds the row may know the space it names. The row was
     * stored for that space after the check that its account may use it
     * ({@see \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::requireAgentSpaceUsableByAccount()}), and
     * the read rights of a space can differ from those of the row: an application may decide them by an entity of
     * its own, whose rights change after the row was stored. Under the read rights such a row would read "no
     * space", and "no space" means "account level" everywhere in the core — the run would go on for the account
     * instead of for the space.
     *
     * Only this load passes the rights. A direct read of a space ({@see self::find()}, a list, an expand join) keeps
     * them, and so does everything the loaded space refers to: a relation of the space that is read later loads under
     * its own rights.
     *
     * Two consequences for the repository of an application's subclass:
     *
     * - The rights are switched off for EVERY repository while this load runs, not only for the space. Whatever the
     *   subclass repository loads while it maps the row to the entity (an override of `mapToEntity()` or
     *   `postProcessAfterMapping()` that reads another entity) loads without rights too. Keep the mapping of a space
     *   free of such reads; read the application's own entity through its lazy relation instead.
     * - "May read the row" implies "may read the space the row names", with every column of the space — the
     *   application's own reference among them. A response that serializes a conversation, a schedule or a mandate
     *   together with its loaded space shows these columns to whoever may read the row. An application for which that
     *   is too much hides the relation or the column on output.
     *
     * @param DefaultObject $initiatingEntity the row that names the space
     * @param LazyLoad $lazyloadAttributeInstance
     * @return DefaultObject|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function lazyload(DefaultObject &$initiatingEntity, LazyLoad &$lazyloadAttributeInstance): ?DefaultObject
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return parent::lazyload($initiatingEntity, $lazyloadAttributeInstance);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * A space is visible to the account it belongs to; an admin with access to all resources sees every space. A space
     * without an account (an application that anchors its spaces on an entity of its own) is visible to no one by this
     * rule: such an application overrides this method in the repository of its subclass and decides by its own entity.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool true = a condition was added (the expand join of a relation to a space merges it only then)
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
        $queryBuilder->andWhere("{$alias}.accountId = :agentSpaceRightsAuthAccountId")
            ->setParameter('agentSpaceRightsAuthAccountId', $authAccount->id);
        return true;
    }
}
