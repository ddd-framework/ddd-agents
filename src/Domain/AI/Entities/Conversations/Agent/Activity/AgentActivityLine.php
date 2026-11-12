<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Activity;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * ONE line the customer reads while the agent works: what one tool call of the running turn is doing, or
 * what came of it. Transient — built from the persisted TOOL_CALL row, narrated by a small model while a customer
 * watches, cached, never persisted and never part of the agent's own context. The customer wire carries this object
 * and nothing of the row it was made from (no tool name, no arguments, no result).
 */
class AgentActivityLine extends ValueObject
{
    /** The call is persisted and not executed yet. */
    public const string STATE_QUEUED = 'queued';

    /** The call executes now. */
    public const string STATE_RUNNING = 'running';

    /** The call returned a result. */
    public const string STATE_FINISHED = 'finished';

    /** The call failed; the customer is told that it did not work, never why. */
    public const string STATE_FAILED = 'failed';

    /** A refused duplicate or a gate refusal: nothing ran, nothing is narrated. */
    public const string STATE_SKIPPED = 'skipped';

    /** An ordinary read or write on the customer's data. */
    public const string KIND_TOOL = 'tool';

    /** A `delegate` call: helpers work in parallel. */
    public const string KIND_DELEGATION = 'delegation';

    /** A background job the conversation does not wait for. */
    public const string KIND_BACKGROUND = 'background';

    /** A remote job that takes a while. */
    public const string KIND_LONG = 'long';

    /**
     * The turn's opening line: what the assistant does now for the owner's message, before its
     * first step. Stands for the owner message (`toolCallMessageId` is that message's id), always the turn's first element.
     */
    public const string KIND_TURN_START = 'turnStart';

    /** @var int The TOOL_CALL message this line stands for (the owner message for {@see KIND_TURN_START}) — the element's identity across requests. */
    public int $toolCallMessageId = 0;

    /** @var string|null The batch id of the model response that emitted the call; calls of one response share it. */
    public ?string $batchId = null;

    #[Choice(choices: [self::STATE_QUEUED, self::STATE_RUNNING, self::STATE_FINISHED, self::STATE_FAILED, self::STATE_SKIPPED])]
    public string $state = self::STATE_QUEUED;

    #[Choice(choices: [self::KIND_TOOL, self::KIND_DELEGATION, self::KIND_BACKGROUND, self::KIND_LONG, self::KIND_TURN_START])]
    public string $kind = self::KIND_TOOL;

    /** @var int For a delegation: how many helpers work in parallel. */
    public int $childCount = 0;

    /** @var int|null For a step one of the delegation's helpers runs: the helper's number, 1-based; null for the assistant's own steps. */
    public ?int $helperIndex = null;

    /** @var string|null The sentence the customer reads; null until narrated (the frontend shows the state alone). */
    public ?string $line = null;

    /**
     * What the narrator gets to see for this element and NOTHING else — set by the service, never serialized to the
     * customer wire (hidden below). The tool name and the cut arguments; the result head only for finished/failed.
     */
    public string $narratorToolName = '';

    public string $narratorArguments = '';

    public ?string $narratorResultHead = null;

    public function __construct()
    {
        parent::__construct();
        $this->addPropertiesToHide('narratorToolName', 'narratorArguments', 'narratorResultHead');
    }

    /** A line that the narrator writes: skipped elements carry no sentence. */
    public function wantsLine(): bool
    {
        return $this->state !== self::STATE_SKIPPED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [self::STATE_FINISHED, self::STATE_FAILED, self::STATE_SKIPPED], true);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->toolCallMessageId . ':' . $this->state);
    }
}
