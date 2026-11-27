<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionAccountLink;
use DDD\Domain\AI\Services\Connections\AgentConnectionAccountLinksService;
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
 * Mode 2: an external user (a Slack user) is this account. Decides under whose rights the agent answers; no link,
 * no answer beyond one fixed line and no model call.
 *
 * @property AgentConnectionAccountLinks $parent
 * @method AgentConnectionAccountLinks getParent()
 * @method static AgentConnectionAccountLinksService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['agentConnectionId', 'externalUserId'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['accountId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionAccountLink::class)]
class AgentConnectionAccountLink extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string The provider's verified e-mail of the user equals the account's e-mail */
    public const string VERIFIED_BY_EMAIL_MATCH = 'EMAIL_MATCH';
    /** @var string An account with the system right linked it by hand */
    public const string VERIFIED_BY_ADMIN = 'ADMIN';

    public ?int $agentConnectionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnection $agentConnection;

    #[Length(max: 64)]
    public ?string $externalUserId = null;

    #[Length(max: 255)]
    public ?string $externalDisplayName = null;

    public ?int $accountId = null;

    public ?DateTime $verifiedAt = null;

    #[Length(max: 20)]
    #[Choice(choices: [self::VERIFIED_BY_EMAIL_MATCH, self::VERIFIED_BY_ADMIN])]
    public ?string $verificationMethod = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(($this->agentConnectionId ?? 0) . '_' . $this->externalUserId);
    }
}
