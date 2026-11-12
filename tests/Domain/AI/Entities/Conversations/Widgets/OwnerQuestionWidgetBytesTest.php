<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestionOption;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetOrigin;
use DDD\Domain\AI\Entities\Conversations\Widgets\MultiChoiceConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\OwnerQuestionConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\QuickRepliesConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The byte proof: the owner question's widget the platform now writes
 * ({@see OwnerQuestionConversationWidget::forAskOwnerQuestion()}) renders EXACTLY {@see AskOwnerTool::buildWidgetMarkdown()}
 * (the widget the platform wrote before) — without an origin byte for byte, with the writer's origin the same bytes plus
 * `,"emittedBy":"…"` as the block's last field. Every branch of the widget builder is a row: single and multiple choice,
 * descriptions (none, some, all, a "0" the widget's `array_filter` drops), the catalogue key with its meanings and the
 * known gap — a key with NULL meanings, which the widget writes as `"optionMeanings":null` while the base class's
 * null-dropping would leave it out; the widget writes it too.
 */
class OwnerQuestionWidgetBytesTest extends UnitTestCase
{
    /**
     * @param array<int, array{label: string, description: string|null}> $options
     * @param string[]|null $optionMeanings
     */
    protected static function question(string $questionText, array $options, bool $multiSelect = false, ?string $questionKey = null, ?array $optionMeanings = null): AskOwnerQuestion
    {
        $askOwnerQuestion = new AskOwnerQuestion();
        $askOwnerQuestion->question = $questionText;
        foreach ($options as $option) {
            $askOwnerQuestion->options[] = AskOwnerQuestionOption::forLabelAndDescription($option['label'], $option['description']);
        }
        $askOwnerQuestion->multiSelect = $multiSelect;
        $askOwnerQuestion->questionKey = $questionKey;
        $askOwnerQuestion->optionMeanings = $optionMeanings;
        return $askOwnerQuestion;
    }

    /** @return array<string, array{0: AskOwnerQuestion}> */
    public static function questions(): array
    {
        $plain = [['label' => 'Red', 'description' => null], ['label' => 'Blue', 'description' => null]];
        $someDescribed = [['label' => 'Red', 'description' => 'Warm and loud'], ['label' => 'Blue', 'description' => null]];
        $allDescribed = [['label' => 'Ja, „so“', 'description' => 'Über /Pfade & "Zitate"'], ['label' => 'Nein', 'description' => 'Später']];
        $zeroDescribed = [['label' => 'Red', 'description' => '0'], ['label' => 'Blue', 'description' => null]];
        return [
            'single choice, no descriptions' => [self::question('Which colour should the posts use?', $plain)],
            'single choice, one description' => [self::question('Which colour?', $someDescribed)],
            'single choice, all described, unicode and slashes' => [self::question('Passt das so?', $allDescribed)],
            'single choice, a "0" description (dropped by the widget)' => [self::question('Which colour?', $zeroDescribed)],
            'single choice with a catalogue key and meanings' => [self::question('Do the sets fit?', [['label' => 'Fits', 'description' => null], ['label' => 'Change', 'description' => null]], false, 'setsApproval', ['approved', 'change'])],
            'the known gap: a key with NULL meanings' => [self::question('Do the sets fit?', $plain, false, 'setsApproval', null)],
            'meanings without a key (not written)' => [self::question('Which colour?', $plain, false, null, ['approved', 'change'])],
            'a question line with surrounding blanks' => [self::question("  Which colour?\n", $plain)],
            'an empty question (no line above the block)' => [self::question('', $plain)],
            'multiple choice, no descriptions' => [self::question('Which channels?', [['label' => 'Facebook', 'description' => null], ['label' => 'Instagram', 'description' => null]], true)],
            'multiple choice with sublabels' => [self::question('Which channels?', $someDescribed, true)],
        ];
    }

    #[DataProvider('questions')]
    public function testTheWidgetWithoutAnOriginIsTheModelFacingWidgetByteForByte(AskOwnerQuestion $askOwnerQuestion): void
    {
        $ownerQuestionWidget = OwnerQuestionConversationWidget::forAskOwnerQuestion($askOwnerQuestion);

        self::assertInstanceOf($askOwnerQuestion->multiSelect ? MultiChoiceConversationWidget::class : QuickRepliesConversationWidget::class, $ownerQuestionWidget);
        self::assertSame(AskOwnerTool::buildWidgetMarkdown($askOwnerQuestion), $ownerQuestionWidget->buildWidgetRowEmittedBy(ConversationWidgetOrigin::model()));
    }

    #[DataProvider('questions')]
    public function testTheWrittenWidgetIsTheModelFacingWidgetPlusEmittedByAsTheLastField(AskOwnerQuestion $askOwnerQuestion): void
    {
        $widgetMarkdown = AskOwnerTool::buildWidgetMarkdown($askOwnerQuestion);
        foreach ([ConversationWidgetOrigin::tool(AskOwnerTool::NAME), ConversationWidgetOrigin::platform('competitorSetsQuestion')] as $origin) {
            $writtenRow = OwnerQuestionConversationWidget::forAskOwnerQuestion($askOwnerQuestion)->buildWidgetRowEmittedBy($origin);

            $expectedRow = substr($widgetMarkdown, 0, (int)strrpos($widgetMarkdown, "}\n```")) . ',"emittedBy":"' . $origin->toEmittedByValue() . '"}' . "\n```";
            self::assertSame($expectedRow, $writtenRow, 'the widget\'s bytes, the field appended as the last one');
            self::assertSame($widgetMarkdown, WidgetBlocks::fromMarkdown($writtenRow)->buildMarkdownWithoutEmittedBy(), 'the model-facing strip gives the widget back');
            self::assertSame($origin->toEmittedByValue(), WidgetBlocks::fromMarkdown($writtenRow)->findFirstBlockOfTypes(QuickRepliesConversationWidget::WIDGET_TYPE, MultiChoiceConversationWidget::WIDGET_TYPE)?->getOrigin()->toEmittedByValue(), 'the block names its origin');
        }
    }

    public function testTheSingleChoiceFactoryAndTheDispatcherAgree(): void
    {
        $askOwnerQuestion = self::question('Which colour?', [['label' => 'Red', 'description' => null], ['label' => 'Blue', 'description' => 'Calm']]);

        self::assertSame(
            OwnerQuestionConversationWidget::forAskOwnerQuestion($askOwnerQuestion)->buildWidgetBlock(),
            QuickRepliesConversationWidget::fromAskOwnerQuestion($askOwnerQuestion)->buildWidgetBlock()
        );
    }
}
