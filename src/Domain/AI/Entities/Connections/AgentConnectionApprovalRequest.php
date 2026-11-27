<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionApprovalRequest;
use DDD\Domain\AI\Services\Connections\AgentConnectionApprovalRequestsService;
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
 * An outward step of a conversation that waits for a person: the exact content and its hash, the options, and
 * through which channel it was answered. The owner question of an unattended run made deliverable: shown in the
 * host's approval list and posted with buttons where the connection names the approval channel. A yes counts only
 * from an account that holds the right for the step, and only for the content whose hash it carries.
 *
 * @property AgentConnectionApprovalRequests $parent
 * @method AgentConnectionApprovalRequests getParent()
 * @method static AgentConnectionApprovalRequestsService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['accountId', 'status'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['aiConversationId'])]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['approvalToken'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionApprovalRequest::class)]
class AgentConnectionApprovalRequest extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string STATUS_OPEN = 'OPEN';
    public const string STATUS_APPROVED = 'APPROVED';
    public const string STATUS_REJECTED = 'REJECTED';
    /** @var string The step was carried out with the approval */
    public const string STATUS_EXECUTED = 'EXECUTED';
    public const string STATUS_EXPIRED = 'EXPIRED';
    /** @var string approved, but the step failed when it ran */
    public const string STATUS_FAILED = 'FAILED';

    public const string OPTION_APPROVE = 'APPROVE';
    public const string OPTION_REJECT = 'REJECT';

    public const string ANSWERED_VIA_ADMIN = 'ADMIN';
    public const string ANSWERED_VIA_CHAT = 'CHAT';
    public const string ANSWERED_VIA_SLACK = 'SLACK';

    public ?int $agentConnectionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnection $agentConnection;

    /** @var int|null The account whose conversation asks (the run's owner) */
    public ?int $accountId = null;

    public ?int $aiConversationId = null;

    #[Length(max: 100)]
    public ?string $toolName = null;

    #[Length(max: 20)]
    public ?string $capability = null;

    #[Length(max: 20)]
    public ?string $operation = null;

    /** @var string|null One line a person reads: what, to whom */
    #[Length(max: 500)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $summary = null;

    /** @var string|null The exact content (the mail with recipients, the event) as the person sees it */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_MEDIUMTEXT)]
    public ?string $content = null;

    /** @var string|null The JSON arguments the step will run with (the approved content is exactly these) */
    #[HideProperty]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_MEDIUMTEXT)]
    public ?string $stepArgumentsJson = null;

    #[Length(max: 64)]
    public ?string $contentHash = null;

    /** @var AgentConnectionApprovalOptions|null The answers a person may give */
    public ?AgentConnectionApprovalOptions $options;

    #[Length(max: 16)]
    #[Choice(choices: [self::STATUS_OPEN, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_EXECUTED, self::STATUS_EXPIRED, self::STATUS_FAILED])]
    public string $status = self::STATUS_OPEN;

    /** @var string|null A random token that names the request in a button (never the id alone) */
    #[HideProperty]
    #[Length(max: 64)]
    public ?string $approvalToken = null;

    public ?int $answeredByAccountId = null;

    #[Length(max: 16)]
    public ?string $answeredVia = null;

    /** @var int|null The connection the answer came through (a Slack button) */
    public ?int $answeredThroughAgentConnectionId = null;

    public ?DateTime $answeredAt = null;

    /** @var string|null Where it was posted: channel and message id, to shorten the post to its outcome */
    #[Length(max: 255)]
    public ?string $postedExternalReference = null;

    public ?DateTime $expiresAt = null;

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        return isset($this->expiresAt) && $this->expiresAt !== null && $this->expiresAt < new DateTime();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
