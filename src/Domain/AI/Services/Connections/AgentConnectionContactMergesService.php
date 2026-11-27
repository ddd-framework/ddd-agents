<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactsScope;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContact;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactMergePlan;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\ContactsAgentConnectionAdapter;
use DDD\Infrastructure\Services\DDDService;

/**
 * Merging two people of My Contacts (the provider has no merge): a plan under one approval, executed step by step —
 * survivor update (etag-bound), labels, photo, host links, then the donor's delete. Both are read again first: a donor
 * or survivor changed since the plan refuses the run. A failure before the delete leaves the donor in place, and the
 * same approved plan can run again: a survivor that already carries the planned values skips its update, labels,
 * photo and links are idempotent, a donor already gone means the merge is done. Other contacts and the directory are
 * never merged.
 */
class AgentConnectionContactMergesService
{
    /** @throws AgentConnectionRefusedException */
    public function buildMergePlan(AgentConnectionContact $survivor, AgentConnectionContact $donor): AgentConnectionContactMergePlan
    {
        if (!$survivor->isWritable() || !$donor->isWritable()) {
            throw new AgentConnectionRefusedException('Only two people of My Contacts of one connection can be merged; Other contacts and the directory are read-only.');
        }
        if ($survivor->contactId === $donor->contactId) {
            throw new AgentConnectionRefusedException('Survivor and donor are the same person.');
        }
        $mergePlan = AgentConnectionContactMergePlan::newInstance();
        $mergedSurvivor = clone $survivor;
        $mergePlan->donorContactId = $donor->contactId;
        $mergePlan->donorEtag = $donor->etag;
        $mergePlan->donorName = $donor->name;
        $addedEmailAddresses = array_values(array_diff($donor->emailAddresses, $survivor->emailAddresses));
        if ($addedEmailAddresses !== []) {
            $mergedSurvivor->emailAddresses = [...$survivor->emailAddresses, ...$addedEmailAddresses];
            $mergePlan->fieldNames[] = 'emailAddresses';
        }
        $addedPhoneNumbers = array_values(array_diff($donor->phoneNumbers, $survivor->phoneNumbers));
        if ($addedPhoneNumbers !== []) {
            $mergedSurvivor->phoneNumbers = [...$survivor->phoneNumbers, ...$addedPhoneNumbers];
            $mergePlan->fieldNames[] = 'phoneNumbers';
        }
        if ($donor->name !== '' && $survivor->name === '') {
            $mergedSurvivor->name = $donor->name;
            $mergePlan->fieldNames[] = 'names';
        } elseif ($donor->name !== '' && $donor->name !== $survivor->name) {
            $mergePlan->conflictLines[] = "name: \"{$survivor->name}\" kept, \"{$donor->name}\" dropped";
            $mergePlan->lostLines[] = "the donor's name \"{$donor->name}\"";
        }
        $this->mergeOrganization($survivor, $donor, $mergedSurvivor, $mergePlan);
        if ($donor->notes !== null && $donor->notes !== '' && $donor->notes !== $survivor->notes) {
            $mergedSurvivor->notes = trim(($survivor->notes ?? '') . "\n" . $donor->notes);
            $mergePlan->fieldNames[] = 'biographies';
        }
        $mergePlan->labelsToAdd = array_values(array_diff($donor->labels, $survivor->labels));
        if ($donor->hasPhoto && $survivor->hasPhoto) {
            $mergePlan->lostLines[] = "the donor's photo (the survivor keeps its own)";
        }
        $mergePlan->survivor = $mergedSurvivor;
        return $mergePlan;
    }

