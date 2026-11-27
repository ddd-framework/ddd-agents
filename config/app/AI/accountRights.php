<?php

declare(strict_types=1);

use DDD\Domain\AI\Entities\Connections\AgentConnectionsAccountRights;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentRoleMappedAccountRights;
use DDD\Domain\Common\Entities\Roles\Role;

/**
 * Which roles hold which account right of the agent's tools, read as `Config::get('AI.accountRights')` by the default
 * rights of {@see \DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService}
 * ({@see AgentRoleMappedAccountRights}): right name => role names; `'*'` grants a right to every account the agent
 * acts for. A right this file does not name is not held, also a right of another package.
 *
 * Defaults: the operator rights (the platform, the texts, the sandbox, the browser) to the superadmins; the everyday
 * rights (tasks, memory, goals, web, UI, drive) to every account. These defaults apply ONLY to a host on the package's
 * role-mapped rights. An application ships its own file at this path, which REPLACES this one whole: copy it, then
 * change it. An application with its own rights object (it overrides `findAccountRightsOfConversation()` /
 * `findAccountRightsOfCurrentAccount()`) gets the package rights its object does not answer from ITS OWN file at this
 * path only; without one this default file grants it nothing — a package right its object does not hold is not held
 * (fail closed; `app:agent:check-installation` warns).
 */
return [
    AgentAccountRights::OPERATE_PLATFORM => [Role::SUPERADMIN],
    AgentAccountRights::MANAGE_TEXTS => [Role::SUPERADMIN],
    AgentAccountRights::USE_SANDBOX => [Role::SUPERADMIN],
    AgentAccountRights::USE_BROWSER => [Role::SUPERADMIN],
    AgentAccountRights::SCHEDULE_AGENT_TASKS => [AgentRoleMappedAccountRights::EVERY_ACCOUNT],
    AgentAccountRights::USE_AGENT_MEMORY => [AgentRoleMappedAccountRights::EVERY_ACCOUNT],
    AgentAccountRights::USE_AGENT_GOALS => [AgentRoleMappedAccountRights::EVERY_ACCOUNT],
    AgentAccountRights::USE_WEB => [AgentRoleMappedAccountRights::EVERY_ACCOUNT],
    AgentAccountRights::USE_UI => [AgentRoleMappedAccountRights::EVERY_ACCOUNT],
    'useDrive' => [AgentRoleMappedAccountRights::EVERY_ACCOUNT],
    // AgentConnections (switched off by default): the tools for every account, its own connections for every account,
    // the agent's own identities for the superadmin
    AgentConnectionsAccountRights::USE_AGENT_CONNECTIONS => [AgentRoleMappedAccountRights::EVERY_ACCOUNT],
    AgentConnectionsAccountRights::MANAGE_OWN_AGENT_CONNECTIONS => [AgentRoleMappedAccountRights::EVERY_ACCOUNT],
    AgentConnectionsAccountRights::MANAGE_SYSTEM_AGENT_CONNECTIONS => [Role::SUPERADMIN],
];
