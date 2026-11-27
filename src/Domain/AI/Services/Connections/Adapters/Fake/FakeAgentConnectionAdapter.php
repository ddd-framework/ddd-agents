<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Fake;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionBusyPeriod;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionBusyPeriods;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendar;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendarEvent;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendarEvents;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendars;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatMessage;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatMessages;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatPost;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContact;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContacts;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactGroup;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactGroups;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactsScope;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFile;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFileContent;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFiles;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFakeRecordedStep;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFakeRecordedSteps;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionInbound;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionInbounds;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailDraft;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessage;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessages;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\AgentConnectionAdapter;
use DDD\Domain\AI\Traits\Connections\FakeDriveAgentConnectionAdapterTrait;
use DDD\Domain\AI\Services\Connections\Adapters\GoogleDocumentsAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\DriveAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\CalendarAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\ChatAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\ContactsAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\FilesAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\InboundAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\MailAgentConnectionAdapter;
use DDD\Infrastructure\Libs\Config;

/**
 * The test double of every capability, for evals and unit tests: never reaches a provider. Its world is a fixture
 * (`AI.agentConnections.adapters.FAKE.fixture`, or {@see seed()}) of mails, calendars, events, contacts, files and
 * chat threads; every send, create and post is recorded in {@see $recordedSteps} and added to the world, so a later
 * read sees it. Inbound deliveries are JSON lists of {@see AgentConnectionInbound} fields, unsigned (a test only):
 * the adapter refuses to work in the production stage.
 */
#[Exclude]
class FakeAgentConnectionAdapter extends AgentConnectionAdapter implements MailAgentConnectionAdapter, CalendarAgentConnectionAdapter, ContactsAgentConnectionAdapter, FilesAgentConnectionAdapter, ChatAgentConnectionAdapter, InboundAgentConnectionAdapter, DriveAgentConnectionAdapter, GoogleDocumentsAgentConnectionAdapter
{
    use FakeDriveAgentConnectionAdapterTrait;

    public const string PROVIDER = AgentConnection::PROVIDER_FAKE;

    /** @var array<string, mixed>|null the world of all fake connections of this process */
    protected static ?array $world = null;

    /** @var AgentConnectionFakeRecordedSteps|null every outward step, oldest first */
    public static ?AgentConnectionFakeRecordedSteps $recordedSteps = null;

    /** @param array<string, mixed> $world mails, events, calendars, contacts, files, chat, users */
    public static function seed(array $world): void
    {
        static::$world = $world;
        static::$recordedSteps = new AgentConnectionFakeRecordedSteps();
    }

    public static function reset(): void
    {
        static::$world = null;
        static::$recordedSteps = new AgentConnectionFakeRecordedSteps();
    }

    /** @return array<string, mixed> */
    protected static function world(): array
    {
        if (static::$world === null) {
            $fixture = Config::get('AI.agentConnections.adapters.FAKE.fixture');
            static::$world = is_array($fixture) ? $fixture : [];
        }
        return static::$world;
    }

    public function getCapabilities(): array
    {
        return [
            AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionPermission::CAPABILITY_CALENDAR,
            AgentConnectionPermission::CAPABILITY_CONTACTS, AgentConnectionPermission::CAPABILITY_FILES,
            AgentConnectionPermission::CAPABILITY_CHAT,
        ];
    }

    public function searchMail(string $query, int $maximumResults): AgentConnectionMailMessages
    {
        $mailMessages = new AgentConnectionMailMessages();
        foreach (array_reverse(static::world()['mails'] ?? []) as $mail) {
            $haystack = mb_strtolower(implode(' ', [$mail['from'] ?? '', $mail['subject'] ?? '', $mail['bodyText'] ?? '']));
            if ($query === '' || str_contains($haystack, mb_strtolower($query))) {
                $mailMessage = $this->toMailMessage($mail);
                $mailMessage->bodyText = null;
                $mailMessages->add($mailMessage);
            }
            if ($mailMessages->count() >= $maximumResults) {
                break;
            }
        }
        return $mailMessages;
    }

