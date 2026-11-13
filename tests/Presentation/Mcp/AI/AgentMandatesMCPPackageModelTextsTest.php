<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Entities\Mandates\AgentMandateGrantOutcome;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMandateMcpResponseDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\MandateGrantRequestDto;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The texts `AgentMandatesMCPPackage` hands the model that moved into its response DTO, pinned
 * VERBATIM against the package literals before it became an adapter, copied, never retyped — the two
 * one-instruction notes name the first-use tool binding the gate enforces; and the grant
 * arguments the request DTO hands the service.
 */
class AgentMandatesMCPPackageModelTextsTest extends UnitTestCase
{
    public function testAlreadyCovered(): void
    {
        $responseDto = AgentMandateMcpResponseDto::forGrantOutcome(AgentMandateGrantOutcome::forAlreadyCovered($this->buildMandate(AgentMandate::SCOPE_ONE_INSTRUCTION)), null);
        self::assertSame(
            'Already covered — an active one-instruction mandate for this action class and THIS customer request exists (no new mandate was created). '
            . 'It covers every further call of this request through the tool it was first used with (and the tools of the same intent): '
            . 'retry the refused tool call(s) NOW and do NOT grant again.',
            $responseDto->note
        );
    }

    public function testAOneInstructionGrant(): void
    {
        $responseDto = AgentMandateMcpResponseDto::forGrantOutcome(AgentMandateGrantOutcome::forGranted($this->buildMandate(AgentMandate::SCOPE_ONE_INSTRUCTION), []), null);
        self::assertSame(
            "Mandate granted. Evidence: Ja, veröffentlichen.\nThe grant alone changes nothing: call the tool that was refused for this permission again NOW, and tell the customer only what that call returned.\nThis one-instruction mandate covers every NEWSLETTER_SEND"
            . ' call of the customer\'s current request through the tool you use first (and the tools of the same intent) — run the whole batch'
            . ' through it now and do NOT grant again until the customer asks for something new. A different action of this class needs the'
            . ' customer\'s own consent.',
            $responseDto->note
        );
    }

    public function testAnUnlimitedGrantThatSupersedes(): void
    {
        $supersededMandate = $this->buildMandate(AgentMandate::SCOPE_TIME_WINDOW);
        $supersededMandate->id = 16;
        $responseDto = AgentMandateMcpResponseDto::forGrantOutcome(AgentMandateGrantOutcome::forGranted($this->buildMandate(AgentMandate::SCOPE_UNLIMITED), [$supersededMandate]), null);
        self::assertSame(
            "Mandate granted. Evidence: Ja, veröffentlichen.\nThe grant alone changes nothing: call the tool that was refused for this permission again NOW, and tell the customer only what that call returned.\nThis is a STANDING permission: once that call has returned, your answer ALSO tells the customer in ONE sentence what you may now do on your own, until when, and that a word from them stops it at once.\nIt REPLACES the previous mandate(s) below (now SUPERSEDED — tell the customer the old permission no longer applies):\n"
            . trim($supersededMandate->toLlmMarkdownInZone(null)),
            $responseDto->note
        );
    }

    public function testRevoked(): void
    {
        self::assertSame('Mandate revoked.', AgentMandateMcpResponseDto::forRevokedMandate($this->buildMandate(AgentMandate::SCOPE_UNLIMITED))->note);
    }

    public function testTheGrantArguments(): void
    {
        $requestDto = new MandateGrantRequestDto();
        $requestDto->actionClass = AgentMandate::ACTION_STANDING_AUTOMATION;
        $requestDto->scopeType = AgentMandate::SCOPE_TIME_WINDOW;
        $requestDto->validUntil = new DateTime('2026-09-21 23:59:59');
        $requestDto->constraintsNote = 'only positive reviews';
        $agentMandateGrantArguments = $requestDto->toGrantArguments();
        self::assertSame(AgentMandate::ACTION_STANDING_AUTOMATION, $agentMandateGrantArguments->actionClass);
        self::assertSame(AgentMandate::SCOPE_TIME_WINDOW, $agentMandateGrantArguments->scopeType);
        self::assertSame($requestDto->validUntil, $agentMandateGrantArguments->validUntil);
        self::assertSame('only positive reviews', $agentMandateGrantArguments->constraintsNote);
    }

    protected function buildMandate(string $scopeType): AgentMandate
    {
        $agentMandate = new AgentMandate();
        $agentMandate->id = 17;
        $agentMandate->accountId = 581;
        // an action class an application declares (the package's own class is never granted for one instruction)
        $agentMandate->actionClass = 'NEWSLETTER_SEND';
        $agentMandate->scopeType = $scopeType;
        $agentMandate->status = AgentMandate::STATUS_ACTIVE;
        $agentMandate->validFrom = new DateTime('2026-09-14 10:00:00');
        $agentMandate->customerApprovalExcerpt = 'Ja, veröffentlichen.';
        return $agentMandate;
    }
}
