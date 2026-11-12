<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Context;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The full, labelled breakdown of ONE agent model call's context — the system-message parts, the tool
 * schemas, and the wire message array, each as an {@see AgentContextSegment}. Built ON DEMAND by
 * {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::buildContextComposition()} for admin
 * observability; NEVER persisted (no new DB column — it is reconstructed from the message's already-stored
 * {@see \DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot}).
 *
 * The single carrier to the frontend (a response DTO wraps it). The FE renders each segment by its own
 * {@see AgentContextSegment::getLabel()} + {@see AgentContextSegment::$tokenCount}.
 *
 * @phpstan-consistent-constructor
 */
class ModelCallComposition extends ValueObject
{
    /** @var AgentContextSegment[] The composed system-message parts, in compose order (base → … → compaction). */
    public array $systemSegments = [];

    /** @var AgentContextSegment[] The tool-schema block (one segment carrying the whole `tools` array). */
    public array $toolSchemaSegments = [];

    /** @var AgentContextSegment[] The wire message array (user / assistant / tool-call / tool-result / signal), in order. */
    public array $messageSegments = [];

    /**
     * True when this message carries no {@see RequestSnapshot} (a user / tool-result / pre-feature message that never
     * produced a model call) — there is nothing to compose. The FE shows "no composition (no request snapshot)".
     */
    public bool $isUnavailable = false;

    /**
     * Whether this composition is a DYNAMIC point-in-time RECONSTRUCTION (true for an assistant turn). There is no
     * stored payload to diff against anymore (the dynamic-context plan dropped it), so this is no longer a drift
     * flag — it tells the FE "this is rebuilt from the immutable rows + the compaction cover + the CURRENT prompt /
     * skills / memory, so it may differ from what was literally sent if those drifted since the turn". Null when
     * unavailable (a non-model-call message with no snapshot).
     */
    public ?bool $isReconstructed = null;

    public static function unavailable(): static
    {
        $modelCallComposition = new static();
        $modelCallComposition->isUnavailable = true;
        return $modelCallComposition;
    }

    /** The sum of every segment's token cost across all three groups (estimate where any segment is an estimate). */
    public function getTotalTokens(): int
    {
        $total = 0;
        foreach ([$this->systemSegments, $this->toolSchemaSegments, $this->messageSegments] as $segmentGroup) {
            foreach ($segmentGroup as $segment) {
                $total += $segment->tokenCount ?? 0;
            }
        }
        return $total;
    }

    /**
     * Empties the text of every segment whose type is not named — the table (type, label, tokens, skills, provenance)
     * stays whole. A reader that cannot hold the whole composition (an external agent) reads the table first
     * and asks for the texts it needs; the emptied text names how to get it.
     *
     * @param string[] $segmentTypeValues {@see AgentContextSegmentType} values whose text is kept
     */
    public function keepSegmentTextsOnlyForTypes(array $segmentTypeValues): void
    {
        foreach ([$this->systemSegments, $this->toolSchemaSegments, $this->messageSegments] as $segmentGroup) {
            foreach ($segmentGroup as $segment) {
                $keepsText = in_array($segment->type->value, $segmentTypeValues, true);
                if (!$keepsText) {
                    $segment->text = '(text left out — ask with includeSegmentTypes: ["' . $segment->type->value . '"])';
                }
                // the cover nodes stay as provenance (level + range); their embedding and back-reference are never read here, their
                // summary text is the segment's text again
                foreach ($segment->compactionNodes?->getElements() ?? [] as $compactionNode) {
                    $compactionNode->addPropertiesToHide('summaryEmbedding', 'aiConversation');
                    if (!$keepsText) {
                        $compactionNode->addPropertiesToHide('summaryText', 'preservedFacts');
                    }
                }
            }
        }
    }
}
