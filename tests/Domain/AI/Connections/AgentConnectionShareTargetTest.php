<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Conversations\Widgets\ContinueInPersonalSpaceConversationWidget;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Tests\Agents\Support\Connections\ShareTargetAgentConnectionsService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The space a connection is shared with (also the visibility chosen before an OAuth consent, on a connection that is
 * not stored yet) and the button that continues a refused request in the owner's personal space.
 */
class AgentConnectionShareTargetTest extends UnitTestCase
{
    public function testWithoutANamedSpaceTheOnlyOfferedSpaceIsTaken(): void
    {
        $agentConnectionsService = $this->buildServiceOffering([13]);
        self::assertSame(13, $agentConnectionsService->resolveShareTarget($this->buildOwnConnection(1), 1, null));
    }

    public function testWithoutANamedSpaceSeveralOrNoOfferedSpacesAreRefused(): void
    {
        foreach ([[], [13, 14]] as $shareableAgentSpaceIds) {
            $agentConnectionsService = $this->buildServiceOffering($shareableAgentSpaceIds);
            try {
                $agentConnectionsService->resolveShareTarget($this->buildOwnConnection(1), 1, null);
                self::fail('a share without a named space needs exactly one offered space');
            } catch (AgentConnectionRefusedException $refused) {
                self::assertStringContainsString('Name the space', $refused->getMessage());
            }
        }
    }

    public function testASpaceThatIsNotOfferedOrAForeignConnectionIsRefused(): void
    {
        $agentConnectionsService = $this->buildServiceOffering([13]);
        self::assertSame(13, $agentConnectionsService->resolveShareTarget($this->buildOwnConnection(1), 1, 13));
        $this->expectException(AgentConnectionRefusedException::class);
        $agentConnectionsService->resolveShareTarget($this->buildOwnConnection(1), 1, 7);
    }

    public function testOnlyTheOwnerSharesAConnection(): void
    {
        $agentConnectionsService = $this->buildServiceOffering([13]);
        $this->expectException(AgentConnectionRefusedException::class);
        $agentConnectionsService->resolveShareTarget($this->buildOwnConnection(2), 1, 13);
    }

    public function testTheContinueButtonCarriesTheRequestAndNothingElse(): void
    {
        $continueWidget = ContinueInPersonalSpaceConversationWidget::forRequest('  ' . str_repeat('a', 2100) . '  ', 'Only for you.');
        self::assertSame(2000, mb_strlen($continueWidget->request), 'the request is trimmed and capped');
        $widgetBlock = $continueWidget->buildWidgetBlock();
        self::assertStringContainsString('"type":"ContinueInPersonalSpace"', $widgetBlock);
        self::assertStringContainsString('"reason":"Only for you."', $widgetBlock);
        self::assertStringNotContainsString('"label"', $widgetBlock, 'the label is the frontend\'s own text');
    }

    /** @param int[] $shareableAgentSpaceIds */
    protected function buildServiceOffering(array $shareableAgentSpaceIds): ShareTargetAgentConnectionsService
    {
        $agentConnectionsService = new ShareTargetAgentConnectionsService();
        $agentConnectionsService->shareableAgentSpaceIds = $shareableAgentSpaceIds;
        return $agentConnectionsService;
    }

    protected function buildOwnConnection(int $ownerAccountId): AgentConnection
    {
        $agentConnection = AgentConnection::newInstance();
        $agentConnection->ownership = AgentConnection::OWNERSHIP_ACCOUNT_GRANTED;
        $agentConnection->provider = AgentConnection::PROVIDER_FAKE;
        $agentConnection->accountId = $ownerAccountId;
        return $agentConnection;
    }
}
