# Record and replay

**Reader:** host developer and package maintainer

Contents: what it proves · the three seams · what a recording holds · the pieces of a harness · a complete harness · reading a comparison · limits · for the package maintainer · full class names

Record a run of the agent on one build, replay it on the next, and compare: the rows written, the messages
dispatched and the requests sent to the model must be the same after normalization. It is the identity proof of a
refactor of the loop. The package ships the seams and the comparison; the harness — a console command that records
or replays one run — is the host's.

## What it proves

Equal means: the same conversation tree (root, sub-agents, their messages, the delegation batches, the compaction
nodes), the same Messenger messages, and every request the loop sent to Argus (model calls, decision calls) the same
body as the recorded call that answered it. A replay never calls a model, never runs a tool, never reads the wall
clock or the random generator at the switched sites. A difference is a change of behaviour — or a non-determinism
the normalization does not cover yet.

## The three seams

| Seam | Where | Record | Replay |
|---|---|---|---|
| Argus calls | `DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations::$transportRecording`, holding a `DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording` (ddd-argus) | `ArgusTransportRecording::forRecording()`: every Argus call goes to the network and is kept (endpoint, body, response, its kind, its turn, whether it ran inside a tool) | `ArgusTransportRecording::forPlaying($entries)`: every call is answered from the recording — same turn and kind, the identical body first, else the oldest unused one; a call with no answer left fails |
| Tool results | `DDD\Presentation\Mcp\Services\MCPToolExecutorService::$toolResultRecording`, holding a `DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording` | `ToolResultRecording::forRecording()`: each tool result with its arguments and the AI costs the tool booked | `ToolResultRecording::forPlaying($entries)`: the tool does not run; the same tool with the same arguments first, else the oldest unused result of that tool; its recorded costs are booked again |
| Clock and ids | `DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources` (a static library, not a service), with the sources in `DDD\Domain\AI\Entities\RecordedRuns` | `runWithPinnedSources($nowSource, $idSource, $work)` with `RecordedRunNowSource::pinnedAt()` and `RecordedRunIdSource::seeded()` | the same moment and seed, so the n-th id and every "now" read through it are the same bytes |

While a tool runs, the executor marks the Argus recording `insideToolExecution`: a recorded in-tool call is not
expected from a replay that plays the tool's result. `ArgusTransportRecording::forPlayingWithLiveTools()` answers
every call, in-tool calls from the in-tool recordings, for a comparison whose tools run live;
`forPlayingToolCalls()` answers only in-tool calls. Which sites read "now" or mint ids through
`RecordedRunClockAndIdSources` is the host's choice (the package's delegation ids do).

**Record in one console process.** A job a worker consumes resets the recorders before it runs:
`ArgusApiOperations::resetForNewJob()` drops `$transportRecording`, `AgentWorkerStateResetter` drops
`MCPToolExecutorService::$toolResultRecording` and the two harness switches below. A recording made in a worker
process therefore loses its recorders when the next job starts. The pinned clock and id sources are not reset there;
`runWithPinnedSources()` puts them back itself, on return and on a throw. A `sync://` dispatch runs inside the
console process without the reset, so run the turn transport on `sync://`.

Two switches belong to the harness: `AgentDecisionModelCircuitBreakerService::$harnessMode` (true: the decision
model's circuit breaker never opens and never reads or writes its shared key, so a breaker opened by another process
does not change the replay) and `AgentToolCallForkJoinService::$forkingDisabled` (true: no tool call runs in a child
process, whose Argus calls would bypass the recording and reach the network). Set both for a record and a replay.

## What a recording holds

The host stores one JSON file per run, with:

- the Argus entries (`ArgusTransportRecording::toStorageArray()`) and the tool results
  (`ToolResultRecording::toStoredEntries()`);
- the owner texts, the scope ids by name, the pinned moment and the id seed;
- the record-time snapshot (tree rows and dispatched messages, normalized) and the run's id normalization
  (`AgentRecordedRunIdNormalization::toArray()`: the high-water marks, the created ids, the scope ids).

## The pieces of a harness

