<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Mime;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailDraft;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Builds the RFC 5322 text of a mail (plain text, UTF-8, quoted-printable) for Gmail's raw upload, IMAP APPEND and
 * SMTP. Header values are stripped of line breaks (no header injection from model text). Bcc recipients never appear
 * in a message that travels by SMTP: SMTP delivers to them by RCPT TO. The header is written only where the provider
 * itself removes it before delivery (Gmail's send API reads Bcc from the raw message and strips it) or where the text
 * stays in the owner's own mailbox (a draft): `$withBccHeader`.
 */
#[Exclude]
class AgentConnectionMimeMessageBuilder
{
    public static function build(AgentConnectionMailDraft $mailDraft, string $fromAddress, ?string $messageIdDomain = null, bool $withBccHeader = false): string
    {
        $headers = [];
        if ($fromAddress !== '') {
            $headers[] = 'From: ' . static::cleanHeaderValue($fromAddress);
        }
        $headers[] = 'To: ' . static::cleanHeaderValue(implode(', ', $mailDraft->to));
        if ($mailDraft->cc !== []) {
            $headers[] = 'Cc: ' . static::cleanHeaderValue(implode(', ', $mailDraft->cc));
        }
        if ($withBccHeader && $mailDraft->bcc !== []) {
            $headers[] = 'Bcc: ' . static::cleanHeaderValue(implode(', ', $mailDraft->bcc));
        }
        $headers[] = 'Subject: ' . mb_encode_mimeheader(static::cleanHeaderValue($mailDraft->subject), 'UTF-8', 'Q');
        $headers[] = 'Date: ' . (new DateTime())->format(DATE_RFC2822);
        $domain = $messageIdDomain ?? (substr((string)strrchr($fromAddress, '@'), 1) ?: 'localhost');
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . static::cleanHeaderValue(trim($domain, '<> ')) . '>';
        if ($mailDraft->inReplyToInternetMessageId !== null && $mailDraft->inReplyToInternetMessageId !== '') {
            $headers[] = 'In-Reply-To: ' . static::cleanHeaderValue($mailDraft->inReplyToInternetMessageId);
            $headers[] = 'References: ' . static::cleanHeaderValue($mailDraft->inReplyToInternetMessageId);
        }
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        return implode("\r\n", $headers) . "\r\n\r\n" . quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $mailDraft->bodyText));
    }

    public static function cleanHeaderValue(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }
}
