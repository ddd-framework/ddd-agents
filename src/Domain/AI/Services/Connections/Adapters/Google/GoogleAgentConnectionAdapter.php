<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Google;

use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundary;
use DDD\Domain\AI\Libs\Connections\CappedStreamReader;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionBusyPeriod;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionBusyPeriods;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendar;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendarEvent;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendarEvents;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendars;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContact;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContacts;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactGroup;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactGroups;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactsScope;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFile;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFileContent;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFiles;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailDraft;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessage;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessages;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionOAuthGrant;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionCredentials;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionExpiredException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionProviderException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\AgentConnectionAdapter;
use DDD\Domain\AI\Traits\Connections\GoogleDriveAgentConnectionAdapterTrait;
use DDD\Domain\AI\Services\Connections\Adapters\GoogleDocumentsAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\DriveAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\CalendarAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\ContactsAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\FilesAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\MailAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\OAuthAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionSecretPayload;
use DDD\Domain\AI\Services\Connections\Adapters\Mime\AgentConnectionHtmlTextExtractor;
use DDD\Domain\AI\Services\Connections\Adapters\Mime\AgentConnectionMimeMessageBuilder;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Cache;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Gmail as GoogleGmail;
use Google\Service\Oauth2 as GoogleOauth2;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\PeopleService as GooglePeopleService;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

/**
 * Google Workspace in mode 1: an account connects its own Google account by OAuth (mail, calendar, contacts and the
 * directory, drive). One consent, one connection, several capabilities; the scopes the consent granted decide which
 * capabilities the connection offers ({@see getScopesByCapability()}).
 *
 * Client id and secret are environment variables (`AI.agentConnections.adapters.GOOGLE.*EnvironmentVariable`); the
 * consent asks offline access, so the refresh token outlives the session, and the access token is refreshed before it
 * expires. A refused refresh (`invalid_grant`) marks the connection EXPIRED.
 */
#[Exclude]
class GoogleAgentConnectionAdapter extends AgentConnectionAdapter implements OAuthAgentConnectionAdapter, MailAgentConnectionAdapter, CalendarAgentConnectionAdapter, ContactsAgentConnectionAdapter, FilesAgentConnectionAdapter, DriveAgentConnectionAdapter, GoogleDocumentsAgentConnectionAdapter
{
    use GoogleDriveAgentConnectionAdapterTrait;

    public const string PROVIDER = AgentConnection::PROVIDER_GOOGLE;

    /** @var string[] The authserv-id Gmail's receiving servers write into Authentication-Results */
    public const array TRUSTED_AUTHENTICATION_SERVER_IDS = ['mx.google.com'];

