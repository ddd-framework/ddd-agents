<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionLayerStatus;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortLimits;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The resolved line both panels render: site mode × cohort arm × the conversation's own switch,
 * with `off` as the absolute veto and the toggles disabled exactly when the site is off in the environment.
 */
class AgentDecisionLayerStatusTest extends UnitTestCase
{
    protected function conversation(int $accountId, ?string $rowSwitch = null): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->accountId = $accountId;
        if ($rowSwitch !== null) {
            $runSettings = new RunSettings();
            $runSettings->decisionModelSkillPreload = $rowSwitch;
            $runSettings->decisionModelSkillPreloadSetByAccountId = 7;
            $runSettings->decisionModelSkillPreloadSetAt = '2026-09-24 10:00:00';
            $conversation->runSettings = $runSettings;
        }
        return $conversation;
    }

    public function testSiteOffIsTheAbsoluteVetoAndDisablesTheToggle(): void
    {
        // an UNLISTED preload site runs on; the veto is the explicit `:off`
        $settings = AgentDecisionModelSettings::fromValues('on', 'replyLanguage,skillPreload:off', null, null);
        $status = AgentDecisionLayerStatus::forConversation($this->conversation(1, 'on'), $settings);

        self::assertSame('off', $status->skillPreloadSiteMode);
        self::assertSame('off', $status->skillPreloadResolvedMode, 'a row on cannot lift a site the environment switched off');
        self::assertTrue($status->skillPreloadToggleDisabled);
        self::assertSame('on', $status->sites['replyLanguage']);
        self::assertStringContainsString('resolved: off', $status->skillPreloadResolvedLine);
    }

    public function testARowOnLiftsShadowAndARowOffSilencesOn(): void
    {
        $shadow = AgentDecisionModelSettings::fromValues('on', 'skillPreload:shadow', null, null);
        $lifted = AgentDecisionLayerStatus::forConversation($this->conversation(1, 'on'), $shadow);
        self::assertSame('on', $lifted->skillPreloadResolvedMode);
        self::assertFalse($lifted->skillPreloadToggleDisabled);
        self::assertSame('site: shadow · row: on · resolved: on · set by 7 at 2026-09-24 10:00:00', $lifted->skillPreloadResolvedLine);

        $on = AgentDecisionModelSettings::fromValues('on', 'skillPreload', null, null);
        $silenced = AgentDecisionLayerStatus::forConversation($this->conversation(1, 'off'), $on);
        self::assertSame('off', $silenced->skillPreloadResolvedMode);
        $inherited = AgentDecisionLayerStatus::forConversation($this->conversation(1), $on);
        self::assertSame('on', $inherited->skillPreloadResolvedMode);
        self::assertSame('site: on · row: inherit · resolved: on', $inherited->skillPreloadResolvedLine);
    }

    public function testCohortShowsTheArmAndResolvesThroughIt(): void
    {
        $cohort = AgentDecisionModelSettings::fromValues('on', 'skillPreload:cohort', null, null);
        foreach ([1, 2, 3, 4, 5, 6] as $accountId) {
            $status = AgentDecisionLayerStatus::forConversation($this->conversation($accountId), $cohort);
            $expectedArm = AgentDecisionModelSettings::resolveArmForAccountId($accountId);
            self::assertSame($expectedArm, $status->skillPreloadArm);
            self::assertSame(match ($expectedArm) { 'control' => 'off', 'shadow' => 'shadow', default => 'on' }, $status->skillPreloadResolvedMode);
            self::assertStringContainsString("(arm $expectedArm)", $status->skillPreloadResolvedLine);
        }
        $overridden = AgentDecisionLayerStatus::forConversation($this->conversation(1, 'on'), $cohort);
        self::assertSame('on', $overridden->skillPreloadResolvedMode, 'a row value wins under cohort (and is excluded from gate 6)');
    }

    // ── the turn-closing line and the kill switch ──

    protected function closingConversation(?string $row = null, ?bool $killSwitch = null): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->accountId = 1;
        $runSettings = new RunSettings();
        $runSettings->decisionModelTurnClosing = $row;
        $runSettings->decisionModelOff = $killSwitch;
        if ($row !== null) {
            $runSettings->decisionModelTurnClosingSetByAccountId = 9;
            $runSettings->decisionModelTurnClosingSetAt = '2026-09-24 22:00:00';
        }
        $conversation->runSettings = $runSettings;
        return $conversation;
    }

    public function testTheTurnClosingLineResolvesTheGuardModeOnlyWhileTheSiteIsOn(): void
    {
        $envBeside = AgentDecisionModelSettings::fromValues('on', 'turnClosing', null, null, 'deterministicCounter');
        $status = AgentDecisionLayerStatus::forConversation($this->closingConversation(), $envBeside);
        self::assertSame('on', $status->turnClosingSiteMode);
        self::assertSame('deterministicCounter', $status->turnClosingResolvedMode);
        self::assertFalse($status->turnClosingToggleDisabled);
        self::assertSame('site: on · env: deterministicCounter · row: inherit · resolved: deterministicCounter', $status->turnClosingResolvedLine);

        $status = AgentDecisionLayerStatus::forConversation($this->closingConversation('decisionModel'), $envBeside);
        self::assertSame('decisionModel', $status->turnClosingResolvedMode, 'the row selects the arm');
        self::assertSame('site: on · env: deterministicCounter · row: decisionModel · resolved: decisionModel · set by 9 at 2026-09-24 22:00:00', $status->turnClosingResolvedLine);

        $shadow = AgentDecisionModelSettings::fromValues('on', 'turnClosing:shadow', null, null, 'decisionModel');
        $status = AgentDecisionLayerStatus::forConversation($this->closingConversation('decisionModel'), $shadow);
        self::assertSame('shadow', $status->turnClosingSiteMode);
        self::assertSame('deterministicCounter', $status->turnClosingResolvedMode, 'a turn-closing mode is not a site mode');
        self::assertStringContainsString('resolved: inert (site shadow)', $status->turnClosingResolvedLine);

        $off = AgentDecisionModelSettings::fromValues('on', 'replyLanguage', null, null, 'decisionModel');
        $status = AgentDecisionLayerStatus::forConversation($this->closingConversation('decisionModel'), $off);
        self::assertTrue($status->turnClosingToggleDisabled, 'the env veto disables the control');
        self::assertSame('off', $status->turnClosingSiteMode);
    }

    public function testTheKillSwitchShowsOnEveryLineAndSilencesEverySite(): void
    {
        $settings = AgentDecisionModelSettings::fromValues('on', 'replyLanguage,turnClosing,skillPreload:on,planItemSkillPreload', null, null, 'decisionModel');
        $status = AgentDecisionLayerStatus::forConversation($this->closingConversation('decisionModel', true), $settings);
        self::assertTrue($status->decisionModelOff);
        self::assertSame('off', $status->turnClosingSiteMode);
        self::assertSame('off', $status->skillPreloadResolvedMode);
        self::assertStringContainsString('KILL SWITCH ON', $status->turnClosingResolvedLine);
        self::assertSame('on', $status->sites['planItemSkillPreload'], 'the environment table stays the environment');
    }

    // ── the conversation's effort baseline, each input with its source ──

    protected function effortConversation(?string $pin = null): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->accountId = 1;
        $runSettings = new RunSettings();
        $runSettings->decisionModelReasoningEffort = $pin;
        $conversation->runSettings = $runSettings;
        return $conversation;
    }

    /** The limits the gatherer hands the status: the conversation's own, plus the catalog / purpose / autonomy reads. */
    protected function conversationEffortLimits(AIConversation $conversation, AgentDecisionModelSettings $settings, ?string $configuredEffort, string $ceilingEffort, ?string $purposeMinimum, bool $worksWithoutAnOwnerTurn): AgentReasoningEffortLimits
    {
        $agentReasoningEffortLimits = AgentReasoningEffortLimits::fromConversation($conversation, $settings);
        $agentReasoningEffortLimits->configuredEffort = $configuredEffort;
        $agentReasoningEffortLimits->ceilingEffort = $ceilingEffort;
        $agentReasoningEffortLimits->purposeMinimum = $purposeMinimum;
        $agentReasoningEffortLimits->worksWithoutAnOwnerTurn = $worksWithoutAnOwnerTurn;
        return $agentReasoningEffortLimits;
    }

    /** @return array{0: string|null, 1: string} the baseline as [effort, source] */
    protected static function baselinePair(AgentReasoningEffortLimits $agentReasoningEffortLimits): array
    {
        $agentReasoningEffortBaseline = $agentReasoningEffortLimits->buildBaseline();
        return [$agentReasoningEffortBaseline->effort, $agentReasoningEffortBaseline->source];
    }

    /** Limits built field by field, for the baseline formula alone (the site default is `low`). */
    protected function effortLimits(string $siteMode, bool $worksWithoutAnOwnerTurn, ?string $configuredEffort, ?string $pinnedEffort, ?string $purposeMinimum, string $ceilingEffort): AgentReasoningEffortLimits
    {
        $agentReasoningEffortLimits = new AgentReasoningEffortLimits();
        $agentReasoningEffortLimits->siteMode = $siteMode;
        $agentReasoningEffortLimits->worksWithoutAnOwnerTurn = $worksWithoutAnOwnerTurn;
        $agentReasoningEffortLimits->configuredEffort = $configuredEffort;
        $agentReasoningEffortLimits->pinnedEffort = $pinnedEffort;
        $agentReasoningEffortLimits->purposeMinimum = $purposeMinimum;
        $agentReasoningEffortLimits->ceilingEffort = $ceilingEffort;
        return $agentReasoningEffortLimits;
    }

    public function testTheBaselineIsTheSiteDefaultRaisedByThePurposeFloorWithEveryInputLabelled(): void
    {
        $settings = AgentDecisionModelSettings::fromValues('on', 'reasoningEffort', null, null);
        $effortConversation = $this->effortConversation();
        $status = AgentDecisionLayerStatus::forConversation($effortConversation, $settings, $this->conversationEffortLimits($effortConversation, $settings, 'low', 'high', 'medium', false));
        self::assertSame('low', $status->reasoningEffortConfigured);
        self::assertSame('medium', $status->reasoningEffortPurposeMinimum);
        self::assertSame('high', $status->reasoningEffortCeiling);
        self::assertSame('medium', $status->reasoningEffortBaseline);
        self::assertSame('purpose', $status->reasoningEffortBaselineSource);
        self::assertSame(
            'baseline: medium (purpose) · configured: low · purpose: medium · pin: — · ceiling: high · site: on',
            $status->reasoningEffortBaselineLine
        );

        $withoutPurpose = AgentDecisionLayerStatus::forConversation($effortConversation, $settings, $this->conversationEffortLimits($effortConversation, $settings, 'low', 'high', null, false));
        self::assertSame('low', $withoutPurpose->reasoningEffortBaseline);
        self::assertSame('default', $withoutPurpose->reasoningEffortBaselineSource);
    }

    public function testThePinRaisesTheBaselineAndThePurposeFloorNamesTheSourceOnlyWhenItRaisesOrTies(): void
    {
        $settings = AgentDecisionModelSettings::fromValues('on', 'reasoningEffort', null, null);
        $pinnedConversation = $this->effortConversation('high');
        $pinned = AgentDecisionLayerStatus::forConversation($pinnedConversation, $settings, $this->conversationEffortLimits($pinnedConversation, $settings, 'low', 'high', 'medium', false));
        self::assertSame('high', $pinned->reasoningEffortBaseline);
        self::assertSame('pin', $pinned->reasoningEffortBaselineSource, 'a purpose floor below the pin does not name the source');
        self::assertStringContainsString('pin: high', $pinned->reasoningEffortBaselineLine);

        self::assertSame(['medium', 'purpose'], self::baselinePair($this->effortLimits('on', false, 'low', 'medium', 'medium', 'high')), 'a tie names the more specific declaration');
        self::assertSame(['medium', 'purpose'], self::baselinePair($this->effortLimits('on', false, 'low', null, 'high', 'medium')), 'the ceiling clamps the purpose floor');
    }

    public function testSiteOffShadowAndNoOwnerTurnFollowTheModelCallsOwnResolution(): void
    {
        // site off: the configured effort, no minimum applies (the model call returns before reading them)
        self::assertSame(['low', 'configured'], self::baselinePair($this->effortLimits('off', false, 'low', 'high', 'medium', 'high')));
        self::assertSame([null, 'configured'], self::baselinePair($this->effortLimits('off', false, null, null, null, 'high')));
        // shadow: the row pin is an admin instruction and applies; without it the configured effort
        self::assertSame(['medium', 'pin'], self::baselinePair($this->effortLimits('shadow', false, 'low', 'medium', null, 'high')));
        self::assertSame(['low', 'configured'], self::baselinePair($this->effortLimits('shadow', false, 'low', null, null, 'high')));
        // a delegation / autonomous purpose: no pin, the configured effort raised by the purpose floor
        self::assertSame(['low', 'configured'], self::baselinePair($this->effortLimits('on', true, 'low', 'high', null, 'high')));
        self::assertSame(['medium', 'purpose'], self::baselinePair($this->effortLimits('on', true, 'low', 'high', 'medium', 'high')));

        $settings = AgentDecisionModelSettings::fromValues('on', 'replyLanguage', null, null);
        $effortConversation = $this->effortConversation();
        $status = AgentDecisionLayerStatus::forConversation($effortConversation, $settings, $this->conversationEffortLimits($effortConversation, $settings, null, 'high', null, true));
        self::assertSame('off', $status->reasoningEffortSiteMode);
        self::assertNull($status->reasoningEffortBaseline);
        self::assertStringStartsWith('baseline: none (provider default) (configured)', $status->reasoningEffortBaselineLine);
        self::assertStringContainsString('no owner turn', $status->reasoningEffortBaselineLine);
    }

    public function testTheBaselineIsTheEffortSentWithoutATurnStampAndWithoutThePlanItemMinimum(): void
    {
        // a fixed effort is the baseline on every path, site off included
        $fixed = $this->effortLimits('off', false, 'low', 'high', null, 'high');
        $fixed->fixedEffort = 'medium';
        self::assertSame(['medium', 'fixed'], self::baselinePair($fixed));
        // the catalog effort above the site default under `on`
        self::assertSame(['medium', 'configured'], self::baselinePair($this->effortLimits('on', false, 'medium', null, null, 'high')));
        // the purpose base on every path: `on`, `shadow`, without an owner turn; the pin raises it for an owner turn
        foreach ([['on', false], ['shadow', false], ['on', true]] as [$siteMode, $worksWithoutAnOwnerTurn]) {
            $purposeBase = $this->effortLimits($siteMode, $worksWithoutAnOwnerTurn, 'low', null, null, 'high');
            $purposeBase->purposeBaseEffort = 'medium';
            self::assertSame(['medium', 'purpose'], self::baselinePair($purposeBase), $siteMode . ($worksWithoutAnOwnerTurn ? ' without an owner turn' : ''));
            self::assertSame('medium', $purposeBase->resolveEffortToSend(null, null), 'the model call sends the same effort');
        }
        $pinnedOverPurposeBase = $this->effortLimits('shadow', false, 'low', 'high', null, 'high');
        $pinnedOverPurposeBase->purposeBaseEffort = 'medium';
        self::assertSame(['high', 'pin'], self::baselinePair($pinnedOverPurposeBase));
        // the plan item's minimum is turn-specific: sent, but not the baseline
        $planItem = $this->effortLimits('on', false, 'low', null, null, 'high');
        $planItem->planItemMinimum = 'high';
        self::assertSame('high', $planItem->resolveEffortToSend(null, null));
        self::assertSame(['low', 'default'], self::baselinePair($planItem));

        $settings = AgentDecisionModelSettings::fromValues('on', 'reasoningEffort', null, null);
        $effortConversation = $this->effortConversation();
        $agentReasoningEffortLimits = $this->conversationEffortLimits($effortConversation, $settings, 'low', 'high', null, false);
        $agentReasoningEffortLimits->purposeBaseEffort = 'medium';
        $agentReasoningEffortLimits->fixedEffort = 'high';
        $status = AgentDecisionLayerStatus::forConversation($effortConversation, $settings, $agentReasoningEffortLimits);
        self::assertSame('high', $status->reasoningEffortBaseline);
        self::assertSame('fixed', $status->reasoningEffortBaselineSource);
        self::assertSame(
            'baseline: high (fixed) · configured: low · purpose: — · pin: — · ceiling: high · site: on · purpose base: medium · fixed: high',
            $status->reasoningEffortBaselineLine
        );
    }

    public function testTheOverrideValueIsNullWhenNothingIsSet(): void
    {
        self::assertNull(RunSettingsOverride::fromValues(null, null, null));
        $override = RunSettingsOverride::fromValues(null, 'decisionModel', null);
        self::assertNotNull($override);
        self::assertFalse($override->isEmpty());
        self::assertSame('decisionModel', $override->decisionModelTurnClosing);
        self::assertNull($override->decisionModelSkillPreload);
    }
}
