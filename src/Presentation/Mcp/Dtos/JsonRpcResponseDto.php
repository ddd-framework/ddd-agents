<?php
declare(strict_types=1);

namespace DDD\Presentation\Mcp\Dtos;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use stdClass;

/**
 * Basic JsonRpcResponse
 */
class JsonRpcResponseDto extends RestResponseDto
{
    /** @var int|null The of the Request */
    #[Parameter(in: Parameter::BODY, required: true)]
    public ?int $id = 0;

    /** @var string The JSON RCP Version */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $jsonrpc = '2.0';

    /** @var stdClass|ValueObject|RestResponseDto|null The result payload */
    #[Parameter(in: Parameter::BODY, required: false)]
    public stdClass|ValueObject|RestResponseDto|null $result = null;

    /** @var JsonRpcErrorDto|null The error object */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?JsonRpcErrorDto $error = null;


    public function __construct(
        int $id = 0,
        stdClass|ValueObject|RestResponseDto|null $result = new stdClass(),
        ?JsonRpcErrorDto $error = null
    )
    {
        parent::__construct();
        $this->id = $id;
        $this->result = $result;
        $this->error = $error;
    }
}