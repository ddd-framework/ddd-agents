<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One named check of the compaction consistency report: its name, whether it passed, and its detail
 * counts in output order (`edge`, `tailCount`, `leafCount` — none for most checks). Rendered as `['pass' => …] +
 * details`, the shape an application's compaction validation command prints.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationCompactionConsistencyCheck extends ValueObject
{
    protected string $checkName = '';

    protected bool $pass = false;

    /** @var array<string, int|null> detail name → value, in output order */
    protected array $details = [];

    /** @param array<string, int|null> $details detail name → value, in output order */
    public static function named(string $checkName, bool $pass, array $details = []): static
    {
        $check = new static();
        $check->checkName = $checkName;
        $check->pass = $pass;
        $check->details = $details;
        return $check;
    }

    public function getCheckName(): string
    {
        return $this->checkName;
    }

    public function isPass(): bool
    {
        return $this->pass;
    }

    /** @return array<string, int|null> detail name → value, in output order */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** @return array<string, bool|int|null> `pass` first, then the details */
    public function toArray(): array
    {
        return ['pass' => $this->pass] + $this->details;
    }
}
