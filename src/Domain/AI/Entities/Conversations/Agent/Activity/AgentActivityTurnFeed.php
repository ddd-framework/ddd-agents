<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Activity;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The activity lines of ONE turn as its readers see them: written by the narration on every exit,
 * read by every surface that shows or speaks what the agent does — the web chat and the voice worker — with one cache
 * read and no database access. Keyed by the ROOT conversation and the turn's initiator index, so a delayed narration
 * of an older turn writes its own key and never overwrites the current one; the pointer key names the turn the
 * newest narration covered.
 *
 * @phpstan-consistent-constructor
 */
class AgentActivityTurnFeed extends ValueObject
{
    /** How long a feed and its pointer live in the cache (seconds) — as long as the cached lines themselves. */
    public const int CACHE_TTL_SECONDS = 7200;

    /** @var int The conversation the turn belongs to — a delegation child's steps are part of its root's turn. */
    public int $rootConversationId = 0;

    /** @var int The index of the message that started the turn (an owner message or a work signal). */
    public int $turnInitiatorIndex = 0;

    /** @var int The root's step generation the narration saw; a reader compares it to notice a new narration. */
    public int $generation = 0;

    /**
     * @var int The newest message index the turn's rows reached when the feed was written. A voice task's delegation
     * signal does not start a turn of its own (the caller's transcript row before it does), so a reader recognises the
     * turn of its task by the rows reaching PAST its signal — a turn before the task ends before the signal.
     */
    public int $newestRowIndex = -1;

    /** @var AgentActivityLines|null The turn's elements in row order, a line where one is narrated. */
    public ?AgentActivityLines $activityLines = null;

    public static function buildCacheKey(int $rootConversationId, int $turnInitiatorIndex): string
    {
        return "agentActivity:turnFeed:{$rootConversationId}:{$turnInitiatorIndex}";
    }

    /** The key that names the turn the newest narration of the root covered. */
    public static function buildCurrentTurnPointerKey(int $rootConversationId): string
    {
        return "agentActivity:currentTurn:{$rootConversationId}";
    }

    public static function buildForTurn(
        int $rootConversationId,
        int $turnInitiatorIndex,
        int $generation,
        AgentActivityLines $activityLines,
        int $newestRowIndex = -1
    ): static {
        $agentActivityTurnFeed = new static();
        $agentActivityTurnFeed->rootConversationId = $rootConversationId;
        $agentActivityTurnFeed->turnInitiatorIndex = $turnInitiatorIndex;
        $agentActivityTurnFeed->generation = $generation;
        $agentActivityTurnFeed->activityLines = $activityLines;
        $agentActivityTurnFeed->newestRowIndex = max($newestRowIndex, $turnInitiatorIndex);
        return $agentActivityTurnFeed;
    }

    /**
     * True when the feed is the turn a reader's task belongs to or a newer one: the turn's rows reach the task's message
     * (a voice task's delegation signal sits INSIDE the turn the caller's row started — the turn-start line is narrated
     * while the signal is its newest row). A turn older than the task ends before the task's message.
     */
    public function coversTaskAtMessageIndex(int $taskMessageIndex): bool
    {
        return $this->newestRowIndex >= $taskMessageIndex;
    }

    /**
     * The narrated lines a reader has not handled yet, in row order — `$handledElementKeys` are the
     * {@see buildElementKey()} keys the reader already spoke or passed on.
     *
     * @param string[] $handledElementKeys
     * @return AgentActivityLine[]
     */
    public function getNarratedLinesNotYetHandled(array $handledElementKeys): array
    {
        $narratedLines = [];
        foreach ($this->activityLines?->getElements() ?? [] as $activityLine) {
            if ($activityLine->line === null || $activityLine->line === ''
                || in_array(self::buildElementKey($activityLine), $handledElementKeys, true)
            ) {
                continue;
            }
            $narratedLines[] = $activityLine;
        }
        return $narratedLines;
    }

    /**
     * The steps that still wait for their line, in row order (skipped elements never get one), minus the handled ones.
     *
     * @param string[] $handledElementKeys
     * @return AgentActivityLine[]
     */
    public function getStepsWithoutLineNotYetHandled(array $handledElementKeys): array
    {
        $stepsWithoutLine = [];
        foreach ($this->activityLines?->getElements() ?? [] as $activityLine) {
            if (!$activityLine->wantsLine() || ($activityLine->line !== null && $activityLine->line !== '')
                || in_array(self::buildElementKey($activityLine), $handledElementKeys, true)
            ) {
                continue;
            }
            $stepsWithoutLine[] = $activityLine;
        }
        return $stepsWithoutLine;
    }

    /** One element's identity for a reader: the step and its state (a step is narrated once per state). */
    public static function buildElementKey(AgentActivityLine $activityLine): string
    {
        return $activityLine->toolCallMessageId . ':' . $activityLine->state;
    }

    /** The plain array the cache stores — scalars only, so every reader decodes it without the serializer. */
    public function toCacheArray(): array
    {
        $elementArrays = [];
        foreach ($this->activityLines?->getElements() ?? [] as $activityLine) {
            $elementArrays[] = [
                'toolCallMessageId' => $activityLine->toolCallMessageId,
                'state' => $activityLine->state,
                'kind' => $activityLine->kind,
                'helperIndex' => $activityLine->helperIndex,
                'line' => $activityLine->line,
                'narratorToolName' => $activityLine->narratorToolName,
            ];
        }
        return [
            'rootConversationId' => $this->rootConversationId,
            'turnInitiatorIndex' => $this->turnInitiatorIndex,
            'generation' => $this->generation,
            'newestRowIndex' => $this->newestRowIndex,
            'elements' => $elementArrays,
        ];
    }

    /** The feed a cache entry holds, or null for an entry that is no feed. */
    public static function fromCacheArray(mixed $cacheArray): ?self
    {
        if (!is_array($cacheArray) || !isset($cacheArray['rootConversationId'], $cacheArray['turnInitiatorIndex'])) {
            return null;
        }
        $activityLines = new AgentActivityLines();
        foreach (is_array($cacheArray['elements'] ?? null) ? $cacheArray['elements'] : [] as $elementArray) {
            if (!is_array($elementArray)) {
                continue;
            }
            $activityLine = new AgentActivityLine();
            $activityLine->toolCallMessageId = (int)($elementArray['toolCallMessageId'] ?? 0);
            $activityLine->state = (string)($elementArray['state'] ?? AgentActivityLine::STATE_QUEUED);
            $activityLine->kind = (string)($elementArray['kind'] ?? AgentActivityLine::KIND_TOOL);
            $activityLine->helperIndex = isset($elementArray['helperIndex']) ? (int)$elementArray['helperIndex'] : null;
            $activityLine->line = isset($elementArray['line']) && is_string($elementArray['line']) ? $elementArray['line'] : null;
            $activityLine->narratorToolName = (string)($elementArray['narratorToolName'] ?? '');
            $activityLines->add($activityLine);
        }
        return self::buildForTurn(
            (int)$cacheArray['rootConversationId'],
            (int)$cacheArray['turnInitiatorIndex'],
            (int)($cacheArray['generation'] ?? 0),
            $activityLines,
            (int)($cacheArray['newestRowIndex'] ?? -1)
        );
    }
}
