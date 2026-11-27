<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections;

use DDD\Domain\AI\Services\Connections\AgentConnectionReach;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContact;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactGroups;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContacts;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactsScope;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsUntrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\ContactsAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionContactLinksService;
use DDD\Domain\AI\Services\Connections\AgentConnectionContactMergesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionContactCreateRequestDto;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionContactDeleteRequestDto;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionContactGetRequestDto;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionContactGroupMembersChangeRequestDto;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionContactGroupMembersRequestDto;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionContactGroupsRequestDto;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionContactMergeRequestDto;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionContactsSearchRequestDto;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionContactUpdateRequestDto;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionResultMcpResponseDto;
use Throwable;

/**
 * Contacts through connections (skill `Agent.Connections.Contacts`, package id `agent.connections.contacts`): My
 * Contacts, Other contacts and the directory of every usable connection, within the reach's sources and label
 * allowlist (boundaries CONTACT_SOURCE, CONTACT_LABEL of the connection and the release, fail closed). Reads live,
 * every write through the gate with its own yes: create, update (etag-bound), labels, merge (one plan), delete. Equal
 * e-mail is a hint, never an identity: nothing is merged unless the owner asks for exactly that merge.
 */
#[MCPRequiresAccountRights(AgentConnectionsAccountRights::USE_AGENT_CONNECTIONS)]
class AgentConnectionsContactsMCPPackage extends AgentConnectionsBaseMCPPackage
{
    public const string CAPABILITY = AgentConnectionPermission::CAPABILITY_CONTACTS;

    public static ?string $name = 'agent.connections.contacts';

    public static ?string $description = 'Contacts of connected accounts (own contacts, other contacts, the directory): find people, read one, labels; create, change, label, merge or delete with approval.';

