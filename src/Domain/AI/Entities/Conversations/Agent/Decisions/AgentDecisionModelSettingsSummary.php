<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One decision-layer settings object as the admin panel reads it: the global mode, every site's mode, the
 * turn-closing mode, the effort default and the outage contract — the committed config, nothing else.
 * Built from an {@see AgentDecisionModelSettings}; never persisted.
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionModelSettingsSummary extends ValueObject
{
    /** @var string `off` / `shadow` / `on`. */
    public string $mode = AgentDecisionModelSettings::MODE_OFF;

    /** @var AgentDecisionSiteMode[] Every site with its effective mode, in panel order. */
    public array $sites = [];

    /** @var string `deterministicCounter` / `decisionModel` / `counterThenDecisionModel`. */
    public string $turnClosingMode = AgentDecisionModelSettings::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER;

    public float $timeoutSeconds = AgentDecisionModelSettings::DEFAULT_TIMEOUT_SECONDS;

    public int $breakerSeconds = AgentDecisionModelSettings::DEFAULT_BREAKER_SECONDS;

    public static function fromSettings(AgentDecisionModelSettings $settings): static
    {
        $summary = new static();
        $summary->mode = $settings->mode;
        foreach ($settings->siteModesSummary() as $site => $mode) {
            $siteMode = new AgentDecisionSiteMode();
            $siteMode->site = $site;
            $siteMode->mode = $mode;
            $siteMode->listed = in_array(strtolower($site), $settings->enabledSites, true);
            $summary->sites[] = $siteMode;
        }
        $summary->turnClosingMode = $settings->turnClosingMode;
        $summary->timeoutSeconds = $settings->timeoutSeconds;
        $summary->breakerSeconds = $settings->breakerSeconds;
        return $summary;
    }
}
