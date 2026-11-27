<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundaries;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One connection as the agent and the screens see it: name, provider, external identity, mode, who granted it,
 * visibility, status, and per capability the level (with its capability-specific label) and the boundaries.
 */
class AgentConnectionSummaryDto extends ValueObject
{
    public int $connectionId = 0;
    public string $name = '';
    public string $provider = '';
    public ?string $externalAddress = null;
    /** @var string ACCOUNT_GRANTED or AGENT_OWNED */
    public string $ownership = '';
    public ?int $ownerAccountId = null;
    public ?int $connectedByAccountId = null;
    /** @var string OWNER_ONLY or AGENT_SPACE */
    public string $visibility = '';
    public string $status = '';
    /** @var string per capability the level the asking account may use and its label: "MAIL=DRAFT (draft); CALENDAR=READ (read, free/busy)" */
    public string $levels = '';
    /** @var AgentConnectionScopeBoundaries|null the connection's own boundaries; empty = the whole granted account */
    public ?AgentConnectionScopeBoundaries $scopeBoundaries = null;
    public bool $isDefault = false;
    public ?string $lastErrorText = null;
    /** @var DateTime|null when the agent last read or wrote through the connection */
    public ?DateTime $lastUsedAt = null;
    /** @var DateTime|null when the connection was first connected (its row was created) */
    public ?DateTime $connectedAt = null;
}
