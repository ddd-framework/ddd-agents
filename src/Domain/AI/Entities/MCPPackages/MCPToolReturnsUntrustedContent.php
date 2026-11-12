<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method — or a whole MCPPackage — whose result carries content written by THIRD PARTIES and
 * fetched from outside (web page text, mentions, other parties' posts, comments on them): text an attacker can
 * place an instruction aimed at the agent into. The loop screens every COMPLETED result of such a
 * tool in the background with the decision model ({@see \DDD\Domain\AI\Services\Conversations\AgentToolResultScreeningService});
 * on a hit the stored result is replaced by a removal notice and the step is re-driven. The tool path never waits.
 *
 * Declare it only for content the owner did not write and the platform did not compose. Web search answers and
 * the feedback an application collects from the owner's customers are deliberately NOT marked.
 *
 *   #[MCPTool, MCPToolReturnsUntrustedContent('the raw text of any web page')]
 *   public function getWebPageAsMarkdown(...): ... {}
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPToolReturnsUntrustedContent
{
    public function __construct(public string $reason = '')
    {
    }
}
