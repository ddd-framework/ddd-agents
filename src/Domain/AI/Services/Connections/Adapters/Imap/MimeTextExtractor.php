<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Imap;

use DDD\Domain\AI\Services\Connections\Adapters\Mime\AgentConnectionHtmlTextExtractor;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use ValueError;

/**
 * The readable text of a raw RFC 5322 message: the first text/plain part (multipart walked to at most
 * {@see MAXIMUM_DEPTH} levels), else the visible text of the HTML part ({@see AgentConnectionHtmlTextExtractor});
 * transfer encodings and charsets decoded, an unknown charset left as it is. Pure, unit tested.
 */
#[Exclude]
class MimeTextExtractor
{
    public const int MAXIMUM_DEPTH = 8;

    public const int MAXIMUM_PARTS = 100;

    public static function extractPlainText(string $rawMessage, int $depth = 0): string
    {
        if ($depth > static::MAXIMUM_DEPTH) {
            return '';
        }
        [$headerBlock, $body] = static::splitHeaderAndBody($rawMessage);
        $headers = static::parseHeaders($headerBlock);
        $contentType = $headers['content-type'] ?? 'text/plain';
        if (stripos($contentType, 'multipart/') === 0 && preg_match('/boundary="?([^";]+)"?/i', $contentType, $matches)) {
            $htmlFallback = '';
            $partCount = 0;
            foreach (explode('--' . $matches[1], $body) as $part) {
                if (++$partCount > static::MAXIMUM_PARTS) {
                    break;
                }
                $part = ltrim($part, "\r\n");
                if ($part === '' || str_starts_with($part, '--')) {
                    continue;
                }
                [$partHeaderBlock] = static::splitHeaderAndBody($part);
                $partType = static::parseHeaders($partHeaderBlock)['content-type'] ?? 'text/plain';
                $partText = static::extractPlainText($part, $depth + 1);
                if (stripos($partType, 'text/html') === 0) {
                    $htmlFallback = $htmlFallback !== '' ? $htmlFallback : $partText;
                    continue;
                }
                if ($partText !== '') {
                    return $partText;
                }
            }
            return $htmlFallback;
        }
        $decoded = match (strtolower(trim($headers['content-transfer-encoding'] ?? ''))) {
            'base64' => (string)base64_decode((string)preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        if (preg_match('/charset="?([^";]+)"?/i', $contentType, $charsetMatches)) {
            $decoded = static::convertToUtf8($decoded, $charsetMatches[1]);
        }
        if (stripos($contentType, 'text/html') === 0) {
            return AgentConnectionHtmlTextExtractor::extractVisibleText($decoded);
        }
        return stripos($contentType, 'text/') === 0 ? trim($decoded) : '';
    }

    /** Converts from a charset the message names; an unknown one leaves the text as it is (never an exception) */
    public static function convertToUtf8(string $text, string $charset): string
    {
        $charset = trim($charset);
        if ($charset === '' || strtolower($charset) === 'utf-8' || strtolower($charset) === 'us-ascii') {
            return $text;
        }
        if (!in_array(strtolower($charset), array_map('strtolower', mb_list_encodings()), true)) {
            return $text;
        }
        try {
            $converted = mb_convert_encoding($text, 'UTF-8', $charset);
        } catch (ValueError) {
            // the charset passed the list check but mbstring still refuses it: keep the raw text (no data loss)
            return $text;
        }
        return is_string($converted) ? $converted : $text;
    }

    /** @return array{0: string, 1: string} */
    protected static function splitHeaderAndBody(string $rawMessage): array
    {
        $normalized = str_replace("\r\n", "\n", $rawMessage);
        $separatorPosition = strpos($normalized, "\n\n");
        return $separatorPosition === false ? [$normalized, ''] : [substr($normalized, 0, $separatorPosition), substr($normalized, $separatorPosition + 2)];
    }

    /** @return array<string, string> */
    protected static function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $currentName = null;
        foreach (explode("\n", $headerBlock) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $currentName !== null) {
                $headers[$currentName] .= ' ' . trim($line);
                continue;
            }
            $colonPosition = strpos($line, ':');
            if ($colonPosition !== false) {
                $currentName = strtolower(substr($line, 0, $colonPosition));
                $headers[$currentName] = trim(substr($line, $colonPosition + 1));
            }
        }
        return $headers;
    }
}
