<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImageRejection;
use DDD\Tests\Agents\Support\UnitTestCase;

class ToolResultImageTest extends UnitTestCase
{
    /** A real 1×1 PNG. */
    protected const string PNG_1X1_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testValidPngIsAcceptedWithMagicVerifiedMetadata(): void
    {
        $toolResultImage = ToolResultImage::decodeMcpImageContentPart(
            ['type' => 'image', 'data' => self::PNG_1X1_BASE64, 'mimeType' => 'image/png']
        );
        self::assertInstanceOf(ToolResultImage::class, $toolResultImage, 'no rejection');
        self::assertSame('image/png', $toolResultImage->mimeType);
        self::assertSame(1, $toolResultImage->width);
        self::assertSame(1, $toolResultImage->height);
        self::assertGreaterThan(0, $toolResultImage->byteSize);
        self::assertSame(64, strlen($toolResultImage->sha256));
    }

    public function testMagicTypeOverridesADeclaredLie(): void
    {
        // Declares image/webp but the bytes are a PNG — the magic-verified type wins.
        $toolResultImage = ToolResultImage::fromMcpImageContentPart(
            ['type' => 'image', 'data' => self::PNG_1X1_BASE64, 'mimeType' => 'image/webp']
        );
        self::assertNotNull($toolResultImage);
        self::assertSame('image/png', $toolResultImage->mimeType);
    }

    public function testRejections(): void
    {
        $rejection = ToolResultImage::decodeMcpImageContentPart(['type' => 'image', 'data' => '']);
        self::assertInstanceOf(ToolResultImageRejection::class, $rejection);
        self::assertSame('empty image data', $rejection->reason);
        self::assertNull(ToolResultImage::fromMcpImageContentPart(['type' => 'image', 'data' => '']));

        $rejection = ToolResultImage::decodeMcpImageContentPart(['type' => 'image', 'data' => '!!!not-base64!!!']);
        self::assertInstanceOf(ToolResultImageRejection::class, $rejection);
        self::assertSame('invalid base64', $rejection->reason);

        // Valid base64, but the bytes are not an image.
        $rejection = ToolResultImage::decodeMcpImageContentPart(['type' => 'image', 'data' => base64_encode('hello world')]);
        self::assertInstanceOf(ToolResultImageRejection::class, $rejection);
        self::assertStringContainsString('not a decodable image', $rejection->reason);
    }

    public function testADurablePicturePartNamesItsStoreAndTheReferenceCarriesIt(): void
    {
        $toolResultImage = ToolResultImage::fromMcpImageContentPart([
            'type' => 'image', 'data' => self::PNG_1X1_BASE64, 'mimeType' => 'image/png',
            'sourceStore' => 'website-template-catalog', 'sourceImageId' => 'layout|tpl|hero-section-01',
        ]);
        self::assertNotNull($toolResultImage);
        self::assertSame('website-template-catalog', $toolResultImage->sourceStore);
        $reference = $toolResultImage->toToolCallImageReference();
        self::assertSame('website-template-catalog', $reference->sourceStore);
        self::assertSame('layout|tpl|hero-section-01', $reference->sourceImageId);
        self::assertTrue($reference->isReResolvable());

        // an inline image (no store on the part) points at its sha256 and can never be re-resolved
        $inline = ToolResultImage::fromMcpImageContentPart(['type' => 'image', 'data' => self::PNG_1X1_BASE64]);
        self::assertNotNull($inline);
        $inlineReference = $inline->toToolCallImageReference();
        self::assertSame('inline', $inlineReference->sourceStore);
        self::assertSame($inline->sha256, $inlineReference->sourceImageId);
        self::assertFalse($inlineReference->isReResolvable());
    }

    public function testOpenAiImagePartIsADataUri(): void
    {
        $toolResultImage = ToolResultImage::fromMcpImageContentPart(['type' => 'image', 'data' => self::PNG_1X1_BASE64]);
        self::assertNotNull($toolResultImage);
        $imagePart = $toolResultImage->toOpenAiImagePart();
        self::assertSame('image_url', $imagePart['type']);
        self::assertStringStartsWith('data:image/png;base64,', $imagePart['image_url']['url']);
    }

    public function testReferenceIsBytesFreeAndCarriesIdentity(): void
    {
        $toolResultImage = ToolResultImage::fromMcpImageContentPart(['type' => 'image', 'data' => self::PNG_1X1_BASE64]);
        self::assertNotNull($toolResultImage);
        $reference = $toolResultImage->toToolCallImageReference();
        self::assertSame($toolResultImage->sha256, $reference->sha256);
        self::assertSame($toolResultImage->sha256, $reference->sourceImageId);
        self::assertSame('inline', $reference->sourceStore);
        self::assertSame($toolResultImage->width, $reference->width);
        // The reference must NOT carry bytes.
        self::assertFalse(property_exists($reference, 'base64Data'));
    }
}
