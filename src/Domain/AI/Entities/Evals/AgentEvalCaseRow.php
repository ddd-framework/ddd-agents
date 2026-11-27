<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentEvalResult;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanSize;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The result row of one evaluated case, as a run writes it into its case file, its result file and its summary.
 *
 * A row is an open map: what a run measures differs per flavor and per kind of case, and every key keeps the name, the
 * order and the type of the stored row. The keys a suite reads have accessors here — `case`, `suite`, `passed`,
 * `error`, `outcome` with `notApplicableReason` / `notApplicableDetail`, `performance` (the figures of
 * {@see AgentEvalRunFigures::toArray()}), `durationMs`, `processSeconds`, `restoreFailed`, `retriedAfter`, `messages`;
 * every other key is read with {@see getValue()}. {@see toArray()} gives back the row exactly as it was built and
 * stamped, in the order of its keys.
 *
 * A row whose case does not apply to its agent space is NOT APPLICABLE ({@see buildNotApplicable()}): it carries no
 * pass (`passed: null`) and counts neither as a pass nor as a fail.
 *
 * Each kind of evaluated case has its row factory, which writes the keys in the order the stored files have always
 * had them: the deterministic run of a tools case ({@see buildForDeterministicRun()}), its judged run
 * ({@see buildForJudgedRun()}), a run that ended in an error ({@see buildFailedOfCase()}), a case that does not apply
 * ({@see buildNotApplicableOfCase()}), a selection case ({@see buildForSelectionRun()}), a recall case
 * ({@see buildForRecallRun()}) and the rows a parent writes for a child process that timed out or printed no result
 * ({@see buildForTimedOutChildProcess()}, {@see buildForUnparseableChildOutput()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseRow extends ValueObject
{
    /** @var string The `outcome` of a row whose case does not apply to its agent space */
    public const string OUTCOME_NOT_APPLICABLE = 'notApplicable';

    /** @var string The prefix of a line of a child process's error output that reports a restore step of the harness */
    public const string HARNESS_LOG_LINE_PREFIX = 'restore ';

    /** @var string The prefix of a harness line that reports the capture before the case (a failed capture changes nothing) */
    public const string HARNESS_CAPTURE_LINE_PREFIX = 'restore capture ';

    /** @var string[] The markers of a harness line that reports a refused restore step */
    public const array REFUSED_HARNESS_STEP_MARKERS = ['[ERROR]', 'was NOT executed', '"isError":true'];

    /** @var string The error of the row a parent writes for a child process it ended at its ceiling */
    public const string CHILD_PROCESS_TIMED_OUT_ERROR = 'child process timed out';

    /** @var string The error of the row a parent writes for a child process whose output holds no result */
    public const string CHILD_OUTPUT_UNPARSEABLE_ERROR = 'no parseable JSON from child process';

    /** @var string The flavor a judged run writes into its rows */
    public const string FLAVOR_JUDGE = AgentEvalSuiteTask::FLAVOR_JUDGE;

    /** @var array<string, mixed> The row's keys and values, in their order */
    protected array $rowFields = [];

    /**
     * The row of the decoded object `$rowFields`, as it was written.
     *
     * @param array<string, mixed> $rowFields
     * @return static
     */
    public static function fromArray(array $rowFields): static
    {
        $agentEvalCaseRow = static::newInstance();
        $agentEvalCaseRow->rowFields = $rowFields;
        return $agentEvalCaseRow;
    }

    /**
     * The row of a case that was not run because it does not apply: the identifying keys `$identifyingFields` (skill,
     * case, tool, flavor, the scope), then `passed: null`, the outcome and the reason.
     *
     * @param array<string, mixed> $identifyingFields
     * @param AgentEvalNotApplicableReason $agentEvalNotApplicableReason
     * @return static
     */
    public static function buildNotApplicable(array $identifyingFields, AgentEvalNotApplicableReason $agentEvalNotApplicableReason): static
    {
        return static::fromArray($identifyingFields + [
            'passed' => null,
            'outcome' => static::OUTCOME_NOT_APPLICABLE,
            'notApplicableReason' => $agentEvalNotApplicableReason->getReason(),
            'notApplicableDetail' => $agentEvalNotApplicableReason->getDetail(),
        ]);
    }

    /**
     * The row of a case whose run ended in an error: the identifying keys, `passed: false`, then the error.
     *
     * @param array<string, mixed> $identifyingFields
     * @return static
     */
    public static function buildFailedWithError(array $identifyingFields, string $error): static
    {
        return static::fromArray($identifyingFields + ['passed' => false, 'error' => $error]);
    }

    /**
     * The row of a case that does not apply: the header (for a tools case `skill`, `case`, `tool`, `flavor`, the
     * scope), then `passed: null`, the outcome and the reason ({@see buildNotApplicable()}).
     */
    public static function buildNotApplicableOfCase(AgentEvalCaseRowHeader $agentEvalCaseRowHeader, AgentEvalNotApplicableReason $agentEvalNotApplicableReason): static
    {
        return static::buildNotApplicable($agentEvalCaseRowHeader->toArray(), $agentEvalNotApplicableReason);
    }

    /**
     * The row of a case whose run ended in an error: the header, `passed: false`, `flavor` when given (the judged run
     * and an application's own kinds name it, the deterministic run does not), then the error.
     */
    public static function buildFailedOfCase(AgentEvalCaseRowHeader $agentEvalCaseRowHeader, ?string $flavor, string $error): static
    {
        $rowFields = $agentEvalCaseRowHeader->toArray() + ['passed' => false];
        if ($flavor !== null) {
            $rowFields['flavor'] = $flavor;
        }
        $rowFields['error'] = $error;
        return static::fromArray($rowFields);
    }

    /**
     * The row of a deterministic run of a tools case: the header (`skill`, `case`, `tool`), then `passed`,
     * `matchedTool`, `conversationId`, `skillPreload`, `planDynamics`, `taskPlanSize`, `reload` (the reload
     * diagnostics of a stored run, else null), `performance`, `loadedSkills`, `calledTools`, `failures`, `finalText`,
     * `widgetBlocks`, `toolCallCount`, `workToolCallCount`, `inputTokens`, `outputTokens`, `totalTokens`, `costUsd`,
     * `durationMs`, `messages`. The pass and the failures are the expectation's result as the caller completed it
     * (with the performance budget and the state check).
     *
     * @param AgentEvalCaseRowHeader $agentEvalCaseRowHeader
     * @param AgentEvalResult $agentEvalResult the result of the case's expectation
     * @param AgentEvalCaseRunMeasurement $agentEvalCaseRunMeasurement the measured run
     * @param AgentEvalRunOutcome $agentEvalRunOutcome the run (its reload diagnostics)
     * @param bool $conversationsPersisted whether the run stored its conversations (only then a reload is diagnosed)
     * @param AgentEvalSkillPreloadSummary|null $agentEvalSkillPreloadSummary null when the run measured none
     * @param AgentEvalPlanDynamicsSummary|null $agentEvalPlanDynamicsSummary null when the run measured none
     * @return static
     */
    public static function buildForDeterministicRun(
        AgentEvalCaseRowHeader $agentEvalCaseRowHeader,
        AgentEvalResult $agentEvalResult,
        AgentEvalCaseRunMeasurement $agentEvalCaseRunMeasurement,
        AgentEvalRunOutcome $agentEvalRunOutcome,
        bool $conversationsPersisted,
        ?AgentEvalSkillPreloadSummary $agentEvalSkillPreloadSummary,
        ?AgentEvalPlanDynamicsSummary $agentEvalPlanDynamicsSummary
    ): static {
        $messagesOfTree = $agentEvalCaseRunMeasurement->messagesOfTree;
        $toolCallHistory = $messagesOfTree->buildToolCallHistory();
        return static::fromArray($agentEvalCaseRowHeader->toArray() + [
            'passed' => $agentEvalResult->passed,
            'matchedTool' => $agentEvalResult->matchedTool,
            'conversationId' => $agentEvalCaseRunMeasurement->getConversationId(),
            'skillPreload' => $agentEvalSkillPreloadSummary?->toArray(),
            'planDynamics' => $agentEvalPlanDynamicsSummary?->toArray(),
            'taskPlanSize' => TaskPlanSize::fromMessagesOfConversation($agentEvalCaseRunMeasurement->conversation)?->toReportFields(),
            'reload' => $conversationsPersisted ? $agentEvalRunOutcome->getReloadDiagnostics() : null,
            'performance' => $agentEvalCaseRunMeasurement->runFigures->toArray(),
            'loadedSkills' => $messagesOfTree->getLoadedSkillNames(),
            'calledTools' => $toolCallHistory->getCalledToolNames(),
            'failures' => $agentEvalResult->failures,
            'finalText' => $messagesOfTree->getFinalAssistantText(),
            'widgetBlocks' => WidgetBlocks::fromMarkdown((string)$messagesOfTree->getFinalAssistantText())->buildBlockSyntaxRecords(),
            'toolCallCount' => $toolCallHistory->countToolCalls(),
            'workToolCallCount' => $toolCallHistory->countWorkToolCalls(),
            'inputTokens' => $messagesOfTree->getTotalInputTokens(),
            'outputTokens' => $messagesOfTree->getTotalOutputTokens(),
            'totalTokens' => $messagesOfTree->getTotalTokens(),
            'costUsd' => $messagesOfTree->getTotalCostUsd(),
            'durationMs' => $agentEvalCaseRunMeasurement->durationMs,
            'messages' => $agentEvalCaseRunMeasurement->messagesTrace,
        ]);
    }

    /**
     * The row of a judged run of a tools case: the header (`skill`, `case`, `tool`), then `passed` (the judge passed
     * the run and the performance budget holds), `flavor: judge`, `compactionFired`, `conversationId`, `failures` (of
     * the performance budget), `performance`, `verdict`, `turns`, `stopReason`, `loadedSkills`, `calledTools`,
     * `finalText`, `widgetBlocks`, `toolCallCount`, `workToolCallCount`, `inputTokens`, `outputTokens`, `totalTokens`,
     * `costUsd` (the agent's own), `judgeCostUsd` and `driverCostUsd` (the harness calls, {@see AgentEvalHarnessCosts}),
     * `durationMs`, `messages`, and `error` when the judge gave no verdict
     * ({@see AgentEvalJudgeVerdict::findEvaluationError()}).
     *
     * @param AgentEvalCaseRowHeader $agentEvalCaseRowHeader
     * @param AgentEvalSimRunResult $agentEvalSimRunResult the judged run
     * @param AgentEvalCaseRunMeasurement $agentEvalCaseRunMeasurement the measured run of its conversation
     * @param string[] $performanceFailures the failures of the case's performance budget
     * @return static
     */
    public static function buildForJudgedRun(
        AgentEvalCaseRowHeader $agentEvalCaseRowHeader,
        AgentEvalSimRunResult $agentEvalSimRunResult,
        AgentEvalCaseRunMeasurement $agentEvalCaseRunMeasurement,
        array $performanceFailures
    ): static {
        $agentEvalJudgeVerdict = $agentEvalSimRunResult->getAgentEvalJudgeVerdict();
        $messagesOfTree = $agentEvalCaseRunMeasurement->messagesOfTree;
        $toolCallHistory = $messagesOfTree->buildToolCallHistory();
        $agentEvalCaseRow = static::fromArray($agentEvalCaseRowHeader->toArray() + [
            'passed' => $agentEvalJudgeVerdict->isPass() && $performanceFailures === [],
            'flavor' => static::FLAVOR_JUDGE,
            'compactionFired' => $agentEvalCaseRunMeasurement->conversation->getCompactionCoveredUpToIndex() !== null,
            'conversationId' => $agentEvalCaseRunMeasurement->getConversationId(),
            'failures' => $performanceFailures,
            'performance' => $agentEvalCaseRunMeasurement->runFigures->toArray(),
            'verdict' => $agentEvalJudgeVerdict->toArray(),
            'turns' => $agentEvalSimRunResult->getTurns(),
            'stopReason' => $agentEvalSimRunResult->getStopReason(),
            'loadedSkills' => $messagesOfTree->getLoadedSkillNames(),
            'calledTools' => $toolCallHistory->getCalledToolNames(),
            'finalText' => $messagesOfTree->getFinalAssistantText(),
            'widgetBlocks' => WidgetBlocks::fromMarkdown((string)$messagesOfTree->getFinalAssistantText())->buildBlockSyntaxRecords(),
            'toolCallCount' => $toolCallHistory->countToolCalls(),
            'workToolCallCount' => $toolCallHistory->countWorkToolCalls(),
            'inputTokens' => $messagesOfTree->getTotalInputTokens(),
            'outputTokens' => $messagesOfTree->getTotalOutputTokens(),
            'totalTokens' => $messagesOfTree->getTotalTokens(),
            'costUsd' => $messagesOfTree->getTotalCostUsd(),
            'judgeCostUsd' => round($agentEvalSimRunResult->getHarnessCosts()->judgeCostUsd, 6),
            'driverCostUsd' => round($agentEvalSimRunResult->getHarnessCosts()->driverCostUsd, 6),
            'durationMs' => $agentEvalCaseRunMeasurement->durationMs,
            'messages' => $agentEvalCaseRunMeasurement->messagesTrace,
        ]);
        $evaluationError = $agentEvalJudgeVerdict->findEvaluationError();
        if ($evaluationError !== null) {
            $agentEvalCaseRow->setValue('error', $evaluationError);
        }
        return $agentEvalCaseRow;
    }

    /**
     * The row of a skill-selection case: the header (`skill` — the expected skills as the fixture names them, `A|B`
     * for either, `none` for no skill at all — and `case`), then `passed` (one of the expected skills was loaded — with
     * `none`: no skill was loaded — and none of `$forbiddenSkillNames`), `conversationId`, `failures`, `loadedSkills`,
     * `calledTools`, `toolCallCount`, `workToolCallCount`, `totalTokens`, `costUsd`, `durationMs`, `performance`.
     *
     * @param AgentEvalCaseRowHeader $agentEvalCaseRowHeader
     * @param string $expectedSkills the expected skills, `|`-separated; `none` ({@see AgentEvalCase::EXPECTED_SKILL_NONE}) = no skill
     * @param AgentEvalCaseRunMeasurement $agentEvalCaseRunMeasurement the measured run
     * @param string[] $forbiddenSkillNames skills the run must not load (the case's `forbiddenSkills`)
     * @return static
     */
    public static function buildForSelectionRun(
        AgentEvalCaseRowHeader $agentEvalCaseRowHeader,
        string $expectedSkills,
        AgentEvalCaseRunMeasurement $agentEvalCaseRunMeasurement,
        array $forbiddenSkillNames = []
    ): static {
        $messagesOfTree = $agentEvalCaseRunMeasurement->messagesOfTree;
        $toolCallHistory = $messagesOfTree->buildToolCallHistory();
        $loadedSkillNames = $messagesOfTree->getLoadedSkillNames();
        $selectionFailures = [];
        if (trim($expectedSkills) === AgentEvalCase::EXPECTED_SKILL_NONE) {
            if ($loadedSkillNames !== []) {
                $selectionFailures[] = sprintf('skill loaded although none was expected: [%s]', implode(', ', $loadedSkillNames));
            }
        } elseif (array_intersect(array_map('trim', explode('|', $expectedSkills)), $loadedSkillNames) === []) {
            $selectionFailures[] = sprintf("skill not loaded: expected '%s', loaded [%s]", $expectedSkills, implode(', ', $loadedSkillNames));
        }
        foreach (array_values(array_intersect($forbiddenSkillNames, $loadedSkillNames)) as $forbiddenSkillName) {
            $selectionFailures[] = sprintf("forbidden skill loaded: '%s'", $forbiddenSkillName);
        }
        $selectionHit = $selectionFailures === [];
        return static::fromArray($agentEvalCaseRowHeader->toArray() + [
            'passed' => $selectionHit,
            'conversationId' => $agentEvalCaseRunMeasurement->getConversationId(),
            'failures' => $selectionFailures,
            'loadedSkills' => $loadedSkillNames,
            'calledTools' => $toolCallHistory->getCalledToolNames(),
            'toolCallCount' => $toolCallHistory->countToolCalls(),
            'workToolCallCount' => $toolCallHistory->countWorkToolCalls(),
            'totalTokens' => $messagesOfTree->getTotalTokens(),
            'costUsd' => $messagesOfTree->getTotalCostUsd(),
            'durationMs' => $agentEvalCaseRunMeasurement->durationMs,
            'performance' => $agentEvalCaseRunMeasurement->runFigures->toArray(),
        ]);
    }

    /**
     * The row of a cross-conversation recall case: `id`, `case` (both the case id), `passed` (no error, one of the
     * expected tools was called — `expect.tool` as a list, no check without one — and every expected mention
     * (`expectMentions`) is in the final answer), `failures`, `performance` (null when the run threw before its
     * conversation existed), `durationMs`, `totalTokens`, `costUsd`, `seededConversationIds`, `calledTools`, `toolOk`,
     * `missingMentions`, `finalText`, `error` (null when none), `messages`. The tools and the answer are those of the
     * driven conversation itself, not of its delegations.
     *
     * @param AgentEvalCase $agentEvalCase the recall case
     * @param AIConversation|null $conversation the driven conversation; null when the run threw before it
     * @param mixed $messagesTrace the trace of the conversation's messages; null = none
     * @param int[] $seededConversationIds the conversations the run seeded (also when it threw after the seed)
     * @param string|null $error the error the run ended in; null when none
     * @param int $durationMs the wall milliseconds of the run
     * @return static
     */
    public static function buildForRecallRun(
        AgentEvalCase $agentEvalCase,
        ?AIConversation $conversation,
        mixed $messagesTrace,
        array $seededConversationIds,
        ?string $error,
        int $durationMs
    ): static {
        $caseId = (string)($agentEvalCase->toArray()['id'] ?? 'unknown');
        $expect = $agentEvalCase->getExpect();
        $expectedToolNames = array_map('strval', is_array($expect['tool'] ?? null) ? $expect['tool'] : []);
        $expectedMentions = $agentEvalCase->getApplicationValue('expectMentions');
        $expectedMentions = array_map('strval', is_array($expectedMentions) ? $expectedMentions : []);
        $calledToolNames = $conversation?->messages?->buildToolCallHistory()->getCalledToolNames() ?? [];
        $finalText = (string)($conversation?->getLastAssistantText() ?? '');
        $performance = $conversation !== null ? AgentEvalRunFigures::fromConversation($conversation)->toArray() : null;
        $toolOk = $expectedToolNames === [] || array_intersect($expectedToolNames, $calledToolNames) !== [];
        $missingMentions = array_values(array_filter(
            $expectedMentions,
            static fn (string $mention): bool => $mention !== '' && mb_stripos($finalText, $mention) === false
        ));
        // `expectAbsentMentions`: text a seeded conversation holds that must NOT reach the answer (a conversation the
        // owner may not read, or one the audience rule keeps out)
        $absentMentions = $agentEvalCase->getApplicationValue('expectAbsentMentions');
        $absentMentions = array_map('strval', is_array($absentMentions) ? $absentMentions : []);
        $leakedMentions = array_values(array_filter(
            $absentMentions,
            static fn (string $mention): bool => $mention !== '' && mb_stripos($finalText, $mention) !== false
        ));
        $failures = [];
        if (!$toolOk) {
            $failures[] = sprintf('tool not called: expected one of [%s], called [%s]', implode(', ', $expectedToolNames), implode(', ', $calledToolNames));
        }
        foreach ($missingMentions as $missingMention) {
            $failures[] = "the answer does not mention '{$missingMention}'";
        }
        foreach ($leakedMentions as $leakedMention) {
            $failures[] = "the answer mentions '{$leakedMention}', which must stay out";
        }
        return static::fromArray([
            'id' => $caseId,
            'case' => $caseId,
            'passed' => $error === null && $toolOk && $missingMentions === [] && $leakedMentions === [],
            'failures' => $failures,
            'performance' => $performance,
            'durationMs' => $durationMs,
            'totalTokens' => $performance['totalTokens'] ?? 0,
            'costUsd' => $performance['costUsd'] ?? 0.0,
            'seededConversationIds' => array_values($seededConversationIds),
            'calledTools' => array_values($calledToolNames),
            'toolOk' => $toolOk,
            'missingMentions' => $missingMentions,
            'leakedMentions' => $leakedMentions,
            'finalText' => $finalText,
            'error' => $error,
            'messages' => $messagesTrace,
        ]);
    }

    /**
     * The row a parent writes for a case whose child process it ended at its ceiling: `case`, `passed: false`, the
     * failure that names the ceiling, the error {@see CHILD_PROCESS_TIMED_OUT_ERROR}.
     */
    public static function buildForTimedOutChildProcess(string $caseId, int $timeoutSeconds): static
    {
        return static::fromArray([
            'case' => $caseId,
            'passed' => false,
            'failures' => ["the case's child process exceeded its {$timeoutSeconds}s ceiling and was killed (raise the case's timeoutSeconds when the flow is legitimately that long)"],
            'error' => static::CHILD_PROCESS_TIMED_OUT_ERROR,
        ]);
    }

    /** The row a parent writes for a case whose child process printed no result: `case`, `passed: false`, the error. */
    public static function buildForUnparseableChildOutput(string $caseId): static
    {
        return static::fromArray(['case' => $caseId, 'passed' => false, 'error' => static::CHILD_OUTPUT_UNPARSEABLE_ERROR]);
    }

    /**
     * The row exactly as it was built and stamped: the stored document of a case file, `<run>/<caseId>.json` /
     * `<run>/<caseId>.rep<k>.json` of a tools run and `<suite run>/<model>-<flavor>/<suite>-<caseId>.json` of a suite run.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->rowFields;
    }

    /**
     * The row without its message trace (`messages`): the form a summary holds (a row of `cases` in the stored document
     * of `<run>/_summary.json` and `<suite run>/<model>-<flavor>/_summary.json`).
     *
     * @return array<string, mixed>
     */
    public function toArrayWithoutMessages(): array
    {
        $rowFields = $this->rowFields;
        unset($rowFields['messages']);
        return $rowFields;
    }

    /** Whether the row carries the key `$key` (a null value counts as carried). */
    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->rowFields);
    }

    /** The value of the key `$key`; null when the row does not carry it. */
    public function getValue(string $key): mixed
    {
        return $this->rowFields[$key] ?? null;
    }

    /**
     * Sets the key `$key`: a key the row carries keeps its place, a new key is appended.
     */
    public function setValue(string $key, mixed $value): void
    {
        $this->rowFields[$key] = $value;
    }

    /** Removes the key `$key` (a no-op when the row does not carry it). */
    public function removeKey(string $key): void
    {
        unset($this->rowFields[$key]);
    }

    /** `case`: the case id; empty when the row names none. */
    public function getCaseId(): string
    {
        return (string)($this->rowFields['case'] ?? '');
    }

    /** `suite`: the suite of the case; empty when the row names none. */
    public function getSuite(): string
    {
        return (string)($this->rowFields['suite'] ?? '');
    }

    /** Whether the case passed (`passed` is true). */
    public function isPassed(): bool
    {
        return ($this->rowFields['passed'] ?? false) === true;
    }

    /** Whether the row carries an error (`error` set and not null). */
    public function hasError(): bool
    {
        return isset($this->rowFields['error']);
    }

    /** `error`: the error the run ended with; null when it ended without one. */
    public function getError(): ?string
    {
        return $this->hasError() ? (string)$this->rowFields['error'] : null;
    }

    /** Whether the case does not apply to its agent space (`outcome: notApplicable`). */
    public function isNotApplicable(): bool
    {
        return ($this->rowFields['outcome'] ?? null) === static::OUTCOME_NOT_APPLICABLE;
    }

    /** Whether the case counts as a failed case: not passed and not a not-applicable row (an error row counts). */
    public function isFailed(): bool
    {
        return !$this->isPassed() && !$this->isNotApplicable();
    }

    /** `notApplicableDetail`: the sentence of a not-applicable row; empty for every other row. */
    public function getNotApplicableDetail(): string
    {
        return (string)($this->rowFields['notApplicableDetail'] ?? '');
    }

    /** Whether the row carries a `performance` block (a row whose run ended in an error carries none). */
    public function hasPerformance(): bool
    {
        return is_array($this->rowFields['performance'] ?? null);
    }

    /**
     * `performance`: the run figures as the row stores them ({@see AgentEvalCasePerformance}); an empty block when the
     * row carries none.
     */
    public function getPerformance(): AgentEvalCasePerformance
    {
        return AgentEvalCasePerformance::fromBlock($this->hasPerformance() ? $this->rowFields['performance'] : []);
    }

    /** One number of the `performance` block, 0 when the block or the key is missing. */
    public function getPerformanceNumber(string $key): float
    {
        return $this->getPerformance()->getNumber($key);
    }

    /** Whether the row carries a measured wall-clock `durationMs` (the rows the totals of a run average over). */
    public function hasDurationMs(): bool
    {
        return isset($this->rowFields['durationMs']);
    }

    /** One number of the row's top level, 0 when the key is missing. */
    public function getNumber(string $key): float
    {
        return (float)($this->rowFields[$key] ?? 0);
    }

    /** `processSeconds`: the wall seconds of the case's child process (both attempts of a retried case); 0 when not measured. */
    public function getProcessSeconds(): float
    {
        return (float)($this->rowFields['processSeconds'] ?? 0);
    }

    /** Whether a restore step of the harness was refused: the case left its agent space changed (`restoreFailed`). */
    public function isRestoreFailed(): bool
    {
        return ($this->rowFields['restoreFailed'] ?? false) === true;
    }

    /** Whether the case ran a second time after an infrastructure error (`retriedAfter`). */
    public function wasRetried(): bool
    {
        return isset($this->rowFields['retriedAfter']);
    }

    /** `suite:case`, the form a suite record lists a case in. */
    public function describeSuiteAndCase(): string
    {
        return $this->getSuite() . ':' . $this->getCaseId();
    }

    /** `suite:case — detail`, the form a suite record lists a not-applicable case in. */
    public function describeNotApplicableCase(): string
    {
        return $this->describeSuiteAndCase() . ' — ' . $this->getNotApplicableDetail();
    }

    /**
     * The infrastructure error a failed row ended with — the first of `$retriedInfrastructureErrors` its error text
     * contains (a database deadlock, a model transport timeout: the case said nothing about the agent) — or null for
     * a passed row, a row without an error and any other error.
     *
     * @param string[] $retriedInfrastructureErrors texts that mark an infrastructure error
     */
    public function findInfrastructureError(array $retriedInfrastructureErrors): ?string
    {
        if ($this->isPassed() || !$this->hasError()) {
            return null;
        }
        foreach ($retriedInfrastructureErrors as $infrastructureError) {
            if (str_contains((string)$this->rowFields['error'], $infrastructureError)) {
                return $infrastructureError;
            }
        }
        return null;
    }

    /** An `error` key that holds null is removed (a row without an error carries none). */
    public function removeEmptyError(): void
    {
        if (array_key_exists('error', $this->rowFields) && $this->rowFields['error'] === null) {
            unset($this->rowFields['error']);
        }
    }

    /**
     * Keeps what the harness did around the agent (capture, restore, `after` steps): the lines of the child process's
     * error output that start with {@see HARNESS_LOG_LINE_PREFIX} become `harnessLog` (trimmed, at most 500 characters
     * each), and a refused restore step marks the row `restoreFailed` — it left the agent space changed for the next
     * case. A failed capture is no change: the case did not run. Nothing is set when no such line is there.
     */
    public function recordHarnessLogOfErrorOutput(string $errorOutput): void
    {
        $harnessLog = [];
        foreach (explode("\n", $errorOutput) as $errorLine) {
            if (str_starts_with(ltrim($errorLine), static::HARNESS_LOG_LINE_PREFIX)) {
                $harnessLog[] = mb_substr(trim($errorLine), 0, 500);
            }
        }
        if ($harnessLog === []) {
            return;
        }
        $this->rowFields['harnessLog'] = $harnessLog;
        foreach ($harnessLog as $harnessLine) {
            if ($this->isRefusedRestoreStepLine($harnessLine)) {
                $this->rowFields['restoreFailed'] = true;
                return;
            }
        }
    }

    /** Whether a harness line reports a refused restore step (never the capture before the case). */
    public function isRefusedRestoreStepLine(string $harnessLine): bool
    {
        if (str_starts_with($harnessLine, static::HARNESS_CAPTURE_LINE_PREFIX)) {
            return false;
        }
        foreach (static::REFUSED_HARNESS_STEP_MARKERS as $refusedStepMarker) {
            if (str_contains($harnessLine, $refusedStepMarker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stamps the task's own facts onto the row (a key the row carries keeps its place): `case`, `suite`, `fixture` (the
     * fixture's file name), `model`, `flavor`, `access`, `lane` and the `processSeconds` of its child process (one
     * decimal).
     */
    public function stampTask(AgentEvalSuiteTask $agentEvalSuiteTask, float $processSeconds): void
    {
        $this->rowFields['case'] = $agentEvalSuiteTask->caseId;
        $this->rowFields['suite'] = $agentEvalSuiteTask->suite;
        $this->rowFields['fixture'] = basename($agentEvalSuiteTask->fixturePath, '.json');
        $this->rowFields['model'] = $agentEvalSuiteTask->modelName;
        $this->rowFields['flavor'] = $agentEvalSuiteTask->flavor;
        $this->rowFields['access'] = $agentEvalSuiteTask->access;
        $this->rowFields['lane'] = $agentEvalSuiteTask->getLane();
        $this->rowFields['processSeconds'] = round($processSeconds, 1);
    }

    /**
     * Stamps which run of a repeated case the row is (`repetition`, appended; a key the row carries keeps its place).
     * Nothing for a case that runs once (`$repetition` 0).
     */
    public function stampRepetition(int $repetition): void
    {
        if ($repetition > 0) {
            $this->rowFields['repetition'] = $repetition;
        }
    }

    /**
     * What a first attempt that ended in an infrastructure error leaves on the row of the second: its error (at most
     * 600 characters), its process seconds and its harness log.
     */
    public function buildFirstAttempt(): AgentEvalFirstAttempt
    {
        return AgentEvalFirstAttempt::fromRow($this);
    }

    /**
     * Marks the row of a second attempt: `retriedAfter` (the infrastructure error of the first), `firstAttempt` (what
     * the first left, {@see buildFirstAttempt()}) and the time of both attempts as the case's `processSeconds`.
     */
    public function markAsRetriedAfter(string $infrastructureError, AgentEvalCaseRow $firstAttemptRow): void
    {
        $agentEvalFirstAttempt = $firstAttemptRow->buildFirstAttempt();
        $this->rowFields['retriedAfter'] = $infrastructureError;
        $this->rowFields['firstAttempt'] = $agentEvalFirstAttempt->toArray();
        $this->rowFields['processSeconds'] = round($this->getProcessSeconds() + $agentEvalFirstAttempt->processSeconds, 1);
    }
}
