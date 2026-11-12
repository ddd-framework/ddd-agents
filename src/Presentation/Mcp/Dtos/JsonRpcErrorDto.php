<?php
declare(strict_types=1);

namespace DDD\Presentation\Mcp\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * JSON-RPC 2.0 Error Object — a nested value object (the `error` member of JsonRpcResponseDto),
 * NOT an HTTP response, so it extends ValueObject (not RestResponseDto/JsonResponse).
 * @see https://www.jsonrpc.org/specification#error_object
 * @phpstan-consistent-constructor
 */
class JsonRpcErrorDto extends ValueObject
{
    /**
     * A Number that indicates the error type that occurred.
     * Standard error codes:
     * - -32700: Parse error
     * - -32600: Invalid Request
     * - -32601: Method not found
     * - -32602: Invalid params
     * - -32603: Internal error
     * - -32000 to -32099: Server error (reserved for implementation-defined server-errors)
     */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[Choice(choices: [JsonRpcError::JSON_RPC_CODE_PARSE_ERROR, JsonRpcError::JSON_RPC_CODE_INVALID_REQUEST, JsonRpcError::JSON_RPC_CODE_METHOD_NOT_FOUND, JsonRpcError::JSON_RPC_CODE_INTERNAL_ERROR, JsonRpcError::JSON_RPC_CODE_SERVER_ERROR])]
    public int $code;

    /**
     * A String providing a short description of the error.
     * The message SHOULD be limited to a concise single sentence.
     */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $message;

    /**
     * A Primitive or Structured value that contains additional information about the error.
     * This may be omitted. The value of this member is defined by the Server (e.g. detailed error information, nested errors, etc.).
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public mixed $data = null;

    public static function fromError(JsonRpcError $error): static {
        return new static($error->jsonRpcErrorCode, $error->getMessage(), $error->exceptionDetails);
    }

    public function __construct(
        int $code,
        string $message,
        mixed $data = null
    )
    {
        parent::__construct();
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    /**
     * Factory method for "Method not found" error (-32601)
     */
    public static function methodNotFound(string $method): static
    {
        return new static(
            code: JsonRpcError::JSON_RPC_CODE_METHOD_NOT_FOUND,
            message: "Method not found: $method"
        );
    }

    /**
     * Factory method for "Invalid params" error (-32602)
     */
    public static function invalidParams(string $message, mixed $data = null): static
    {
        return new static(
            code: JsonRpcError::JSON_RPC_CODE_INVALID_PARAMS,
            message: $message,
            data: $data
        );
    }

    /**
     * Factory method for "Parse error" (-32700)
     */
    public static function parseError(string $message = 'Parse error'): static
    {
        return new static(
            code: JsonRpcError::JSON_RPC_CODE_PARSE_ERROR,
            message: $message
        );
    }

    /**
     * Factory method for "Invalid Request" error (-32600)
     */
    public static function invalidRequest(string $message = 'Invalid Request'): static
    {
        return new static(
            code: JsonRpcError::JSON_RPC_CODE_INVALID_REQUEST,
            message: $message
        );
    }

    /**
     * Factory method for "Internal error" (-32603)
     */
    public static function internalError(string $message = 'Internal error', mixed $data = null): static
    {
        return new static(
            code: JsonRpcError::JSON_RPC_CODE_INTERNAL_ERROR,
            message: $message,
            data: $data
        );
    }
}
