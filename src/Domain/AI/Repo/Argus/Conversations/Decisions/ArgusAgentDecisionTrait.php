<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionChoiceAnswer;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Domain\Base\Entities\Entity;

/**
 * The System One DECISION-CALL protocol shared by every decision site of the agent loop (override, not
 * copy): the payload is `{model, state, questions}`, the answer `{model, answers, usage}`. A class
 * using it ALSO uses {@see \DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait} and
 * {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait} and keeps from the latter everything that is not
 * the chat protocol: scope + envelope validation, the budget gate (incl. the admin bypass), cost booking with the
 * provider-reported cost and the granular usage log.
 *
 * The using class provides the SITE: {@see buildState()} (only the fields its questions read),
 * {@see buildQuestionsForRequest()} (the questions) and {@see applyAnswers()} (typing the answers
 * into its own properties, returning whether the answer set was usable).
 *
 * Outage behaviour: the declared per-call timeout; a transport-class failure sets {@see $transportFailed} (the
 * caller trips the shared circuit breaker) and NEVER throws into the loop.
 */
trait ArgusAgentDecisionTrait
{
    /** @var Account|null Account for AI cost attribution. */
    public ?Account $budgetAccount = null;

    /** @var Entity|null The entity within the account the AI costs of this call are billed to (the billing entity of the conversation's space). */
    public ?Entity $billingEntity = null;

    /** @var bool True once the model returned a usable `answers` object. */
    public bool $decided = false;

    /** @var bool True when the call failed at the transport level (timeout, connection, 429/5xx) — the breaker input. */
    public bool $transportFailed = false;

    /** @var int|null The HTTP status the gateway reported, when it did. */
    public ?int $httpStatusCode = null;

    /** @var string|null The transport-level reason when this call was REJECTED inside a settled batch (a timeout, a connection error). */
    public ?string $transportFailureReason = null;

    /** @var string The batch child key — what keeps two decision children of one container apart (`site` + pass). */
    public string $decisionKeyInBatch = 'decision';

