<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanChannelConnectionStatesFixture;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanItemOfAnApplicationWithAGuidedSetup;

/**
 * A `done` tick on a connect item is accepted only when the channel is CONNECTED on the record:
 * a rendered widget satisfies the widget gate at exactly the moment the item is not done. Covers the tick gate, the
 * full-replace degrade, the seed round trip and the resend carry. The module declares no connection: the tests run with
 * the item class of an application that declares two.
 */
class TaskPlanConnectedChannelGateTest extends UnitTestCase
{
    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[TaskPlanItem::class] = TaskPlanItemOfAnApplicationWithAGuidedSetup::class;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::tearDown();
    }

    protected function message(int $index, string $type, string $text, ?string $role, ?string $authorType = null, ?string $signalSource = null): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = $type;
        $message->message = $text;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->signalSource = $signalSource;
        $message->toolCall = null;
        return $message;
    }

    protected function messageSet(array $messages): AIConversationMessages
    {
        $messageSet = self::instantiateWithoutConstructor(AIConversationMessages::class);
        self::setPropertyValue($messageSet, 'elements', $messages);
        self::setPropertyValue($messageSet, 'elementCount', count($messages));
        return $messageSet;
    }

    protected function withRenderedWidget(): AIConversationMessages
    {
        return $this->messageSet([
            $this->message(1, AIConversationMessage::MESSAGE_TEXT, 'hallo', AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER),
            $this->message(2, AIConversationMessage::MESSAGE_TEXT, "Connect here:\n```widget\n{\"type\": \"ChannelConnections\", \"shopId\": 1}\n```", AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT),
        ]);
    }

    protected function planWithConnectItem(): TaskPlan
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Google connect offer', 'doneRequiresWidget' => 'ChannelConnections', 'doneRequiresConnectedChannel' => 'google', 'deferrableAtSetupEnd' => true],
            ['text' => 'Keywords'],
        ]);
        $taskPlan = new TaskPlan();
        $taskPlan->items = $taskPlanItems;
        return $taskPlan;
    }

    protected function tick(TaskPlan $taskPlan, ?TaskPlanChannelConnectionStatesFixture $channelConnectionStates, string $status = 'done'): array
    {
        return SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => $status, 'statusNote' => 'widget rendered, nobody clicked', 'requiresUserAction' => $status === 'blocked']]],
            $taskPlan,
            1,
            $this->withRenderedWidget(),
            1,
            [],
            channelConnectionStates: $channelConnectionStates
        );
    }

    public function testSeedRoundTripAndResendCarryKeepTheGate(): void
    {
        $item = $this->planWithConnectItem()->items->getElements()[0];
        self::assertSame(TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_GOOGLE, $item->doneRequiresConnectedChannel);
        self::assertTrue($item->carriesEvidenceGate());
        $roundTrip = TaskPlanItem::fromRepositoryFields($item->toRepositoryFields());
        self::assertSame('google', $roundTrip->doneRequiresConnectedChannel);
        self::assertNull(TaskPlanItems::fromPurposeSeedDefinition([['text' => 'x', 'doneRequiresConnectedChannel' => 'linkedin']])->getElements()[0]->doneRequiresConnectedChannel);
    }

    public function testDoneOnAnUnconnectedChannelIsNotedWithoutAPendingNote(): void
    {
        $taskPlan = $this->planWithConnectItem();
        $result = $this->tick($taskPlan, new TaskPlanChannelConnectionStatesFixture([TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_GOOGLE => false]));
        self::assertSame(0, $result['updatedCount']);
        self::assertSame(1, $result['notedCount']);
        self::assertStringContainsString('NOT connected', implode(' ', $result['feedback']));
        self::assertStringContainsString('awaiting', implode(' ', $result['feedback']));
        $item = $taskPlan->items->getElements()[0];
        self::assertSame(TaskPlanItem::STATUS_OPEN, $item->status);
    }

    public function testDoneOnAConnectedChannelIsApplied(): void
    {
        $taskPlan = $this->planWithConnectItem();
        $result = $this->tick($taskPlan, new TaskPlanChannelConnectionStatesFixture([TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_GOOGLE => true, TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_SOCIAL => false]));
        self::assertSame(1, $result['updatedCount']);
        self::assertSame(TaskPlanItem::STATUS_DONE, $taskPlan->items->getElements()[0]->status);
    }

    public function testUnreadableStateIsRejectedWithTheUnreadableText(): void
    {
        $taskPlan = $this->planWithConnectItem();
        $result = $this->tick($taskPlan, new TaskPlanChannelConnectionStatesFixture([]));
        self::assertSame(0, $result['updatedCount']);
        self::assertStringContainsString('could not be read', implode(' ', $result['feedback']));
        self::assertSame(TaskPlanItem::STATUS_OPEN, $taskPlan->items->getElements()[0]->status);
    }

    public function testWithoutARenderedWidgetTheWidgetCorrectiveWinsAndTheResolverIsNeverAsked(): void
    {
        $taskPlan = $this->planWithConnectItem();
        $channelConnectionStates = new TaskPlanChannelConnectionStatesFixture([TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_GOOGLE => false, TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_SOCIAL => false]);
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'x']]],
            $taskPlan,
            1,
            $this->messageSet([$this->message(1, AIConversationMessage::MESSAGE_TEXT, 'hallo', AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER)]),
            1,
            [],
            channelConnectionStates: $channelConnectionStates
        );
        self::assertSame(1, $result['notedCount']);
        self::assertStringContainsString('widget block', implode(' ', $result['feedback']));
        self::assertSame(TaskPlanItem::STATUS_OPEN, $taskPlan->items->getElements()[0]->status, 'the refused tick leaves the connect leaf open');
        self::assertSame([], $channelConnectionStates->askedChannels);
    }

    public function testBlockedDeferralAndItemsWithoutTheFieldNeverAskTheResolver(): void
    {
        $taskPlan = $this->planWithConnectItem();
        $channelConnectionStates = new TaskPlanChannelConnectionStatesFixture([TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_GOOGLE => false, TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_SOCIAL => false]);
        $blocked = $this->tick($taskPlan, $channelConnectionStates, 'blocked');
        self::assertSame(1, $blocked['updatedCount']);
        self::assertSame(TaskPlanItem::STATUS_BLOCKED, $taskPlan->items->getElements()[0]->status);
        $keywords = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '2', 'status' => 'done', 'statusNote' => 'saved']]],
            $taskPlan,
            1,
            $this->withRenderedWidget(),
            1,
            [],
            channelConnectionStates: $channelConnectionStates
        );
        self::assertSame(1, $keywords['updatedCount']);
        self::assertSame([], $channelConnectionStates->askedChannels);
    }

    /** Whether the newest row is the owner's widget click, as the turn-start sweep reads it ({@see AIConversationMessages::buildOwnerReactionOfNewestRow()}). */
    protected static function newestRowIsAWidgetClick(AIConversationMessages $messages): bool
    {
        return $messages->buildOwnerReactionOfNewestRow()?->isWidgetClickSignal() === true;
    }

    public function testTheNewestFrontendWidgetSignalIsDetected(): void
    {
        $signalLast = $this->messageSet([
            $this->message(1, AIConversationMessage::MESSAGE_TEXT, 'hallo', AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER),
            $this->message(2, AIConversationMessage::MESSAGE_SIGNAL, 'Google connected', AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK),
        ]);
        self::assertTrue(self::newestRowIsAWidgetClick($signalLast));
        self::assertFalse(self::newestRowIsAWidgetClick($this->withRenderedWidget()));
        $navigationLast = $this->messageSet([
            $this->message(1, AIConversationMessage::MESSAGE_SIGNAL, 'back', AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_NAVIGATION),
        ]);
        self::assertFalse(self::newestRowIsAWidgetClick($navigationLast));
    }
}
