<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Mandates\AgentMandateGrantArguments;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMandateMcpResponseDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMandatesMcpResponseDto;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * What the three mandate tools DO — grant from the customer's own verified statement, list, revoke — without
 * declaring a tool. A tool is what the model sees: its name, its texts, its examples and its arguments. Those belong
 * to whoever declares the tool:
 *
 * - {@see AgentMandatesMCPPackage} declares the package's own three tools: a call names the space by `agentSpaceId`,
 *   the texts name no action class of an application.
 * - An application whose tools name the space by an argument of its own (the id of its own entity), or whose texts
 *   list its action classes, derives its package from THIS class and declares its three tools with its own request
 *   classes. Each tool hands the arguments that name the space to the method here, by argument name;
 *   {@see AgentSpacesService::findAgentSpaceOfToolCall()} — which the application overrides for its argument —
 *   answers the space. A tool method cannot be overridden with another request class (the executor builds the
 *   request object from the declared parameter type), which is why the tools sit one class below the work.
 *
 * The grant is UNFORGEABLE by construction: a tool passes NO transcript — the server pulls the dialogue window from
 * the conversation's own messages and the approval check
 * ({@see \DDD\Domain\AI\Repo\Argus\Mandates\ArgusAgentMandateApproval}) verifies the customer's consent against it,
 * storing verbatim quotes as evidence. Tools marked `#[MCPToolRequiresMandate]` are refused by the executor until a
 * covering mandate is ACTIVE.
 *
 * SECURITY: the owning account is ALWAYS the authenticated account. The space a call names, or the space of the
 * conversation when it names none, is resolved through {@see AgentSpacesService::findAgentSpaceOfToolCall()}: a space
 * the account may not use refuses the call, and the grant asks
 * {@see AgentSpacesService::requireAgentSpaceUsableByAccount()} once more before a mandate is stored with a space. A
 * mandate WITHOUT a space is account-wide — it is granted only when the call asks for it or in a conversation that
 * works for no space. Revocation is deliberately un-checked (safety-directed — any customer utterance suffices to
 * revoke).
 */
