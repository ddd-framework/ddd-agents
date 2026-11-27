<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionAddress;
use DDD\Domain\AI\Services\Connections\AgentConnectionAddressesService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/**
 * What is reachable through a connection: a mailbox address, a Slack channel. For a channel of mode 2 its
 * behaviour, the right an account needs to be answered there, and what the agent posts there (escalations, reports,
 * approval requests). A channel shared with another organisation is EXTERNAL.
 *
 * @property AgentConnectionAddresses $parent
 * @method AgentConnectionAddresses getParent()
 * @method static AgentConnectionAddressesService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['agentConnectionId', 'externalAddress'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionAddress::class)]
class AgentConnectionAddress extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string ROLE_AGENT_OWN_IDENTITY = 'AGENT_OWN_IDENTITY';
    public const string ROLE_OPERATED_ON_BEHALF = 'OPERATED_ON_BEHALF';

    public const string AUDIENCE_INTERNAL = 'INTERNAL';
    public const string AUDIENCE_EXTERNAL = 'EXTERNAL';

    /** @var string The agent reads the channel only when asked */
    public const string BEHAVIOUR_READ_WHEN_ASKED = 'READ_WHEN_ASKED';
    /** @var string The agent answers mentions of linked accounts */
    public const string BEHAVIOUR_ANSWER_MENTIONS = 'ANSWER_MENTIONS';
    /** @var string The agent answers mentions and may post there on its own */
    public const string BEHAVIOUR_MAY_POST = 'MAY_POST';

    public const string USE_NONE = 'NONE';
    /** @var string Approval requests of unattended runs are posted here */
    public const string USE_APPROVALS = 'APPROVALS';
    /** @var string Reports of scheduled runs are posted here */
    public const string USE_REPORTS = 'REPORTS';
    /** @var string Escalations (support) are posted here */
    public const string USE_ESCALATIONS = 'ESCALATIONS';

    public ?int $agentConnectionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnection $agentConnection;

    /** @var string|null The mailbox address or the channel id */
    #[Length(max: 255)]
    public ?string $externalAddress = null;

    /** @var string|null What a person reads (the channel name) */
    #[Length(max: 255)]
    public ?string $displayName = null;

    #[Length(max: 32)]
    #[Choice(choices: [self::ROLE_AGENT_OWN_IDENTITY, self::ROLE_OPERATED_ON_BEHALF])]
    public string $role = self::ROLE_OPERATED_ON_BEHALF;

    #[Length(max: 16)]
    #[Choice(choices: [self::AUDIENCE_INTERNAL, self::AUDIENCE_EXTERNAL])]
    public string $audience = self::AUDIENCE_INTERNAL;

    #[Length(max: 32)]
    #[Choice(choices: [self::BEHAVIOUR_READ_WHEN_ASKED, self::BEHAVIOUR_ANSWER_MENTIONS, self::BEHAVIOUR_MAY_POST])]
    public string $behaviour = self::BEHAVIOUR_ANSWER_MENTIONS;

    /** @var string What the agent posts there on its own */
    #[Length(max: 32)]
    #[Choice(choices: [self::USE_NONE, self::USE_APPROVALS, self::USE_REPORTS, self::USE_ESCALATIONS])]
    public string $postingUse = self::USE_NONE;

    /** @var string|null The account right a linked account needs to be answered here; null = any linked account */
    #[Length(max: 64)]
    public ?string $requiredAccountRight = null;

    /** @var string|null The purpose a new thread here starts; null = the owner's chat */
    #[Length(max: 128)]
    public ?string $purposeName = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(($this->agentConnectionId ?? 0) . '_' . $this->externalAddress);
    }
}
