<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Domain\AI\MessageHandlers\AgentMessengerTransports;
use DDD\Domain\Common\Services\WorkerDefinitionsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * `examples/workers.yaml` — the consumer list a host copies — read by the framework's own parser: one consumer per
 * agent transport ({@see AgentMessengerTransports::ALL}), no unknown key, and a turn consumer whose silent threshold
 * lies above the turn handler's 1800 s time limit.
 */
class ExampleWorkerListTest extends UnitTestCase
{
    public const string EXAMPLE_WORKER_LIST = __DIR__ . '/../../examples/workers.yaml';

    /** The turn handler's time limit ({@see \DDD\Domain\AI\MessageHandlers\AIConversationResumeHandler}). */
    public const int TURN_TIME_LIMIT_SECONDS = 1800;

    public function testTheExampleListsOneConsumerPerAgentTransport(): void
    {
        /** @var WorkerDefinitionsService $workerDefinitionsService */
        $workerDefinitionsService = DDDService::instance()->getService(WorkerDefinitionsService::class);
        $workerDefinitions = $workerDefinitionsService->readWorkerDefinitionsFromFile(self::EXAMPLE_WORKER_LIST);
        self::assertTrue($workerDefinitions->definitionFileExists);
        self::assertSame([], $workerDefinitions->duplicateTransports);
        $listedTransports = $workerDefinitions->consumers->getTransportNames();
        $agentTransports = AgentMessengerTransports::ALL;
        sort($listedTransports);
        sort($agentTransports);
        self::assertSame($agentTransports, $listedTransports);
        foreach ($workerDefinitions->consumers->getElements() as $workerConsumerDefinition) {
            self::assertSame([], $workerConsumerDefinition->additionalSettings->getElements(), $workerConsumerDefinition->transport . ' carries an unknown key');
        }
        $turnConsumer = $workerDefinitions->consumers->getByTransport(AgentMessengerTransports::TURN);
        self::assertNotNull($turnConsumer?->busySilentSeconds);
        self::assertGreaterThan(self::TURN_TIME_LIMIT_SECONDS, $turnConsumer->busySilentSeconds);
    }
}
