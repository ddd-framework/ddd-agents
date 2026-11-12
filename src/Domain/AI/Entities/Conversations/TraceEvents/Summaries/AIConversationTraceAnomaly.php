<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * ONE anomaly the trace read found — computed on read from rows and trace events, never stored.
 * The inspector renders it as a warning chip on the row it names and sums the codes in the call header.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationTraceAnomaly extends ValueObject
{
    /** The voice model delegated without reading the caller's request back first. */
    public const string CODE_NO_READ_BACK = 'NO_READ_BACK';
    /** The voice model's read-back started before the delegation and was held in the backlog. */
    public const string CODE_READ_BACK_IN_BACKLOG = 'READ_BACK_IN_BACKLOG';
    /** A row from the backlog landed after a row that was spoken later. */
    public const string CODE_ROW_ORDER_INVERTED = 'ROW_ORDER_INVERTED';
    /** The commentary handed to the voice model carried a ```widget fence. */
    public const string CODE_WIDGET_IN_COMMENTARY = 'WIDGET_IN_COMMENTARY';
    /** The agent's answer was cut to the append cap before it was spoken. */
    public const string CODE_ANSWER_CUT = 'ANSWER_CUT';
    /** A caller transcript row under 3 characters. */
    public const string CODE_NOISE_ROW = 'NOISE_ROW';
    /** The turn worker picked the delegated turn up more than 1 s after its dispatch. */
    public const string CODE_SLOW_PICKUP = 'SLOW_PICKUP';
    /** The call trace has no completeness marker — the worker ended without a clean final flush. */
    public const string CODE_TRACE_INCOMPLETE = 'TRACE_INCOMPLETE';

    public const array CODES = [
        self::CODE_NO_READ_BACK, self::CODE_READ_BACK_IN_BACKLOG, self::CODE_ROW_ORDER_INVERTED,
        self::CODE_WIDGET_IN_COMMENTARY, self::CODE_ANSWER_CUT, self::CODE_NOISE_ROW, self::CODE_SLOW_PICKUP,
        self::CODE_TRACE_INCOMPLETE,
    ];

    /** @var string|null One of the CODE_* constants. */
    #[Choice(choices: self::CODES)]
    public ?string $code = null;

    /** @var int|null The conversation row the anomaly belongs to, when it belongs to one. */
    public ?int $messageIndex = null;

    /** @var int|null The voice call it was found on. */
    public ?int $agentVoiceCallId = null;

    /** @var string|null One readable line: what was found. */
    public ?string $detail = null;

    public static function create(string $code, ?int $agentVoiceCallId, ?int $messageIndex, string $detail): static
    {
        $traceAnomaly = new static();
        $traceAnomaly->code = $code;
        $traceAnomaly->agentVoiceCallId = $agentVoiceCallId;
        $traceAnomaly->messageIndex = $messageIndex;
        $traceAnomaly->detail = $detail;

        return $traceAnomaly;
    }
}
