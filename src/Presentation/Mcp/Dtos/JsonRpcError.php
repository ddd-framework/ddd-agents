<?php
declare(strict_types=1);

namespace DDD\Presentation\Mcp\Dtos;

use DDD\Infrastructure\Exceptions\Exception;
use DDD\Infrastructure\Exceptions\ExceptionDetails;
use Throwable;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * JSON-RPC 2.0 Error Object
 * @see https://www.jsonrpc.org/specification#error_object
 */
class JsonRpcError extends Exception
{
    /** Parse error - Invalid JSON was received by the server */
    public const int JSON_RPC_CODE_PARSE_ERROR = -32700;

    /** Invalid Request - The JSON sent is not a valid Request object */
    public const int JSON_RPC_CODE_INVALID_REQUEST = -32600;

    /** Method not found - The method does not exist or is not available */
    public const int JSON_RPC_CODE_METHOD_NOT_FOUND = -32601;

    /** Invalid params - Invalid method parameter(s) */
    public const int JSON_RPC_CODE_INVALID_PARAMS = -32602;

    /** Internal error - Internal JSON-RPC error */
    public const int JSON_RPC_CODE_INTERNAL_ERROR = -32603;

    /** Server error - Reserved for implementation-defined server-errors (-32000 to -32099) */
    public const int JSON_RPC_CODE_SERVER_ERROR = -32000;

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
    #[Choice(choices: [self::JSON_RPC_CODE_PARSE_ERROR, self::JSON_RPC_CODE_INVALID_REQUEST, self::JSON_RPC_CODE_METHOD_NOT_FOUND, self::JSON_RPC_CODE_INTERNAL_ERROR, self::JSON_RPC_CODE_SERVER_ERROR])]
    public int $jsonRpcErrorCode;

    /**
     * A Primitive or Structured value that contains additional information about the error.
     * This may be omitted. The value of this member is defined by the Server (e.g. detailed error information, nested errors, etc.).
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public mixed $data = null;

    public function __construct(int $jsonRpcErrorCode, string $message, ?ExceptionDetails &$exceptionDetails = null, ?Throwable $previous = null)
    {
        $this->jsonRpcErrorCode = $jsonRpcErrorCode;
        $this->exceptionDetails = $exceptionDetails;
        $this->error = $message;
        parent::__construct($message, $exceptionDetails, $previous);
    }
}
