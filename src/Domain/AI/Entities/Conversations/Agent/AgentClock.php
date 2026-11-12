<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DateTimeZone;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Traits\Serializer\SerializerRegistry;

/**
 * Model-facing renders of moments and days DURING tool execution (the executor's model-facing region).
 *
 * Structured tool results convert their `DateTime` properties through the serializer flag; anything a tool renders
 * into TEXT itself (`toLlmMarkdown()`, a note, a summary line) goes through these two helpers so it shows the same
 * clock: the conversation's zone from the serializer registry, UTC when no zone is set (a legacy conversation, a
 * REST or console caller). Pure — never reads "now".
 */
class AgentClock
{
    /**
     * A moment as the model should read it: local wall-clock time WITH its offset, e.g. `2026-09-14 10:30:00-04:00`.
     * `$timezone` is for renders that run OUTSIDE the executor's model-facing region (a tool body composing prose,
     * the loop composing a note) — they pass the conversation's zone explicitly; inside the region it is omitted.
     */
    public static function formatMoment(DateTime $moment, ?DateTimeZone $timezone = null): string
    {
        return $moment->formatForModel(self::modelFacingTimezone($timezone));
    }

    /** A moment shown as a DAY: converted into the model's zone first, then truncated to `Y-m-d`. */
    public static function formatDay(DateTime $moment, ?DateTimeZone $timezone = null): string
    {
        return (clone $moment)->setTimezone(self::modelFacingTimezone($timezone))->format('Y-m-d');
    }

    /** An explicit zone, else the executor region's zone, else UTC. */
    public static function modelFacingTimezone(?DateTimeZone $timezone = null): DateTimeZone
    {
        return $timezone ?? SerializerRegistry::$modelFacingTimezone ?? new DateTimeZone('UTC');
    }
}
