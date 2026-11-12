<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Entities\Mandates\AgentMandateGrantArguments;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Input of the package's own `grantMandate` ({@see \DDD\Presentation\Mcp\Client\AI\AgentMandatesMCPPackage}).
 * Deliberately carries NO transcript and NO quotes — the evidence is pulled server-side from the conversation's own
 * messages and verified by the approval check, so the model only names WHAT it believes was granted, never proves it
 * itself. The action classes are the application's; an application that describes them to the model, or names the
 * space by an argument of its own, declares its own request class and tool
 * ({@see \DDD\Presentation\Mcp\Client\AI\AgentMandateToolCallsMCPPackage}).
 */
class MandateGrantRequestDto extends RequestDto
{
    /** @var string The action class the customer authorized — one of the listed values. */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[Choice(callback: [AgentMandate::class, 'getActionClasses'])]
    public string $actionClass;

    /**
     * @var string ONE_INSTRUCTION (every call the ONE current customer request needs) | TIME_WINDOW (until
     * validUntil) | UNLIMITED (until revoked). Never quantity-based — convert a wish for a number of executions
     * into a time window or an unlimited mandate.
     */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[Choice(choices: AgentMandate::SCOPE_TYPES)]
    public string $scopeType;

    /** @var DateTime|null The window end, required for TIME_WINDOW (e.g. "2026-09-21 23:59:59"). */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?DateTime $validUntil = null;

    /** @var string|null Constraints the customer stated (a limit on which items the action covers) — verified against the dialogue. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $constraintsNote = null;

    /** @var int|null The space the mandate is bound to; omit it for the space this conversation works for. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;

    /** @var bool|null Set true to grant ACCOUNT-WIDE (every space of the account) — only when the customer clearly said so. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $accountWide = null;

    /** What the grant asks for, as the service takes it. */
    public function toGrantArguments(): AgentMandateGrantArguments
    {
        return AgentMandateGrantArguments::build($this->actionClass, $this->scopeType, $this->validUntil, $this->constraintsNote);
    }
}
