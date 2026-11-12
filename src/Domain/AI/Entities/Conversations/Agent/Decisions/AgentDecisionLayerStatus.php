<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The decision layer as the OPERATOR sees it for one conversation: every site's effective mode from
 * the environment, and for the skill-preload site the RESOLVED line the admin settings summary and the client
 * impersonation panel both render — `site: shadow · row: on · resolved: on · set by … at …`. Read-only; the switch
 * itself is written through {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::applyAdminRunSettingsOverride()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionLayerStatus extends ValueObject
{
    /** @var string The global mode of the committed config (`decisionModel.php`). */
    public string $globalMode = AgentDecisionModelSettings::MODE_OFF;

    /** @var array<string, string> Site → effective mode (`off` / `shadow` / `on` / `cohort`). */
    public array $sites = [];

    /** @var string The skill-preload site's mode from the environment (`off` = the toggles render disabled). */
    public string $skillPreloadSiteMode = AgentDecisionModelSettings::MODE_OFF;

    /** @var string|null The account's gate 6 arm under `cohort`, else null. */
    public ?string $skillPreloadArm = null;

    /** @var string|null The conversation's own switch (`on` / `off`), null = inherit. */
    public ?string $skillPreloadRowSwitch = null;

    /** @var string The mode the preload site RUNS with for this conversation after arm and row: `off` / `shadow` / `on`. */
    public string $skillPreloadResolvedMode = AgentDecisionModelSettings::MODE_OFF;

    /** @var int|null Who set the row switch. */
    public ?int $skillPreloadSetByAccountId = null;

    /** @var string|null When the row switch was set. */
    public ?string $skillPreloadSetAt = null;

    /** @var bool True when the site is `off` in the environment — the toggles are disabled with this reason. */
    public bool $skillPreloadToggleDisabled = true;

    /** @var string One line for both panels: `site: shadow · row: on · resolved: on · set by 12 at 2026-09-24 10:00:00`. */
    public string $skillPreloadResolvedLine = '';

    /** @var string The turn-closing site's mode for this conversation (`off` / `shadow` / `on`, after the kill switch and the row). */
    public string $turnClosingSiteMode = AgentDecisionModelSettings::MODE_OFF;

    /** @var string The environment's turn-closing mode (`deterministicCounter` / `decisionModel`). */
    public string $turnClosingModeEnv = AgentDecisionModelSettings::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER;

    /** @var string|null The conversation's own row (`off` / `deterministicCounter` / `decisionModel` / `counterThenDecisionModel`), null = inherit. */
    public ?string $turnClosingRowSwitch = null;

    /** @var string The turn-closing mode the checked stop RUNS with here (`decisionModel` only while the site is `on`). */
    public string $turnClosingResolvedMode = AgentDecisionModelSettings::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER;

    public ?int $turnClosingSetByAccountId = null;

    public ?string $turnClosingSetAt = null;

    /** @var bool True when the turn-closing site is `off` in the environment — the row cannot lift it, the control renders disabled. */
    public bool $turnClosingToggleDisabled = true;

    /** @var string One line: `site: on · env: deterministicCounter · row: decisionModel · resolved: decisionModel · set by 7 at …`. */
    public string $turnClosingResolvedLine = '';

    /** @var string The reasoning-effort site's mode for this conversation (`off` / `shadow` / `on`). */
    public string $reasoningEffortSiteMode = AgentDecisionModelSettings::MODE_OFF;

    /** @var string|null The conversation's own effort row (`off` / `low` / `medium` / `high`), null = inherit. */
    public ?string $reasoningEffortRowSwitch = null;

    public ?int $reasoningEffortSetByAccountId = null;

    public ?string $reasoningEffortSetAt = null;

    /** @var bool True when the effort site is `off` in the environment — the row cannot lift it. */
    public bool $reasoningEffortToggleDisabled = true;

    /** @var string One line: `site: on · default: low · row: medium · set by 7 at …`. */
    public string $reasoningEffortResolvedLine = '';

    /** @var string|null The conversation model's CONFIGURED agentic effort (`agenticUseCase.reasoningEffort`), null = none configured. */
    public ?string $reasoningEffortConfigured = null;

    /** @var string The ceiling every effort is clamped to (`agenticUseCase.maxReasoningEffort`, else `high`). */
    public string $reasoningEffortCeiling = AgentReasoningEffortDecision::DEFAULT_CEILING;

    /** @var string|null The purpose's declared floor (`minReasoningEffort` in the purpose frontmatter), null = none. */
    public ?string $reasoningEffortPurposeMinimum = null;

    /** @var string|null The minimum the plan's NEXT open item declares right now (per turn, not part of the baseline), null = none. */
    public ?string $reasoningEffortPlanItemMinimum = null;

    /** @var bool A delegation or an autonomous purpose: no decision-model verdict and no row pin — the purpose's base effort (else the configured one) raised by the declared minimums. */
    public bool $reasoningEffortWorksWithoutOwnerTurn = false;

    /**
     * @var string|null The effort a turn of THIS conversation is sent with when nothing turn-specific raises it (no
     * decision-model raise, no plan-item minimum) — the same rule as the model call, a fixed effort included; the
     * comparison point the per-turn effort chips render deviations against. Null = no effort is sent and none is
     * configured (the provider's default).
     */
    public ?string $reasoningEffortBaseline = null;

    /** @var string What set {@see $reasoningEffortBaseline}: `configured`, `default`, `pin`, `purpose` or `fixed`. */
    public string $reasoningEffortBaselineSource = AgentReasoningEffortDecision::FLOOR_SOURCE_DEFAULT;

    /** @var string One line: `baseline: medium (purpose) · configured: low · default: low · purpose: medium · pin: — · ceiling: high · site: on`. */
    public string $reasoningEffortBaselineLine = '';

    /** @var string The stall-recovery site's mode for this conversation (`off` / `shadow` / `on`, after the kill switch and the row). */
    public string $stallRecoverySiteMode = AgentDecisionModelSettings::MODE_OFF;

    /** @var string|null The conversation's own row (`on` / `off`), null = inherit. */
    public ?string $stallRecoveryRowSwitch = null;

    public ?int $stallRecoverySetByAccountId = null;

    public ?string $stallRecoverySetAt = null;

    /** @var bool True when the stall-recovery site is `off` in the environment — the row cannot lift it. */
    public bool $stallRecoveryToggleDisabled = true;

    /** @var string One line: `site: shadow · row: on · resolved: on · set by 7 at …`. */
    public string $stallRecoveryResolvedLine = '';

    public string $replyLanguageSiteMode = AgentDecisionModelSettings::MODE_OFF;

    public ?string $replyLanguageRowSwitch = null;

    public ?int $replyLanguageSetByAccountId = null;

    public ?string $replyLanguageSetAt = null;

    public bool $replyLanguageToggleDisabled = true;

    public string $replyLanguageResolvedLine = '';

    /** @var string The plan-dynamics mode this conversation RUNS with (derived from the two switches after the kill switch and the rows). */
    public string $planDynamicsSiteMode = AgentDecisionModelSettings::MODE_OFF;

    /** @var bool Switch 1 of the environment: the decision model is asked the plan question. */
    public bool $planDynamicsAskDecisionModelEnv = false;

    /** @var bool Switch 2 of the environment: the chat model receives the verdict. */
    public bool $planDynamicsModelReceivesVerdictEnv = false;

    /** @var bool|null The conversation's own switch 1, null = inherit. */
    public ?bool $planDynamicsAskDecisionModelRow = null;

    /** @var bool|null The conversation's own switch 2, null = inherit. */
    public ?bool $planDynamicsModelReceivesVerdictRow = null;

    /** @var bool The resolved switch 1 for this conversation. */
    public bool $planDynamicsAskDecisionModel = false;

    /** @var bool The resolved switch 2 for this conversation. */
    public bool $planDynamicsModelReceivesVerdict = false;

    public ?int $planDynamicsSetByAccountId = null;

    public ?string $planDynamicsSetAt = null;

    public bool $planDynamicsToggleDisabled = true;

    public string $planDynamicsResolvedLine = '';

    public bool $decisionModelOff = false;

    public ?int $decisionModelOffSetByAccountId = null;

    public ?string $decisionModelOffSetAt = null;

    /**
     * @param AgentReasoningEffortLimits|null $agentReasoningEffortLimits the conversation's effort limits as the model call
     *        reads them ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentReasoningEffortDecisionsService::buildReasoningEffortLimitsForConversation()});
     *        null = only what the conversation itself carries (no catalog effort, the default ceiling, no purpose)
     */
    public static function forConversation(AIConversation $conversation, ?AgentDecisionModelSettings $settings = null, ?AgentReasoningEffortLimits $agentReasoningEffortLimits = null): static
    {
        $settings ??= AgentDecisionModelSettings::current();
        $status = new static();
        $status->globalMode = $settings->mode;
        foreach ([
            AgentDecisionModelSettings::SITE_REPLY_LANGUAGE,
            AgentDecisionModelSettings::SITE_TURN_CLOSING,
            AgentDecisionModelSettings::SITE_SKILL_PRELOAD,
            AgentDecisionModelSettings::SITE_PLAN_ITEM,
            AgentDecisionModelSettings::SITE_REASONING_EFFORT,
            AgentDecisionModelSettings::SITE_STALL_RECOVERY,
        ] as $site) {
            $status->sites[$site] = $settings->siteMode($site);
        }
        $status->skillPreloadSiteMode = $settings->siteMode(AgentDecisionModelSettings::SITE_SKILL_PRELOAD);
        $status->skillPreloadArm = $status->skillPreloadSiteMode === AgentDecisionModelSettings::MODE_COHORT
            ? AgentDecisionModelSettings::resolveArmForAccountId($conversation->accountId ?? null)
            : null;
        $runSettings = isset($conversation->runSettings) ? $conversation->runSettings : null;
        $status->skillPreloadRowSwitch = $runSettings?->decisionModelSkillPreload;
        $status->skillPreloadSetByAccountId = $runSettings?->decisionModelSkillPreloadSetByAccountId;
        $status->skillPreloadSetAt = $runSettings?->decisionModelSkillPreloadSetAt;
        // the ONE helper (env veto → kill switch → row), so the panel shows what the loop runs with
        $resolved = $settings->siteModeForConversation(AgentDecisionModelSettings::SITE_SKILL_PRELOAD, $conversation);
        $status->skillPreloadResolvedMode = $resolved;
        $status->decisionModelOff = $runSettings?->decisionModelOff === true;
        $status->decisionModelOffSetByAccountId = $runSettings?->decisionModelOffSetByAccountId;
        $status->decisionModelOffSetAt = $runSettings?->decisionModelOffSetAt;
        $status->turnClosingSiteMode = $settings->siteModeForConversation(AgentDecisionModelSettings::SITE_TURN_CLOSING, $conversation);
        $status->turnClosingModeEnv = $settings->turnClosingMode;
        $status->turnClosingRowSwitch = $runSettings?->decisionModelTurnClosing;
        $status->turnClosingResolvedMode = $settings->turnClosingModeForConversation($conversation);
        $status->turnClosingSetByAccountId = $runSettings?->decisionModelTurnClosingSetByAccountId;
        $status->turnClosingSetAt = $runSettings?->decisionModelTurnClosingSetAt;
        $status->turnClosingToggleDisabled = $settings->siteMode(AgentDecisionModelSettings::SITE_TURN_CLOSING) === AgentDecisionModelSettings::MODE_OFF;
        $status->turnClosingResolvedLine = 'site: ' . $status->turnClosingSiteMode
            . ' · env: ' . $status->turnClosingModeEnv
            . ' · row: ' . ($status->turnClosingRowSwitch ?? 'inherit')
            . ' · resolved: ' . ($status->turnClosingSiteMode === AgentDecisionModelSettings::MODE_ON ? $status->turnClosingResolvedMode : 'inert (site ' . $status->turnClosingSiteMode . ')')
            . ($status->decisionModelOff ? ' · KILL SWITCH ON' : '')
            . ($status->turnClosingSetByAccountId !== null ? ' · set by ' . $status->turnClosingSetByAccountId . ' at ' . ($status->turnClosingSetAt ?? '?') : '');
        // the effort site (a row value is a PIN, the site may raise above it) and the stall-recovery site
        $status->reasoningEffortSiteMode = $settings->siteModeForConversation(AgentDecisionModelSettings::SITE_REASONING_EFFORT, $conversation);
        $status->reasoningEffortRowSwitch = $runSettings?->decisionModelReasoningEffort;
        $status->reasoningEffortSetByAccountId = $runSettings?->decisionModelReasoningEffortSetByAccountId;
        $status->reasoningEffortSetAt = $runSettings?->decisionModelReasoningEffortSetAt;
        $status->reasoningEffortToggleDisabled = $settings->siteMode(AgentDecisionModelSettings::SITE_REASONING_EFFORT) === AgentDecisionModelSettings::MODE_OFF;
        $status->reasoningEffortResolvedLine = 'site: ' . $status->reasoningEffortSiteMode
            . ' · row: ' . ($status->reasoningEffortRowSwitch ?? 'inherit')
            . ($status->decisionModelOff ? ' · KILL SWITCH ON' : '')
            . ($status->reasoningEffortSetByAccountId !== null ? ' · set by ' . $status->reasoningEffortSetByAccountId . ' at ' . ($status->reasoningEffortSetAt ?? '?') : '');
        $status->applyReasoningEffortBaseline($agentReasoningEffortLimits ?? AgentReasoningEffortLimits::fromConversation($conversation, $settings));
        $status->stallRecoverySiteMode = $settings->siteModeForConversation(AgentDecisionModelSettings::SITE_STALL_RECOVERY, $conversation);
        $status->stallRecoveryRowSwitch = $runSettings?->decisionModelStallRecovery;
        $status->stallRecoverySetByAccountId = $runSettings?->decisionModelStallRecoverySetByAccountId;
        $status->stallRecoverySetAt = $runSettings?->decisionModelStallRecoverySetAt;
        $status->stallRecoveryToggleDisabled = $settings->siteMode(AgentDecisionModelSettings::SITE_STALL_RECOVERY) === AgentDecisionModelSettings::MODE_OFF;
        $status->stallRecoveryResolvedLine = 'site: ' . $settings->siteMode(AgentDecisionModelSettings::SITE_STALL_RECOVERY)
            . ' · row: ' . ($status->stallRecoveryRowSwitch ?? 'inherit')
            . ' · resolved: ' . $status->stallRecoverySiteMode
            . ($status->decisionModelOff ? ' · KILL SWITCH ON' : '')
            . ($status->stallRecoverySetByAccountId !== null ? ' · set by ' . $status->stallRecoverySetByAccountId . ' at ' . ($status->stallRecoverySetAt ?? '?') : '');
        // the language row and the reserved plan-dynamics row
        $status->sites[AgentDecisionModelSettings::SITE_PLAN_DYNAMICS] = $settings->siteMode(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS);
        $status->replyLanguageSiteMode = $settings->siteModeForConversation(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE, $conversation);
        $status->replyLanguageRowSwitch = $runSettings?->decisionModelReplyLanguage;
        $status->replyLanguageSetByAccountId = $runSettings?->decisionModelReplyLanguageSetByAccountId;
        $status->replyLanguageSetAt = $runSettings?->decisionModelReplyLanguageSetAt;
        $status->replyLanguageToggleDisabled = $settings->siteMode(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE) === AgentDecisionModelSettings::MODE_OFF;
        $status->replyLanguageResolvedLine = 'site: ' . $settings->siteMode(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE)
            . ' · row: ' . ($status->replyLanguageRowSwitch ?? 'inherit')
            . ' · resolved: ' . $status->replyLanguageSiteMode
            . ($status->decisionModelOff ? ' · KILL SWITCH ON' : '')
            . ($status->replyLanguageSetByAccountId !== null ? ' · set by ' . $status->replyLanguageSetByAccountId . ' at ' . ($status->replyLanguageSetAt ?? '?') : '');
        // two switches — asked? / does the model receive the verdict? — each row overrides its
        // environment switch; the kill switch and the global mode still silence the site as a whole
        $status->planDynamicsSiteMode = $settings->siteModeForConversation(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, $conversation);
        $status->planDynamicsAskDecisionModelEnv = $settings->planDynamicsAskDecisionModel;
        $status->planDynamicsModelReceivesVerdictEnv = $settings->planDynamicsModelReceivesVerdict;
        $status->planDynamicsAskDecisionModelRow = $runSettings?->planDynamicsAskDecisionModel;
        $status->planDynamicsModelReceivesVerdictRow = $runSettings?->planDynamicsModelReceivesVerdict;
        $status->planDynamicsAskDecisionModel = $status->planDynamicsSiteMode !== AgentDecisionModelSettings::MODE_OFF;
        $status->planDynamicsModelReceivesVerdict = $status->planDynamicsSiteMode === AgentDecisionModelSettings::MODE_ON;
        $status->planDynamicsSetByAccountId = $runSettings?->planDynamicsSetByAccountId;
        $status->planDynamicsSetAt = $runSettings?->planDynamicsSetAt;
        $status->planDynamicsToggleDisabled = $settings->mode === AgentDecisionModelSettings::MODE_OFF;
        $renderSwitch = static fn (?bool $value): string => $value === null ? 'inherit' : ($value ? 'yes' : 'no');
        $status->planDynamicsResolvedLine = 'ask decision model: env ' . $renderSwitch($status->planDynamicsAskDecisionModelEnv)
            . ' · row ' . $renderSwitch($status->planDynamicsAskDecisionModelRow)
            . ' → ' . $renderSwitch($status->planDynamicsAskDecisionModel)
            . ' · model receives verdict: env ' . $renderSwitch($status->planDynamicsModelReceivesVerdictEnv)
            . ' · row ' . $renderSwitch($status->planDynamicsModelReceivesVerdictRow)
            . ' → ' . $renderSwitch($status->planDynamicsModelReceivesVerdict)
            . ' · resolved: ' . $status->planDynamicsSiteMode
            . ($status->decisionModelOff ? ' · KILL SWITCH ON' : '')
            . ($status->planDynamicsSetByAccountId !== null ? ' · set by ' . $status->planDynamicsSetByAccountId . ' at ' . ($status->planDynamicsSetAt ?? '?') : '');
        $status->skillPreloadToggleDisabled = $status->skillPreloadSiteMode === AgentDecisionModelSettings::MODE_OFF;
        $status->skillPreloadResolvedLine = 'site: ' . $status->skillPreloadSiteMode
            . ($status->skillPreloadArm !== null ? ' (arm ' . $status->skillPreloadArm . ')' : '')
            . ' · row: ' . ($status->skillPreloadRowSwitch ?? 'inherit')
            . ' · resolved: ' . $resolved
            . ($status->skillPreloadSetByAccountId !== null ? ' · set by ' . $status->skillPreloadSetByAccountId . ' at ' . ($status->skillPreloadSetAt ?? '?') : '');
        return $status;
    }

    /**
     * The conversation's reasoning-effort inputs (global and per turn), each with its source so a deviation names where
     * it comes from, and the RESULTING baseline ({@see AgentReasoningEffortLimits::buildBaseline()}).
     */
    protected function applyReasoningEffortBaseline(AgentReasoningEffortLimits $agentReasoningEffortLimits): void
    {
        $this->reasoningEffortConfigured = $agentReasoningEffortLimits->configuredEffort;
        $this->reasoningEffortCeiling = $agentReasoningEffortLimits->ceilingEffort;
        $this->reasoningEffortPurposeMinimum = $agentReasoningEffortLimits->purposeMinimum;
        $this->reasoningEffortWorksWithoutOwnerTurn = $agentReasoningEffortLimits->worksWithoutAnOwnerTurn;
        $this->reasoningEffortPlanItemMinimum = $agentReasoningEffortLimits->planItemMinimum;
        $pinnedEffort = $agentReasoningEffortLimits->pinnedEffort;
        $agentReasoningEffortBaseline = $agentReasoningEffortLimits->buildBaseline();
        $this->reasoningEffortBaseline = $agentReasoningEffortBaseline->effort;
        $this->reasoningEffortBaselineSource = $agentReasoningEffortBaseline->source;
        $this->reasoningEffortBaselineLine = 'baseline: ' . ($this->reasoningEffortBaseline ?? 'none (provider default)') . ' (' . $this->reasoningEffortBaselineSource . ')'
            . ' · configured: ' . ($this->reasoningEffortConfigured ?? '—')
            . ' · purpose: ' . ($this->reasoningEffortPurposeMinimum ?? '—')
            . ' · pin: ' . ($pinnedEffort ?? '—')
            . ' · ceiling: ' . $this->reasoningEffortCeiling
            . ' · site: ' . $this->reasoningEffortSiteMode
            . ($agentReasoningEffortLimits->purposeBaseEffort !== null ? ' · purpose base: ' . $agentReasoningEffortLimits->purposeBaseEffort : '')
            . ($agentReasoningEffortLimits->fixedEffort !== null ? ' · fixed: ' . $agentReasoningEffortLimits->fixedEffort : '')
            . ($this->reasoningEffortWorksWithoutOwnerTurn ? ' · no owner turn (purpose base, else configured, + declared minimums)' : '')
            . ($this->reasoningEffortPlanItemMinimum !== null ? ' · next plan item: ' . $this->reasoningEffortPlanItemMinimum : '');
    }
}
