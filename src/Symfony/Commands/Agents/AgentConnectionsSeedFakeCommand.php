<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates (or finds) a FAKE connection of an account for evals: every capability at a chosen level, its world from
 * `AI.agentConnections.adapters.FAKE.fixture`. Refused in production. The eval cases of Agent.Connections.* name the
 * connection by its name.
 */
#[AsCommand(
    name: 'app:agent:connections:seed-fake',
    description: 'Create or find a FAKE AgentConnection of an account for evals (never in production).',
)]
class AgentConnectionsSeedFakeCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this->addOption('accountId', null, InputOption::VALUE_REQUIRED, 'The account that owns the connection');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'The connection name', 'Test-Postfach');
        $this->addOption('level', null, InputOption::VALUE_REQUIRED, 'Level of every capability', AgentConnectionPermission::LEVEL_SEND_WITH_APPROVAL);
    }

    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $agentConnectionsConfiguration = AgentConnectionsConfiguration::current();
        if ($agentConnectionsConfiguration->isProductionStage() || !$agentConnectionsConfiguration->isAdapterEnabled(AgentConnection::PROVIDER_FAKE)) {
            $output->writeln('<error>FAKE connections exist only outside production with the FAKE adapter on.</error>');
            return self::FAILURE;
        }
        $accountId = (int)$input->getOption('accountId');
        $name = (string)$input->getOption('name');
        $level = (string)$input->getOption('level');
        if ($accountId <= 0 || !in_array($level, AgentConnectionPermission::LEVELS, true)) {
            $output->writeln('<error>--accountId and a valid --level are required.</error>');
            return self::FAILURE;
        }
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnection = null;
            foreach ($agentConnectionsService->findOfStage($accountId, false, AgentConnection::PROVIDER_FAKE)->getElements() as $existingConnection) {
                if ($existingConnection->displayName === $name) {
                    $agentConnection = $existingConnection;
                }
            }
            if ($agentConnection === null) {
                $agentConnection = $agentConnectionsService->createPendingConnection(AgentConnection::OWNERSHIP_ACCOUNT_GRANTED, AgentConnection::PROVIDER_FAKE, $accountId, $accountId, $name);
                $agentConnection->externalAccountId = 'fake-' . $accountId;
                $agentConnection->externalAddress = 'owner@fake.invalid';
            }
            $agentConnection->status = AgentConnection::STATUS_CONNECTED;
            $agentConnectionsService->persistIgnoringRights($agentConnection);
            $agentConnectionsService->ensurePermissions($agentConnection, AgentConnectionPermission::CAPABILITIES, $accountId);
            foreach (AgentConnectionPermission::CAPABILITIES as $capability) {
                $agentConnectionsService->setLevel($agentConnection, $capability, $level, $accountId);
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $output->writeln("FAKE connection #{$agentConnection->id} \"$name\" of account $accountId at $level.");
        return self::SUCCESS;
    }
}
