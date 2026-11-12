<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\DB\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpace;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/** The space repo with the database read replaced: it records, per read, the id asked for and whether the read rights applied. */
class DBAgentSpaceRecordingRightsRestrictionsStub extends DBAgentSpace
{
    /** @var int[] The ids read, in order */
    public array $agentSpaceIdsRead = [];

    /** @var bool[] Whether the read rights applied while each read ran, in order */
    public array $readRightsAppliedPerRead = [];

    /** @var AgentSpace|null What a read returns */
    public ?AgentSpace $agentSpaceToReturn = null;

    /** @var bool true = a read throws */
    public bool $isReadFailing = false;

    public function find(
        DoctrineQueryBuilder|string|int $idOrQueryBuilder,
        bool $useEntityRegistrCache = true,
        ?DoctrineModel &$loadedOrmInstance = null,
        bool $deferredCaching = false,
        array $initiatorClasses = []
    ): ?DefaultObject {
        $this->agentSpaceIdsRead[] = (int)$idOrQueryBuilder;
        $this->readRightsAppliedPerRead[] = static::getApplyRightsRestrictions();
        if ($this->isReadFailing) {
            throw new InternalErrorException('The read of the space failed.');
        }
        return $this->agentSpaceToReturn;
    }
}
