<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionBusyPeriods;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendarEvents;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendars;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatMessages;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContact;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactGroups;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContacts;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFileContent;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFiles;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessages;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionResultMcpResponseDto extends RestResponseDto
{
    /** @var string The connection used (its name) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $connection = '';

    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $connectionId = 0;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionMailMessages $mailMessages = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionCalendars $calendars = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionCalendarEvents $calendarEvents = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionBusyPeriods $busyPeriods = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionContacts $contacts = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionContact $contact = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionContactGroups $contactGroups = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionFiles $files = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionFileContent $fileContent = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionChatMessages $chatMessages = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionSendResult $sendResult = null;

    /** @var string|null What happened, for the model */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;
}
