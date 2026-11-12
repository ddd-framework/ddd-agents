<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionCallOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Exceptions\MissingAIScopeException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentStallRecoveryDecision;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Tests\Agents\Support\UnitTestCase;
use Closure;
use GuzzleHttp\Exception\TransferException;
use RuntimeException;

/**
 * The one runner of decision-model calls classifies every call the same way — and only a transport
 * failure trips the breaker; a sibling's transport failure trips it while the primary's answer stands.
 */
class AgentDecisionCallsServiceTest extends UnitTestCase
{
    public function testAnOpenBreakerMakesNoCall(): void
    {
        $breaker = $this->breaker(true);
        $called = false;
        $runner = $this->runner($breaker);
        $outcome = $runner->runDecisionBatch($this->batch(static function () use (&$called) {
            $called = true;
        }), null, 1.0, 60, 'test');

        self::assertTrue($runner->isCircuitBreakerOpen());
        self::assertSame(AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN, $outcome->outcome);
        self::assertFalse($called);
        self::assertNull($outcome->elapsedMs);
        self::assertFalse($outcome->childrenSettled);
    }

    public function testWithoutTheBreakerCheckTheBatchIsCalledAndClassifiedLikeAnyOther(): void
    {
        $breaker = $this->breaker(false);
        $primary = new ArgusAgentStallRecoveryDecision();
        $runner = $this->runner($breaker);
        $outcome = $runner->runDecisionBatchWithoutBreakerCheck($this->batch(static function () use ($primary) {
            $primary->transportFailed = true;
            $primary->transportFailureReason = 'timeout';
        }, $primary), $primary, 1.0, 60, 'test');

        self::assertFalse($runner->isCircuitBreakerOpen());
        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, $outcome->outcome);
        self::assertSame([[60, 'timeout']], $breaker->trips);
    }

    public function testAThrownExceptionIsSummarizedOnTheOutcome(): void
    {
        $breaker = $this->breaker(false);
        $refusal = new MissingAIScopeException(str_repeat('x', 300));
        $outcome = $this->runner($breaker)->runDecisionBatch($this->batch(static function () use ($refusal) {
            throw $refusal;
        }), null, 1.0, 60, 'test');

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_REFUSED, $outcome->outcome);
        self::assertSame(MissingAIScopeException::class, $outcome->failureReason);
        self::assertSame(MissingAIScopeException::class . ': ' . mb_substr($refusal->getMessage(), 0, 160), $outcome->thrownExceptionSummary);
        self::assertSame([], $breaker->trips);
    }

    public function testAnAnswerSettlesTheChildrenAndLeavesTheBreakerToTheSite(): void
    {
        $breaker = $this->breaker(false);
        $primary = new ArgusAgentStallRecoveryDecision();
        $outcome = $this->runner($breaker)->runDecisionBatch($this->batch(static function () {}, $primary), $primary, 1.0, 60, 'test');

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_ANSWERED, $outcome->outcome);
        self::assertTrue($outcome->childrenSettled);
        self::assertNotNull($outcome->elapsedMs);
        self::assertSame([], $breaker->trips);
        self::assertSame(0, $breaker->closes);
    }

    public function testThePrimarysTransportFailureTripsTheBreakerWithItsReason(): void
    {
        $breaker = $this->breaker(false);
        $primary = new ArgusAgentStallRecoveryDecision();
        $outcome = $this->runner($breaker)->runDecisionBatch($this->batch(static function () use ($primary) {
            $primary->transportFailed = true;
            $primary->transportFailureReason = 'timeout';
        }, $primary), $primary, 1.0, 60, 'test');

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, $outcome->outcome);
        self::assertSame('timeout', $outcome->failureReason);
        self::assertTrue($outcome->childrenSettled);
        self::assertSame([[60, 'timeout']], $breaker->trips);
    }

    public function testASiblingsTransportFailureTripsTheBreakerButThePrimaryAnswerStands(): void
    {
        $breaker = $this->breaker(false);
        $primary = new ArgusAgentStallRecoveryDecision();
        $sibling = new ArgusAgentStallRecoveryDecision();
        $outcome = $this->runner($breaker)->runDecisionBatch($this->batch(static function () use ($sibling) {
            $sibling->transportFailed = true;
        }, $primary, $sibling), $primary, 1.0, 60, 'turn-start');

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_ANSWERED, $outcome->outcome);
        self::assertTrue($outcome->siblingTransportFailed);
        self::assertNull($outcome->failureReason);
        self::assertCount(1, $breaker->trips);
        self::assertStringContainsString('turn-start', $breaker->trips[0][1]);
    }

    public function testWithoutAPrimaryAnyChildsTransportFailureIsTheBatchsFailure(): void
    {
        $breaker = $this->breaker(false);
        $child = new ArgusAgentStallRecoveryDecision();
        $outcome = $this->runner($breaker)->runDecisionBatch($this->batch(static function () use ($child) {
            $child->transportFailed = true;
        }, $child), null, 1.0, 60, 'sub-skill');

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, $outcome->outcome);
        self::assertTrue($outcome->childrenSettled);
        self::assertCount(1, $breaker->trips);
    }

    public function testAThrownTransportErrorTripsTheBreakerAndSettlesNoChild(): void
    {
        $breaker = $this->breaker(false);
        $outcome = $this->runner($breaker)->runDecisionBatch($this->batch(static function () {
            throw new TransferException('connection refused');
        }), null, 1.0, 60, 'test');

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, $outcome->outcome);
        self::assertFalse($outcome->childrenSettled);
        self::assertStringContainsString('connection refused', (string)$outcome->failureReason);
        self::assertCount(1, $breaker->trips);
    }

    public function testOurOwnGatesRefusalNeverTripsTheBreaker(): void
    {
        $breaker = $this->breaker(false);
        $outcome = $this->runner($breaker)->runDecisionBatch($this->batch(static function () {
            throw new MissingAIScopeException('no scope');
        }), null, 1.0, 60, 'test');

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_REFUSED, $outcome->outcome);
        self::assertSame(MissingAIScopeException::class, $outcome->failureReason);
        self::assertSame([], $breaker->trips);
    }

    public function testOurOwnErrorDuringTheCallNeverTripsTheBreaker(): void
    {
        $breaker = $this->breaker(false);
        $outcome = $this->runner($breaker)->runDecisionBatch($this->batch(static function () {
            throw new RuntimeException('missing registry entry');
        }), null, 1.0, 60, 'test');

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_ERROR, $outcome->outcome);
        self::assertStringContainsString('missing registry entry', (string)$outcome->failureReason);
        self::assertFalse($outcome->childrenSettled);
        self::assertSame([], $breaker->trips);
    }

    /** ONE close rule — only a parsable answer closes the breaker. */
    public function testOnlyAParsableAnswerClosesTheBreaker(): void
    {
        $breaker = $this->breaker(true);
        $runner = $this->runner($breaker);
        $answered = AgentDecisionCallOutcome::build(AgentDecisionCallOutcome::OUTCOME_ANSWERED);

        $runner->closeCircuitBreakerAfter($answered, false);
        self::assertSame(0, $breaker->closes, 'an unparsable (or HTTP status error) answer leaves the breaker');
        $runner->closeCircuitBreakerAfter($answered, true);
        self::assertSame(1, $breaker->closes, 'a parsable answer closes it');

        $siblingFailed = AgentDecisionCallOutcome::build(AgentDecisionCallOutcome::OUTCOME_ANSWERED);
        $siblingFailed->siblingTransportFailed = true;
        $runner->closeCircuitBreakerAfter($siblingFailed, true);
        foreach ([AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN, AgentDecisionCallOutcome::OUTCOME_REFUSED, AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, AgentDecisionCallOutcome::OUTCOME_ERROR] as $outcomeWithoutAnswer) {
            $runner->closeCircuitBreakerAfter(AgentDecisionCallOutcome::build($outcomeWithoutAnswer), true);
        }
        self::assertSame(1, $breaker->closes, 'a call without its own answer, or with a failed sibling, never closes it');
    }

    /** ONE stamp rule — our own gate's refusal is `fallback:refused`. */
    public function testEveryOutcomeHasOneStamp(): void
    {
        $stampByOutcome = [];
        foreach ([AgentDecisionCallOutcome::OUTCOME_ANSWERED, AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN, AgentDecisionCallOutcome::OUTCOME_REFUSED, AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, AgentDecisionCallOutcome::OUTCOME_TIMEOUT_SPENT_ON_OWN_WORK, AgentDecisionCallOutcome::OUTCOME_ERROR] as $outcome) {
            $decisionCallOutcome = AgentDecisionCallOutcome::build($outcome);
            $stampByOutcome[$outcome] = [
                $decisionCallOutcome->resolveFallbackStamp(),
                $decisionCallOutcome->resolveDecidedBy(true, AgentDecisionModelSettings::MODE_ON),
                $decisionCallOutcome->resolveDecidedBy(true, AgentDecisionModelSettings::MODE_SHADOW),
                $decisionCallOutcome->resolveDecidedBy(false, AgentDecisionModelSettings::MODE_ON),
            ];
        }
        self::assertSame([
            AgentDecisionCallOutcome::OUTCOME_ANSWERED => [null, 'jev', 'shadow', 'fallback:unparsable'],
            AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN => ['fallback:breaker', 'fallback:breaker', 'fallback:breaker', 'fallback:breaker'],
            AgentDecisionCallOutcome::OUTCOME_REFUSED => ['fallback:refused', 'fallback:refused', 'fallback:refused', 'fallback:refused'],
            AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED => ['fallback:transport', 'fallback:transport', 'fallback:transport', 'fallback:transport'],
            AgentDecisionCallOutcome::OUTCOME_TIMEOUT_SPENT_ON_OWN_WORK => ['fallback:ownWork', 'fallback:ownWork', 'fallback:ownWork', 'fallback:ownWork'],
            AgentDecisionCallOutcome::OUTCOME_ERROR => ['fallback:error', 'fallback:error', 'fallback:error', 'fallback:error'],
        ], $stampByOutcome);
    }

    protected function breaker(bool $open): AgentDecisionModelCircuitBreakerService
    {
        return new class ($open) extends AgentDecisionModelCircuitBreakerService {
            /** @var array<int, array{0: int, 1: string}> */
            public array $trips = [];
            public int $closes = 0;

            public function __construct(protected bool $open)
            {
            }

            public function isOpen(): bool
            {
                return $this->open;
            }

            public function trip(int $seconds, string $reason): void
            {
                $this->trips[] = [$seconds, $reason];
            }

            public function close(): void
            {
                $this->closes++;
            }
        };
    }

    protected function runner(AgentDecisionModelCircuitBreakerService $breaker): AgentDecisionCallsService
    {
        return new class ($breaker) extends AgentDecisionCallsService {
            public function __construct(protected AgentDecisionModelCircuitBreakerService $breakerDouble)
            {
            }

            protected function circuitBreaker(): AgentDecisionModelCircuitBreakerService
            {
                return $this->breakerDouble;
            }
        };
    }

    /** The split call: a refusal the send throws is classified on receive, exactly like the one-shot call's. */
    public function testARefusalOfTheSendIsClassifiedWhenTheBatchIsReceived(): void
    {
        $breaker = $this->breaker(false);
        $runner = $this->runner($breaker);
        $batch = $this->splitBatch();
        $batch->sendError = new MissingAIScopeException('no scope');

        $agentDecisionBatchInFlight = $runner->sendDecisionBatchWithoutBreakerCheck($batch, null, 1.0, 60, 'turn-start');
        $outcome = $runner->receiveDecisionBatch($agentDecisionBatchInFlight);

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_REFUSED, $outcome->outcome);
        self::assertSame(MissingAIScopeException::class, $outcome->failureReason);
        self::assertFalse($batch->received, 'a failed send receives nothing');
        self::assertSame([], $breaker->trips);
    }

    /** The split call: a transport failure of the received primary trips the breaker as in the one-shot call. */
    public function testATransportFailureOnReceiveTripsTheBreaker(): void
    {
        $breaker = $this->breaker(false);
        $runner = $this->runner($breaker);
        $primary = new ArgusAgentStallRecoveryDecision();
        $batch = $this->splitBatch($primary);
        $batch->primaryFailsAtTheTransportLevel = true;

        $outcome = $runner->receiveDecisionBatch($runner->sendDecisionBatchWithoutBreakerCheck($batch, $primary, 1.0, 60, 'turn-start'));

        self::assertTrue($batch->sent);
        self::assertTrue($batch->received);
        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, $outcome->outcome);
        self::assertSame([[60, 'timeout']], $breaker->trips);
        self::assertTrue($outcome->childrenSettled);
    }

    /** The split call: a transfer error the receive throws is a transport failure. */
    public function testATransferErrorOnReceiveIsATransportFailure(): void
    {
        $breaker = $this->breaker(false);
        $runner = $this->runner($breaker);
        $batch = $this->splitBatch();
        $batch->receiveError = new TransferException('connection reset');

        $outcome = $runner->receiveDecisionBatch($runner->sendDecisionBatchWithoutBreakerCheck($batch, null, 1.0, 60, 'turn-start'));

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, $outcome->outcome);
        self::assertFalse($outcome->childrenSettled);
        self::assertCount(1, $breaker->trips);
    }

    /**
     * Our own work between the send and the receive took longer than the decision timeout while the requests were
     * not yet written, and the primary got no answer — no evidence about the model or the network, so the breaker
     * stays closed and the sites fall back.
     */
    public function testOurOwnWorkLongerThanTheTimeoutBeforeTheRequestWasWrittenNeverTripsTheBreaker(): void
    {
        $breaker = $this->breaker(false);
        $runner = $this->runner($breaker);
        $primary = new ArgusAgentStallRecoveryDecision();
        $sibling = new ArgusAgentStallRecoveryDecision();
        $batch = $this->transportScriptedBatch($primary, $sibling);
        $batch->childrenRejectedOnReceive = [$primary, $sibling];
        $batch->requestsWrittenBySend = false;

        $agentDecisionBatchInFlight = $runner->sendDecisionBatchWithoutBreakerCheck($batch, $primary, 0.05, 60, 'turn-start');
        usleep(120000); // the loop's own work: longer than the 50 ms decision timeout
        $outcome = $runner->receiveDecisionBatch($agentDecisionBatchInFlight);

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TIMEOUT_SPENT_ON_OWN_WORK, $outcome->outcome);
        self::assertSame('fallback:ownWork', $outcome->resolveFallbackStamp());
        self::assertFalse($outcome->siblingTransportFailed);
        self::assertSame([], $breaker->trips);
        $runner->closeCircuitBreakerAfter($outcome, true);
        self::assertSame(0, $breaker->closes, 'no answer, no close');
    }

    /** Our own work outlasted cURL's transport timeout before the receive: the written request never had its time. */
    public function testATransportTimeoutThatRanOutBeforeTheReceiveNeverTripsTheBreaker(): void
    {
        $breaker = $this->breaker(false);
        $runner = $this->runner($breaker);
        $primary = new ArgusAgentStallRecoveryDecision();
        $batch = $this->transportScriptedBatch($primary);
        $batch->childrenRejectedOnReceive = [$primary];
        $batch->transportTimeoutSecondsInsteadOfTheSlack = 0.1;

        $agentDecisionBatchInFlight = $runner->sendDecisionBatchWithoutBreakerCheck($batch, $primary, 0.05, 60, 'turn-start');
        usleep(150000); // the loop's own work: longer than the 100 ms transport timeout
        $outcome = $runner->receiveDecisionBatch($agentDecisionBatchInFlight);

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TIMEOUT_SPENT_ON_OWN_WORK, $outcome->outcome);
        self::assertSame([], $breaker->trips);
    }

    /**
     * A request written at the send gets the full decision timeout from its write: no answer within it is evidence
     * about the model or the network, however long our own work before the receive took — the breaker trips.
     */
    public function testARequestWrittenAtTheSendWithoutAnAnswerTripsTheBreakerAfterOurOwnWork(): void
    {
        $breaker = $this->breaker(false);
        $runner = $this->runner($breaker);
        $primary = new ArgusAgentStallRecoveryDecision();
        $batch = $this->transportScriptedBatch($primary);
        $batch->childrenRejectedOnReceive = [$primary];

        $agentDecisionBatchInFlight = $runner->sendDecisionBatchWithoutBreakerCheck($batch, $primary, 0.05, 60, 'turn-start');
        usleep(120000); // longer than the 50 ms decision timeout, shorter than the 3.05 s transport timeout
        $outcome = $runner->receiveDecisionBatch($agentDecisionBatchInFlight);

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, $outcome->outcome);
        self::assertCount(1, $breaker->trips);
    }

    /**
     * A send that cannot split (a recorded run, a monolith endpoint) loads the batch at once under the plain decision
     * timeout, not the longer transport timeout; its missing answer is never our own work.
     */
    public function testTheBlockingFallbackLoadsUnderThePlainDecisionTimeoutAndItsMissTrips(): void
    {
        $breaker = $this->breaker(false);
        $runner = $this->runner($breaker);
        $primary = new ArgusAgentStallRecoveryDecision();
        $batch = $this->transportScriptedBatch($primary);
        $batch->childrenRejectedOnReceive = [$primary];
        $batch->sendFallsBackToTheBlockingLoad = true;

        $agentDecisionBatchInFlight = $runner->sendDecisionBatchWithoutBreakerCheck($batch, $primary, 0.05, 60, 'turn-start');
        usleep(120000);
        $outcome = $runner->receiveDecisionBatch($agentDecisionBatchInFlight);

        self::assertSame(0.05, $batch->blockingLoadTimeoutSeconds);
        self::assertSame(0.05 + ArgusAgentDecisions::TRANSPORT_TIMEOUT_MINIMUM_SLACK_SECONDS, $batch->getArgusSettings()->timeout, 'a split send would have carried the slack');
        self::assertNull($batch->receiveDeadlineHr, 'nothing in flight, nothing received');
        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, $outcome->outcome);
        self::assertCount(1, $breaker->trips);
    }

    /** Without our own delay the same missing answer is the model's timeout — the breaker trips as before. */
    public function testAMissingAnswerWithinTheTimeoutStillTripsTheBreaker(): void
    {
        $breaker = $this->breaker(false);
        $runner = $this->runner($breaker);
        $primary = new ArgusAgentStallRecoveryDecision();
        $batch = $this->transportScriptedBatch($primary);
        $batch->childrenRejectedOnReceive = [$primary];

        $outcome = $runner->receiveDecisionBatch($runner->sendDecisionBatchWithoutBreakerCheck($batch, $primary, 1.0, 60, 'turn-start'));

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, $outcome->outcome);
        self::assertCount(1, $breaker->trips);
    }

    /** A provider that ANSWERED with a 5xx is the provider's failure, however long our own work took. */
    public function testAnHttpStatusFailureTripsTheBreakerEvenAfterOurOwnDelay(): void
    {
        $breaker = $this->breaker(false);
        $runner = $this->runner($breaker);
        $primary = new ArgusAgentStallRecoveryDecision();
        $batch = $this->transportScriptedBatch($primary);
        $batch->childrenRejectedOnReceive = [$primary];
        $batch->httpStatusCodeOfRejectedChildren = 503;

        $agentDecisionBatchInFlight = $runner->sendDecisionBatchWithoutBreakerCheck($batch, $primary, 0.05, 60, 'turn-start');
        usleep(120000);
        $outcome = $runner->receiveDecisionBatch($agentDecisionBatchInFlight);

        self::assertSame(AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED, $outcome->outcome);
        self::assertCount(1, $breaker->trips);
    }

    /**
     * The transport timeout is the decision timeout plus a slack, the send drives the transfers until the request
     * is written, and the receive's deadline is the decision timeout from that write — never from the send.
     */
    public function testTheDeadlineRunsFromTheWrittenRequestAndTheTransportTimeoutCarriesASlack(): void
    {
        $batch = $this->transportScriptedBatch(new ArgusAgentStallRecoveryDecision());
        $batch->requestsWrittenAtHr = hrtime(true) + 300e6;

        $batch->sendDecisionRequests(2.0);
        $batch->receiveDecisionResponses();

        self::assertSame(2.0 + ArgusAgentDecisions::TRANSPORT_TIMEOUT_MINIMUM_SLACK_SECONDS, $batch->getArgusSettings()->timeout);
        self::assertSame([ArgusAgentDecisions::REQUEST_WRITE_MAX_SECONDS], $batch->writeDriveSeconds);
        self::assertEqualsWithDelta($batch->requestsWrittenAtHr + 2.0e9, $batch->receiveDeadlineHr, 1);
    }

    /** A batch whose Argus send, write drive and receive are scripted: no HTTP, the rejected children marked on receive. */
    protected function transportScriptedBatch(ArgusAgentStallRecoveryDecision ...$children): AgentDecisionCallsServiceTestTransportScriptedBatch
    {
        $batch = new AgentDecisionCallsServiceTestTransportScriptedBatch();
        foreach ($children as $position => $child) {
            $batch->addChild($child, 'child' . $position);
        }
        return $batch;
    }

    /** A batch whose send and receive are scripted instead of calling Argus. */
    protected function splitBatch(ArgusAgentStallRecoveryDecision ...$children): AgentDecisionCallsServiceTestSplitBatch
    {
        $batch = new AgentDecisionCallsServiceTestSplitBatch();
        foreach ($children as $position => $child) {
            $batch->addChild($child, 'child' . $position);
        }
        return $batch;
    }

    /** A batch whose load runs `$onLoad` instead of calling Argus. */
    protected function batch(Closure $onLoad, ArgusAgentStallRecoveryDecision ...$children): ArgusAgentDecisions
    {
        $batch = new class extends ArgusAgentDecisions {
            public ?Closure $onLoad = null;

            public function loadDecisions(float $timeoutSeconds): void
            {
                ($this->onLoad)();
            }
        };
        $batch->onLoad = $onLoad;
        foreach ($children as $position => $child) {
            $batch->addChild($child, 'child' . $position);
        }
        return $batch;
    }
}
