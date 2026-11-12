<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/** One decision site and its effective mode (the admin panel's row). */
class AgentDecisionSiteMode extends ValueObject
{
    /** @var string The site's canonical name (`replyLanguage`, `skillPreload`, …). */
    public string $site = '';

    /** @var string `off` / `shadow` / `on` / `cohort`. */
    public string $mode = AgentDecisionModelSettings::MODE_OFF;

    /** @var bool True when the site is listed explicitly (environment or stored default); false = its implicit default. */
    public bool $listed = false;
}