    /**
     * Finds people. Without `connection` every usable contacts connection is searched; each hit's `source` names its
     * connection and source, and hits sharing an e-mail carry `sameEmailAs` (a hint: they stay separate people). Name
     * search is by prefix (name, e-mail, phone, organization); for "everyone @example.org" or "everyone at Example" set
     * `domainOrOrganization`. Several candidates: ask the owner which one; never guess a recipient.
     *
     * @example {"query": "Anna Schmidt"}
     * @example {"query": "example.org", "domainOrOrganization": true}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('contact fields and notes are written by others')]
    #[MCPToolSummary('Find people in the contacts of one or all usable connections (prefix search, or e-mail domain / organization).')]
    public function findContacts(AgentConnectionContactsSearchRequestDto $requestDto, AgentToolCallProvenance $callProvenance): AgentConnectionResultMcpResponseDto
    {
        $maximumResults = max(1, min(30, $requestDto->maximumResults));
        $readKind = $requestDto->domainOrOrganization ? AgentConnectionContactsReadCall::KIND_DOMAIN : AgentConnectionContactsReadCall::KIND_SEARCH;
        if ($requestDto->connection !== null && trim($requestDto->connection) !== '') {
            $agentConnection = $this->resolveConnection($callProvenance, $requestDto->connection);
            $responseDto = $this->newResponse($agentConnection);
            $responseDto->contacts = $this->readContacts($agentConnection, $callProvenance, $readKind, $requestDto->query, $maximumResults);
            $this->markSameEmail($responseDto->contacts);
            return $responseDto;
        }
        $responseDto = new AgentConnectionResultMcpResponseDto();
        $responseDto->connection = 'all';
        $responseDto->contacts = AgentConnectionContacts::newInstance();
        $failures = [];
        foreach ($this->findUsableConnections($callProvenance)->getElements() as $agentConnection) {
            if ($this->getAgentConnectionsService()->getEffectiveLevel($agentConnection, static::CAPABILITY, $this->findActingAccountId($callProvenance), AgentConnectionReach::ofCall($callProvenance)) === AgentConnectionPermission::LEVEL_OFF) {
                continue;
            }
            try {
                $foundContacts = $this->readContacts($agentConnection, $callProvenance, $readKind, $requestDto->query, $maximumResults);
            } catch (Throwable $searchFailure) {
                // one connection never blocks the others; the answer names it (an expired one says so)
                $failures[] = $agentConnection->getLabel() . ': ' . mb_substr($searchFailure->getMessage(), 0, 150);
                continue;
            }
            foreach ($foundContacts->getElements() as $contact) {
                $contact->source = $agentConnection->getLabel() . ' / ' . $contact->source;
                $responseDto->contacts->add($contact);
            }
        }
        $this->markSameEmail($responseDto->contacts);
        $responseDto->note = $failures !== [] ? 'Not searched: ' . implode('; ', $failures) : null;
        return $responseDto;
    }

    /**
     * Reads one person with its labels, its etag (needed by updateContact) and the application's records linked to it.
     *
     * @example {"connection": "Team contacts", "contactId": "people/c123"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ, resultIsReusedWithinTurn: false)]
    #[MCPToolReturnsUntrustedContent('contact fields and notes are written by others')]
    #[MCPToolSummary('Read one person of a contacts connection with labels, etag and linked records.')]
    public function getContact(AgentConnectionContactGetRequestDto $requestDto, AgentToolCallProvenance $callProvenance): AgentConnectionResultMcpResponseDto
    {
        $agentConnection = $this->resolveConnection($callProvenance, $requestDto->connection);
        $responseDto = $this->newResponse($agentConnection);
        $contact = $this->readOne($agentConnection, $callProvenance, $requestDto->contactId, 'getContact');
        if ($contact === null) {
            $responseDto->note = 'Unavailable: the person is gone, moved, or outside what this connection may show.';
            return $responseDto;
        }
        /** @var AgentConnectionContactLinksService $agentConnectionContactLinksService */
        $agentConnectionContactLinksService = DDDService::instance()->getService(AgentConnectionContactLinksService::class);
        $contact->hostLinks = $agentConnectionContactLinksService->findHostLinksOfPerson($agentConnection, $contact, $this->findActingAccountId($callProvenance));
        $responseDto->contact = $contact;
        return $responseDto;
    }

    /**
     * Lists the labels (contact groups) of My Contacts this connection may show.
     *
     * @example {"connection": "Team contacts"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('List the labels of a contacts connection.')]
    public function listContactGroups(AgentConnectionContactGroupsRequestDto $requestDto, AgentToolCallProvenance $callProvenance): AgentConnectionResultMcpResponseDto
    {
        $agentConnection = $this->resolveConnection($callProvenance, $requestDto->connection);
        $responseDto = $this->newResponse($agentConnection);
        $contactGroups = $this->getOperationsService()->runRead(
            $this->createOperation($agentConnection, AgentConnectionAction::OPERATION_LIST, 'listContactGroups', $callProvenance),
            new AgentConnectionContactsReadCall($this->getContactsAdapter($agentConnection), $this->buildContactsScope($agentConnection, $callProvenance), AgentConnectionContactsReadCall::KIND_GROUPS)
        );
        $responseDto->contactGroups = $contactGroups instanceof AgentConnectionContactGroups ? $contactGroups : AgentConnectionContactGroups::newInstance();
        return $responseDto;
    }

    /**
     * Lists the members of one allowed label.
     *
     * @example {"connection": "Team contacts", "groupId": "contactGroups/abc"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('contact fields and notes are written by others')]
    #[MCPToolSummary('List the members of a label of a contacts connection.')]
    public function listContactGroupMembers(AgentConnectionContactGroupMembersRequestDto $requestDto, AgentToolCallProvenance $callProvenance): AgentConnectionResultMcpResponseDto
    {
        $agentConnection = $this->resolveConnection($callProvenance, $requestDto->connection);
        $responseDto = $this->newResponse($agentConnection);
        $responseDto->contacts = $this->readContacts($agentConnection, $callProvenance, AgentConnectionContactsReadCall::KIND_GROUP_MEMBERS, $requestDto->groupId, max(1, min(200, $requestDto->maximumResults)), 'listContactGroupMembers');
        return $responseDto;
    }

    /**
     * CREATES a contact in My Contacts (optionally in labels), or copies a person of Other contacts there
     * (`copyFromContactId`). Show the owner the fields and get a yes first.
     *
     * @example {"name": "Anna Schmidt", "emailAddresses": ["anna@example.org"], "organization": "Example GmbH"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE, identicalRepeatDoesNewWork: true, isOutwardAction: true)]
    #[MCPToolSummary('Create a contact (or copy one from Other contacts) in a connected account; needs the owner\'s yes.')]
    public function createContact(AgentConnectionContactCreateRequestDto $requestDto, AgentToolCallProvenance $callProvenance): AgentConnectionResultMcpResponseDto
    {
        $agentConnection = $this->resolveConnection($callProvenance, $requestDto->connection);
        $this->requireLabelsAllowed($agentConnection, $callProvenance, $requestDto->labels);
        $contact = AgentConnectionContact::newInstance();
        $contact->contactId = (string)($requestDto->copyFromContactId ?? '');
        $contact->name = $requestDto->name;
        $contact->emailAddresses = array_values($requestDto->emailAddresses);
        $contact->phoneNumbers = array_values($requestDto->phoneNumbers);
        $contact->organization = $requestDto->organization;
        $contact->jobTitle = $requestDto->jobTitle;
        $contact->notes = $requestDto->notes;
        $contact->labels = array_values($requestDto->labels);
        $agentConnectionOperation = $this->createOperation($agentConnection, AgentConnectionAction::OPERATION_CREATE, 'createContact', $callProvenance);
        $agentConnectionOperation->approvalSummary = ($contact->contactId !== '' ? 'Copy contact ' : 'Create contact ') . $contact->name;
        $agentConnectionOperation->approvalContent = $contact->describeForApproval();
        $agentConnectionOperation->stepArguments->contact = $contact;
        $responseDto = $this->newResponse($agentConnection);
        $responseDto->sendResult = $this->getOperationsService()->runOutwardStep($agentConnectionOperation);
        $responseDto->note = 'Contact created.';
        return $responseDto;
    }

    /**
     * CHANGES fields of a person of My Contacts, bound to the etag of your getContact read: show the owner before and
     * after and get a yes. A refusal "changed since it was read" means: getContact again, show the new difference, ask
     * again — never retry blindly. Pass only the changed fields; a list field is the complete new list.
     *
     * @example {"contactId": "people/c123", "etag": "%EgUBAi43PRoEAQIFByIM", "phoneNumbers": ["+49 30 1234567"]}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE, isOutwardAction: true)]
    #[MCPToolSummary('Change fields of a contact (etag-bound); needs the owner\'s yes to the before/after.')]
    public function updateContact(AgentConnectionContactUpdateRequestDto $requestDto, AgentToolCallProvenance $callProvenance): AgentConnectionResultMcpResponseDto
    {
        $agentConnection = $this->resolveConnection($callProvenance, $requestDto->connection);
        $currentContact = $this->readOne($agentConnection, $callProvenance, $requestDto->contactId, 'updateContact');
        if ($currentContact === null || !$currentContact->isWritable()) {
            throw new AgentConnectionRefusedException('Only a person of My Contacts this connection may show can be changed.');
        }
        $changedContact = clone $currentContact;
        $changedContact->etag = $requestDto->etag;
        $fieldNames = [];
        if ($requestDto->name !== null) {
            $changedContact->name = $requestDto->name;
            $fieldNames[] = 'names';
        }
        if ($requestDto->emailAddresses !== null) {
            $changedContact->emailAddresses = array_values($requestDto->emailAddresses);
            $fieldNames[] = 'emailAddresses';
        }
        if ($requestDto->phoneNumbers !== null) {
            $changedContact->phoneNumbers = array_values($requestDto->phoneNumbers);
            $fieldNames[] = 'phoneNumbers';
        }
        if ($requestDto->organization !== null || $requestDto->jobTitle !== null) {
            $changedContact->organization = $requestDto->organization ?? $currentContact->organization;
            $changedContact->jobTitle = $requestDto->jobTitle ?? $currentContact->jobTitle;
            $fieldNames[] = 'organizations';
        }
        if ($requestDto->notes !== null) {
            $changedContact->notes = $requestDto->notes;
            $fieldNames[] = 'biographies';
        }
        if ($fieldNames === []) {
            throw new AgentConnectionRefusedException('Name at least one changed field.');
        }
        $agentConnectionOperation = $this->createOperation($agentConnection, AgentConnectionAction::OPERATION_UPDATE, 'updateContact', $callProvenance);
        $agentConnectionOperation->approvalSummary = 'Change contact ' . $currentContact->name;
        $agentConnectionOperation->approvalContent = "Before:\n" . $currentContact->describeForApproval() . "\n\nAfter:\n" . $changedContact->describeForApproval();
        $agentConnectionOperation->stepArguments->contact = $changedContact;
        $agentConnectionOperation->stepArguments->fieldNames = array_values($fieldNames);
        $responseDto = $this->newResponse($agentConnection);
        $responseDto->sendResult = $this->getOperationsService()->runOutwardStep($agentConnectionOperation);
        $responseDto->note = 'Contact changed.';
        return $responseDto;
    }

    /**
     * ADDS and REMOVES people of one allowed label, or creates a label (`newGroupName`). One yes per batch.
     *
     * @example {"groupId": "contactGroups/abc", "addContactIds": ["people/c1", "people/c2"]}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE, isOutwardAction: true)]
    #[MCPToolSummary('Add/remove people of a label or create a label; needs the owner\'s yes.')]
    public function changeContactGroupMembers(AgentConnectionContactGroupMembersChangeRequestDto $requestDto, AgentToolCallProvenance $callProvenance): AgentConnectionResultMcpResponseDto
    {
        $agentConnection = $this->resolveConnection($callProvenance, $requestDto->connection);
        $isNewGroup = $requestDto->newGroupName !== null && trim($requestDto->newGroupName) !== '';
        if (!$isNewGroup) {
            $this->requireLabelsAllowed($agentConnection, $callProvenance, [(string)$requestDto->groupId]);
        } elseif ($this->buildContactsScope($agentConnection, $callProvenance)->hasLabelAllowlist()) {
            throw new AgentConnectionRefusedException('This connection is limited to released labels; a new label is created by its owner.');
        }
        $operation = $requestDto->addContactIds !== [] || $isNewGroup ? AgentConnectionAction::OPERATION_LINK : AgentConnectionAction::OPERATION_UNLINK;
        $agentConnectionOperation = $this->createOperation($agentConnection, $operation, 'changeContactGroupMembers', $callProvenance);
        $labelName = $isNewGroup ? 'new label "' . trim((string)$requestDto->newGroupName) . '"' : 'label ' . $requestDto->groupId;
        $agentConnectionOperation->approvalSummary = 'Change members of ' . $labelName;
        $agentConnectionOperation->approvalContent = implode("\n", array_filter([
            'Label: ' . $labelName,
            $requestDto->addContactIds !== [] ? 'Add: ' . implode(', ', $requestDto->addContactIds) : null,
            $requestDto->removeContactIds !== [] ? 'Remove: ' . implode(', ', $requestDto->removeContactIds) : null,
        ]));
        $agentConnectionOperation->stepArguments->groupId = $requestDto->groupId;
        $agentConnectionOperation->stepArguments->newGroupName = $requestDto->newGroupName;
        $agentConnectionOperation->stepArguments->addContactIds = array_values($requestDto->addContactIds);
        $agentConnectionOperation->stepArguments->removeContactIds = array_values($requestDto->removeContactIds);
        $responseDto = $this->newResponse($agentConnection);
        $responseDto->sendResult = $this->getOperationsService()->runOutwardStep($agentConnectionOperation);
        $responseDto->note = 'Label members changed.';
        return $responseDto;
    }

    /**
     * MERGES the donor into the survivor (both in My Contacts of this connection) under ONE yes to the shown plan: the
     * survivor gets every donor field, the donor's labels, its photo when the survivor has none and the linked records,
     * then the donor is deleted. The plan names what is lost. Other contacts and the directory are refused.
     *
     * @example {"survivorContactId": "people/c1", "donorContactId": "people/c2"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::DELETE, isOutwardAction: true)]
    #[MCPToolSummary('Merge two contacts into one under one approval of the plan (the donor is deleted).')]
    public function mergeContacts(AgentConnectionContactMergeRequestDto $requestDto, AgentToolCallProvenance $callProvenance): AgentConnectionResultMcpResponseDto
    {
        $agentConnection = $this->resolveConnection($callProvenance, $requestDto->connection);
        $survivor = $this->readOne($agentConnection, $callProvenance, $requestDto->survivorContactId, 'mergeContacts');
        $donor = $this->readOne($agentConnection, $callProvenance, $requestDto->donorContactId, 'mergeContacts');
        if ($survivor === null || $donor === null) {
            throw new AgentConnectionRefusedException('Both people must be readable in this connection.');
        }
        /** @var AgentConnectionContactMergesService $agentConnectionContactMergesService */
        $agentConnectionContactMergesService = DDDService::instance()->getService(AgentConnectionContactMergesService::class);
        $mergePlan = $agentConnectionContactMergesService->buildMergePlan($survivor, $donor);
        $agentConnectionOperation = $this->createOperation($agentConnection, AgentConnectionAction::OPERATION_MERGE, 'mergeContacts', $callProvenance);
        $agentConnectionOperation->approvalSummary = 'Merge contact ' . $donor->name . ' into ' . $survivor->name;
        $agentConnectionOperation->approvalContent = $mergePlan->describeForApproval();
        $agentConnectionOperation->stepArguments->contact = $mergePlan->survivor;
        $agentConnectionOperation->stepArguments->contactId = $mergePlan->donorContactId;
        $agentConnectionOperation->stepArguments->donorEtag = $mergePlan->donorEtag;
        $agentConnectionOperation->stepArguments->fieldNames = array_values($mergePlan->fieldNames);
        $agentConnectionOperation->stepArguments->labelsToAdd = array_values($mergePlan->labelsToAdd);
        $responseDto = $this->newResponse($agentConnection);
        $responseDto->sendResult = $this->getOperationsService()->runOutwardStep($agentConnectionOperation);
        $responseDto->note = 'Contacts merged; the donor was deleted.';
        return $responseDto;
    }

    /**
     * DELETES one person of My Contacts. Its own yes, never in a batch.
     *
     * @example {"contactId": "people/c123"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::DELETE, isOutwardAction: true)]
    #[MCPToolSummary('Delete one contact; needs its own yes.')]
    public function deleteContact(AgentConnectionContactDeleteRequestDto $requestDto, AgentToolCallProvenance $callProvenance): AgentConnectionResultMcpResponseDto
    {
        $agentConnection = $this->resolveConnection($callProvenance, $requestDto->connection);
        $contact = $this->readOne($agentConnection, $callProvenance, $requestDto->contactId, 'deleteContact');
        if ($contact === null || !$contact->isWritable()) {
            throw new AgentConnectionRefusedException('Only a person of My Contacts this connection may show can be deleted.');
        }
        $agentConnectionOperation = $this->createOperation($agentConnection, AgentConnectionAction::OPERATION_DELETE, 'deleteContact', $callProvenance);
        $agentConnectionOperation->approvalSummary = 'Delete contact ' . $contact->name;
        $agentConnectionOperation->approvalContent = $contact->describeForApproval();
        $agentConnectionOperation->stepArguments->contactId = $contact->contactId;
        $responseDto = $this->newResponse($agentConnection);
        $responseDto->sendResult = $this->getOperationsService()->runOutwardStep($agentConnectionOperation);
        $responseDto->note = 'Contact deleted.';
        return $responseDto;
    }

    /**
     * The reach of the acting account on this connection as a contacts scope: sources and labels of the connection and
     * of the release, intersected; an empty intersection allows nothing (fail closed).
     */
    protected function buildContactsScope(AgentConnection $agentConnection, AgentToolCallProvenance $callProvenance): AgentConnectionContactsScope
    {
        $agentConnectionAccess = $this->getAgentConnectionsService()->getAccessOfAccount($agentConnection, $this->findActingAccountId($callProvenance), AgentConnectionReach::ofCall($callProvenance), static::CAPABILITY);
        if ($agentConnectionAccess === null) {
            throw new AgentConnectionRefusedException("The connection \"{$agentConnection->getLabel()}\" is not usable here.");
        }
        return AgentConnectionContactsScope::fromBoundaryValues(
            $agentConnectionAccess->getAllowedBoundaryValues($agentConnection, AgentConnectionContactsScope::BOUNDARY_SOURCE),
            $agentConnectionAccess->getAllowedBoundaryValues($agentConnection, AgentConnectionContactsScope::BOUNDARY_LABEL)
        );
    }

    protected function readContacts(AgentConnection $agentConnection, AgentToolCallProvenance $callProvenance, string $readKind, string $argument, int $maximumResults, string $toolName = 'findContacts'): AgentConnectionContacts
    {
        $operation = $readKind === AgentConnectionContactsReadCall::KIND_GROUP_MEMBERS ? AgentConnectionAction::OPERATION_LIST : AgentConnectionAction::OPERATION_SEARCH;
        $contacts = $this->getOperationsService()->runRead(
            $this->createOperation($agentConnection, $operation, $toolName, $callProvenance),
            new AgentConnectionContactsReadCall($this->getContactsAdapter($agentConnection), $this->buildContactsScope($agentConnection, $callProvenance), $readKind, $argument, $maximumResults)
        );
        return $contacts instanceof AgentConnectionContacts ? $contacts : AgentConnectionContacts::newInstance();
    }

    protected function readOne(AgentConnection $agentConnection, AgentToolCallProvenance $callProvenance, string $contactId, string $toolName): ?AgentConnectionContact
    {
        $contact = $this->getOperationsService()->runRead(
            $this->createOperation($agentConnection, AgentConnectionAction::OPERATION_READ, $toolName, $callProvenance),
            new AgentConnectionContactsReadCall($this->getContactsAdapter($agentConnection), $this->buildContactsScope($agentConnection, $callProvenance), AgentConnectionContactsReadCall::KIND_GET, $contactId)
        );
        return $contact instanceof AgentConnectionContact ? $contact : null;
    }

    /** @param string[] $groupIds */
    protected function requireLabelsAllowed(AgentConnection $agentConnection, AgentToolCallProvenance $callProvenance, array $groupIds): void
    {
        $contactsScope = $this->buildContactsScope($agentConnection, $callProvenance);
        foreach ($groupIds as $groupId) {
            if ($groupId === '' || !$contactsScope->isLabelAllowed($groupId)) {
                throw new AgentConnectionRefusedException('The label "' . $groupId . '" is not released for this connection.');
            }
        }
    }

    protected function getContactsAdapter(AgentConnection $agentConnection): ContactsAgentConnectionAdapter
    {
        /** @var ContactsAgentConnectionAdapter $contactsAdapter */
        $contactsAdapter = $this->getCapabilityAdapter($agentConnection, ContactsAgentConnectionAdapter::class);
        return $contactsAdapter;
    }

    /** Hits that share an e-mail get `sameEmailAs` (the other hits' names): grouped visually, never collapsed */
    protected function markSameEmail(AgentConnectionContacts $contacts): void
    {
        $namesByEmail = [];
        foreach ($contacts->getElements() as $contact) {
            foreach ($contact->emailAddresses as $emailAddress) {
                $namesByEmail[mb_strtolower($emailAddress)][] = $contact->name . ' (' . $contact->source . ')';
            }
        }
        foreach ($contacts->getElements() as $contact) {
            $others = [];
            foreach ($contact->emailAddresses as $emailAddress) {
                foreach ($namesByEmail[mb_strtolower($emailAddress)] ?? [] as $otherName) {
                    if ($otherName !== $contact->name . ' (' . $contact->source . ')') {
                        $others[$otherName] = true;
                    }
                }
            }
            $contact->sameEmailAs = $others !== [] ? implode(', ', array_keys($others)) : null;
        }
    }
}
