<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The decision BATCH of one loop boundary: a plural Argus container with no endpoint of its own whose
 * CHILDREN are the decision calls of that boundary (today ONE child per boundary — the folded language + preload
 * request at the turn start, the follow-up preload pass, the closing decision on the final text, the stall-recovery
 * decision at the second stall; a second
 * same-boundary site becomes a parallel child by construction). ONE `argusLoad()` issues the children concurrently
 * through the framework's operation merging, under the DECISION timeout and in the settle mode: a child rejected at
 * the transport level (timeout, connection) gets a null result and its reason instead of voiding its siblings, and
 * the trait classifies it as `transportFailed`. Never drive `ArgusApiOperations` by hand from a service.
 *
 * @property (ArgusAgentTurnStartDecision|ArgusAgentTurnClosingDecision|ArgusAgentStallRecoveryDecision|ArgusAgentOwnerMessageInjectionDecision|ArgusAgentToolResultInjectionDecision|ArgusAgentOwnerConsentDecision)[] $elements
 * @phpstan-consistent-constructor
 */
class ArgusAgentDecisions extends ObjectSet
{
    use ArgusTrait;

    /**
     * Every decision child class of the module. The cascade loads ONLY the classes selected for it
     * ({@see getDecisionChildClassesToLoad()}) — a child of a class that is not selected is silently never loaded: its
     * `decided` stays false and the site records `fallback:unparsable` after a few milliseconds (the mid-turn plan
     * re-ask did exactly that on its first run). The unit test walks this directory and fails on any decision class
     * that is not listed.
     */
    public const array DECISION_CHILD_CLASSES = [
        ArgusAgentTurnStartDecision::class,
        ArgusAgentTurnClosingDecision::class,
        ArgusAgentStallRecoveryDecision::class,
        ArgusAgentProbeDecision::class,
        ArgusAgentOwnerMessageInjectionDecision::class,
        ArgusAgentToolResultInjectionDecision::class,
        ArgusAgentOwnerConsentDecision::class,
    ];

    /** @var string One id per container load — stamped on the request events and the decision records of its children. */
    public string $batchId = '';

    /** @var int|null Wall clock of the batch = the slowest child, not the sum. */
    public ?int $elapsedMs = null;

    /** A batch with ONE child, keyed by its boundary — the common case today. */
    public static function forChild(DefaultObject $child, string $decisionKeyInBatch): static
    {
        $batch = new static();
        $batch->addChild($child, $decisionKeyInBatch);
        return $batch;
    }

    /** Add a decision child under its batch key (two children with the same key would collapse into ONE operation). */
    public function addChild(DefaultObject $child, string $decisionKeyInBatch): void
    {
        if (property_exists($child, 'decisionKeyInBatch')) {
            $child->decisionKeyInBatch = $decisionKeyInBatch;
        }
        $this->add($child);
    }

    /**
     * The classes the cascade loads: the module's decision classes and the class of every child this batch holds — a
     * decision an application declares in its own domain is loaded without a registration.
     *
     * @return class-string[]
     */
    public function getDecisionChildClassesToLoad(): array
    {
        $decisionChildClassesToLoad = self::DECISION_CHILD_CLASSES;
        foreach ($this->getElements() as $decisionChild) {
            if (!in_array($decisionChild::class, $decisionChildClassesToLoad, true)) {
                $decisionChildClassesToLoad[] = $decisionChild::class;
            }
        }
        return $decisionChildClassesToLoad;
    }

    /** The batch's own settings before the load: settle mode on, the decision timeout, the child classes to cascade. Pure (no call). */
    public function prepareBatch(float $timeoutSeconds): void
    {
        $this->batchId = bin2hex(random_bytes(6));
        $argusSettings = $this->getArgusSettings();
        $argusSettings->keepFulfilledCallsWhenOneIsRejected = true;
        if ($timeoutSeconds > 0) {
            $argusSettings->timeout = $timeoutSeconds;
        }
        $this->setPropertiesToLoad(...$this->getDecisionChildClassesToLoad());
    }

    /** ONE merged, concurrent, settled batch under the decision timeout — never the entity or the call cache. */
    public function loadDecisions(float $timeoutSeconds): void
    {
        $this->prepareBatch($timeoutSeconds);
        $startedAt = hrtime(true);
        $this->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
        $this->elapsedMs = (int) round((hrtime(true) - $startedAt) / 1e6);
    }

    /**
     * The transport timeout of a split batch is the decision timeout plus this slack (at least the decision timeout
     * itself): the decision deadline is enforced by {@see receiveDecisionResponses()} from the moment the requests are
     * written, so the turn's own work between the send and the receive never runs into cURL's timeout.
     */
    public const float TRANSPORT_TIMEOUT_MINIMUM_SLACK_SECONDS = 3.0;

