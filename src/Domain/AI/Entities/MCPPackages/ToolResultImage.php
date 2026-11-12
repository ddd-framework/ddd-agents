<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A TRANSIENT image produced by a tool call — the resolved bytes plus metadata, split out of the
 * {@see \DDD\Presentation\Mcp\Dtos\MCPToolResponse} content parts by {@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService}
 * so the base64 NEVER lands in {@see ToolResult::$content} (the "armed trap": a json_encoded image part is
 * tens of thousands of tokens of unreadable base64 billed as tool text). Never persisted — the
 * bytes-free {@see ToolCallImageReference} is what survives on the tool call. Renders its own OpenAI wire part.
 *
 * @phpstan-consistent-constructor
 */
class ToolResultImage extends ValueObject
{
    /** @var string[] The MIME types a tool image may carry — verified against the MAGIC bytes, not the declared type. */
    public const array ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** @var int Decoded-byte ceiling per image. */
    public const int MAX_DECODED_BYTES = 5_242_880; // 5 MB

    /** @var int Pixel ceiling per image — the decompression-bomb guard. */
    public const int MAX_PIXELS = 20_000_000; // 20 MP

    /** @var string The base64-encoded image bytes (as delivered by the tool). */
    public string $base64Data = '';

    /** @var string The MAGIC-verified MIME type (one of {@see ALLOWED_MIME_TYPES}), NOT the tool's declared type. */
    public string $mimeType = '';

    public int $width = 0;

    public int $height = 0;

    /** @var int Decoded byte size. */
    public int $byteSize = 0;

    /** @var string sha256 of the decoded bytes — identity for the reference + the admin trace. */
    public string $sha256 = '';

    /**
     * @var string Where the bytes can be RE-RESOLVED after this process forgets them: `inline` (single-turn spool,
     * the default — a browser viewport exists only in the moment it was captured) or the name a
     * {@see ToolCallImageStore} package declares (a durable catalog picture the loop re-reads on every model call
     * inside the wire window, so the picture survives the 12-step worker chunks and turn boundaries).
     */
    public string $sourceStore = ToolCallImageReference::SOURCE_STORE_INLINE;

    /** @var string The id within {@see $sourceStore} — empty for an inline image (the reference then falls back to the sha256). */
    public string $sourceImageId = '';

    /**
     * The image of a valid MCP `type:image` content part, or null when it is rejected — the reason is
     * {@see decodeMcpImageContentPart()}'s.
     *
     * @param array<string, mixed> $mcpImageContentPart the `['type'=>'image','data'=>base64,'mimeType'=>...]` part
     */
    public static function fromMcpImageContentPart(array $mcpImageContentPart): ?self
    {
        $decodedImage = self::decodeMcpImageContentPart($mcpImageContentPart);
        return $decodedImage instanceof self ? $decodedImage : null;
    }

    /**
     * Validates an MCP `type:image` content part and builds the VO, or returns the rejection.
     * Validation, all BEFORE anything is persisted or sent: valid base64, decoded-byte ceiling,
     * a real decodable image (magic bytes via getimagesizefromstring), the MAGIC type on the allowlist, and the
     * pixel ceiling. A rejected image is a text note upstream — never a failed call, never a provider round trip.
     *
     * @param array<string, mixed> $mcpImageContentPart the `['type'=>'image','data'=>base64,'mimeType'=>...]` part
     */
    public static function decodeMcpImageContentPart(array $mcpImageContentPart): static|ToolResultImageRejection
    {
        $base64Data = is_string($mcpImageContentPart['data'] ?? null) ? $mcpImageContentPart['data'] : '';
        if ($base64Data === '') {
            return ToolResultImageRejection::because('empty image data');
        }
        $decodedBytes = base64_decode($base64Data, true);
        if ($decodedBytes === false || $decodedBytes === '') {
            return ToolResultImageRejection::because('invalid base64');
        }
        $byteSize = strlen($decodedBytes);
        if ($byteSize > self::MAX_DECODED_BYTES) {
            return ToolResultImageRejection::because("image exceeds the {$byteSize}-byte ceiling of " . self::MAX_DECODED_BYTES);
        }
        $imageInfo = @getimagesizefromstring($decodedBytes);
        if ($imageInfo === false) {
            return ToolResultImageRejection::because('not a decodable image (magic bytes)');
        }
        $magicMimeType = $imageInfo['mime'];
        if (!in_array($magicMimeType, self::ALLOWED_MIME_TYPES, true)) {
            return ToolResultImageRejection::because("disallowed image type '{$magicMimeType}'");
        }
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        if ($width * $height > self::MAX_PIXELS) {
            return ToolResultImageRejection::because('image exceeds the pixel ceiling (decompression-bomb guard)');
        }

        $toolResultImage = new static();
        $toolResultImage->base64Data = $base64Data;
        $toolResultImage->mimeType = $magicMimeType;
        $toolResultImage->width = $width;
        $toolResultImage->height = $height;
        $toolResultImage->byteSize = $byteSize;
        $toolResultImage->sha256 = hash('sha256', $decodedBytes);
        // A tool that serves a DURABLE picture names its store + id on the part (MCPToolResponse::addImageContent),
        // so the persisted reference can be re-resolved later; anything else is an inline, single-turn image.
        $sourceStore = trim((string)($mcpImageContentPart['sourceStore'] ?? ''));
        $sourceImageId = trim((string)($mcpImageContentPart['sourceImageId'] ?? ''));
        if ($sourceStore !== '' && $sourceImageId !== '') {
            $toolResultImage->sourceStore = $sourceStore;
            $toolResultImage->sourceImageId = $sourceImageId;
        }
        return $toolResultImage;
    }

    /** The OpenAI `image_url` content part (data-URI) — the wire form of this image. */
    public function toOpenAiImagePart(): array
    {
        return [
            'type' => 'image_url',
            'image_url' => ['url' => "data:{$this->mimeType};base64,{$this->base64Data}"],
        ];
    }

    /**
     * The bytes-free pointer to persist on the tool call — everything the reference needs except the bytes. Defaults
     * to the store + id the tool declared on the image part ({@see $sourceStore}); an inline image points at its sha256.
     */
    public function toToolCallImageReference(?string $sourceStore = null, string $sourceImageId = ''): ToolCallImageReference
    {
        $toolCallImageReference = new ToolCallImageReference();
        $toolCallImageReference->sourceStore = $sourceStore ?? $this->sourceStore;
        $toolCallImageReference->sourceImageId = $sourceImageId !== '' ? $sourceImageId : ($this->sourceImageId !== '' ? $this->sourceImageId : $this->sha256);
        $toolCallImageReference->mimeType = $this->mimeType;
        $toolCallImageReference->width = $this->width;
        $toolCallImageReference->height = $this->height;
        $toolCallImageReference->byteSize = $this->byteSize;
        $toolCallImageReference->sha256 = $this->sha256;
        return $toolCallImageReference;
    }
}
