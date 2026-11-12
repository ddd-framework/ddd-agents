<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Rights;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\Service;

/**
 * The read rights of the agent's tables, by account: the ONE rule every core DB repo's `applyReadRightsQuery()` calls
 * (each repo keeps its own `if (!self::$applyRightsRestrictions) return false;` first). A row is readable when its
 * account (the row's own `accountId`, or that of its conversation) is the authenticated account; an admin with access
 * to all resources reads everything; nobody authenticated reads nothing.
 *
 * An application with account hierarchies (resellers, parent and child accounts) puts a same-path subclass under its own
 * root namespace and overrides {@see applyNonAdminAccountCondition()}; the repos resolve this service only through
 * `DDDService::instance()->getService(AgentAccountRightsService::class)`.
 *
 * Both methods return true whenever they added a condition or decided "all rows" for an admin — the expand merge of the
 * DDD rights system merges the conditions only on true.
 */
class AgentAccountRightsService extends Service
{
    /**
     * Restricts `$queryBuilder` to the rows the authenticated account may read.
     *
     * - no authenticated account → no row (`<alias>.id is null`);
     * - {@see Account::isAdminWithAccessToAllResources()} → every row, no condition and no join;
     * - otherwise → {@see applyNonAdminAccountCondition()} on the account id of the row: `<alias>.<accountIdProperty>`
     *   when `$conversationRelation` is null (conversations, schedules), else the `accountId` of the related
     *   conversation, joined as `<alias>_<conversationRelation>_rights` (only in this branch).
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $baseModelAlias the repo's base model alias
     * @param string|null $conversationRelation the model relation to the owning conversation (e.g. `aiConversation`); null = the row carries the account id itself
     * @param string $accountIdProperty the account id property of the row (used when `$conversationRelation` is null)
     * @return bool always true (conditions were added, or the admin reads every row)
     */
    public function applyAccountReadRights(
        DoctrineQueryBuilder &$queryBuilder,
        string $baseModelAlias,
        ?string $conversationRelation = null,
        string $accountIdProperty = 'accountId'
    ): bool {
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount instanceof Account) {
            $queryBuilder->andWhere("$baseModelAlias.id is null");
            return true;
        }
        if ($authAccount->isAdminWithAccessToAllResources()) {
            return true;
        }
        $accountIdExpression = $this->joinAccountIdExpression($queryBuilder, $baseModelAlias, $conversationRelation, $accountIdProperty);
        $this->applyNonAdminAccountCondition($queryBuilder, $authAccount, $accountIdExpression);
        return true;
    }

    /**
     * Restricts `$queryBuilder` to admins with access to all resources: an admin reads every row, anyone else none.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $baseModelAlias
     * @return bool always true
     */
    public function applyAdminOnlyReadRights(DoctrineQueryBuilder &$queryBuilder, string $baseModelAlias): bool
    {
        $authAccount = AuthService::instance()->getAccount();
        if ($authAccount instanceof Account && $authAccount->isAdminWithAccessToAllResources()) {
            return true;
        }
        $queryBuilder->andWhere("$baseModelAlias.id is null");
        return true;
    }

    /**
     * The DQL expression of the row's account id; joins the owning conversation as `<alias>_<relation>_rights` when the
     * account id sits on it.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $baseModelAlias
     * @param string|null $conversationRelation
     * @param string $accountIdProperty
     * @return string e.g. `m_aiConversation_rights.accountId`
     */
    protected function joinAccountIdExpression(
        DoctrineQueryBuilder &$queryBuilder,
        string $baseModelAlias,
        ?string $conversationRelation,
        string $accountIdProperty
    ): string {
        if ($conversationRelation === null) {
            return "$baseModelAlias.$accountIdProperty";
        }
        $conversationRightsAlias = "{$baseModelAlias}_{$conversationRelation}_rights";
        $queryBuilder->leftJoin("$baseModelAlias.$conversationRelation", $conversationRightsAlias);
        return "$conversationRightsAlias.accountId";
    }

    /**
     * The condition for an authenticated account that is no admin: the row's account is the authenticated account
     * (parameter `rights_user_id`). An application's subclass adds its hierarchy branches here.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param Account $authAccount
     * @param string $accountIdExpression the DQL expression of the row's account id
     * @return void
     */
    protected function applyNonAdminAccountCondition(DoctrineQueryBuilder &$queryBuilder, Account $authAccount, string $accountIdExpression): void
    {
        $queryBuilder
            ->andWhere("$accountIdExpression = :rights_user_id")
            ->setParameter('rights_user_id', $authAccount->id);
    }
}
