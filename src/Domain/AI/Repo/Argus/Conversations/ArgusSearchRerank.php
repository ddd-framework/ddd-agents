<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Models\AIModel;
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
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The LLM precision stage of conversation/memory search: given a query + N short candidate texts
 * (already vector-retrieved), the model returns the candidate indices ordered by true relevance — vector = recall,
 * LLM = precision. Reranking a handful of short snippets is light CLASSIFICATION, not generation, and the user is
 * waiting on a search. One small call per search → negligible cost.
 *
 * The model is gpt-oss-120b at reasoning effort `low`, named here rather than resolved from the scope `FAST_CHEAP`
 * (measured in an application's rerank evaluation at the size of a production call — 40
 * candidates): 47 of 48 runs right, one irrelevant
 * candidate kept in 282, a median of 0.49 s and no call above a second. Without the effort the same model spent
 * about 270 reasoning tokens per call and needed 0.78 s; GPT-6 Luna at effort `low` was as accurate but took
 * 1.1 s with a tail of 3.7 s, without effort it kept eleven irrelevant candidates. Naming the model keeps a later
 * change of the scope's speed pick from changing the rerank unmeasured.
 *
 * A language-model Argus op routed via the direct openrouter.ai egress (direct OpenRouter egress, no LiteLLM hop).
 * Mirrors the application's memory-reconcile Argus call.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT_OSS_120B,
    reasoningEffort: 'low',
    defaultAIPromptName: AgentAIPromptNames::APP_AI_SEARCH_RERANK,
    temperature: 0.0,
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
    aiUsageScope: AgentAIUsageScope::AI_SEARCH_RERANK,
    // Speed-route to the fastest provider (the user is waiting on the search) — explicit now that provider routing is
    // attribute-declared, not scope-inferred.
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
    // Infrastructure op: search precision, not a billable deliverable → NEVER capped by the customer's AI value-budget
    // (the same pipeline's query embedding isn't either). Spend is still logged via the AI envelope. See the trait's
    // isAIBudgetEnforcedForOperation(). So the search box stays full-quality even on a budget-exhausted account.
    enforceBudget: false,
)]
class ArgusSearchRerank extends ValueObject
{
    use ArgusTrait;
    use ArgusAILanguageModelTrait;

    /** @var string The search query the candidates are ranked against. */
    public string $query = '';

    /** @var string[] The candidate texts, index-aligned with the caller's candidate list (the model ranks by index). */
    public array $candidateTexts = [];

    /** @var Account|null Account for AI-budget/cost attribution (the conversation's account). */
    public ?Account $budgetAccount = null;

    /** @var Entity|null The entity within the account the AI costs of this call are billed to (the billing entity of the conversation's space). */
    public ?Entity $billingEntity = null;

    /** @var int[] The model's candidate indices best-first (set by {@see applyLoadResult}); empty when it returned nothing parseable. */
    public array $rankedIndices = [];

    /**
     * @var bool True once the model returned a PARSEABLE ranking (even an empty one = "none of the candidates are
     * relevant"). Distinguishes that verdict from a FAILED/unparseable call: on a real verdict the caller honours
     * {@see $rankedIndices} (empty → drop everything); on a failure it falls back to the vector order.
     */
    public bool $reranked = false;

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        if ($this->billingEntity) {
            $this->billAICostsTo(entityWithinAccount: $this->billingEntity);
        } elseif ($this->budgetAccount) {
            $this->billAICostsTo(account: $this->budgetAccount);
        }
        // The prompt is the instruction alone and the same for every search; the query and the candidates travel
        // once, in the user message.
        return $this->getAIPrompt();
    }

    public function getUserContent(): string|array
    {
        return 'QUERY: ' . $this->query . "\n\nCANDIDATES:\n" . $this->renderCandidates();
    }

    protected function renderCandidates(): string
    {
        $renderedLines = '';
        foreach ($this->candidateTexts as $index => $candidateText) {
            // Label each candidate with a NON-NUMERIC prefix ("c0", "c1", …). A bare integer index makes a cheap model
            // (gpt-oss-120b @ temp 0) FUSE the single-/double-digit indices of its JSON answer into one number — proven:
            // it reasoned the order "2,0,1,3" correctly but emitted {"ranking":[2013]}. The "c" prefix forces each label
            // to stay a distinct quoted token, so the ordering survives serialization. See applyLoadResult().
            $renderedLines .= '[c' . $index . '] ' . trim($candidateText) . "\n";
        }
        return $renderedLines === '' ? '(none)' : $renderedLines;
    }

    protected function applyLoadResult(string $resultText): void
    {
        if (trim($resultText) === '') {
            return;
        }
        $cleaned = (string)preg_replace(['/^```[a-zA-Z]*\s*/', '/\s*```$/'], '', trim($resultText));
        $decoded = json_decode($cleaned, true);
        $ranking = is_array($decoded) ? ($decoded['ranking'] ?? null) : null;
        if (!is_array($ranking)) {
            return;
        }
        // A parseable ranking IS a verdict — even an empty one ("none relevant"). Mark it so the caller honours it
        // instead of treating empty as a failure (which would wrongly fall back to the full vector order).
        $this->reranked = true;
        $rankedIndices = [];
        foreach ($ranking as $rankLabel) {
            // The model echoes each candidate's label ("c2", "c0", …); strip the non-digit prefix back to the index.
            // A bare/legacy integer still parses (no non-digits to strip). A still-fused token ("c2013") collapses to one
            // out-of-range index the caller drops — but the labelled-string format makes that fusion far less likely.
            $indexDigits = preg_replace('/\D+/', '', (string)$rankLabel);
            if ($indexDigits !== '' && $indexDigits !== null) {
                $rankedIndices[] = (int)$indexDigits;
            }
        }
        $this->rankedIndices = $rankedIndices;
    }
}
