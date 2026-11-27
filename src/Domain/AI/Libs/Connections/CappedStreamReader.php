<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Libs\Connections;

use Psr\Http\Message\StreamInterface;

/** Reads a streamed provider body in chunks and stops past a limit: a large download is never held in memory whole. */
class CappedStreamReader
{
    public const int CHUNK_BYTES = 65536;

    /** The body, or null when it is longer than `$maximumBytes` (the rest is not read) */
    public static function read(StreamInterface $body, int $maximumBytes): ?string
    {
        $content = '';
        while (!$body->eof()) {
            $content .= $body->read(static::CHUNK_BYTES);
            if (strlen($content) > $maximumBytes) {
                $body->close();
                return null;
            }
        }
        return $content;
    }
}
