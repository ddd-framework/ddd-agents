<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What {@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService::reserveOwnerConsentForStep()} gave
 * the tool for its write: UNGATED — no owner consent applies (an HTTP MCP or harness call), write;
 * RESERVED — this call holds the owner's consent, write, then mark it used or release it; NOT_RESERVED — no consent this
 * call may use: write nothing and answer {@see buildModelFacingText()}.
 *
 * @phpstan-consistent-constructor
 */
class OwnerConsentReservation extends ValueObject
{
    public const string OUTCOME_UNGATED = 'UNGATED';
    public const string OUTCOME_RESERVED = 'RESERVED';
    public const string OUTCOME_NOT_RESERVED = 'NOT_RESERVED';

    public string $outcome = self::OUTCOME_NOT_RESERVED;

    /** The verdict the reservation was decided on. */
    public OwnerConsentVerdict $verdict;

    /** The step, its target and its consequence. */
    public OwnerConsentRequest $request;

    /** The reserved record, set for RESERVED only. */
    public ?OwnerConsentUse $reservedUse = null;

    public static function ungated(OwnerConsentRequest $request, OwnerConsentVerdict $verdict): self
    {
        return self::withOutcome(self::OUTCOME_UNGATED, $request, $verdict);
    }

    public static function reserved(OwnerConsentRequest $request, OwnerConsentVerdict $verdict, OwnerConsentUse $reservedUse): self
    {
        $ownerConsentReservation = self::withOutcome(self::OUTCOME_RESERVED, $request, $verdict);
        $ownerConsentReservation->reservedUse = $reservedUse;
        return $ownerConsentReservation;
    }

    public static function notReserved(OwnerConsentRequest $request, OwnerConsentVerdict $verdict): self
    {
        return self::withOutcome(self::OUTCOME_NOT_RESERVED, $request, $verdict);
    }

    protected static function withOutcome(string $outcome, OwnerConsentRequest $request, OwnerConsentVerdict $verdict): static
    {
        $ownerConsentReservation = new static();
        $ownerConsentReservation->outcome = $outcome;
        $ownerConsentReservation->request = $request;
        $ownerConsentReservation->verdict = $verdict;
        return $ownerConsentReservation;
    }

    public function isWriteAllowed(): bool
    {
        return $this->outcome !== self::OUTCOME_NOT_RESERVED;
    }

    /**
     * The text the model reads when the tool wrote nothing: the verdict's text, or — when the owner did agree but
     * another call of this tool already used that agreement — the same "ask the owner" text, since this call needs an
     * agreement of its own. '' when the write is allowed.
     */
    public function buildModelFacingText(): string
    {
        if ($this->isWriteAllowed()) {
            return '';
        }
        if (!$this->verdict->consented) {
            return $this->verdict->buildModelFacingText($this->request);
        }
        return 'NOT DONE — nothing was changed. The owner\'s confirmation was already used by another call of this tool; '
            . 'this step needs a confirmation of its own. Ask the owner explicitly about exactly this step — '
            . $this->request->targetLine . ' — and tell them the consequence: ' . $this->request->consequenceLine
            . '. Call this tool again with the SAME arguments only AFTER the owner confirmed.';
    }
}
