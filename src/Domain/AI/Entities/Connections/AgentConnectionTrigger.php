<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionTrigger;
use DDD\Domain\AI\Services\Connections\AgentConnectionTriggersService;
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
 * Mode 1 inbound: an event of the connection (a new mail, a mention) that matches the typed filter starts an
 * owner-absent run of the account (a purpose or a mission text). The event-driven sibling of a schedule: the run
 * follows the contract of a scheduled run (owner absent, report to the owner, outward steps through the approval
 * bridge). No trigger, nothing happens: a connected mailbox is read when the account asks, not watched.
 *
 * @property AgentConnectionTriggers $parent
 * @method AgentConnectionTriggers getParent()
 * @method static AgentConnectionTriggersService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentConnectionId', 'active'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionTrigger::class)]
class AgentConnectionTrigger extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string EVENT_NEW_MAIL = 'NEW_MAIL';
    public const string EVENT_MENTION = 'MENTION';
    public const string EVENT_NEW_MESSAGE = 'NEW_MESSAGE';
    public const string EVENT_CALENDAR_CHANGE = 'CALENDAR_CHANGE';

    public ?int $agentConnectionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnection $agentConnection;

    /** @var int|null The account whose run starts */
    public ?int $accountId = null;

    #[Length(max: 32)]
    #[Choice(choices: [self::EVENT_NEW_MAIL, self::EVENT_MENTION, self::EVENT_NEW_MESSAGE, self::EVENT_CALENDAR_CHANGE])]
    public ?string $eventKind = null;

    /** @var string|null Only events whose sender address or user id contains this text */
    #[Length(max: 255)]
    public ?string $senderFilter = null;

    /** @var string|null Only mails whose subject contains this text */
    #[Length(max: 255)]
    public ?string $subjectFilter = null;

    /** @var string|null Only events of this channel or mail label */
    #[Length(max: 128)]
    public ?string $channelFilter = null;

    /** @var string|null The purpose the run starts; else {@see $missionText} in the account's chat */
    #[Length(max: 128)]
    public ?string $purposeName = null;

    #[Length(max: 2000)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $missionText = null;

    /** @var float|null The cost cap of one run in USD */
    public ?float $perRunCostCapUsd = null;

    public bool $active = true;

    public ?DateTime $lastFiredAt = null;

    public function matches(string $eventKind, ?string $sender, ?string $subject, ?string $channel): bool
    {
        if (!$this->active || $this->eventKind !== $eventKind) {
            return false;
        }
        if ($this->senderFilter !== null && $this->senderFilter !== '' && mb_stripos((string)$sender, $this->senderFilter) === false) {
            return false;
        }
        if ($this->subjectFilter !== null && $this->subjectFilter !== '' && mb_stripos((string)$subject, $this->subjectFilter) === false) {
            return false;
        }
        if ($this->channelFilter !== null && $this->channelFilter !== '' && $this->channelFilter !== $channel) {
            return false;
        }
        return true;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
