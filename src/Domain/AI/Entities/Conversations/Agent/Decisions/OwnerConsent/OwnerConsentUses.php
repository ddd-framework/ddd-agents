<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The owner-consent use record of a conversation: one {@see OwnerConsentUse} per tool and owner row, as
 * the working state last loaded it. The decision reads the tool's window from the database, not from this copy; the copy
 * serves the readers in the same process (the admin inspector, the tests).
 *
 * @property OwnerConsentUse[] $elements
 * @method OwnerConsentUse[] getElements()
 * @phpstan-consistent-constructor
 */
class OwnerConsentUses extends ObjectSet
{
    /** Puts `$ownerConsentUse` in place of a record of the same key. */
    public function replaceUse(OwnerConsentUse $ownerConsentUse): void
    {
        $this->removeUseWithKey($ownerConsentUse->getKey());
        $this->add($ownerConsentUse);
    }

    public function removeUseWithKey(string $key): void
    {
        foreach ($this->getElements() as $recordedUse) {
            if ($recordedUse->getKey() === $key) {
                $this->remove($recordedUse);
            }
        }
    }

    /** @param array<array-key, mixed> $repositoryObject the stored `ownerConsentUses` object, keyed `<tool>:<owner row>` */
    public static function fromRepositoryObject(array $repositoryObject): static
    {
        $ownerConsentUses = new static();
        foreach ($repositoryObject as $repositoryFields) {
            if (is_array($repositoryFields)) {
                $ownerConsentUse = OwnerConsentUse::fromRepositoryArray($repositoryFields);
                $ownerConsentUses->add($ownerConsentUse);
            }
        }
        return $ownerConsentUses;
    }
}
