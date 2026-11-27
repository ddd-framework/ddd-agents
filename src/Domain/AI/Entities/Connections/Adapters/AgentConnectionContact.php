<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One person of a contact source: My Contacts (read/write), Other contacts (saved from mail,
 * read-only) or the directory (read-only). A person is an external record: equal e-mail is a hint, never an identity.
 */
class AgentConnectionContact extends ValueObject
{
    public const string SOURCE_CONTACTS = 'CONTACTS';
    public const string SOURCE_OTHER_CONTACTS = 'OTHER_CONTACTS';
    public const string SOURCE_DIRECTORY = 'DIRECTORY';

    /** @var string[] */
    public const array SOURCES = [self::SOURCE_CONTACTS, self::SOURCE_OTHER_CONTACTS, self::SOURCE_DIRECTORY];

    /** @var string[] The writable sources (only contact-based people can be written) */
    public const array WRITABLE_SOURCES = [self::SOURCE_CONTACTS];

    /** @var string The provider's resource name (people/c123, otherContacts/c456) */
    public string $contactId = '';
    /** @var string|null The source's stable id (metadata.sources), survives a changed resource name */
    public ?string $sourceId = null;
    /** @var string|null The etag of the read; an update is bound to it (stale → read again, new diff) */
    public ?string $etag = null;
    public string $name = '';
    /** @var string[] */
    public array $emailAddresses = [];
    /** @var string[] */
    public array $phoneNumbers = [];
    public ?string $organization = null;
    public ?string $jobTitle = null;
    /** @var string|null Free text written by others (third-party content) */
    public ?string $notes = null;
    /** @var string[] The resource names of the labels (contact groups) the person belongs to */
    public array $labels = [];
    /** @var string CONTACTS, OTHER_CONTACTS or DIRECTORY; in a search over all connections prefixed with the connection's name */
    public string $source = self::SOURCE_CONTACTS;
    /** @var bool Whether the person has a photo (the photo itself never leaves the backend) */
    public bool $hasPhoto = false;
    /** @var string|null Set in a result when other hits share an e-mail with this one: a hint, the records stay separate */
    public ?string $sameEmailAs = null;
    /** @var string[] Lines describing host records linked to the person (host hook; empty without one) */
    public array $hostLinks = [];

    public function isWritable(): bool
    {
        return in_array($this->source, self::WRITABLE_SOURCES, true);
    }

    public function describeForApproval(): string
    {
        return implode("\n", array_filter([
            'Contact: ' . $this->name,
            $this->emailAddresses !== [] ? 'E-mail: ' . implode(', ', $this->emailAddresses) : null,
            $this->phoneNumbers !== [] ? 'Phone: ' . implode(', ', $this->phoneNumbers) : null,
            $this->organization !== null ? 'Organization: ' . $this->organization : null,
            $this->jobTitle !== null ? 'Job title: ' . $this->jobTitle : null,
            $this->notes !== null ? 'Notes: ' . $this->notes : null,
            $this->labels !== [] ? 'Labels: ' . implode(', ', $this->labels) : null,
        ]));
    }
}