| Piece | What the host does |
|---|---|
| The recorder instances | `ArgusApiOperations::$transportRecording` and `MCPToolExecutorService::$toolResultRecording` are static slots; the harness creates the instances (`ArgusTransportRecording::forRecording()`, `forPlaying()`, …), keeps a reference and installs them. `turnOrdinal`, `idCounterparts`, `playedAnswerTranslator`, `playedRequests` are **instance** properties of that `ArgusTransportRecording`: `$argusTransportRecording->turnOrdinal = 2` |
| High-water marks | `AgentRecordedRunIdNormalization::withHighWaterMarks($highWaterMarksByTable, $tableByFamily)`. The five families are the constants `FAMILY_CONVERSATION`, `FAMILY_MESSAGE`, `FAMILY_DELEGATION_BATCH`, `FAMILY_COMPACTION_NODE`, `FAMILY_SCHEDULE`; `$tableByFamily` maps each to its table name, read from the generated model (`DBAIConversationModel::getTableName()`, `DBAIConversationMessageModel`, `DBAIConversationDelegationBatchModel`, `DBAIConversationCompactionNodeModel`, `DBAIConversationScheduleModel`). `$highWaterMarksByTable` holds the highest `id` of each table at the run start, keyed by that table name; a table the host's tools write may be added under its own name. An id of a table without a mark is never normalized |
| Reading ids | the framework's connection: `EntityManagerFactory::getInstance()->getConnection()` (a Doctrine DBAL connection; `fetchOne()`, `fetchFirstColumn()`) — the package itself reads the same way |
| Created ids | after the run, per table every `id` above its mark, ascending, as `[table => int[]]` into `addMintedIds()` (only ids above the mark are kept) |
| Scope ids | `addScopeIds(['agentSpace' => $id, …])` with the ids of the rows the harness created before the run; on replay the replay's and the recording's ids both, so both read as `<agentSpace>` |
| Dispatched messages | the package ships no collector. A Messenger middleware of the host, first in the bus, appends one `AgentRecordedRunDispatchedMessage::forMessageClassAndBody($class, $body)` per dispatched (not received) message and sets its `outcome` after the handler ran; with the transports on `sync://` the handler runs inside the dispatch |
| Turns | before each owner turn `$argusTransportRecording->turnOrdinal = <turn number>`, after it null |
| Comparison | `AgentRecordedRunComparison::buildComparison()` (below) |

`buildComparison()` takes seven arguments:

| # | Argument | What |
|---|---|---|
| 1 | `$expectedSnapshot` | the recording's snapshot: `buildTreeSnapshot($treeRows, $idNormalization)` plus `[SECTION_DISPATCHES => buildDispatchSnapshot($dispatchedMessages, $idNormalization)]`, built at record time and stored |
| 2 | `$actualSnapshot` | the same for the replay |
| 3 | `$expectedRequests` | per recorded position that a replay asks for (in-tool calls left out when tool results are played), `AgentRecordedRunArgusRequest::forEndpointAndRequestBody($entry['endpoint'], $entry['requestBody'])` |
| 4 | `$actualRequests` | `AgentRecordedRunComparison::buildPlayedArgusRequests($argusPlayer->playedRequests)` |
| 5 | `$expectedIdNormalization` | `AgentRecordedRunIdNormalization::fromArray()` of the stored state, plus the replay's scope ids |
| 6 | `$actualIdNormalization` | the replay's own normalization |
| 7 | `$unusedRecordedCalls` | `$argusPlayer->unusedEntries()` as it is (int positions), plus each `ToolResultRecording::unusedEntries()` entry under a string key (`'tool result #3'`), so neither list overwrites the other |

Afterwards `isIdentical()`, `$differences` (at most `MAX_REPORTED_DIFFERENCES`) and `$notes`. A harness that reads
created ids per scope column reports it with `addCreatedIdReadReport()` (`AgentRecordedRunCreatedIdReadReport`); a
replay whose tools run live translates the ids the tools minted with `$playedAnswerTranslator` and
`AgentRecordedRunPlayedAnswerIdTranslation`, reported with `addPlayedAnswerIdTranslationReport()` — the listing below
plays the tool results, so it needs neither.

## A complete harness

`runWithPinnedSources()` takes a `callable` and calls it from its own class: pass an array callable of a **public**
method. A protected or private method is not callable from there and fails with a TypeError. The host's command
subclasses the harness, implements its abstract turn method (one synchronous turn of the root conversation inside a turn
envelope, see console-chat-on-the-printer) and stores the array `record()` returns as JSON.

