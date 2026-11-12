<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The PERSISTED, bytes-free pointer to an image a tool call produced — stored on the tool call's JSON column.
 * A separate type from {@see ToolResultImage} ON PURPOSE: one VO holding both bytes and the
 * reference is how bytes reach the database by accident. This is the only thing that lets a resumed worker, a
 * retry, or the admin view know what image was sent — the bytes are re-resolved per model call, never stored.
 */
class ToolCallImageReference extends ValueObject
{
    /** The single-turn spool: the bytes exist only in the process that captured them (a browser viewport) — never re-resolvable. */
    public const string SOURCE_STORE_INLINE = 'inline';

    /** @var string Where the bytes can be re-resolved: {@see SOURCE_STORE_INLINE}, or the name a {@see ToolCallImageStore} package declares. */
    public string $sourceStore = self::SOURCE_STORE_INLINE;

    /** @var string The id within {@see $sourceStore} (falls back to the sha256 for an inline image). */
    public string $sourceImageId = '';

    public string $mimeType = '';

    public int $width = 0;

    public int $height = 0;

    public int $byteSize = 0;

    /** @var string sha256 of the decoded bytes — the identity shown in the admin placeholder. */
    public string $sha256 = '';

    /** @var string|null When the image was captured (ISO), for the trace. */
    public ?string $capturedAt = null;

    /** Whether a {@see ToolCallImageStore} can re-resolve this image's bytes (anything but the inline spool). */
    public function isReResolvable(): bool
    {
        return $this->sourceStore !== '' && $this->sourceStore !== self::SOURCE_STORE_INLINE && $this->sourceImageId !== '';
    }

    /**
     * The human-readable placeholder the admin forensic reconstruction renders at the image's wire position
     * — `[image <sha12>, WxH, N KB — bytes not retained]`. Bytes are never stored, so the view
     * shows what was sent from the persisted reference instead of re-resolving today's viewport. Pure.
     */
    public function toDisplayPlaceholder(): string
    {
        $shaPrefix = $this->sha256 !== '' ? substr($this->sha256, 0, 12) : 'unknown';
        $kiloBytes = (int)round($this->byteSize / 1024);
        return '[image ' . $shaPrefix . ', ' . $this->width . '×' . $this->height . ', ' . $kiloBytes . ' KB — bytes not retained]';
    }
}
