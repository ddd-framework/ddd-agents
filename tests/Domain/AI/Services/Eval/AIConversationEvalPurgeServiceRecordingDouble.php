<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSteps;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTree;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversations;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Domain\Base\Repo\DB\Dialect\DialectResolver;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;

/**
 * The evaluation purge without a database: the write and count methods return scripted values and record what they
 * were asked, the reads answer from scripted maps, the clocks are properties, pauses are recorded instead of slept,
 * the server-load guard answers from a counter, the dialect is chosen by the test.
 */
class AIConversationEvalPurgeServiceRecordingDouble extends AIConversationEvalPurgeService
{
    /** @var array<int, int|\Throwable> one entry per executeStepChunk() call; an empty script answers 0 */
    public array $scriptedWrittenRows = [];

    /** @var array<int, array{label: string, ids: int[], rowLimit: int}> */
    public array $executedChunks = [];

    /** @var array<int, array{label: string, ids: int[]}> */
    public array $countedChunks = [];

    /** @var int[] */
    public array $pauses = [];

    /** Whether AgentConnections is switched on (its three tables are then purge steps) */
    public bool $agentConnectionsEnabled = false;

    public float $clockSeconds = 1000.0;

    public float $secondsPerWrite = 0.01;

    /** @var float Seconds every tree check takes on the clock */
    public float $secondsPerTreeCheck = 0.0;

    public int $busyAnswersLeft = 0;

    /** @var int The wall clock the cutoffs are computed from (2026-03-12 12:00:00 UTC) */
    public int $unixTimestamp = 1773316800;

    /** @var int[] the configured evaluation accounts the double answers */
    public array $configuredEvalAccountIds = [7001, 7002];

    /** @var int[] the accounts whose marked trees only may go */
    public array $accountIdsAllowedForEvalOnlyPurge = [];

    /** @var array<int, int[]> the roots each candidate read answers, in order; then none */
    public array $scriptedCandidateBatches = [];

    /** @var array<int, array{accountIds: int[], activityCutoff: string, afterRootConversationId: int}> */
    public array $candidateReads = [];

    /** @var array<int, AIConversationEvalTreeConversations> the conversations read for a root */
    public array $treeConversationsByRootConversationId = [];

    /** @var array<int, string|null> the newest message of a root's tree */
    public array $newestMessageCreatedAtByRootConversationId = [];

    /** @var array<string, int[]> `table.column` → the ids the id read answers */
    public array $scriptedIdsByTableColumn = [];

    /** @var array<int, string|null> root → marker */
    public array $scriptedEvalMarkersByRootConversationId = [];

    /** @var int[] the average row bytes the statistics read answers */
    public array $scriptedAverageRowBytesByTableName = [];

    /** @var string The DBAL driver whose dialect the statements are built for */
    public string $databaseDriver = 'pdo_mysql';

    public function findConfiguredEvalAccountIds(): array
    {
        return $this->configuredEvalAccountIds;
    }

    public function findAccountIdsAllowedForEvalOnlyPurge(): array
    {
        return $this->accountIdsAllowedForEvalOnlyPurge;
    }

    protected function findCandidateRootConversationIds(array $evalAccountIds, string $activityCutoff, int $afterRootConversationId, AIConversationEvalPurgeSettings $settings): array
    {
        $this->candidateReads[] = ['accountIds' => $evalAccountIds, 'activityCutoff' => $activityCutoff, 'afterRootConversationId' => $afterRootConversationId];
        return array_shift($this->scriptedCandidateBatches) ?? [];
    }

    public function findEvalTree(int $rootConversationId, AIConversationEvalPurgeSettings $settings): AIConversationEvalTree
    {
        $this->clockSeconds += $this->secondsPerTreeCheck;
        return AIConversationEvalTree::fromConversations(
            $rootConversationId,
            $this->treeConversationsByRootConversationId[$rootConversationId] ?? AIConversationEvalTreeConversations::newInstance(),
            $this->newestMessageCreatedAtByRootConversationId[$rootConversationId] ?? null
        );
    }

    protected function findEvalMarkersOfRootConversations(array $rootConversationIds): array
    {
        $evalMarkersByRootConversationId = [];
        foreach ($rootConversationIds as $rootConversationId) {
            $evalMarkersByRootConversationId[$rootConversationId] = $this->scriptedEvalMarkersByRootConversationId[$rootConversationId] ?? null;
        }
        return $evalMarkersByRootConversationId;
    }

    public function findIdsWhereColumnIn(string $tableName, string $columnName, array $columnValues, AIConversationEvalPurgeSettings $settings): array
    {
        return $columnValues === [] ? [] : ($this->scriptedIdsByTableColumn["{$tableName}.{$columnName}"] ?? []);
    }

    public function findAverageRowBytesByTableName(AIConversationEvalPurgeSteps $steps): array
    {
        return $this->scriptedAverageRowBytesByTableName;
    }

    protected function executeStepChunk(AIConversationEvalPurgeStep $step, array $idChunk, int $rowLimit): int
    {
        $this->executedChunks[] = ['label' => $step->getReportLabel(), 'ids' => $idChunk, 'rowLimit' => $rowLimit];
        $this->clockSeconds += $this->secondsPerWrite;
        $scriptedAnswer = array_shift($this->scriptedWrittenRows) ?? 0;
        if ($scriptedAnswer instanceof \Throwable) {
            throw $scriptedAnswer;
        }
        return $scriptedAnswer;
    }

    protected function countStepRows(AIConversationEvalPurgeStep $step, array $idChunk): int
    {
        $this->countedChunks[] = ['label' => $step->getReportLabel(), 'ids' => $idChunk];
        return count($idChunk);
    }

    protected function isDatabaseTooBusy(AIConversationEvalPurgeSettings $settings): bool
    {
        if ($this->busyAnswersLeft > 0) {
            $this->busyAnswersLeft--;
            return true;
        }
        return false;
    }

    public function currentSeconds(): float
    {
        return $this->clockSeconds;
    }

    protected function currentUnixTimestamp(): int
    {
        return $this->unixTimestamp;
    }

    protected function pauseMilliseconds(int $pauseMs): void
    {
        $this->pauses[] = $pauseMs;
    }

    protected function getSqlDialect(): SqlDialect
    {
        return DialectResolver::forDriver($this->databaseDriver);
    }

    protected function areAgentConnectionTablesInUse(): bool
    {
        return $this->agentConnectionsEnabled;
    }
}
