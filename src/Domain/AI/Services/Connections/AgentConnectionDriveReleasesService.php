<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundary;
use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundaries;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionDriveRoot;
use DDD\Domain\AI\Entities\Connections\AgentConnectionDriveRoots;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Listeners\Connections\AgentConnectionDriveRootsChangedListener;
use DDD\Domain\AI\Services\Connections\Adapters\DriveAgentConnectionAdapter;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * The four ways a drive item becomes a root of a drive connection, and taking it back:
 * - the agent's own identity: items shared with the identity arrive UNASSIGNED (visible to no one) until a holder of
 *   the system right assigns them ({@see discoverUnassignedItems()}, {@see assignRoot()});
 * - release to the agent: a person picks a folder or shared drive on their own connection; the backend shares it with
 *   the agent's identity with the person's token at the chosen role and adds it as a root of the agent's connection,
 *   only when the host allows publishing into the agent's space ({@see releaseToAgent()}, {@see revokeReleaseToAgent()});
 * - a person's own connection: the person picks roots in the browser; enforced by the application only (the token
 *   could read more) ({@see addRoot()} with WAY_PERSON_ROOT);
 * - a Picker selection on a separate Picker-only connection: the provider grants only the picked items (WAY_PICKER).
 * An ACTIVE root is a `FOLDER:` boundary line of the connection, written with a partial update; every change is
 * audited and announced to the {@see AgentConnectionDriveRootsChangedListener}s (the drive spaces, the live mounts).
 */
class AgentConnectionDriveReleasesService
{
    public const string BOUNDARY_KIND_FOLDER = AgentConnectionDriveScopeService::BOUNDARY_KIND_FOLDER;

    /** @var string[] the roles a release to the agent may grant (never organizer) */
    public const array RELEASE_ROLES = ['reader', 'commenter', 'writer', 'fileOrganizer'];

    /** @var iterable<AgentConnectionDriveRootsChangedListener> */
    protected iterable $driveRootsChangedListeners = [];

    /** @param iterable<AgentConnectionDriveRootsChangedListener> $driveRootsChangedListeners */
    #[Required]
    public function setDriveRootsChangedListeners(
        #[AutowireIterator(AgentConnectionDriveRootsChangedListener::TAG)]
        iterable $driveRootsChangedListeners
    ): void {
        $this->driveRootsChangedListeners = $driveRootsChangedListeners;
    }

