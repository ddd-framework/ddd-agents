<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;

/** Fixture: the settings of an application that asks the decision model one question of its own. */
class AgentDecisionModelSettingsOfAnApplicationWithItsOwnSite extends AgentDecisionModelSettings
{
    public const string SITE_CHECK_OF_THE_APPLICATION = 'checkOfTheApplication';

    public const array SITES = [
        self::SITE_REPLY_LANGUAGE,
        self::SITE_CHECK_OF_THE_APPLICATION,
        self::SITE_TURN_CLOSING,
    ];
}