    /**
     * Runs an approved merge from the operation's stored plan (only through AgentConnectionOperationsService::executeStep)
     *
     * @throws AgentConnectionRefusedException
     */
    public function executeMerge(AgentConnectionOperation $agentConnectionOperation, ContactsAgentConnectionAdapter $contactsAdapter): AgentConnectionSendResult
    {
        $stepArguments = $agentConnectionOperation->stepArguments;
        $plannedSurvivor = $stepArguments->contact;
        $donorContactId = (string)$stepArguments->contactId;
        if ($plannedSurvivor === null || $plannedSurvivor->contactId === '' || $donorContactId === '') {
            throw new AgentConnectionRefusedException('The merge plan names no survivor or no donor.');
        }
        $myContactsScope = AgentConnectionContactsScope::fromBoundaryValues([AgentConnectionContact::SOURCE_CONTACTS], null);
        $currentSurvivor = $contactsAdapter->getContact($plannedSurvivor->contactId, $myContactsScope);
        if ($currentSurvivor === null) {
            throw new AgentConnectionRefusedException('The surviving contact no longer exists; nothing was merged. Read both contacts again and plan a new merge.');
        }
        $currentDonor = $contactsAdapter->getContact($donorContactId, $myContactsScope);
        $sendResult = AgentConnectionSendResult::newInstance();
        $sendResult->externalId = $plannedSurvivor->contactId;
        if ($currentDonor === null) {
            // the donor's delete is the last step: a donor already gone is a merge that ran to its end
            return $sendResult;
        }
        if ($currentDonor->etag !== $stepArguments->donorEtag) {
            throw new AgentConnectionRefusedException('The donor contact changed since the merge was planned; nothing was merged. Read both contacts again and plan a new merge.');
        }
        $this->updateSurvivor($contactsAdapter, $plannedSurvivor, $currentSurvivor, $stepArguments->fieldNames);
        foreach ($stepArguments->labelsToAdd as $groupId) {
            if (!in_array((string)$groupId, $currentSurvivor->labels, true)) {
                $contactsAdapter->changeContactGroupMembers((string)$groupId, null, [$plannedSurvivor->contactId], []);
            }
        }
        if ($currentDonor->hasPhoto) {
            $contactsAdapter->moveContactPhoto($donorContactId, $plannedSurvivor->contactId);
        }
        /** @var AgentConnectionContactLinksService $agentConnectionContactLinksService */
        $agentConnectionContactLinksService = DDDService::instance()->getService(AgentConnectionContactLinksService::class);
        $agentConnectionContactLinksService->retargetHostLinks($agentConnectionOperation->agentConnection, $donorContactId, $plannedSurvivor->contactId, (int)$agentConnectionOperation->actingAccountId);
        $contactsAdapter->deleteContact($donorContactId);
        return $sendResult;
    }

    /**
     * The survivor's update, bound to the etag of the plan: an unchanged survivor is updated (also checked when no
     * field changes), a survivor that already carries the planned values (an earlier run of this plan) is left as it
     * is, any other change refuses.
     *
     * @param string[] $fieldNames
     * @throws AgentConnectionRefusedException
     */
    protected function updateSurvivor(ContactsAgentConnectionAdapter $contactsAdapter, AgentConnectionContact $plannedSurvivor, AgentConnectionContact $currentSurvivor, array $fieldNames): void
    {
        if ($currentSurvivor->etag === $plannedSurvivor->etag) {
            if ($fieldNames !== []) {
                $contactsAdapter->updateContact($plannedSurvivor, $fieldNames);
            }
            return;
        }
        if ($fieldNames === [] || !$this->carriesPlannedValues($currentSurvivor, $plannedSurvivor, $fieldNames)) {
            throw new AgentConnectionRefusedException('The surviving contact changed since the merge was planned; nothing was merged. Read both contacts again and plan a new merge.');
        }
    }

    /** @param string[] $fieldNames */
    public function carriesPlannedValues(AgentConnectionContact $currentSurvivor, AgentConnectionContact $plannedSurvivor, array $fieldNames): bool
    {
        foreach ($fieldNames as $fieldName) {
            $carriesValue = match ($fieldName) {
                'emailAddresses' => array_diff($plannedSurvivor->emailAddresses, $currentSurvivor->emailAddresses) === [],
                'phoneNumbers' => array_diff($plannedSurvivor->phoneNumbers, $currentSurvivor->phoneNumbers) === [],
                'names' => $currentSurvivor->name === $plannedSurvivor->name,
                'organizations' => $currentSurvivor->organization === $plannedSurvivor->organization && $currentSurvivor->jobTitle === $plannedSurvivor->jobTitle,
                'biographies' => trim((string)$currentSurvivor->notes) === trim((string)$plannedSurvivor->notes),
                default => false,
            };
            if (!$carriesValue) {
                return false;
            }
        }
        return true;
    }

    protected function mergeOrganization(AgentConnectionContact $survivor, AgentConnectionContact $donor, AgentConnectionContact $mergedSurvivor, AgentConnectionContactMergePlan $mergePlan): void
    {
        if ($donor->organization === null && $donor->jobTitle === null) {
            return;
        }
        if ($survivor->organization === null && $survivor->jobTitle === null) {
            $mergedSurvivor->organization = $donor->organization;
            $mergedSurvivor->jobTitle = $donor->jobTitle;
            $mergePlan->fieldNames[] = 'organizations';
            return;
        }
        if ($donor->organization !== $survivor->organization || $donor->jobTitle !== $survivor->jobTitle) {
            $mergePlan->conflictLines[] = 'organization: "' . trim(($survivor->organization ?? '') . ' ' . ($survivor->jobTitle ?? '')) . '" kept';
            $mergePlan->lostLines[] = 'the donor\'s organization "' . trim(($donor->organization ?? '') . ' ' . ($donor->jobTitle ?? '')) . '"';
        }
    }
}
