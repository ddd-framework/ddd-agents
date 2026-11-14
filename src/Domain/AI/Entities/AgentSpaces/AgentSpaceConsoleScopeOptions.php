<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\AgentSpaces;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The console options by which an application names the space of a console command in its own words
 * ({@see \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::getConsoleScopeOptions()}), one per option name.
 *
 * @property AgentSpaceConsoleScopeOption[] $elements
 * @method AgentSpaceConsoleScopeOption|null first()
 * @method AgentSpaceConsoleScopeOption[] getElements()
 * @method AgentSpaceConsoleScopeOption|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentSpaceConsoleScopeOptions extends ObjectSet
{
}
