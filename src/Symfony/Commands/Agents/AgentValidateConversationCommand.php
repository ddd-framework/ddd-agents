<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationIntegrityReport;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Validates the integrity of persisted conversations ({@see AIConversationIntegrityReport}): preloaded skills on
 * their anchor, no reload of an admitted skill in the same turn, final tool calls, unique tool-call ids, contiguous
 * indexes. Read-only, rights lifted; exits 1 on any violation.
 *
 *   php bin/console app:ai-agent:validate-conversation 631846 631850
 */
#[AsCommand(
    name: 'app:ai-agent:validate-conversation',
    description: 'Validate persisted agent conversations (preloads on their anchor, no same-turn reload, final tool calls, unique ids, contiguous indexes); exit 1 on a violation.',
)]
class AgentValidateConversationCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this->addArgument('conversationIds', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'One or more conversation ids');
    }

    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Prints one report line per conversation and its violations.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int `Command::FAILURE` when a conversation is missing or has a violation
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);

        $anyViolation = false;
        foreach ((array)$input->getArgument('conversationIds') as $conversationId) {
            $conversation = $aIConversationsService->find((int)$conversationId, false);
            if (!$conversation instanceof AIConversation) {
                $output->writeln("<error>{$conversationId}: no such conversation</error>");
                $anyViolation = true;
                continue;
            }
            $integrityReport = AIConversationIntegrityReport::buildFromRecords(
                $aIConversationMessagesService->findIntegrityProjectionRows($conversation),
                $aIConversationTurnEventsService->findIntegrityRecordsOfConversation($conversation),
                $conversation->runState
            );
            $output->writeln(sprintf(
                '%d: %d rows, %d admitted preloads (%d on their anchor, %d reloaded), %s',
                (int)$conversationId,
                $integrityReport->rowCount,
                count($integrityReport->admittedPreloads),
                $integrityReport->countAdmittedPreloadsOnTheirAnchor(),
                $integrityReport->countAdmittedPreloadsLoadedAgain(),
                $integrityReport->hasViolations() ? '<error>' . count($integrityReport->violations) . ' violation(s)</error>' : '<info>OK</info>'
            ));
            foreach ($integrityReport->violations as $integrityViolation) {
                $output->writeln("  [{$integrityViolation->check}] {$integrityViolation->detail}");
            }
            $anyViolation = $anyViolation || $integrityReport->hasViolations();
        }
        return $anyViolation ? Command::FAILURE : Command::SUCCESS;
    }
}
