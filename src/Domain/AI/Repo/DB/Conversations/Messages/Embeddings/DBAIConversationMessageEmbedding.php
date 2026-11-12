<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings;

use DDD\Domain\AI\Entities\Conversations\Messages\Embeddings\AIConversationMessageEmbedding;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;

/**
 * @method AIConversationMessageEmbedding find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBAIConversationMessageEmbeddingModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationMessageEmbedding extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversationMessageEmbedding::class;
    public const string BASE_ORM_MODEL = DBAIConversationMessageEmbeddingModel::class;

    /**
     * Rights scope via the owning conversation's account — the account read rights of
     * {@see AgentAccountRightsService::applyAccountReadRights()} like
     * {@see \DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage::applyReadRightsQuery} (an
     * embedding is no more public than its message; it has no account of its own). The embedding carries no
     * `aiConversationId` of its own, so it joins its 1:1 message first and the rights run on the message's
     * conversation (`aiConversationMessage → aiConversation`). The unauthenticated and admin decisions are taken here
     * on the embedding itself, before the join (the rights service's own checks would otherwise judge the joined
     * message alias). Returns true wherever conditions were added so ExpandOptions merges them on nested loads
     * (ddd-rights-specialist: never `return parent::` after adding WHEREs).
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }

        $baseModelAlias = static::getBaseModelAlias();
        $messageRightsAlias = $baseModelAlias . '_aiConversationMessage_rights';
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount instanceof Account) {
            $queryBuilder->andWhere("$baseModelAlias.id is null");
            return true;
        }

        if ($authAccount->isAdminWithAccessToAllResources()) {
            return true;
        }

        // Join through the 1:1 message to reach the conversation (the rights service joins the conversation).
        $queryBuilder->leftJoin("$baseModelAlias.aiConversationMessage", $messageRightsAlias);
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAccountReadRights($queryBuilder, $messageRightsAlias, 'aiConversation');
    }
}
