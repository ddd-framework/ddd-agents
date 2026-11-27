<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

/**
 * The decrypted secrets of one connection, in memory only, for the duration of one adapter call. Deliberately no
 * value object: it is never serialized, never part of an answer, a log or the model's context, and its debug output
 * shows no value.
 */
#[Exclude]
class AgentConnectionSecretPayload
{
    /** @param array<string, string|int|null> $values */
    public function __construct(protected array $values = [])
    {
    }

    public function get(string $key): ?string
    {
        $value = $this->values[$key] ?? null;
        return $value === null ? null : (string)$value;
    }

    public function getInt(string $key): ?int
    {
        $value = $this->values[$key] ?? null;
        return $value === null ? null : (int)$value;
    }

    public function with(string $key, string|int|null $value): static
    {
        $copy = clone $this;
        $copy->values[$key] = $value;
        return $copy;
    }

    /** @return array<string, string|int|null> for the encryption only */
    public function toEncryptableArray(): array
    {
        return $this->values;
    }

    public function __debugInfo(): array
    {
        return ['keys' => array_keys($this->values)];
    }
}
