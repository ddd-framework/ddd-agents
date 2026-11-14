<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Stamps every conversation that carries no environment with the given one. A conversation is stamped with the
 * environment that created it ({@see AIConversationsService::update()}); a database with conversations written before
 * that stamp existed has rows without one, which
 * {@see \DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations::buildEnvironmentPredicate()} lets the production
 * environment adopt. Stamping them once with the environment that drove them closes that adoption.
 *
 * Batched writes through the service, no entity load, no rights (the rows belong to every account).
 *
 *   php bin/console app:ai-conversation:backfill-environment --dryRun
 *   php bin/console app:ai-conversation:backfill-environment --environment=prod
 */
#[AsCommand(
    name: 'app:ai-conversation:backfill-environment',
    description: 'Stamp every conversation without an environment with the given one (default prod).',
)]
class AIConversationBackfillEnvironmentCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'environment',
            null,
            InputOption::VALUE_OPTIONAL,
            'The environment name written onto unstamped conversations',
            AIConversationsService::ENVIRONMENT_PRODUCTION
        );
        $this->addOption('dryRun', null, InputOption::VALUE_NONE, 'Count the unstamped conversations without writing');
    }

    /**
     * Counts (dry run) or stamps the conversations without an environment.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(3600);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $environment = trim((string)$input->getOption('environment'));
        if ($environment === '') {
            $symfonyStyle->error('--environment must not be empty');
            return Command::FAILURE;
        }
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        try {
            if ((bool)$input->getOption('dryRun')) {
                $unstampedCount = $aIConversationsService->countConversationsWithoutEnvironment();
                $symfonyStyle->note("{$unstampedCount} conversation(s) carry no environment stamp; would stamp them '{$environment}'.");
                return Command::SUCCESS;
            }
            $stampedRowCount = $aIConversationsService->backfillEnvironmentOnUnstampedConversations($environment);
            $symfonyStyle->success("Stamped {$stampedRowCount} conversation(s) with environment '{$environment}'.");
            return Command::SUCCESS;
        } catch (Throwable $backfillError) {
            $symfonyStyle->error($backfillError->getMessage());
            return Command::FAILURE;
        }
    }
}
