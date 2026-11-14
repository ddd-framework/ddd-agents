<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceConsoleScopeOption;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceConsoleScopeOptions;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * An agent-spaces service of an application that names its spaces on the console by a key of its own: it declares
 * one scope option, knows one stored space by id and one by key, and gives every space the same owner. Reads nothing
 * from a database.
 */
class AgentSpacesServiceConsoleScopeDouble extends AgentSpacesService
{
    public const string SCOPE_OPTION_NAME = 'externalSpaceKey';

    public const int STORED_AGENT_SPACE_ID = 7;

    public const string KNOWN_SCOPE_OPTION_VALUE = 'north';

    public const int AGENT_SPACE_ID_OF_KNOWN_SCOPE_OPTION_VALUE = 42;

    public const int OWNER_ACCOUNT_ID = 5;

    /** @var array<int, array<string, string>> The option values the command asked about, in order */
    public array $askedScopeOptionValues = [];

    public function getConsoleScopeOptions(): AgentSpaceConsoleScopeOptions
    {
        $agentSpaceConsoleScopeOptions = new AgentSpaceConsoleScopeOptions();
        $agentSpaceConsoleScopeOption = AgentSpaceConsoleScopeOption::forNameAndDescription(self::SCOPE_OPTION_NAME, 'The key of the space in the application');
        $agentSpaceConsoleScopeOptions->add($agentSpaceConsoleScopeOption);
        return $agentSpaceConsoleScopeOptions;
    }

    public function findAgentSpaceForConsoleScopeOptionValues(array $optionValuesByName): ?AgentSpace
    {
        $this->askedScopeOptionValues[] = $optionValuesByName;
        if (($optionValuesByName[self::SCOPE_OPTION_NAME] ?? null) !== self::KNOWN_SCOPE_OPTION_VALUE) {
            return null;
        }
        return $this->buildAgentSpaceWithId(self::AGENT_SPACE_ID_OF_KNOWN_SCOPE_OPTION_VALUE);
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return (int)$entityId === self::STORED_AGENT_SPACE_ID ? $this->buildAgentSpaceWithId(self::STORED_AGENT_SPACE_ID) : null;
    }

    public function findOwnerAccountOfAgentSpace(AgentSpace $agentSpace): ?Account
    {
        $ownerAccount = Account::newInstance();
        $ownerAccount->id = self::OWNER_ACCOUNT_ID;
        return $ownerAccount;
    }

    protected function buildAgentSpaceWithId(int $agentSpaceId): AgentSpace
    {
        $agentSpace = AgentSpace::newInstance();
        $agentSpace->id = $agentSpaceId;
        return $agentSpace;
    }
}