```php
<?php

declare(strict_types=1);

namespace App\Symfony\Commands\AI\RecordedRuns;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunArgusRequest;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunDispatchedMessage;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording;
use DDD\Domain\AI\Entities\RecordedRuns\RecordedRunIdSource;
use DDD\Domain\AI\Entities\RecordedRuns\RecordedRunNowSource;
use DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use DDD\Domain\Base\Repo\Argus\Utils\RecordedRunIdCounterparts;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;

abstract class AgentRecordedRunHarness
{
    /** The harness of this moment, read by the dispatch capture middleware; null in every normal run. */
    public static ?self $activeHarness = null;

    public ?ArgusTransportRecording $argusTransportRecording = null;

    public ?ToolResultRecording $toolResultRecording = null;

    /** @var AgentRecordedRunDispatchedMessage[] filled by the dispatch capture middleware */
    public array $dispatchedMessages = [];

    /** @var string[] */
    public array $ownerTexts = [];

    public ?AIConversation $rootConversation = null;

    /** One synchronous owner turn of the run's root conversation; returns that conversation. */
    abstract protected function driveOwnerTurn(string $ownerText): AIConversation;

    /** @return array<string, string> id family => table */
    public static function buildTableByFamily(): array
    {
        return [
            AgentRecordedRunIdNormalization::FAMILY_CONVERSATION => DBAIConversationModel::getTableName(),
            AgentRecordedRunIdNormalization::FAMILY_MESSAGE => DBAIConversationMessageModel::getTableName(),
            AgentRecordedRunIdNormalization::FAMILY_DELEGATION_BATCH => DBAIConversationDelegationBatchModel::getTableName(),
            AgentRecordedRunIdNormalization::FAMILY_COMPACTION_NODE => DBAIConversationCompactionNodeModel::getTableName(),
            AgentRecordedRunIdNormalization::FAMILY_SCHEDULE => DBAIConversationScheduleModel::getTableName(),
        ];
    }

    /** Public: RecordedRunClockAndIdSources calls it from outside this class. */
    public function driveRecordedTurns(): void
    {
        foreach (array_values($this->ownerTexts) as $turnIndex => $ownerText) {
            $this->argusTransportRecording->turnOrdinal = $turnIndex + 1;
            try {
                $this->rootConversation = $this->driveOwnerTurn($ownerText);
            } finally {
                $this->argusTransportRecording->turnOrdinal = null;
            }
        }
    }

    /**
     * @param string[] $ownerTexts
     * @param array<string, int> $scopeIdsByName the rows the harness created for the run, e.g. ['agentSpace' => 4711]
     * @return array<string, mixed> the recording, stored as JSON by the caller
     */
    public function record(array $ownerTexts, array $scopeIdsByName, DateTime $pinnedMoment, string $idSeed): array
    {
        $idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks($this->readHighWaterMarks(), self::buildTableByFamily());
        $idNormalization->addScopeIds($scopeIdsByName);
        $this->ownerTexts = $ownerTexts;
        $this->runWithRecorders(ArgusTransportRecording::forRecording(), ToolResultRecording::forRecording(), $pinnedMoment, $idSeed);
        $snapshot = $this->buildSnapshot($idNormalization);
        return [
            'ownerTexts' => $ownerTexts,
            'scopeIds' => $scopeIdsByName,
            'pinnedMoment' => $pinnedMoment->format(DateTime::SIMPLE),
            'idSeed' => $idSeed,
            'calls' => $this->argusTransportRecording->toStorageArray(),
            'toolResults' => $this->toolResultRecording->toStoredEntries(),
            'snapshot' => $snapshot,
            'idNormalization' => $idNormalization->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $recording what record() returned, read back from its JSON file
     * @param array<string, int> $scopeIdsByName the replay's own scope rows, under the recording's names
     */
    public function replayAndCompare(array $recording, array $scopeIdsByName): AgentRecordedRunComparison
    {
        $recordedScopeIdsByName = (array)$recording['scopeIds'];
        $idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks($this->readHighWaterMarks(), self::buildTableByFamily());
        $idNormalization->addScopeIds($scopeIdsByName);
        $idNormalization->addScopeIds($recordedScopeIdsByName);
        $argusPlayer = ArgusTransportRecording::forPlaying((array)$recording['calls']);
        $idCounterparts = new RecordedRunIdCounterparts();
        foreach ($scopeIdsByName as $scopeName => $replayScopeId) {
            if (isset($recordedScopeIdsByName[$scopeName])) {
                $idCounterparts->addCounterpart($replayScopeId, $recordedScopeIdsByName[$scopeName]);
            }
        }
        $argusPlayer->idCounterparts = $idCounterparts;
        $this->ownerTexts = (array)$recording['ownerTexts'];
        $this->runWithRecorders(
            $argusPlayer,
            ToolResultRecording::forPlaying((array)$recording['toolResults']),
            new DateTime((string)$recording['pinnedMoment']),
            (string)$recording['idSeed']
        );
        $actualSnapshot = $this->buildSnapshot($idNormalization);

        $expectedIdNormalization = AgentRecordedRunIdNormalization::fromArray((array)$recording['idNormalization']);
        $expectedIdNormalization->addScopeIds($scopeIdsByName);
        $expectedRequests = [];
        foreach ((array)$recording['calls'] as $position => $recordedCall) {
            if (!($recordedCall['duringTool'] ?? false)) {
                $expectedRequests[$position] = AgentRecordedRunArgusRequest::forEndpointAndRequestBody((string)$recordedCall['endpoint'], (string)$recordedCall['requestBody']);
            }
        }
        $unusedRecordedCalls = $argusPlayer->unusedEntries();
        foreach ($this->toolResultRecording->unusedEntries() as $position => $toolName) {
            $unusedRecordedCalls['tool result #' . $position] = $toolName;
        }
        return AgentRecordedRunComparison::buildComparison(
            (array)$recording['snapshot'],
            $actualSnapshot,
            $expectedRequests,
            AgentRecordedRunComparison::buildPlayedArgusRequests($argusPlayer->playedRequests),
            $expectedIdNormalization,
            $idNormalization,
            $unusedRecordedCalls
        );
    }

    protected function runWithRecorders(ArgusTransportRecording $argusTransportRecording, ToolResultRecording $toolResultRecording, DateTime $pinnedMoment, string $idSeed): void
    {
        $this->argusTransportRecording = $argusTransportRecording;
        $this->toolResultRecording = $toolResultRecording;
        $this->dispatchedMessages = [];
        ArgusApiOperations::$transportRecording = $argusTransportRecording;
        MCPToolExecutorService::$toolResultRecording = $toolResultRecording;
        AgentDecisionModelCircuitBreakerService::$harnessMode = true;
        AgentToolCallForkJoinService::$forkingDisabled = true;
        self::$activeHarness = $this;
        try {
            RecordedRunClockAndIdSources::runWithPinnedSources(
                RecordedRunNowSource::pinnedAt($pinnedMoment),
                RecordedRunIdSource::seeded($idSeed),
                [$this, 'driveRecordedTurns']
            );
        } finally {
            ArgusApiOperations::$transportRecording = null;
            MCPToolExecutorService::$toolResultRecording = null;
            AgentDecisionModelCircuitBreakerService::$harnessMode = false;
            AgentToolCallForkJoinService::$forkingDisabled = false;
            self::$activeHarness = null;
        }
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    protected function buildSnapshot(AgentRecordedRunIdNormalization $idNormalization): array
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $treeRows = $aIConversationsService->findTreeRowsForRecordedRunComparison($this->rootConversation);
        $idNormalization->addMintedIds($this->readCreatedIds($idNormalization->highWaterMarksByTable));
        $snapshot = AgentRecordedRunComparison::buildTreeSnapshot($treeRows, $idNormalization);
        $snapshot[AgentRecordedRunComparison::SECTION_DISPATCHES] = AgentRecordedRunComparison::buildDispatchSnapshot($this->dispatchedMessages, $idNormalization);
        return $snapshot;
    }

    /** @return array<string, int> table => highest id now */
    protected function readHighWaterMarks(): array
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $highWaterMarksByTable = [];
        foreach (self::buildTableByFamily() as $table) {
            $highWaterMarksByTable[$table] = (int)$connection->fetchOne('SELECT COALESCE(MAX(`id`), 0) FROM `' . $table . '`');
        }
        return $highWaterMarksByTable;
    }

    /**
     * @param array<string, int> $highWaterMarksByTable
     * @return array<string, int[]> table => the ids above its mark, ascending
     */
    protected function readCreatedIds(array $highWaterMarksByTable): array
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $createdIdsByTable = [];
        foreach ($highWaterMarksByTable as $table => $highWaterMark) {
            $createdIdsByTable[$table] = array_map('intval', $connection->fetchFirstColumn(
                'SELECT `id` FROM `' . $table . '` WHERE `id` > :highWaterMark ORDER BY `id`',
                ['highWaterMark' => $highWaterMark]
            ));
        }
        return $createdIdsByTable;
    }
}
```

