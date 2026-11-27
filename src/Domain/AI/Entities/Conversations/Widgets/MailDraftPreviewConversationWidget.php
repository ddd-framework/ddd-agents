<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailDraft;

/**
 * The exact mail a send step carries, shown as one compact preview: sender connection, recipients, subject and the body
 * (rendered as markdown, so a list or a small table reads as one). Written by the mail tool only, never by the model:
 * - status DRAFT: the step waits for the owner's yes; the preview carries ONE send button whose click sends its label
 *   (the renderer's, in the owner's language) as the owner's message. That click, or the owner's own words, is the one
 *   confirmation; the tool-written row with the step's `contentHash` is the evidence that exactly this content was shown;
 * - status SENT: the mail went out; no button.
 */
class MailDraftPreviewConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'MailDraftPreview';

    public const string SUMMARY = 'The exact mail of a send step as one compact preview (to, subject, body) with one send button, or the mail as sent.';

    public const string WHEN_TO_USE = 'Written by the mail tool only: before a send that waits for the owner\'s yes, and after a send.';

    public const string OWNER_CONTROL = 'one send button (DRAFT only)';

    public const string STATUS_DRAFT = 'DRAFT';
    public const string STATUS_SENT = 'SENT';

    /** @var string DRAFT (waits for the owner's yes) or SENT */
    public string $status = self::STATUS_DRAFT;

    /** @var int The connection the mail goes through */
    public int $connectionId = 0;

    /** @var string The connection's sending address or name */
    public string $from = '';

    /** @var string[] */
    public array $to = [];

    /** @var string[] */
    public array $cc = [];

    /** @var string[] */
    public array $bcc = [];

    public string $subject = '';

    /** @var string The body as written (markdown: lists and tables render) */
    public string $bodyText = '';

    /** @var string The hash of the exact step; binds the shown preview to the step it confirms */
    public string $contentHash = '';

    public static function forMailDraft(AgentConnectionMailDraft $mailDraft, int $connectionId, string $from, string $contentHash, string $status): static
    {
        $mailDraftPreviewWidget = new static();
        $mailDraftPreviewWidget->status = $status;
        $mailDraftPreviewWidget->connectionId = $connectionId;
        $mailDraftPreviewWidget->from = $from;
        $mailDraftPreviewWidget->to = array_values($mailDraft->to);
        $mailDraftPreviewWidget->cc = array_values($mailDraft->cc);
        $mailDraftPreviewWidget->bcc = array_values($mailDraft->bcc);
        $mailDraftPreviewWidget->subject = $mailDraft->subject;
        $mailDraftPreviewWidget->bodyText = $mailDraft->bodyText;
        $mailDraftPreviewWidget->contentHash = $contentHash;
        return $mailDraftPreviewWidget;
    }

    /** The preview as the owner reads it, for the consent decision's dialogue */
    public function buildReadableText(): string
    {
        $readableLines = ['Mail preview shown to the owner (' . $this->status . ')' . ($this->from !== '' ? ', from ' . $this->from : '') . ':'];
        $readableLines[] = 'To: ' . implode(', ', $this->to);
        if ($this->cc !== []) {
            $readableLines[] = 'Cc: ' . implode(', ', $this->cc);
        }
        if ($this->bcc !== []) {
            $readableLines[] = 'Bcc: ' . implode(', ', $this->bcc);
        }
        $readableLines[] = 'Subject: ' . $this->subject;
        $readableLines[] = $this->bodyText;
        if ($this->status === self::STATUS_DRAFT) {
            $readableLines[] = 'Under it one send button: it sends exactly this mail to ' . implode(', ', array_merge($this->to, $this->cc, $this->bcc)) . '.';
        }
        return implode("\n", $readableLines);
    }

    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'status' => $this->status,
            'connectionId' => $this->connectionId,
            'from' => $this->from,
            'to' => $this->to,
            'cc' => $this->cc === [] ? null : $this->cc,
            'bcc' => $this->bcc === [] ? null : $this->bcc,
            'subject' => $this->subject,
            'bodyText' => $this->bodyText,
            'contentHash' => $this->contentHash,
        ]);
    }
}
