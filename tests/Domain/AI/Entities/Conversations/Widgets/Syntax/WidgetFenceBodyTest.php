<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockSourceText;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetFenceBody;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetFenceSyntaxParser;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The lines of the source text and the body of a fence are value objects: each line keeps its text with the line
 * ending and its byte offset, a fence body its end, JSON, closure and last line, and builds the block match.
 */
class WidgetFenceBodyTest extends UnitTestCase
{
    public function testEachLineKeepsItsLineEndingAndItsByteOffset(): void
    {
        $widgetBlockSourceText = WidgetBlockSourceText::fromMarkdown("Intro\r\n```widget\n{\"type\":\"PostsList\"}\n```");

        // the split ends with an empty line at the end of the text
        self::assertSame(5, $widgetBlockSourceText->getLineCount());
        self::assertSame("Intro\r\n", $widgetBlockSourceText->getLine(0));
        self::assertSame(0, $widgetBlockSourceText->getLineStart(0));
        self::assertSame("```widget\n", $widgetBlockSourceText->getLine(1));
        self::assertSame(7, $widgetBlockSourceText->getLineStart(1));
        self::assertSame('```', $widgetBlockSourceText->getLine(3));
        self::assertSame(38, $widgetBlockSourceText->getLineStart(3));
        self::assertSame('', $widgetBlockSourceText->getLine(4));
        self::assertSame(41, $widgetBlockSourceText->getLineStart(4));
    }

    public function testAClosedFenceBodyEndsAfterItsCloserLine(): void
    {
        $markdown = "```widget\n{\"type\":\"PostsList\"}\n```\nAfter";
        $widgetFenceBody = WidgetFenceSyntaxParser::readFenceBody(WidgetBlockSourceText::fromMarkdown($markdown), 1, '```', true);

        self::assertSame("{\"type\":\"PostsList\"}\n", $widgetFenceBody->widgetJson);
        self::assertTrue($widgetFenceBody->isClosed);
        self::assertSame(2, $widgetFenceBody->lastLineIndex);
        self::assertSame(strlen("```widget\n{\"type\":\"PostsList\"}\n```\n"), $widgetFenceBody->end);
    }

    public function testARepairedFenceBodyEndsAfterItsJsonObjectAndAnUnrepairableOneRunsToTheEnd(): void
    {
        $markdown = "```widget\n{\"type\":\"PostsList\"}\nMore prose.";
        $widgetBlockSourceText = WidgetBlockSourceText::fromMarkdown($markdown);

        $repairedFenceBody = WidgetFenceSyntaxParser::readFenceBody($widgetBlockSourceText, 1, '```', true);
        self::assertSame('{"type":"PostsList"}', $repairedFenceBody->widgetJson);
        self::assertTrue($repairedFenceBody->isClosed);
        self::assertSame(1, $repairedFenceBody->lastLineIndex);
        self::assertSame(strpos($markdown, '}') + 1, $repairedFenceBody->end);

        $unclosedFenceBody = WidgetFenceSyntaxParser::readFenceBody($widgetBlockSourceText, 1, '```', false);
        self::assertSame("{\"type\":\"PostsList\"}\nMore prose.", $unclosedFenceBody->widgetJson);
        self::assertFalse($unclosedFenceBody->isClosed);
        self::assertSame(3, $unclosedFenceBody->lastLineIndex, 'the empty line at the end of the text');
        self::assertSame(strlen($markdown), $unclosedFenceBody->end);
    }

    public function testTheBodyBuildsTheFencedBlockMatchFromItsStart(): void
    {
        $widgetBlockSyntaxMatch = WidgetFenceBody::forBodyEnd(40, '{"type":"PostsList"}', false, 3)->buildBlockSyntaxMatch(10);

        self::assertSame(3, $widgetBlockSyntaxMatch->lastLineIndex);
        self::assertSame(10, $widgetBlockSyntaxMatch->start);
        self::assertSame(30, $widgetBlockSyntaxMatch->length);
        self::assertSame(WidgetBlock::SYNTAX_FENCE, $widgetBlockSyntaxMatch->syntax);
        self::assertSame('{"type":"PostsList"}', $widgetBlockSyntaxMatch->widgetJson);
        self::assertNull($widgetBlockSyntaxMatch->syntaxOutcome);
        self::assertFalse($widgetBlockSyntaxMatch->isClosed);
    }

    public function testAFenceAndAGluedOpenerAreReadIntoTheSameBlocks(): void
    {
        $markdown = "Here it is:```widget\n{\"type\":\"PostsList\"}\nAnd a fence:\n```widget\n{\"type\":\"ReviewsList\"}\n```\n";
        $widgetBlocks = WidgetBlocks::fromMarkdown($markdown)->getElements();

        self::assertCount(2, $widgetBlocks);
        self::assertSame('```widget' . "\n" . '{"type":"PostsList"}', substr($markdown, $widgetBlocks[0]->start, $widgetBlocks[0]->length));
        self::assertTrue($widgetBlocks[0]->isClosed);
        self::assertSame("```widget\n{\"type\":\"ReviewsList\"}\n```\n", substr($markdown, $widgetBlocks[1]->start, $widgetBlocks[1]->length));
        self::assertSame(WidgetBlock::OUTCOME_VALID, $widgetBlocks[1]->outcome);
    }
}
