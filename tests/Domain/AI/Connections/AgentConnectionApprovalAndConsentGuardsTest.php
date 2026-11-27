<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalOptions;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequest;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\AgentConnectionConnectsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Libs\JWTPayload;
use DDD\Tests\Agents\Support\Connections\ClaimTestApprovalRequestsService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An approval is answered at most once (expiry first, a lost claim is refused) and a consent completes only with the
 * signed state of this stage and the browser that started it.
 */
class AgentConnectionApprovalAndConsentGuardsTest extends UnitTestCase
{
    /** @var array<string, mixed> the signing settings before the test */
    protected array $environmentBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([JWTPayload::ENV_HASH_KEY => 'a-test-signing-key-of-sufficient-length-0123456789', JWTPayload::ENV_HASH_METHOD => 'HS256'] as $variableName => $testValue) {
            $this->environmentBefore[$variableName] = $_ENV[$variableName] ?? null;
            $_ENV[$variableName] = $testValue;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environmentBefore as $variableName => $valueBefore) {
            if ($valueBefore === null) {
                unset($_ENV[$variableName]);
                continue;
            }
            $_ENV[$variableName] = $valueBefore;
        }
        parent::tearDown();
    }

    public function testAnExpiredRequestIsMarkedExpiredAndSaysSo(): void
    {
        $approvalRequestsService = new ClaimTestApprovalRequestsService();
        $agentConnectionApprovalRequest = $this->buildOpenRequest();
        $agentConnectionApprovalRequest->expiresAt = (new DateTime())->modify('-1 minute');
        try {
            $approvalRequestsService->answer($agentConnectionApprovalRequest, AgentConnectionApprovalRequest::OPTION_APPROVE, 2, 'web');
            self::fail('an expired request cannot be answered');
        } catch (ForbiddenException $forbidden) {
            self::assertStringContainsString('expired', $forbidden->getMessage());
        }
        self::assertSame([AgentConnectionApprovalRequest::STATUS_EXPIRED], $approvalRequestsService->claimedStatuses);
    }

    public function testAnAnsweredRequestAndALostClaimAreRefused(): void
    {
        $approvalRequestsService = new ClaimTestApprovalRequestsService();
        $approvalRequestsService->inMemoryConnectionsService->connectionsById[9] = AgentConnection::newInstance();
        $answeredRequest = $this->buildOpenRequest();
        $answeredRequest->status = AgentConnectionApprovalRequest::STATUS_REJECTED;
        try {
            $approvalRequestsService->answer($answeredRequest, AgentConnectionApprovalRequest::OPTION_APPROVE, 2, 'web');
            self::fail('an answered request cannot be answered again');
        } catch (ForbiddenException $forbidden) {
            self::assertStringContainsString('already rejected', $forbidden->getMessage());
        }
        $approvalRequestsService->aParallelAnswerWinsTheRow = true;
        try {
            $approvalRequestsService->answer($this->buildOpenRequest(), AgentConnectionApprovalRequest::OPTION_APPROVE, 2, 'slack');
            self::fail('the second of two parallel answers is refused');
        } catch (ForbiddenException $forbidden) {
            self::assertStringContainsString('answered meanwhile', $forbidden->getMessage());
        }
        self::assertSame([AgentConnectionApprovalRequest::STATUS_APPROVED], $approvalRequestsService->claimedStatuses, 'only the open request reached the claim');
    }

    public function testAConsentNeedsTheSignedStateOfThisStageAndTheStartingBrowser(): void
    {
        /** @var AgentConnectionConnectsService $connectsService */
        $connectsService = DDDService::instance()->getService(AgentConnectionConnectsService::class);
        $this->assertConsentRefused($connectsService, 'not-a-signed-state', 'nonce', 'invalid or expired');
        $foreignPurposeState = $this->buildState(['purpose' => 'somethingElse']);
        $this->assertConsentRefused($connectsService, $foreignPurposeState, 'nonce', 'invalid or expired');
        $otherStageState = $this->buildState(['stage' => 'another-stage']);
        $this->assertConsentRefused($connectsService, $otherStageState, 'nonce', 'invalid or expired');
        $validState = $this->buildState([]);
        $this->assertConsentRefused($connectsService, $validState, null, 'another browser');
        $this->assertConsentRefused($connectsService, $validState, 'a-different-nonce', 'another browser');
    }

    protected function assertConsentRefused(AgentConnectionConnectsService $connectsService, string $state, ?string $browserNonce, string $expectedText): void
    {
        try {
            $connectsService->completeOAuthConnect('GOOGLE', $state, 'code', $browserNonce);
            self::fail('the consent must be refused: ' . $expectedText);
        } catch (AgentConnectionRefusedException $refused) {
            self::assertStringContainsString($expectedText, $refused->getMessage());
        }
    }

    /** @param array<string, mixed> $overrides */
    protected function buildState(array $overrides): string
    {
        return JWTPayload::createJWTFromParameters($overrides + [
            'purpose' => AgentConnectionConnectsService::STATE_PURPOSE,
            'agentConnectionId' => 1,
            'accountId' => 1,
            'provider' => 'GOOGLE',
            'stage' => AgentConnectionsConfiguration::current()->getCurrentStage(),
            'browserNonceHash' => hash('sha256', 'the-starting-browser'),
        ], 600);
    }

    protected function buildOpenRequest(): AgentConnectionApprovalRequest
    {
        $agentConnectionApprovalRequest = AgentConnectionApprovalRequest::newInstance();
        $agentConnectionApprovalRequest->id = 501;
        $agentConnectionApprovalRequest->agentConnectionId = 9;
        $agentConnectionApprovalRequest->status = AgentConnectionApprovalRequest::STATUS_OPEN;
        $agentConnectionApprovalRequest->options = AgentConnectionApprovalOptions::approveOrReject();
        $agentConnectionApprovalRequest->expiresAt = (new DateTime())->modify('+1 hour');
        return $agentConnectionApprovalRequest;
    }
}