    /** How long {@see sendDecisionRequests()} drives the transfers at most until the request bytes are written. */
    public const float REQUEST_WRITE_MAX_SECONDS = 0.15;

    /** A receive that starts after the decision deadline still reads what already arrived for this long. */
    public const float RECEIVE_AFTER_DEADLINE_DRAIN_SECONDS = 0.05;

    /** @var bool True while the requests of {@see sendDecisionRequests()} travel. */
    protected bool $decisionRequestsInFlight = false;

    /** @var int|float|null The hrtime {@see sendDecisionRequests()} sent the requests at. */
    protected int|float|null $decisionRequestsSentAtHr = null;

    /** @var int|float|null The hrtime the requests were fully written at; null while they were not (seen) written. */
    protected int|float|null $decisionRequestsWrittenAtHr = null;

    /** @var int|float|null The hrtime {@see receiveDecisionResponses()} started at. */
    protected int|float|null $decisionResponsesReceiveStartedAtHr = null;

    /** True when the requests travelled while the caller worked; false when the send fell back to the blocking load. */
    protected bool $decisionRequestsSentSplit = false;

    /** True when the requests were fully written before {@see receiveDecisionResponses()} started. */
    protected bool $decisionRequestsWrittenBeforeTheReceive = false;

    /** The decision timeout of the split batch (its transport timeout is longer, {@see TRANSPORT_TIMEOUT_MINIMUM_SLACK_SECONDS}). */
    protected float $decisionTimeoutSeconds = 0.0;

    /** The transport (cURL) timeout of the split batch, from the send. */
    protected float $transportTimeoutSeconds = 0.0;

    /** @var int|null The milliseconds {@see receiveDecisionResponses()} waited for the answers (0 when they had arrived). */
    public ?int $waitedMs = null;

    /**
     * {@see loadDecisions()} in two halves: prepare the batch and SEND its children's requests — driving the transfers
     * for at most {@see REQUEST_WRITE_MAX_SECONDS} until the request bytes are written, so the model works while the
     * caller does its own — then return; the caller calls {@see receiveDecisionResponses()}. The transport timeout is
     * the decision timeout plus a slack; the decision deadline itself is the receive's. Where the execution cannot be
     * split (a recorded run, a monolith endpoint) the batch is loaded here already under the plain decision timeout —
     * no receive deadline bounds that load — and the receive then only measures. Never the entity or the call cache.
     */
    public function sendDecisionRequests(float $timeoutSeconds): void
    {
        $this->decisionTimeoutSeconds = $timeoutSeconds;
        $this->transportTimeoutSeconds = $this->buildTransportTimeoutSeconds($timeoutSeconds);
        $this->prepareBatch($this->transportTimeoutSeconds);
        $this->decisionRequestsSentAtHr = hrtime(true);
        $this->decisionRequestsInFlight = $this->sendDecisionRequestsOverTheArgusLoad($timeoutSeconds);
        $this->decisionRequestsSentSplit = $this->decisionRequestsInFlight;
        if ($this->decisionRequestsInFlight && $this->advanceDecisionRequestsUntilWritten(self::REQUEST_WRITE_MAX_SECONDS)) {
            $this->decisionRequestsWrittenAtHr = $this->findDecisionRequestsWrittenAtHr();
        }
    }

    /** The transport timeout of a split batch: the decision timeout plus {@see TRANSPORT_TIMEOUT_MINIMUM_SLACK_SECONDS}, at least the timeout itself. */
    protected function buildTransportTimeoutSeconds(float $decisionTimeoutSeconds): float
    {
        return $decisionTimeoutSeconds > 0 ? $decisionTimeoutSeconds + max(self::TRANSPORT_TIMEOUT_MINIMUM_SLACK_SECONDS, $decisionTimeoutSeconds) : $decisionTimeoutSeconds;
    }

    /** Move the requests of {@see sendDecisionRequests()} forward without waiting. */
    public function advanceDecisionRequests(): void
    {
        if ($this->decisionRequestsInFlight) {
            $this->advanceArgusLoadRequests();
            $this->decisionRequestsWrittenAtHr ??= $this->findDecisionRequestsWrittenAtHr();
        }
    }

    /**
     * Wait for the answers of {@see sendDecisionRequests()} until the decision deadline and hand each child its answer;
     * a child still pending then is cancelled (a rejected child: `transportFailed` without an HTTP status). The deadline
     * is the decision timeout from the moment the requests were written, or from now when they were not seen written —
     * the caller's own work before the receive never shortens the model's time. `elapsedMs` is the time from the send to
     * the last answer, {@see $waitedMs} the part the caller waited.
     */
    public function receiveDecisionResponses(): void
    {
        $waitStartedAt = hrtime(true);
        $this->decisionResponsesReceiveStartedAtHr = $waitStartedAt;
        if ($this->decisionRequestsInFlight) {
            $this->decisionRequestsInFlight = false;
            $this->decisionRequestsWrittenAtHr ??= $this->findDecisionRequestsWrittenAtHr();
            $this->decisionRequestsWrittenBeforeTheReceive = $this->decisionRequestsWrittenAtHr !== null && $this->decisionRequestsWrittenAtHr <= $waitStartedAt;
            $this->receiveDecisionResponsesOverTheArgusLoad($this->buildDecisionDeadlineHr($waitStartedAt));
        }
        $this->waitedMs = (int) round((hrtime(true) - $waitStartedAt) / 1e6);
        $this->elapsedMs = (int) round((hrtime(true) - ($this->decisionRequestsSentAtHr ?? $waitStartedAt)) / 1e6);
    }

