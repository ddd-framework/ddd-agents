<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionContactUpdateRequestDto extends RequestDto
{
    /** @var string|null The connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string The contactId (My Contacts) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $contactId = '';
    /** @var string The etag of your getContact read; a stale one is refused (read again) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $etag = '';
    /** @var ?string New full name (only when changed) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $name = null;
    /** @var string[]|null The complete new list (only when changed) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $emailAddresses = null;
    /** @var string[]|null The complete new list (only when changed) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $phoneNumbers = null;
    /** @var ?string  */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $organization = null;
    /** @var ?string  */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $jobTitle = null;
    /** @var ?string  */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $notes = null;
}
