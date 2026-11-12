<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolExecutionTimeout;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMandateMcpResponseDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMandatesMcpResponseDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\MandateGrantRequestDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\MandateListRequestDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\MandateRevokeRequestDto;

/**
 * MANDATES — the customer's standing authorizations for consequential outward agent actions: the package's own three
 * tools (grant from the customer's own verified statement, list, revoke). A call names the space by `agentSpaceId`;
 * the texts name no action class of an application — the classes a mandate can be granted for are the application's
 * ({@see \DDD\Domain\AI\Entities\Mandates\AgentMandate::getActionClasses()}). What the tools do, and the rules
 * of the grant, are in {@see AgentMandateToolCallsMCPPackage}; an application whose tools take an argument of its own
 * for the space, or whose texts list its action classes, derives its package from that class instead of this one.
 */
class AgentMandatesMCPPackage extends AgentMandateToolCallsMCPPackage
{
    public static ?string $name = 'agent.mandates';

    public static ?string $description = 'Grant (from the customer\'s own verified statement), list and revoke the customer\'s standing mandates — the authorizations the assistant needs for consequential outward actions.';

    /**
     * Grants a mandate from the customer's OWN statement in this conversation. Call it ONLY after the customer
     * clearly asked for or agreed to the action class — the server verifies the grant against the actual dialogue
     * (you pass no transcript; forging is impossible) and REJECTS it with a reason when consent is missing,
     * ambiguous, or quantity-based (a number of items instead of a time span → offer a time window or unlimited
     * instead). Scope: ONE_INSTRUCTION = every call the ONE current customer request needs through the tool first
     * used under it (and the tools of the same intent) — a different action of the class needs its own consent;
     * TIME_WINDOW = until validUntil; UNLIMITED = until revoked (the customer grants it from now on).
     *
     * @example {"actionClass": "STANDING_AUTOMATION", "scopeType": "UNLIMITED"}
     * @example {"actionClass": "STANDING_AUTOMATION", "scopeType": "TIME_WINDOW", "validUntil": "2026-09-21 23:59:59", "constraintsNote": "only on weekdays"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE, identicalRepeatDoesNewWork: true, isOutwardAction: true)]
    #[MCPToolExecutionTimeout(100, 'The consent check is a model call of up to 45 s and runs a second time when it gave no verdict.')]
    #[MCPToolSummary('Grant a standing mandate from the customer\'s own statement in this conversation — server-verified against the real dialogue; rejected with a reason when consent is missing or quantity-based.')]
    public function grantMandate(
        MandateGrantRequestDto $requestDto,
        AgentMandatesService $agentMandatesService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentMandateMcpResponseDto {
        return $this->executeGrantMandateCall(
            [AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => $requestDto->agentSpaceId],
            $requestDto->accountWide === true,
            $requestDto->toGrantArguments(),
            $agentMandatesService,
            $agentToolCallProvenance
        );
    }

    /**
     * Lists the customer's mandates (active AND past — consumed / expired / revoked), newest first. Use it to
     * answer what the assistant may do on its own, before granting (avoid duplicates), and to get the id for a revoke.
     *
     * @example {}
     * @example {"agentSpaceId": 7}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('lists the account\'s mandates')]
    #[MCPToolSummary('List the customer\'s mandates (active and past), newest first — the source for what the assistant may do on its own and for revoke ids.')]
    public function listMandates(
        MandateListRequestDto $requestDto,
        AgentMandatesService $agentMandatesService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentMandatesMcpResponseDto {
        return $this->executeListMandatesCall(
            [AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => $requestDto->agentSpaceId],
            $agentMandatesService,
            $agentToolCallProvenance
        );
    }

    /**
     * Revokes a mandate IMMEDIATELY. Deliberately un-checked: any customer utterance that they no longer want
     * the standing authorization suffices (a plain stop, or a wish that the action no longer runs on its own) —
     * when in doubt, revoke. Get the id from `listMandates`.
     *
     * @example {"mandateId": 17}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::DELETE)]
    #[MCPToolSummary('Revoke a mandate immediately — any customer utterance suffices; when in doubt, revoke. Id from listMandates.')]
    public function revokeMandate(
        MandateRevokeRequestDto $requestDto,
        AgentMandatesService $agentMandatesService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentMandateMcpResponseDto {
        return $this->executeRevokeMandateCall($requestDto->mandateId, $agentMandatesService, $agentToolCallProvenance);
    }
}
