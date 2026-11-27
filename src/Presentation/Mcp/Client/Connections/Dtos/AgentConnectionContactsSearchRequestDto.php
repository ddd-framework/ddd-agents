<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionContactsSearchRequestDto extends RequestDto
{
    /** @var string|null The connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string Prefix of a name, e-mail, phone or organization; with `domainOrOrganization` an e-mail domain (example.org) or a part of an organization name */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $query = '';
    /** @var bool true = the domain/organization path (full list, filtered): "everyone @example.org", "everyone at Example" */
    #[Parameter(in: Parameter::BODY, required: false)]
    public bool $domainOrOrganization = false;
    /** @var int 1..30 */
    #[Parameter(in: Parameter::BODY, required: false)]
    public int $maximumResults = 10;
}
