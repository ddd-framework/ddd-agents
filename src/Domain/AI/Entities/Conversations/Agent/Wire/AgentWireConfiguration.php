<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\AIModel as BaseAIModel;
use DDD\Infrastructure\Libs\Config;
use Throwable;

/**
 * How the agent egress talks to the model for one run: the wire API (Chat Completions or
 * Responses), the skill-loading strategy (system-prompt swap or append-only) and whether the provider's reasoning items
 * are sent back — resolved from the model's catalog entry, the conversation's run settings and its stamped context
 * retention settings, and VALIDATED against the allowed combinations. A rejected combination carries the reason line
 * instead of being switched silently. Pure, no repository contact; the loop resolves it once per run.
 *
 * @phpstan-consistent-constructor
 */
class AgentWireConfiguration
{
    /** The Chat-Completions + append-only rejection. */
    public const string REJECTION_CONVERSATION_APPEND_NEEDS_RESPONSES = 'skillLoadingStrategy conversationAppend needs the Responses API: Chat Completions has no mid-conversation tool addition (allowed_tools is ignored, a changed tools array loses the cached prefix)';

    /** The Responses + non-OpenAI-model rejection. */
    public const string REJECTION_RESPONSES_NEEDS_OPENAI_MODEL = 'agentWireApi responses is probed for OpenAI models only (GPT-6 Luna, GPT-6 Sol, GPT-5.6 Luna)';

    /** The Responses + LiteLLM-egress rejection. */
    public const string REJECTION_RESPONSES_NEEDS_DIRECT_OPENROUTER = 'agentWireApi responses needs the direct OpenRouter egress: the LiteLLM proxy\'s Responses route is untested';

    /**
     * The server-state arm + system-prompt swap rejection: under the swap the system content and the top-level tools move
     * with every skill load, and each move needs a fresh server-side chain (the whole input again); under the append-only
     * strategy both stay fixed for the epoch, so the chain survives the conversation.
     */
    public const string REJECTION_SERVER_STATE_NEEDS_CONVERSATION_APPEND = 'agentWireApi responsesServerState is a test arm for skillLoadingStrategy conversationAppend only: under systemPromptSwap every skill load changes the system content and the tools, which restarts the server-side chain';

    /**
     * The server-state arm + `stateChangeDelivery = onceWhenChanged` rejection:
     * the notes under a server-side chain are not built — the clock there is a per-call tail entry
     * the arm's design replaces, and nothing of the combination is measured.
     */
    public const string REJECTION_SERVER_STATE_WITH_STATE_NOTES = 'agentWireApi responsesServerState with stateChangeDelivery onceWhenChanged is not built: the state notes under a server-side chain are unmeasured';

    /** @var string {@see AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS}, {@see AIModel::AGENT_WIRE_API_RESPONSES} or {@see RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE}. */
    public string $wireApi = AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS;

    /** @var string {@see ContextRetentionSettings::SKILL_LOADING_STRATEGIES}. */
    public string $skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP;

    /**
     * @var string Which reasoning items of the previous calls go back on a call — the RESOLVED mode, never `inherit`
     *      ({@see RunSettings::PREVIOUS_REASONING_CARRY_OVER_VALUES}): `all` (every earlier step's), `currentTurn` (only
     *      the steps since the newest owner message; an earlier turn's items stay off the wire), `capped` (every earlier
     *      step's up to {@see $previousReasoningCapTokens}, then cut in one block, {@see AgentPreviousReasoningCut}) or
     *      `off` (none).
     */
    public string $previousReasoningCarryOver = RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF;

    /** The built-in cap of the carry-over `capped` when the committed config names none. */
    public const int DEFAULT_PREVIOUS_REASONING_CAP_TOKENS = 1000;

    /**
     * @var int The cap of the carry-over `capped` in reasoning tokens — the conversation's row, else the committed default
     *      ({@see resolveDefaultPreviousReasoningCapTokens()}); read only under `capped` ({@see AgentPreviousReasoningCut}).
     */
    public int $previousReasoningCapTokens = self::DEFAULT_PREVIOUS_REASONING_CAP_TOKENS;

    /** @var string|null Why this combination is not allowed; null = allowed. */
    public ?string $rejectionReason = null;

