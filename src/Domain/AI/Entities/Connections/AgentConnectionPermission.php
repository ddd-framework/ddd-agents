<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionPermission;
use DDD\Domain\AI\Services\Connections\AgentConnectionPermissionsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/**
 * What the agent may do with one capability of one connection. One ordered level enum for every capability (OFF,
 * READ, DRAFT, WRITE_WITH_APPROVAL, DELETE_WITH_APPROVAL, SEND_WITH_APPROVAL, SEND_BY_RULE) with capability-specific
 * labels ({@see self::LEVEL_LABELS}); {@see levelNeededFor()} maps an operation to its level. A *_WITH_APPROVAL level
 * needs a yes to the exact content per step; SEND_BY_RULE
 * runs without a yes within the limits and the stage guard, first only for internal targets and the account's own
 * calendar.
 * A new connection starts at READ for what its scopes cover.
 *
 * @property AgentConnectionPermissions $parent
 * @method AgentConnectionPermissions getParent()
 * @method static AgentConnectionPermissionsService getService()
 */
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['agentConnectionId', 'capability'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionPermission::class)]
class AgentConnectionPermission extends Entity
{
    use ChangeHistoryTrait;

    public const string CAPABILITY_MAIL = 'MAIL';
    public const string CAPABILITY_CALENDAR = 'CALENDAR';
    public const string CAPABILITY_CONTACTS = 'CONTACTS';
    /** @var string Files of a drive (Google Drive) */
    public const string CAPABILITY_FILES = 'FILES';
    /** @var string Chat messages (Slack) */
    public const string CAPABILITY_CHAT = 'CHAT';
    /** @var string Messaging to people outside the team (WhatsApp Business); no adapter yet */
    public const string CAPABILITY_MESSAGING = 'MESSAGING';

    public const array CAPABILITIES = [
        self::CAPABILITY_MAIL, self::CAPABILITY_CALENDAR, self::CAPABILITY_CONTACTS, self::CAPABILITY_FILES,
        self::CAPABILITY_CHAT, self::CAPABILITY_MESSAGING,
    ];

    public const string LEVEL_OFF = 'OFF';
    /** @var string search, read, list, free/busy */
    public const string LEVEL_READ = 'READ';
    /** @var string also drafts (a mail draft, a draft post): reach nobody */
    public const string LEVEL_DRAFT = 'DRAFT';
    /** @var string also writes that reach no person: create, change, merge, link, unlink (a contact, a file, an own event), each after a yes to the exact content */
    public const string LEVEL_WRITE_WITH_APPROVAL = 'WRITE_WITH_APPROVAL';
    /** @var string also removals (a contact, a file, an event), each after a yes */
    public const string LEVEL_DELETE_WITH_APPROVAL = 'DELETE_WITH_APPROVAL';
    /** @var string also what reaches people (a mail, an invitation, a post), each after a yes to the exact content */
    public const string LEVEL_SEND_WITH_APPROVAL = 'SEND_WITH_APPROVAL';
    /** @var string also without a yes, under a standing rule (mandate), first only for internal targets */
    public const string LEVEL_SEND_BY_RULE = 'SEND_BY_RULE';

    /** @var string[] one ordered enum for every capability, lowest first */
    public const array LEVELS = [
        self::LEVEL_OFF, self::LEVEL_READ, self::LEVEL_DRAFT, self::LEVEL_WRITE_WITH_APPROVAL, self::LEVEL_DELETE_WITH_APPROVAL,
        self::LEVEL_SEND_WITH_APPROVAL, self::LEVEL_SEND_BY_RULE,
    ];

