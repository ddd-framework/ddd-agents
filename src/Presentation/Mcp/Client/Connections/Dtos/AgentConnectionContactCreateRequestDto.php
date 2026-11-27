<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionContactCreateRequestDto extends RequestDto
{
    /** @var string|null The connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string Full name */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $name = '';
    /** @var string[]  */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $emailAddresses = [];
    /** @var string[]  */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $phoneNumbers = [];
    /** @var ?string  */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $organization = null;
    /** @var ?string  */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $jobTitle = null;
    /** @var ?string Free text */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $notes = null;
    /** @var string[] Labels (groupIds) the new contact joins */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $labels = [];
    /** @var ?string A contactId of Other contacts to copy into My Contacts instead of the fields above */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $copyFromContactId = null;
}
