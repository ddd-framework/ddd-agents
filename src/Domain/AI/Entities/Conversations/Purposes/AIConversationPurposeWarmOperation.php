<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE background warm-up a purpose asks for after a loop step
 * ({@see AIConversationPurpose::backgroundWarmOperationsAfterStep()}): a mechanical operation, typically
 * {@see BackgroundMechanicalOperationPurpose::OPERATION_EXECUTE_TOOL} of one tool, that a sub-agent runs without a
 * model call so that the cache entry the model's later call reads is already written.
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeBackgroundWorkService::buildPurposeWarmSubAgentRequestsAfterStep()}
 * turns each one into a sub-agent request.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationPurposeWarmOperation extends ValueObject
{
    /** @var string The mechanical operation (an OPERATION_* name of {@see BackgroundMechanicalOperationPurpose}) */
    public string $operation = '';

    /** @var string|null The tool the operation runs; null for an operation that runs none */
    public ?string $toolName = null;

    /** @var array<string, mixed> The arguments the tool is called with */
    public array $toolArguments = [];

    /** @var string[] The skills whose tools the sub-agent needs for the call */
    public array $skillNames = [];

    /**
     * @var string|null The identity of the cache entry the operation writes, named when that identity follows the
     *      record (a later write step of the same turn then warms again exactly when the record changed); null when
     *      the tool and its `url` argument identify the entry
     */
    public ?string $cacheIdentity = null;

    /**
     * @var int How many seconds the loop waits for the sub-agent's run before the next model call of the same turn;
     *      0 = it does not wait
     */
    public int $awaitGraceSeconds = 0;

    /**
     * @param string $operation
     * @param string|null $toolName
     * @param array<string, mixed> $toolArguments
     * @param string[] $skillNames
     * @return static
     */
    public static function forOperation(string $operation, ?string $toolName = null, array $toolArguments = [], array $skillNames = []): static
    {
        $warmOperation = new static();
        $warmOperation->operation = trim($operation);
        $warmOperation->toolName = $toolName;
        $warmOperation->toolArguments = $toolArguments;
        $warmOperation->skillNames = array_values(array_map('strval', $skillNames));
        return $warmOperation;
    }

    /**
     * Name the identity of the cache entry the operation writes.
     *
     * @param string|null $cacheIdentity
     * @return static
     */
    public function withCacheIdentity(?string $cacheIdentity): static
    {
        $trimmedCacheIdentity = trim((string)$cacheIdentity);
        $this->cacheIdentity = $trimmedCacheIdentity !== '' ? $trimmedCacheIdentity : null;
        return $this;
    }

    /**
     * Let the loop wait for the sub-agent's run before the next model call of the same turn.
     *
     * @param int $awaitGraceSeconds
     * @return static
     */
    public function withAwaitGraceSeconds(int $awaitGraceSeconds): static
    {
        $this->awaitGraceSeconds = $awaitGraceSeconds;
        return $this;
    }

    /** @return bool Whether the operation is named and its tool name, when it has one, is a plain tool name */
    public function isRunnable(): bool
    {
        return $this->operation !== '' && ($this->toolName === null || preg_match('/^[A-Za-z0-9_]+$/', $this->toolName) === 1);
    }

    /**
     * The start of the key a run remembers its dispatched warm-ups under: the operation and its tool. Every warm-up
     * of the same tool within a turn shares it.
     *
     * @return string
     */
    public function buildDispatchMemoKeyPrefix(): string
    {
        return $this->operation . ':' . ($this->toolName ?? '') . ':';
    }

    /**
     * The key a run remembers this warm-up under: operation, tool, the tool's `url` argument and the cache identity.
     *
     * @return string
     */
    public function buildDispatchMemoKey(): string
    {
        return $this->buildDispatchMemoKeyPrefix() . trim((string)($this->toolArguments['url'] ?? '')) . ':' . ($this->cacheIdentity ?? '');
    }
}
