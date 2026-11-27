<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionActionTargets;
use DDD\Domain\AI\Entities\Connections\AgentConnectionActionTarget;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionActions;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Services\DDDService;

/**
 * The audit of the connections: one append-only row per step, setting change and approval. Reads are recorded as
 * metadata only; content never.
 *
 * @method AgentConnectionAction|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionActions findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 */
class AgentConnectionActionsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionAction::class;

    public function record(
        AgentConnectionOperation $agentConnectionOperation,
        string $outcome,
        string $target,
        ?string $contentHash,
        ?string $outcomeText,
        ?int $approvalRequestId
    ): AgentConnectionAction {
        $agentConnectionAction = AgentConnectionAction::newInstance();
        $agentConnectionAction->agentConnectionId = (int)$agentConnectionOperation->agentConnection->id;
        $agentConnectionAction->capability = $agentConnectionOperation->capability;
        $agentConnectionAction->operation = $agentConnectionOperation->operation;
        $agentConnectionAction->toolName = mb_substr($agentConnectionOperation->toolName, 0, 100);
        $agentConnectionAction->aiConversationId = $agentConnectionOperation->getConversationId();
        $agentConnectionAction->actingAccountId = $agentConnectionOperation->actingAccountId;
        $agentConnectionAction->target = $target !== '' ? mb_substr($target, 0, 255) : null;
        $agentConnectionAction->contentHash = $contentHash;
        $agentConnectionAction->outcome = $outcome;
        $agentConnectionAction->outcomeText = $outcomeText !== null ? mb_substr($outcomeText, 0, 500) : null;
        $agentConnectionAction->agentConnectionApprovalRequestId = $approvalRequestId;
        $agentConnectionAction = $this->persist($agentConnectionAction);
        if ($outcome === AgentConnectionAction::OUTCOME_DONE && in_array($agentConnectionOperation->operation, AgentConnectionAction::OUTWARD_OPERATIONS, true)) {
            $this->recordTargets($agentConnectionAction, $agentConnectionOperation->recipients);
        }
        return $agentConnectionAction;
    }

    /**
     * One row per target of a carried-out outward step, in the audit's form, for the exact per-recipient count
     *
     * @param string[] $recipients
     */
    protected function recordTargets(AgentConnectionAction $agentConnectionAction, array $recipients): void
    {
        $describedTargets = [];
        foreach ($recipients as $recipient) {
            $describedTarget = mb_substr(AgentConnectionOperationsService::describeTarget($recipient), 0, 255);
            if ($describedTarget !== '') {
                $describedTargets[$describedTarget] = true;
            }
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            foreach (array_keys($describedTargets) as $describedTarget) {
                $agentConnectionActionTarget = AgentConnectionActionTarget::newInstance();
                $agentConnectionActionTarget->agentConnectionActionId = (int)$agentConnectionAction->id;
                $agentConnectionActionTarget->agentConnectionId = (int)$agentConnectionAction->agentConnectionId;
                $agentConnectionActionTarget->target = (string)$describedTarget;
                $agentConnectionActionTarget->doneAt = new DateTime();
                $agentConnectionActionTarget->insert();
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** A setting change, a connect or a disconnect by a person */
    public function recordAdministration(AgentConnection $agentConnection, string $operation, ?int $actingAccountId, string $outcomeText): AgentConnectionAction
    {
        $agentConnectionAction = AgentConnectionAction::newInstance();
        $agentConnectionAction->agentConnectionId = (int)$agentConnection->id;
        $agentConnectionAction->operation = $operation;
        $agentConnectionAction->toolName = 'admin';
        $agentConnectionAction->actingAccountId = $actingAccountId;
        $agentConnectionAction->outcome = AgentConnectionAction::OUTCOME_DONE;
        $agentConnectionAction->outcomeText = mb_substr($outcomeText, 0, 500);
        return $this->persist($agentConnectionAction);
    }

    /** Outward steps DONE to one target (exact, in the audit's form) since `$since` */
    public function countDoneToTargetSince(int $agentConnectionId, DateTime $since, string $target): int
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionActionTargets::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->select("COUNT($alias.id)")
                ->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', $agentConnectionId)
                ->andWhere("$alias.target = :target")->setParameter('target', mb_substr($target, 0, 255))
                ->andWhere("$alias.doneAt >= :since")->setParameter('since', $since);
            return (int)$queryBuilder->getQuery()->getSingleScalarResult();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** Outward steps DONE since `$since` */
    public function countOutwardDoneSince(int $agentConnectionId, DateTime $since): int
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionActions::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->select("COUNT($alias.id)")
                ->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', $agentConnectionId)
                ->andWhere("$alias.operation IN (:outwardOperations)")->setParameter('outwardOperations', AgentConnectionAction::OUTWARD_OPERATIONS)
                ->andWhere("$alias.outcome = :done")->setParameter('done', AgentConnectionAction::OUTCOME_DONE)
                ->andWhere("$alias.created >= :since")->setParameter('since', $since);
            return (int)$queryBuilder->getQuery()->getSingleScalarResult();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** The newest actions of a connection (the settings screen) */
    public function findLatestOfConnection(int $agentConnectionId, int $limit = 50): AgentConnectionActions
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionActions::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', $agentConnectionId)
                ->orderBy("$alias.id", 'DESC')->setMaxResults(max(1, $limit));
            return $repository->find($queryBuilder, false) ?? AgentConnectionActions::newInstance();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    protected function persist(AgentConnectionAction $agentConnectionAction): AgentConnectionAction
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnectionAction->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $agentConnectionAction;
    }
}