    /**
     * The configuration of a run with `$aiModel` (null = unresolved), the conversation's run settings and its stamped
     * context retention settings, over the direct OpenRouter egress or not. The matrix:
     * Chat Completions + system-prompt swap → allowed (every model, both egresses); Chat Completions + append-only →
     * rejected; Responses + either strategy → allowed for an OpenAI model over the direct OpenRouter egress, rejected
     * otherwise.
     */
    public static function resolveFor(
        ?BaseAIModel $aiModel,
        ?RunSettings $runSettings,
        ?ContextRetentionSettings $contextRetentionSettings,
        bool $directOpenRouterEgress
    ): static {
        $agentWireConfiguration = new static();
        $agentWireConfiguration->wireApi = self::resolveWireApiFor($aiModel, $runSettings);
        $agentWireConfiguration->skillLoadingStrategy = $contextRetentionSettings !== null
            ? $contextRetentionSettings->getSkillLoadingStrategy()
            : ContextRetentionSettings::resolveDefaultSkillLoadingStrategy();
        // on by default on the Responses API; on Chat Completions only when the row asks for it (the
        // A arm stays today's request, A′ = A + `all` measures the carry-over alone)
        // the server-state arm never carries reasoning items: OpenAI keeps them in the stored conversation state
        $agentWireConfiguration->previousReasoningCarryOver = self::resolvePreviousReasoningCarryOver(
            $runSettings->previousReasoningCarryOver ?? null,
            $agentWireConfiguration->wireApi
        );
        $previousReasoningCapTokensRow = $runSettings->previousReasoningCapTokens ?? null;
        $agentWireConfiguration->previousReasoningCapTokens = $previousReasoningCapTokensRow !== null && $previousReasoningCapTokensRow > 0
            ? $previousReasoningCapTokensRow
            : self::resolveDefaultPreviousReasoningCapTokens();
        $agentWireConfiguration->rejectionReason = self::findRejectionReason(
            $agentWireConfiguration->wireApi,
            $agentWireConfiguration->skillLoadingStrategy,
            $aiModel->vendor ?? null,
            $directOpenRouterEgress
        ) ?? self::findStateChangeDeliveryRejectionReason(
            $agentWireConfiguration->wireApi,
            ($contextRetentionSettings ?? new ContextRetentionSettings())->deliversStateChangesOnce()
        );
        return $agentWireConfiguration;
    }

    /**
     * The wire API a run with `$aiModel` (null = unresolved) speaks under the conversation's run settings: the run
     * settings' row ({@see RunSettings::$agentWireApi}), else the model's catalog value, else Chat Completions.
     */
    public static function resolveWireApiFor(?BaseAIModel $aiModel, ?RunSettings $runSettings): string
    {
        return RunSettings::canonicalAgentWireApi($runSettings?->agentWireApi)
            ?? ($aiModel instanceof AIModel ? $aiModel->getAgentWireApi() : AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS);
    }

    /** The reason line of a combination the matrix rejects, null for an allowed one. */
    public static function findRejectionReason(string $wireApi, string $skillLoadingStrategy, ?string $modelVendor, bool $directOpenRouterEgress): ?string
    {
        if ($wireApi === AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS) {
            return $skillLoadingStrategy === ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND
                ? self::REJECTION_CONVERSATION_APPEND_NEEDS_RESPONSES
                : null;
        }
        if ($modelVendor !== AIModel::VENDOR_OPENAI) {
            return self::REJECTION_RESPONSES_NEEDS_OPENAI_MODEL;
        }
        if ($wireApi === RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE) {
            // the arm calls OpenAI directly (OpenRouter rejects `store: true` / `previous_response_id`): the OpenRouter
            // egress does not matter to it
            return $skillLoadingStrategy === ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND
                ? null
                : self::REJECTION_SERVER_STATE_NEEDS_CONVERSATION_APPEND;
        }
        if (!$directOpenRouterEgress) {
            return self::REJECTION_RESPONSES_NEEDS_DIRECT_OPENROUTER;
        }
        return null;
    }

    /** The reason line of a wire API the state notes do not run on yet; null when they do. */
    public static function findStateChangeDeliveryRejectionReason(string $wireApi, bool $deliversStateChangesOnce): ?string
    {
        return $deliversStateChangesOnce && $wireApi === RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE
            ? self::REJECTION_SERVER_STATE_WITH_STATE_NOTES
            : null;
    }

    /**
     * The error texts with which a provider refuses carried reasoning items: the error code
     * `invalid_encrypted_content` (a blob that cannot be verified — another model, a tampered item) and the organisation
     * mismatch of an item minted under another OpenAI organisation (a shared-capacity or second-key route).
     */
    public const array CARRIED_REASONING_REJECTION_ERROR_TEXTS = ['invalid_encrypted_content', 'organization_id did not match'];

