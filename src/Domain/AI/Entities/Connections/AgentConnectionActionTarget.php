<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionActionTarget;
use DDD\Domain\AI\Services\Connections\AgentConnectionActionTargetsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\Validator\Constraints\Length;

/**
 * One target of a carried-out outward step (a mail recipient, an invitee, a channel), in the form the audit stores
 * (an internal address in clear, any other as a hash). The per-recipient limit counts these rows by exact match.
 *
 * @property AgentConnectionActionTargets $parent
 * @method AgentConnectionActionTargets getParent()
 * @method static AgentConnectionActionTargetsService getService()
 */
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentConnectionId', 'target', 'doneAt'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionActionTarget::class)]
class AgentConnectionActionTarget extends Entity
{
    use ChangeHistoryTrait;

    public ?int $agentConnectionActionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnectionAction $agentConnectionAction;

    /** @var int|null The connection (copied from the action, so the limit query needs no join) */
    public ?int $agentConnectionId = null;

    /** @var string|null The normalised target as the audit stores it */
    #[Length(max: 255)]
    public ?string $target = null;

    /** @var DateTime|null When the step was carried out */
    public ?DateTime $doneAt = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
