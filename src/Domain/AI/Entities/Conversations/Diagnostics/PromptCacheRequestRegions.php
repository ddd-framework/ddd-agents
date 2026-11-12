<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The three cache regions of ONE sent model request: the tools array (as JSON), the system content and the wire
 * messages after it, each in the form the prompt-cache diff compares. {@see PromptCacheDiffReport} builds one per call
 * that stored its debug payload.
 *
 * @phpstan-consistent-constructor
 */
class PromptCacheRequestRegions extends ValueObject
{
    /** @var string The request's tools array as JSON */
    public string $tools = '';

    /** @var string The system content as text (JSON when it is not text only) */
    public string $system = '';

    /** @var array[] The wire messages after the system content, each as sent with its content reduced to text where it is text only */
    public array $messages = [];

    /**
     * @param string $tools
     * @param string $system
     * @param array[] $messages
     * @return static
     */
    public static function forToolsSystemAndMessages(string $tools, string $system, array $messages): static
    {
        $promptCacheRequestRegions = new static();
        $promptCacheRequestRegions->tools = $tools;
        $promptCacheRequestRegions->system = $system;
        $promptCacheRequestRegions->messages = array_values($messages);
        return $promptCacheRequestRegions;
    }
}