    /**
     * Whether the provider error of a call (the JSON of the response's `error` object, {@see \DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse::$providerError})
     * refuses the reasoning items the call carried — its code or message names one of
     * {@see CARRIED_REASONING_REJECTION_ERROR_TEXTS}, case-insensitive (OpenRouter forwards the upstream error in the
     * message or in `metadata.raw`).
     */
    public static function providerErrorRejectsTheCarriedReasoningItems(?string $providerError): bool
    {
        if ($providerError === null || $providerError === '') {
            return false;
        }
        $providerErrorLowercased = strtolower($providerError);
        foreach (self::CARRIED_REASONING_REJECTION_ERROR_TEXTS as $rejectionErrorText) {
            if (str_contains($providerErrorLowercased, strtolower($rejectionErrorText))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The carry-over mode a call on `$wireApi` runs with, from the conversation's row (null = inherit): the row's value
     * (the legacy `on` reads as `all`), else the per-API default — `capped` on the Responses API
     * (cap {@see resolveDefaultPreviousReasoningCapTokens()}), `off` on Chat Completions; always `off` on the
     * server-state arm.
     */
    public static function resolvePreviousReasoningCarryOver(?string $previousReasoningCarryOverRow, string $wireApi): string
    {
        if ($wireApi === RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE) {
            return RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF;
        }
        $canonicalRow = $previousReasoningCarryOverRow !== null ? RunSettings::canonicalPreviousReasoningCarryOver($previousReasoningCarryOverRow) : null;
        if ($canonicalRow !== null && $canonicalRow !== RunSettings::PREVIOUS_REASONING_CARRY_OVER_INHERIT) {
            return $canonicalRow;
        }
        return $wireApi === AIModel::AGENT_WIRE_API_RESPONSES
            ? RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED
            : RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF;
    }

    /** Whether the calls send any reasoning item of a previous call back (every mode but `off`). */
    public function carriesPreviousReasoning(): bool
    {
        return $this->previousReasoningCarryOver !== RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF;
    }

    /**
     * The committed default cap of the carry-over `capped`: `AI.contextRetention.previousReasoningCapTokens` (a positive
     * count), else {@see DEFAULT_PREVIOUS_REASONING_CAP_TOKENS}. No environment variable. Never throws.
     */
    public static function resolveDefaultPreviousReasoningCapTokens(): int
    {
        try {
            $configured = Config::get(ContextRetentionSettings::CONFIG_KEY);
            $configuredCapTokens = is_array($configured) ? ($configured['previousReasoningCapTokens'] ?? null) : null;
            if (is_int($configuredCapTokens) && $configuredCapTokens > 0) {
                return $configuredCapTokens;
            }
        } catch (Throwable) {
            // no config file — the built-in default
        }
        return self::DEFAULT_PREVIOUS_REASONING_CAP_TOKENS;
    }

    /** Whether the earlier steps' items go back up to the cap and are then cut in one block (`capped`). */
    public function carriesCappedPreviousReasoning(): bool
    {
        return $this->previousReasoningCarryOver === RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED;
    }

    /** Whether only the reasoning items of the current turn's steps go back (`currentTurn`). */
    public function carriesOnlyTheCurrentTurnsReasoning(): bool
    {
        return $this->previousReasoningCarryOver === RunSettings::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN;
    }

    /** A copy of this configuration that carries no reasoning item back (the recovery after a refused item). */
    public function withoutPreviousReasoningCarryOver(): self
    {
        $agentWireConfiguration = clone $this;
        $agentWireConfiguration->previousReasoningCarryOver = RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF;
        return $agentWireConfiguration;
    }

    /** Today's configuration: Chat Completions, system-prompt swap, carry-over off (a call made outside a run). */
    public static function chatCompletionsWithSystemPromptSwap(): static
    {
        return new static();
    }

    public function isRejected(): bool
    {
        return $this->rejectionReason !== null;
    }

    /** Whether the calls speak the Responses API — through OpenRouter, or directly at OpenAI with server state. */
    public function usesResponsesApi(): bool
    {
        return RunSettings::isResponsesAgentWireApi($this->wireApi);
    }

    /** Whether the calls go directly to OpenAI with `store: true` + `previous_response_id` (the test-only server-state arm). */
    public function usesResponsesServerState(): bool
    {
        return $this->wireApi === RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE;
    }

    public function usesConversationAppend(): bool
    {
        return $this->skillLoadingStrategy === ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
    }
}
