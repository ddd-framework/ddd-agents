<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendarEvent;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatPost;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContact;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailDraft;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What an outward step runs with, typed: exactly one payload of its kind is set. Stored with an approval request
 * (serialized) and run with exactly these values once a person approved; the content hash is taken over them.
 */
class AgentConnectionStepArguments extends ValueObject
{
    /** @var string The drive step kind (AgentConnectionDriveStepsService::STEP_*); empty for the other capabilities */
    public string $stepKind = '';

    public ?AgentConnectionMailDraft $mailDraft = null;

    public ?AgentConnectionCalendarEvent $calendarEvent = null;

    /** @var AgentConnectionContact|null The contact to create or update, or the survivor of a merge */
    public ?AgentConnectionContact $contact = null;

    public ?AgentConnectionChatPost $chatPost = null;

    /** @var string[] The contact fields an update or a merge writes */
    public array $fieldNames = [];

    /** @var string|null The contact to delete, or the donor of a merge */
    public ?string $contactId = null;

    /** @var string|null A merge: the etag of the donor as read for the plan (the donor must be unchanged at the run) */
    public ?string $donorEtag = null;

    /** @var string[] Labels the merge survivor is added to */
    public array $labelsToAdd = [];

    /** @var string|null The label whose members change; null with `newGroupName` creates one */
    public ?string $groupId = null;

    public ?string $newGroupName = null;

    /** @var string[] */
    public array $addContactIds = [];

    /** @var string[] */
    public array $removeContactIds = [];

    /** @var string|null A new text file: its name, text and folder */
    public ?string $fileName = null;

    public ?string $fileText = null;

    public ?string $parentFolderId = null;

    /** @var string|null The drive item a drive step works on */
    public ?string $itemId = null;

    /** @var string|null The revision a native edit is locked on */
    public ?string $requiredRevisionId = null;

    /** @var string|null The resolved batchUpdate requests of a native edit, in the provider's wire format (JSON) */
    public ?string $providerRequestsJson = null;

    /** @var string|null A sheet range (A1 notation) and its rows (JSON array of rows, each an array of cells) */
    public ?string $range = null;

    public ?string $rowsJson = null;

    /** @var string|null The fingerprint of the agent's read of the range (an update is refused when it changed) */
    public ?string $readFingerprint = null;

    /** @var string|null A comment or reply, the text it quotes, the comment it answers or resolves */
    public ?string $commentContent = null;

    public ?string $quotedText = null;

    public ?string $commentId = null;

    /** A drive or native-document step (the item is set by the writer) */
    public static function forDriveStep(
        string $stepKind,
        ?string $requiredRevisionId = null,
        ?string $providerRequestsJson = null,
        ?string $range = null,
        ?string $rowsJson = null,
        ?string $readFingerprint = null,
        ?string $commentContent = null,
        ?string $quotedText = null,
        ?string $commentId = null
    ): static {
        $stepArguments = static::newInstance();
        $stepArguments->stepKind = $stepKind;
        $stepArguments->requiredRevisionId = $requiredRevisionId;
        $stepArguments->providerRequestsJson = $providerRequestsJson;
        $stepArguments->range = $range;
        $stepArguments->rowsJson = $rowsJson;
        $stepArguments->readFingerprint = $readFingerprint;
        $stepArguments->commentContent = $commentContent;
        $stepArguments->quotedText = $quotedText;
        $stepArguments->commentId = $commentId;
        return $stepArguments;
    }

    /** @return string[] Every address the step reaches (mail recipients, invitees), lower case */
    public function getRecipients(): array
    {
        $recipients = $this->mailDraft?->getAllRecipients() ?? [];
        foreach ($this->calendarEvent?->attendees ?? [] as $attendee) {
            $recipients[] = mb_strtolower(trim((string)$attendee));
        }
        return array_values(array_unique(array_filter($recipients)));
    }

    /** The stored form (with an approval request), also the base of the content hash */
    public function toStoredJson(): string
    {
        return (string)json_encode($this->toObject(false));
    }

    public static function fromStoredJson(?string $storedJson): static
    {
        $stepArguments = static::newInstance();
        $storedValues = json_decode((string)$storedJson);
        if (is_object($storedValues)) {
            $stepArguments->setPropertiesFromObject($storedValues, false);
        }
        return $stepArguments;
    }
}