    public function readMailThread(string $threadId): AgentConnectionMailMessages
    {
        $mailMessages = new AgentConnectionMailMessages();
        foreach (static::world()['mails'] ?? [] as $mail) {
            if (($mail['threadId'] ?? $mail['messageId'] ?? '') === $threadId) {
                $addedElement = $this->toMailMessage($mail);
                $mailMessages->add($addedElement);
            }
        }
        if ($mailMessages->count() === 0) {
            throw new AgentConnectionRefusedException("No mail thread $threadId in this mailbox.");
        }
        return $mailMessages;
    }

    public function createMailDraft(AgentConnectionMailDraft $mailDraft): AgentConnectionSendResult
    {
        return $this->recordStep('MAIL:DRAFT', $mailDraft->toObject(false), 'draft-');
    }

    public function sendMail(AgentConnectionMailDraft $mailDraft): AgentConnectionSendResult
    {
        $sendResult = $this->recordStep('MAIL:SEND', $mailDraft->toObject(false), 'sent-');
        static::$world['mails'][] = [
            'messageId' => $sendResult->externalId, 'threadId' => $mailDraft->threadId ?? $sendResult->externalId,
            'from' => (string)($this->agentConnection?->externalAddress ?? 'me'), 'to' => $mailDraft->to, 'subject' => $mailDraft->subject,
            'bodyText' => $mailDraft->bodyText, 'labels' => ['SENT'],
        ];
        return $sendResult;
    }

    public function listCalendars(): AgentConnectionCalendars
    {
        $calendars = new AgentConnectionCalendars();
        foreach (static::world()['calendars'] ?? [['calendarId' => 'primary', 'name' => 'Primary', 'isPrimary' => true]] as $calendar) {
            $addedElement = static::fill(new AgentConnectionCalendar(), $calendar);
            $calendars->add($addedElement);
        }
        return $calendars;
    }

    public function getFreeBusy(array $calendarIds, DateTime $fromTime, DateTime $toTime): AgentConnectionBusyPeriods
    {
        $busyPeriods = new AgentConnectionBusyPeriods();
        foreach ($this->eventsBetween($fromTime, $toTime) as $event) {
            if ($calendarIds === [] || in_array($event['calendarId'] ?? 'primary', $calendarIds, true)) {
                $addedElement = static::fill(new AgentConnectionBusyPeriod(), ['calendarId' => $event['calendarId'] ?? 'primary', 'startsAt' => $event['startsAt'], 'endsAt' => $event['endsAt']]);
                $busyPeriods->add($addedElement);
            }
        }
        return $busyPeriods;
    }

    public function listCalendarEvents(string $calendarId, DateTime $fromTime, DateTime $toTime, ?string $query, int $maximumResults): AgentConnectionCalendarEvents
    {
        $calendarEvents = new AgentConnectionCalendarEvents();
        foreach ($this->eventsBetween($fromTime, $toTime) as $event) {
            if (($event['calendarId'] ?? 'primary') !== $calendarId) {
                continue;
            }
            if ($query !== null && $query !== '' && !str_contains(mb_strtolower((string)($event['title'] ?? '')), mb_strtolower($query))) {
                continue;
            }
            $addedElement = static::fill(new AgentConnectionCalendarEvent(), $event);
            $calendarEvents->add($addedElement);
            if ($calendarEvents->count() >= $maximumResults) {
                break;
            }
        }
        return $calendarEvents;
    }

    public function createCalendarEvent(AgentConnectionCalendarEvent $calendarEvent): AgentConnectionSendResult
    {
        $sendResult = $this->recordStep('CALENDAR:CREATE', $calendarEvent->toObject(false), 'event-');
        $storedEvent = (array)json_decode((string)json_encode($calendarEvent->toObject(false)), true);
        $storedEvent['eventId'] = $sendResult->externalId;
        static::$world['events'][] = $storedEvent;
        return $sendResult;
    }

    public function searchContacts(string $query, int $maximumResults, ?AgentConnectionContactsScope $contactsScope = null): AgentConnectionContacts
    {
        $contactsScope ??= AgentConnectionContactsScope::newInstance();
        $contacts = AgentConnectionContacts::newInstance();
        $normalizedQuery = mb_strtolower(trim($query));
        foreach ($this->findScopedContacts($contactsScope) as $contact) {
            if ($normalizedQuery !== '' && !static::hasWordStartingWith($contact, $normalizedQuery)) {
                continue;
            }
            $contacts->add($contact);
            if ($contacts->count() >= $maximumResults) {
                break;
            }
        }
        return $contacts;
    }

