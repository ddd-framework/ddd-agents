<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Quality;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The quality scorecard of a finalized conversation — measurement, assessment and quality analysis — asked by the
 * scorecard worker ({@see \DDD\Domain\AI\MessageHandlers\AIConversationScorecardHandler}) after the completion
 * compaction. The quality component (metrics, assessments, defects, wishes, capability tree) belongs to the application;
 * the core keeps only the worker, its transport and the erased-scope guard.
 *
 * The default writes nothing. An application with a quality component puts a same-path subclass under its own root
 * namespace; the core resolves this service only through
 * `DDDService::instance()->getService(AgentConversationScorecardsService::class)`.
 */
class AgentConversationScorecardsService extends Service
{
    /**
     * Writes the scorecard of the finalized conversation `$conversation` and runs its quality analysis
     * (`$forceAnalysis` = analyse again even when the analysis already covers the newest message). The default has no
     * quality component and writes nothing.
     *
     * @param AIConversation $conversation
     * @param bool $forceAnalysis
     * @return string|null the analysis outcome for the worker's trace line; null when nothing was written
     * @throws Throwable the worker logs a failure, it never rethrows
     */
    public function writeScorecardOfFinalizedConversation(AIConversation $conversation, bool $forceAnalysis): ?string
    {
        return null;
    }
}