    public const array IDENTITY_SCOPES = ['openid', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/userinfo.profile'];

    /**
     * The scopes per capability and level, cumulative: a level needs its own scopes and those of every level below.
     * The connect asks only for the scopes of the chosen levels; a higher level asks for its additional scopes then
     * (incremental consent).
     *
     * @var array<string, array<string, string[]>> capability => level => the scopes that level adds
     */
    public const array SCOPES_BY_CAPABILITY_AND_LEVEL = [
        AgentConnectionPermission::CAPABILITY_MAIL => [
            AgentConnectionPermission::LEVEL_READ => ['https://www.googleapis.com/auth/gmail.readonly'],
            AgentConnectionPermission::LEVEL_DRAFT => ['https://www.googleapis.com/auth/gmail.compose'],
            AgentConnectionPermission::LEVEL_SEND_WITH_APPROVAL => ['https://www.googleapis.com/auth/gmail.send'],
        ],
        AgentConnectionPermission::CAPABILITY_CALENDAR => [
            AgentConnectionPermission::LEVEL_READ => ['https://www.googleapis.com/auth/calendar.readonly'],
            AgentConnectionPermission::LEVEL_WRITE_WITH_APPROVAL => ['https://www.googleapis.com/auth/calendar.events'],
        ],
        AgentConnectionPermission::CAPABILITY_CONTACTS => [
            AgentConnectionPermission::LEVEL_READ => [
                'https://www.googleapis.com/auth/contacts.readonly', 'https://www.googleapis.com/auth/contacts.other.readonly', 'https://www.googleapis.com/auth/directory.readonly',
            ],
            AgentConnectionPermission::LEVEL_WRITE_WITH_APPROVAL => ['https://www.googleapis.com/auth/contacts'],
        ],
        AgentConnectionPermission::CAPABILITY_FILES => [
            AgentConnectionPermission::LEVEL_READ => ['https://www.googleapis.com/auth/drive.readonly'],
            AgentConnectionPermission::LEVEL_WRITE_WITH_APPROVAL => ['https://www.googleapis.com/auth/drive'],
        ],
    ];

    /** @var array<string, string[]> a granted scope => the narrower scopes it covers */
    public const array SCOPE_IMPLICATIONS = [
        'https://www.googleapis.com/auth/gmail.modify' => ['https://www.googleapis.com/auth/gmail.readonly'],
        'https://www.googleapis.com/auth/calendar' => ['https://www.googleapis.com/auth/calendar.readonly', 'https://www.googleapis.com/auth/calendar.events'],
        'https://www.googleapis.com/auth/contacts' => ['https://www.googleapis.com/auth/contacts.readonly'],
        'https://www.googleapis.com/auth/drive' => ['https://www.googleapis.com/auth/drive.readonly'],
    ];

    /** @var int seconds before the expiry at which the access token is refreshed */
    public const int REFRESH_MARGIN_SECONDS = 120;

    public const int MAXIMUM_BODY_CHARACTERS = 20000;

    /** @var int The most bytes of a file read as text (an export or a download); the rest is never fetched */
    public const int MAXIMUM_FILE_READ_BYTES = 2_000_000;

    public const string DRIVE_FILES_URL = 'https://www.googleapis.com/drive/v3/files/';

    /** @var int Depth limit of the MIME tree walk of a mail */
    public const int MAXIMUM_MIME_DEPTH = 8;

    /** @var int How long a token refresh may hold the per-connection lock */
    public const int REFRESH_LOCK_SECONDS = 20;

    protected ?GoogleClient $authorizedClient = null;

    public function getCapabilities(): array
    {
        return array_keys(static::SCOPES_BY_CAPABILITY_AND_LEVEL);
    }

    public function getScopesByCapability(): array
    {
        $scopesByCapability = [];
        foreach (array_keys(static::SCOPES_BY_CAPABILITY_AND_LEVEL) as $capability) {
            $scopesByCapability[$capability] = $this->getScopesFor($capability, AgentConnectionPermission::LEVEL_SEND_BY_RULE);
        }
        return $scopesByCapability;
    }

    public function getScopesFor(string $capability, string $level): array
    {
        $scopes = [];
        foreach (static::SCOPES_BY_CAPABILITY_AND_LEVEL[$capability] ?? [] as $addingLevel => $addedScopes) {
            if ($level !== AgentConnectionPermission::LEVEL_OFF && AgentConnectionPermission::rankOfLevel($addingLevel) <= AgentConnectionPermission::rankOfLevel($level)) {
                $scopes = [...$scopes, ...$addedScopes];
            }
        }
        return array_values(array_unique($scopes));
    }

    public function findMissingScopes(array $grantedScopes, string $capability, string $level): array
    {
        $coveredScopes = $grantedScopes;
        foreach ($grantedScopes as $grantedScope) {
            $coveredScopes = [...$coveredScopes, ...(static::SCOPE_IMPLICATIONS[$grantedScope] ?? [])];
        }
        return array_values(array_diff($this->getScopesFor($capability, $level), $coveredScopes));
    }

    public function isConfigured(): bool
    {
        return $this->getClientId() !== null && $this->getClientSecret() !== null;
    }

    public function forConnection(AgentConnection $agentConnection): static
    {
        $boundAdapter = parent::forConnection($agentConnection);
        $boundAdapter->authorizedClient = null;
        return $boundAdapter;
    }

    public function buildAuthorizationUrl(string $state, string $redirectUri, array $scopes, ?string $loginHint, string $codeChallenge): string
    {
        $googleClient = $this->createUnauthorizedClient($redirectUri);
        $googleClient->setScopes(array_values(array_unique([...static::IDENTITY_SCOPES, ...$scopes])));
        $googleClient->setAccessType('offline');
        $googleClient->setPrompt('consent');
        $googleClient->setIncludeGrantedScopes(true);
        $googleClient->setState($state);
        if ($loginHint !== null && $loginHint !== '') {
            $googleClient->setLoginHint($loginHint);
        }
        $allowedHostedDomains = (array)($this->getSettings()->allowedHostedDomains);
        if (count($allowedHostedDomains) === 1) {
            $googleClient->setHostedDomain((string)$allowedHostedDomains[0]);
        }
        return $googleClient->createAuthUrl(null, ['code_challenge' => $codeChallenge, 'code_challenge_method' => 'S256']);
    }

    public function exchangeAuthorizationCode(string $authorizationCode, string $redirectUri, string $codeVerifier): AgentConnectionOAuthGrant
    {
        $googleClient = $this->createUnauthorizedClient($redirectUri);
        $tokenAnswer = $googleClient->fetchAccessTokenWithAuthCode($authorizationCode, $codeVerifier);
        if (!is_array($tokenAnswer) || isset($tokenAnswer['error']) || !isset($tokenAnswer['access_token'])) {
            throw new AgentConnectionRefusedException('Google did not accept the consent: ' . (string)($tokenAnswer['error_description'] ?? $tokenAnswer['error'] ?? 'no token'));
        }
        $oAuthGrant = $this->grantFromTokenAnswer($tokenAnswer);
        $googleClient->setAccessToken($tokenAnswer);
        $userInfo = (new GoogleOauth2($googleClient))->userinfo->get();
        $oAuthGrant->externalAccountId = (string)$userInfo->getId();
        $oAuthGrant->emailAddress = $userInfo->getEmail() !== null ? mb_strtolower((string)$userInfo->getEmail()) : null;
        $oAuthGrant->displayName = $userInfo->getName();
        $oAuthGrant->hostedDomain = $userInfo->getHd();
        $allowedHostedDomains = array_map('mb_strtolower', $this->getSettings()->allowedHostedDomains);
        if ($allowedHostedDomains !== [] && !in_array(mb_strtolower((string)$oAuthGrant->hostedDomain), $allowedHostedDomains, true)) {
            throw new AgentConnectionRefusedException('Only accounts of ' . implode(', ', $allowedHostedDomains) . ' may be connected.');
        }
        return $oAuthGrant;
    }

    public function refreshAccessToken(AgentConnectionSecretPayload $secretPayload): AgentConnectionOAuthGrant
    {
        $refreshToken = $secretPayload->get('refreshToken');
        if ($refreshToken === null || $refreshToken === '') {
            throw new AgentConnectionExpiredException('No refresh token: connect again.');
        }
        $googleClient = $this->createUnauthorizedClient(null);
        try {
            $tokenAnswer = $googleClient->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (Throwable $refreshFailure) {
            throw new AgentConnectionProviderException('Google token refresh failed: ' . $refreshFailure->getMessage(), 0, $refreshFailure);
        }
        if (isset($tokenAnswer['error'])) {
            if (in_array($tokenAnswer['error'], ['invalid_grant', 'unauthorized_client'], true)) {
                throw new AgentConnectionExpiredException('Google refused the refresh (' . $tokenAnswer['error'] . '): connect again.');
            }
            throw new AgentConnectionProviderException('Google token refresh failed: ' . (string)$tokenAnswer['error']);
        }
        $oAuthGrant = $this->grantFromTokenAnswer($tokenAnswer);
        $oAuthGrant->refreshToken ??= $refreshToken;
        return $oAuthGrant;
    }

    public function revokeGrant(AgentConnectionSecretPayload $secretPayload): void
    {
        $token = $secretPayload->get('refreshToken') ?? $secretPayload->get('accessToken');
        if ($token !== null && $token !== '') {
            $this->createUnauthorizedClient(null)->revokeToken($token);
        }
    }

    public function searchMail(string $query, int $maximumResults): AgentConnectionMailMessages
    {
        $gmail = new GoogleGmail($this->getAuthorizedClient());
        $mailMessages = new AgentConnectionMailMessages();
        $listAnswer = $gmail->users_messages->listUsersMessages('me', ['q' => $query, 'maxResults' => max(1, min(50, $maximumResults))]);
        foreach ($listAnswer->getMessages() ?? [] as $listedMessage) {
            $gmailMessage = $gmail->users_messages->get('me', $listedMessage->getId(), ['format' => 'metadata', 'metadataHeaders' => ['From', 'To', 'Cc', 'Subject', 'Date', 'Message-ID', 'Authentication-Results']]);
            $addedElement = $this->toMailMessage($gmailMessage, false);
            $mailMessages->add($addedElement);
        }
        return $mailMessages;
    }

    public function readMailThread(string $threadId): AgentConnectionMailMessages
    {
        $gmail = new GoogleGmail($this->getAuthorizedClient());
        $mailMessages = new AgentConnectionMailMessages();
        $gmailThread = $gmail->users_threads->get('me', $threadId, ['format' => 'full']);
        foreach ($gmailThread->getMessages() ?? [] as $gmailMessage) {
            $addedElement = $this->toMailMessage($gmailMessage, true);
            $mailMessages->add($addedElement);
        }
        return $mailMessages;
    }

    public function createMailDraft(AgentConnectionMailDraft $mailDraft): AgentConnectionSendResult
    {
        $gmail = new GoogleGmail($this->getAuthorizedClient());
        $gmailMessage = new GoogleGmail\Message();
        $gmailMessage->setRaw(static::base64UrlEncode(AgentConnectionMimeMessageBuilder::build($mailDraft, (string)$this->requireConnection()->externalAddress, null, true)));
        if ($mailDraft->threadId !== null) {
            $gmailMessage->setThreadId($mailDraft->threadId);
        }
        $gmailDraft = new GoogleGmail\Draft();
        $gmailDraft->setMessage($gmailMessage);
        $createdDraft = $gmail->users_drafts->create('me', $gmailDraft);
        $sendResult = AgentConnectionSendResult::newInstance();
        $sendResult->externalId = (string)$createdDraft->getId();
        $sendResult->threadId = $createdDraft->getMessage()?->getThreadId();
        return $sendResult;
    }

    public function sendMail(AgentConnectionMailDraft $mailDraft): AgentConnectionSendResult
    {
        $gmail = new GoogleGmail($this->getAuthorizedClient());
        $gmailMessage = new GoogleGmail\Message();
        $gmailMessage->setRaw(static::base64UrlEncode(AgentConnectionMimeMessageBuilder::build($mailDraft, (string)$this->requireConnection()->externalAddress, null, true)));
        if ($mailDraft->threadId !== null) {
            $gmailMessage->setThreadId($mailDraft->threadId);
        }
        $sentMessage = $gmail->users_messages->send('me', $gmailMessage);
        $sendResult = AgentConnectionSendResult::newInstance();
        $sendResult->externalId = (string)$sentMessage->getId();
        $sendResult->threadId = $sentMessage->getThreadId();
        return $sendResult;
    }

    public function listCalendars(): AgentConnectionCalendars
    {
        $googleCalendar = new GoogleCalendar($this->getAuthorizedClient());
        $calendars = new AgentConnectionCalendars();
        $allowedCalendarIds = $this->requireConnection()->getScopeBoundaryValues(AgentConnectionScopeBoundary::KIND_CALENDAR);
        foreach ($googleCalendar->calendarList->listCalendarList(['maxResults' => 100])->getItems() ?? [] as $calendarListEntry) {
            if ($allowedCalendarIds !== [] && !in_array($calendarListEntry->getId(), $allowedCalendarIds, true)) {
                continue;
            }
            $calendar = new AgentConnectionCalendar();
            $calendar->calendarId = (string)$calendarListEntry->getId();
            $calendar->name = (string)($calendarListEntry->getSummaryOverride() ?: $calendarListEntry->getSummary());
            $calendar->isPrimary = (bool)$calendarListEntry->getPrimary();
            $calendar->timeZone = $calendarListEntry->getTimeZone();
            $calendar->accessRole = $calendarListEntry->getAccessRole();
            $calendars->add($calendar);
        }
        return $calendars;
    }

    public function getFreeBusy(array $calendarIds, DateTime $fromTime, DateTime $toTime): AgentConnectionBusyPeriods
    {
        $googleCalendar = new GoogleCalendar($this->getAuthorizedClient());
        $freeBusyRequest = new GoogleCalendar\FreeBusyRequest();
        $freeBusyRequest->setTimeMin($fromTime->format(DATE_RFC3339));
        $freeBusyRequest->setTimeMax($toTime->format(DATE_RFC3339));
        $requestItems = [];
        if ($calendarIds === []) {
            throw new AgentConnectionRefusedException('Name the calendars to read free/busy of.');
        }
        foreach ($calendarIds as $calendarId) {
            $requestItem = new GoogleCalendar\FreeBusyRequestItem();
            $requestItem->setId($calendarId);
            $requestItems[] = $requestItem;
        }
        $freeBusyRequest->setItems($requestItems);
        $freeBusyAnswer = $googleCalendar->freebusy->query($freeBusyRequest);
        $busyPeriods = new AgentConnectionBusyPeriods();
        foreach ($freeBusyAnswer->getCalendars() ?? [] as $calendarId => $freeBusyCalendar) {
            foreach ($freeBusyCalendar->getBusy() ?? [] as $timePeriod) {
                $busyPeriod = new AgentConnectionBusyPeriod();
                $busyPeriod->calendarId = (string)$calendarId;
                $busyPeriod->startsAt = static::parseProviderTime((string)$timePeriod->getStart());
                $busyPeriod->endsAt = static::parseProviderTime((string)$timePeriod->getEnd());
                $busyPeriods->add($busyPeriod);
            }
        }
        return $busyPeriods;
    }

    public function listCalendarEvents(string $calendarId, DateTime $fromTime, DateTime $toTime, ?string $query, int $maximumResults): AgentConnectionCalendarEvents
    {
        $this->requireCalendarInBoundaries($calendarId);
        $googleCalendar = new GoogleCalendar($this->getAuthorizedClient());
        $listParameters = ['timeMin' => $fromTime->format(DATE_RFC3339), 'timeMax' => $toTime->format(DATE_RFC3339), 'singleEvents' => true, 'orderBy' => 'startTime', 'maxResults' => max(1, min(250, $maximumResults))];
        if ($query !== null && $query !== '') {
            $listParameters['q'] = $query;
        }
        $calendarEvents = new AgentConnectionCalendarEvents();
        foreach ($googleCalendar->events->listEvents($calendarId, $listParameters)->getItems() ?? [] as $googleEvent) {
            $calendarEvent = new AgentConnectionCalendarEvent();
            $calendarEvent->eventId = (string)$googleEvent->getId();
            $calendarEvent->calendarId = $calendarId;
            $calendarEvent->title = (string)$googleEvent->getSummary();
            $calendarEvent->isAllDay = $googleEvent->getStart()?->getDate() !== null;
            $calendarEvent->startsAt = static::parseProviderTime($googleEvent->getStart()?->getDateTime() ?? $googleEvent->getStart()?->getDate());
            $calendarEvent->endsAt = static::parseProviderTime($googleEvent->getEnd()?->getDateTime() ?? $googleEvent->getEnd()?->getDate());
            $calendarEvent->location = $googleEvent->getLocation();
            $calendarEvent->description = $googleEvent->getDescription() !== null ? static::shorten((string)$googleEvent->getDescription(), 2000) : null;
            $attendeeAddresses = [];
            foreach ($googleEvent->getAttendees() ?? [] as $eventAttendee) {
                if ((string)$eventAttendee->getEmail() !== '') {
                    $attendeeAddresses[] = (string)$eventAttendee->getEmail();
                }
            }
            $calendarEvent->attendees = $attendeeAddresses;
            $calendarEvent->organizer = $googleEvent->getOrganizer()?->getEmail();
            $calendarEvent->status = $googleEvent->getStatus();
            $calendarEvents->add($calendarEvent);
        }
        return $calendarEvents;
    }

    public function createCalendarEvent(AgentConnectionCalendarEvent $calendarEvent): AgentConnectionSendResult
    {
        $calendarId = $calendarEvent->calendarId !== '' ? $calendarEvent->calendarId : 'primary';
        $this->requireCalendarInBoundaries($calendarId);
        $googleCalendar = new GoogleCalendar($this->getAuthorizedClient());
        $googleEvent = new GoogleCalendar\Event();
        $googleEvent->setSummary($calendarEvent->title);
        $googleEvent->setLocation($calendarEvent->location);
        $googleEvent->setDescription($calendarEvent->description);
        $startDateTime = new GoogleCalendar\EventDateTime();
        $endDateTime = new GoogleCalendar\EventDateTime();
        if ($calendarEvent->startsAt === null || $calendarEvent->endsAt === null) {
            throw new AgentConnectionRefusedException('An event needs a start and an end.');
        }
        if ($calendarEvent->isAllDay) {
            $startDateTime->setDate($calendarEvent->startsAt->format('Y-m-d'));
            $endDateTime->setDate($calendarEvent->endsAt->format('Y-m-d'));
        } else {
            $startDateTime->setDateTime($calendarEvent->startsAt->format(DATE_RFC3339));
            $endDateTime->setDateTime($calendarEvent->endsAt->format(DATE_RFC3339));
        }
        $googleEvent->setStart($startDateTime);
        $googleEvent->setEnd($endDateTime);
        $eventAttendees = [];
        foreach ($calendarEvent->attendees as $attendeeAddress) {
            $eventAttendee = new GoogleCalendar\EventAttendee();
            $eventAttendee->setEmail($attendeeAddress);
            $eventAttendees[] = $eventAttendee;
        }
        $googleEvent->setAttendees($eventAttendees);
        $createdEvent = $googleCalendar->events->insert($calendarId, $googleEvent, ['sendUpdates' => $calendarEvent->attendees !== [] ? 'all' : 'none']);
        $sendResult = AgentConnectionSendResult::newInstance();
        $sendResult->externalId = (string)$createdEvent->getId();
        return $sendResult;
    }

    /** @var array<string, bool> Google's contact search reads a lazy cache: one empty query per source and process warms it */
    protected static array $warmedUpContactSources = [];

    public const string CONTACT_READ_MASK = 'names,emailAddresses,phoneNumbers,organizations,biographies,memberships,metadata,photos';
    public const string OTHER_CONTACT_READ_MASK = 'names,emailAddresses,phoneNumbers,metadata';

    /** @var string[] */
    public const array DIRECTORY_SOURCES = ['DIRECTORY_SOURCE_TYPE_DOMAIN_PROFILE', 'DIRECTORY_SOURCE_TYPE_DOMAIN_CONTACT'];

    public function searchContacts(string $query, int $maximumResults, ?AgentConnectionContactsScope $contactsScope = null): AgentConnectionContacts
    {
        $contactsScope ??= AgentConnectionContactsScope::newInstance();
        $peopleService = new GooglePeopleService($this->getAuthorizedClient());
        $contacts = AgentConnectionContacts::newInstance();
        $maximumResults = max(1, min(30, $maximumResults));
        foreach ($contactsScope->allowedSources as $source) {
            if ($source === AgentConnectionContact::SOURCE_CONTACTS && $contactsScope->hasLabelAllowlist()) {
                // with a label allowlist the provider's search cannot filter: list the allowed labels' members instead
                $sourceContacts = $this->filterContactsLocally($this->listMembersOfLabels($peopleService, (array)$contactsScope->allowedLabels, 1000), $query);
            } else {
                $sourceContacts = $this->searchSourceWarmedUp($peopleService, $source, $query, $maximumResults);
            }
            foreach ($sourceContacts->getElements() as $contact) {
                if (!$contactsScope->allowsContact($contact)) {
                    continue;
                }
                $contacts->add($contact);
                if ($contacts->count() >= $maximumResults) {
                    return $contacts;
                }
            }
        }
        return $contacts;
    }

    public function findContactsByDomainOrOrganization(string $term, int $maximumResults, AgentConnectionContactsScope $contactsScope): AgentConnectionContacts
    {
        $peopleService = new GooglePeopleService($this->getAuthorizedClient());
        $contacts = AgentConnectionContacts::newInstance();
        $normalizedTerm = mb_strtolower(ltrim(trim($term), '@'));
        foreach ($contactsScope->allowedSources as $source) {
            $pageToken = null;
            do {
                $pagePeople = [];
                if ($source === AgentConnectionContact::SOURCE_CONTACTS) {
                    $listAnswer = $peopleService->people_connections->listPeopleConnections('people/me', array_filter(['personFields' => static::CONTACT_READ_MASK, 'pageSize' => 1000, 'pageToken' => $pageToken]));
                    $pagePeople = $listAnswer->getConnections() ?? [];
                } elseif ($source === AgentConnectionContact::SOURCE_OTHER_CONTACTS) {
                    $listAnswer = $peopleService->otherContacts->listOtherContacts(array_filter(['readMask' => static::OTHER_CONTACT_READ_MASK, 'pageSize' => 1000, 'pageToken' => $pageToken]));
                    $pagePeople = $listAnswer->getOtherContacts() ?? [];
                } else {
                    $listAnswer = $peopleService->people->listDirectoryPeople(array_filter(['readMask' => static::CONTACT_READ_MASK, 'sources' => static::DIRECTORY_SOURCES, 'pageSize' => 1000, 'pageToken' => $pageToken]));
                    $pagePeople = $listAnswer->getPeople() ?? [];
                }
                $pageToken = $listAnswer->getNextPageToken();
                foreach ($pagePeople as $person) {
                    $contact = $this->toContact($person, $source);
                    if ($contact === null || !$contactsScope->allowsContact($contact) || !static::matchesDomainOrOrganization($contact, $normalizedTerm)) {
                        continue;
                    }
                    $contacts->add($contact);
                    if ($contacts->count() >= $maximumResults) {
                        return $contacts;
                    }
                }
            } while ($pageToken !== null && $pageToken !== '');
        }
        return $contacts;
    }

    public function getContact(string $contactId, AgentConnectionContactsScope $contactsScope): ?AgentConnectionContact
    {
        $peopleService = new GooglePeopleService($this->getAuthorizedClient());
        $source = static::sourceOfResourceName($contactId);
        if (!$contactsScope->isSourceAllowed($source)) {
            return null;
        }
        $person = $source === AgentConnectionContact::SOURCE_OTHER_CONTACTS
            ? $this->findOtherContact($peopleService, $contactId)
            : $peopleService->people->get($contactId, ['personFields' => static::CONTACT_READ_MASK]);
        $contact = $person !== null ? $this->toContact($person, $source) : null;
        return $contact !== null && $contactsScope->allowsContact($contact) ? $contact : null;
    }

    public function listContactGroups(AgentConnectionContactsScope $contactsScope): AgentConnectionContactGroups
    {
        $peopleService = new GooglePeopleService($this->getAuthorizedClient());
        $contactGroups = AgentConnectionContactGroups::newInstance();
        if (!$contactsScope->isSourceAllowed(AgentConnectionContact::SOURCE_CONTACTS)) {
            return $contactGroups;
        }
        foreach ($peopleService->contactGroups->listContactGroups(['pageSize' => 1000])->getContactGroups() ?? [] as $googleContactGroup) {
            if (!$contactsScope->isLabelAllowed((string)$googleContactGroup->getResourceName())) {
                continue;
            }
            $contactGroup = AgentConnectionContactGroup::newInstance();
            $contactGroup->groupId = (string)$googleContactGroup->getResourceName();
            $contactGroup->name = (string)($googleContactGroup->getFormattedName() ?: $googleContactGroup->getName());
            $contactGroup->groupType = (string)$googleContactGroup->getGroupType();
            $contactGroup->memberCount = (int)$googleContactGroup->getMemberCount();
            $contactGroups->add($contactGroup);
        }
        return $contactGroups;
    }

    public function listContactGroupMembers(string $groupId, int $maximumResults, AgentConnectionContactsScope $contactsScope): AgentConnectionContacts
    {
        if (!$contactsScope->isSourceAllowed(AgentConnectionContact::SOURCE_CONTACTS) || !$contactsScope->isLabelAllowed($groupId)) {
            return AgentConnectionContacts::newInstance();
        }
        return $this->listMembersOfLabels(new GooglePeopleService($this->getAuthorizedClient()), [$groupId], max(1, min(1000, $maximumResults)));
    }

    public function createContact(AgentConnectionContact $contact): AgentConnectionSendResult
    {
        $peopleService = new GooglePeopleService($this->getAuthorizedClient());
        $sendResult = AgentConnectionSendResult::newInstance();
        if (str_starts_with($contact->contactId, 'otherContacts/')) {
            // a person of Other contacts is copied into My Contacts (the API's own way), then labelled
            $copyRequest = new GooglePeopleService\CopyOtherContactToMyContactsGroupRequest();
            $copyRequest->setCopyMask('names,emailAddresses,phoneNumbers');
            $sendResult->externalId = (string)$peopleService->otherContacts->copyOtherContactToMyContactsGroup($contact->contactId, $copyRequest)->getResourceName();
        } else {
            $sendResult->externalId = (string)$peopleService->people->createContact($this->toPerson($contact), ['personFields' => 'names'])->getResourceName();
        }
        foreach ($contact->labels as $groupId) {
            $this->modifyGroupMembers($peopleService, $groupId, [$sendResult->externalId], []);
        }
        return $sendResult;
    }

    public function updateContact(AgentConnectionContact $contact, array $fieldNames): AgentConnectionSendResult
    {
        $allowedFieldNames = array_values(array_intersect($fieldNames, ['names', 'emailAddresses', 'phoneNumbers', 'organizations', 'biographies']));
        if ($allowedFieldNames === [] || $contact->etag === null) {
            throw new AgentConnectionRefusedException('An update names the changed fields and carries the etag of a fresh read (getContact).');
        }
        $peopleService = new GooglePeopleService($this->getAuthorizedClient());
        $person = $this->toPerson($contact);
        $person->setEtag($contact->etag);
        try {
            $updatedPerson = $peopleService->people->updateContact($contact->contactId, $person, ['updatePersonFields' => implode(',', $allowedFieldNames), 'personFields' => 'names']);
        } catch (GoogleServiceException $updateFailure) {
            // a stale etag: the model must read again and show a new difference, never retry blindly
            if ($updateFailure->getCode() === 400 && str_contains(mb_strtolower($updateFailure->getMessage()), 'etag')) {
                throw new AgentConnectionRefusedException('The contact changed since it was read: read it again (getContact), show the new difference and ask again. No blind retry.');
            }
            throw $updateFailure;
        }
        $sendResult = AgentConnectionSendResult::newInstance();
        $sendResult->externalId = (string)$updatedPerson->getResourceName();
        return $sendResult;
    }

    public function changeContactGroupMembers(?string $groupId, ?string $newGroupName, array $addContactIds, array $removeContactIds): AgentConnectionSendResult
    {
        $peopleService = new GooglePeopleService($this->getAuthorizedClient());
        if ($newGroupName !== null && trim($newGroupName) !== '') {
            $googleContactGroup = new GooglePeopleService\ContactGroup();
            $googleContactGroup->setName(trim($newGroupName));
            $createRequest = new GooglePeopleService\CreateContactGroupRequest();
            $createRequest->setContactGroup($googleContactGroup);
            $groupId = (string)$peopleService->contactGroups->create($createRequest)->getResourceName();
        }
        if ($groupId === null || $groupId === '') {
            throw new AgentConnectionRefusedException('Name the label (groupId) or a new label name.');
        }
        $this->modifyGroupMembers($peopleService, $groupId, $addContactIds, $removeContactIds);
        $sendResult = AgentConnectionSendResult::newInstance();
        $sendResult->externalId = $groupId;
        return $sendResult;
    }

    public function deleteContact(string $contactId): AgentConnectionSendResult
    {
        if (!str_starts_with($contactId, 'people/')) {
            throw new AgentConnectionRefusedException('Only a person of My Contacts can be deleted.');
        }
        (new GooglePeopleService($this->getAuthorizedClient()))->people->deleteContact($contactId);
        $sendResult = AgentConnectionSendResult::newInstance();
        $sendResult->externalId = $contactId;
        return $sendResult;
    }

    public function moveContactPhoto(string $donorContactId, string $survivorContactId): bool
    {
        $peopleService = new GooglePeopleService($this->getAuthorizedClient());
        if (static::findOwnPhotoUrl($peopleService->people->get($survivorContactId, ['personFields' => 'photos'])) !== null) {
            return false;
        }
        $donorPhotoUrl = static::findOwnPhotoUrl($peopleService->people->get($donorContactId, ['personFields' => 'photos']));
        if ($donorPhotoUrl === null) {
            return false;
        }
        // the photo bytes pass only through this backend, never a client
        $photoBytes = (string)$this->getAuthorizedClient()->authorize()->request('GET', $donorPhotoUrl)->getBody();
        $photoRequest = new GooglePeopleService\UpdateContactPhotoRequest();
        $photoRequest->setPhotoBytes(base64_encode($photoBytes));
        $peopleService->people->updateContactPhoto($survivorContactId, $photoRequest);
        return true;
    }

    /** One source's prefix search; the first search of a source in a process sends the warm-up (an empty query) first */
    protected function searchSourceWarmedUp(GooglePeopleService $peopleService, string $source, string $query, int $maximumResults): AgentConnectionContacts
    {
        $warmUpKey = ($this->agentConnection?->id ?? 0) . ':' . $source;
        if ($source !== AgentConnectionContact::SOURCE_DIRECTORY && !isset(static::$warmedUpContactSources[$warmUpKey])) {
            $this->searchSource($peopleService, $source, '', 1);
            static::$warmedUpContactSources[$warmUpKey] = true;
        }
        return $this->searchSource($peopleService, $source, $query, $maximumResults);
    }

    protected function searchSource(GooglePeopleService $peopleService, string $source, string $query, int $maximumResults): AgentConnectionContacts
    {
        $contacts = AgentConnectionContacts::newInstance();
        $people = [];
        if ($source === AgentConnectionContact::SOURCE_CONTACTS) {
            foreach ($peopleService->people->searchContacts(['query' => $query, 'readMask' => static::CONTACT_READ_MASK, 'pageSize' => $maximumResults])->getResults() ?? [] as $searchResult) {
                $people[] = $searchResult->getPerson();
            }
        } elseif ($source === AgentConnectionContact::SOURCE_OTHER_CONTACTS) {
            foreach ($peopleService->otherContacts->search(['query' => $query, 'readMask' => static::OTHER_CONTACT_READ_MASK, 'pageSize' => $maximumResults])->getResults() ?? [] as $searchResult) {
                $people[] = $searchResult->getPerson();
            }
        } elseif ($source === AgentConnectionContact::SOURCE_DIRECTORY) {
            $people = $peopleService->people->searchDirectoryPeople(['query' => $query, 'readMask' => static::CONTACT_READ_MASK, 'sources' => static::DIRECTORY_SOURCES, 'pageSize' => $maximumResults])->getPeople() ?? [];
        }
        foreach ($people as $person) {
            $contact = $this->toContact($person, $source);
            if ($contact !== null) {
                $contacts->add($contact);
            }
        }
        return $contacts;
    }

    /** @param string[] $groupIds */
    protected function listMembersOfLabels(GooglePeopleService $peopleService, array $groupIds, int $maximumMembers): AgentConnectionContacts
    {
        $memberResourceNames = [];
        foreach ($groupIds as $groupId) {
            foreach ($peopleService->contactGroups->get($groupId, ['maxMembers' => $maximumMembers])->getMemberResourceNames() ?? [] as $memberResourceName) {
                $memberResourceNames[$memberResourceName] = true;
            }
        }
        $contacts = AgentConnectionContacts::newInstance();
        foreach (array_chunk(array_keys($memberResourceNames), 200) as $resourceNameChunk) {
            foreach ($peopleService->people->getBatchGet(['resourceNames' => $resourceNameChunk, 'personFields' => static::CONTACT_READ_MASK])->getResponses() ?? [] as $personResponse) {
                $contact = $this->toContact($personResponse->getPerson(), AgentConnectionContact::SOURCE_CONTACTS);
                if ($contact !== null) {
                    $contacts->add($contact);
                }
            }
        }
        return $contacts;
    }

    /** Prefix match on every word of name, organization, e-mails and phones (the provider's search rule, applied locally) */
    protected function filterContactsLocally(AgentConnectionContacts $contacts, string $query): AgentConnectionContacts
    {
        $normalizedQuery = mb_strtolower(trim($query));
        $matchingContacts = AgentConnectionContacts::newInstance();
        foreach ($contacts->getElements() as $contact) {
            if ($normalizedQuery === '' || static::hasWordStartingWith($contact, $normalizedQuery)) {
                $matchingContacts->add($contact);
            }
        }
        return $matchingContacts;
    }

    protected static function hasWordStartingWith(AgentConnectionContact $contact, string $normalizedQuery): bool
    {
        foreach ([$contact->name, (string)$contact->organization, ...$contact->emailAddresses, ...$contact->phoneNumbers] as $value) {
            $normalizedValue = mb_strtolower($value);
            if (str_starts_with($normalizedValue, $normalizedQuery)) {
                return true;
            }
            foreach (preg_split('/[\s@.\-]+/', $normalizedValue) ?: [] as $word) {
                if (str_starts_with($word, $normalizedQuery)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Other contacts have no get: read the list (paged) and pick the person */
    protected function findOtherContact(GooglePeopleService $peopleService, string $contactId): ?GooglePeopleService\Person
    {
        $pageToken = null;
        do {
            $listAnswer = $peopleService->otherContacts->listOtherContacts(array_filter(['readMask' => static::OTHER_CONTACT_READ_MASK, 'pageSize' => 1000, 'pageToken' => $pageToken]));
            foreach ($listAnswer->getOtherContacts() ?? [] as $person) {
                if ($person->getResourceName() === $contactId) {
                    return $person;
                }
            }
            $pageToken = $listAnswer->getNextPageToken();
        } while ($pageToken !== null && $pageToken !== '');
        return null;
    }

    /**
     * @param string[] $addContactIds
     * @param string[] $removeContactIds
     */
    protected function modifyGroupMembers(GooglePeopleService $peopleService, string $groupId, array $addContactIds, array $removeContactIds): void
    {
        if ($addContactIds === [] && $removeContactIds === []) {
            return;
        }
        $modifyRequest = new GooglePeopleService\ModifyContactGroupMembersRequest();
        $modifyRequest->setResourceNamesToAdd(array_values($addContactIds));
        $modifyRequest->setResourceNamesToRemove(array_values($removeContactIds));
        $peopleService->contactGroups_members->modify($groupId, $modifyRequest);
    }

    protected function toContact(?GooglePeopleService\Person $person, string $source): ?AgentConnectionContact
    {
        if ($person === null || $person->getMetadata()?->getDeleted()) {
            return null;
        }
        $contact = AgentConnectionContact::newInstance();
        $contact->contactId = (string)$person->getResourceName();
        $contact->etag = $person->getEtag();
        $firstSource = ($person->getMetadata()?->getSources() ?? [])[0] ?? null;
        $contact->sourceId = $firstSource?->getId();
        $firstName = ($person->getNames() ?? [])[0] ?? null;
        $contact->name = (string)($firstName?->getDisplayName() ?? '');
        foreach ($person->getEmailAddresses() ?? [] as $emailAddress) {
            if ($emailAddress->getValue()) {
                $contact->emailAddresses[] = (string)$emailAddress->getValue();
            }
        }
        foreach ($person->getPhoneNumbers() ?? [] as $phoneNumber) {
            if ($phoneNumber->getValue()) {
                $contact->phoneNumbers[] = (string)$phoneNumber->getValue();
            }
        }
        $firstOrganization = ($person->getOrganizations() ?? [])[0] ?? null;
        $contact->organization = $firstOrganization?->getName();
        $contact->jobTitle = $firstOrganization?->getTitle();
        $biography = ($person->getBiographies() ?? [])[0] ?? null;
        $contact->notes = $biography !== null ? static::shorten((string)$biography->getValue(), 1000) : null;
        foreach ($person->getMemberships() ?? [] as $membership) {
            $groupResourceName = $membership->getContactGroupMembership()?->getContactGroupResourceName();
            if ($groupResourceName) {
                $contact->labels[] = (string)$groupResourceName;
            }
        }
        $contact->hasPhoto = static::findOwnPhotoUrl($person) !== null;
        $contact->source = $source;
        return $contact;
    }

    protected function toPerson(AgentConnectionContact $contact): GooglePeopleService\Person
    {
        $person = new GooglePeopleService\Person();
        $name = new GooglePeopleService\Name();
        $name->setUnstructuredName($contact->name);
        $person->setNames([$name]);
        $emailAddresses = [];
        foreach ($contact->emailAddresses as $address) {
            $emailAddress = new GooglePeopleService\EmailAddress();
            $emailAddress->setValue($address);
            $emailAddresses[] = $emailAddress;
        }
        $person->setEmailAddresses($emailAddresses);
        $phoneNumbers = [];
        foreach ($contact->phoneNumbers as $number) {
            $phoneNumber = new GooglePeopleService\PhoneNumber();
            $phoneNumber->setValue($number);
            $phoneNumbers[] = $phoneNumber;
        }
        $person->setPhoneNumbers($phoneNumbers);
        if ($contact->organization !== null || $contact->jobTitle !== null) {
            $organization = new GooglePeopleService\Organization();
            $organization->setName($contact->organization);
            $organization->setTitle($contact->jobTitle);
            $person->setOrganizations([$organization]);
        }
        if ($contact->notes !== null) {
            $biography = new GooglePeopleService\Biography();
            $biography->setValue($contact->notes);
            $person->setBiographies([$biography]);
        }
        return $person;
    }

    protected static function findOwnPhotoUrl(GooglePeopleService\Person $person): ?string
    {
        foreach ($person->getPhotos() ?? [] as $photo) {
            if (!$photo->getDefault() && $photo->getUrl()) {
                return (string)$photo->getUrl();
            }
        }
        return null;
    }

    protected static function sourceOfResourceName(string $contactId): string
    {
        return str_starts_with($contactId, 'otherContacts/') ? AgentConnectionContact::SOURCE_OTHER_CONTACTS : AgentConnectionContact::SOURCE_CONTACTS;
    }

    protected static function matchesDomainOrOrganization(AgentConnectionContact $contact, string $normalizedTerm): bool
    {
        if ($normalizedTerm === '') {
            return false;
        }
        foreach ($contact->emailAddresses as $emailAddress) {
            $normalizedAddress = mb_strtolower($emailAddress);
            if (str_ends_with($normalizedAddress, '@' . $normalizedTerm) || str_ends_with($normalizedAddress, '.' . $normalizedTerm)) {
                return true;
            }
        }
        return $contact->organization !== null && str_contains(mb_strtolower($contact->organization), $normalizedTerm);
    }

    public function searchFiles(string $query, int $maximumResults, array $allowedFolderIds): AgentConnectionFiles
    {
        $googleDrive = new GoogleDrive($this->getAuthorizedClient());
        $escapedQuery = str_replace(['\\', "'"], ['\\\\', "\\'"], $query);
        $conditions = ['trashed = false'];
        if ($query !== '') {
            $conditions[] = "(name contains '$escapedQuery' or fullText contains '$escapedQuery')";
        }
        if ($allowedFolderIds !== []) {
            $folderConditions = [];
            foreach ($allowedFolderIds as $allowedFolderId) {
                $folderConditions[] = "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $allowedFolderId) . "' in parents";
            }
            $conditions[] = '(' . implode(' or ', $folderConditions) . ')';
        }
        $listAnswer = $googleDrive->files->listFiles([
            'q' => implode(' and ', $conditions),
            'pageSize' => max(1, min(50, $maximumResults)),
            'fields' => 'files(id,name,mimeType,modifiedTime,size,parents,owners(emailAddress))',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);
        $files = new AgentConnectionFiles();
        foreach ($listAnswer->getFiles() ?? [] as $driveFile) {
            $addedElement = $this->toFile($driveFile);
            $files->add($addedElement);
        }
        return $files;
    }

    public function readFile(string $fileId, int $maximumCharacters, array $allowedFolderIds): AgentConnectionFileContent
    {
        $googleDrive = new GoogleDrive($this->getAuthorizedClient());
        $driveFile = $googleDrive->files->get($fileId, ['fields' => 'id,name,mimeType,parents,size', 'supportsAllDrives' => true]);
        if ($allowedFolderIds !== [] && array_intersect($driveFile->getParents() ?? [], $allowedFolderIds) === []) {
            throw new AgentConnectionRefusedException('This file lies outside the folders released for this connection.');
        }
        $mimeType = (string)$driveFile->getMimeType();
        $exportMimeTypes = [
            'application/vnd.google-apps.document' => 'text/plain',
            'application/vnd.google-apps.spreadsheet' => 'text/csv',
            'application/vnd.google-apps.presentation' => 'text/plain',
        ];
        $isExport = isset($exportMimeTypes[$mimeType]);
        if (!$isExport && !str_starts_with($mimeType, 'text/') && !in_array($mimeType, ['application/json', 'application/xml', 'text/csv'], true)) {
            throw new AgentConnectionRefusedException("The file type $mimeType cannot be read as text here.");
        }
        if (!$isExport && (int)$driveFile->getSize() > static::MAXIMUM_FILE_READ_BYTES) {
            throw new AgentConnectionRefusedException(sprintf('"%s" is larger than %d MB and is not read.', (string)$driveFile->getName(), intdiv(static::MAXIMUM_FILE_READ_BYTES, 1000000)));
        }
        // streamed and read up to the limit (an export has no size beforehand)
        $downloadResponse = $this->getAuthorizedClient()->authorize()->request('GET', static::DRIVE_FILES_URL . rawurlencode($fileId) . ($isExport ? '/export' : ''), [
            'query' => $isExport ? ['mimeType' => $exportMimeTypes[$mimeType]] : ['alt' => 'media', 'supportsAllDrives' => 'true'],
            'stream' => true,
            'http_errors' => false,
            'timeout' => 60,
        ]);
        if ($downloadResponse->getStatusCode() >= 400) {
            throw new AgentConnectionProviderException(sprintf('The drive provider failed (%d) to deliver "%s".', $downloadResponse->getStatusCode(), (string)$driveFile->getName()));
        }
        $text = CappedStreamReader::read($downloadResponse->getBody(), static::MAXIMUM_FILE_READ_BYTES);
        if ($text === null) {
            throw new AgentConnectionRefusedException(sprintf('"%s" is larger than %d MB and is not read.', (string)$driveFile->getName(), intdiv(static::MAXIMUM_FILE_READ_BYTES, 1000000)));
        }
        $text = mb_check_encoding($text, 'UTF-8') ? $text : (string)mb_scrub($text, 'UTF-8');
        $fileContent = new AgentConnectionFileContent();
        $fileContent->fileId = $fileId;
        $fileContent->name = (string)$driveFile->getName();
        $fileContent->mimeType = $mimeType;
        $fileContent->isTruncated = mb_strlen($text) > $maximumCharacters;
        $fileContent->text = static::shorten($text, $maximumCharacters);
        return $fileContent;
    }

    public function createTextFile(string $name, string $text, ?string $parentFolderId): AgentConnectionSendResult
    {
        $allowedFolderIds = $this->requireConnection()->getScopeBoundaryValues('FOLDER');
        if ($allowedFolderIds !== [] && ($parentFolderId === null || !in_array($parentFolderId, $allowedFolderIds, true))) {
            throw new AgentConnectionRefusedException('A new file must go into one of the released folders: ' . implode(', ', $allowedFolderIds) . '.');
        }
        $googleDrive = new GoogleDrive($this->getAuthorizedClient());
        $driveFile = new GoogleDrive\DriveFile();
        $driveFile->setName($name);
        if ($parentFolderId !== null) {
            $driveFile->setParents([$parentFolderId]);
        }
        $createdFile = $googleDrive->files->create($driveFile, ['data' => $text, 'mimeType' => 'text/plain', 'uploadType' => 'multipart', 'supportsAllDrives' => true, 'fields' => 'id']);
        $sendResult = AgentConnectionSendResult::newInstance();
        $sendResult->externalId = (string)$createdFile->getId();
        return $sendResult;
    }

    /**
     * An authorized client; refreshes and stores the access token when it is about to expire. The refresh runs under a
     * lock per connection: parallel tool calls wait for the one refresh and read its stored token instead of
     * refreshing (and overwriting the stored credentials) each.
     */
    protected function getAuthorizedClient(): GoogleClient
    {
        if ($this->authorizedClient !== null) {
            return $this->authorizedClient;
        }
        $agentConnection = $this->requireConnection();
        $agentConnectionCredentialSetsService = $this->getCredentialSetsService();
        $secretPayload = $agentConnectionCredentialSetsService->readSecrets($agentConnection);
        if ($this->isAccessTokenDue()) {
            $secretPayload = $this->refreshAccessTokenUnderLock();
        }
        $googleClient = $this->createUnauthorizedClient(null);
        $googleClient->setAccessToken(['access_token' => (string)$secretPayload->get('accessToken'), 'expires_in' => 3600, 'created' => (new DateTime())->getTimestamp()]);
        return $this->authorizedClient = $googleClient;
    }

    protected function isAccessTokenDue(): bool
    {
        $expiresAtUnixTime = $this->getCredentialSetsService()->findAccessTokenExpiry($this->requireConnection()) ?? 0;
        return $expiresAtUnixTime < (new DateTime())->getTimestamp() + static::REFRESH_MARGIN_SECONDS;
    }

    /** Refreshes once per connection at a time; a caller that finds the lock taken waits and reads the new token */
    protected function refreshAccessTokenUnderLock(): AgentConnectionSecretPayload
    {
        $agentConnection = $this->requireConnection();
        $agentConnectionCredentialSetsService = $this->getCredentialSetsService();
        $cache = Cache::instance();
        $lockKey = 'agentConnectionTokenRefresh_' . (int)$agentConnection->id;
        $lockToken = $cache->acquireLock($lockKey, static::REFRESH_LOCK_SECONDS);
        $waitedMilliseconds = 0;
        while ($lockToken === null && $waitedMilliseconds < static::REFRESH_LOCK_SECONDS * 1000) {
            usleep(250_000);
            $waitedMilliseconds += 250;
            if (!$this->isAccessTokenDue()) {
                return $agentConnectionCredentialSetsService->readSecrets($agentConnection);
            }
            $lockToken = $cache->acquireLock($lockKey, static::REFRESH_LOCK_SECONDS);
        }
        if ($lockToken === null) {
            throw new AgentConnectionProviderException('Another call is refreshing the Google token of this connection; try again in a moment.');
        }
        try {
            $secretPayload = $agentConnectionCredentialSetsService->readSecrets($agentConnection);
            if (!$this->isAccessTokenDue()) {
                return $secretPayload;
            }
            $oAuthGrant = $this->refreshAccessToken($secretPayload);
            $secretPayload = $secretPayload->with('accessToken', $oAuthGrant->accessToken)->with('refreshToken', $oAuthGrant->refreshToken);
            $agentConnectionCredentialSetsService->storeSecrets($agentConnection, AgentConnectionCredentials::TYPE_OAUTH, $secretPayload, $oAuthGrant->expiresAtUnixTime);
            return $secretPayload;
        } finally {
            $cache->releaseLock($lockKey, $lockToken);
        }
    }

    protected function createUnauthorizedClient(?string $redirectUri): GoogleClient
    {
        if (!$this->isConfigured()) {
            throw new AgentConnectionRefusedException('The Google connection is not configured in this stage (client id and secret are missing).');
        }
        $googleClient = new GoogleClient();
        $googleClient->setClientId((string)$this->getClientId());
        $googleClient->setClientSecret((string)$this->getClientSecret());
        if ($redirectUri !== null) {
            $googleClient->setRedirectUri($redirectUri);
        }
        return $googleClient;
    }

    /** @param array<string, mixed> $tokenAnswer */
    protected function grantFromTokenAnswer(array $tokenAnswer): AgentConnectionOAuthGrant
    {
        $oAuthGrant = new AgentConnectionOAuthGrant();
        $oAuthGrant->accessToken = (string)$tokenAnswer['access_token'];
        $oAuthGrant->refreshToken = isset($tokenAnswer['refresh_token']) ? (string)$tokenAnswer['refresh_token'] : null;
        $oAuthGrant->expiresAtUnixTime = (new DateTime())->getTimestamp() + (int)($tokenAnswer['expires_in'] ?? 3600);
        $oAuthGrant->grantedScopes = array_values(array_filter(explode(' ', (string)($tokenAnswer['scope'] ?? ''))));
        return $oAuthGrant;
    }

    protected function requireCalendarInBoundaries(string $calendarId): void
    {
        $allowedCalendarIds = $this->requireConnection()->getScopeBoundaryValues(AgentConnectionScopeBoundary::KIND_CALENDAR);
        if ($allowedCalendarIds !== [] && !in_array($calendarId, $allowedCalendarIds, true) && !($calendarId === 'primary' && in_array((string)$this->requireConnection()->externalAddress, $allowedCalendarIds, true))) {
            throw new AgentConnectionRefusedException("The calendar $calendarId is outside the calendars released for this connection.");
        }
    }

    protected function toMailMessage(GoogleGmail\Message $gmailMessage, bool $withBody): AgentConnectionMailMessage
    {
        $headers = [];
        $authenticationResultsHeaders = [];
        foreach ($gmailMessage->getPayload()?->getHeaders() ?? [] as $header) {
            $headerName = mb_strtolower((string)$header->getName());
            if ($headerName === 'authentication-results') {
                $authenticationResultsHeaders[] = (string)$header->getValue();
                continue;
            }
            $headers[$headerName] = (string)$header->getValue();
        }
        $mailMessage = new AgentConnectionMailMessage();
        $mailMessage->messageId = (string)$gmailMessage->getId();
        $mailMessage->threadId = $gmailMessage->getThreadId();
        $mailMessage->internetMessageId = $headers['message-id'] ?? null;
        $mailMessage->from = $headers['from'] ?? '';
        $mailMessage->to = static::splitAddresses($headers['to'] ?? '');
        $mailMessage->cc = static::splitAddresses($headers['cc'] ?? '');
        $mailMessage->subject = $headers['subject'] ?? '';
        $mailMessage->snippet = html_entity_decode((string)$gmailMessage->getSnippet(), ENT_QUOTES);
        $mailMessage->sentAt = static::parseProviderTime($headers['date'] ?? null);
        $mailMessage->senderAuthenticated = static::isSenderAuthenticated($authenticationResultsHeaders, $mailMessage->from, static::TRUSTED_AUTHENTICATION_SERVER_IDS);
        $mailMessage->labels = $gmailMessage->getLabelIds() ?? [];
        $mailMessage->isDraft = in_array('DRAFT', $mailMessage->labels, true);
        if ($withBody) {
            $mailMessage->bodyText = static::shorten(static::extractPlainText($gmailMessage->getPayload()), static::MAXIMUM_BODY_CHARACTERS);
        }
        return $mailMessage;
    }

    protected static function extractPlainText(?GoogleGmail\MessagePart $messagePart, int $depth = 0): string
    {
        if ($messagePart === null || $depth > static::MAXIMUM_MIME_DEPTH) {
            return '';
        }
        $mimeType = (string)$messagePart->getMimeType();
        if ($mimeType === 'text/plain' && $messagePart->getBody()?->getData() !== null) {
            return static::base64UrlDecode((string)$messagePart->getBody()->getData());
        }
        $htmlFallback = '';
        foreach ($messagePart->getParts() ?? [] as $childPart) {
            $childText = static::extractPlainText($childPart, $depth + 1);
            if ($childText !== '' && $childPart->getMimeType() !== 'text/html') {
                return $childText;
            }
            if ($childPart->getMimeType() === 'text/html' && $childPart->getBody()?->getData() !== null) {
                $htmlFallback = AgentConnectionHtmlTextExtractor::extractVisibleText(static::base64UrlDecode((string)$childPart->getBody()->getData()));
            }
        }
        if ($htmlFallback === '' && $mimeType === 'text/html' && $messagePart->getBody()?->getData() !== null) {
            $htmlFallback = AgentConnectionHtmlTextExtractor::extractVisibleText(static::base64UrlDecode((string)$messagePart->getBody()->getData()));
        }
        return $htmlFallback;
    }

    protected function toFile(GoogleDrive\DriveFile $driveFile): AgentConnectionFile
    {
        $file = new AgentConnectionFile();
        $file->fileId = (string)$driveFile->getId();
        $file->name = (string)$driveFile->getName();
        $file->mimeType = (string)$driveFile->getMimeType();
        $file->modifiedAt = static::parseProviderTime($driveFile->getModifiedTime());
        $file->sizeBytes = $driveFile->getSize() !== null ? (int)$driveFile->getSize() : null;
        $file->parentIds = $driveFile->getParents() ?? [];
        $firstOwner = ($driveFile->getOwners() ?? [])[0] ?? null;
        $file->ownerAddress = $firstOwner?->getEmailAddress();
        return $file;
    }

    /** @return string[] */
    protected static function splitAddresses(string $headerValue): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $headerValue))));
    }

    protected static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected static function base64UrlDecode(string $data): string
    {
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }

    protected function getClientId(): ?string
    {
        return AgentConnectionsConfiguration::readEnvironmentValue($this->getSettings()->clientIdEnvironmentVariable) ?: null;
    }

    protected function getClientSecret(): ?string
    {
        return AgentConnectionsConfiguration::readEnvironmentValue($this->getSettings()->clientSecretEnvironmentVariable) ?: null;
    }
}
