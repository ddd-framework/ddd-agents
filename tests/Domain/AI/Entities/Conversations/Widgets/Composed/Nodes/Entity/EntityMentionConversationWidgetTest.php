<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Entity;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\EntityMentionConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `EntityMention` widget class: the block the owner's composer writes (`{type, entityType, entityId,
 * label, agentSpaceId}`, the frontend's field order) is read back into the entity it names; an id written as a JSON
 * integer is read as its digits; a block without a valid type or id names no entity. The class is in the registry.
 * (Lives next to the `EntityLink` node test.)
 */
class EntityMentionConversationWidgetTest extends UnitTestCase
{
    public function testTheComposerBlockIsReadBackIntoTheEntityItNames(): void
    {
        $entityMentionConversationWidget = $this->readFirstBlock('{"type":"EntityMention","entityType":"competitor","entityId":"8812","label":"Altes Gasthaus Leve","agentSpaceId":5100107}');
        self::assertTrue($entityMentionConversationWidget->namesAnEntity());
        self::assertSame('Altes Gasthaus Leve', $entityMentionConversationWidget->label);
        self::assertSame(['entityType' => 'competitor', 'entityId' => '8812', 'agentSpaceId' => 5100107], $entityMentionConversationWidget->buildEntityReferenceRow()?->toWireArray());
    }

    public function testAnIntegerIdIsReadAsItsDigits(): void
    {
        $entityMentionConversationWidget = $this->readFirstBlock('{"type":"EntityMention","entityType":"review","entityId":4711}');
        self::assertSame('4711', $entityMentionConversationWidget->entityId);
        self::assertNull($entityMentionConversationWidget->buildEntityReferenceRow()?->widgetSelfHydrationKey);
    }

    public function testABlockWithoutAValidTypeOrIdNamesNoEntity(): void
    {
        self::assertNull($this->readFirstBlock('{"type":"EntityMention","entityType":"competitor"}')->buildEntityReferenceRow());
        self::assertNull($this->readFirstBlock('{"type":"EntityMention","entityType":"no type!","entityId":"1"}')->buildEntityReferenceRow());
        self::assertNull($this->readFirstBlock('{"type":"EntityMention","entityType":"review","entityId":"../etc"}')->buildEntityReferenceRow());
    }

    public function testTheClassWritesItsBlockAndIsRegistered(): void
    {
        $entityMentionConversationWidget = new EntityMentionConversationWidget();
        $entityMentionConversationWidget->entityType = 'keyword';
        $entityMentionConversationWidget->entityId = '55';
        $entityMentionConversationWidget->widgetSelfHydrationKey = 7;
        self::assertSame("```widget\n{\"type\":\"EntityMention\",\"agentSpaceId\":7,\"entityType\":\"keyword\",\"entityId\":\"55\"}\n```", $entityMentionConversationWidget->buildWidgetBlock());
        self::assertSame(EntityMentionConversationWidget::class, ConversationWidgetClasses::getWidgetClassForWidgetType('EntityMention'));
        self::assertFalse(ConversationWidgetClasses::isWidgetTypeAwaitingOwnerInput('EntityMention'));
    }

    protected function readFirstBlock(string $widgetJson): EntityMentionConversationWidget
    {
        $widgetBlock = WidgetBlocks::fromMarkdown("Why is their rating better?\n\n```widget\n" . $widgetJson . "\n```")->findFirstBlockOfTypes('EntityMention');
        self::assertNotNull($widgetBlock);
        return EntityMentionConversationWidget::fromWidgetBlock($widgetBlock);
    }
}
