<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the platform's closers did to a task plan in one pass: the leaves they closed — item key
 * (the names below predate keys) → the status written. One value for every closer (the completed call's
 * evidence, the turn-end widget / delivery evidence) and for their merge: a leaf closed
 * by an earlier closer keeps that status ({@see mergeWith()}). A working value of one pass: its fields are protected, so
 * a serializer sees nothing — read it through the getters (the traces take {@see getStatusByNumberPath()}).
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanLeafClosings extends ValueObject
{
    /** @var array<int|string, string> item key → the status written, in closing order (PHP keeps "1" as int 1) */
    protected array $statusByNumberPath = [];

    /** @param array<int|string, string> $statusByNumberPath */
    public static function ofStatusByNumberPath(array $statusByNumberPath): static
    {
        $taskPlanLeafClosings = new static();
        $taskPlanLeafClosings->statusByNumberPath = $statusByNumberPath;
        return $taskPlanLeafClosings;
    }

    /** The leaf with key `$numberPath` was closed with `$status` (a leaf already recorded keeps its first status). */
    public function addClosing(string $numberPath, string $status): void
    {
        $this->statusByNumberPath += [$numberPath => $status];
    }

    /** Both passes in one value: a leaf closed here keeps its status, the other's closings follow. */
    public function mergeWith(self $otherClosings): static
    {
        $mergedClosings = new static();
        $mergedClosings->statusByNumberPath = $this->statusByNumberPath + $otherClosings->statusByNumberPath;
        return $mergedClosings;
    }

    /** @return array<int|string, string> item key → the status written, in closing order (a top-level key is an int array key) */
    public function getStatusByNumberPath(): array
    {
        return $this->statusByNumberPath;
    }

    /** @return string[] the closed leaves' keys, in closing order */
    public function getClosedNumberPaths(): array
    {
        return array_map('strval', array_keys($this->statusByNumberPath));
    }

    public function hasClosings(): bool
    {
        return $this->statusByNumberPath !== [];
    }
}
