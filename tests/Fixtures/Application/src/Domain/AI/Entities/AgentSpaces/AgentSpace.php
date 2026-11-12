<?php

declare(strict_types=1);

namespace App\Domain\AI\Entities\AgentSpaces;

use App\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpace;
use App\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use App\Domain\Workshops\Entities\Workshops\Workshop;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceTimezone;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\Accounts\Account;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Fixture application: what the agent works for is a {@see Workshop}. One space per workshop, created by
 * {@see AgentSpacesService::findOrCreateAgentSpaceOfWorkshop()}.
 *
 * The space adds the application's own reference — a column every row must carry — and answers two facts from the
 * workshop: its owner and, when the space stores no zone itself, its time zone. Who may read a space is decided by the
 * workshop ({@see DBAgentSpace::applyReadRightsQuery()}); the package column `accountId` decides nothing here.
 *
 * @method static AgentSpacesService getService()
 * @method static DBAgentSpace getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentSpace::class)]
class AgentSpace extends \DDD\Domain\AI\Entities\AgentSpaces\AgentSpace
{
    /** @var int|null Id of the workshop this space stands for; one space per workshop */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    public ?int $workshopId;

    /** @var Workshop|null The workshop this space stands for; deleting the workshop deletes its space, and with the space its conversations, schedules and mandates */
    #[LazyLoad(addAsParent: true)]
    public ?Workshop $workshop;

    /**
     * The zone the space stores, else the zone of its workshop (not confirmed by anyone for the agent).
     *
     * @return AgentSpaceTimezone|null null when neither is known
     */
    public function findTimezone(): ?AgentSpaceTimezone
    {
        $storedTimezone = parent::findTimezone();
        if ($storedTimezone !== null) {
            return $storedTimezone;
        }
        $workshop = $this->workshop ?? null;
        $workshopTimezoneIdentifier = $workshop?->timezone;
        if ($workshopTimezoneIdentifier === null || $workshopTimezoneIdentifier === '') {
            return null;
        }
        return AgentSpaceTimezone::ofKnownIdentifier($workshopTimezoneIdentifier, true);
    }

    /**
     * The account that owns the workshop today.
     *
     * @return Account|null null when the workshop or its account cannot be read
     */
    public function findOwnerAccount(): ?Account
    {
        $workshop = $this->workshop ?? null;
        return $workshop?->account ?? null;
    }
}
