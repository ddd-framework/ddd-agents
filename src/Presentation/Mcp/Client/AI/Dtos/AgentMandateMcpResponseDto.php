<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Entities\Mandates\AgentMandateGrantOutcome;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Response of the single-mandate write tools (grant / revoke): the affected mandate + a short confirmation.
 * Renders compactly for the agent by delegating to {@see AgentMandate::toLlmMarkdown()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentMandateMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var AgentMandate|null The granted or revoked mandate. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentMandate $mandate = null;

    /** @var string|null A short confirmation of the action. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    /** The answer of a grant: the covering mandate with the note on what was granted, replaced or already covered. */
    public static function forGrantOutcome(AgentMandateGrantOutcome $agentMandateGrantOutcome, ?\DateTimeZone $dialogueTimezone): static
    {
        $grantedAgentMandate = $agentMandateGrantOutcome->agentMandate;
        $responseDto = new static();
        $responseDto->mandate = $grantedAgentMandate;
        if ($agentMandateGrantOutcome->requestAlreadyCovered) {
            $responseDto->note = 'Already covered — an active one-instruction mandate for this action class and THIS '
                . 'customer request exists (no new mandate was created). It covers every further call of this '
                . 'request through the tool it was first used with (and the tools of the same intent): retry the '
                . 'refused tool call(s) NOW and do NOT grant again.';
            return $responseDto;
        }
        $responseDto->note = 'Mandate granted. Evidence: ' . ($grantedAgentMandate->customerApprovalExcerpt ?? '')
            // a grant changes nothing by itself: the refused call has to run again
            . "\nThe grant alone changes nothing: call the tool that was refused for this permission again NOW, and tell the "
            . 'customer only what that call returned.';
        if ($grantedAgentMandate->scopeType === AgentMandate::SCOPE_ONE_INSTRUCTION) {
            $responseDto->note .= "\nThis one-instruction mandate covers every " . $grantedAgentMandate->actionClass
                . ' call of the customer\'s current request through the tool you use first (and the tools of the '
                . 'same intent) — run the whole batch through it now and do NOT grant again until the customer '
                . 'asks for something new. A different action of this class needs the customer\'s own consent.';
        } else {
            // a standing permission is confirmed to the customer: what, until when, and how it stops
            $responseDto->note .= "\nThis is a STANDING permission: once that call has returned, your answer ALSO tells the "
                . 'customer in ONE sentence what you may now do on your own, until when, and that a word from them stops it at once.';
        }
        if ($agentMandateGrantOutcome->supersededMandates !== []) {
            $supersededLines = array_map(
                static fn (AgentMandate $supersededMandate): string => trim($supersededMandate->toLlmMarkdownInZone($dialogueTimezone)),
                $agentMandateGrantOutcome->supersededMandates
            );
            $responseDto->note .= "\nIt REPLACES the previous mandate(s) below (now SUPERSEDED — tell the customer the old permission no longer applies):\n"
                . implode("\n", $supersededLines);
        }
        return $responseDto;
    }

    /** The answer of a revoke. */
    public static function forRevokedMandate(AgentMandate $revokedAgentMandate): static
    {
        $responseDto = new static();
        $responseDto->mandate = $revokedAgentMandate;
        $responseDto->note = 'Mandate revoked.';
        return $responseDto;
    }

    public function toLlmMarkdown(): string
    {
        $mandateLine = $this->mandate?->toLlmMarkdown() ?? '(no mandate)';
        return ($this->note !== null && $this->note !== '') ? $this->note . "\n" . $mandateLine : $mandateLine;
    }
}