    /** @var array<string, array<string, string>> capability-specific labels of the levels (screens and the agent) */
    public const array LEVEL_LABELS = [
        self::CAPABILITY_MAIL => [self::LEVEL_READ => 'read', self::LEVEL_DRAFT => 'draft', self::LEVEL_WRITE_WITH_APPROVAL => 'draft, labels with approval', self::LEVEL_DELETE_WITH_APPROVAL => 'also delete with approval', self::LEVEL_SEND_WITH_APPROVAL => 'send with approval', self::LEVEL_SEND_BY_RULE => 'send by rule'],
        self::CAPABILITY_CALENDAR => [self::LEVEL_READ => 'read, free/busy', self::LEVEL_DRAFT => 'read', self::LEVEL_WRITE_WITH_APPROVAL => 'own events with approval', self::LEVEL_DELETE_WITH_APPROVAL => 'also delete events with approval', self::LEVEL_SEND_WITH_APPROVAL => 'invite with approval', self::LEVEL_SEND_BY_RULE => 'invite by rule'],
        self::CAPABILITY_CONTACTS => [self::LEVEL_READ => 'read', self::LEVEL_DRAFT => 'read', self::LEVEL_WRITE_WITH_APPROVAL => 'write with approval', self::LEVEL_DELETE_WITH_APPROVAL => 'also merge and delete with approval', self::LEVEL_SEND_WITH_APPROVAL => 'also merge and delete with approval', self::LEVEL_SEND_BY_RULE => 'write by rule, merge and delete with approval'],
        self::CAPABILITY_FILES => [self::LEVEL_READ => 'read', self::LEVEL_DRAFT => 'read', self::LEVEL_WRITE_WITH_APPROVAL => 'write with approval', self::LEVEL_DELETE_WITH_APPROVAL => 'also delete with approval', self::LEVEL_SEND_WITH_APPROVAL => 'also share with approval', self::LEVEL_SEND_BY_RULE => 'write by rule'],
        self::CAPABILITY_CHAT => [self::LEVEL_READ => 'read', self::LEVEL_DRAFT => 'draft', self::LEVEL_WRITE_WITH_APPROVAL => 'draft', self::LEVEL_DELETE_WITH_APPROVAL => 'also delete own posts with approval', self::LEVEL_SEND_WITH_APPROVAL => 'post with approval', self::LEVEL_SEND_BY_RULE => 'post by rule'],
        self::CAPABILITY_MESSAGING => [self::LEVEL_READ => 'read', self::LEVEL_DRAFT => 'draft', self::LEVEL_WRITE_WITH_APPROVAL => 'draft', self::LEVEL_DELETE_WITH_APPROVAL => 'draft', self::LEVEL_SEND_WITH_APPROVAL => 'send with approval', self::LEVEL_SEND_BY_RULE => 'send by rule'],
    ];

    /** @var string[] Operations that remove something (a merge deletes its donor): always the delete level and a person's yes */
    public const array REMOVING_OPERATIONS = ['DELETE', 'MERGE'];

    /**
     * The level an operation needs: a step that reaches people (recipients, attendees, a post) SEND_WITH_APPROVAL; a
     * removal (delete, merge) DELETE_WITH_APPROVAL; any other write (create, change, link, unlink) WRITE_WITH_APPROVAL;
     * a draft DRAFT; everything else READ.
     */
    public static function levelNeededFor(string $operation, bool $reachesPeople): string
    {
        return match (true) {
            $reachesPeople || $operation === 'SEND' => self::LEVEL_SEND_WITH_APPROVAL,
            in_array($operation, self::REMOVING_OPERATIONS, true) => self::LEVEL_DELETE_WITH_APPROVAL,
            in_array($operation, ['CREATE', 'UPDATE', 'LINK', 'UNLINK'], true) => self::LEVEL_WRITE_WITH_APPROVAL,
            $operation === 'DRAFT' => self::LEVEL_DRAFT,
            default => self::LEVEL_READ,
        };
    }

    public static function labelOfLevel(string $capability, string $level): string
    {
        return $level === self::LEVEL_OFF ? 'off' : (self::LEVEL_LABELS[$capability][$level] ?? strtolower($level));
    }

    public ?int $agentConnectionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnection $agentConnection;

    #[Length(max: 20)]
    #[Choice(choices: self::CAPABILITIES)]
    public ?string $capability = null;

    #[Length(max: 20)]
    #[Choice(choices: self::LEVELS)]
    public string $level = self::LEVEL_READ;

    public ?int $grantedByAccountId = null;

    public static function rankOfLevel(string $level): int
    {
        $rank = array_search($level, self::LEVELS, true);
        return $rank === false ? 0 : (int)$rank;
    }

    public static function lowerLevel(string $firstLevel, string $secondLevel): string
    {
        return self::rankOfLevel($firstLevel) <= self::rankOfLevel($secondLevel) ? $firstLevel : $secondLevel;
    }

    public function allows(string $neededLevel): bool
    {
        return self::rankOfLevel($this->level) >= self::rankOfLevel($neededLevel);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(($this->agentConnectionId ?? 0) . '_' . $this->capability);
    }
}