    public function findRootsOfConnection(AgentConnection $agentConnection, bool $withRevoked = false): AgentConnectionDriveRoots
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AgentConnectionDriveRootsService $agentConnectionDriveRootsService */
            $agentConnectionDriveRootsService = DDDService::instance()->getService(AgentConnectionDriveRootsService::class);
            $repository = $agentConnectionDriveRootsService->getEntitySetRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', (int)$agentConnection->id);
            if (!$withRevoked) {
                $queryBuilder->andWhere("$alias.status <> :revokedStatus")->setParameter('revokedStatus', AgentConnectionDriveRoot::STATUS_REVOKED);
            }
            $queryBuilder->orderBy("$alias.id", 'ASC');
            return $repository->find($queryBuilder, false) ?? AgentConnectionDriveRoots::newInstance();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * Adds an item the connection's token can reach as an ACTIVE root (the caller checked that `$accountId` manages the
     * connection). Never the whole drive: the item must be a folder, a shared drive or a single file.
     *
     * @throws AgentConnectionRefusedException
     */
    public function addRoot(AgentConnection $agentConnection, string $itemId, int $accountId, string $releaseWay): AgentConnectionDriveRoot
    {
        $driveItem = $this->requireDriveAdapter($agentConnection)->getDriveItem($itemId);
        if ($driveItem->itemId === '' || in_array($driveItem->itemId, ['root', 'my-drive'], true)) {
            throw new AgentConnectionRefusedException('A whole drive is never a root: pick a folder, a shared drive or a file.');
        }
        $agentConnectionDriveRoot = $this->upsertRoot($agentConnection, $driveItem, $releaseWay, AgentConnectionDriveRoot::STATUS_ACTIVE, $accountId);
        $this->writeFolderBoundary($agentConnection, $itemId, true);
        $this->recordAndAnnounce($agentConnection, $accountId, sprintf('root added: %s "%s" (%s)', $itemId, $driveItem->name, $releaseWay));
        return $agentConnectionDriveRoot;
    }

    /** Takes a root away (ACTIVE or UNASSIGNED → REVOKED); the item itself is never touched */
    public function removeRoot(AgentConnection $agentConnection, string $itemId, int $accountId): void
    {
        $agentConnectionDriveRoot = $this->findRoot($agentConnection, $itemId);
        if ($agentConnectionDriveRoot !== null) {
            $agentConnectionDriveRoot->status = AgentConnectionDriveRoot::STATUS_REVOKED;
            $agentConnectionDriveRoot->revokedAt = new DateTime();
            $agentConnectionDriveRoot->revokedByAccountId = $accountId;
            $agentConnectionDriveRoot->updatePartialIgnoringRights('status', 'revokedAt', 'revokedByAccountId');
        }
        $this->writeFolderBoundary($agentConnection, $itemId, false);
        $this->recordAndAnnounce($agentConnection, $accountId, 'root removed: ' . $itemId);
    }

    /**
     * Release to the agent: `$sourceConnection` is the releasing person's own drive connection (its token creates the
     * share), `$agentIdentityConnection` the agent's own drive identity (a system connection). Refused when the person
     * does not own the source, the provider does not let them share the item, or the host does not allow them to
     * publish into the agent connection's space.
     *
     * @throws AgentConnectionRefusedException
     */
    public function releaseToAgent(AgentConnection $sourceConnection, string $itemId, AgentConnection $agentIdentityConnection, string $role, int $accountId): AgentConnectionDriveRoot
    {
        if ($sourceConnection->isAgentOwned() || (int)$sourceConnection->accountId !== $accountId) {
            throw new AgentConnectionRefusedException('Only the owner of the drive connection releases its items.');
        }
        if (!$agentIdentityConnection->isAgentOwned() || (string)$agentIdentityConnection->externalAddress === '') {
            throw new AgentConnectionRefusedException('The target must be the agent\'s own drive identity (a system connection with an address).');
        }
        if (!in_array($role, static::RELEASE_ROLES, true)) {
            throw new AgentConnectionRefusedException('Choose one of the roles: ' . implode(', ', static::RELEASE_ROLES) . '.');
        }
        $agentSpaceId = $agentIdentityConnection->agentSpaceId;
        if ($agentSpaceId === null || !$this->getAgentConnectionsService()->mayShareToAgentSpace($agentIdentityConnection, $accountId, (int)$agentSpaceId)) {
            throw new AgentConnectionRefusedException('Publishing into the agent\'s space is not allowed for this account.');
        }
        $sourceAdapter = $this->requireDriveAdapter($sourceConnection);
        $driveItem = $sourceAdapter->getDriveItem($itemId);
        if (!$driveItem->canShare && !$driveItem->canManageMembers) {
            throw new AgentConnectionRefusedException(sprintf('The provider does not let this account share "%s" (it needs the right to share; a shared drive needs the manager role).', $driveItem->name));
        }
        $sendResult = $sourceAdapter->shareDriveItem($itemId, (string)$agentIdentityConnection->externalAddress, $role);
        $agentConnectionDriveRoot = $this->upsertRoot($agentIdentityConnection, $driveItem, AgentConnectionDriveRoot::WAY_RELEASED_TO_AGENT, AgentConnectionDriveRoot::STATUS_ACTIVE, $accountId);
        $agentConnectionDriveRoot->providerRole = $role;
        $agentConnectionDriveRoot->sourceAgentConnectionId = (int)$sourceConnection->id;
        $agentConnectionDriveRoot->providerPermissionId = $sendResult->externalId;
        $agentConnectionDriveRoot->updatePartialIgnoringRights('providerRole', 'sourceAgentConnectionId', 'providerPermissionId');
        $this->writeFolderBoundary($agentIdentityConnection, $itemId, true);
        $this->recordAndAnnounce($agentIdentityConnection, $accountId, sprintf('released to the agent: %s "%s" as %s from connection #%d', $itemId, $driveItem->name, $role, (int)$sourceConnection->id));
        $this->getActionsService()->recordAdministration($sourceConnection, AgentConnectionAction::OPERATION_SETTINGS, $accountId, sprintf('shared %s with the agent\'s identity as %s', $itemId, $role));
        return $agentConnectionDriveRoot;
    }

    /**
     * Takes a release to the agent back: the share is deleted with the releasing person's token when that connection
     * is still usable, the root is removed either way. Allowed for the person who released it or a manager of system
     * connections (`$mayManageSystemConnections`, the host's answer).
     *
     * @throws AgentConnectionRefusedException
     */
    public function revokeReleaseToAgent(AgentConnectionDriveRoot $agentConnectionDriveRoot, int $accountId, bool $mayManageSystemConnections): string
    {
        if ((int)$agentConnectionDriveRoot->grantedByAccountId !== $accountId && !$mayManageSystemConnections) {
            throw new AgentConnectionRefusedException('Only the person who released it or a manager of the agent\'s connections takes it back.');
        }
        $agentConnectionsService = $this->getAgentConnectionsService();
        $agentIdentityConnection = $agentConnectionsService->findIgnoringRights((int)$agentConnectionDriveRoot->agentConnectionId);
        if (!$agentIdentityConnection instanceof AgentConnection) {
            throw new AgentConnectionRefusedException('The connection of this root no longer exists.');
        }
        $outcome = 'root removed';
        $sourceConnection = $agentConnectionDriveRoot->sourceAgentConnectionId !== null ? $agentConnectionsService->findIgnoringRights((int)$agentConnectionDriveRoot->sourceAgentConnectionId) : null;
        if ($sourceConnection instanceof AgentConnection && $sourceConnection->isUsable() && (string)$agentConnectionDriveRoot->providerPermissionId !== '') {
            try {
                $this->requireDriveAdapter($sourceConnection)->unshareDriveItem((string)$agentConnectionDriveRoot->itemId, (string)$agentConnectionDriveRoot->providerPermissionId);
                $outcome = 'share deleted and root removed';
            } catch (Throwable $unshareFailure) {
                // the root goes regardless (fail closed on our side); the remaining share is reported, not hidden
                $outcome = 'root removed; the share at the provider could not be deleted: ' . mb_substr($unshareFailure->getMessage(), 0, 200);
            }
        } else {
            $outcome = 'root removed; the releasing connection is gone or unusable, so the share stays at the provider until its owner removes it';
        }
        $this->removeRoot($agentIdentityConnection, (string)$agentConnectionDriveRoot->itemId, $accountId);
        return $outcome;
    }

    /** Assigns an UNASSIGNED item of the agent's identity as an ACTIVE root (the caller checked the system right) */
    public function assignRoot(AgentConnection $agentConnection, string $itemId, int $accountId): AgentConnectionDriveRoot
    {
        $agentConnectionDriveRoot = $this->findRoot($agentConnection, $itemId);
        if ($agentConnectionDriveRoot === null || $agentConnectionDriveRoot->status !== AgentConnectionDriveRoot::STATUS_UNASSIGNED) {
            throw new AgentConnectionRefusedException('This item is no unassigned share of the agent\'s identity.');
        }
        $agentConnectionDriveRoot->status = AgentConnectionDriveRoot::STATUS_ACTIVE;
        $agentConnectionDriveRoot->grantedByAccountId = $accountId;
        $agentConnectionDriveRoot->updatePartialIgnoringRights('status', 'grantedByAccountId');
        $this->writeFolderBoundary($agentConnection, $itemId, true);
        $this->recordAndAnnounce($agentConnection, $accountId, 'unassigned item assigned as root: ' . $itemId);
        return $agentConnectionDriveRoot;
    }

    /**
     * The agent's own identity: items shared with it that are no root yet are stored UNASSIGNED (visible to no one).
     * For the sweep and the system screen. Answers how many new ones were found.
     */
    public function discoverUnassignedItems(AgentConnection $agentIdentityConnection): int
    {
        if (!$agentIdentityConnection->isAgentOwned() || !$agentIdentityConnection->isUsable()) {
            return 0;
        }
        $driveAdapter = $this->requireDriveAdapter($agentIdentityConnection);
        $knownItemIds = [];
        foreach ($this->findRootsOfConnection($agentIdentityConnection, true)->getElements() as $agentConnectionDriveRoot) {
            $knownItemIds[(string)$agentConnectionDriveRoot->itemId] = true;
        }
        $newCount = 0;
        $pageToken = null;
        for ($pageNumber = 0; $pageNumber < 10; $pageNumber++) {
            $sharedItems = $driveAdapter->listSharedWithMe($pageToken, 100);
            foreach ($sharedItems->getElements() as $driveItem) {
                if (isset($knownItemIds[$driveItem->itemId])) {
                    continue;
                }
                $this->upsertRoot($agentIdentityConnection, $driveItem, AgentConnectionDriveRoot::WAY_OWN_IDENTITY, AgentConnectionDriveRoot::STATUS_UNASSIGNED, null);
                $knownItemIds[$driveItem->itemId] = true;
                $newCount++;
            }
            $pageToken = $sharedItems->nextPageToken;
            if ($pageToken === null) {
                break;
            }
        }
        return $newCount;
    }

    public function findRoot(AgentConnection $agentConnection, string $itemId): ?AgentConnectionDriveRoot
    {
        foreach ($this->findRootsOfConnection($agentConnection, true)->getElements() as $agentConnectionDriveRoot) {
            if ((string)$agentConnectionDriveRoot->itemId === $itemId) {
                return $agentConnectionDriveRoot;
            }
        }
        return null;
    }

    protected function upsertRoot(AgentConnection $agentConnection, AgentConnectionDriveItem $driveItem, string $releaseWay, string $status, ?int $accountId): AgentConnectionDriveRoot
    {
        $agentConnectionDriveRoot = $this->findRoot($agentConnection, $driveItem->itemId) ?? AgentConnectionDriveRoot::newInstance();
        $agentConnectionDriveRoot->agentConnectionId = (int)$agentConnection->id;
        $agentConnectionDriveRoot->itemId = $driveItem->itemId;
        $agentConnectionDriveRoot->itemName = mb_substr($driveItem->name, 0, 255);
        $agentConnectionDriveRoot->releaseWay = $releaseWay;
        $agentConnectionDriveRoot->status = $status;
        $agentConnectionDriveRoot->grantedByAccountId = $accountId;
        $agentConnectionDriveRoot->revokedAt = null;
        $agentConnectionDriveRoot->revokedByAccountId = null;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AgentConnectionDriveRoot $storedRoot */
            $storedRoot = $agentConnectionDriveRoot->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $storedRoot;
    }

    /** Adds or removes one folder boundary of the connection (partial update: other code writes the row too) */
    protected function writeFolderBoundary(AgentConnection $agentConnection, string $itemId, bool $isPresent): void
    {
        $scopeBoundaries = ($agentConnection->scopeBoundaries ?? null) ?? new AgentConnectionScopeBoundaries();
        $agentConnection->scopeBoundaries = $scopeBoundaries->withBoundary(AgentConnectionScopeBoundary::KIND_FOLDER, $itemId, $isPresent);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnection->updatePartialIgnoringRights('scopeBoundaries');
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    protected function recordAndAnnounce(AgentConnection $agentConnection, ?int $accountId, string $outcomeText): void
    {
        $this->getActionsService()->recordAdministration($agentConnection, AgentConnectionAction::OPERATION_SETTINGS, $accountId, $outcomeText);
        /** @var AgentConnectionDriveScopeService $agentConnectionDriveScopeService */
        $agentConnectionDriveScopeService = DDDService::instance()->getService(AgentConnectionDriveScopeService::class);
        $agentConnectionDriveScopeService->forgetWalks();
        foreach ($this->driveRootsChangedListeners as $driveRootsChangedListener) {
            try {
                $driveRootsChangedListener->afterDriveRootsChanged($agentConnection);
            } catch (Throwable $listenerFailure) {
                // every access is checked against the connection again; the failed rebuild is logged, never hidden
                DDDService::instance()->getLogger()->warning('[AgentConnectionDriveRoots] listener failed', [
                    'listener' => $driveRootsChangedListener::class,
                    'agentConnectionId' => $agentConnection->id ?? null,
                    'reason' => $listenerFailure::class . ': ' . mb_substr($listenerFailure->getMessage(), 0, 500),
                ]);
            }
        }
    }

    /** @throws AgentConnectionRefusedException */
    protected function requireDriveAdapter(AgentConnection $agentConnection): DriveAgentConnectionAdapter
    {
        $hasFilesCapability = false;
        foreach ($this->getAgentConnectionsService()->findPermissionsOfConnection($agentConnection)->getElements() as $agentConnectionPermission) {
            $hasFilesCapability = $hasFilesCapability || ($agentConnectionPermission->capability === AgentConnectionPermission::CAPABILITY_FILES && $agentConnectionPermission->level !== AgentConnectionPermission::LEVEL_OFF);
        }
        if (!$hasFilesCapability) {
            throw new AgentConnectionRefusedException("The connection \"{$agentConnection->getLabel()}\" has no drive access.");
        }
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $adapter = $agentConnectionAdaptersService->getAdapterOfConnection($agentConnection);
        if (!$adapter instanceof DriveAgentConnectionAdapter) {
            throw new AgentConnectionRefusedException("The connection \"{$agentConnection->getLabel()}\" is no drive.");
        }
        return $adapter;
    }

    protected function getAgentConnectionsService(): AgentConnectionsService
    {
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        return $agentConnectionsService;
    }

    protected function getActionsService(): AgentConnectionActionsService
    {
        /** @var AgentConnectionActionsService $agentConnectionActionsService */
        $agentConnectionActionsService = DDDService::instance()->getService(AgentConnectionActionsService::class);
        return $agentConnectionActionsService;
    }
}
