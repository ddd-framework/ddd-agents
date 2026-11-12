<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use stdClass;

class McpCapabilitiesResponseDto extends RestResponseDto
{
    /** @var string The MCP protocol version (e.g., "2025-06-18") */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $protocolVersion;

    /** @var stdClass Capabilities supported by the MCP server */
    #[Parameter(in: Parameter::BODY, required: true)]
    public stdClass $capabilities;

    /** @var stdClass Basic information about the server */
    #[Parameter(in: Parameter::BODY, required: true)]
    public stdClass $serverInfo;

    /** @var string|null Markdown-formatted usage instructions */
    #[Parameter(in: Parameter::BODY, required: true)]
    public ?string $instructions;

    public function __construct(?string $name = null, ?string $instructions = null)
    {
        parent::__construct();

        $this->protocolVersion = '2025-11-25';

        $this->capabilities = (object) [
            'tools'     => (object)[
                'listChanged' => true,
            ],
        ];

        $this->serverInfo = (object) [
            'name'    => $name ?? '',
            'version' => '1.0.0',
        ];

        $this->instructions = $instructions ?? '';
    }
}