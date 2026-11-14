<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method whose result the token budget of the kept turns never replaces by a stub: a read a later
 * draft or answer is composed from (a web search, a page read), which the model would otherwise read again or report
 * as "nothing found" ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::resolveToolResultRetention()}).
 * The exemption covers the budget only: a result outside the kept turns is cleared like every other.
 *
 * {@see MCPToolNewestResultIsNeverStubbed} is the narrower sibling: it keeps only the newest result of a tool, against
 * every clearing rule.
 *
 * Read by tool name through {@see MCPToolAttributeCatalog}, which reads the method of the class an application binds:
 * an application that declares the tool on its own package class repeats the attribute there. Every exempt result
 * stays on the wire for as long as its turn is kept, so mark few tools.
 *
 *   #[MCPTool, MCPToolResultIsNeverStubbedByTheBudget('the answer is composed from this page')]
 *   public function readPage(PageRequestDto $requestDto): PageResponseDto {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolResultIsNeverStubbedByTheBudget
{
    public function __construct(public string $reason = '')
    {
    }
}
