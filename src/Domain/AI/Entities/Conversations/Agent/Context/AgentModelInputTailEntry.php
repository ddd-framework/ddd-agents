<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Context;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One entry of the model input's volatile TAIL: a transient `role:user` wire entry the
 * egress appends after the history fold — never persisted, outside the cacheable prefix. Built only by
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput::buildTailEntries()}; the live egress turns it into its
 * wire message ({@see self::toWireMessage()}), the admin composition view into an {@see AgentContextSegment} of
 * {@see $segmentType} carrying {@see self::buildCompositionText()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentModelInputTailEntry extends ValueObject
{
    /** The opt-in "data already fetched — reuse it" list of completed reads. */
    public const string KIND_RECENT_READS_HINT = 'RECENT_READS_HINT';

    /** The model's per-step view of its task plan, led by the owner-turn counter line. */
    public const string KIND_TASK_PLAN = 'TASK_PLAN';

    /** The decision model's plan directive for this owner turn. */
    public const string KIND_PLAN_DIRECTIVE = 'PLAN_DIRECTIVE';

    /** The `[SIGNAL:widget]` line of the turn's decided direct widget. */
    public const string KIND_DIRECT_WIDGET = 'DIRECT_WIDGET';

    /** The answer contract of a turn that completed writes or received pictures. */
    public const string KIND_ANSWER_CONTRACT = 'ANSWER_CONTRACT';

    /** The resolved tool pictures, when they cannot ride inside their tool results. */
    public const string KIND_IMAGE_FALLBACK = 'IMAGE_FALLBACK';

    /** The `[SIGNAL:situation]` line: the values of the SITUATION line the owner has not confirmed. */
    public const string KIND_SITUATION = 'SITUATION';

    /** The part of the local situation the application repeats beside the newest message. */
    public const string KIND_LOCAL_SITUATION = 'LOCAL_SITUATION';

    /** The `[SIGNAL:time-zone]` line: the `# Time` zone is unverified (a country default or the schedule's zone). */
    public const string KIND_TIME_ZONE = 'TIME_ZONE';

    /** The `[SIGNAL:clock]` line: the captured "now" of this model call. */
    public const string KIND_CLOCK = 'CLOCK';

    /** The `[SIGNAL:answer-step]` line of a step whose tools are paused (`tool_choice = none`). */
    public const string KIND_ANSWER_STEP = 'ANSWER_STEP';

    /** The reply-language cue — always present, always the last entry. */
    public const string KIND_REPLY_LANGUAGE_CUE = 'REPLY_LANGUAGE_CUE';

    /** One of the `KIND_*` constants; the tail holds each kind at most once. */
    public string $kind;

    /** The segment type the admin composition view shows this entry as. */
    public AgentContextSegmentType $segmentType;

    /** The entry's text content (for {@see self::KIND_IMAGE_FALLBACK}: the leading label of its content parts). */
    public string $text = '';

    /**
     * @var array<int, array<string, mixed>>|null The OpenAI content parts when the entry carries more than text (the
     *      pictures of {@see self::KIND_IMAGE_FALLBACK}); null = a plain text entry.
     */
    public ?array $contentParts = null;

    public static function ofText(string $kind, AgentContextSegmentType $segmentType, string $text): static
    {
        $agentModelInputTailEntry = new static();
        $agentModelInputTailEntry->kind = $kind;
        $agentModelInputTailEntry->segmentType = $segmentType;
        $agentModelInputTailEntry->text = $text;
        return $agentModelInputTailEntry;
    }

    /**
     * @param array<int, array<string, mixed>> $contentParts
     */
    public static function ofContentParts(string $kind, AgentContextSegmentType $segmentType, string $text, array $contentParts): self
    {
        $agentModelInputTailEntry = self::ofText($kind, $segmentType, $text);
        $agentModelInputTailEntry->contentParts = $contentParts;
        return $agentModelInputTailEntry;
    }

    /**
     * The OpenAI Chat wire entry: a `role:user` message whose content is the text, or the content parts when set.
     *
     * @return array<string, mixed>
     */
    public function toWireMessage(): array
    {
        return ['role' => 'user', 'content' => $this->contentParts ?? $this->text];
    }

    /**
     * The text the admin composition view shows for this entry: the text itself, or — for content parts — every text
     * part on its own line and one `[image <mime type>]` marker per picture (the bytes stay out of the view).
     */
    public function buildCompositionText(): string
    {
        if ($this->contentParts === null) {
            return $this->text;
        }
        $compositionLines = [];
        foreach ($this->contentParts as $contentPart) {
            if (($contentPart['type'] ?? null) === 'text') {
                $compositionLines[] = (string)($contentPart['text'] ?? '');
                continue;
            }
            $imageUrl = (string)($contentPart['image_url']['url'] ?? '');
            $imageMimeType = preg_match('#^data:([^;,]+)[;,]#', $imageUrl, $mimeTypeMatch) === 1 ? $mimeTypeMatch[1] : 'url';
            $compositionLines[] = '[image ' . $imageMimeType . ']';
        }
        return implode("\n", $compositionLines);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->kind);
    }
}
