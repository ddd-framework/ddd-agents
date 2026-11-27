<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Widgets\ExtendConnectionPermissionConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ShownConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperation;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperationsService;
use DDD\Domain\AI\Services\Connections\Installation\AgentConnectionsFeatureTableConditionProviderService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use PHPUnit\Framework\TestCase;

/**
 * The owner widgets of a refused step (none in a chat thread, one per connection and capability) and the installation
 * check's table conditions of AgentConnections.
 */
class AgentConnectionWidgetsAndInstallationTest extends TestCase
{
    public function testATurnThatCameInThroughAChatConnectionGetsNoButton(): void
    {
        self::assertTrue(AgentConnectionOperationsService::isChatConnectionTurn($this->buildOperation(OwnerDevice::KIND_CHAT_CONNECTION)));
        self::assertFalse(AgentConnectionOperationsService::isChatConnectionTurn($this->buildOperation(OwnerDevice::KIND_WEB_BROWSER)));
        self::assertFalse(AgentConnectionOperationsService::isChatConnectionTurn($this->buildOperation(null)));
    }

    public function testAShownButtonCountsOnlyForItsOwnConnectionAndCapability(): void
    {
        $shownWidget = new ShownConversationWidget();
        $shownWidget->widgetType = ExtendConnectionPermissionConversationWidget::WIDGET_TYPE;
        $shownWidget->widgetBlock = new WidgetBlock();
        $shownWidget->widgetBlock->widgetJson = (string)json_encode(['type' => ExtendConnectionPermissionConversationWidget::WIDGET_TYPE, 'connectionId' => 3, 'capability' => 'MAIL', 'level' => 'SEND']);

        self::assertTrue(AgentConnectionOperationsService::shownWidgetMatches($shownWidget, ['connectionId' => 3, 'capability' => 'MAIL']));
        self::assertFalse(AgentConnectionOperationsService::shownWidgetMatches($shownWidget, ['connectionId' => 4, 'capability' => 'MAIL']), 'a second connection gets its own button');
        self::assertFalse(AgentConnectionOperationsService::shownWidgetMatches($shownWidget, ['connectionId' => 3, 'capability' => 'CALENDAR']), 'another capability gets its own button');
        self::assertTrue(AgentConnectionOperationsService::shownWidgetMatches($shownWidget, []), 'a widget without matching fields is one per type');
    }

    public function testEveryAgentConnectionsTableIsRequiredOnlyWhileTheFeatureIsOn(): void
    {
        $repositoryDirectory = dirname(__DIR__, 4) . '/src/Domain/AI/Repo/DB/Connections';
        $entityClassNames = [];
        foreach (glob($repositoryDirectory . '/DB*.php') ?: [] as $repositoryFile) {
            $repositoryClassName = 'DDD\\Domain\\AI\\Repo\\DB\\Connections\\' . basename($repositoryFile, '.php');
            if (is_subclass_of($repositoryClassName, DBEntity::class)) {
                $entityClassNames[] = $repositoryClassName::BASE_ENTITY_CLASS;
            }
        }
        sort($entityClassNames);
        $conditionClassNames = AgentConnectionsFeatureTableConditionProviderService::TABLE_ENTITY_CLASSES;
        sort($conditionClassNames);

        self::assertNotSame([], $entityClassNames);
        self::assertSame($entityClassNames, $conditionClassNames, 'every database entity of AgentConnections has its condition');
        foreach ((new AgentConnectionsFeatureTableConditionProviderService())->getFeatureTableConditions()->getElements() as $agentFeatureTableCondition) {
            self::assertFalse((bool)$agentFeatureTableCondition->isFeatureSwitchedOn, 'the feature is off in the package suite');
        }
    }

    protected function buildOperation(?string $ownerDeviceKind): AgentConnectionOperation
    {
        $sourceUserMessage = AIConversationMessage::newInstance();
        if ($ownerDeviceKind !== null) {
            $ownerDevice = new OwnerDevice();
            $ownerDevice->kind = $ownerDeviceKind;
            $sourceUserMessage->ownerDevice = $ownerDevice;
        }
        $agentConnection = AgentConnection::newInstance();
        $agentConnection->id = 3;
        return AgentConnectionOperation::create($agentConnection, AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionAction::OPERATION_SEND, 'sendMail', 1, new AgentToolCallProvenance(sourceUserMessage: $sourceUserMessage, conversationId: 50));
    }
}
