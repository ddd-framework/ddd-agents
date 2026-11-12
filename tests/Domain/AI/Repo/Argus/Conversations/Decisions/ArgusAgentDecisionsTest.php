<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisionTrait;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnClosingDecision;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Tests\Agents\Support\UnitTestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * The decision batch container and the Argus base changes it needs: the opt-in settle mode (a rejected
 * call yields a null result slot + its reason while the siblings are handled; the default path still throws), the
 * container's own timeout and settle flag, and per-child keys so two children never collapse into one operation.
 * No network: the settle mapping is driven with already-settled promises.
 */
class ArgusAgentDecisionsTest extends UnitTestCase
{
    /** @return array<string, mixed> */
    protected function collect(array $promises, bool $settle): array
    {
        $operations = new ArgusApiOperations();
        return self::invokeMethod($operations, 'collectResponses', [$promises, $settle, '___']);
    }

    public function testTheSettleModeKeepsTheFulfilledSiblingAndRecordsTheRejectedOne(): void
    {
        $operations = new ArgusApiOperations();
        $promises = [
            'POST:/systemone___a' => new FulfilledPromise(new Response(200, [], '{"answers":{}}')),
            'POST:/systemone___b' => new RejectedPromise(new ConnectException('cURL error 28: Operation timed out', new Request('POST', '/systemone'))),
        ];
        $responses = self::invokeMethod($operations, 'collectResponses', [$promises, true, '___']);

        self::assertInstanceOf(Response::class, $responses['POST:/systemone___a'], 'the fulfilled sibling is handled as today');
        self::assertNull($responses['POST:/systemone___b'], 'the rejected call gets a null slot so its handler still runs');
        $reasons = self::getPropertyValue($operations, 'rejectionReasonsByCallId');
        self::assertArrayHasKey('b', $reasons);
        self::assertStringContainsString('ConnectException', $reasons['b']);
        self::assertStringContainsString('timed out', $reasons['b']);
        self::assertArrayNotHasKey('a', $reasons);
    }

    public function testTheDefaultPathStillThrowsOnTheFirstRejection(): void
    {
        $promises = [
            'POST:/systemone___a' => new FulfilledPromise(new Response(200, [], '{}')),
            'POST:/systemone___b' => new RejectedPromise(new ConnectException('boom', new Request('POST', '/systemone'))),
        ];
        $this->expectException(ConnectException::class);
        $this->collect($promises, false);
    }

    public function testTwoChildrenWithTheSameStateAreTwoOperationsAndTheSameKeyCollapses(): void
    {
        $first = new ArgusAgentTurnStartDecision();
        $second = new ArgusAgentTurnStartDecision();
        $batch = new ArgusAgentDecisions();
        $batch->addChild($first, 'turnStart');
        $batch->addChild($second, 'turnStart:pass2');
        self::assertNotSame($first->uniqueKey(), $second->uniqueKey(), 'the batch key beats the content-derived default');
        self::assertCount(2, $batch->getElements());

        $twin = new ArgusAgentTurnStartDecision();
        $batch->addChild($twin, 'turnStart');
        self::assertCount(2, $batch->getElements(), 'a duplicate key is the documented collapse — never two children with one key');
    }

    public function testPrepareBatchSetsTheSettleModeTheDecisionTimeoutAndTheChildClasses(): void
    {
        $batch = ArgusAgentDecisions::forChild(new ArgusAgentTurnClosingDecision(), 'turnClosing');
        $batch->prepareBatch(2.0);
        $settings = self::invokeMethod($batch, 'getArgusSettings', []);
        self::assertTrue($settings->keepFulfilledCallsWhenOneIsRejected);
        self::assertSame(2.0, $settings->timeout, 'the container carries the decision timeout — a child\'s own value is inert inside a batch load');
        self::assertNotSame('', $batch->batchId);
        self::assertNull($settings->getLoadEndpoint(), 'the container has no endpoint of its own — no root operation');
        self::assertFalse($batch->hasTransportFailure());
    }

    public function testABatchOfTheModulesDecisionsSelectsTheListedClasses(): void
    {
        $batch = ArgusAgentDecisions::forChild(new ArgusAgentTurnClosingDecision(), 'turnClosing');
        self::assertSame(ArgusAgentDecisions::DECISION_CHILD_CLASSES, $batch->getDecisionChildClassesToLoad());
    }

    public function testABatchSelectsTheClassOfADecisionAnApplicationDeclares(): void
    {
        self::assertNotContains(ArgusAgentDecisionOfAnApplication::class, ArgusAgentDecisions::DECISION_CHILD_CLASSES);
        $decisionOfAnApplication = new ArgusAgentDecisionOfAnApplication();
        $batch = ArgusAgentDecisions::forChild($decisionOfAnApplication, 'decisionOfAnApplication');
        $batch->addChild(new ArgusAgentDecisionOfAnApplication(), 'decisionOfAnApplication:second');
        self::assertSame(
            [...ArgusAgentDecisions::DECISION_CHILD_CLASSES, ArgusAgentDecisionOfAnApplication::class],
            $batch->getDecisionChildClassesToLoad(),
            'the class is selected once, after the module\'s own'
        );

        $batch->prepareBatch(2.0);
        $settingsOfTheDecision = self::invokeMethod($decisionOfAnApplication, 'getArgusSettings', []);
        self::assertTrue($settingsOfTheDecision->isToBeLoaded(), 'the cascade loads the application\'s decision');
    }

    /**
     * The cascade loads only the classes the container selects — a decision class missing from the list is never
     * loaded and its site falls back to "unparsable" after a few milliseconds (observed on the mid-turn plan re-ask).
     * Every class in this directory that uses the decision trait must be listed.
     */
    public function testEveryDecisionClassInTheDirectoryIsABatchChildClass(): void
    {
        $directory = dirname((new \ReflectionClass(ArgusAgentDecisions::class))->getFileName());
        $decisionClasses = [];
        foreach (glob($directory . '/ArgusAgent*Decision.php') ?: [] as $file) {
            $className = 'DDD\\Domain\\AI\\Repo\\Argus\\Conversations\\Decisions\\' . basename($file, '.php');
            if (class_exists($className) && in_array(ArgusAgentDecisionTrait::class, (new \ReflectionClass($className))->getTraitNames(), true)) {
                $decisionClasses[] = $className;
            }
        }
        self::assertGreaterThanOrEqual(5, count($decisionClasses));
        foreach ($decisionClasses as $decisionClass) {
            self::assertContains($decisionClass, ArgusAgentDecisions::DECISION_CHILD_CLASSES, $decisionClass . ' is not selected by the batch — it would never be loaded');
        }
    }
}
