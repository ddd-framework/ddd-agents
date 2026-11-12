<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Maintenance;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What ONE run of the background job that removes the debug payloads from old request snapshots did
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::removeDebugPayloadsFromRequestSnapshots()}):
 * which message ids it walked, how many snapshots carried a payload and were stripped, how often it waited, how long
 * its writes took, and why it stopped. The job counts on this object while it runs.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationRequestSnapshotDebugPayloadRemovalReport extends ValueObject
{
    /** @var string The run walked every window up to the cutoff */
    public const string STOPPED_BECAUSE_REACHED_THE_CUTOFF = 'reached the cutoff';

    /** @var string No message is older than the cutoff */
    public const string STOPPED_BECAUSE_NO_MESSAGE_OLDER_THAN_THE_CUTOFF = 'no message older than the cutoff';

    /** @var string The run used up the seconds it was given */
    public const string STOPPED_BECAUSE_TIME_BUDGET_USED_UP = 'time budget used up';

    /** @var int|null The newest message id older than the cutoff; null when no message is that old */
    public ?int $cutoffMessageId = null;

    /** @var int The message id the run started at */
    public int $fromMessageId = 0;

    /** @var int|null The last message id of the last window the run scanned; null when it scanned none */
    public ?int $lastScannedMessageId = null;

    /** @var int How many windows of message ids the run scanned */
    public int $windowsScanned = 0;

    /** @var int How many scanned snapshots carried a debug payload */
    public int $rowsWithPayload = 0;

    /** @var int How many snapshots the run stripped */
    public int $rowsStripped = 0;

    /** @var int How many chunks the run skipped because a row of the chunk was locked */
    public int $chunksSkippedOnLock = 0;

    /** @var int How often the run waited because the database server was busy */
    public int $pausesForServerLoad = 0;

    /** @var int How many write statements the run sent */
    public int $writes = 0;

    /** @var int|null The average duration of a write in milliseconds; null when the run wrote nothing */
    public ?int $averageWriteMs = null;

    /** @var int|null The duration of the slowest write in milliseconds; null when the run wrote nothing */
    public ?int $slowestWriteMs = null;

    /** @var string Why the run stopped (one of the STOPPED_BECAUSE_* texts) */
    public string $stoppedBecause = self::STOPPED_BECAUSE_REACHED_THE_CUTOFF;

    /**
     * @param int|null $cutoffMessageId
     * @param int $fromMessageId
     * @return static
     */
    public static function forCutoffAndStartMessageId(?int $cutoffMessageId, int $fromMessageId): static
    {
        $debugPayloadRemovalReport = new static();
        $debugPayloadRemovalReport->cutoffMessageId = $cutoffMessageId;
        $debugPayloadRemovalReport->fromMessageId = $fromMessageId;
        return $debugPayloadRemovalReport;
    }

    /**
     * @param int $writeMs The duration of one write statement in milliseconds
     * @param int $totalWriteMs The duration of all writes of the run so far, this one included
     * @return void
     */
    public function addWrite(int $writeMs, int $totalWriteMs): void
    {
        $this->writes++;
        $this->averageWriteMs = intdiv($totalWriteMs, $this->writes);
        $this->slowestWriteMs = max($this->slowestWriteMs ?? 0, $writeMs);
    }

    /**
     * @return array<string, int|string|null> The counters by their name, in the order a report table prints them
     */
    public function toReportFields(): array
    {
        return [
            'cutoffMessageId' => $this->cutoffMessageId,
            'fromMessageId' => $this->fromMessageId,
            'lastScannedMessageId' => $this->lastScannedMessageId,
            'windowsScanned' => $this->windowsScanned,
            'rowsWithPayload' => $this->rowsWithPayload,
            'rowsStripped' => $this->rowsStripped,
            'chunksSkippedOnLock' => $this->chunksSkippedOnLock,
            'pausesForServerLoad' => $this->pausesForServerLoad,
            'writes' => $this->writes,
            'averageWriteMs' => $this->averageWriteMs,
            'slowestWriteMs' => $this->slowestWriteMs,
            'stoppedBecause' => $this->stoppedBecause,
        ];
    }
}
