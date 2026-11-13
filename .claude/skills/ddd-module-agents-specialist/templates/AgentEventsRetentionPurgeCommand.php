<?php
// placeholder {{NAMESPACE}} sample: App\Symfony\Commands\Agents
// placeholder {{COMMAND_NAME}} sample: agent:events:purge

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Host duty: purge the agent's event tables (daily).
 *
 * The trace events and the turn events of a run are kept for a retention period (180 days by default) and then deleted
 * in batches on their `created` index; after the turn events, the catalog snapshots no event references any more are
 * deleted (never by their own age alone: a newer event may reference an old snapshot). The package calls none of
 * these methods; without this command the three tables grow without bound.
 *
 * Account: none, the statements are raw and read no rights. Usage envelope: none, no model call.
 */
#[AsCommand(name: '{{COMMAND_NAME}}', description: 'Purge agent trace and turn events past their retention, then the unreferenced turn-event snapshots.')]
class AgentEventsRetentionPurgeCommand extends Command
{
    use LockableTrait;

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_OPTIONAL, 'Retention in days', (string)AIConversationTraceEventsService::RETENTION_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('A previous pass is still running.');
            return Command::SUCCESS;
        }
        try {
            $retentionDays = (int)$input->getOption('days') ?: AIConversationTraceEventsService::RETENTION_DAYS;
            /** @var AIConversationTraceEventsService $traceEventsService */
            $traceEventsService = DDDService::instance()->getService(AIConversationTraceEventsService::class);
            /** @var AIConversationTurnEventsService $turnEventsService */
            $turnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
            $output->writeln('Trace events deleted: ' . $traceEventsService->purgeOlderThanDays($retentionDays));
            $output->writeln('Turn events deleted: ' . $turnEventsService->purgeOlderThanDays($retentionDays));
            $output->writeln('Unreferenced snapshots deleted: ' . $turnEventsService->purgeUnreferencedSnapshots());
        } finally {
            $this->release();
        }
        return Command::SUCCESS;
    }
}
