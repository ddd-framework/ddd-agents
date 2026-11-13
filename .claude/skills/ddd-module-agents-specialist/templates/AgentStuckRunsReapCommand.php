<?php
// placeholder {{NAMESPACE}} sample: App\Symfony\Commands\Agents
// placeholder {{COMMAND_NAME}} sample: agent:runs:reap

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\MessageHandlers\AIConversationResumeMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Host duty: reap the runs whose worker died (every five minutes).
 *
 * 0. A run stuck past the revival window is expired to FAILED (its owed report then becomes deliverable by the
 *    owed-work recovery). 1. A run stuck RUNNING or AWAITING_DELEGATES past the hung threshold gets a fresh resume;
 *    the worker's claim takes the stale row over. 2. A delegation batch past its deadline is timed out and its parent
 *    woken. 3. A purposed conversation whose goal was reached but that was never closed is settled. 4. A parent whose
 *    settled batch was never folded is woken. 5. A sub-agent run end whose notification was lost is notified again.
 *
 * Account: every dispatch and every settle runs as the conversation's own account (for a batch: its parent's); a row
 * without a resolvable account is skipped. Usage envelope: none here. The dispatched resumes open theirs in the
 * worker; the settle of a purposed conversation calls no model on its own.
 */
#[AsCommand(name: '{{COMMAND_NAME}}', description: 'Wake or expire agent runs whose worker died, time out stale delegation batches.')]
class AgentStuckRunsReapCommand extends Command
{
    /** @var int How long a purposed conversation must be idle before the settle pass retries its close */
    public const int PURPOSE_SETTLE_QUIET_SECONDS = 600;

    protected function configure(): void
    {
        $this->addOption('hung-after', null, InputOption::VALUE_OPTIONAL, 'Seconds without a heartbeat before a run counts as hung', (string)AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS);
        $this->addOption('revive-max-age', null, InputOption::VALUE_OPTIONAL, 'Seconds after which a stuck run is expired instead of revived', (string)AIConversationsService::RUN_REVIVE_MAX_AGE_SECONDS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hungAfterSeconds = (int)$input->getOption('hung-after') ?: AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS;
        $reviveMaxAgeSeconds = (int)$input->getOption('revive-max-age') ?: AIConversationsService::RUN_REVIVE_MAX_AGE_SECONDS;
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        /** @var AIConversationDelegationBatchesService $batchesService */
        $batchesService = DDDService::instance()->getService(AIConversationDelegationBatchesService::class);
        $previousAccount = AuthService::instance()->getAccount();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            foreach ($aIConversationsService->expireStuckConversationsPastMaxAge($reviveMaxAgeSeconds) as $expiredConversationId) {
                $output->writeln("Conversation {$expiredConversationId}: expired to FAILED");
            }
            foreach ($aIConversationsService->findStuckConversationIds($hungAfterSeconds, $reviveMaxAgeSeconds) as $stuckConversationId) {
                $this->resumeAsOwnAccount($stuckConversationId, $output);
            }
            foreach ($batchesService->findTimedOutUnfinishedBatchIds() as $timedOutBatchId) {
                try {
                    $parentConversationId = $batchesService->find($timedOutBatchId)?->parentConversationId ?? null;
                    if ($parentConversationId === null || !$this->actAsAccountOfConversation((int)$parentConversationId)) {
                        continue;
                    }
                    $wokenParentConversationId = $batchesService->timeoutBatch($timedOutBatchId);
                    if ($wokenParentConversationId !== null) {
                        $this->resumeAsOwnAccount($wokenParentConversationId, $output);
                    }
                } catch (Throwable $batchError) {
                    $output->writeln("<error>Batch {$timedOutBatchId}: {$batchError->getMessage()}</error>");
                }
            }
            foreach (AIConversationPurpose::getPurposeNamesClosedWithoutAnOwnerTurn() as $purposeName) {
                foreach ($aIConversationsService->findQuietIdlePurposedConversationIds($purposeName, static::PURPOSE_SETTLE_QUIET_SECONDS) as $purposedConversationId) {
                    $this->settleAsOwnAccount($purposedConversationId, $output);
                }
            }
            foreach ($batchesService->findParentConversationIdsWithUnreadChildResults() as $unfoldedParentConversationId) {
                $this->resumeAsOwnAccount($unfoldedParentConversationId, $output);
            }
            /** @var AIConversationDelegationNotificationsService $notificationsService */
            $notificationsService = DDDService::instance()->getService(AIConversationDelegationNotificationsService::class);
            foreach ($batchesService->findEndedUnreportedSubAgentRunDeadlineIds() as $deadlineId) {
                try {
                    $parentConversationId = $batchesService->find($deadlineId)?->parentConversationId ?? null;
                    if ($parentConversationId !== null && $this->actAsAccountOfConversation((int)$parentConversationId)) {
                        $notificationsService->notifyParentOfSubAgentRunEndOfDeadline($deadlineId);
                    }
                } catch (Throwable $notificationError) {
                    $output->writeln("<error>Deadline {$deadlineId}: {$notificationError->getMessage()}</error>");
                }
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            AuthService::instance()->setAccount($previousAccount);
        }
        return Command::SUCCESS;
    }

    /** Dispatches a fresh resume of `$conversationId` under its own account (the message carries the account). */
    protected function resumeAsOwnAccount(int $conversationId, OutputInterface $output): void
    {
        try {
            if (!$this->actAsAccountOfConversation($conversationId)) {
                $output->writeln("Conversation {$conversationId}: no account, skipped");
                return;
            }
            (new AIConversationResumeMessage($conversationId))->dispatch();
            $output->writeln("Conversation {$conversationId}: resume dispatched");
        } catch (Throwable $resumeError) {
            $output->writeln("<error>Conversation {$conversationId}: {$resumeError->getMessage()}</error>");
        }
    }

    /** Runs the close path of a reached purpose goal for `$conversationId` under its own account. */
    protected function settleAsOwnAccount(int $conversationId, OutputInterface $output): void
    {
        try {
            $conversation = $this->findConversation($conversationId);
            if ($conversation === null || !$this->actAsAccount($conversation->account)) {
                return;
            }
            /** @var AIConversationAgentService $aIConversationAgentService */
            $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
            $aIConversationAgentService->settlePurposeConversationIfGoalReached($conversation);
            $output->writeln("Conversation {$conversationId}: " . ($conversation->isClosed() ? 'settled and closed' : 'left open'));
        } catch (Throwable $settleError) {
            $output->writeln("<error>Conversation {$conversationId}: {$settleError->getMessage()}</error>");
        }
    }

    protected function actAsAccountOfConversation(int $conversationId): bool
    {
        $conversation = $this->findConversation($conversationId);
        return $conversation !== null && $this->actAsAccount($conversation->account);
    }

    /** Acts as `$account` from now on; false (and nothing changed) when there is none. */
    protected function actAsAccount(?Account $account): bool
    {
        if ($account === null) {
            return false;
        }
        AuthService::instance()->setAccount($account);
        return true;
    }

    protected function findConversation(int $conversationId): ?AIConversation
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $conversation = $aIConversationsService->find($conversationId, useEntityRegistrCache: false);
        return $conversation instanceof AIConversation ? $conversation : null;
    }
}
