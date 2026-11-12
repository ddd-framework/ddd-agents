<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE mechanical operation a tool-run sub-agent runs without a model call, as its seed message carries it: the
 * operation, and for {@see BackgroundMechanicalOperationPurpose::OPERATION_EXECUTE_TOOL} the tool and its arguments.
 * {@see BackgroundMechanicalOperationPurpose::parseOperationSeedText()} reads it from the seed text.
 *
 * @phpstan-consistent-constructor
 */
class BackgroundMechanicalOperation extends ValueObject
{
    /** @var string The operation (an OPERATION_* name of {@see BackgroundMechanicalOperationPurpose}, or an unknown name the run then refuses) */
    public string $operation = '';

    /** @var string|null The tool the operation runs; null when the seed names none */
    public ?string $toolName = null;

    /** @var array<string, mixed> The arguments the tool is called with */
    public array $toolArguments = [];

    /**
     * @param array $operationPayload The decoded JSON line of the seed text (`operation`, `toolName`, `toolArguments`)
     * @return static|null null when the payload names no operation
     */
    public static function fromOperationPayload(array $operationPayload): ?static
    {
        if (!is_string($operationPayload['operation'] ?? null) || $operationPayload['operation'] === '') {
            return null;
        }
        $backgroundMechanicalOperation = new static();
        $backgroundMechanicalOperation->operation = $operationPayload['operation'];
        $backgroundMechanicalOperation->toolName = is_string($operationPayload['toolName'] ?? null) ? $operationPayload['toolName'] : null;
        $backgroundMechanicalOperation->toolArguments = is_array($operationPayload['toolArguments'] ?? null) ? $operationPayload['toolArguments'] : [];
        return $backgroundMechanicalOperation;
    }

    /** @return bool Whether the operation runs one tool */
    public function isToolExecution(): bool
    {
        return $this->operation === BackgroundMechanicalOperationPurpose::OPERATION_EXECUTE_TOOL;
    }
}