The dispatch capture middleware, listed first in the middleware of the host's message bus (the messenger
configuration of the host):

```php
<?php

declare(strict_types=1);

namespace App\Symfony\Messenger\Middleware;

use App\Symfony\Commands\AI\RecordedRuns\AgentRecordedRunHarness;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunDispatchedMessage;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Throwable;

class AgentRecordedRunDispatchCaptureMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $harness = AgentRecordedRunHarness::$activeHarness;
        if ($harness === null || $envelope->last(ReceivedStamp::class) !== null) {
            return $stack->next()->handle($envelope, $stack);
        }
        $message = $envelope->getMessage();
        // the body a transport would carry; hide attributes ignored, so earlier tool output cannot change it
        $body = $message instanceof AppMessage
            ? (string)$message->toJSON(true)
            : (string)json_encode(get_object_vars($message), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $dispatchedMessage = AgentRecordedRunDispatchedMessage::forMessageClassAndBody($message::class, $body);
        $harness->dispatchedMessages[] = $dispatchedMessage;
        try {
            $handledEnvelope = $stack->next()->handle($envelope, $stack);
            $dispatchedMessage->outcome = 'handled';
            return $handledEnvelope;
        } catch (Throwable $dispatchError) {
            $dispatchedMessage->outcome = 'failed: ' . $dispatchError::class;
            throw $dispatchError;
        }
    }
}
```

