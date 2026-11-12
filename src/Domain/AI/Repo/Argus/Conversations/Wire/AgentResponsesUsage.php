<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Wire;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The token usage ONE Responses answer reports: input, output, cached input, written cache and reasoning tokens. Read
 * from the answer's `usage` block by {@see AgentResponsesResponse::getUsage()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentResponsesUsage extends ValueObject
{
    /** @var int The input tokens (`usage.input_tokens`) */
    public int $inputTokens = 0;

    /** @var int The output tokens (`usage.output_tokens`) */
    public int $outputTokens = 0;

    /** @var int The input tokens read from the prompt cache (`usage.input_tokens_details.cached_tokens`) */
    public int $cachedInputTokens = 0;

    /** @var int The input tokens written to the prompt cache (`usage.input_tokens_details.cache_write_tokens`) */
    public int $cacheWriteTokens = 0;

    /** @var int The reasoning tokens (`usage.output_tokens_details.reasoning_tokens`) */
    public int $reasoningTokens = 0;

    /**
     * @param object $usageBlock The answer's `usage` block
     * @return static
     */
    public static function fromUsageBlock(object $usageBlock): static
    {
        $agentResponsesUsage = new static();
        $agentResponsesUsage->inputTokens = (int)($usageBlock->input_tokens ?? 0);
        $agentResponsesUsage->outputTokens = (int)($usageBlock->output_tokens ?? 0);
        $agentResponsesUsage->cachedInputTokens = (int)($usageBlock->input_tokens_details->cached_tokens ?? 0);
        $agentResponsesUsage->cacheWriteTokens = (int)($usageBlock->input_tokens_details->cache_write_tokens ?? 0);
        $agentResponsesUsage->reasoningTokens = (int)($usageBlock->output_tokens_details->reasoning_tokens ?? 0);
        return $agentResponsesUsage;
    }
}