    public function findContactsByDomainOrOrganization(string $term, int $maximumResults, AgentConnectionContactsScope $contactsScope): AgentConnectionContacts
    {
        $contacts = AgentConnectionContacts::newInstance();
        $normalizedTerm = mb_strtolower(ltrim(trim($term), '@'));
        foreach ($this->findScopedContacts($contactsScope) as $contact) {
            $matches = $contact->organization !== null && $normalizedTerm !== '' && str_contains(mb_strtolower($contact->organization), $normalizedTerm);
            foreach ($contact->emailAddresses as $emailAddress) {
                $matches = $matches || ($normalizedTerm !== '' && str_ends_with(mb_strtolower($emailAddress), '@' . $normalizedTerm));
            }
            if ($matches) {
                $contacts->add($contact);
            }
            if ($contacts->count() >= $maximumResults) {
                break;
            }
        }
        return $contacts;
    }

    public function getContact(string $contactId, AgentConnectionContactsScope $contactsScope): ?AgentConnectionContact
    {
        foreach ($this->findScopedContacts($contactsScope) as $contact) {
            if ($contact->contactId === $contactId) {
                return $contact;
            }
        }
        return null;
    }

    public function listContactGroups(AgentConnectionContactsScope $contactsScope): AgentConnectionContactGroups
    {
        $contactGroups = AgentConnectionContactGroups::newInstance();
        if (!$contactsScope->isSourceAllowed(AgentConnectionContact::SOURCE_CONTACTS)) {
            return $contactGroups;
        }
        foreach (static::world()['contactGroups'] ?? [] as $contactGroup) {
            $filledContactGroup = static::fill(AgentConnectionContactGroup::newInstance(), $contactGroup);
            if ($contactsScope->isLabelAllowed($filledContactGroup->groupId)) {
                $contactGroups->add($filledContactGroup);
            }
        }
        return $contactGroups;
    }

    public function listContactGroupMembers(string $groupId, int $maximumResults, AgentConnectionContactsScope $contactsScope): AgentConnectionContacts
    {
        $contacts = AgentConnectionContacts::newInstance();
        if (!$contactsScope->isLabelAllowed($groupId)) {
            return $contacts;
        }
        foreach ($this->findScopedContacts($contactsScope) as $contact) {
            if (in_array($groupId, $contact->labels, true) && $contacts->count() < $maximumResults) {
                $contacts->add($contact);
            }
        }
        return $contacts;
    }

    public function createContact(AgentConnectionContact $contact): AgentConnectionSendResult
    {
        $sendResult = $this->recordStep('CONTACTS:CREATE', $contact->toObject(false), 'people/fake-');
        $storedContact = (array)json_decode((string)json_encode($contact->toObject(false)), true);
        $storedContact['contactId'] = $sendResult->externalId;
        $storedContact['source'] = AgentConnectionContact::SOURCE_CONTACTS;
        $storedContact['etag'] = 'etag-1';
        static::$world['contacts'][] = $storedContact;
        return $sendResult;
    }

    public function updateContact(AgentConnectionContact $contact, array $fieldNames): AgentConnectionSendResult
    {
        $contactIndex = $this->findContactIndex($contact->contactId);
        if ($contactIndex === null) {
            throw new AgentConnectionRefusedException('The contact is gone.');
        }
        if (($contact->etag ?? '') !== (string)(static::$world['contacts'][$contactIndex]['etag'] ?? '')) {
            throw new AgentConnectionRefusedException('The contact changed since it was read: read it again (getContact), show the new difference and ask again. No blind retry.');
        }
        $sendResult = $this->recordStep('CONTACTS:UPDATE', ['contact' => $contact->toObject(false), 'fieldNames' => $fieldNames], 'update-');
        $fieldMap = ['names' => 'name', 'emailAddresses' => 'emailAddresses', 'phoneNumbers' => 'phoneNumbers', 'organizations' => 'organization', 'biographies' => 'notes'];
        foreach ($fieldNames as $fieldName) {
            if (isset($fieldMap[$fieldName])) {
                $propertyName = $fieldMap[$fieldName];
                static::$world['contacts'][$contactIndex][$propertyName] = $contact->$propertyName;
            }
        }
        static::$world['contacts'][$contactIndex]['etag'] = 'etag-' . static::countRecordedSteps();
        $sendResult->externalId = $contact->contactId;
        return $sendResult;
    }

