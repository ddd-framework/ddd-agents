<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The reply language ONE owner turn resolves to by recency and the source that decided it. Built by
 * {@see AgentReplyLanguageDecision::resolveByRecency()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentReplyLanguageResolution extends ValueObject
{
    /** @var string The language code the reply is written in */
    public string $languageCode = '';

    /** @var string The source that decided it ({@see AgentReplyLanguageDecision} SOURCE_*) */
    public string $source = '';

    /**
     * @param string $languageCode
     * @param string $source
     * @return static
     */
    public static function forLanguageCodeAndSource(string $languageCode, string $source): static
    {
        $replyLanguageResolution = new static();
        $replyLanguageResolution->languageCode = $languageCode;
        $replyLanguageResolution->source = $source;
        return $replyLanguageResolution;
    }
}
