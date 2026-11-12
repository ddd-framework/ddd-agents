<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewKitSpec;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceRow;
use DDD\Domain\AI\Entities\Conversations\Widgets\EntityMentionConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\QuickRepliesConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNames;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockSerializer;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetLeafSyntaxParser;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The block field of the widget's self-hydration key and the suffix of a click on another space's widget are named
 * by the application: without an application class the module writes and reads `agentSpaceId` and knows no suffix;
 * with one, every writer and reader of a block uses the application's names and nothing else of the block changes.
 */
class WidgetBlockFieldNamesTest extends UnitTestCase
{
    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::tearDown();
    }

    public function testWithoutAnApplicationClassTheKeyTravelsAsAgentSpaceIdAndNoSuffixIsRead(): void
    {
        StaticRegistry::$frameworkToAppClassNames[WidgetBlockFieldNames::class] = WidgetBlockFieldNames::class;

        self::assertSame('agentSpaceId', WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName());
        self::assertNull(WidgetBlockFieldNames::getOwnerTextSuffixPatternOfAnotherSpace());
        self::assertSame("```widget\n{\"type\":\"EntityMention\",\"agentSpaceId\":7,\"entityType\":\"keyword\",\"entityId\":\"55\"}\n```", $this->buildEntityMentionWidgetOfSpaceKey(7)->buildWidgetBlock());
        self::assertSame(7, $this->readEntityMentionWidget('{"type":"EntityMention","entityType":"keyword","entityId":"55","agentSpaceId":7}')->widgetSelfHydrationKey);
        self::assertNull($this->readEntityMentionWidget('{"type":"EntityMention","entityType":"keyword","entityId":"55","shopId":7}')->widgetSelfHydrationKey);
        self::assertSame(['entityType' => 'keyword', 'entityId' => '55', 'agentSpaceId' => 7], EntityReferenceRow::create('keyword', '55', 7)->toWireArray());
        self::assertSame('{"days":3,"type":"Clock","agentSpaceId":"7"}', WidgetLeafSyntaxParser::decodeLeafAttributes('Clock', "{payload='{\"days\":3}' agentSpaceId=7}"));
        self::assertNull(WidgetLeafSyntaxParser::decodeLeafAttributes('Clock', "{payload='{\"days\":3}' shopId=7}"));

        $ownerQuestionPick = $this->buildQuickRepliesWidgetOfSpaceKey(7)->buildPickFromOwnerText('Yes (for shop 7)');
        self::assertNotNull($ownerQuestionPick);
        self::assertNull($ownerQuestionPick->foreignWidgetSelfHydrationKey);
        self::assertSame([], $ownerQuestionPick->labels, 'the text with the suffix is no option of the widget');
    }

    public function testAnApplicationClassNamesTheFieldAndTheSuffixForEveryWriterAndReader(): void
    {
        StaticRegistry::$frameworkToAppClassNames[WidgetBlockFieldNames::class] = WidgetBlockFieldNamesOfAnApplicationWithItsOwnNames::class;

        self::assertSame('shopId', WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName());
        self::assertSame("```widget\n{\"type\":\"EntityMention\",\"shopId\":7,\"entityType\":\"keyword\",\"entityId\":\"55\"}\n```", $this->buildEntityMentionWidgetOfSpaceKey(7)->buildWidgetBlock());
        self::assertSame("```widget\n{\"type\":\"Clock\",\"shopId\":7,\"days\":3}\n```", WidgetBlockSerializer::buildFenceBlock('Clock', 7, ['days' => 3]));
        self::assertSame(7, $this->readEntityMentionWidget('{"type":"EntityMention","entityType":"keyword","entityId":"55","shopId":7}')->widgetSelfHydrationKey);
        self::assertSame(['entityType' => 'keyword', 'entityId' => '55', 'shopId' => 7], EntityReferenceRow::create('keyword', '55', 7)->toWireArray());
        self::assertSame('{"days":3,"type":"Clock","shopId":"7"}', WidgetLeafSyntaxParser::decodeLeafAttributes('Clock', "{payload='{\"days\":3}' shopId=7}"));
        self::assertStringContainsString('plus the shopId where listed', ComposedViewKitSpec::build());

        $ownerQuestionPick = $this->buildQuickRepliesWidgetOfSpaceKey(7)->buildPickFromOwnerText('Yes (for shop 7)');
        self::assertNotNull($ownerQuestionPick);
        self::assertSame(7, $ownerQuestionPick->foreignWidgetSelfHydrationKey);
        self::assertSame(['Yes'], $ownerQuestionPick->labels);

        $pickOnAnotherKey = $this->buildQuickRepliesWidgetOfSpaceKey(7)->buildPickFromOwnerText('Yes (for shop 8)');
        self::assertNotNull($pickOnAnotherKey);
        self::assertNull($pickOnAnotherKey->foreignWidgetSelfHydrationKey, 'the suffix names another key than the widget carries');
    }

    protected function buildEntityMentionWidgetOfSpaceKey(int $widgetSelfHydrationKey): EntityMentionConversationWidget
    {
        $entityMentionConversationWidget = new EntityMentionConversationWidget();
        $entityMentionConversationWidget->entityType = 'keyword';
        $entityMentionConversationWidget->entityId = '55';
        $entityMentionConversationWidget->widgetSelfHydrationKey = $widgetSelfHydrationKey;
        return $entityMentionConversationWidget;
    }

    protected function readEntityMentionWidget(string $widgetJson): EntityMentionConversationWidget
    {
        $widgetBlock = WidgetBlocks::fromMarkdown("A keyword:\n\n```widget\n" . $widgetJson . "\n```")->findFirstBlockOfTypes('EntityMention');
        self::assertNotNull($widgetBlock);
        return EntityMentionConversationWidget::fromWidgetBlock($widgetBlock);
    }

    protected function buildQuickRepliesWidgetOfSpaceKey(int $widgetSelfHydrationKey): QuickRepliesConversationWidget
    {
        $quickRepliesConversationWidget = new QuickRepliesConversationWidget();
        $quickRepliesConversationWidget->options = ['Yes', 'No'];
        $quickRepliesConversationWidget->widgetSelfHydrationKey = $widgetSelfHydrationKey;
        return $quickRepliesConversationWidget;
    }
}
