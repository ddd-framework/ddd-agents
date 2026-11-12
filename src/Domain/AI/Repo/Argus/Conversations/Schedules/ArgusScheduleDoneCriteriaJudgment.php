<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Schedules;

use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\AI\Entities\Models\Selection\ModelScope;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The schedule doneCriteria judgment: a COMPLETED schedule run's final answer is
 * judged against the schedule's {@see \DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule::$doneCriteria}
 * — fulfilled → the schedule transitions to DONE and stops firing. A light one-shot CLASSIFICATION (not
 * generation), so routed via {@see ModelScope::FAST_CHEAP} like the search rerank; deliberately CONSERVATIVE
 * (the prompt defaults to not-fulfilled — a wrong "fulfilled" silently kills a live automation, a wrong
 * "not fulfilled" merely fires one more run).
 *
 * A language-model Argus op routed via the direct openrouter.ai egress (no LiteLLM hop). Mirrors
 * {@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusSearchRerank}.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE
)]
#[ArgusLanguageModel(
    modelScope: ModelScope::FAST_CHEAP,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_SCHEDULE_DONE_CRITERIA_JUDGMENT,
    temperature: 0.0,
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
    aiUsageScope: AgentAIUsageScope::AI_SCHEDULE_JUDGE_DONE_CRITERIA,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
    // System bookkeeping of the schedule lifecycle, not a billable deliverable: on a budget-exhausted account a
    // DONE schedule must still be able to CLOSE (else it keeps firing runs that all fail on budget). Spend is
    // still logged via the AI envelope — the rerank precedent (isAIBudgetEnforcedForOperation()).
    enforceBudget: false,
)]
class ArgusScheduleDoneCriteriaJudgment extends ValueObject
{
    use ArgusTrait;
    use ArgusAILanguageModelTrait;

    /** @var string The schedule's task instruction (fired on every occurrence). */
    public string $task = '';

    /** @var string The schedule's doneCriteria — when the recurring goal counts as fulfilled overall. */
    public string $doneCriteria = '';

    /** @var string The completed run's final answer text — the only evidence the judgment sees. */
    public string $runOutcomeText = '';

    /** @var Account|null Account for AI cost attribution (the schedule's account). */
    public ?Account $budgetAccount = null;

    /** @var Entity|null The entity within the account the AI costs of this call are billed to (the billing entity of the conversation's space). */
    public ?Entity $billingEntity = null;

    /**
     * @var bool True once the model returned a PARSEABLE verdict (set by {@see applyLoadResult}). Distinguishes a
     * real "not fulfilled" from a FAILED/unparseable call: on a failure the caller must NOT transition and must
     * NOT treat the criteria as judged — the next run terminal simply judges again.
     */
    public bool $judged = false;

    /** @var bool The verdict: the doneCriteria are fulfilled by this run's final answer. Meaningful only when {@see $judged}. */
    public bool $fulfilled = false;

    /** @var string The model's one-sentence deciding fact — logged for admin traceability, never customer-facing. */
    public string $reason = '';

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        if ($this->billingEntity) {
            $this->billAICostsTo(entityWithinAccount: $this->billingEntity);
        } elseif ($this->budgetAccount) {
            $this->billAICostsTo(account: $this->budgetAccount);
        }
        $prompt = $this->getAIPrompt();
        $prompt->setParameter('TASK', $this->task);
        $prompt->setParameter('DONE_CRITERIA', $this->doneCriteria);
        $prompt->setParameter('RUN_OUTCOME', $this->runOutcomeText);
        return $prompt;
    }

    public function getUserContent(): string|array
    {
        return 'TASK: ' . $this->task
            . "\n\nDONE CRITERIA: " . $this->doneCriteria
            . "\n\nTHIS RUN'S FINAL ANSWER:\n" . $this->runOutcomeText;
    }

    protected function applyLoadResult(string $resultText): void
    {
        if (trim($resultText) === '') {
            return;
        }
        $cleaned = (string)preg_replace(['/^```[a-zA-Z]*\s*/', '/\s*```$/'], '', trim($resultText));
        $decoded = json_decode($cleaned, true);
        if (!is_array($decoded) || !array_key_exists('fulfilled', $decoded)) {
            return; // unparseable → judged stays false, the caller skips the transition and re-judges next terminal.
        }
        $this->judged = true;
        $this->fulfilled = filter_var($decoded['fulfilled'], FILTER_VALIDATE_BOOLEAN);
        $this->reason = is_string($decoded['reason'] ?? null) ? trim($decoded['reason']) : '';
    }
}
