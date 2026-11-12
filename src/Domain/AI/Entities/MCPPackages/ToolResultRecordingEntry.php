<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\Conversations\Usage\AIUsageBookedCostsStoredForm;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE tool result a recorded-run comparison recorded: the tool, its encoded arguments, the result as the tool returned
 * it and the AI costs the tool booked while it ran. {@see ToolResultRecording::recordResult()} builds it, a recording
 * file stores it as {@see toStoredEntry()}, {@see fromStoredEntry()} reads it back and {@see buildToolResult()} plays it.
 *
 * @phpstan-consistent-constructor
 */
class ToolResultRecordingEntry extends ValueObject
{
    /** @var string The tool's name */
    public string $toolName = '';

    /** @var string The call's arguments as JSON */
    public string $arguments = '';

    /** @var string The result content */
    public string $content = '';

    /** @var bool Whether the result is an error */
    public bool $isError = false;

    /** @var string|null The executor gate's refusal kind, when the call was refused */
    public ?string $refusalKind = null;

    /** @var string|null The tool's platform note for the model; null also for a recording written before the note existed */
    public ?string $platformNoteForModel = null;

    /** @var string|null The widget type the tool's note dictates; null also for a recording written before the type existed */
    public ?string $dictatedWidgetType = null;

    /** @var array|null The result's bindable form as the tool returned it */
    public ?array $bindableResult = null;

    /**
     * @var array|object|null The AI costs the tool booked, in their stored form (the object form when recorded, an
     *      array once read back from a recording file); null when the tool booked none
     */
    public array|object|null $bookedCosts = null;

    /**
     * @param string $toolName
     * @param string $arguments The call's arguments as JSON
     * @param ToolResult $toolResult
     * @param array|object|null $bookedCosts The booked costs' stored form; null when the tool booked none
     * @return static
     */
    public static function forToolResult(string $toolName, string $arguments, ToolResult $toolResult, array|object|null $bookedCosts = null): static
    {
        $toolResultRecordingEntry = new static();
        $toolResultRecordingEntry->toolName = $toolName;
        $toolResultRecordingEntry->arguments = $arguments;
        $toolResultRecordingEntry->content = $toolResult->content;
        $toolResultRecordingEntry->isError = $toolResult->isError;
        $toolResultRecordingEntry->refusalKind = $toolResult->refusalKind;
        $toolResultRecordingEntry->platformNoteForModel = $toolResult->platformNoteForModel;
        $toolResultRecordingEntry->dictatedWidgetType = $toolResult->dictatedWidgetType;
        $toolResultRecordingEntry->bindableResult = $toolResult->bindableResult;
        $toolResultRecordingEntry->bookedCosts = $bookedCosts;
        return $toolResultRecordingEntry;
    }

    /**
     * @param array $storedEntry One entry of a recording file's tool results; every key but `toolName`, `arguments`,
     *      `content` and `isError` is optional
     * @return static
     */
    public static function fromStoredEntry(array $storedEntry): static
    {
        $toolResultRecordingEntry = new static();
        $toolResultRecordingEntry->toolName = (string)($storedEntry['toolName'] ?? '');
        $toolResultRecordingEntry->arguments = (string)($storedEntry['arguments'] ?? '');
        $toolResultRecordingEntry->content = (string)($storedEntry['content'] ?? '');
        $toolResultRecordingEntry->isError = (bool)($storedEntry['isError'] ?? false);
        $toolResultRecordingEntry->refusalKind = $storedEntry['refusalKind'] ?? null;
        $toolResultRecordingEntry->platformNoteForModel = $storedEntry['platformNoteForModel'] ?? null;
        $toolResultRecordingEntry->dictatedWidgetType = $storedEntry['dictatedWidgetType'] ?? null;
        $toolResultRecordingEntry->bindableResult = is_array($storedEntry['bindableResult'] ?? null) ? $storedEntry['bindableResult'] : null;
        $storedBookedCosts = $storedEntry['bookedCosts'] ?? null;
        $toolResultRecordingEntry->bookedCosts = is_array($storedBookedCosts) || is_object($storedBookedCosts) ? $storedBookedCosts : null;
        return $toolResultRecordingEntry;
    }

    /**
     * The stored form inside a recording file (key order fixed; an entry without booked costs stores none).
     *
     * @return array{toolName: string, arguments: string, content: string, isError: bool, refusalKind: string|null, platformNoteForModel: string|null, dictatedWidgetType: string|null, bindableResult: array|null, bookedCosts?: array|object}
     */
    public function toStoredEntry(): array
    {
        $storedEntry = [
            'toolName' => $this->toolName,
            'arguments' => $this->arguments,
            'content' => $this->content,
            'isError' => $this->isError,
            'refusalKind' => $this->refusalKind,
            'platformNoteForModel' => $this->platformNoteForModel,
            'dictatedWidgetType' => $this->dictatedWidgetType,
            'bindableResult' => $this->bindableResult,
        ];
        if ($this->bookedCosts !== null) {
            $storedEntry['bookedCosts'] = $this->bookedCosts;
        }
        return $storedEntry;
    }

    /**
     * The tool result a replay plays for the call. Booked costs are read back only from their array form (a recording
     * read back from its file); a recording written before the object form holds them keyed by scope, both forms read.
     *
     * @return ToolResult
     */
    public function buildToolResult(): ToolResult
    {
        $toolResult = new ToolResult($this->content, $this->isError);
        $toolResult->refusalKind = $this->refusalKind;
        $toolResult->platformNoteForModel = $this->platformNoteForModel;
        $toolResult->dictatedWidgetType = $this->dictatedWidgetType;
        $toolResult->bindableResult = $this->bindableResult;
        $toolResult->recordedBookedCosts = AIUsageBookedCostsStoredForm::buildBookedCostsFromStoredForm(
            is_array($this->bookedCosts) ? $this->bookedCosts : null
        );
        return $toolResult;
    }
}