    /**
     * The hrtime the receive stops waiting at: the decision timeout after the requests were written (or after the
     * receive started when they were not seen written), but never before a short drain of what already arrived.
     * Null without a decision timeout (the transport timeout alone bounds the wait).
     */
    public function buildDecisionDeadlineHr(int|float $receiveStartedAtHr): int|float|null
    {
        if ($this->decisionTimeoutSeconds <= 0) {
            return null;
        }
        $decisionDeadlineHr = ($this->decisionRequestsWrittenAtHr ?? $receiveStartedAtHr) + $this->decisionTimeoutSeconds * 1e9;
        return max($decisionDeadlineHr, $receiveStartedAtHr + self::RECEIVE_AFTER_DEADLINE_DRAIN_SECONDS * 1e9);
    }

    /**
     * True when the caller's own work between the send and the receive took longer than the decision timeout AND left
     * the model less than its time: the transport timeout ran out before the receive started, or the requests were not
     * yet written then (nothing drove them meanwhile). An answer missing then is no evidence about the model or the
     * network (the breaker must not trip on it). A request written before the receive gets the full decision timeout
     * from its write, so its missing answer is evidence however long the caller worked; a batch the send loaded at
     * once (no split) never counts.
     */
    public function wasDecisionTimeoutSpentOnTheCallersOwnWork(): bool
    {
        if (!$this->decisionRequestsSentSplit || $this->decisionTimeoutSeconds <= 0 || $this->decisionRequestsSentAtHr === null || $this->decisionResponsesReceiveStartedAtHr === null) {
            return false;
        }
        $secondsFromTheSendToTheReceive = ($this->decisionResponsesReceiveStartedAtHr - $this->decisionRequestsSentAtHr) / 1e9;
        if ($secondsFromTheSendToTheReceive <= $this->decisionTimeoutSeconds) {
            return false;
        }
        return $secondsFromTheSendToTheReceive >= $this->transportTimeoutSeconds || !$this->decisionRequestsWrittenBeforeTheReceive;
    }

    /**
     * True when a child that failed at the transport level got an HTTP status (a 429 or a 5xx): the provider answered,
     * so the failure is the provider's whatever the caller did meanwhile. The owner-message injection check is excluded
     * as in {@see hasTransportFailure()}.
     */
    public function hasTransportFailureWithAnHttpStatus(): bool
    {
        foreach ($this->getElements() as $child) {
            if ($child instanceof ArgusAgentOwnerMessageInjectionDecision) {
                continue;
            }
            if (($child->transportFailed ?? false) === true && ($child->httpStatusCode ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * The send of the Argus load ({@see sendArgusLoadRequests()}); true when the requests travel. A load that cannot be
     * split runs at once under `$blockingLoadTimeoutSeconds` instead of the longer transport timeout.
     */
    protected function sendDecisionRequestsOverTheArgusLoad(float $blockingLoadTimeoutSeconds): bool
    {
        return $this->sendArgusLoadRequests(useArgusEntityCache: false, useApiACallCache: false, blockingLoadTimeoutSeconds: $blockingLoadTimeoutSeconds);
    }

    protected function advanceDecisionRequestsUntilWritten(float $maxSeconds): bool
    {
        return $this->advanceArgusLoadRequestsUntilWritten($maxSeconds);
    }

    protected function findDecisionRequestsWrittenAtHr(): int|float|null
    {
        return $this->findArgusLoadRequestsWrittenAtHr();
    }

    protected function receiveDecisionResponsesOverTheArgusLoad(int|float|null $deadlineHr): void
    {
        $this->receiveArgusLoadResponses($deadlineHr);
    }

    /**
     * True when ANY child failed at the transport level — the shared breaker trips on the whole batch, never per sibling
     * order. The owner-message injection check is excluded: its larger question must never bench the other
     * turn-start sites; a failure there only means no verdict.
     */
    public function hasTransportFailure(): bool
    {
        foreach ($this->getElements() as $child) {
            if ($child instanceof ArgusAgentOwnerMessageInjectionDecision) {
                continue;
            }
            if (($child->transportFailed ?? false) === true) {
                return true;
            }
        }
        return false;
    }
}
