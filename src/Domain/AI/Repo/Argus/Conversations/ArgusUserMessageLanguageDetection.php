<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations;

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
 * Detects which LANGUAGE the customer writes their chat messages in, against the CLOSED set of active app
 * language codes — a purpose's automatic interface-language confirmation (run by that purpose's plan
 * consequence hook, {@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::applyConsequencesOfPersistedTaskPlan()}).
 * A light one-shot CLASSIFICATION (not generation), routed via {@see ModelScope::FAST_CHEAP}; deliberately
 * CONSERVATIVE (the prompt answers "other" on short/ambiguous text — a wrong language switch flips the customer's
 * whole app UI, a missed one merely keeps the default).
 *
 * A language-model Argus op routed via the direct openrouter.ai egress (no LiteLLM hop). Mirrors
 * {@see \DDD\Domain\AI\Repo\Argus\Conversations\Schedules\ArgusScheduleDoneCriteriaJudgment}.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE
)]
#[ArgusLanguageModel(
    modelScope: ModelScope::FAST_CHEAP,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_CONVERSATION_USER_LANGUAGE_DETECTION,
    temperature: 0.0,
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
    aiUsageScope: AgentAIUsageScope::AI_CONVERSATION_DETECT_USER_LANGUAGE,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
    // System bookkeeping of the purpose that asks for it, not a billable deliverable: the interface-language
    // confirmation must also run for a budget-exhausted account (it fires seldom). Spend is still logged via the
    // AI envelope — the schedule-judgment precedent (isAIBudgetEnforcedForOperation()).
    enforceBudget: false,
)]
class ArgusUserMessageLanguageDetection extends ValueObject
{
    use ArgusTrait;
    use ArgusAILanguageModelTrait;

    /** @var string The customer's own messages, concatenated newest-last — the only evidence the detection sees. */
    public string $userMessagesText = '';

    /** @var string[] The closed answer set: the ACTIVE app language codes (lowercase). */
    public array $supportedLanguageCodes = [];

    /** @var Account|null Account for AI cost attribution. */
    public ?Account $budgetAccount = null;

    /** @var Entity|null The entity within the account the AI costs of this call are billed to (the billing entity of the conversation's space). */
    public ?Entity $billingEntity = null;

    /**
     * @var bool True once the model returned a PARSEABLE verdict (set by {@see applyLoadResult}). Distinguishes a
     * real "other" from a FAILED/unparseable call: on a failure the caller must NOT count the attempt as decided —
     * the next trigger simply detects again (attempt-capped by the caller).
     */
    public bool $detected = false;

    /**
     * @var string The detected language code (lowercase, from {@see $supportedLanguageCodes}) or 'other' when the
     * predominant language is not supported / undecidable. Meaningful only when {@see $detected}.
     */
    public string $detectedLanguageCode = '';

    /** @var string The model's one-sentence deciding evidence — logged for admin traceability, never customer-facing. */
    public string $reason = '';

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        if ($this->billingEntity) {
            $this->billAICostsTo(entityWithinAccount: $this->billingEntity);
        } elseif ($this->budgetAccount) {
            $this->billAICostsTo(account: $this->budgetAccount);
        }
        $prompt = $this->getAIPrompt();
        $prompt->setParameter('SUPPORTED_LANGUAGE_CODES', implode(', ', $this->supportedLanguageCodes));
        $prompt->setParameter('USER_MESSAGES', $this->userMessagesText);
        return $prompt;
    }

    public function getUserContent(): string|array
    {
        return 'SUPPORTED LANGUAGE CODES: ' . implode(', ', $this->supportedLanguageCodes)
            . "\n\nTHE CUSTOMER'S MESSAGES:\n" . $this->userMessagesText;
    }

    protected function applyLoadResult(string $resultText): void
    {
        if (trim($resultText) === '') {
            return;
        }
        $cleaned = (string)preg_replace(['/^```[a-zA-Z]*\s*/', '/\s*```$/'], '', trim($resultText));
        $decoded = json_decode($cleaned, true);
        if (!is_array($decoded) || !is_string($decoded['languageCode'] ?? null)) {
            return; // unparseable → detected stays false, the caller counts a failed attempt and may retry once.
        }
        $decodedLanguageCode = strtolower(trim($decoded['languageCode']));
        if ($decodedLanguageCode === '') {
            return;
        }
        // The closed answer set is enforced HERE, not trusted from the model: anything outside it degrades to
        // 'other' (= no switch) instead of flowing onward as a write candidate.
        if ($decodedLanguageCode !== 'other' && !in_array($decodedLanguageCode, $this->supportedLanguageCodes, true)) {
            $decodedLanguageCode = 'other';
        }
        $this->detected = true;
        $this->detectedLanguageCode = $decodedLanguageCode;
        $this->reason = is_string($decoded['reason'] ?? null) ? trim($decoded['reason']) : '';
    }
}