    public function changeContactGroupMembers(?string $groupId, ?string $newGroupName, array $addContactIds, array $removeContactIds): AgentConnectionSendResult
    {
        if ($newGroupName !== null && trim($newGroupName) !== '') {
            $groupId = 'contactGroups/fake-' . (count(static::world()['contactGroups'] ?? []) + 1);
            static::$world['contactGroups'][] = ['groupId' => $groupId, 'name' => trim($newGroupName)];
        }
        if ($groupId === null || $groupId === '') {
            throw new AgentConnectionRefusedException('Name the label (groupId) or a new label name.');
        }
        $sendResult = $this->recordStep('CONTACTS:GROUP_MEMBERS', ['groupId' => $groupId, 'add' => $addContactIds, 'remove' => $removeContactIds], 'members-');
        foreach (static::world()['contacts'] ?? [] as $contactIndex => $storedContact) {
            $labels = (array)($storedContact['labels'] ?? []);
            if (in_array($storedContact['contactId'] ?? '', $addContactIds, true) && !in_array($groupId, $labels, true)) {
                $labels[] = $groupId;
            }
            if (in_array($storedContact['contactId'] ?? '', $removeContactIds, true)) {
                $labels = array_values(array_diff($labels, [$groupId]));
            }
            static::$world['contacts'][$contactIndex]['labels'] = $labels;
        }
        $sendResult->externalId = $groupId;
        return $sendResult;
    }

    public function deleteContact(string $contactId): AgentConnectionSendResult
    {
        $contactIndex = $this->findContactIndex($contactId);
        if ($contactIndex === null || (static::$world['contacts'][$contactIndex]['source'] ?? AgentConnectionContact::SOURCE_CONTACTS) !== AgentConnectionContact::SOURCE_CONTACTS) {
            throw new AgentConnectionRefusedException('Only a person of My Contacts can be deleted.');
        }
        $sendResult = $this->recordStep('CONTACTS:DELETE', ['contactId' => $contactId], 'delete-');
        array_splice(static::$world['contacts'], $contactIndex, 1);
        $sendResult->externalId = $contactId;
        return $sendResult;
    }

    public function moveContactPhoto(string $donorContactId, string $survivorContactId): bool
    {
        $donorIndex = $this->findContactIndex($donorContactId);
        $survivorIndex = $this->findContactIndex($survivorContactId);
        if ($donorIndex === null || $survivorIndex === null || empty(static::$world['contacts'][$donorIndex]['hasPhoto']) || !empty(static::$world['contacts'][$survivorIndex]['hasPhoto'])) {
            return false;
        }
        $this->recordStep('CONTACTS:PHOTO', ['from' => $donorContactId, 'to' => $survivorContactId], 'photo-');
        static::$world['contacts'][$survivorIndex]['hasPhoto'] = true;
        return true;
    }

    /** @return AgentConnectionContact[] the fixture's contacts the scope allows */
    protected function findScopedContacts(AgentConnectionContactsScope $contactsScope): array
    {
        $contacts = [];
        foreach (static::world()['contacts'] ?? [] as $storedContact) {
            $contact = static::fill(AgentConnectionContact::newInstance(), $storedContact);
            if ($contactsScope->allowsContact($contact)) {
                $contacts[] = $contact;
            }
        }
        return $contacts;
    }

    protected function findContactIndex(string $contactId): ?int
    {
        foreach (static::world()['contacts'] ?? [] as $contactIndex => $storedContact) {
            if (($storedContact['contactId'] ?? '') === $contactId) {
                return (int)$contactIndex;
            }
        }
        return null;
    }

