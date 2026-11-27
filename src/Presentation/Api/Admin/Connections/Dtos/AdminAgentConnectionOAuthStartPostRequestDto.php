<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Domain\AI\Entities\Connections\AgentConnectionLevelRequest;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionOAuthStartPostRequestDto extends RequestDto
{
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $provider = '';
    /** @var string[] */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $capabilities = [];
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $displayName = null;
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $loginHint = null;
    /** @var bool a system connection of the agent (the system right) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public bool $systemConnection = false;
    /** @var string|null OWNER_ONLY (default) or AGENT_SPACE: shared with `targetAgentSpaceId` once the consent completes (an account's own connection only) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $visibility = null;
    /** @var int|null the space for AGENT_SPACE; omitted = the only space the account may share with */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $targetAgentSpaceId = null;
    /** @var int|null an existing connection: asks the provider for the additional scopes of `requestedLevels` (incremental consent) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $connectionId = null;
    /** @var AgentConnectionLevelRequest[] with `connectionId`: the levels to set once the provider granted their scopes */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $requestedLevels = [];
}
