<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionRelease;
use DDD\Domain\AI\Services\Connections\AgentConnectionReleasesService;
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
 * One release of a connection to others: today one target kind, an agent space (everyone working in it), with the
 * highest level they may use and the boundaries (folders, labels, calendars) that hold for that target. The one
 * release mechanism for every capability and both modes: the visibility AGENT_SPACE of a connection is an active
 * release to its space, OWNER_ONLY is none. A system connection without a release is used by nobody. Further target
 * kinds can be added later. Revoking keeps the row (audit).
 *
 * @property AgentConnectionReleases $parent
 * @method AgentConnectionReleases getParent()
 * @method static AgentConnectionReleasesService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentConnectionId', 'revokedAt'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentSpaceId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionRelease::class)]
class AgentConnectionRelease extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string everyone working in the space (membership is the application's answer) */
    public const string TARGET_AGENT_SPACE = 'AGENT_SPACE';

    public ?int $agentConnectionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnection $agentConnection;

    #[Length(max: 24)]
    #[Choice(choices: [self::TARGET_AGENT_SPACE])]
    public string $targetType = self::TARGET_AGENT_SPACE;

    /** @var int|null TARGET_AGENT_SPACE: the space */
    public ?int $agentSpaceId = null;

    /**
     * @var AgentConnectionCapabilityCeilings|null The ceiling per capability. A capability not named here is not
     * shared, also one the connection gains later. Each is also capped by the connection's own level.
     */
    public ?AgentConnectionCapabilityCeilings $capabilityCeilings;

    /** @var AgentConnectionScopeBoundaries|null Boundaries for this target; empty = none beyond the connection's own */
    public ?AgentConnectionScopeBoundaries $scopeBoundaries;

    public ?int $grantedByAccountId = null;

    public ?DateTime $revokedAt = null;

    public ?int $revokedByAccountId = null;

    /** The ceiling of one capability; OFF when it is not released */
    public function getCeilingOfCapability(string $capability): string
    {
        return ($this->capabilityCeilings ?? null)?->getLevelOf($capability) ?? AgentConnectionPermission::LEVEL_OFF;
    }

    /** @return string[] values of one boundary kind for this target */
    public function getScopeBoundaryValues(string $kind): array
    {
        return ($this->scopeBoundaries ?? null)?->getValuesOfKind($kind) ?? [];
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
