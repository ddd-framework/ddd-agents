<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Imap;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionImapCredentials;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailDraft;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessage;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessages;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\AgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\MailAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\Mime\AgentConnectionMimeMessageBuilder;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A mailbox by IMAP and SMTP, typically the agent's own address (mode 2), without OAuth. The credentials (host,
 * port, user, password of IMAP and SMTP) are stored per connection, encrypted. No PHP extension needed: a small IMAP
 * client over TLS ({@see ImapClient}) and an SMTP client ({@see SmtpClient}).
 *
 * IMAP has no threads: a message is its own thread (`threadId` = its UID); a reply carries In-Reply-To.
 */
#[Exclude]
class ImapSmtpAgentConnectionAdapter extends AgentConnectionAdapter implements MailAgentConnectionAdapter
{
    public const string PROVIDER = AgentConnection::PROVIDER_IMAP;

    public const int MAXIMUM_BODY_CHARACTERS = 20000;

    public function getCapabilities(): array
    {
        return [AgentConnectionPermission::CAPABILITY_MAIL];
    }

    /** Checks the credentials before a connection is stored (a login on both servers; public hosts, standard ports) */
    public static function verifyCredentials(AgentConnectionImapCredentials $imapCredentials): void
    {
        $imapClient = ImapClient::open($imapCredentials->imapHost, $imapCredentials->imapPort, $imapCredentials->username, $imapCredentials->password);
        $imapClient->logout();
        $smtpClient = SmtpClient::open($imapCredentials->smtpHost, $imapCredentials->smtpPort, $imapCredentials->username, $imapCredentials->password);
        $smtpClient->quit();
    }

    public function searchMail(string $query, int $maximumResults): AgentConnectionMailMessages
    {
        $imapClient = $this->openImap();
        try {
            $imapClient->select('INBOX');
            $uids = $imapClient->searchUids($query);
            $mailMessages = new AgentConnectionMailMessages();
            foreach (array_slice(array_reverse($uids), 0, max(1, min(50, $maximumResults))) as $uid) {
                $addedElement = $this->toMailMessage($uid, $imapClient->fetchHeaders($uid), null);
                $mailMessages->add($addedElement);
            }
            return $mailMessages;
        } finally {
            $imapClient->logout();
        }
    }

    public function readMailThread(string $threadId): AgentConnectionMailMessages
    {
        if (!ctype_digit($threadId)) {
            throw new AgentConnectionRefusedException('An IMAP thread id is the message UID (digits).');
        }
        $imapClient = $this->openImap();
        try {
            $imapClient->select('INBOX');
            $mailMessages = new AgentConnectionMailMessages();
            $addedElement = $this->toMailMessage((int)$threadId, $imapClient->fetchHeaders((int)$threadId), $imapClient->fetchPlainBody((int)$threadId));
            $mailMessages->add($addedElement);
            return $mailMessages;
        } finally {
            $imapClient->logout();
        }
    }

    public function createMailDraft(AgentConnectionMailDraft $mailDraft): AgentConnectionSendResult
    {
        $secrets = $this->readSecrets();
        $imapClient = $this->openImap();
        try {
            $draftsFolder = $secrets->get('draftsFolder') ?: 'Drafts';
            $imapClient->append($draftsFolder, AgentConnectionMimeMessageBuilder::build($mailDraft, (string)$secrets->get('fromAddress'), null, true), '(\\Draft)');
        } finally {
            $imapClient->logout();
        }
        $sendResult = new AgentConnectionSendResult();
        $sendResult->externalId = 'draft';
        return $sendResult;
    }

    public function sendMail(AgentConnectionMailDraft $mailDraft): AgentConnectionSendResult
    {
        $secrets = $this->readSecrets();
        $fromAddress = (string)$secrets->get('fromAddress');
        $mimeText = AgentConnectionMimeMessageBuilder::build($mailDraft, $fromAddress);
        $smtpClient = SmtpClient::open((string)$secrets->get('smtpHost'), (int)$secrets->getInt('smtpPort'), (string)$secrets->get('username'), (string)$secrets->get('password'));
        try {
            $smtpClient->send($fromAddress, $mailDraft->getAllRecipients(), $mimeText);
        } finally {
            $smtpClient->quit();
        }
        preg_match('/^Message-ID: (<[^>]+>)/mi', $mimeText, $matches);
        $sendResult = new AgentConnectionSendResult();
        $sendResult->externalId = $matches[1] ?? 'sent';
        return $sendResult;
    }

    protected function openImap(): ImapClient
    {
        $secrets = $this->readSecrets();
        return ImapClient::open((string)$secrets->get('imapHost'), (int)$secrets->getInt('imapPort'), (string)$secrets->get('username'), (string)$secrets->get('password'));
    }

    /** @param array<string, string> $headers */
    protected function toMailMessage(int $uid, array $headers, ?string $bodyText): AgentConnectionMailMessage
    {
        $mailMessage = new AgentConnectionMailMessage();
        $mailMessage->messageId = (string)$uid;
        $mailMessage->threadId = (string)$uid;
        $mailMessage->internetMessageId = $headers['message-id'] ?? null;
        $mailMessage->from = static::decodeHeader($headers['from'] ?? '');
        $mailMessage->to = array_values(array_filter(array_map('trim', explode(',', static::decodeHeader($headers['to'] ?? '')))));
        $mailMessage->cc = array_values(array_filter(array_map('trim', explode(',', static::decodeHeader($headers['cc'] ?? '')))));
        $mailMessage->subject = static::decodeHeader($headers['subject'] ?? '');
        $mailMessage->sentAt = static::parseProviderTime($headers['date'] ?? null);
        $mailMessage->senderAuthenticated = static::isSenderAuthenticated(isset($headers['authentication-results']) ? [$headers['authentication-results']] : [], $mailMessage->from, $this->getSettings()->trustedAuthenticationServerIds);
        if ($bodyText !== null) {
            $mailMessage->bodyText = static::shorten($bodyText, static::MAXIMUM_BODY_CHARACTERS);
            $mailMessage->snippet = static::shorten(trim((string)preg_replace('/\s+/', ' ', $bodyText)), 140);
        }
        return $mailMessage;
    }

    protected static function decodeHeader(string $value): string
    {
        return function_exists('iconv_mime_decode') ? (string)iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $value;
    }
}
