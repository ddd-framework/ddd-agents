<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The boundaries of a connection or of one release. Empty = no limit on that side.
 *
 * @property AgentConnectionScopeBoundary[] $elements
 * @method AgentConnectionScopeBoundary[] getElements()
 * @method AgentConnectionScopeBoundary|null first()
 */
class AgentConnectionScopeBoundaries extends ObjectSet
{
    public static function ofBoundaries(AgentConnectionScopeBoundary ...$scopeBoundaries): static
    {
        $boundariesSet = new static();
        foreach ($scopeBoundaries as $scopeBoundary) {
            $boundariesSet->add($scopeBoundary);
        }
        return $boundariesSet;
    }

    /** @return string[] the values of one kind */
    public function getValuesOfKind(string $kind): array
    {
        $values = [];
        foreach ($this->getElements() as $scopeBoundary) {
            if ($scopeBoundary->kind === $kind && $scopeBoundary->value !== '') {
                $values[] = $scopeBoundary->value;
            }
        }
        return $values;
    }

    /** Whether one stored boundary cannot be read (an unknown kind, no value): the boundaries then limit to nothing */
    public function hasUnreadableBoundary(): bool
    {
        foreach ($this->getElements() as $scopeBoundary) {
            if (!$scopeBoundary instanceof AgentConnectionScopeBoundary || !$scopeBoundary->isReadable()) {
                return true;
            }
        }
        return false;
    }

    /** A copy with the boundary added (once) or removed */
    public function withBoundary(string $kind, string $value, bool $isPresent): static
    {
        $changedBoundaries = new static();
        foreach ($this->getElements() as $scopeBoundary) {
            if ($scopeBoundary->kind === $kind && strcasecmp($scopeBoundary->value, $value) === 0) {
                continue;
            }
            $changedBoundaries->add($scopeBoundary);
        }
        if ($isPresent) {
            $addedBoundary = AgentConnectionScopeBoundary::of($kind, $value);
            $changedBoundaries->add($addedBoundary);
        }
        return $changedBoundaries;
    }

    /** Only known kinds and non-empty values, each once */
    public function normalized(): static
    {
        $normalizedBoundaries = new static();
        foreach ($this->getElements() as $scopeBoundary) {
            $cleanBoundary = AgentConnectionScopeBoundary::of($scopeBoundary->kind, $scopeBoundary->value);
            if ($cleanBoundary->value === '' || !in_array($cleanBoundary->kind, AgentConnectionScopeBoundary::KINDS, true) || $normalizedBoundaries->contains($cleanBoundary)) {
                continue;
            }
            $normalizedBoundaries->add($cleanBoundary);
        }
        return $normalizedBoundaries;
    }

    /** A stable text for cache keys */
    public function fingerprintText(): string
    {
        $lines = [];
        foreach ($this->getElements() as $scopeBoundary) {
            $lines[] = $scopeBoundary->kind . ':' . $scopeBoundary->value;
        }
        sort($lines);
        return implode("\n", $lines);
    }
}
