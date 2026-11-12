<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks a tool whose NEWEST result always rides whole on the wire: the result-clearing stub never replaces it, because a
 * later write needs values only that result holds (a search whose result holds the ids the following write
 * takes; its stub would leave the model only a label to guess an id from). Older calls of the same tool are stubbed
 * as usual ({@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation}).
 *
 *   #[MCPTool]
 *   #[MCPToolNewestResultIsNeverStubbed('the booking write takes the slot ids of this result')]
 *   public function searchFreeSlots(...): ... {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolNewestResultIsNeverStubbed
{
    public function __construct(public string $reason = '')
    {
    }
}
