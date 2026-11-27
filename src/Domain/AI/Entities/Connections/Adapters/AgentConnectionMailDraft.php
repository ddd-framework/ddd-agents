<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A mail the agent drafts or sends: exactly what a person approves.
 */
class AgentConnectionMailDraft extends ValueObject
{
    /** @var string[] */
    public array $to = [];
    /** @var string[] */
    public array $cc = [];
    /** @var string[] */
    public array $bcc = [];
    public string $subject = '';
    public string $bodyText = '';
    /** @var string|null The thread a reply belongs to */
    public ?string $threadId = null;
    /** @var string|null The Message-ID header of the mail answered */
    public ?string $inReplyToInternetMessageId = null;
    /** @var string|null An existing draft that is sent */
    public ?string $draftId = null;

    /** @return string[] every recipient, lower case */
    public function getAllRecipients(): array
    {
        $recipients = [];
        foreach ([...$this->to, ...$this->cc, ...$this->bcc] as $address) {
            $recipients[] = mb_strtolower(trim($address));
        }
        return array_values(array_unique($recipients));
    }

    /** The exact content as a person reads it, the base of the content hash */
    public function describeForApproval(): string
    {
        $lines = ['To: ' . implode(', ', $this->to)];
        if ($this->cc !== []) {
            $lines[] = 'Cc: ' . implode(', ', $this->cc);
        }
        if ($this->bcc !== []) {
            $lines[] = 'Bcc: ' . implode(', ', $this->bcc);
        }
        $lines[] = 'Subject: ' . $this->subject;
        $lines[] = '';
        $lines[] = $this->bodyText;
        return implode("\n", $lines);
    }
}
