<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Tests\Agents\Support\UnitTestCase;

class ToolCallImageReferenceTest extends UnitTestCase
{
    protected const string PNG_1X1_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testApplyResultImagesRecordsBytesFreeReferences(): void
    {
        $toolResultImage = ToolResultImage::fromMcpImageContentPart(['type' => 'image', 'data' => self::PNG_1X1_BASE64]);
        self::assertNotNull($toolResultImage);

        $toolResult = new ToolResult('some text result', false);
        $toolResult->images = [$toolResultImage];

        $toolCall = new ToolCall();
        $toolCall->applyResultImages($toolResult);

        self::assertCount(1, $toolCall->imageReferences);
        self::assertSame($toolResultImage->sha256, $toolCall->imageReferences[0]->sha256);
        self::assertSame('inline', $toolCall->imageReferences[0]->sourceStore);
        self::assertSame($toolResultImage->width, $toolCall->imageReferences[0]->width);
        self::assertSame($toolResultImage->mimeType, $toolCall->imageReferences[0]->mimeType);
    }

    public function testApplyResultImagesIsNoOpWithoutImages(): void
    {
        $toolResult = new ToolResult('text only', false);
        $toolCall = new ToolCall();
        $toolCall->applyResultImages($toolResult);
        self::assertSame([], $toolCall->imageReferences);
    }
}
