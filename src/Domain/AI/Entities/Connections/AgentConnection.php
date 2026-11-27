<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnection;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/**
 * ONE external account connected to the agent, whatever it can do: a Google consent that grants mail and calendar is
 * one row with two capabilities ({@see AgentConnectionPermission}). Never one row per capability: a refresh token
 * would be stored several times.
 *
 * Two modes ({@see $ownership}):
 * - ACCOUNT_GRANTED (mode 1): an account connected its own mailbox, calendar, contacts or drive; the agent works in it
 *   for that account, in its conversations only, or in those of the space it shares the connection with.
 * - AGENT_OWNED (mode 2): a system connection owned by the host or a space: the agent's own identity (a Slack bot,
 *   its own mailbox) and shared team mailboxes, installed by an account with the system right; never a person's
 *   row. It reaches a space only through a release an account with the system right sets ({@see AgentConnectionRelease}); until then it
 *   is unassigned and used by nobody.
 *
 * A row is bound to the stage that created it ({@see $environment}): a production row copied into a dev database is
 * inert there (no read, no send), and its credentials are encrypted with another stage's key.
 *
 * @property AgentConnections $parent
 * @method AgentConnections getParent()
 * @method static AgentConnectionsService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['accountId'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentSpaceId', 'displayName'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['provider', 'externalAccountId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnection::class)]
class AgentConnection extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string Mode 1: an account connected its own external account */
    public const string OWNERSHIP_ACCOUNT_GRANTED = 'ACCOUNT_GRANTED';

    /** @var string Mode 2: the agent's own identity (system connection) */
    public const string OWNERSHIP_AGENT_OWNED = 'AGENT_OWNED';

    public const string PROVIDER_GOOGLE = 'GOOGLE';
    public const string PROVIDER_IMAP = 'IMAP';
    public const string PROVIDER_SLACK = 'SLACK';
    public const string PROVIDER_FAKE = 'FAKE';

    /** @var string The consent screen was opened and has not come back yet */
    public const string STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const string STATUS_CONNECTED = 'CONNECTED';

    /** @var string The provider refused the refresh: the account must connect again */
    public const string STATUS_EXPIRED = 'EXPIRED';

    /** @var string Paused by its owner, or by itself after repeated provider errors */
    public const string STATUS_PAUSED = 'PAUSED';

    /** @var string Disconnected: the grant was revoked and the credentials deleted; the audit stays */
    public const string STATUS_REVOKED = 'REVOKED';

    public const string STATUS_ERROR = 'ERROR';

    public const array STATUSES = [
        self::STATUS_IN_PROGRESS, self::STATUS_CONNECTED, self::STATUS_EXPIRED, self::STATUS_PAUSED,
        self::STATUS_REVOKED, self::STATUS_ERROR,
    ];

    /** @var string mode 1 or mode 2 */
    #[Length(max: 20)]
    #[Choice(choices: [self::OWNERSHIP_ACCOUNT_GRANTED, self::OWNERSHIP_AGENT_OWNED])]
    public string $ownership = self::OWNERSHIP_ACCOUNT_GRANTED;

    /** @var string|null The provider (a key of `AI.agentConnections.adapters.classes`) */
    #[Length(max: 20)]
    public ?string $provider = null;

    /** @var string|null The provider's id of the external account (Google `sub`, Slack team id, mailbox address) */
    #[Length(max: 255)]
    public ?string $externalAccountId = null;

    /**
     * @var string|null The free name its owner gives the connection, unique per agent space ("Team mailbox", "My calendar"); the agent sees
     * and names connections by it. An account or space may hold any number of connections of every provider and
     * both modes: no "one per provider" anywhere, every tool takes a connection reference (id or name).
     */
    #[Length(max: 100)]
    public ?string $displayName = null;

    /**
     * @var AgentConnectionScopeBoundaries|null Boundaries of the scope inside the external account, as a person set
     * them (allowed folders of a drive, labels of a mailbox, calendars). Empty = the whole granted account.
     */
    public ?AgentConnectionScopeBoundaries $scopeBoundaries;

    /** @var bool The connection the agent uses for a capability when no connection is named (a default, never a limit) */
    public bool $isDefaultForItsCapabilities = false;

    /** @var string|null The external identity a person recognises: the mailbox address, the workspace name */
    #[Length(max: 255)]
    public ?string $externalAddress = null;

    /** @var int|null Mode 1: the account that owns the connection; mode 2: null */
    public ?int $accountId = null;

    /**
     * @var int|null Mode 2: the owning space, null = the host. Mode 1: unused. Who else may use a connection is never
     * this column: it is the release list ({@see AgentConnectionRelease}), the one mechanism for every capability.
     */
    public ?int $agentSpaceId = null;

    /** @var int|null Who completed the connect (audit only; never an owner of a system connection) */
    public ?int $connectedByAccountId = null;

    #[Length(max: 20)]
    #[Choice(choices: self::STATUSES)]
    public string $status = self::STATUS_IN_PROGRESS;

    /** @var string|null The stage that created the row ({@see AgentConnectionsConfiguration::getCurrentStage()}) */
    #[Length(max: 32)]
    public ?string $environment = null;

    /**
     * @var bool Mode 1 chat: the agent may post as the person. Default off: only the
     * account itself switches it on for its own connection.
     */
    public bool $postAsPersonAllowed = false;

    /** @var string|null The scopes the provider granted, separated by spaces */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $grantedScopes = null;

    /** @var int|null Sends per hour; null = the configured default */
    public ?int $sendsPerHourLimit = null;

    /** @var int|null Sends per day; null = the configured default */
    public ?int $sendsPerDayLimit = null;

    /** @var int|null Sends to one recipient per day; null = the configured default */
    public ?int $sendsPerRecipientPerDayLimit = null;

    /** @var int|null Runs started by one inbound sender per hour; null = the configured default */
    public ?int $inboundRunsPerSenderPerHourLimit = null;

    /** @var int Provider errors in a row; the connection pauses itself at the configured number */
    public int $consecutiveErrorCount = 0;

    /** @var string|null The last provider error, shortened, without secrets */
    #[Length(max: 500)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $lastErrorText = null;

    public ?DateTime $lastErrorAt = null;

    public ?DateTime $lastUsedAt = null;

    /** @var AgentConnectionPermissions|null The level per capability */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AgentConnectionPermissions $permissions;

    /** @var AgentConnectionReleases|null Who else may use it: the spaces it is released to, each with a maximum level */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AgentConnectionReleases $releases;

    /** @var AgentConnectionAddresses|null What is reachable through it (a mailbox, Slack channels) */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AgentConnectionAddresses $addresses;

    public function isAgentOwned(): bool
    {
        return $this->ownership === self::OWNERSHIP_AGENT_OWNED;
    }

    /** Whether the row was created in `$currentStage`: a row of another stage never reads or sends here */
    public function belongsToStage(string $currentStage): bool
    {
        return $this->environment !== null && $this->environment === $currentStage;
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    /** The label the agent and the screens show: the given name, else the external identity */
    public function getLabel(): string
    {
        return (string)($this->displayName ?: ($this->externalAddress ?: ($this->provider . ' #' . ($this->id ?? '?'))));
    }

    /** @return string[] values of one boundary kind (FOLDER, LABEL, CALENDAR) */
    public function getScopeBoundaryValues(string $kind): array
    {
        return ($this->scopeBoundaries ?? null)?->getValuesOfKind($kind) ?? [];
    }

    /** @return string[] */
    public function getGrantedScopeList(): array
    {
        $grantedScopeList = [];
        foreach (explode(' ', (string)$this->grantedScopes) as $scope) {
            if ($scope !== '') {
                $grantedScopeList[] = $scope;
            }
        }
        return $grantedScopeList;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
