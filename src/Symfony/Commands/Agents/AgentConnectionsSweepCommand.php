<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Services\Connections\AgentConnectionApprovalRequestsService;
use DDD\Domain\AI\Services\Connections\AgentConnectionTriggersRunService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The sweep of AgentConnections, for the host's cron list (every few minutes): polls the mailboxes of active mail
 * triggers and starts their runs, and expires open approval requests past their expiry. Does nothing while AgentConnections is switched off.
 */
#[AsCommand(
    name: 'app:agent:connections:sweep',
    description: 'Poll the mailboxes of active AgentConnection triggers and start their runs.',
)]
class AgentConnectionsSweepCommand extends AgentConsoleCommand
{
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        if (!AgentConnectionsConfiguration::current()->isEnabled()) {
            $output->writeln('AgentConnections is switched off.');
            return self::SUCCESS;
        }
        /** @var AgentConnectionTriggersRunService $agentConnectionTriggersRunService */
        $agentConnectionTriggersRunService = DDDService::instance()->getService(AgentConnectionTriggersRunService::class);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            foreach ($agentConnectionTriggersRunService->pollMailTriggers() as $reportLine) {
                $output->writeln($reportLine);
            }
            /** @var AgentConnectionApprovalRequestsService $agentConnectionApprovalRequestsService */
            $agentConnectionApprovalRequestsService = DDDService::instance()->getService(AgentConnectionApprovalRequestsService::class);
            $output->writeln('expired approval requests: ' . $agentConnectionApprovalRequestsService->expireOpenRequests());
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return self::SUCCESS;
    }
}
