<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The ADMIN's per-conversation decision-layer switches as ONE request-side value: the skill-preload
 * row, the turn-closing turn-closing row and the kill switch. Null = "leave as is"; `inherit` = clear the row.
 * Carried from the admin console's PATCH and the drive endpoints (admin start / continue, the client start / post
 * under impersonation) into {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::applyAdminRunSettingsOverride()},
 * the one gated writer. Never persisted itself.
 *
 * @phpstan-consistent-constructor
 */
class RunSettingsOverride extends ValueObject
{
    /** @var string|null `inherit` / `on` / `off`. */
    public ?string $decisionModelSkillPreload = null;

    /** @var string|null `inherit` / `off` / `deterministicCounter` / `decisionModel` / `counterThenDecisionModel`. */
    public ?string $decisionModelTurnClosing = null;

    /** @var bool|null The kill switch: true = every site off for this conversation; false = lift it. */
    public ?bool $decisionModelOff = null;

    /** @var string|null `inherit` / `off` / `low` / `medium` / `high` (a value pins the effort floor). */
    public ?string $decisionModelReasoningEffort = null;

    /** @var string|null `inherit` / `off` / `on` — the stall-recovery site. */
    public ?string $decisionModelStallRecovery = null;

    /** @var string|null `inherit` / `off` / `on` — the reply-language site. */
    public ?string $decisionModelReplyLanguage = null;

    /**
     * @var string|null Switch 1 of the plan-dynamics site: `inherit` / `yes` / `no` (also `true` / `false`,
     *      `on` / `off`) — is the decision model asked the plan question here?
     */
    public ?string $planDynamicsAskDecisionModel = null;

    /** @var string|null Switch 2 of the plan-dynamics site: `inherit` / `yes` / `no` — does the chat model receive the verdict here? */
    public ?string $planDynamicsModelReceivesVerdict = null;

    /** @var string|null `inherit` / `chatCompletions` / `responses` — the conversation's agent wire API. */
    public ?string $agentWireApi = null;

    /** @var string|null `inherit` / `all` / `currentTurn` / `capped` / `off` — which reasoning items of the previous calls go back. */
    public ?string $previousReasoningCarryOver = null;

    /** @var int|null The cap of the carry-over `capped` in reasoning tokens — `0` clears it (the committed default), a positive count sets it. */
    public ?int $previousReasoningCapTokens = null;

    public static function fromValues(
        ?string $decisionModelSkillPreload = null,
        ?string $decisionModelTurnClosing = null,
        ?bool $decisionModelOff = null,
        ?string $decisionModelReasoningEffort = null,
        ?string $decisionModelStallRecovery = null,
        ?string $decisionModelReplyLanguage = null,
        ?string $planDynamicsAskDecisionModel = null,
        ?string $planDynamicsModelReceivesVerdict = null,
        ?string $agentWireApi = null,
        ?string $previousReasoningCarryOver = null,
        ?int $previousReasoningCapTokens = null
    ): ?static {
        $override = new static();
        $override->decisionModelSkillPreload = $decisionModelSkillPreload;
        $override->decisionModelTurnClosing = $decisionModelTurnClosing;
        $override->decisionModelOff = $decisionModelOff;
        $override->decisionModelReasoningEffort = $decisionModelReasoningEffort;
        $override->decisionModelStallRecovery = $decisionModelStallRecovery;
        $override->decisionModelReplyLanguage = $decisionModelReplyLanguage;
        $override->planDynamicsAskDecisionModel = $planDynamicsAskDecisionModel;
        $override->planDynamicsModelReceivesVerdict = $planDynamicsModelReceivesVerdict;
        $override->agentWireApi = $agentWireApi;
        $override->previousReasoningCarryOver = $previousReasoningCarryOver;
        $override->previousReasoningCapTokens = $previousReasoningCapTokens;
        return $override->isEmpty() ? null : $override;
    }

    public function isEmpty(): bool
    {
        return $this->decisionModelSkillPreload === null && $this->decisionModelTurnClosing === null && $this->decisionModelOff === null
            && $this->decisionModelReasoningEffort === null && $this->decisionModelStallRecovery === null
            && $this->decisionModelReplyLanguage === null
            && $this->planDynamicsAskDecisionModel === null && $this->planDynamicsModelReceivesVerdict === null
            && $this->agentWireApi === null && $this->previousReasoningCarryOver === null
            && $this->previousReasoningCapTokens === null;
    }

    /**
     * A switch row value → the stored tri-state: `inherit` → null (clear), `yes` / `true` / `on` / `1` → true,
     * `no` / `false` / `off` / `0` → false; anything else is invalid (the writer refuses it).
     *
     * @param string $value
     * @return RunSettingsSwitchRow
     */
    public static function parseSwitchRow(string $value): RunSettingsSwitchRow
    {
        $normalized = strtolower(trim($value));
        if ($normalized === 'inherit') {
            return RunSettingsSwitchRow::forValidityAndStoredValue(true, null);
        }
        if (in_array($normalized, ['yes', 'true', 'on', '1'], true)) {
            return RunSettingsSwitchRow::forValidityAndStoredValue(true, true);
        }
        if (in_array($normalized, ['no', 'false', 'off', '0'], true)) {
            return RunSettingsSwitchRow::forValidityAndStoredValue(true, false);
        }
        return RunSettingsSwitchRow::forValidityAndStoredValue(false, null);
    }
}
