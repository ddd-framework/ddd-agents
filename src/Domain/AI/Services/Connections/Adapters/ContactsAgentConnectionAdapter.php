<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContact;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactGroups;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContacts;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactsScope;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;

/**
 * Capability CONTACTS: My Contacts (read/write), Other contacts and the directory (read-only), the
 * labels of My Contacts. Every read honours the scope (enabled sources, label allowlist); every write is called only
 * by the operations gate after the level and the approval.
 */
interface ContactsAgentConnectionAdapter
{
    /** Prefix search over names, nicknames, e-mails, phones and organizations of the allowed sources */
    public function searchContacts(string $query, int $maximumResults, ?AgentConnectionContactsScope $contactsScope = null): AgentConnectionContacts;

    /** The domain/organization path: the full list (paged) filtered locally by e-mail domain or organization substring */
    public function findContactsByDomainOrOrganization(string $term, int $maximumResults, AgentConnectionContactsScope $contactsScope): AgentConnectionContacts;

    /** One person with its labels and etag; null when it is gone or outside the scope */
    public function getContact(string $contactId, AgentConnectionContactsScope $contactsScope): ?AgentConnectionContact;

    public function listContactGroups(AgentConnectionContactsScope $contactsScope): AgentConnectionContactGroups;

    public function listContactGroupMembers(string $groupId, int $maximumResults, AgentConnectionContactsScope $contactsScope): AgentConnectionContacts;

    /** Creates a contact in My Contacts, optionally in labels; a contactId of Other contacts is copied instead */
    public function createContact(AgentConnectionContact $contact): AgentConnectionSendResult;

    /**
     * Changes the named fields of a contact, bound to `$contact->etag` (a stale etag fails: read again, new diff).
     *
     * @param string[] $fieldNames names, emailAddresses, phoneNumbers, organizations, biographies
     */
    public function updateContact(AgentConnectionContact $contact, array $fieldNames): AgentConnectionSendResult;

    /**
     * Adds and removes people of one label; `$newGroupName` creates the label first (then `$groupId` is ignored).
     *
     * @param string[] $addContactIds
     * @param string[] $removeContactIds
     */
    public function changeContactGroupMembers(?string $groupId, ?string $newGroupName, array $addContactIds, array $removeContactIds): AgentConnectionSendResult;

    public function deleteContact(string $contactId): AgentConnectionSendResult;

    /** Moves the donor's photo to the survivor when the survivor has none; false when there is nothing to move */
    public function moveContactPhoto(string $donorContactId, string $survivorContactId): bool;
}
