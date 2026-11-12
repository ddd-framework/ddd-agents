<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The facts a {@see AIConversationCompactionNode} preserves VERBATIM, bypassing summarization. Persisted
 * as one JSON column on the node (the DDD ValueObject→JSON convention — a bare `array` property is unsupported by the
 * entity model generator, so a persisted entity carries its arrays inside a value object).
 *
 * - {@see $identifiers}: deterministically extracted from the covered span's tool calls (the ids of the account, the
 *   space and the application's records, names, URLs) — code writes them, the cheap model never authors an id (a
 *   paraphrased id silently targets the wrong entity).
 * - {@see $standingConstraints}: standing rules the user stated ("only English", "never cancel a booking") —
 *   carried verbatim across every level and re-derived from source on a from-raw node, never inherited-and-eroded.
 * - {@see $toolTelemetry}: the covered span's COMPLETED tool counts + write excerpts for the SUPERVISOR
 *   — node data only, never rendered into the summariser input or the agent-side summary text.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationCompactionPreservedFacts extends ValueObject
{
    /** @var string[] Verbatim identifiers extracted from the covered span. */
    public array $identifiers = [];

    /** @var string[] Standing constraints carried verbatim across the tree. */
    public array $standingConstraints = [];

    /** @var AIConversationCompactionToolTelemetry|null The span's tool telemetry; null on a node written before the slot existed. */
    public ?AIConversationCompactionToolTelemetry $toolTelemetry = null;

    /**
     * Deterministically extract the identifiers of raw messages — code writes ids, the cheap model never authors one.
     * Standing constraints are carried/merged at roll-up; a leaf starts with the prose-carried set empty. Pure.
     *
     * @param AIConversationMessage[] $rawMessagesInRange
     */
    public static function buildForMessages(array $rawMessagesInRange): static
    {
        $preservedFacts = new static();
        $identifiers = [];
        foreach ($rawMessagesInRange as $message) {
            foreach ($message->extractToolCallIdentifiers() as $identifier) {
                $identifiers[$identifier] = true;
            }
            // user-stated quoted facts ("remember: my workshop is named 'X'") — prose the cheap model pulls unreliably.
            foreach ($message->extractQuotedUserFacts() as $quotedFact) {
                $identifiers[$quotedFact] = true;
            }
        }
        $preservedFacts->identifiers = array_keys($identifiers);
        // The supervisor's tool telemetry — the same walk, one more deterministic slot; node
        // data only (never part of the summariser input or the agent-side summary text).
        $preservedFacts->toolTelemetry = AIConversationCompactionToolTelemetry::fromMessages($rawMessagesInRange);
        return $preservedFacts;
    }

    /**
     * Merge the preserved facts of the K children a roll-up consolidates — identifiers + standing constraints carried
     * VERBATIM upward (never eroded). Pure.
     *
     * @param AIConversationCompactionNode[] $childNodes
     */
    public static function mergeOf(array $childNodes): static
    {
        $merged = new static();
        $identifiers = [];
        $standingConstraints = [];
        $childToolTelemetries = [];
        foreach ($childNodes as $childNode) {
            foreach ($childNode->preservedFacts->identifiers ?? [] as $identifier) {
                $identifiers[$identifier] = true;
            }
            foreach ($childNode->preservedFacts->standingConstraints ?? [] as $standingConstraint) {
                $standingConstraints[$standingConstraint] = true;
            }
            // Null for a child without the slot (an older node) — the merge then flags its excerpts as incomplete.
            $childToolTelemetries[] = $childNode->preservedFacts->toolTelemetry ?? null;
        }
        $merged->identifiers = array_keys($identifiers);
        $merged->standingConstraints = array_keys($standingConstraints);
        $merged->toolTelemetry = AIConversationCompactionToolTelemetry::merge($childToolTelemetries);
        return $merged;
    }

}
