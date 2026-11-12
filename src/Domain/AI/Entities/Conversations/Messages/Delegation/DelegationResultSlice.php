<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What ONE delegation child hands to the `delegate` call that started it: the task it was given, its result as the
 * parent's model reads it, and what the child's whole subtree cost
 * ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildDelegationResultSlice()}). A call stored without
 * a sub-agent record keeps one of these per child in its `delegationResults` column
 * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\DelegationResults::$results}); there it is stored as
 * `{"task":…,"verdict":…,"costInUSD":…}` ({@see toStoredSlice()}).
 *
 * @phpstan-consistent-constructor
 */
class DelegationResultSlice extends ValueObject
{
    /** @var string The task the child was given */
    public string $task = '';

    /** @var string The child's result as the parent's model reads it; empty when the child produced none */
    public string $verdict = '';

    /**
     * @var float|null The child's whole-subtree spend in USD — the only way a child's cost reaches the turn total of
     *      its parent; null on a slice stored before the field existed
     */
    public ?float $costInUSD = null;

    /**
     * @param string $task
     * @param string $verdict
     * @param float|null $costInUSD
     * @return static
     */
    public static function forTaskAndVerdict(string $task, string $verdict, ?float $costInUSD = null): static
    {
        $delegationResultSlice = new static();
        $delegationResultSlice->task = $task;
        $delegationResultSlice->verdict = $verdict;
        $delegationResultSlice->costInUSD = $costInUSD;
        return $delegationResultSlice;
    }

    /**
     * @param array $storedSlice One stored `{task, verdict, costInUSD?}` value of the `delegationResults` column
     * @return static
     */
    public static function fromStoredSlice(array $storedSlice): static
    {
        return static::forTaskAndVerdict(
            (string)($storedSlice['task'] ?? ''),
            (string)($storedSlice['verdict'] ?? ''),
            is_numeric($storedSlice['costInUSD'] ?? null) ? (float)$storedSlice['costInUSD'] : null
        );
    }

    /**
     * The stored form inside the `delegationResults` column (key order fixed; a slice without a cost stores none).
     *
     * @return array{task: string, verdict: string, costInUSD?: float}
     */
    public function toStoredSlice(): array
    {
        $storedSlice = ['task' => $this->task, 'verdict' => $this->verdict];
        if ($this->costInUSD !== null) {
            $storedSlice['costInUSD'] = $this->costInUSD;
        }
        return $storedSlice;
    }

    /** @return string|null The child's result; null when the child produced none (an empty or blank text) */
    public function getVerdictUnlessBlank(): ?string
    {
        return trim($this->verdict) !== '' ? $this->verdict : null;
    }
}
