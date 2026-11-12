<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Why an MCP `type:image` content part did not become a {@see ToolResultImage}
 * ({@see ToolResultImage::decodeMcpImageContentPart()}): the reason a tool result notes in place of the image.
 *
 * @phpstan-consistent-constructor
 */
class ToolResultImageRejection extends ValueObject
{
    /** The rejection in plain words (`empty image data`, `invalid base64`, `disallowed image type 'image/gif'`, …). */
    public string $reason = '';

    public static function because(string $reason): static
    {
        $toolResultImageRejection = new static();
        $toolResultImageRejection->reason = $reason;
        return $toolResultImageRejection;
    }
}
