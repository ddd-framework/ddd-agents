<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections;

use DDD\Domain\AI\Services\Connections\AgentConnectionReach;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnections;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Services\Connections\Adapters\AgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionAdaptersService;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperation;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperationsService;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionResultMcpResponseDto;
use Throwable;

/**
 * The common part of the connection tool packages: available while AgentConnections is switched on; every call works
 * for the account of the conversation (else the signed-in account), resolves the connection the call names among the
 * connections that account may use in this space, and goes through {@see AgentConnectionOperationsService} (level,
 * stage guard, limits, approval, audit). A connection is never created by a tool.
 */
abstract class AgentConnectionsBaseMCPPackage extends MCPPackage
{
    /** The capability of the package; empty = all */
    public const string CAPABILITY = '';

    public static function isAvailable(): bool
    {
        try {
            $agentConnectionsConfiguration = AgentConnectionsConfiguration::current();
            if (!$agentConnectionsConfiguration->isEnabled()) {
                return false;
            }
            if (static::CAPABILITY === '') {
                return $agentConnectionsConfiguration->getEnabledProviders() !== [];
            }
            /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
            $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
            foreach ($agentConnectionsConfiguration->getEnabledProviders() as $provider) {
                if ($agentConnectionAdaptersService->getAdapter($provider)->supportsCapability(static::CAPABILITY)) {
                    return true;
                }
            }
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /** @throws BadRequestException */
    protected function findActingAccountId(AgentToolCallProvenance $callProvenance): int
    {
        $accountId = $callProvenance->getConversationOwnerAccountIdOrAuthAccountId(AuthService::instance()->getAccount()?->id !== null ? (int)AuthService::instance()->getAccount()->id : null);
        if ($accountId === null) {
            throw new BadRequestException('No account for this call.');
        }
        return $accountId;
    }

    protected function findUsableConnections(AgentToolCallProvenance $callProvenance): AgentConnections
    {
        return $this->getAgentConnectionsService()->findUsableConnectionsOfAccount($this->findActingAccountId($callProvenance), AgentConnectionReach::ofCall($callProvenance));
    }

    /** @throws AgentConnectionRefusedException */
    protected function resolveConnection(AgentToolCallProvenance $callProvenance, ?string $connectionReference, string $capability = ''): AgentConnection
    {
        return $this->getAgentConnectionsService()->resolveConnectionForCapability($this->findUsableConnections($callProvenance), $capability !== '' ? $capability : static::CAPABILITY, $connectionReference);
    }

    protected function createOperation(AgentConnection $agentConnection, string $operation, string $toolName, AgentToolCallProvenance $callProvenance, string $capability = ''): AgentConnectionOperation
    {
        return AgentConnectionOperation::create($agentConnection, $capability !== '' ? $capability : static::CAPABILITY, $operation, $toolName, $this->findActingAccountId($callProvenance), $callProvenance);
    }

    /**
     * @template T of AgentConnectionAdapter
     * @param class-string<T> $capabilityInterface
     * @return T
     * @throws AgentConnectionRefusedException
     */
    /**
     * The allowed values of one boundary kind (LABEL, CALENDAR, CHANNEL, FOLDER) for the acting account's reach:
     * null = no limit, [] = nothing allowed (fail closed).
     *
     * @return string[]|null
     */
    protected function findAllowedBoundaryValues(AgentConnection $agentConnection, AgentToolCallProvenance $callProvenance, string $boundaryKind): ?array
    {
        $agentConnectionAccess = $this->getAgentConnectionsService()->getAccessOfAccount($agentConnection, $this->findActingAccountId($callProvenance), AgentConnectionReach::ofCall($callProvenance));
        if ($agentConnectionAccess === null) {
            return [];
        }
        return $agentConnectionAccess->getAllowedBoundaryValues($agentConnection, $boundaryKind);
    }

    /** Refuses a value outside the boundary of the acting account's reach (fail closed) */
    protected function requireWithinBoundary(AgentConnection $agentConnection, AgentToolCallProvenance $callProvenance, string $boundaryKind, string $value): void
    {
        $allowedValues = $this->findAllowedBoundaryValues($agentConnection, $callProvenance, $boundaryKind);
        if ($allowedValues !== null && !in_array($value, $allowedValues, true)) {
            throw new AgentConnectionRefusedException("\"$value\" is outside what \"{$agentConnection->getLabel()}\" is released for ($boundaryKind: " . ($allowedValues === [] ? 'nothing' : implode(', ', $allowedValues)) . ').');
        }
    }

    protected function getCapabilityAdapter(AgentConnection $agentConnection, string $capabilityInterface): object
    {
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $agentConnectionAdapter = $agentConnectionAdaptersService->getAdapterOfConnection($agentConnection);
        if (!$agentConnectionAdapter instanceof $capabilityInterface) {
            throw new AgentConnectionRefusedException("The connection \"{$agentConnection->getLabel()}\" cannot do this.");
        }
        return $agentConnectionAdapter;
    }

    protected function newResponse(AgentConnection $agentConnection): AgentConnectionResultMcpResponseDto
    {
        $responseDto = new AgentConnectionResultMcpResponseDto();
        $responseDto->connection = $agentConnection->getLabel();
        $responseDto->connectionId = (int)$agentConnection->id;
        return $responseDto;
    }

    protected function getOperationsService(): AgentConnectionOperationsService
    {
        /** @var AgentConnectionOperationsService $agentConnectionOperationsService */
        $agentConnectionOperationsService = DDDService::instance()->getService(AgentConnectionOperationsService::class);
        return $agentConnectionOperationsService;
    }

    protected function getAgentConnectionsService(): AgentConnectionsService
    {
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        return $agentConnectionsService;
    }
}
