<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolResultImagesOfToolCalls;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit coverage of the image tail-fallback on {@see AgentModelInput} and the wire-image cap knob on
 * {@see ContextRetentionSettings}. Pure VO behaviour — container-less.
 */
class AgentModelInputImageFallbackTest extends TestCase
{
    protected function image(): ToolResultImage
    {
        $image = (new ReflectionClass(ToolResultImage::class))->newInstanceWithoutConstructor();
        $image->base64Data = 'QUJD';
        $image->mimeType = 'image/png';
        return $image;
    }

    public function testTailFallbackMovesImagesOutOfTheToolResult(): void
    {
        $agentContext = new AgentModelInput();
        $agentContext->resolvedToolImages = new AgentToolResultImagesOfToolCalls();
        $agentContext->resolvedToolImages->addImagesOfToolCall('call-1', [$this->image()]);
        $agentContext->imageWireTailFallback = true;

        // Under the fallback the tool result carries NO image part...
        self::assertSame([], $agentContext->getResolvedImagesForToolResult('call-1'));

        // ...it rides as ONE role:user tail entry instead, labelled by tool_call_id.
        $tailMessage = $agentContext->buildImageFallbackTailMessage();
        self::assertIsArray($tailMessage);
        self::assertSame('user', $tailMessage['role']);
        self::assertStringContainsString('tool_call_id', $tailMessage['content'][0]['text']);
        $imageParts = array_values(array_filter($tailMessage['content'], static fn (array $part): bool => ($part['type'] ?? null) === 'image_url'));
        self::assertCount(1, $imageParts);
        self::assertSame('data:image/png;base64,QUJD', $imageParts[0]['image_url']['url']);
    }

    public function testNoTailFallbackWhenFlagOff(): void
    {
        $agentContext = new AgentModelInput();
        $agentContext->resolvedToolImages = new AgentToolResultImagesOfToolCalls();
        $agentContext->resolvedToolImages->addImagesOfToolCall('call-1', [$this->image()]);

        // Default (flag off): the image rides inside the tool result and there is no tail message.
        self::assertCount(1, $agentContext->getResolvedImagesForToolResult('call-1'));
        self::assertNull($agentContext->buildImageFallbackTailMessage());
    }

    public function testMaxToolCallImagesOnWireDefaultsToTwo(): void
    {
        $settings = new ContextRetentionSettings();
        self::assertSame(ContextRetentionSettings::DEFAULT_MAX_TOOL_CALL_IMAGES_ON_WIRE, $settings->getMaxToolCallImagesOnWire());
        self::assertSame(2, $settings->getMaxToolCallImagesOnWire());

        $settings->maxToolCallImagesOnWire = 1;
        self::assertSame(1, $settings->getMaxToolCallImagesOnWire());
    }
}
