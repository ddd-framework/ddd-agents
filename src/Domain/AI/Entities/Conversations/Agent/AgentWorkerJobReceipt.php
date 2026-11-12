<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * When a worker RECEIVED one resume job — taken by the resume handler before
 * anything is loaded, so the turn-start stamps measure everything between the owner's message and the first model call
 * that no other stamp covers (e.g. why pickup + load take 2 s). The handler builds it and
 * passes it into {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::resumeConversationIfReady()},
 * which hands it to the run ({@see AgentRun::$workerJobReceipt}). The handler also keeps it as the LAST receipt of the
 * process ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::$lastWorkerJobReceipt}).
 *
 * @phpstan-consistent-constructor
 */
class AgentWorkerJobReceipt extends ValueObject
{
    /** @var float The hrtime (nanoseconds) the job was received at — the origin of the first turn-start phase. */
    public float $receivedHr;

    /** @var float The wall clock (microtime) the job was received at. */
    public float $receivedWallUs;

    /**
     * @var float|null The dispatching process's wall clock right before the transport send (null when the message
     *      carried none).
     *
     *      Unit: a Unix time in SECONDS with fractions, as `microtime(true)` returns it. The name ends in `Us`
     *      although the value does not hold microseconds; the name stays because it is a field of a queued message.
     */
    public ?float $dispatchedAtWallUs = null;

    /**
     * @var float|null The API process's wall clock right after the owner's row was saved (null when the dispatch
     *      saved none).
     *
     *      Unit: a Unix time in SECONDS with fractions, as `microtime(true)` returns it. The name ends in `Us`
     *      although the value does not hold microseconds; the name stays because it is a field of a queued message.
     */
    public ?float $ownerRowSavedWallUs = null;

    /**
     * The receipt of a job received NOW, with the dispatch and owner-row stamps its message carried.
     *
     * Both parameters are Unix times in SECONDS with fractions, as `microtime(true)` returns them. Their names end in
     * `Us` although they do not hold microseconds; the names stay because they are fields of a queued message.
     *
     * @param float|null $dispatchedAtWallUs The dispatching process's wall clock right before the transport send, in
     *      seconds with fractions; null when the message carried none
     * @param float|null $ownerRowSavedWallUs The API process's wall clock right after the owner's row was saved, in
     *      seconds with fractions; null when the dispatch saved none
     * @return static
     */
    public static function receivedNow(?float $dispatchedAtWallUs, ?float $ownerRowSavedWallUs = null): static
    {
        $agentWorkerJobReceipt = new static();
        $agentWorkerJobReceipt->receivedHr = hrtime(true);
        $agentWorkerJobReceipt->receivedWallUs = microtime(true);
        $agentWorkerJobReceipt->dispatchedAtWallUs = $dispatchedAtWallUs;
        $agentWorkerJobReceipt->ownerRowSavedWallUs = $ownerRowSavedWallUs;
        return $agentWorkerJobReceipt;
    }
}
