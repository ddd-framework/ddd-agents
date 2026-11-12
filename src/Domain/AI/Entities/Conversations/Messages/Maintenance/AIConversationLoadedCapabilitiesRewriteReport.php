<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Maintenance;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What ONE run of the background job that rewrites the stored loaded-capability sets did over all conversations it
 * reached ({@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::compactLoadedCapabilities()}).
 * The job adds the outcome of every conversation to this object while it runs.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationLoadedCapabilitiesRewriteReport extends ValueObject
{
    /** @var string The run reached the last conversation */
    public const string STOPPED_BECAUSE_NO_CONVERSATION_LEFT = 'no conversation left';

    /** @var string The run used up the seconds it was given */
    public const string STOPPED_BECAUSE_TIME_BUDGET_USED_UP = 'time budget used up';

    /** @var int The conversation id the run started after */
    public int $fromConversationId = 0;

    /** @var int|null The last conversation the run read; null when it read none */
    public ?int $lastConversationId = null;

    /** @var int How many conversations had at least one row rewritten */
    public int $conversationsCompacted = 0;

    /** @var int How many conversations were left alone because they are in use */
    public int $conversationsSkippedActive = 0;

    /** @var int How many conversations were given up after a second conflict with another writer */
    public int $conversationsSkippedConflict = 0;

    /** @var int How many message rows were rewritten (in a dry run: would be rewritten) */
    public int $rowsRewritten = 0;

    /** @var int The bytes the rewritten rows' sets held before */
    public int $bytesBefore = 0;

    /** @var int The bytes the rewritten rows' sets hold afterwards */
    public int $bytesAfter = 0;

    /** @var int How often the run waited because the database server was busy */
    public int $pausesForServerLoad = 0;

    /** @var int How many rows were read back and compared with the set they stored before */
    public int $verifiedRows = 0;

    /** @var int How many of the compared rows resolve to another set than before */
    public int $verifyMismatches = 0;

    /** @var string Why the run stopped (one of the STOPPED_BECAUSE_* texts) */
    public string $stoppedBecause = self::STOPPED_BECAUSE_NO_CONVERSATION_LEFT;

    /**
     * @param int $fromConversationId
     * @return static
     */
    public static function forStartConversationId(int $fromConversationId): static
    {
        $loadedCapabilitiesRewriteReport = new static();
        $loadedCapabilitiesRewriteReport->fromConversationId = $fromConversationId;
        return $loadedCapabilitiesRewriteReport;
    }

    /**
     * Counts one conversation the run read.
     *
     * @param int $conversationId
     * @param AIConversationLoadedCapabilitiesRewriteOutcome $rewriteOutcome
     * @return void
     */
    public function addOutcomeOfConversation(int $conversationId, AIConversationLoadedCapabilitiesRewriteOutcome $rewriteOutcome): void
    {
        $this->verifiedRows += $rewriteOutcome->verifiedRows;
        $this->verifyMismatches += $rewriteOutcome->verifyMismatches;
        $this->lastConversationId = $conversationId;
        if ($rewriteOutcome->isActive()) {
            $this->conversationsSkippedActive++;
        } elseif ($rewriteOutcome->isConflict()) {
            $this->conversationsSkippedConflict++;
        } elseif ($rewriteOutcome->rowsRewritten > 0) {
            $this->conversationsCompacted++;
        }
        $this->rowsRewritten += $rewriteOutcome->rowsRewritten;
        $this->bytesBefore += $rewriteOutcome->bytesBefore;
        $this->bytesAfter += $rewriteOutcome->bytesAfter;
    }

    /** @return float The megabytes the rewritten rows hold less than before, rounded to one decimal */
    public function getSavedMegabytes(): float
    {
        return round(($this->bytesBefore - $this->bytesAfter) / 1048576, 1);
    }

    /**
     * @return array<string, int|string|null> The counters by their name, in the order a report table prints them
     */
    public function toReportFields(): array
    {
        return [
            'fromConversationId' => $this->fromConversationId,
            'lastConversationId' => $this->lastConversationId,
            'conversationsCompacted' => $this->conversationsCompacted,
            'conversationsSkippedActive' => $this->conversationsSkippedActive,
            'conversationsSkippedConflict' => $this->conversationsSkippedConflict,
            'rowsRewritten' => $this->rowsRewritten,
            'bytesBefore' => $this->bytesBefore,
            'bytesAfter' => $this->bytesAfter,
            'pausesForServerLoad' => $this->pausesForServerLoad,
            'verifiedRows' => $this->verifiedRows,
            'verifyMismatches' => $this->verifyMismatches,
            'stoppedBecause' => $this->stoppedBecause,
        ];
    }
}
