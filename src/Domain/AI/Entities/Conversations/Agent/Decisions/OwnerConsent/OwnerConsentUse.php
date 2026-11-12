<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one owner row did for one tool: it authorised a step that is now RESERVED for the
 * running write or USED by a write that went through, or the decision model REFUSED it. Keyed `<tool>:<owner row>` in the
 * working state's `ownerConsentUses` object; the tool's WINDOW (`ownerConsentWindows.<tool>`, the newest recorded owner
 * row) moves with every record, and a new check only reads the owner rows after it. Written only by its own guarded
 * statements ({@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService::recordOwnerConsentReservation()}), never by a
 * write of the whole working state.
 *
 * @phpstan-consistent-constructor
 */
class OwnerConsentUse extends ValueObject
{
    public const string STATE_RESERVED = 'RESERVED';
    public const string STATE_USED = 'USED';
    public const string STATE_REFUSED = 'REFUSED';

    public string $toolName = '';

    /** {@see OwnerConsentRequest::$targetKey} of the step. */
    public string $targetKey = '';

    /** The newest owner row the verdict read — the tool's window starts after it once this is recorded. */
    public int $ownerMessageIndex = -1;

    /** The tool's window start the verdict was decided on — restored when a reservation is released. */
    public int $windowStart = -1;

    /** The provider's id of the tool call that recorded it — "used" and the release are guarded on it. */
    public ?string $toolCallId = null;

    public string $state = self::STATE_RESERVED;

    /** Y-m-d H:i:s, server-local, when the state was written. */
    public string $at = '';

    /** A consented step reserved for the call `$toolCallId`. */
    public static function reservedFor(OwnerConsentRequest $request, int $ownerMessageIndex, int $windowStart, string $toolCallId): self
    {
        return self::recordedFor($request, $ownerMessageIndex, $windowStart, $toolCallId, self::STATE_RESERVED);
    }

    /** A step the decision model refused on these owner rows — the next check reads only the rows after them. */
    public static function refusedFor(OwnerConsentRequest $request, int $ownerMessageIndex, int $windowStart, ?string $toolCallId): self
    {
        return self::recordedFor($request, $ownerMessageIndex, $windowStart, $toolCallId, self::STATE_REFUSED);
    }

    protected static function recordedFor(OwnerConsentRequest $request, int $ownerMessageIndex, int $windowStart, ?string $toolCallId, string $state): static
    {
        $ownerConsentUse = new static();
        $ownerConsentUse->toolName = $request->toolName;
        $ownerConsentUse->targetKey = $request->targetKey;
        $ownerConsentUse->ownerMessageIndex = $ownerMessageIndex;
        $ownerConsentUse->windowStart = $windowStart;
        $ownerConsentUse->toolCallId = $toolCallId;
        $ownerConsentUse->state = $state;
        $ownerConsentUse->at = date('Y-m-d H:i:s');
        return $ownerConsentUse;
    }

    /** The same record in another state, stamped now. */
    public function withState(string $state): self
    {
        $ownerConsentUse = self::fromRepositoryArray($this->toRepositoryArray());
        $ownerConsentUse->state = $state;
        $ownerConsentUse->at = date('Y-m-d H:i:s');
        return $ownerConsentUse;
    }

    /** `<tool>:<owner row>` — the key in the working state's `ownerConsentUses` object. */
    public function getKey(): string
    {
        return $this->toolName . ':' . $this->ownerMessageIndex;
    }

    /**
     * A tool name is a method name — anything else (a quote, an all-digit name a JSON encoder turns into a list index)
     * could break the merge of the records in the stored working state and is refused (fail closed). Every statement
     * of the owner-consent service passes the name through this before it binds it as a key of the stored document.
     *
     * @throws \InvalidArgumentException
     */
    public static function requireToolName(string $toolName): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $toolName) !== 1) {
            throw new \InvalidArgumentException('Not a tool name for an owner-consent record: ' . $toolName);
        }
        return $toolName;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->getKey());
    }

    /** @return array<string, int|string|null> */
    public function toRepositoryArray(): array
    {
        return [
            'toolName' => $this->toolName,
            'targetKey' => $this->targetKey,
            'ownerMessageIndex' => $this->ownerMessageIndex,
            'windowStart' => $this->windowStart,
            'toolCallId' => $this->toolCallId,
            'state' => $this->state,
            'at' => $this->at,
        ];
    }

    /** @param array<string, mixed> $repositoryFields */
    public static function fromRepositoryArray(array $repositoryFields): static
    {
        $ownerConsentUse = new static();
        $ownerConsentUse->toolName = (string)($repositoryFields['toolName'] ?? '');
        $ownerConsentUse->targetKey = (string)($repositoryFields['targetKey'] ?? '');
        $ownerConsentUse->ownerMessageIndex = (int)($repositoryFields['ownerMessageIndex'] ?? -1);
        $ownerConsentUse->windowStart = (int)($repositoryFields['windowStart'] ?? -1);
        $toolCallId = trim((string)($repositoryFields['toolCallId'] ?? ''));
        $ownerConsentUse->toolCallId = $toolCallId !== '' ? $toolCallId : null;
        $state = (string)($repositoryFields['state'] ?? '');
        // an unreadable state fails closed: it blocks like a use
        $ownerConsentUse->state = in_array($state, [self::STATE_RESERVED, self::STATE_USED, self::STATE_REFUSED], true) ? $state : self::STATE_USED;
        $ownerConsentUse->at = (string)($repositoryFields['at'] ?? '');
        return $ownerConsentUse;
    }
}
