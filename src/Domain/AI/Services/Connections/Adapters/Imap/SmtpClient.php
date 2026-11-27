<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Imap;

use DDD\Domain\AI\Exceptions\Connections\AgentConnectionProviderException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A minimal SMTP submission client: implicit TLS on 465, STARTTLS on 587 (and every other port), AUTH LOGIN, one
 * message. Lines starting with a dot are doubled (dot-stuffing). Only ports 465 and 587, only a host whose addresses
 * are all public ({@see MailServerEndpointGuard}); the connect goes to the checked address, the certificate is checked
 * against the typed host. The envelope recipients come from RCPT TO, never from a header (no Bcc header is sent).
 */
#[Exclude]
class SmtpClient
{
    /** @var resource */
    protected $stream;

    public const int TIMEOUT_SECONDS = 20;

    public static function open(string $host, int $port, string $username, string $password): static
    {
        $smtpClient = new static();
        $implicitTls = $port === 465;
        $errorNumber = 0;
        $errorText = '';
        $publicIp = MailServerEndpointGuard::resolvePublicAddress($host, $port, MailServerEndpointGuard::SMTP_PORTS);
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $stream = @stream_socket_client(MailServerEndpointGuard::buildSocketAddress($implicitTls ? 'ssl' : 'tcp', $publicIp, $port), $errorNumber, $errorText, static::TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new AgentConnectionProviderException("SMTP connection to $host:$port failed: $errorText");
        }
        stream_set_timeout($stream, static::TIMEOUT_SECONDS);
        $smtpClient->stream = $stream;
        $smtpClient->expect(220);
        $smtpClient->sendCommand('EHLO agent-connections', 250);
        if (!$implicitTls) {
            $smtpClient->sendCommand('STARTTLS', 220);
            if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new AgentConnectionProviderException('SMTP STARTTLS failed.');
            }
            $smtpClient->sendCommand('EHLO agent-connections', 250);
        }
        $smtpClient->sendCommand('AUTH LOGIN', 334);
        $smtpClient->sendCommand(base64_encode($username), 334);
        $answer = $smtpClient->sendCommand(base64_encode($password), null);
        if ((int)substr($answer, 0, 3) !== 235) {
            throw new AgentConnectionRefusedException('The SMTP server refused the login.');
        }
        return $smtpClient;
    }

    /** @param string[] $recipients */
    public function send(string $fromAddress, array $recipients, string $mimeText): void
    {
        $this->sendCommand('MAIL FROM:<' . static::cleanAddress($fromAddress) . '>', 250);
        foreach ($recipients as $recipient) {
            $this->sendCommand('RCPT TO:<' . static::cleanAddress($recipient) . '>', 250);
        }
        $this->sendCommand('DATA', 354);
        $stuffed = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $mimeText));
        fwrite($this->stream, str_replace("\n", "\r\n", (string)$stuffed) . "\r\n.\r\n");
        $this->expect(250);
    }

    public function quit(): void
    {
        if (is_resource($this->stream)) {
            fwrite($this->stream, "QUIT\r\n");
            fclose($this->stream);
        }
    }

    protected function sendCommand(string $command, ?int $expectedCode): string
    {
        fwrite($this->stream, $command . "\r\n");
        return $expectedCode === null ? $this->readAnswer() : $this->expect($expectedCode);
    }

    protected function expect(int $expectedCode): string
    {
        $answer = $this->readAnswer();
        if ((int)substr($answer, 0, 3) !== $expectedCode) {
            throw new AgentConnectionProviderException('SMTP answered: ' . mb_substr($answer, 0, 200));
        }
        return $answer;
    }

    protected function readAnswer(): string
    {
        $answer = '';
        while (($line = fgets($this->stream)) !== false) {
            $answer .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $answer;
    }

    protected static function cleanAddress(string $address): string
    {
        if (preg_match('/<([^>]+)>/', $address, $matches)) {
            $address = $matches[1];
        }
        return trim(str_replace(["\r", "\n", '<', '>'], '', $address));
    }
}