abstract class AgentMandateToolCallsMCPPackage extends MCPPackage
{
    /**
     * Grants a mandate from the customer's own statement in the conversation the call runs in.
     *
     * @param array<string, mixed> $toolArgumentsNamingTheAgentSpace the arguments of the call that name the space the
     *                                                               mandate is bound to, by argument name; none
     *                                                               named = the space of the conversation
     * @param bool $isAccountWide true = the mandate is bound to no space
     * @param AgentMandateGrantArguments $agentMandateGrantArguments
     * @param AgentMandatesService $agentMandatesService
     * @param AgentToolCallProvenance $agentToolCallProvenance
     * @return AgentMandateMcpResponseDto
     * @throws BadRequestException when the call runs outside a conversation
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function executeGrantMandateCall(
        array $toolArgumentsNamingTheAgentSpace,
        bool $isAccountWide,
        AgentMandateGrantArguments $agentMandateGrantArguments,
        AgentMandatesService $agentMandatesService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentMandateMcpResponseDto {
        $agentMandatesService->throwErrors = true;
        $accountId = $this->requireAuthAccountId();
        if ($agentToolCallProvenance->conversationMessages === null) {
            throw new BadRequestException(
                'grantMandate only works inside an agent conversation — the grant is verified against the live dialogue.'
            );
        }
        // null = an ACCOUNT-WIDE mandate: asked for by the call, or granted in a conversation that works for no space
        // and names none. Every other grant is bound to the named space, else to the conversation's.
        $agentSpaceOfMandate = $isAccountWide
            ? null
            : $this->findAgentSpaceOfMandateCall($toolArgumentsNamingTheAgentSpace, $agentToolCallProvenance);
        $agentMandateGrantOutcome = $agentMandatesService->grantMandateFromDialogue(
            $accountId,
            $agentSpaceOfMandate,
            $agentMandateGrantArguments,
            $agentToolCallProvenance->conversationMessages,
            $agentToolCallProvenance->sourceUserMessage,
            // The ROOT conversation id: the ONE_INSTRUCTION request predicate compares against the root thread, so a
            // grant issued while delegation children exist still binds to the thread the customer talks in.
            $agentToolCallProvenance->rootConversationId ?? $agentToolCallProvenance->conversationId,
            $agentToolCallProvenance->getTimezone(),
        );
        return AgentMandateMcpResponseDto::forGrantOutcome($agentMandateGrantOutcome, $agentToolCallProvenance->getTimezone());
    }

    /**
     * Lists the mandates of the authenticated account: the view of the space the call names (else of the
     * conversation's space) — its own mandates and the account-wide ones; without a space (a conversation that works
     * for none, a call over HTTP that names none) every mandate of the account.
     *
     * @param array<string, mixed> $toolArgumentsNamingTheAgentSpace
     * @param AgentMandatesService $agentMandatesService
     * @param AgentToolCallProvenance $agentToolCallProvenance
     * @return AgentMandatesMcpResponseDto
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function executeListMandatesCall(
        array $toolArgumentsNamingTheAgentSpace,
        AgentMandatesService $agentMandatesService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentMandatesMcpResponseDto {
        $agentMandatesService->throwErrors = true;
        $accountId = $this->requireAuthAccountId();
        $agentSpaceOfView = $this->findAgentSpaceOfMandateCall($toolArgumentsNamingTheAgentSpace, $agentToolCallProvenance);
        $responseDto = new AgentMandatesMcpResponseDto();
        $responseDto->mandates = $agentMandatesService->findMandatesForAccount($accountId, $agentSpaceOfView);
        return $responseDto;
    }

    /**
     * Revokes a mandate of the authenticated account at once.
     *
     * @param int $mandateId
     * @param AgentMandatesService $agentMandatesService
     * @param AgentToolCallProvenance $agentToolCallProvenance
     * @return AgentMandateMcpResponseDto
     * @throws ForbiddenException
     * @throws NotFoundException when the account has no such mandate
     */
    protected function executeRevokeMandateCall(
        int $mandateId,
        AgentMandatesService $agentMandatesService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentMandateMcpResponseDto {
        $agentMandatesService->throwErrors = true;
        $accountId = $this->requireAuthAccountId();
        // find() is rights-scoped (applyReadRightsQuery: own account only) and throws on miss (throwErrors)
        $revokedAgentMandate = $agentMandatesService->revokeMandateOfAccount($accountId, $mandateId, $agentToolCallProvenance->getSourceUserMessageId());
        if ($revokedAgentMandate === null) {
            throw new NotFoundException('No such mandate for this account.');
        }
        return AgentMandateMcpResponseDto::forRevokedMandate($revokedAgentMandate);
    }

    protected function requireAuthAccountId(): int
    {
        /** @var Account|null $authAccount */
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount instanceof Account || !isset($authAccount->id)) {
            throw new ForbiddenException('No authenticated account');
        }
        return (int)$authAccount->id;
    }

    /**
     * The space a mandate call works for: the space its arguments name, else the space of the conversation the call
     * runs in ({@see AgentSpacesService::findAgentSpaceOfToolCall()}).
     *
     * @param array<string, mixed> $toolArgumentsNamingTheAgentSpace by argument name; an empty value names none
     * @param AgentToolCallProvenance $agentToolCallProvenance empty for a call over HTTP (no conversation)
     * @return AgentSpace|null null = no space is named and the conversation works for none (or there is none)
     * @throws BadRequestException
     * @throws ForbiddenException when the call names a space that does not exist or that the account may not use
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findAgentSpaceOfMandateCall(array $toolArgumentsNamingTheAgentSpace, AgentToolCallProvenance $agentToolCallProvenance): ?AgentSpace
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->findAgentSpaceOfToolCall($toolArgumentsNamingTheAgentSpace, $agentToolCallProvenance);
    }
}
