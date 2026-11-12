<?php

declare(strict_types=1);

/**
 * The decision layer defaults of the agent — the package default, read by
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings::current()} as
 * `Config::get('AI.decisionModel')`. An application replaces this file whole with its own
 * `config/app/AI/decisionModel.php` (keys are never merged); the per-conversation run settings come last.
 *
 * `mode`: `off` (no decision call anywhere) / `shadow` (asked, recorded, never applied) / `on`.
 * `sites`: site → `off` / `shadow` / `on`. A site missing here is `off`, except the two skill preload sites, which
 * are `on` unless listed otherwise ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings::DEFAULT_ON_SITES}).
 * The package default asks no decision model: a second application switches the sites on once its model catalog names
 * a decision model.
 */
return [
    'mode' => 'off',
    'sites' => [
        'skillPreload' => 'off',
        'planItemSkillPreload' => 'off',
        'planDynamics' => [
            'askDecisionModel' => false,
            'modelReceivesVerdict' => false,
        ],
    ],
    // the checked stop's mode while `turnClosing` is on: deterministicCounter | decisionModel | counterThenDecisionModel
    'turnClosingMode' => 'deterministicCounter',
    // the outage contract: per-call timeout and the shared circuit breaker's open time
    'timeoutSeconds' => 2.0,
    'breakerSeconds' => 60,
];
