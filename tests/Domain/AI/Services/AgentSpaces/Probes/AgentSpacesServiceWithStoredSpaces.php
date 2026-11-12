<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\AgentSpaces\Probes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Entities\Entity;

/** The space service over spaces held in memory: `find()` answers from `$storedAgentSpacesById` and records each id it was asked for. */
class AgentSpacesServiceWithStoredSpaces extends AgentSpacesService
{
    /** @var AgentSpace[] by id */
    public array $storedAgentSpacesById = [];

    /** @var int[] the ids `find()` was asked for, in order */
    public array $idsFindWasAskedFor = [];

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        $this->idsFindWasAskedFor[] = (int)$entityId;
        return $this->storedAgentSpacesById[(int)$entityId] ?? null;
    }
}
