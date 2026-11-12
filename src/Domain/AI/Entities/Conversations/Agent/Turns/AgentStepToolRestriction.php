<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;

/**
 * What one model call of the loop sends as `tools` and `tool_choice` when the step narrows its offer: the seeded-plan purpose's set, the forced first step of a supervisor steer or a work signal, the plan-tick
 * brake, the stall recovery's forced re-drive.
 *
 * Under the system-prompt swap the call sends the narrowed set, as it always did (byte for byte). Under append-only
 * loading the top-level `tools` and the `additional_tools` items are part of the cached prefix, so a narrowed array would
 * cost the whole prefix on that step and on the step after: the call sends the step's whole offer instead, the narrowing
 * is enforced by the dispatch (a withheld tool's call is refused with {@see buildRefusalText()}, the seeded-plan tools
 * with their own refusal), and a step whose narrowed set is empty sends `tool_choice: none` — a probe kept
 * the prefix cached across a `tool_choice` change, while `allowed_tools` renders into the prompt and is never used.
 * Pure.
 *
 * @phpstan-consistent-constructor
 */
class AgentStepToolRestriction
{
    /** @var array<int, array<string, mixed>> The `tools` the call sends. */
    public array $toolSchemasToSend = [];

    /** @var string|array<string, mixed>|null The `tool_choice` the call sends (null = the provider's default). */
    public string|array|null $toolChoiceToSend = null;

    /** @var string[] The offered tools this step holds back while they stay on the wire — the dispatch refuses their calls. */
    public array $withheldToolNames = [];

    /**
     * Whether this step holds the plan tools back for the plan-tick brake (repeated rejected ticks). A refused call of a
     * plan tool on such a step makes the next step the tool-less answer step: the tools never leave the wire, so another
     * `auto` step would only invite the next refused call.
     */
    public bool $holdsBackPlanToolsAfterRejectedTicks = false;

    /**
     * The call of one step: `$stepTools` is the step's narrowed set of `$offeredTools`, `$stepToolChoice` the step's
     * tool choice; `$keepsTheOfferedToolsOnTheWire` true under append-only loading.
     *
     * @param string|array<string, mixed>|null $stepToolChoice
     */
    public static function forStep(bool $keepsTheOfferedToolsOnTheWire, AgentOfferedTools $offeredTools, AgentOfferedTools $stepTools, string|array|null $stepToolChoice): static
    {
        $agentStepToolRestriction = new static();
        if (!$keepsTheOfferedToolsOnTheWire) {
            $agentStepToolRestriction->toolSchemasToSend = $stepTools->toOpenAiToolSchemas();
            $agentStepToolRestriction->toolChoiceToSend = $stepToolChoice;
            return $agentStepToolRestriction;
        }
        $agentStepToolRestriction->toolSchemasToSend = $offeredTools->toOpenAiToolSchemas();
        $agentStepToolRestriction->withheldToolNames = array_values(array_diff($offeredTools->getToolNames(), $stepTools->getToolNames()));
        $agentStepToolRestriction->toolChoiceToSend = $stepTools->isEmpty() && !$offeredTools->isEmpty()
            ? AgentStepToolChoice::MODE_NONE
            : $stepToolChoice;
        return $agentStepToolRestriction;
    }

    /** Whether this step holds an offered tool back on the wire (the dispatch reads it from {@see AgentRun::$stepToolRestriction}). */
    public function restrictsTools(): bool
    {
        return $this->withheldToolNames !== [];
    }

    public function withholdsTool(string $toolName): bool
    {
        return in_array($toolName, $this->withheldToolNames, true);
    }

    /** Marks this step as holding the plan tools back for the plan-tick brake ({@see $holdsBackPlanToolsAfterRejectedTicks}). */
    public function markPlanToolsHeldBackAfterRejectedTicks(): void
    {
        $this->holdsBackPlanToolsAfterRejectedTicks = true;
    }

    /** Whether a refused call of `$toolName` ends the model's tool work for this turn's next step (a plan tool held back by the brake). */
    public function answersWithheldCallWithToolLessStep(string $toolName): bool
    {
        return $this->holdsBackPlanToolsAfterRejectedTicks && in_array($toolName, TaskPlan::PLAN_TOOL_NAMES, true);
    }

    /** The refusal of a withheld tool's call: what happened and the valid next move (under the brake: answer now). */
    public function buildRefusalText(string $toolName): string
    {
        if ($this->answersWithheldCallWithToolLessStep($toolName)) {
            return sprintf(
                'Not executed: `%s` is held back after the rejected plan updates of this turn. Answer the owner now from what you '
                    . 'have — the next step has no tools.',
                $toolName
            );
        }
        return sprintf(
            'Not executed: `%s` is not available on this step — the platform holds it back for this one model call. Do the '
                . 'step with another of your tools now, or answer; `%s` is available again on a later step.',
            $toolName,
            $toolName
        );
    }
}
