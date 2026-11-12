<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The per-turn EVICTION SNAPSHOT of a loaded capability set ({@see LoadedCapabilities::buildEvictionSnapshot()}): the
 * load split (skill vs tool vs total) and every loaded capability's {@see LoadedCapability::toEvictionTelemetry()}
 * row, highest eviction score first. What an application's agent console command and its eviction eval suite
 * print each turn so an eviction can be watched on real data; {@see toArray()} is the printed JSON.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilitiesEvictionSnapshot extends ValueObject
{
    /** The owner turn the snapshot was taken at. */
    public int $turn = 0;

    /** The conversation's latest context input tokens (the pressure denominator). */
    public int $contextInputTokens = 0;

    /** The resident tokens of the loaded skill bodies. */
    public int $skillTokens = 0;

    /** The resident tokens of the loaded tool schemas. */
    public int $toolTokens = 0;

    /** The resident tokens of every loaded capability. */
    public int $totalResidentTokens = 0;

    /** @var array<int, array<string, mixed>> One {@see LoadedCapability::toEvictionTelemetry()} row per capability, highest score first. */
    public array $capabilityTelemetryRows = [];

    /**
     * @param array<int, array<string, mixed>> $capabilityTelemetryRows
     */
    public static function create(int $turn, int $contextInputTokens, int $skillTokens, int $toolTokens, int $totalResidentTokens, array $capabilityTelemetryRows): static
    {
        $loadedCapabilitiesEvictionSnapshot = new static();
        $loadedCapabilitiesEvictionSnapshot->turn = $turn;
        $loadedCapabilitiesEvictionSnapshot->contextInputTokens = $contextInputTokens;
        $loadedCapabilitiesEvictionSnapshot->skillTokens = $skillTokens;
        $loadedCapabilitiesEvictionSnapshot->toolTokens = $toolTokens;
        $loadedCapabilitiesEvictionSnapshot->totalResidentTokens = $totalResidentTokens;
        $loadedCapabilitiesEvictionSnapshot->capabilityTelemetryRows = $capabilityTelemetryRows;
        return $loadedCapabilitiesEvictionSnapshot;
    }

    /** The snapshot of a turn with nothing loaded. */
    public static function withNothingLoaded(int $turn, int $contextInputTokens): self
    {
        return self::create($turn, $contextInputTokens, 0, 0, 0, []);
    }

    /** @return string[] the loaded capabilities' names, highest score first */
    public function getCapabilityNames(): array
    {
        return array_map(static fn (array $capabilityTelemetryRow): string => (string)$capabilityTelemetryRow['name'], $this->capabilityTelemetryRows);
    }

    /**
     * The printed JSON form (key order fixed: the eval artifacts' bytes).
     *
     * @return array{turn: int, contextInputTokens: int, skillTokens: int, toolTokens: int, totalResidentTokens: int, capabilities: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'turn' => $this->turn,
            'contextInputTokens' => $this->contextInputTokens,
            'skillTokens' => $this->skillTokens,
            'toolTokens' => $this->toolTokens,
            'totalResidentTokens' => $this->totalResidentTokens,
            'capabilities' => $this->capabilityTelemetryRows,
        ];
    }
}
