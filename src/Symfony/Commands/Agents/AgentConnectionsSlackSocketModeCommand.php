<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Services\Connections\Adapters\Slack\SlackAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\Slack\SocketMode\SlackSocketModeRunner;
use DDD\Domain\AI\Services\Connections\AgentConnectionAdaptersService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Modules\Agents\AgentsModule;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Slack inbound by Socket Mode, only locally and on dev: a long-running process ({@see SlackSocketModeRunner}) that
 * acknowledges every envelope and hands its payload to the same dispatch as the Events API route. Needs the optional
 * WebSocket library ({@see AgentsModule::SOCKET_MODE_LIBRARY_CLASS}).
 */
#[AsCommand(
    name: 'app:agent:connections:slack-socket-mode',
    description: 'Receive Slack events by Socket Mode (local and dev only) and dispatch them like the Events API route.',
)]
class AgentConnectionsSlackSocketModeCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this->addOption('maximumMinutes', null, InputOption::VALUE_REQUIRED, 'Stop after this many minutes (0 = run until stopped)', '0');
    }

    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $agentConnectionsConfiguration = AgentConnectionsConfiguration::current();
        if (!$agentConnectionsConfiguration->isAdapterEnabled(AgentConnection::PROVIDER_SLACK)) {
            $output->writeln('<error>The Slack adapter is switched off.</error>');
            return self::FAILURE;
        }
        if ($agentConnectionsConfiguration->isProductionStage()) {
            $output->writeln('<error>Socket Mode is for local and dev only; production uses the Events API.</error>');
            return self::FAILURE;
        }
        if (!class_exists(AgentsModule::SOCKET_MODE_LIBRARY_CLASS)) {
            $output->writeln('<error>The WebSocket library (ratchet/pawl) is not installed.</error>');
            return self::FAILURE;
        }
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $slackAdapter = $agentConnectionAdaptersService->getAdapter(AgentConnection::PROVIDER_SLACK);
        if (!$slackAdapter instanceof SlackAgentConnectionAdapter) {
            $output->writeln('<error>The SLACK provider is not the Slack adapter of the package.</error>');
            return self::FAILURE;
        }
        $slackSocketModeRunner = new SlackSocketModeRunner($slackAdapter, $output);
        $slackSocketModeRunner->run((int)$input->getOption('maximumMinutes'));
        $output->writeln('Socket Mode stopped.');
        return self::SUCCESS;
    }
}
