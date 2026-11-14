<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;

/**
 * One recording or replay of a recorded run, as a harness drives it: a console chat over several owner turns, or a
 * judged evaluation run. It holds the state of the recording across the calls of one run (the recorders it installs
 * in the process, the queue of the messages the run dispatches, the high-water marks of the tables, the scope of the
 * run), so it is neither a value object nor a container service; a harness gets one per run and drives it through
 * these calls only.
 *
 * Order of a run: {@see setScopeIds()} and, once the root conversation exists, {@see setRootConversation()};
 * {@see start()}; per owner turn {@see beginTurn()}, then {@see runQueuedMessages()}; at the end either
 * {@see concludeRun()} (it stops the session and builds the recording or the comparison) or {@see stop()} followed by
 * {@see buildRecording()} / {@see compareWithRecording()}. {@see stop()} belongs in a `finally`: a run that fails must
 * leave no recorder installed.
 */
interface AgentRecordedRunSessionInterface
{
    /**
     * Whether this session replays a recording (true) or records a run (false).
     *
     * @return bool
     */
    public function isReplay(): bool;

    /**
     * Reads the high-water mark of every table, once. A harness that creates the run's own scope before the run
     * starts calls it before that creation, so the rows created with the scope count as created by the run.
     *
     * @return void
     */
    public function readHighWaterMarks(): void;

    /**
     * Adds scope ids the created ids of the run are read in, and whose ids read as placeholders, by scope name.
     * Called before {@see start()}.
     *
     * @param array<string, int|string|null> $scopeIdsByName
     * @return void
     */
    public function setScopeIds(array $scopeIdsByName): void;

    /**
     * Starts owner turn `$turnOrdinal`: every call made from now on, and by every message dispatched now, is recorded
     * under it and answered only from the recording's calls of the same turn.
     *
     * @param int $turnOrdinal
     * @return void
     */
    public function beginTurn(int $turnOrdinal): void;

    /**
     * The root conversation of the run, set by the harness right after it created it.
     *
     * @param AIConversation $rootConversation
     * @return void
     */
    public function setRootConversation(AIConversation $rootConversation): void;

    /**
     * The tables whose created ids were read through a scope the run shares with other runs, as one line to print;
     * null when there is none.
     *
     * @return string|null
     */
    public function describeSharedScopeTables(): ?string;

    /**
     * Reads the high-water marks (unless read already) and installs the recorders of this session in the process.
     *
     * @return void
     */
    public function start(): void;

    /**
     * Runs every message the run has dispatched so far, in dispatch order, in this process.
     *
     * @return int how many messages ran
     */
    public function runQueuedMessages(): int;

    /**
     * Removes the recorders of this session from the process, after running what is still queued unless the run is
     * abandoned (`$runRemainingMessages` false: an exception unwinds, and a queued message must neither run nor mask
     * it).
     *
     * @param bool $runRemainingMessages
     * @return void
     */
    public function stop(bool $runRemainingMessages = true): void;

    /**
     * Ends the run where the harness still holds its scope: runs what is still queued, stops the session, then builds
     * the recording (record mode) or the comparison (replay mode) and keeps it on the session.
     *
     * @param AIConversation $rootConversation
     * @param array<string, int|string|null> $scopeIdsByName the scope ids the run created for itself
     * @return void
     */
    public function concludeRun(AIConversation $rootConversation, array $scopeIdsByName): void;

    /**
     * The recording of this run: the harness's own fields, the recorded calls and tool results, the id normalization
     * and the normalized snapshot of the conversation tree and the dispatched messages.
     *
     * @param AIConversation $rootConversation
     * @param array<string, int|string|null> $scopeIdsByName
     * @param array<string, mixed> $runFields the harness's own fields (turns, case, models)
     * @return array<string, mixed>
     */
    public function buildRecording(AIConversation $rootConversation, array $scopeIdsByName, array $runFields): array;

    /**
     * The replay compared with its recording.
     *
     * @param AIConversation $rootConversation
     * @param array<string, int|string|null> $scopeIdsByName the scope ids this replay created for itself
     * @return AgentRecordedRunComparison
     */
    public function compareWithRecording(AIConversation $rootConversation, array $scopeIdsByName): AgentRecordedRunComparison;

    /**
     * The one-line summary a harness prints of a run snapshot: model calls, rows per section, dispatched messages.
     *
     * @param array<string, mixed> $snapshot
     * @return string
     */
    public function describeRun(array $snapshot): string;
}
