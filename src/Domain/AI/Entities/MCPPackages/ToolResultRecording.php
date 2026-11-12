<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\UsageLogs\AIUsageBookedCosts;

/**
 * The tool results of a recorded-run comparison: RECORD keeps every tool call's result in call order; PLAY
 * answers each call from the recording instead of running the tool — the same tool with the same arguments first,
 * else the oldest unused result of that tool — so a replay never depends on what the live database, a third party or
 * the wall clock answer today. A call without a recorded result fails visibly, and {@see unusedEntries()} names the
 * recorded results a replay never asked for. (Image parts of a result are not recorded.)
 *
 * @phpstan-consistent-constructor
 */
class ToolResultRecording
{
    public const string MODE_RECORD = 'record';
    public const string MODE_PLAY = 'play';

    /** @var ToolResultRecordingEntry[] The recorded tool results in call order */
    public array $entries = [];

    /** @var array<int, true> */
    protected array $usedEntryPositions = [];

    public function __construct(public readonly string $mode)
    {
    }

    /**
     * @param array[] $storedEntries The tool results of a recording file, each in its stored form ({@see ToolResultRecordingEntry::toStoredEntry()})
     * @return static
     */
    public static function forPlaying(array $storedEntries): static
    {
        $toolResultRecording = new static(self::MODE_PLAY);
        foreach (array_values($storedEntries) as $storedEntry) {
            $toolResultRecording->entries[] = ToolResultRecordingEntry::fromStoredEntry((array)$storedEntry);
        }
        return $toolResultRecording;
    }

    public static function forRecording(): static
    {
        return new static(self::MODE_RECORD);
    }

    /**
     * Records one tool result and the AI costs the tool booked while it ran (a web search fee, a page reading) — a
     * replay plays them back with the result, so its tool call carries the same costs. The costs are stored in their
     * object form ({@see AIUsageBookedCosts::toObject()}).
     *
     * @param array<string, mixed>|null $arguments
     */
    public function recordResult(string $toolName, ?array $arguments, ToolResult $toolResult, ?AIUsageBookedCosts $bookedCosts = null): void
    {
        $this->entries[] = ToolResultRecordingEntry::forToolResult(
            $toolName,
            self::encodeArguments($arguments),
            $toolResult,
            $bookedCosts !== null && $bookedCosts->hasBookedCosts() ? $bookedCosts->toObject(false) : null
        );
    }

    /**
     * The recorded tool results in their stored form, in call order — what a recording file keeps.
     *
     * @return array[]
     */
    public function toStoredEntries(): array
    {
        return array_map(static fn (ToolResultRecordingEntry $toolResultRecordingEntry): array => $toolResultRecordingEntry->toStoredEntry(), $this->entries);
    }

    /** @param array<string, mixed>|null $arguments */
    public function playResult(string $toolName, ?array $arguments): ToolResult
    {
        $encodedArguments = self::encodeArguments($arguments);
        $answerPosition = null;
        foreach ($this->entries as $position => $toolResultRecordingEntry) {
            if (isset($this->usedEntryPositions[$position]) || $toolResultRecordingEntry->toolName !== $toolName) {
                continue;
            }
            if ($toolResultRecordingEntry->arguments === $encodedArguments) {
                $answerPosition = $position;
                break;
            }
            $answerPosition ??= $position;
        }
        if ($answerPosition === null) {
            return new ToolResult("recorded-run comparison: no recorded result for tool '{$toolName}'", true);
        }
        $this->usedEntryPositions[$answerPosition] = true;
        return $this->entries[$answerPosition]->buildToolResult();
    }

    /** @return string[] the recorded results a replay never asked for, as tool name per position */
    public function unusedEntries(): array
    {
        $unusedEntries = [];
        foreach ($this->entries as $position => $toolResultRecordingEntry) {
            if (!isset($this->usedEntryPositions[$position])) {
                $unusedEntries[$position] = $toolResultRecordingEntry->toolName;
            }
        }
        return $unusedEntries;
    }

    /** @param array<string, mixed>|null $arguments */
    protected static function encodeArguments(?array $arguments): string
    {
        return (string)json_encode($arguments ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
