<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionThreads;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionCapabilityCeilings;
use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionReleaseModel;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermissions;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAddresses;
use DDD\Domain\AI\Entities\Connections\AgentConnectionReleases;
use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionModel;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnections;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionConsentRequiredException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\OAuthAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters;
use DDD\Domain\AI\Entities\Connections\AgentConnectionRelease;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;

/**
 * The connections: which ones an account may use (its own, and every connection released to a space it works in,
 * system connections included), finding one by the reference a tool names (id or name), the level that applies, creating, pausing and
 * disconnecting, and counting provider errors.
 *
 * An account may hold ANY number of connections of every provider and both modes (owner 2026-11-27); nothing here
 * assumes one per provider. A tool names its connection; without a name the default of the capability is used, and
 * with several and no default the tool asks which one.
 *
 * Host hooks (an application's subclass at the same path): {@see findAgentSpaceIdForSharing()},
 * {@see mayShareToAgentSpace()}, {@see isAccountMemberOfAgentSpace()}.
 *
 * @method AgentConnection|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnections findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AgentConnection update(AgentConnection &$entity)
 */
class AgentConnectionsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnection::class;

    /**
     * Every connection `$accountId` reaches ({@see AgentConnectionReach}: in a conversation only the releases to its
     * space; the management view every member space), of any provider and mode, connected in this stage and with its
     * adapter switched on. Read without rights (the filter is this method's own).
     */
    public function findUsableConnectionsOfAccount(int $accountId, AgentConnectionReach $agentConnectionReach): AgentConnections
    {
        $agentConnectionsConfiguration = AgentConnectionsConfiguration::current();
        $usableConnections = AgentConnections::newInstance();
        if (!$agentConnectionsConfiguration->isEnabled()) {
            return $usableConnections;
        }
        $currentStage = $agentConnectionsConfiguration->getCurrentStage();
        $enabledProviders = $agentConnectionsConfiguration->getEnabledProviders();
        // the connections the account owns, plus those released to a space the account is a member of: membership is
        // asked once per released space, then one query loads the candidates (no per-row release queries)
        $memberAgentSpaceIds = [];
        foreach ($this->findSpaceIdsWithActiveReleases() as $releasedAgentSpaceId) {
            if ($agentConnectionReach->admitsReleaseTo($releasedAgentSpaceId) && $this->isAccountMemberOfAgentSpace($accountId, $releasedAgentSpaceId)) {
                $memberAgentSpaceIds[] = $releasedAgentSpaceId;
            }
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnections::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $reachCondition = "($alias.accountId = :accountId AND $alias.ownership = :accountGranted)";
            $queryBuilder->setParameter('accountId', $accountId)->setParameter('accountGranted', AgentConnection::OWNERSHIP_ACCOUNT_GRANTED);
            if ($memberAgentSpaceIds !== []) {
                $reachCondition .= " OR $alias.id IN (SELECT memberRelease.agentConnectionId FROM " . DBAgentConnectionReleaseModel::class
                    . ' memberRelease WHERE memberRelease.agentSpaceId IN (:memberAgentSpaceIds) AND memberRelease.revokedAt IS NULL)';
                $queryBuilder->setParameter('memberAgentSpaceIds', $memberAgentSpaceIds);
            }
            $queryBuilder->andWhere($reachCondition)
                ->andWhere("$alias.status IN (:usableStatuses)")
                ->setParameter('usableStatuses', [AgentConnection::STATUS_CONNECTED, AgentConnection::STATUS_EXPIRED, AgentConnection::STATUS_PAUSED])
                ->andWhere("$alias.environment = :currentStage")->setParameter('currentStage', $currentStage)
                ->andWhere("$alias.provider IN (:enabledProviders)")->setParameter('enabledProviders', $enabledProviders !== [] ? $enabledProviders : ['-'])
                ->orderBy("$alias.id", 'ASC');
            $candidateConnections = $repository->find($queryBuilder, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        foreach ($candidateConnections?->getElements() ?? [] as $agentConnection) {
            $usableConnections->add($agentConnection);
        }
        return $usableConnections;
    }

    /**
     * How `$accountId` reaches the connection; null = not at all. Mode 1: its owner (by `accountId`) always; anyone
     * else only through an active release to a space the account is a member of. Mode 2 (system connections): ONLY
     * through an active release. Membership is the application's answer ({@see isAccountMemberOfAgentSpace()}, fail
     * closed); the space of a conversation alone grants nothing, and in a conversation only a release to its own space
     * counts ({@see AgentConnectionReach}). Of several releases the one with the highest ceiling for `$capability` wins
     * (without a capability: the first that reaches).
     */
    public function getAccessOfAccount(AgentConnection $agentConnection, int $accountId, AgentConnectionReach $agentConnectionReach, ?string $capability = null): ?AgentConnectionAccess
    {
        // the owner's own access holds only where nobody else reads; in a conversation others read only a release to
        // that conversation's space counts (below)
        if (!$agentConnection->isAgentOwned() && $agentConnection->accountId !== null && (int)$agentConnection->accountId === $accountId
            && ($agentConnectionReach->acrossMemberSpaces || $agentConnectionReach->aIConversationId === null
                || !$this->isConversationSharedWithOthers($agentConnectionReach->aIConversationId, $agentConnection))) {
            return AgentConnectionAccess::asOwner();
        }
        $bestAccess = null;
        foreach ($this->findActiveReleasesOfConnection($agentConnection)->getElements() as $agentConnectionRelease) {
            if ($agentConnectionRelease->targetType !== AgentConnectionRelease::TARGET_AGENT_SPACE || $agentConnectionRelease->agentSpaceId === null
                || !$agentConnectionReach->admitsReleaseTo((int)$agentConnectionRelease->agentSpaceId)
                || !$this->isAccountMemberOfAgentSpace($accountId, (int)$agentConnectionRelease->agentSpaceId)) {
                continue;
            }
            $candidateAccess = AgentConnectionAccess::throughRelease($agentConnectionRelease);
            if ($bestAccess === null || ($capability !== null && AgentConnectionPermission::rankOfLevel($candidateAccess->getCeiling($capability)) > AgentConnectionPermission::rankOfLevel($bestAccess->getCeiling($capability)))) {
                $bestAccess = $candidateAccess;
            }
        }
        return $bestAccess;
    }

    /**
     * The level `$accountId` may use for `$capability`: the connection's level, capped by the release's ceiling for
     * that capability. OFF when not offered, not released or not reachable.
     */
    public function getEffectiveLevel(AgentConnection $agentConnection, string $capability, int $accountId, AgentConnectionReach $agentConnectionReach): string
    {
        $agentConnectionAccess = $this->getAccessOfAccount($agentConnection, $accountId, $agentConnectionReach, $capability);
        if ($agentConnectionAccess === null) {
            return AgentConnectionPermission::LEVEL_OFF;
        }
        $level = AgentConnectionPermission::LEVEL_OFF;
        foreach ($this->findPermissionsOfConnection($agentConnection)->getElements() as $agentConnectionPermission) {
            if ($agentConnectionPermission->capability === $capability) {
                $level = $agentConnectionPermission->level;
            }
        }
        return AgentConnectionPermission::lowerLevel($level, $agentConnectionAccess->getCeiling($capability));
    }

    public function findActiveReleasesOfConnection(AgentConnection $agentConnection): AgentConnectionReleases
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionReleases::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', (int)$agentConnection->id)
                ->andWhere("$alias.revokedAt IS NULL");
            return $repository->find($queryBuilder, false) ?? AgentConnectionReleases::newInstance();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** @return int[] the agent spaces that hold at least one active release */
    protected function findSpaceIdsWithActiveReleases(): array
    {
        $selectQuery = EntityManagerFactory::getInstance()->createQuery(
            'SELECT DISTINCT activeRelease.agentSpaceId FROM ' . DBAgentConnectionReleaseModel::class . ' activeRelease'
            . ' WHERE activeRelease.revokedAt IS NULL AND activeRelease.agentSpaceId IS NOT NULL'
        );
        $agentSpaceIds = [];
        foreach ($selectQuery->getScalarResult() as $resultRow) {
            $agentSpaceIds[] = (int)$resultRow['agentSpaceId'];
        }
        return $agentSpaceIds;
    }

    /** @var string only the owning account (mode 1) uses the connection */
    public const string VISIBILITY_OWNER_ONLY = 'OWNER_ONLY';
    /** @var string everyone working in the connection's agent space uses it (and the space's drive, for files) */
    public const string VISIBILITY_AGENT_SPACE = 'AGENT_SPACE';

    /**
     * The visibility of a connection: OWNER_ONLY (mode 1 only: only the owning account) or AGENT_SPACE (an active
     * release to a space). Stored as the release list. The application names the options in its own words. A system
     * connection is always AGENT_SPACE (it has no owning account).
     */
    public function getVisibility(AgentConnection $agentConnection): string
    {
        return $this->findActiveReleasesOfConnection($agentConnection)->count() > 0 || $agentConnection->isAgentOwned()
            ? self::VISIBILITY_AGENT_SPACE
            : self::VISIBILITY_OWNER_ONLY;
    }

    /**
     * Releases the connection to a space with a ceiling per capability (replaces an active release to the same
     * space), audited.
     *
     * @param AgentConnectionCapabilityCeilings|null $capabilityCeilings null = every capability the connection offers,
     *                                                                  at most READ
     * @throws AgentConnectionRefusedException a target the application does not offer or allow
     */
    public function releaseToAgentSpace(AgentConnection $agentConnection, int $agentSpaceId, ?AgentConnectionCapabilityCeilings $capabilityCeilings, int $changedByAccountId): AgentConnectionRelease
    {
        $this->invalidateReadCache($agentConnection);
        if (!in_array($agentSpaceId, $this->findShareableAgentSpaceIdsOfAccount($agentConnection, $changedByAccountId), true)
            || !$this->mayShareToAgentSpace($agentConnection, $changedByAccountId, $agentSpaceId)) {
            throw new AgentConnectionRefusedException('This connection cannot be shared with that space by this account.');
        }
        if ($capabilityCeilings === null) {
            $ceilingsByCapability = [];
            // no ceilings named: the released space reads only; the owner raises a ceiling explicitly
            foreach ($this->findPermissionsOfConnection($agentConnection)->getElements() as $agentConnectionPermission) {
                $ceilingsByCapability[(string)$agentConnectionPermission->capability] = AgentConnectionPermission::lowerLevel($agentConnectionPermission->level, AgentConnectionPermission::LEVEL_READ);
            }
            $capabilityCeilings = AgentConnectionCapabilityCeilings::fromLevelsByCapability($ceilingsByCapability);
        }
        $this->revokeReleases($agentConnection, $changedByAccountId, $agentSpaceId);
        return $this->createRelease($agentConnection, $agentSpaceId, $capabilityCeilings->normalized(), $changedByAccountId);
    }

    /**
     * A system connection works in the space that owns it: an active release to that space, without a cap below the
     * connection's own levels (so the bot reaches what its levels allow, e.g. posting with approval). Not a share (no
     * share hooks asked); a reconnect keeps an existing release and its ceilings.
     */
    public function ensureOwningSpaceRelease(AgentConnection $agentConnection, int $changedByAccountId): void
    {
        if (!$agentConnection->isAgentOwned() || $agentConnection->agentSpaceId === null) {
            return;
        }
        foreach ($this->findActiveReleasesOfConnection($agentConnection)->getElements() as $agentConnectionRelease) {
            if ((int)$agentConnectionRelease->agentSpaceId === (int)$agentConnection->agentSpaceId) {
                return;
            }
        }
        $ceilingsByCapability = [];
        foreach ($this->findPermissionsOfConnection($agentConnection)->getElements() as $agentConnectionPermission) {
            $ceilingsByCapability[(string)$agentConnectionPermission->capability] = AgentConnectionPermission::LEVEL_SEND_BY_RULE;
        }
        $this->createRelease($agentConnection, (int)$agentConnection->agentSpaceId, AgentConnectionCapabilityCeilings::fromLevelsByCapability($ceilingsByCapability), $changedByAccountId);
    }

    protected function createRelease(AgentConnection $agentConnection, int $agentSpaceId, AgentConnectionCapabilityCeilings $capabilityCeilings, int $changedByAccountId): AgentConnectionRelease
    {
        $this->invalidateReadCache($agentConnection);
        $agentConnectionRelease = AgentConnectionRelease::newInstance();
        $agentConnectionRelease->agentConnectionId = (int)$agentConnection->id;
        $agentConnectionRelease->targetType = AgentConnectionRelease::TARGET_AGENT_SPACE;
        $agentConnectionRelease->agentSpaceId = $agentSpaceId;
        $agentConnectionRelease->capabilityCeilings = $capabilityCeilings;
        $agentConnectionRelease->grantedByAccountId = $changedByAccountId;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnectionRelease->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $this->recordSettingChange($agentConnection, AgentConnectionAction::OPERATION_VISIBILITY, $changedByAccountId, "released to space $agentSpaceId: " . str_replace("\n", ', ', $capabilityCeilings->fingerprintText()));
        return $agentConnectionRelease;
    }

    /**
     * Sets the visibility. AGENT_SPACE needs the target space (one of {@see findShareableAgentSpaceIdsOfAccount()};
     * without one, the only offered space); OWNER_ONLY revokes every release and is refused for a system connection.
     *
     * @throws AgentConnectionRefusedException
     */
    public function setVisibility(AgentConnection $agentConnection, string $visibility, int $changedByAccountId, ?int $targetAgentSpaceId = null): void
    {
        $this->invalidateReadCache($agentConnection);
        if ($visibility === self::VISIBILITY_OWNER_ONLY) {
            if ($agentConnection->isAgentOwned() || $agentConnection->accountId === null) {
                throw new AgentConnectionRefusedException('A system connection has no owning account; it is always shared with a space.');
            }
            if ($this->revokeReleases($agentConnection, $changedByAccountId, null) > 0) {
                $this->recordSettingChange($agentConnection, AgentConnectionAction::OPERATION_VISIBILITY, $changedByAccountId, 'visibility OWNER_ONLY');
            }
            return;
        }
        if ($visibility !== self::VISIBILITY_AGENT_SPACE) {
            throw new AgentConnectionRefusedException('Unknown visibility.');
        }
        $this->releaseToAgentSpace($agentConnection, $this->resolveShareTarget($agentConnection, $changedByAccountId, $targetAgentSpaceId), null, $changedByAccountId);
    }

    /**
     * The space `$accountId` shares the connection with: the named one when the account may share there, without a
     * name the only offered space. Works on a connection that is not stored yet (the visibility chosen before an
     * OAuth consent is checked before the consent starts).
     *
     * @throws AgentConnectionRefusedException no offered space, several without a name, or a space the account may not share with
     */
    public function resolveShareTarget(AgentConnection $agentConnection, int $accountId, ?int $targetAgentSpaceId): int
    {
        $shareableAgentSpaceIds = $this->findShareableAgentSpaceIdsOfAccount($agentConnection, $accountId);
        if ($targetAgentSpaceId === null) {
            if (count($shareableAgentSpaceIds) !== 1) {
                throw new AgentConnectionRefusedException('Name the space to share the connection with.');
            }
            return $shareableAgentSpaceIds[0];
        }
        if (!in_array($targetAgentSpaceId, $shareableAgentSpaceIds, true) || !$this->mayShareToAgentSpace($agentConnection, $accountId, $targetAgentSpaceId)) {
            throw new AgentConnectionRefusedException('This connection cannot be shared with that space by this account.');
        }
        return $targetAgentSpaceId;
    }

    /** Revokes the active releases (of one space, or all); returns how many */
    public function revokeReleases(AgentConnection $agentConnection, int $revokedByAccountId, ?int $onlyAgentSpaceId): int
    {
        $this->invalidateReadCache($agentConnection);
        $revokedCount = 0;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            foreach ($this->findActiveReleasesOfConnection($agentConnection)->getElements() as $agentConnectionRelease) {
                if ($onlyAgentSpaceId !== null && (int)$agentConnectionRelease->agentSpaceId !== $onlyAgentSpaceId) {
                    continue;
                }
                $agentConnectionRelease->revokedAt = new DateTime();
                $agentConnectionRelease->revokedByAccountId = $revokedByAccountId;
                $agentConnectionRelease->update();
                $revokedCount++;
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $revokedCount;
    }

    protected function recordSettingChange(AgentConnection $agentConnection, string $operation, int $accountId, string $text): void
    {
        /** @var AgentConnectionActionsService $agentConnectionActionsService */
        $agentConnectionActionsService = DDDService::instance()->getService(AgentConnectionActionsService::class);
        $agentConnectionActionsService->recordAdministration($agentConnection, $operation, $accountId, $text);
    }

    /**
     * HOST HOOK. The spaces `$accountId` may share the connection with (the targets a screen offers). Default: a
     * system connection its own space; a mode-1 connection none (the application decides).
     *
     * @return int[]
     */
    public function findShareableAgentSpaceIdsOfAccount(AgentConnection $agentConnection, int $accountId): array
    {
        return $agentConnection->isAgentOwned() && $agentConnection->agentSpaceId !== null ? [(int)$agentConnection->agentSpaceId] : [];
    }

    /**
     * HOST HOOK. Whether `$accountId` may share the connection with the space. Default: the owner of a mode-1
     * connection; a system connection only by the application (its system right).
     */
    public function mayShareToAgentSpace(AgentConnection $agentConnection, int $accountId, int $agentSpaceId): bool
    {
        return !$agentConnection->isAgentOwned() && $agentConnection->accountId !== null && (int)$agentConnection->accountId === $accountId;
    }

    /**
     * HOST HOOK: an owner-facing text of a connection widget (button labels, reasons) in the application's words and
     * language; `%s` placeholders take `$values` in order. Default: the English text.
     */
    public function getOwnerFacingText(string $textKey, string $englishText, string ...$values): string
    {
        return $values === [] ? $englishText : vsprintf($englishText, $values);
    }

    /**
     * HOST HOOK: how the readers of a conversation that {@see isConversationReadByOthers()} answered true for are
     * named to the model ("the shared team space, which all admins can read"). Default: a shared space.
     */
    public function describeConversationAudience(int $conversationId): string
    {
        return 'a shared space that other people can read';
    }

    /**
     * HOST HOOK (audience rule): whether people other than the conversation's owner read the conversation (a space
     * whose conversations its members read). There a connection is used only through a release to the conversation's
     * space; the owner's own access does not count. Default: true (fail closed): a host answers for its spaces.
     */
    public function isConversationReadByOthers(int $aIConversationId, AgentConnection $agentConnection): bool
    {
        return true;
    }

    /** Whether others read the conversation: a shared chat thread of a connection, or the host's answer */
    public function isConversationSharedWithOthers(int $aIConversationId, AgentConnection $agentConnection): bool
    {
        return $this->isSharedChatThread($aIConversationId) || $this->isConversationReadByOthers($aIConversationId, $agentConnection);
    }

    /** Whether the conversation is a thread in a chat channel that others read (not a direct message) */
    public function isSharedChatThread(int $aIConversationId): bool
    {
        if (!AgentConnectionsConfiguration::current()->isEnabled()) {
            return false;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionThreads::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->select("COUNT($alias.id)")
                ->andWhere("$alias.aiConversationId = :conversationId")->setParameter('conversationId', $aIConversationId)
                ->andWhere("$alias.readByOwnerOnly = :sharedThread")->setParameter('sharedThread', false);
            return (int)$queryBuilder->getQuery()->getSingleScalarResult() > 0;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** HOST HOOK. Whether `$accountId` is a member of the space: the ONLY gate of a release. Default: no (fail closed). */
    public function isAccountMemberOfAgentSpace(int $accountId, int $agentSpaceId): bool
    {
        return false;
    }

    /**
     * The connection a tool call names: an id, its display name (case-insensitive) or its external address; without
     * a reference the default of the capability, else the only one that offers it.
     *
     * @throws AgentConnectionRefusedException none, unknown, or several and none named
     */
    public function resolveConnectionForCapability(AgentConnections $usableConnections, string $capability, ?string $connectionReference): AgentConnection
    {
        $offeringConnections = [];
        foreach ($usableConnections->getElements() as $agentConnection) {
            foreach ($this->findPermissionsOfConnection($agentConnection)->getElements() as $agentConnectionPermission) {
                if ($agentConnectionPermission->capability === $capability && $agentConnectionPermission->level !== AgentConnectionPermission::LEVEL_OFF) {
                    $offeringConnections[] = $agentConnection;
                    break;
                }
            }
        }
        if ($offeringConnections === []) {
            throw new AgentConnectionRefusedException("No connection with the capability $capability is available here. The account connects one in its connection settings.");
        }
        $connectionReference = $connectionReference !== null ? trim($connectionReference) : '';
        if ($connectionReference !== '') {
            foreach ($offeringConnections as $agentConnection) {
                if ((string)$agentConnection->id === $connectionReference) {
                    return $agentConnection;
                }
            }
            $matchingConnections = [];
            foreach ($offeringConnections as $agentConnection) {
                if (mb_strtolower((string)$agentConnection->displayName) === mb_strtolower($connectionReference)
                    || mb_strtolower((string)$agentConnection->externalAddress) === mb_strtolower($connectionReference)) {
                    $matchingConnections[] = $agentConnection;
                }
            }
            if (count($matchingConnections) === 1) {
                return $matchingConnections[0];
            }
            if (count($matchingConnections) > 1) {
                throw new AgentConnectionRefusedException("Several $capability connections are named \"$connectionReference\"; name one by its id: " . static::describeConnectionChoices(...$matchingConnections) . '.');
            }
            throw new AgentConnectionRefusedException("No $capability connection is named \"$connectionReference\". Available: " . static::describeConnectionChoices(...$offeringConnections) . '.');
        }
        if (count($offeringConnections) === 1) {
            return $offeringConnections[0];
        }
        foreach ($offeringConnections as $agentConnection) {
            if ($agentConnection->isDefaultForItsCapabilities) {
                return $agentConnection;
            }
        }
        throw new AgentConnectionRefusedException("Several $capability connections are available; name one with `connection`: " . static::describeConnectionChoices(...$offeringConnections) . '.');
    }

    public static function describeConnectionChoices(AgentConnection ...$agentConnections): string
    {
        $choices = [];
        foreach ($agentConnections as $agentConnection) {
            $choices[] = '"' . $agentConnection->getLabel() . '" (id ' . $agentConnection->id . ')';
        }
        return implode(', ', $choices);
    }

    /** A new connection row of this stage, IN_PROGRESS until its connect completes */
    public function createPendingConnection(string $ownership, string $provider, ?int $accountId, ?int $connectedByAccountId, ?string $displayName, ?int $agentSpaceId = null): AgentConnection
    {
        $agentConnection = AgentConnection::newInstance();
        $agentConnection->ownership = $ownership;
        $agentConnection->provider = $provider;
        $agentConnection->accountId = $ownership === AgentConnection::OWNERSHIP_AGENT_OWNED ? null : $accountId;
        $agentConnection->agentSpaceId = $agentSpaceId;
        $agentConnection->connectedByAccountId = $connectedByAccountId;
        $agentConnection->displayName = $displayName !== null && trim($displayName) !== '' ? mb_substr(trim($displayName), 0, 100) : null;
        $agentConnection->status = AgentConnection::STATUS_IN_PROGRESS;
        $agentConnection->environment = AgentConnectionsConfiguration::current()->getCurrentStage();
        $this->persistIgnoringRights($agentConnection);
        return $agentConnection;
    }

    /**
     * A capability the grant covers starts at `$initialLevel`, one it does not cover is OFF. Existing levels are kept
     * (a reconnect does not reset what the owner chose).
     *
     * @param string[] $coveredCapabilities
     */
    public function ensurePermissions(AgentConnection $agentConnection, array $coveredCapabilities, ?int $grantedByAccountId, string $initialLevel = AgentConnectionPermission::LEVEL_READ): void
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $existingCapabilities = [];
            foreach ($this->findPermissionsOfConnection($agentConnection)->getElements() as $agentConnectionPermission) {
                $existingCapabilities[$agentConnectionPermission->capability] = true;
                if (!in_array($agentConnectionPermission->capability, $coveredCapabilities, true) && $agentConnectionPermission->level !== AgentConnectionPermission::LEVEL_OFF) {
                    $agentConnectionPermission->level = AgentConnectionPermission::LEVEL_OFF;
                    $agentConnectionPermission->update();
                }
            }
            foreach ($coveredCapabilities as $capability) {
                if (isset($existingCapabilities[$capability])) {
                    continue;
                }
                $agentConnectionPermission = AgentConnectionPermission::newInstance();
                $agentConnectionPermission->agentConnectionId = (int)$agentConnection->id;
                $agentConnectionPermission->capability = $capability;
                $agentConnectionPermission->level = $initialLevel;
                $agentConnectionPermission->grantedByAccountId = $grantedByAccountId;
                $agentConnectionPermission->update();
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * Sets the level of one capability; never above what the grant covers (a capability without a row stays OFF).
     *
     * @throws AgentConnectionRefusedException
     */
    public function setLevel(AgentConnection $agentConnection, string $capability, string $level, int $changedByAccountId): void
    {
        $this->invalidateReadCache($agentConnection);
        if (!in_array($level, AgentConnectionPermission::LEVELS, true)) {
            throw new AgentConnectionRefusedException('Unknown level.');
        }
        $missingScopes = $this->findMissingScopes($agentConnection, $capability, $level);
        if ($missingScopes !== []) {
            // incremental consent: the level takes effect only after the provider granted its additional scopes
            throw new AgentConnectionConsentRequiredException($capability, $level, $missingScopes);
        }
        foreach ($this->findPermissionsOfConnection($agentConnection)->getElements() as $agentConnectionPermission) {
            if ($agentConnectionPermission->capability !== $capability) {
                continue;
            }
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $agentConnectionPermission->level = $level;
                $agentConnectionPermission->grantedByAccountId = $changedByAccountId;
                $agentConnectionPermission->update();
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
            /** @var AgentConnectionActionsService $agentConnectionActionsService */
            $agentConnectionActionsService = DDDService::instance()->getService(AgentConnectionActionsService::class);
            $agentConnectionActionsService->recordAdministration($agentConnection, AgentConnectionAction::OPERATION_SETTINGS, $changedByAccountId, "level $capability $level");
            return;
        }
        throw new AgentConnectionRefusedException("The connection does not offer $capability; connect it again with that permission.");
    }

    /**
     * The scopes the provider has not granted yet for `$capability` at `$level`; empty for a provider without a
     * consent screen or when the grant covers the level.
     *
     * @return string[]
     */
    public function findMissingScopes(AgentConnection $agentConnection, string $capability, string $level): array
    {
        $oAuthAdapter = $this->findOAuthAdapterOfConnection($agentConnection);
        return $oAuthAdapter === null ? [] : $oAuthAdapter->findMissingScopes($agentConnection->getGrantedScopeList(), $capability, $level);
    }

    /** The highest level of `$capability` the provider's grant covers (OFF when not even READ) */
    public function findHighestGrantedLevel(AgentConnection $agentConnection, string $capability): string
    {
        $highestGrantedLevel = AgentConnectionPermission::LEVEL_OFF;
        foreach (AgentConnectionPermission::LEVELS as $level) {
            if ($level !== AgentConnectionPermission::LEVEL_OFF && $this->findMissingScopes($agentConnection, $capability, $level) === []) {
                $highestGrantedLevel = $level;
            }
        }
        return $highestGrantedLevel;
    }

    protected function findOAuthAdapterOfConnection(AgentConnection $agentConnection): ?OAuthAgentConnectionAdapter
    {
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $agentConnectionAdapter = $agentConnectionAdaptersService->getAdapter((string)$agentConnection->provider);
        return $agentConnectionAdapter instanceof OAuthAgentConnectionAdapter ? $agentConnectionAdapter : null;
    }

    public function findPermissionsOfConnection(AgentConnection $agentConnection): AgentConnectionPermissions
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionPermissions::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', (int)$agentConnection->id);
            return $repository->find($queryBuilder, false) ?? AgentConnectionPermissions::newInstance();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * Counts a provider error atomically (no full-row write: a running turn never turns a revoked or paused
     * connection back on, and parallel errors are all counted); pauses a CONNECTED connection at the limit.
     */
    public function recordProviderError(AgentConnection $agentConnection, string $errorText): void
    {
        $now = new DateTime();
        $this->executeConnectionUpdate(
            'SET agentConnection.consecutiveErrorCount = agentConnection.consecutiveErrorCount + 1, agentConnection.lastErrorText = :lastErrorText, agentConnection.lastErrorAt = :now WHERE agentConnection.id = :agentConnectionId',
            ['lastErrorText' => mb_substr($errorText, 0, 500), 'now' => $now, 'agentConnectionId' => (int)$agentConnection->id]
        );
        $pausedRows = $this->executeConnectionUpdate(
            'SET agentConnection.status = :pausedStatus WHERE agentConnection.id = :agentConnectionId AND agentConnection.status = :connectedStatus AND agentConnection.consecutiveErrorCount >= :pauseLimit',
            ['pausedStatus' => AgentConnection::STATUS_PAUSED, 'connectedStatus' => AgentConnection::STATUS_CONNECTED, 'agentConnectionId' => (int)$agentConnection->id,
                'pauseLimit' => AgentConnectionsConfiguration::current()->getLimit('pauseAfterConsecutiveErrors', 5)]
        );
        $agentConnection->consecutiveErrorCount++;
        $agentConnection->lastErrorText = mb_substr($errorText, 0, 500);
        $agentConnection->lastErrorAt = $now;
        if ($pausedRows > 0) {
            $agentConnection->status = AgentConnection::STATUS_PAUSED;
            $this->invalidateReadCache($agentConnection);
        }
    }

    /** Resets the error count and stamps the last use (at most once a minute); never touches the status */
    public function recordProviderSuccess(AgentConnection $agentConnection): void
    {
        $oneMinuteAgo = (new DateTime())->modify('-60 seconds');
        if ($agentConnection->consecutiveErrorCount === 0 && $agentConnection->lastUsedAt !== null && $agentConnection->lastUsedAt > $oneMinuteAgo) {
            return;
        }
        $now = new DateTime();
        $this->executeConnectionUpdate(
            'SET agentConnection.consecutiveErrorCount = 0, agentConnection.lastUsedAt = :now WHERE agentConnection.id = :agentConnectionId',
            ['now' => $now, 'agentConnectionId' => (int)$agentConnection->id]
        );
        $agentConnection->consecutiveErrorCount = 0;
        $agentConnection->lastUsedAt = $now;
    }

    /** A refused grant: CONNECTED or PAUSED becomes EXPIRED (a revoked connection stays revoked) */
    public function markExpired(AgentConnection $agentConnection, string $reason): void
    {
        $this->invalidateReadCache($agentConnection);
        $now = new DateTime();
        $expiredRows = $this->executeConnectionUpdate(
            'SET agentConnection.status = :expiredStatus, agentConnection.lastErrorText = :lastErrorText, agentConnection.lastErrorAt = :now'
            . ' WHERE agentConnection.id = :agentConnectionId AND agentConnection.status IN (:liveStatuses)',
            ['expiredStatus' => AgentConnection::STATUS_EXPIRED, 'lastErrorText' => mb_substr($reason, 0, 500), 'now' => $now,
                'agentConnectionId' => (int)$agentConnection->id, 'liveStatuses' => [AgentConnection::STATUS_CONNECTED, AgentConnection::STATUS_PAUSED]]
        );
        if ($expiredRows > 0) {
            $agentConnection->status = AgentConnection::STATUS_EXPIRED;
        }
        $agentConnection->lastErrorText = mb_substr($reason, 0, 500);
        $agentConnection->lastErrorAt = $now;
    }

    /** One DQL UPDATE of the connection table; returns the changed row count */
    protected function executeConnectionUpdate(string $setAndWhereClause, array $parameters): int
    {
        $updateQuery = EntityManagerFactory::getInstance()->createQuery('UPDATE ' . DBAgentConnectionModel::class . ' agentConnection ' . $setAndWhereClause);
        $updateQuery->setParameters($parameters);
        return (int)$updateQuery->execute();
    }

    /**
     * Disconnect: revokes the grant at the provider (a failure there is recorded, the local end happens anyway),
     * deletes the credentials, revokes every release; the row and the audit stay.
     */
    public function disconnect(AgentConnection $agentConnection, int $disconnectedByAccountId): void
    {
        $this->invalidateReadCache($agentConnection);
        /** @var AgentConnectionCredentialSetsService $agentConnectionCredentialSetsService */
        $agentConnectionCredentialSetsService = DDDService::instance()->getService(AgentConnectionCredentialSetsService::class);
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $revokeNote = 'disconnected';
        try {
            $agentConnectionAdapter = $agentConnectionAdaptersService->getAdapterOfConnection($agentConnection);
            if ($agentConnectionAdapter instanceof Adapters\OAuthAgentConnectionAdapter) {
                $agentConnectionAdapter->revokeGrant($agentConnectionCredentialSetsService->readSecrets($agentConnection));
                $revokeNote .= ', grant revoked at the provider';
            }
        } catch (\Throwable $revokeFailure) {
            $revokeNote .= ', revoke at the provider failed: ' . mb_substr($revokeFailure->getMessage(), 0, 200);
        }
        $agentConnectionCredentialSetsService->deleteSecrets($agentConnection);
        $this->revokeReleases($agentConnection, $disconnectedByAccountId, null);
        $agentConnection->status = AgentConnection::STATUS_REVOKED;
        $this->persistIgnoringRights($agentConnection);
        /** @var AgentConnectionActionsService $agentConnectionActionsService */
        $agentConnectionActionsService = DDDService::instance()->getService(AgentConnectionActionsService::class);
        $agentConnectionActionsService->recordAdministration($agentConnection, AgentConnectionAction::OPERATION_DISCONNECT, $disconnectedByAccountId, $revokeNote);
    }

    /** Every change of a connection makes earlier cached reads unreachable ({@see AgentConnectionReadCacheService}) */
    protected function invalidateReadCache(AgentConnection $agentConnection): void
    {
        if (!isset($agentConnection->id)) {
            return;
        }
        /** @var AgentConnectionReadCacheService $agentConnectionReadCacheService */
        $agentConnectionReadCacheService = DDDService::instance()->getService(AgentConnectionReadCacheService::class);
        $agentConnectionReadCacheService->invalidateConnection($agentConnection);
    }

    public function persistIgnoringRights(AgentConnection $agentConnection): void
    {
        $this->invalidateReadCache($agentConnection);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnection->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** @return string[] the external addresses (channels, mailboxes) configured on the connection */
    public function findConfiguredAddressesOfConnection(AgentConnection $agentConnection): array
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionAddresses::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', (int)$agentConnection->id);
            $agentConnectionAddresses = $repository->find($queryBuilder, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $externalAddresses = [];
        foreach ($agentConnectionAddresses?->getElements() ?? [] as $agentConnectionAddress) {
            $externalAddresses[] = (string)$agentConnectionAddress->externalAddress;
        }
        return $externalAddresses;
    }

    /** Writes only the named columns of an existing connection (settings changed by a person while turns run) */
    public function persistPropertiesIgnoringRights(AgentConnection $agentConnection, string ...$propertyNames): void
    {
        $this->invalidateReadCache($agentConnection);
        $agentConnection->updatePartialIgnoringRights(...$propertyNames);
    }

    /** A connection by id, without rights (the caller decides access through {@see getAccessOfAccount()}) */
    public function findIgnoringRights(int $agentConnectionId): ?AgentConnection
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnection = $this->find($agentConnectionId, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $agentConnection instanceof AgentConnection ? $agentConnection : null;
    }

    /** The connections of this stage, optionally of one owner account or only the system connections; newest first */
    public function findOfStage(?int $ownerAccountId = null, bool $onlySystem = false, ?string $provider = null): AgentConnections
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnections::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.environment = :currentStage")->setParameter('currentStage', AgentConnectionsConfiguration::current()->getCurrentStage())
                ->andWhere("$alias.status <> :revoked")->setParameter('revoked', AgentConnection::STATUS_REVOKED)
                ->orderBy("$alias.id", 'DESC');
            if ($ownerAccountId !== null) {
                $queryBuilder->andWhere("$alias.accountId = :ownerAccountId")->setParameter('ownerAccountId', $ownerAccountId);
            }
            if ($onlySystem) {
                $queryBuilder->andWhere("$alias.ownership = :agentOwned")->setParameter('agentOwned', AgentConnection::OWNERSHIP_AGENT_OWNED);
            }
            if ($provider !== null) {
                $queryBuilder->andWhere("$alias.provider = :provider")->setParameter('provider', $provider);
            }
            return $repository->find($queryBuilder, false) ?? AgentConnections::newInstance();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

}
