<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The FIRST wire message in which one model call's request differs from the previous call's: its position, its role,
 * whether it was appended, changed or removed, and a short head of its content. {@see PromptCacheDiffReport} builds
 * it and stores it in a report row in its array form ({@see toReportArray()}).
 *
 * @phpstan-consistent-constructor
 */
class PromptCacheMessageDifference extends ValueObject
{
    public const string KIND_APPEND = 'append';
    public const string KIND_CHANGE = 'change';
    public const string KIND_REMOVED = 'removed';

    /** @var int The position of the message among the wire messages after the system content */
    public int $position = 0;

    /** @var string The message's role; a Responses item without a role reads as its type */
    public string $role = '';

    /** @var string Whether the message was appended, changed or removed ({@see KIND_APPEND}, {@see KIND_CHANGE}, {@see KIND_REMOVED}) */
    public string $kind = '';

    /** @var string The first 80 characters of the message content, whitespace collapsed */
    public string $head = '';

    /**
     * @param int $position
     * @param string $role
     * @param string $kind
     * @param string $head
     * @return static
     */
    public static function forPosition(int $position, string $role, string $kind, string $head): static
    {
        $promptCacheMessageDifference = new static();
        $promptCacheMessageDifference->position = $position;
        $promptCacheMessageDifference->role = $role;
        $promptCacheMessageDifference->kind = $kind;
        $promptCacheMessageDifference->head = $head;
        return $promptCacheMessageDifference;
    }

    /** @return bool Whether the previous call had no message at this position */
    public function isAppend(): bool
    {
        return $this->kind === self::KIND_APPEND;
    }

    /**
     * The form a report row carries (key order fixed).
     *
     * @return array{position: int, role: string, kind: string, head: string}
     */
    public function toReportArray(): array
    {
        return ['position' => $this->position, 'role' => $this->role, 'kind' => $this->kind, 'head' => $this->head];
    }
}
