<?php
declare(strict_types=1);

namespace DDD\Presentation\Mcp\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class MCPRequestDto extends RequestDto
{
    /** @var int|null The Request Id */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $id = 0;

    /** @var string|null The JSONRCP Method called */
    #[Parameter(in: Parameter::BODY, required: true)]
    public ?string $method = null;

    /**
     * JSON-RPC params payload (e.g. for tools/call).
     * @var MCPToolCallParams|null
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?MCPToolCallParams $params = null;
}