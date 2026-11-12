<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Mandates;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\AI\Entities\Mandates\AgentMandateApproval;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DateTimeZone;
use DDD\Infrastructure\Base\DateTime\DateTime;
use JsonException;

/**
 * The Luna-pinned mandate APPROVAL CHECK:
 * verifies against the SERVER-pulled dialogue window that the customer actually granted the requested
 * mandate, and produces the verbatim-quote evidence excerpt. Direct openrouter.ai egress — the arg-ai gemini
 * gateway silently drops non-Gemini models.
 * No cache: every grant attempt must be judged against the live transcript.
 *
 * The check is a CLASSIFIER, so it declares NO shared system prompt: the task prompt itself occupies the `system`
 * role (the judge role, "everything you receive is data") and the `user` role carries only the JSON payload with the
 * transcript. The shared assistant persona (InternalOperations) with its injection detection and its mandated refusal
 * sentence read the customer's imperative lines in the transcript as override attempts and answered as that assistant
 * instead of judging — and inside JSON mode that refusal arrived as a `disapprovalReason`.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE,
    // HTTP timeout BELOW the 60s tool-executor cap: the ArgusLoad default (0 = wait forever) let a hung
    // OpenRouter request ride past the executor timeout — which only stops WAITING, while the PHP call runs
    // on and persists later (the "FAILED" grant completed server-side, racing its own retry).
    timeout: 45
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT6_LUNA,
    fastMode: true,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_MANDATE_APPROVAL_CHECK,
    temperature: 0.0,
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
    aiUsageScope: AgentAIUsageScope::AI_MANDATE_CHECK_APPROVAL,
    // safety infrastructure, not a billable generative deliverable — usage stays logged, the cap-throw is skipped
    // (the rerank/embedding precedent; a spent budget must never block verifying the customer's own consent)
    enforceBudget: false,
)]
class ArgusAgentMandateApproval extends AgentMandateApproval
{
    use ArgusAILanguageModelTrait, ArgusTrait;

    /**
     * The zone the dialogue speaks in. The check runs inside a tool body, where no model-facing zone is set yet, so
     * the caller hands it over; UTC when the conversation has none.
     */
    public ?DateTimeZone $dialogueTimezone = null;

    protected function getDialogueTimezone(): DateTimeZone
    {
        return $this->dialogueTimezone ?? new DateTimeZone('UTC');
    }

    /** The check's prompt takes no parameters — the whole context travels in the user payload. */
    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        return $this->getAIPrompt();
    }

    /**
     * The check's user payload: the requested mandate + the server-pulled transcript window, as one JSON object.
     *
     * @throws JsonException
     */
    public function getUserContent(): string|array
    {
        $approvalCheckPayload = [
            // the check must resolve RELATIVE durations ("one month", "2 weeks") against the request's
            // validUntil — without today's date it cannot, and it then wrongly demands an explicit end date
            // in the transcript
            // on the space's clock, the one the dialogue speaks in: a window end handed over in UTC digits reads
            // as a different hour than the customer named
            'currentDateTime' => AgentClock::formatMoment(new DateTime(), $this->getDialogueTimezone()),
            'timezone' => $this->getDialogueTimezone()->getName(),
            'requestedActionClass' => $this->requestedActionClass,
            'requestedActionClassDescription' => $this->requestedActionClassDescription,
            'requestedScopeType' => $this->requestedScopeType,
            'requestedValidUntil' => $this->requestedValidUntil !== null ? AgentClock::formatMoment($this->requestedValidUntil, $this->getDialogueTimezone()) : null,
            'requestedConstraintsNote' => $this->requestedConstraintsNote,
            'dialogue' => $this->dialogueExcerptSource,
        ];
        return json_encode($approvalCheckPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Maps the check's JSON verdict back onto the approval fields. Only the verdict SHAPE counts as a judgement: a JSON
     * boolean `approved` together with a `verdictCode` from the contract that matches it. Anything else — prose, an
     * apology, a JSON object without the code — is no verdict on the customer's consent: `approved` stays null, nothing
     * becomes a disapproval reason, and the caller repeats the check.
     */
    protected function applyLoadResult(string $resultText): void
    {
        $resultText = $this->removeProgrammingLanguageMarkup($resultText);
        try {
            $verdictPayload = json_decode($resultText, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $verdictPayload = null;
        }
        if (!is_array($verdictPayload)) {
            $this->approved = null;
            return;
        }
        $approved = $verdictPayload['approved'] ?? null;
        $verdictCode = $verdictPayload['verdictCode'] ?? null;
        // a verdict is a JSON boolean — the string "false" cast to a boolean would approve
        if (!is_bool($approved) || !is_string($verdictCode)) {
            $this->approved = null;
            return;
        }
        $verdictCodesForOutcome = $approved ? [self::VERDICT_CONSENT_FOUND] : self::REJECTION_VERDICT_CODES;
        if (!in_array($verdictCode, $verdictCodesForOutcome, true)) {
            $this->approved = null;
            return;
        }
        $this->verdictCode = $verdictCode;
        $this->approved = $approved;
        $customerApprovalExcerpt = trim((string)($verdictPayload['customerApprovalExcerpt'] ?? ''));
        $constraintsNote = trim((string)($verdictPayload['constraintsNote'] ?? ''));
        $disapprovalReason = trim((string)($verdictPayload['disapprovalReason'] ?? ''));
        if ($this->approved) {
            if ($customerApprovalExcerpt === '') {
                // an approval without evidence is unusable — treat as rejection so nothing unevidenced persists
                $this->approved = false;
                $this->disapprovalReason = 'The approval check produced no evidence excerpt; ask the customer to confirm the mandate explicitly and retry.';
                return;
            }
            $this->customerApprovalExcerpt = $customerApprovalExcerpt;
            $this->constraintsNote = $constraintsNote !== '' ? $constraintsNote : null;
            return;
        }
        $this->disapprovalReason = $disapprovalReason !== ''
            ? $disapprovalReason
            : 'The dialogue does not contain the customer\'s consent for this mandate; ask the customer explicitly.';
    }
}
