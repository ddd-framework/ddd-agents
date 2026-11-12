<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Presentation\Mcp\Client\AI\AgentMandateToolCallsMCPPackage;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMandatesMcpResponseDto;

/**
 * The mandate tools of an application: its own package id, its own request class, its own argument for the space
 * (`shopId`) — on top of what the package's mandate tools do.
 */
class ProbeShopMandatesMCPPackage extends AgentMandateToolCallsMCPPackage
{
    use RecordsAgentSpaceLookupOfMandateCallTrait;

    public static ?string $name = 'shop.mandates';

    /**
     * Lists the customer's mandates.
     *
     * @example {"shopId": 31}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    public function listMandates(
        ProbeShopMandateListRequestDto $requestDto,
        AgentMandatesService $agentMandatesService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentMandatesMcpResponseDto {
        return $this->executeListMandatesCall(['shopId' => $requestDto->shopId], $agentMandatesService, $agentToolCallProvenance);
    }
}
