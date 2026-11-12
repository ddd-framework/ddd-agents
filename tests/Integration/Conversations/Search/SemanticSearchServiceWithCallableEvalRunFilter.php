<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Search;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\SemanticSearchService;

/** The search service with its eval-run filter callable from a test. Every statement against the database is the service's. */
class SemanticSearchServiceWithCallableEvalRunFilter extends SemanticSearchService
{
    /**
     * @param AIConversationMessage[] $foundMessages
     * @return AIConversationMessage[]
     */
    public function dropMessagesOfEvalRuns(array $foundMessages): array
    {
        return $this->withoutMessagesOfEvalRuns($foundMessages);
    }
}