    protected static function hasWordStartingWith(AgentConnectionContact $contact, string $normalizedQuery): bool
    {
        foreach ([$contact->name, (string)$contact->organization, ...$contact->emailAddresses, ...$contact->phoneNumbers] as $value) {
            foreach ([mb_strtolower($value), ...(preg_split('/[\s@.\-]+/', mb_strtolower($value)) ?: [])] as $word) {
                if (str_starts_with($word, $normalizedQuery)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function searchFiles(string $query, int $maximumResults, array $allowedFolderIds): AgentConnectionFiles
    {
        $files = new AgentConnectionFiles();
        foreach (static::world()['files'] ?? [] as $file) {
            if (!$this->isInAllowedFolders($file, $allowedFolderIds)) {
                continue;
            }
            if ($query === '' || str_contains(mb_strtolower(($file['name'] ?? '') . ' ' . ($file['text'] ?? '')), mb_strtolower($query))) {
                $fileWithoutText = $file;
                unset($fileWithoutText['text']);
                $addedElement = static::fill(new AgentConnectionFile(), $fileWithoutText);
                $files->add($addedElement);
            }
            if ($files->count() >= $maximumResults) {
                break;
            }
        }
        return $files;
    }

    public function readFile(string $fileId, int $maximumCharacters, array $allowedFolderIds): AgentConnectionFileContent
    {
        foreach (static::world()['files'] ?? [] as $file) {
            if (($file['fileId'] ?? '') !== $fileId) {
                continue;
            }
            if (!$this->isInAllowedFolders($file, $allowedFolderIds)) {
                throw new AgentConnectionRefusedException('This file lies outside the folders released for this connection.');
            }
            $fileContent = new AgentConnectionFileContent();
            $fileContent->fileId = $fileId;
            $fileContent->name = (string)($file['name'] ?? '');
            $fileContent->mimeType = (string)($file['mimeType'] ?? 'text/plain');
            $fileContent->text = static::shorten((string)($file['text'] ?? ''), $maximumCharacters);
            $fileContent->isTruncated = mb_strlen((string)($file['text'] ?? '')) > $maximumCharacters;
            return $fileContent;
        }
        throw new AgentConnectionRefusedException("No file $fileId.");
    }

    public function createTextFile(string $name, string $text, ?string $parentFolderId): AgentConnectionSendResult
    {
        $sendResult = $this->recordStep('FILES:CREATE', ['name' => $name, 'text' => $text, 'parentFolderId' => $parentFolderId], 'file-');
        static::$world['files'][] = ['fileId' => $sendResult->externalId, 'name' => $name, 'text' => $text, 'mimeType' => 'text/plain', 'parentIds' => $parentFolderId !== null ? [$parentFolderId] : []];
        return $sendResult;
    }

    public function readChatThread(string $channelId, string $threadId, int $maximumMessages): AgentConnectionChatMessages
    {
        $chatMessages = new AgentConnectionChatMessages();
        foreach (static::world()['chat'] ?? [] as $chatMessage) {
            if (($chatMessage['channelId'] ?? '') === $channelId && (($chatMessage['threadId'] ?? $chatMessage['messageId'] ?? '') === $threadId)) {
                $addedElement = static::fill(new AgentConnectionChatMessage(), $chatMessage);
                $chatMessages->add($addedElement);
            }
        }
        return $chatMessages;
    }

    public function postChatMessage(AgentConnectionChatPost $chatPost): AgentConnectionSendResult
    {
        $sendResult = $this->recordStep('CHAT:SEND', $chatPost->toObject(false), 'post-');
        $sendResult->channelId = $chatPost->channelId;
        $sendResult->threadId = $chatPost->threadId ?? $sendResult->externalId;
        static::$world['chat'][] = ['channelId' => $chatPost->channelId, 'threadId' => $sendResult->threadId, 'messageId' => $sendResult->externalId, 'text' => $chatPost->text, 'isFromAgent' => true];
        return $sendResult;
    }

    public function findVerifiedEmailOfUser(string $externalUserId): ?string
    {
        return static::world()['users'][$externalUserId]['email'] ?? null;
    }

    public function verifyAndParseDelivery(array $headers, string $rawBody, DateTime $receivedAt): AgentConnectionInbounds
    {
        static::refuseInProduction();
        $inbounds = new AgentConnectionInbounds();
        foreach ((array)json_decode($rawBody, true) as $inboundFields) {
            $addedElement = static::fill(new AgentConnectionInbound(), (array)$inboundFields);
            $inbounds->add($addedElement);
        }
        return $inbounds;
    }

    public function forConnection(AgentConnection $agentConnection): static
    {
        static::refuseInProduction();
        return parent::forConnection($agentConnection);
    }

    /** The test double never runs in production: its inbound is unsigned and its world is a fixture */
    public static function refuseInProduction(): void
    {
        if (AgentConnectionsConfiguration::current()->isProductionStage()) {
            throw new ForbiddenException('The FAKE connection adapter is not available in production.');
        }
    }

    public static function countRecordedSteps(): int
    {
        return static::$recordedSteps?->count() ?? 0;
    }

    /** @param array<string, mixed> $payload */
    protected function recordStep(string $operation, mixed $payload, string $idPrefix): AgentConnectionSendResult
    {
        static::$recordedSteps ??= new AgentConnectionFakeRecordedSteps();
        $recordedStep = AgentConnectionFakeRecordedStep::of($operation, $payload);
        static::$recordedSteps->add($recordedStep);
        $sendResult = AgentConnectionSendResult::newInstance();
        $sendResult->externalId = $idPrefix . static::countRecordedSteps();
        return $sendResult;
    }

    /** @return array<int, array<string, mixed>> */
    protected function eventsBetween(DateTime $fromTime, DateTime $toTime): array
    {
        $fromUnixTime = $fromTime->getTimestamp();
        $toUnixTime = $toTime->getTimestamp();
        $events = [];
        foreach (static::world()['events'] ?? [] as $event) {
            if ((static::toTimestamp((string)($event['endsAt'] ?? '')) ?? 0) > $fromUnixTime && (static::toTimestamp((string)($event['startsAt'] ?? '')) ?? 0) < $toUnixTime) {
                $events[] = $event;
            }
        }
        return $events;
    }

    protected static function toTimestamp(string $time): ?int
    {
        $parsedTime = static::parseTime($time);
        return $parsedTime?->getTimestamp();
    }

    /** An ISO 8601 time of the fixture (with offset or a plain date) as a framework DateTime; null when it is none */
    protected static function parseTime(string $time): ?DateTime
    {
        if (trim($time) === '') {
            return null;
        }
        $parsedTime = DateTime::fromString($time, [DATE_ATOM, 'Y-m-d\\TH:i:sP', 'Y-m-d\\TH:i:s.vP', '!Y-m-d']);
        return $parsedTime instanceof DateTime ? $parsedTime : null;
    }

    /** @param array<string, mixed> $file @param string[] $allowedFolderIds */
    protected function isInAllowedFolders(array $file, array $allowedFolderIds): bool
    {
        return $allowedFolderIds === [] || array_intersect((array)($file['parentIds'] ?? []), $allowedFolderIds) !== [];
    }

    /** @param array<string, mixed> $mail */
    protected function toMailMessage(array $mail): AgentConnectionMailMessage
    {
        $mailMessage = static::fill(new AgentConnectionMailMessage(), $mail);
        $mailMessage->threadId ??= $mailMessage->messageId;
        $mailMessage->snippet = $mailMessage->snippet !== '' ? $mailMessage->snippet : static::shorten((string)($mail['bodyText'] ?? ''), 140);
        return $mailMessage;
    }

    /**
     * @template T of object
     * @param T $valueObject
     * @param array<string, mixed> $values
     * @return T
     */
    protected static function fill(object $valueObject, array $values): object
    {
        foreach ($values as $propertyName => $value) {
            if (!property_exists($valueObject, $propertyName)) {
                continue;
            }
            $propertyType = (new ReflectionProperty($valueObject, $propertyName))->getType();
            if ($propertyType instanceof ReflectionNamedType && is_a($propertyType->getName(), DateTime::class, true) && is_string($value)) {
                $value = static::parseTime($value);
            }
            $valueObject->$propertyName = $value;
        }
        return $valueObject;
    }
}
