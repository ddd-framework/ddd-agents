<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionContactGroupMembersChangeRequestDto extends RequestDto
{
    /** @var string|null The connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var ?string The label (groupId); omit with newGroupName */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $groupId = null;
    /** @var ?string Creates this label first */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $newGroupName = null;
    /** @var string[] contactIds to add */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $addContactIds = [];
    /** @var string[] contactIds to remove */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $removeContactIds = [];
}
