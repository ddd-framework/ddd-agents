<?php
// placeholder {{NAMESPACE}} sample: App\Symfony\Commands\Agents
// placeholder {{COMMAND_NAME}} sample: agent:runs:recover-owed-work

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Host duty: deliver owed run reports and resume owed turns (every five minutes).
 *
 * Pass 1, owed reports: a finished run stamped a report obligation that was never delivered. The obligation of a
 * scope that is no longer eligible is closed or deferred; every other report is delivered late as the run's final
 * chat message. A failing row is pushed to the back of the queue.
 * Pass 2, owed turns: a turn handover marker older than the quiet window, nobody claimed the turn. A conversation that
 * ended normally gets its marker cleared, every other one is resumed. A failing row restarts its quiet window.
 * Pass 3, lost resumes: an idle conversation whose newest owner message is newer than its last heartbeat and that has
 * no marker. The marker is stamped first (pass 2 owns further retries), then the turn is resumed.
 *
 * Account: every delivery and every dispatch runs as the conversation's own account (the dispatched message carries
 * it to the worker); a conversation without a resolvable account is skipped, never handled under the previous one.
 * Usage envelope: none here. The late report is a template without a model call; a resumed turn opens its envelope
 * in the worker.
 */
#[AsCommand(name: '{{COMMAND_NAME}}', description: 'Deliver owed agent run reports and resume agent turns that are still owed.')]
class AgentOwedWorkRecoveryCommand extends Command
{
    use LockableTrait;

    /** @var int The newest rows read to find the last wire message of a conversation */
    public const int NEWEST_ROWS_FOR_THE_LAST_WIRE_MESSAGE = 30;

    protected function configure(): void
    {
        $this->addOption('quiet-seconds', null, InputOption::VALUE_OPTIONAL, 'Quiet window of an owed turn', (string)AIConversationsService::AGENT_TURN_OWED_QUIET_SECONDS);
        $this->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'Candidates per pass', (string)AIConversationsService::OWED_CONVERSATIONS_BATCH_LIMIT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('A previous pass is still running.');
            return Command::SUCCESS;
        }
        $quietSeconds = (int)$input->getOption('quiet-seconds') ?: AIConversationsService::AGENT_TURN_OWED_QUIET_SECONDS;
        $batchLimit = (int)$input->getOption('batch') ?: AIConversationsService::OWED_CONVERSATIONS_BATCH_LIMIT;
        $previousAccount = AuthService::instance()->getAccount();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $this->deliverOwedReports($batchLimit, $output);
            $this->resumeOwedTurns($quietSeconds, $batchLimit, $output);
            $this->resumeLostResumes($quietSeconds, $batchLimit, $output);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            AuthService::instance()->setAccount($previousAccount);
            $this->release();
        }
        return Command::SUCCESS;
    }

    protected function deliverOwedReports(int $batchLimit, OutputInterface $output): void
    {
        $aIConversationsService = $this->conversationsService();
        /** @var AIConversationOwnerReportDeliveryService $reportDeliveryService */
        $reportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        foreach ($aIConversationsService->findConversationIdsOwingReports($batchLimit) as $conversationId) {
            $conversation = null;
            try {
                $conversation = $aIConversationsService->find($conversationId, useEntityRegistrCache: false);
                if (!$conversation instanceof AIConversation) {
                    continue;
                }
                if ($reportDeliveryService->handleReportObligationForIneligibleScope($conversation, false)) {
                    continue;
                }
                if (!$this->actAsAccount($conversation->account)) {
                    continue;
                }
                $this->agentService()->deliverOwedReportLate($conversation);
                $output->writeln("Conversation {$conversationId}: owed report delivered");
            } catch (Throwable $deliveryError) {
                $output->writeln("<error>Conversation {$conversationId}: {$deliveryError->getMessage()}</error>");
                if ($conversation instanceof AIConversation) {
                    $reportDeliveryService->deferOwedReport($conversation);
                }
            }
        }
    }

    protected function resumeOwedTurns(int $quietSeconds, int $batchLimit, OutputInterface $output): void
    {
        $aIConversationsService = $this->conversationsService();
        foreach ($aIConversationsService->findConversationIdsOwingContinuations($quietSeconds, $batchLimit) as $conversationId) {
            $conversation = null;
            try {
                $conversation = $this->findWithNewestMessages($conversationId);
                if ($conversation === null) {
                    continue;
                }
                if ($conversation->lastWireMessageIsFinalAssistantText()) {
                    $aIConversationsService->clearAgentTurnOwed($conversation);
                    continue;
                }
                if (!$this->actAsAccount($conversation->account)) {
                    continue;
                }
                $this->agentService()->resumeConversationIfReady($conversation, async: true);
                $output->writeln("Conversation {$conversationId}: owed turn resumed");
            } catch (Throwable $resumeError) {
                $output->writeln("<error>Conversation {$conversationId}: {$resumeError->getMessage()}</error>");
                if ($conversation instanceof AIConversation) {
                    $aIConversationsService->deferOwedTurn($conversation);
                }
            }
        }
    }

    protected function resumeLostResumes(int $quietSeconds, int $batchLimit, OutputInterface $output): void
    {
        $aIConversationsService = $this->conversationsService();
        /** @var AIConversationRunClaimsService $runClaimsService */
        $runClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        foreach ($aIConversationsService->findConversationIdsWithInboundLeftIdle($quietSeconds, $batchLimit) as $conversationId) {
            try {
                $conversation = $this->findWithNewestMessages($conversationId);
                if ($conversation === null || $conversation->isClosed() || $conversation->lastWireMessageIsFinalAssistantText()) {
                    continue;
                }
                if ($conversation->resumeAttempts > 0 && !$conversation->hasUnansweredInboundMessage()) {
                    continue;
                }
                if ($conversation->runState !== AIConversationRunState::IDLE || $runClaimsService->hasLiveRunClaim($conversation)) {
                    continue;
                }
                if (!$this->actAsAccount($conversation->account)) {
                    continue;
                }
                $aIConversationsService->deferOwedTurn($conversation);
                $this->agentService()->resumeConversationIfReady($conversation, async: true);
                $output->writeln("Conversation {$conversationId}: lost resume re-fired");
            } catch (Throwable $resumeError) {
                $output->writeln("<error>Conversation {$conversationId}: {$resumeError->getMessage()}</error>");
            }
        }
    }

    /** The conversation with only its newest rows loaded (enough to read its last wire message), null when gone. */
    protected function findWithNewestMessages(int $conversationId): ?AIConversation
    {
        $conversation = $this->conversationsService()->find($conversationId, useEntityRegistrCache: false);
        if (!$conversation instanceof AIConversation) {
            return null;
        }
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        $conversation->messages = $aIConversationMessagesService->findNewestMessages($conversation, static::NEWEST_ROWS_FOR_THE_LAST_WIRE_MESSAGE);
        return $conversation;
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

    protected function conversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    protected function agentService(): AIConversationAgentService
    {
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
        return $aIConversationAgentService;
    }
}
