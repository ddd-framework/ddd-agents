<?php
// placeholder {{NAMESPACE}} sample: App\Symfony\Commands\Agents
// placeholder {{COMMAND_NAME}} sample: agent:conversations:finalize-idle

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\MessageHandlers\AIConversationFinalizeMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Host duty: mark and reduce the finalized conversations (every five minutes; both steps are cheap when idle).
 *
 * Step 1, mark: every conversation idle past the window (no new owner message that owes a turn) gets its finalize
 * stamp, in one indexed UPDATE. A later owner message clears the stamp and the cycle starts again.
 * Step 2, reduce: every finalized conversation that still owes its completion compaction gets one finalize job on the
 * compaction transport. The worker builds the completion tree and the final display summary, stamps the completion,
 * and dispatches the quality scorecard. A failed job leaves no stamp, so the next pass selects the row again.
 *
 * Account: the mark runs without one; each job is dispatched as the conversation's own account (the message carries
 * it to the worker). Usage envelope: none here. The finalize job's message declares its envelope scope, which the
 * host's envelope middleware opens around the handler; the handler lifts the budget for this maintenance work.
 */
#[AsCommand(name: '{{COMMAND_NAME}}', description: 'Mark idle agent conversations finalized and dispatch their completion compaction.')]
class AgentFinalizedConversationsReduceCommand extends Command
{
    public const int DEFAULT_IDLE_MINUTES = 60;

    public const int DEFAULT_BATCH_SIZE = 50;

    protected function configure(): void
    {
        $this->addOption('idle-minutes', null, InputOption::VALUE_OPTIONAL, 'Minutes without an owner message before a conversation is finalized', (string)static::DEFAULT_IDLE_MINUTES);
        $this->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'Finalize jobs per pass', (string)static::DEFAULT_BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $idleMinutes = (int)$input->getOption('idle-minutes') ?: static::DEFAULT_IDLE_MINUTES;
        $batchSize = (int)$input->getOption('batch') ?: static::DEFAULT_BATCH_SIZE;
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $markedCount = $aIConversationsService->markIdleConversationsFinalized($idleMinutes);
        $output->writeln("Marked {$markedCount} conversation(s) finalized.");

        $previousAccount = AuthService::instance()->getAccount();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $dispatchedCount = 0;
        try {
            foreach ($aIConversationsService->findFinalizedConversationIdsOwingCompletionCompaction($batchSize) as $conversationId) {
                try {
                    $conversation = $aIConversationsService->find($conversationId, useEntityRegistrCache: false);
                    if (!$conversation instanceof AIConversation) {
                        continue;
                    }
                    $conversationAccount = $conversation->account;
                    if ($conversationAccount === null) {
                        continue;
                    }
                    AuthService::instance()->setAccount($conversationAccount);
                    (new AIConversationFinalizeMessage($conversationId))->dispatch();
                    $dispatchedCount++;
                } catch (Throwable $dispatchError) {
                    $output->writeln("<error>Conversation {$conversationId}: {$dispatchError->getMessage()}</error>");
                }
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            AuthService::instance()->setAccount($previousAccount);
        }
        $output->writeln("Dispatched {$dispatchedCount} finalize job(s).");
        return Command::SUCCESS;
    }
}