The listing reads created ids unscoped: rows another process writes into the same tables during the run count as
created. Run it on a database nothing else writes to, or scope the read (and report it with
`addCreatedIdReadReport()`).

## Reading a comparison

| Difference reads | Means |
|---|---|
| `messages: 12 recorded, 13 replayed` | the replay wrote a row more or less |
| `messages #4 <conversation#1>@4 message: recorded «…» replayed «…»` | one column of one row differs; each excerpt starts 60 bytes before the first different byte and shows 160 bytes |
| `call #3 (recorded #3) POST:…: …` | a request body to Argus changed — the model saw something else |
| `recorded call #7 … was never asked for` | the replay made fewer calls (or a tool result was not used) |
| `no recorded call answered it` | the replay made a call the recording does not have |
| a note `… read unscoped …` | the created ids of a table were read without a scope column; rows of other runs may count |

Ids above the high-water mark become ordinals of their creation (`<message#3>`), declared scope ids become
`<agentSpace>`-style names, timestamps, UUIDs, long hex tokens and sub-agent durations become placeholders, the
timing keys (`AgentRecordedRunIdNormalization::TIMING_KEYS`) and the screening verdict keys written later by a worker
(`ASYNC_VERDICT_KEYS`) are dropped, and the volatile columns (`AgentRecordedRunComparison::VOLATILE_ROW_COLUMNS`) are
never compared.

## Limits

- The tree is the root and the conversations whose `rootConversationId` is the root; a supervisor conversation lies
  outside it — record with the supervisor off.
- Image parts of a tool result are not recorded.
- Live third-party data: a recording carries what the tools answered on that day; store it where the host keeps
  test data, not in the repository when it holds customer data.
- Runtime code must not change while a recording or replay runs: one run, one build.

The reference kernel's `ReferenceScriptedModelTransport` is a hand-written player of the same Argus seam: it extends
`ArgusTransportRecording` and picks an answer by what the request says instead of its position — a way to run a host
without a model provider, not a comparison.

## For the package maintainer

- A new column that differs between two runs of one build (a stamp, a token, a vector) goes into
  `VOLATILE_ROW_COLUMNS`; a new id-bearing column or JSON key into `AgentRecordedRunIdNormalization::TABLE_FAMILY_BY_ID_KEY`;
  a new timing key into `TIMING_KEYS`. Otherwise every recording reports it.
- A new key on the stored tool call that old recordings lack is compared only when the recording holds it
  (`TOOL_CALL_KEYS_ADDED_AFTER_RECORDINGS`).
- A new site that reads the clock or mints an id on a path a replay compares reads through
  `RecordedRunClockAndIdSources::readNow()`, `mintHexToken()` or `mintUuid()`.

## Full class names

The classes this reference names, with their namespace (for the `use` lines of a host's code):
`DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunArgusRequest` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunCreatedIdReadReport` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunDispatchedMessage` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunPlayedAnswerIdTranslation` ·
`DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService` ·
`DDD\Domain\AI\Messenger\Conversations\AgentWorkerStateResetter` ·
`DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations` · `DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording` ·
`DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel` ·
`DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel` ·
`DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel` ·
`DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel` ·
`DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel` ·
`DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory` · `DDD\Presentation\Mcp\Services\MCPToolExecutorService` ·
`DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources` ·
`DDD\Domain\AI\Entities\RecordedRuns\RecordedRunIdSource` · `DDD\Domain\AI\Entities\RecordedRuns\RecordedRunNowSource`
· `DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording`
