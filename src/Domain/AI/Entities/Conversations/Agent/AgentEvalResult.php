<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Verdict of evaluating one driven conversation against a case's `expect` block. A DDD ValueObject: transient, never
 * persisted. It is NOT a copy of the run — it holds the verdict plus a reference to the {@see AIConversation} itself,
 * whose messages are the lossless trace for failure reporting.
 */
class AgentEvalResult extends ValueObject
{
    /** @var bool True when every assertion held. */
    public bool $passed;

    /** @var string[] Human-readable description of each unmet assertion (empty when passed). */
    public array $failures;

    /** @var string|null The expected tool that was actually found in the run (null if none matched). */
    public ?string $matchedTool;

    /** @var AIConversation The driven conversation — its `messages` carry the full trace (called tools, results,
     *      status, loaded skills, final text, per-message tokens/cost) for reporting on failure. */
    public AIConversation $conversation;

    public function __construct(
        bool $passed,
        array $failures,
        ?string $matchedTool,
        AIConversation $conversation,
    ) {
        parent::__construct();
        $this->passed = $passed;
        $this->failures = $failures;
        $this->matchedTool = $matchedTool;
        $this->conversation = $conversation;
    }
}
