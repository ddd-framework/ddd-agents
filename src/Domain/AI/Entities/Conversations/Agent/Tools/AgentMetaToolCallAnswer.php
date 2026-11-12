<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * How a meta tool answers its own call: the result text the model reads, whether the call
 * failed, and whether the next step must run without tools. The dispatch stores the text, marks the call and raises
 * the loop's flag; the tool never touches the loop.
 *
 * @phpstan-consistent-constructor
 */
class AgentMetaToolCallAnswer extends ValueObject
{
    /** The result text the model reads. */
    public string $text = '';

    /** The call failed (the model is told why); otherwise it completed. */
    public bool $failed = false;

    /** The next step runs without tools: what the turn still owes is its final text. */
    public bool $forcesToolLessAnswerStep = false;

    /** What the call did to the task plan ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::$taskPlanCallOutcome}); null: the answer records none. */
    public ?string $taskPlanCallOutcome = null;

    public static function completed(string $text): static
    {
        $metaToolCallAnswer = new static();
        $metaToolCallAnswer->text = $text;
        return $metaToolCallAnswer;
    }

    public static function failed(string $text): self
    {
        $metaToolCallAnswer = self::completed($text);
        $metaToolCallAnswer->failed = true;
        return $metaToolCallAnswer;
    }

    /** This answer, with the next step running without tools. */
    public function withToolLessAnswerStep(): self
    {
        $this->forcesToolLessAnswerStep = true;
        return $this;
    }

    /** This answer, recording $taskPlanCallOutcome on the call. */
    public function withTaskPlanCallOutcome(string $taskPlanCallOutcome): self
    {
        $this->taskPlanCallOutcome = $taskPlanCallOutcome;
        return $this;
    }
}
