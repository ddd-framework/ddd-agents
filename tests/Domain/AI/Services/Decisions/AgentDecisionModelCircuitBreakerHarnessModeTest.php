<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions;

use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Cache\Cache;
use RuntimeException;

/**
 * The decision-model breaker in harness mode (a recorded-run comparison): never open, the shared key never touched;
 * outside harness mode it reads and writes the shared key exactly as before.
 */
class AgentDecisionModelCircuitBreakerHarnessModeTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        AgentDecisionModelCircuitBreakerService::$harnessMode = false;
        parent::tearDown();
    }

    /** A breaker whose shared cache counts every access and is unavailable (each method fails closed). */
    protected static function buildBreakerCountingCacheAccess(): AgentDecisionModelCircuitBreakerService
    {
        return new class () extends AgentDecisionModelCircuitBreakerService {
            public int $cacheAccessCount = 0;

            protected function cache(): Cache
            {
                $this->cacheAccessCount++;
                throw new RuntimeException('cache unavailable in the test');
            }
        };
    }

    public function testInHarnessModeTheBreakerIsNeverOpenAndNeverTouchesTheSharedKey(): void
    {
        AgentDecisionModelCircuitBreakerService::$harnessMode = true;
        $agentDecisionModelCircuitBreakerService = self::buildBreakerCountingCacheAccess();

        $agentDecisionModelCircuitBreakerService->trip(60, 'recorded timeout');
        self::assertFalse($agentDecisionModelCircuitBreakerService->isOpen());
        $agentDecisionModelCircuitBreakerService->close();

        self::assertSame(0, $this->getPropertyValue($agentDecisionModelCircuitBreakerService, 'cacheAccessCount'));
    }

    public function testOutsideHarnessModeTheBreakerUsesTheSharedKeyAsBefore(): void
    {
        $agentDecisionModelCircuitBreakerService = self::buildBreakerCountingCacheAccess();

        $agentDecisionModelCircuitBreakerService->trip(60, 'timeout');
        self::assertFalse($agentDecisionModelCircuitBreakerService->isOpen(), 'an unavailable cache reads closed');

        self::assertSame(2, $this->getPropertyValue($agentDecisionModelCircuitBreakerService, 'cacheAccessCount'), 'trip writes, isOpen reads');
    }
}
