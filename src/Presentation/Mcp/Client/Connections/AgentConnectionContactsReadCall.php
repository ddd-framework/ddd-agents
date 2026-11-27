<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContact;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactGroups;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContacts;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactsScope;
use DDD\Domain\AI\Services\Connections\Adapters\ContactsAgentConnectionAdapter;

/**
 * One provider read of the contacts tools, handed to the operations gate as its provider call (an invokable object
 * instead of a closure): the gate checks level, audience and status, then invokes it and audits the outcome.
 */
class AgentConnectionContactsReadCall
{
    public const string KIND_SEARCH = 'SEARCH';
    public const string KIND_DOMAIN = 'DOMAIN';
    public const string KIND_GET = 'GET';
    public const string KIND_GROUPS = 'GROUPS';
    public const string KIND_GROUP_MEMBERS = 'GROUP_MEMBERS';

    public function __construct(
        protected ContactsAgentConnectionAdapter $contactsAdapter,
        protected AgentConnectionContactsScope $contactsScope,
        protected string $kind,
        protected string $argument = '',
        protected int $maximumResults = 10
    ) {
    }

    public function __invoke(): AgentConnectionContacts|AgentConnectionContactGroups|AgentConnectionContact|null
    {
        return match ($this->kind) {
            self::KIND_SEARCH => $this->contactsAdapter->searchContacts($this->argument, $this->maximumResults, $this->contactsScope),
            self::KIND_DOMAIN => $this->contactsAdapter->findContactsByDomainOrOrganization($this->argument, $this->maximumResults, $this->contactsScope),
            self::KIND_GET => $this->contactsAdapter->getContact($this->argument, $this->contactsScope),
            self::KIND_GROUPS => $this->contactsAdapter->listContactGroups($this->contactsScope),
            self::KIND_GROUP_MEMBERS => $this->contactsAdapter->listContactGroupMembers($this->argument, $this->maximumResults, $this->contactsScope),
            default => null,
        };
    }
}
