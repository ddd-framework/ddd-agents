<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCalls;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The response-only half of the loop's stall classification, on the model response itself. */
class ModelResponseStallClassificationTest extends UnitTestCase
{
    public function testAWidgetOnlyAnswerShowsNothingAndCountsAsEmpty(): void
    {
        $widgetOnly = new ModelResponse("```widget\n{\"type\":\"ReviewsList\"}\n```");
        self::assertSame('', $widgetOnly->getVisibleProse());
        self::assertTrue($widgetOnly->emitsNoOutput());

        $introduced = new ModelResponse("Hier sind deine Bewertungen:\n```widget\n{\"type\":\"ReviewsList\"}\n```");
        self::assertSame('Hier sind deine Bewertungen:', $introduced->getVisibleProse());
        self::assertFalse($introduced->emitsNoOutput());
    }

    public function testATurnWithAToolCallIsNeverEmpty(): void
    {
        $toolCalls = new ToolCalls();
        $toolCall = new ToolCall();
        $toolCall->name = 'getKeywords';
        $toolCalls->add($toolCall);
        self::assertFalse((new ModelResponse('', $toolCalls))->emitsNoOutput());
    }

    public function testThePreCallTokenInEveryFormItLeaksIn(): void
    {
        self::assertTrue((new ModelResponse(AIConversationMessage::PRE_CALL_MESSAGE_MARKER . ' Ich lade deine Rankings'))->visibleProseCarriesPreCallToken());
        self::assertTrue((new ModelResponse('{"preCallMessage": "Ich lade", "name": "getRankings"}'))->visibleProseCarriesPreCallToken());
        self::assertTrue((new ModelResponse('Ich lade <|im_call:getRankings'))->visibleProseCarriesPreCallToken());
        self::assertFalse((new ModelResponse('Deine Rankings sind gestiegen.'))->visibleProseCarriesPreCallToken());
        self::assertFalse((new ModelResponse("```\n" . AIConversationMessage::PRE_CALL_MESSAGE_MARKER . "\n```"))->visibleProseCarriesPreCallToken(), 'inside a fenced block it is no visible prose');
    }
}
