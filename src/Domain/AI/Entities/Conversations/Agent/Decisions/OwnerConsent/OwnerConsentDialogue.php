<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The dialogue the owner-consent check reads, in conversation order: the eligible owner rows after the
 * newest used consent of the tool (at most {@see MAX_OWNER_LINES}), each with the assistant text right before it and,
 * when it picks on an askOwner widget, that widget. Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService::buildOwnerConsentDialogue()}; empty when no
 * eligible owner row exists — then there is nothing to ask the decision model and the consent is refused.
 *
 * @property OwnerConsentDialogueLine[] $elements
 * @method OwnerConsentDialogueLine[] getElements()
 */
class OwnerConsentDialogue extends ObjectSet
{
    /** The owner rows the check reads at most — the newest ones (short lists calibrate best). */
    public const int MAX_OWNER_LINES = 4;

    public function hasOwnerLine(): bool
    {
        return $this->getNewestOwnerMessageIndex() !== null;
    }

    /**
     * The newest owner row of the dialogue — the row a consent is recorded on. The decision names no row; recording the
     * newest one is the conservative reading: the next consent of this tool then needs a row after it.
     */
    public function getNewestOwnerMessageIndex(): ?int
    {
        $newestOwnerMessageIndex = null;
        foreach ($this->getElements() as $line) {
            if ($line->isOwnerLine()) {
                $newestOwnerMessageIndex = $line->messageIndex;
            }
        }
        return $newestOwnerMessageIndex;
    }

    /** @return array<int, array{speaker: string, text: string}> the lines as the decision's state carries them */
    public function toDecisionState(): array
    {
        return array_values(array_map(static fn (OwnerConsentDialogueLine $line): array => $line->toDecisionStateRow(), $this->getElements()));
    }
}
