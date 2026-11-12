<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Listeners\Conversations;

use DDD\Domain\AI\Services\SystemPrompts\AgentBaseSystemPromptCacheService;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Event\TransactionCommitEventArgs;
use Doctrine\DBAL\Event\TransactionRollBackEventArgs;
use Throwable;

/**
 * Renews the base system prompt's inputs versions that a write bumped inside an open database transaction, once that
 * transaction ended ({@see AgentBaseSystemPromptCacheService::bumpInputsVersionsWaitingForTheTransactionEnd()}) — the
 * DBAL connection's commit and rollback events. A rollback renews them too: an extra rebuild costs less than a stale
 * prompt. Never throws into the commit.
 */
class AgentBaseSystemPromptTransactionEndListener
{
    public function onTransactionCommit(TransactionCommitEventArgs $transactionCommitEventArgs): void
    {
        $this->bumpInputsVersionsAfterTheTransactionEnd($transactionCommitEventArgs->getConnection());
    }

    public function onTransactionRollBack(TransactionRollBackEventArgs $transactionRollBackEventArgs): void
    {
        $this->bumpInputsVersionsAfterTheTransactionEnd($transactionRollBackEventArgs->getConnection());
    }

    protected function bumpInputsVersionsAfterTheTransactionEnd(Connection $connection): void
    {
        try {
            /** @var AgentBaseSystemPromptCacheService $agentBaseSystemPromptCacheService */
            $agentBaseSystemPromptCacheService = DDDService::instance()->getService(AgentBaseSystemPromptCacheService::class);
            $agentBaseSystemPromptCacheService->bumpInputsVersionsWaitingForTheTransactionEnd($connection);
        } catch (Throwable) {
            // the prompt's TTL bounds a missed bump
        }
    }
}
