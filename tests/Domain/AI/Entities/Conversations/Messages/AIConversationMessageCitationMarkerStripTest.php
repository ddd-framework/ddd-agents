<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The model's citation markers leaked into owner text — the private-use
 * span `\u{E200}cite\u{E202}turn1search0\u{E201}` and `⟦widget⟧`. {@see AIConversationMessage::stripModelCitationMarkers()}
 * removes exactly those characters; prose and fenced widget JSON stay as they were. Also: a
 * bare `_widget` token glued before a ```widget fence (`…aktuell._widget`) goes too.
 */
class AIConversationMessageCitationMarkerStripTest extends UnitTestCase
{
    public function testThePrivateUseCitationSpanAndTheSpaceBeforeItAreRemoved(): void
    {
        $answerText = "Die Praxis hat montags bis freitags geöffnet \u{E200}cite\u{E202}turn1search0\u{E201}. Termine gibt es online.";

        self::assertSame('Die Praxis hat montags bis freitags geöffnet. Termine gibt es online.', AIConversationMessage::stripModelCitationMarkers($answerText));
    }

    public function testSeveralSpansAndAStrayPrivateUseCharacterAreRemoved(): void
    {
        $answerText = "Erster Punkt \u{E200}cite\u{E202}turn1search0\u{E202}turn2search3\u{E201}\nZweiter Punkt\u{E203} \u{E200}cite\u{E202}turn3news1\u{E201}";

        self::assertSame("Erster Punkt\nZweiter Punkt", AIConversationMessage::stripModelCitationMarkers($answerText));
    }

    public function testABracketTokenIsRemoved(): void
    {
        self::assertSame('Hier sind Deine neuesten Bewertungen:', AIConversationMessage::stripModelCitationMarkers('Hier sind Deine neuesten Bewertungen: ⟦widget⟧'));
    }

    public function testFencedWidgetJsonKeepsEverythingButTheMarkers(): void
    {
        $widgetBlock = "```widget\n{\"type\":\"ComposedView\",\"view\":{\"type\":\"Text\",\"text\":\"3   of 4 done\"}}\n```";
        $answerText = "Kurz vorweg:\n\n" . $widgetBlock;

        self::assertSame($answerText, AIConversationMessage::stripModelCitationMarkers($answerText));
        self::assertSame(
            "```widget\n{\"type\":\"ComposedView\",\"view\":{\"type\":\"Text\",\"text\":\"3   of 4 done\"}}\n```",
            AIConversationMessage::stripModelCitationMarkers("```widget\n{\"type\":\"ComposedView\",\"view\":{\"type\":\"Text\",\"text\":\"3   of 4 done \u{E200}cite\u{E202}turn0search1\u{E201}\"}}\n```")
        );
    }

    public function testAWidgetTokenGluedBeforeAWidgetFenceIsRemoved(): void
    {
        $widgetBlock = "```widget\n{\"type\":\"ComposedView\",\"view\":{\"type\":\"Text\",\"text\":\"4,8\"}}\n```";

        self::assertSame("Die wichtigsten Zahlen aktuell.\n" . $widgetBlock, AIConversationMessage::stripModelCitationMarkers("Die wichtigsten Zahlen aktuell._widget\n" . $widgetBlock));
        self::assertSame("Die wichtigsten Zahlen:\n" . $widgetBlock, AIConversationMessage::stripModelCitationMarkers("Die wichtigsten Zahlen:widget \n" . $widgetBlock));
        self::assertSame("Kurz vorweg.\n\n" . $widgetBlock, AIConversationMessage::stripModelCitationMarkers("Kurz vorweg.\nwidget\n" . $widgetBlock));
    }

    public function testTheWordWidgetInProseOrAwayFromAFenceStays(): void
    {
        $widgetBlock = "```widget\n{\"type\":\"Clock\"}\n```";
        foreach ([
            "Hier ist das widget\n" . $widgetBlock,
            "Das Feld heißt user_widget\n" . $widgetBlock,
            "Der Wert war._widget\nund mehr Text.\n\n" . $widgetBlock,
            "Ein Satz._widget\n```json\n{}\n```",
        ] as $answerText) {
            self::assertSame($answerText, AIConversationMessage::stripModelCitationMarkers($answerText));
        }
    }

    public function testATextWithoutMarkersIsReturnedUnchanged(): void
    {
        $answerText = "  Zwei Leerzeichen davor, [eckige] Klammern und ⟨spitze⟩ bleiben.\n";

        self::assertSame($answerText, AIConversationMessage::stripModelCitationMarkers($answerText));
    }
}
