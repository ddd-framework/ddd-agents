<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the activity narrator wrote for one tool call when a conversation was replayed (the activity-lines console
 * command): the call, and the line of each moment the narrator was asked for — the started moment (arguments only)
 * and the finished moment (with the result head). Identified by the tool-call message
 * ({@see AgentActivityLineElementReplays}).
 *
 * @phpstan-consistent-constructor
 */
class AgentActivityLineElementReplay extends ValueObject
{
    /** @var string The moment a call starts: the narrator sees the arguments only */
    public const string MOMENT_STARTED = 'started';

    /** @var string The moment a call has finished: the narrator sees the result head too */
    public const string MOMENT_FINISHED = 'finished';

    /** @var int The tool-call message */
    public int $toolCallMessageId = 0;

    /** @var string The tool name the narrator was given */
    public string $toolName = '';

    /** @var string The state of the element at the first moment it was narrated ({@see AgentActivityLine::$state}) */
    public string $state = '';

    /** @var string The kind of the element ({@see AgentActivityLine::$kind}) */
    public string $kind = '';

    /** @var bool Whether the narrator was asked for the started moment */
    public bool $isStartedMomentNarrated = false;

    /** @var string|null The line written for the started moment; null when none came back */
    public ?string $startedLine = null;

    /** @var bool Whether the narrator was asked for the finished moment */
    public bool $isFinishedMomentNarrated = false;

    /** @var string|null The line written for the finished moment; null when none came back */
    public ?string $finishedLine = null;

    /**
     * The replay of an element, described by the activity line it was first narrated with.
     *
     * @param AgentActivityLine $activityLine
     * @return static
     */
    public static function forActivityLine(AgentActivityLine $activityLine): static
    {
        $agentActivityLineElementReplay = new static();
        $agentActivityLineElementReplay->toolCallMessageId = $activityLine->toolCallMessageId;
        $agentActivityLineElementReplay->toolName = $activityLine->narratorToolName;
        $agentActivityLineElementReplay->state = $activityLine->state;
        $agentActivityLineElementReplay->kind = $activityLine->kind;
        return $agentActivityLineElementReplay;
    }

    /**
     * Records the line the narrator wrote for a moment.
     *
     * @param string $moment {@see self::MOMENT_STARTED} or {@see self::MOMENT_FINISHED}
     * @param string|null $line null when no line came back
     * @return void
     */
    public function recordLineOfMoment(string $moment, ?string $line): void
    {
        if ($moment === self::MOMENT_STARTED) {
            $this->isStartedMomentNarrated = true;
            $this->startedLine = $line;
            return;
        }
        $this->isFinishedMomentNarrated = true;
        $this->finishedLine = $line;
    }

    /**
     * @param int $toolCallMessageId
     * @return string the key of the element of that tool-call message in an {@see AgentActivityLineElementReplays}
     */
    public static function uniqueKeyForToolCallMessageId(int $toolCallMessageId): string
    {
        return self::class . '_' . $toolCallMessageId;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyForToolCallMessageId($this->toolCallMessageId);
    }
}
