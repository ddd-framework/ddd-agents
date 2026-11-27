<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The credentials of an IMAP/SMTP mailbox as a person enters them: never persisted as is (the connect stores them
 * encrypted), never serialized into a response ({@see __debugInfo()}). Ports are limited to 993 (IMAP) and 465/587
 * (SMTP) by the clients.
 */
class AgentConnectionImapCredentials extends ValueObject
{
    public string $imapHost = '';
    public int $imapPort = 993;
    public string $smtpHost = '';
    public int $smtpPort = 587;
    public string $username = '';
    public string $password = '';
    public string $fromAddress = '';
    public string $draftsFolder = 'Drafts';

    /** @return array<string, string> the secret payload stored encrypted per connection */
    public function toSecretValues(): array
    {
        return [
            'imapHost' => $this->imapHost, 'imapPort' => (string)$this->imapPort, 'smtpHost' => $this->smtpHost,
            'smtpPort' => (string)$this->smtpPort, 'username' => $this->username, 'password' => $this->password,
            'fromAddress' => $this->fromAddress, 'draftsFolder' => $this->draftsFolder,
        ];
    }

    /** @param array<string, mixed> $values */
    public static function fromValues(array $values): static
    {
        $credentials = new static();
        $credentials->imapHost = (string)($values['imapHost'] ?? '');
        $credentials->imapPort = (int)($values['imapPort'] ?? 993);
        $credentials->smtpHost = (string)($values['smtpHost'] ?? '');
        $credentials->smtpPort = (int)($values['smtpPort'] ?? 587);
        $credentials->username = (string)($values['username'] ?? '');
        $credentials->password = (string)($values['password'] ?? '');
        $credentials->fromAddress = (string)($values['fromAddress'] ?? '');
        $credentials->draftsFolder = (string)($values['draftsFolder'] ?? 'Drafts') ?: 'Drafts';
        return $credentials;
    }

    public function __debugInfo(): array
    {
        return ['imapHost' => $this->imapHost, 'smtpHost' => $this->smtpHost, 'username' => $this->username, 'password' => '***'];
    }
}