    /** Two children of one batch must never collapse into one operation (`ObjectSet::add()` and the operation id both dedupe by this). */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->decisionKeyInBatch);
    }

    /** @var string|null The exact model version that answered (`typesafe/jev-1.13-20260917`). */
    public ?string $modelVersion = null;

    /** @var array<string, mixed> The raw `answers` object, for the admin trace. */
    public array $rawAnswers = [];

    /** @return array<string, mixed> The `state` object — only the fields the questions need. */
    abstract public function buildState(): array;

    /** @return array<string, array<string, mixed>> The questions of this request, keyed by question name. */
    abstract protected function buildQuestionsForRequest(): array;

    /** Type the model's `answers` object into the site's properties; true when the answer set is usable. */
    abstract protected function applyAnswers(object $answers): bool;

    /** HTTP status codes that mean "the model is unavailable", not "our request is wrong". */
    public static function transportFailureStatusCodes(): array
    {
        return [408, 425, 429, 500, 502, 503, 504, 529];
    }

    /** Overrides the declared per-call timeout (config `timeoutSeconds`); must be called before {@see argusLoad()}. */
    public function setTimeoutSeconds(float $timeoutSeconds): void
    {
        if ($timeoutSeconds > 0) {
            $this->getArgusSettings()->timeout = $timeoutSeconds;
        }
    }

    /** The state as ONE string — what the budget pre-estimate tokenizes (the estimator prices strings and chat arrays, not a bare state object). */
    public function getUserContent(): string|array
    {
        return (string)json_encode($this->buildState(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The registry prompt of this call (its NAME keys the cost counters and usage rows); its text is never sent —
     * a decision call carries its questions as data. Satisfies the language-model trait's abstract signature.
     */
    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        $aiPrompt = $this->getAIPrompt();
        if (!$aiPrompt instanceof AIPrompt) {
            // Loud, never masked: a missing registry entry would otherwise surface as a TypeError deep in the cost
            // booking and the call's spend would go unbooked.
            throw new InternalErrorException('Decision-call registry prompt missing: ' . static::class . ' names no loadable AIPrompt');
        }
        return $aiPrompt;
    }

    /**
     * The System One payload: `{model, state, questions}`. The gates the language-model trait runs before any chat
     * payload run here too — scope + envelope, eligibility of the billed scope, the budget (with the admin bypass) — then the
     * OpenRouter bearer via the shared auth seam.
     *
     * @return array<string, mixed>|null
     */
    protected function getLoadPayloadInternal(): ?array
    {
        if ($this->billingEntity) {
            $this->billAICostsTo(entityWithinAccount: $this->billingEntity);
        } elseif ($this->budgetAccount) {
            $this->billAICostsTo(account: $this->budgetAccount);
        }
        // every stage of the gates, the eligibility of the billed scope included: a locked, deleted or erased agent space's
        // decision call is refused (AgentDecisionCallsService classifies that as REFUSED; the site falls back)
        $aiModel = $this->runModelCallGates();
        $payload = [
            'body' => [
                'model' => $this->resolveEgressModelId($aiModel),
                'state' => $this->buildState(),
                'questions' => $this->buildQuestionsForRequest(),
            ],
        ];
        $payload = $this->applyEgressAuthentication($payload);
        $this->lastRequestPayload = $payload;
        return $payload;
    }

    /**
     * Parse `{model, answers, usage}`. Books the usage exactly like a chat completion (input/output tokens through
     * the model's price row, OpenRouter's `usage.cost` as the provider-reported cross-check) and hands the answers to
     * the site. A missing/invalid answers object marks the call failed; a transport-class status code additionally
     * marks {@see $transportFailed} for the breaker. Never throws.
     */
    public function handleLoadResponseInternal(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        $this->httpResponseHeaders = $apiOperation?->responseHeaders;
        $this->lastRawResponse = is_object($callResponseData) ? $callResponseData : null;
        // The HTTP status lives on the OPERATION on the direct OpenRouter route (its error body is `{error:{…}}`, no
        // status field); the body's `status_code` exists only inside the Argus gateway envelope.
        $this->httpStatusCode = $apiOperation?->responseStatusCode
            ?? (is_object($callResponseData) && isset($callResponseData->status_code) ? (int)$callResponseData->status_code : null);
        $this->transportFailureReason = $apiOperation?->rejectionReason;

        $responseObject = $this->unwrapDecisionResponse($callResponseData);
        // Book what the provider billed BEFORE judging the answers: a 200 whose shape we cannot read still cost money.
        if (is_object($responseObject) && isset($responseObject->usage) && is_object($responseObject->usage)) {
            $inputTokens = isset($responseObject->usage->input_tokens) ? (int)$responseObject->usage->input_tokens : null;
            $outputTokens = isset($responseObject->usage->output_tokens) ? (int)$responseObject->usage->output_tokens : 0;
            $providerReportedCost = isset($responseObject->usage->cost) && is_numeric($responseObject->usage->cost)
                ? new MoneyAmount((float)$responseObject->usage->cost, 'USD')
                : null;
            if ($inputTokens !== null) {
                $this->updateCostsForUsage($inputTokens, $outputTokens, $providerReportedCost);
            } elseif ($providerReportedCost !== null) {
                $this->updateCostsForUsage(null, null, $providerReportedCost);
            }
        }
        if (!is_object($responseObject) || !isset($responseObject->answers) || !is_object($responseObject->answers)) {
            // No status at all = the transport never answered; a known status is transport only from the list — our
            // own 400 / 401 / 422 is a misconfiguration the caller logs loudly, never a breaker trip.
            $this->transportFailed = $this->httpStatusCode === null
                || in_array($this->httpStatusCode, self::transportFailureStatusCodes(), true);
            $this->postProcessLoadResponse($callResponseData, false);
            $this->clearArgusCache();
            return;
        }

        $this->modelVersion = isset($responseObject->model) ? (string)$responseObject->model : null;
        $this->rawAnswers = json_decode((string)json_encode($responseObject->answers), true) ?: [];
        $this->decided = $this->applyAnswers($responseObject->answers);
        $this->postProcessLoadResponse($callResponseData, $this->decided);
    }

    /** The language-model trait's text hook — a decision answer is parsed in {@see handleLoadResponseInternal()}, nothing arrives here. */
    protected function applyLoadResult(string $resultText): void
    {
    }

    /** OpenRouter answers the System One route with the bare object; the Argus gateway shapes wrap it. */
    protected function unwrapDecisionResponse(mixed &$callResponseData): mixed
    {
        if (is_object($callResponseData) && isset($callResponseData->answers)) {
            return $callResponseData;
        }
        return $this->getResponseDataFromArgusResponse($callResponseData);
    }

    /**
     * One `noul` answer → P(true) as the model reports it (`{"type":"noul","noul":0.94}` — quantised to 0.01, no
     * separate confidence field), or null when the answer is absent or malformed.
     */
    protected function readNoulProbability(object $answers, string $questionName): ?float
    {
        $answer = $answers->{$questionName} ?? null;
        if (!is_object($answer) || !isset($answer->noul) || !is_numeric($answer->noul)) {
            return null;
        }
        return max(0.0, min(1.0, (float)$answer->noul));
    }

    /**
     * One `choice` answer → lowercase choice, confidence and raw probabilities — the reader for a question whose
     * DISTRIBUTION matters (the pick's margin over the second candidate is a floor). The choice is
     * enforced against `$allowedChoices` like {@see readChoiceFrom()}; the probabilities come back RAW (keys lowercased)
     * for the site to validate — an outside key, a non-finite value or a sum far from 1 is the site's call.
     *
     * @param object $answers
     * @param string $questionName
     * @param string[] $allowedChoices
     * @param string $outsideSetChoice
     * @return AgentDecisionChoiceAnswer
     */
    protected function readChoiceWithProbabilitiesFrom(object $answers, string $questionName, array $allowedChoices, string $outsideSetChoice): AgentDecisionChoiceAnswer
    {
        $choiceAnswer = $this->readChoiceFrom($answers, $questionName, $allowedChoices, $outsideSetChoice);
        $answer = $answers->{$questionName} ?? null;
        $probabilities = [];
        if (is_object($answer) && isset($answer->probabilities) && (is_object($answer->probabilities) || is_array($answer->probabilities))) {
            foreach ((array) $answer->probabilities as $key => $value) {
                $probabilities[strtolower(trim((string) $key))] = $value;
            }
        }
        return AgentDecisionChoiceAnswer::forChoice($choiceAnswer->choice, $choiceAnswer->confidence, $probabilities);
    }

    /**
     * One `choice` answer → lowercase choice and confidence. An answer outside `$allowedChoices` degrades to
     * `$outsideSetChoice` HERE — the answer space is enforced by us, never trusted from the model.
     *
     * @param object $answers
     * @param string $questionName
     * @param string[] $allowedChoices
     * @param string $outsideSetChoice
     * @return AgentDecisionChoiceAnswer
     */
    protected function readChoiceFrom(object $answers, string $questionName, array $allowedChoices, string $outsideSetChoice): AgentDecisionChoiceAnswer
    {
        $answer = $answers->{$questionName} ?? null;
        if (!is_object($answer) || !isset($answer->choice) || !is_string($answer->choice)) {
            return AgentDecisionChoiceAnswer::forChoice(null, 0.0);
        }
        $choice = strtolower(trim($answer->choice));
        $confidence = isset($answer->confidence) && is_numeric($answer->confidence) ? (float)$answer->confidence : 0.0;
        if (!in_array($choice, $allowedChoices, true)) {
            $choice = $outsideSetChoice;
        }
        return AgentDecisionChoiceAnswer::forChoice($choice, $confidence);
    }
}
