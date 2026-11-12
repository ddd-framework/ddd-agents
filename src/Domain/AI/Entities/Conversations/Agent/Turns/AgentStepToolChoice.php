<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * Which tool use one model call of the loop asks for, and why (formerly a bare
 * `string|array|null`): the provider's default (`auto`), no tool (`none` — the tool-less answer step), any tool
 * (`required` — a supervisor steer or the stall recovery's re-drive) or one named tool (`function`).
 *
 * @phpstan-consistent-constructor
 */
class AgentStepToolChoice extends ValueObject
{
    public const string MODE_AUTO = 'auto';
    public const string MODE_NONE = 'none';
    public const string MODE_REQUIRED = 'required';
    public const string MODE_FUNCTION = 'function';

    public const string REASON_DEFAULT = 'default';
    public const string REASON_TOOL_LESS_ANSWER_STEP = 'toolLessAnswerStep';
    public const string REASON_FORCED_FIRST_STEP = 'forcedFirstStep';
    public const string REASON_STALL_REDRIVE = 'stallRedrive';
    /** The EMAIL run ended with a text and no `send_owner_report` call — one step pinned to that call. */
    public const string REASON_FORCED_OWNER_REPORT_CALL = 'forcedOwnerReportCall';

    #[Choice(choices: [self::MODE_AUTO, self::MODE_NONE, self::MODE_REQUIRED, self::MODE_FUNCTION])]
    public string $mode = self::MODE_AUTO;

    /** The one tool a `function` choice names. */
    public ?string $functionName = null;

    /** Why the loop chose it (one of the REASON_* constants). */
    public string $reason = self::REASON_DEFAULT;

    public static function auto(): static
    {
        return new static();
    }

    public static function none(string $reason): self
    {
        return self::withMode(self::MODE_NONE, $reason);
    }

    public static function required(string $reason): self
    {
        return self::withMode(self::MODE_REQUIRED, $reason);
    }

    public static function function(string $functionName, string $reason): self
    {
        $stepToolChoice = self::withMode(self::MODE_FUNCTION, $reason);
        $stepToolChoice->functionName = $functionName;
        return $stepToolChoice;
    }

    /**
     * The `tool_choice` of one step of the loop (formerly inline in
     * `runConversationLoop()`), pure:
     * - a tool-less answer step (the write-perseveration breaker, the budget closing step): `none` — the model writes its
     *   closing answer NOW;
     * - a step a supervisor steer or a work order forces: `required`, pinned to ONE function when the armed steer names its
     *   expected tool (the machine-written `Expected tool:` protocol line) — for a work order without one, the plan's
     *   FIRST open stage gate (the first stage's tool on a run that has done nothing yet) — AND that tool is among the
     *   step's tools. When the expected tool is NOT loaded yet the choice stays `required`: the proven recovery route
     *   (forced call → "tool belongs to skill X" → load_skills → the real call) must remain reachable;
     * - a step the loop pins to one tool (`$forcedFunctionName`, e.g. the EMAIL run's closing `send_owner_report`
     *   call) when that tool is among the step's tools: `function`;
     * - otherwise the provider's default.
     */
    public static function buildForStep(
        bool $toolLessAnswerStep,
        bool $supervisorSteerForcesStep,
        bool $workSignalForcesFirstStep,
        ?string $steerExpectedToolName,
        ?string $firstOpenDoneGateToolName,
        AgentOfferedTools $stepTools,
        ?string $forcedFunctionName = null
    ): self {
        if ($toolLessAnswerStep) {
            return self::none(self::REASON_TOOL_LESS_ANSWER_STEP);
        }
        if ($forcedFunctionName !== null && $stepTools->offersTool($forcedFunctionName)) {
            return self::function($forcedFunctionName, self::REASON_FORCED_OWNER_REPORT_CALL);
        }
        if (!$supervisorSteerForcesStep) {
            return self::auto();
        }
        $expectedToolName = $steerExpectedToolName === null && $workSignalForcesFirstStep ? $firstOpenDoneGateToolName : $steerExpectedToolName;
        if ($expectedToolName !== null && $stepTools->offersTool($expectedToolName)) {
            return self::function($expectedToolName, self::REASON_FORCED_FIRST_STEP);
        }
        return self::required(self::REASON_FORCED_FIRST_STEP);
    }

    protected static function withMode(string $mode, string $reason): static
    {
        $stepToolChoice = new static();
        $stepToolChoice->mode = $mode;
        $stepToolChoice->reason = $reason;
        return $stepToolChoice;
    }

    /**
     * The value the model call sends as `tool_choice`: null for the provider's default, the mode string for `none` /
     * `required`, the function object for one named tool.
     *
     * @return string|array{type: string, function: array{name: string}}|null
     */
    public function toWireValue(): string|array|null
    {
        return match ($this->mode) {
            self::MODE_NONE, self::MODE_REQUIRED => $this->mode,
            self::MODE_FUNCTION => ['type' => 'function', 'function' => ['name' => (string)$this->functionName]],
            default => null,
        };
    }
}
