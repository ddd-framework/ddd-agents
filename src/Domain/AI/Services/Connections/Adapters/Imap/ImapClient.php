<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Imap;

use DDD\Domain\AI\Exceptions\Connections\AgentConnectionProviderException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A minimal IMAP4rev1 client over implicit TLS (port 993): LOGIN, SELECT, UID SEARCH, UID FETCH, APPEND, LOGOUT. Only
 * what the mail capability needs; literals are sent synchronously. Every string sent to the server is quoted, so a
 * search text cannot inject a command. The host is checked by {@see MailServerEndpointGuard} (public addresses only,
 * port 993) and the connect goes to the checked address. A fetched message is capped at {@see MAXIMUM_MESSAGE_BYTES}.
 */
#[Exclude]
class ImapClient
{
    /** @var resource */
    protected $stream;

    protected int $tagCounter = 0;

    public const int TIMEOUT_SECONDS = 20;

    /** @var int The most bytes of one message read (attachments beyond are cut; the text part comes first in practice) */
    public const int MAXIMUM_MESSAGE_BYTES = 2_000_000;

    /** @var int The most bytes of all untagged lines of one command */
    public const int MAXIMUM_RESPONSE_BYTES = 4_000_000;

    /** @throws AgentConnectionRefusedException wrong credentials */
    public static function open(string $host, int $port, string $username, string $password): static
    {
        $imapClient = new static();
        $errorNumber = 0;
        $errorText = '';
        $publicIp = MailServerEndpointGuard::resolvePublicAddress($host, $port, MailServerEndpointGuard::IMAP_PORTS);
        $sslContext = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $stream = @stream_socket_client(MailServerEndpointGuard::buildSocketAddress('ssl', $publicIp, $port), $errorNumber, $errorText, static::TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT, $sslContext);
        if ($stream === false) {
            throw new AgentConnectionProviderException("IMAP connection to $host:$port failed: $errorText");
        }
        stream_set_timeout($stream, static::TIMEOUT_SECONDS);
        $imapClient->stream = $stream;
        $imapClient->readLine();
        [$status] = $imapClient->command('LOGIN ' . static::quote($username) . ' ' . static::quote($password));
        if ($status !== 'OK') {
            throw new AgentConnectionRefusedException('The IMAP server refused the login.');
        }
        return $imapClient;
    }

    public function select(string $mailbox): void
    {
        [$status, , $statusLine] = $this->command('SELECT ' . static::quote($mailbox));
        if ($status !== 'OK') {
            throw new AgentConnectionProviderException('IMAP SELECT failed: ' . $statusLine);
        }
    }

    /** @return int[] */
    public function searchUids(string $text): array
    {
        $criteria = trim($text) === '' ? 'ALL' : 'TEXT ' . static::quote($text);
        [$status, $lines] = $this->command('UID SEARCH CHARSET UTF-8 ' . $criteria);
        if ($status !== 'OK') {
            [$status, $lines] = $this->command('UID SEARCH ' . $criteria);
        }
        $uids = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($line, 8))) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int)$uid;
                    }
                }
            }
        }
        return $uids;
    }

    /** @return array<string, string> lower-case header => value */
    public function fetchHeaders(int $uid): array
    {
        [, $lines] = $this->command("UID FETCH $uid (BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID AUTHENTICATION-RESULTS)])");
        $headers = [];
        $currentName = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, '*') || $line === ')' || $line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $currentName !== null) {
                $headers[$currentName] .= ' ' . trim($line);
                continue;
            }
            $colonPosition = strpos($line, ':');
            if ($colonPosition !== false) {
                $currentName = mb_strtolower(substr($line, 0, $colonPosition));
                if (isset($headers[$currentName]) && $currentName === 'authentication-results') {
                    // only the topmost Authentication-Results counts (the receiving server's); later ones are ignored
                    $currentName = 'ignored-authentication-results';
                }
                $headers[$currentName] = trim(substr($line, $colonPosition + 1));
            }
        }
        return $headers;
    }

    /** The text of a message: the first text/plain part, else the stripped HTML; decoded */
    public function fetchPlainBody(int $uid): string
    {
        [, $lines] = $this->command("UID FETCH $uid (BODY.PEEK[]<0." . static::MAXIMUM_MESSAGE_BYTES . '>)');
        $messageLines = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line, '* ') && $line !== ')') {
                $messageLines[] = $line;
            }
        }
        return MimeTextExtractor::extractPlainText(implode("\r\n", $messageLines));
    }

    public function append(string $mailbox, string $mimeText, string $flags): void
    {
        $tag = $this->nextTag();
        fwrite($this->stream, "$tag APPEND " . static::quote($mailbox) . " $flags {" . strlen($mimeText) . "}\r\n");
        $continuation = $this->readLine();
        if (!str_starts_with($continuation, '+')) {
            throw new AgentConnectionProviderException('IMAP APPEND refused: ' . $continuation);
        }
        fwrite($this->stream, $mimeText . "\r\n");
        [$status, , $statusLine] = $this->readUntilTag($tag);
        if ($status !== 'OK') {
            throw new AgentConnectionProviderException('IMAP APPEND failed: ' . $statusLine);
        }
    }

    public function logout(): void
    {
        if (is_resource($this->stream)) {
            $this->command('LOGOUT');
            fclose($this->stream);
        }
    }

    /** @return array{0: string, 1: string[], 2: string} status, untagged lines, status line */
    protected function command(string $command): array
    {
        $tag = $this->nextTag();
        fwrite($this->stream, "$tag $command\r\n");
        return $this->readUntilTag($tag);
    }

    /** @return array{0: string, 1: string[], 2: string} */
    protected function readUntilTag(string $tag): array
    {
        $lines = [];
        $readBytes = 0;
        while (true) {
            $line = $this->readLine();
            $readBytes += strlen($line);
            if ($readBytes > static::MAXIMUM_RESPONSE_BYTES) {
                throw new AgentConnectionProviderException('The IMAP answer is larger than ' . static::MAXIMUM_RESPONSE_BYTES . ' bytes.');
            }
            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper((string)(explode(' ', $line)[1] ?? ''));
                return [$status, $lines, $line];
            }
            // a literal {n}: read exactly n bytes, then continue
            if (preg_match('/\{(\d+)\}$/', $line, $matches)) {
                if ((int)$matches[1] > static::MAXIMUM_RESPONSE_BYTES) {
                    throw new AgentConnectionProviderException('An IMAP literal is larger than ' . static::MAXIMUM_RESPONSE_BYTES . ' bytes.');
                }
                $readBytes += (int)$matches[1];
                $lines[] = $line;
                $literal = '';
                while (strlen($literal) < (int)$matches[1]) {
                    $chunk = fread($this->stream, (int)$matches[1] - strlen($literal));
                    if ($chunk === false || $chunk === '') {
                        throw new AgentConnectionProviderException('IMAP connection closed in a literal.');
                    }
                    $literal .= $chunk;
                }
                foreach (preg_split('/\r\n/', $literal) ?: [] as $literalLine) {
                    $lines[] = $literalLine;
                }
                continue;
            }
            $lines[] = $line;
        }
    }

    protected function readLine(): string
    {
        $line = fgets($this->stream);
        if ($line === false) {
            throw new AgentConnectionProviderException('IMAP connection closed.');
        }
        return rtrim($line, "\r\n");
    }

    protected function nextTag(): string
    {
        return 'A' . str_pad((string)++$this->tagCounter, 4, '0', STR_PAD_LEFT);
    }

    public static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', ' ', ' '], $value) . '"';
    }
}
