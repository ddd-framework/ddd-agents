<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\UsageLogs\AIUsageBookedCosts;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Neutral, layer-agnostic result of a tool execution — the return of {@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService}.
 *
 * A DDD ValueObject: a transient in-memory result (never persisted). It keeps the Presentation-side
 * {@see \DDD\Presentation\Mcp\Dtos\MCPToolResponse} from leaking into the Domain.
 */
class ToolResult extends ValueObject
{
    /** @var string The serialized tool output the model is fed back as the tool result. */
    public string $content;

    /** @var bool True if the tool reported a failure (→ the call message is recorded as FAILED). */
    public bool $isError;

    /**
     * @var ToolResultImage[] Images the tool produced, SPLIT OUT of {@see $content} — so their base64 never
     * lands in the text the model is billed for. Transient; the bytes-free
     * {@see ToolCallImageReference} is what persists on the tool call.
     */
    public array $images = [];

    /** @var string|null Set when the executor's gate refused the call before it ran ({@see \DDD\Presentation\Mcp\Dtos\MCPToolResponse::$refusalKind}). */
    public ?string $refusalKind = null;

    /**
     * @var array<string, mixed>|null What a bindable result keeps for a `ComposedView` (the stored
     *      form of {@see \DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindableResultRecord}).
     *      It goes onto {@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::$bindableResult}, never into
     *      {@see $content}: the result text, its size column and every reader of it stay as they were.
     */
    public ?array $bindableResult = null;

    /** The tool's platform note for the model — it goes onto {@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::$platformNoteForModel}, never into {@see $content}. */
    public ?string $platformNoteForModel = null;

    /** The widget type the tool's note dictates without a condition — it goes onto {@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::$dictatedWidgetType}. */
    public ?string $dictatedWidgetType = null;

    /**
     * The AI costs a recorded run stored for this result, set only on a PLAYED result
     * ({@see ToolResultRecording::playResult()}): the tool did not run, so nothing was booked; the executor books these
     * on the open recording instead, so the replayed tool call carries the costs the recorded one did.
     */
    public ?AIUsageBookedCosts $recordedBookedCosts = null;

    public function __construct(
        string $content,
        bool $isError = false,
    ) {
        parent::__construct();
        $this->content = $content;
        $this->isError = $isError;
    }
}
