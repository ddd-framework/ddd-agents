<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundary;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveChanges;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItems;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\DriveAgentConnectionAdapter;

/**
 * The roots of a drive connection, enforced on every operation for every release way (the agent's own identity
 * included): list, search, get, download, the change feed. The roots of one reach are the FOLDER boundaries of the
 * connection and of the release it is reached through ({@see AgentConnectionAccess::getAllowedBoundaryValues()}); no
 * root = nothing is visible (never the whole drive).
 *
 * An item is inside when a walk up its parents reaches a root (a provider's "in parents" matches direct parents
 * only). Search runs over the token's corpus and keeps a hit only after that walk, paging on until the page is full.
 */
class AgentConnectionDriveScopeService
{
    public const string BOUNDARY_KIND_FOLDER = AgentConnectionScopeBoundary::KIND_FOLDER;

    /** @var int a walk never climbs further (a cycle or a very deep tree is refused) */
    public const int MAXIMUM_ANCESTRY_DEPTH = 40;

    public const int MAXIMUM_SEARCH_PAGES = 10;

    /** @var array<string, bool> per access fingerprint and item id: inside the roots */
    protected array $insideByKey = [];

    /** @return string[] the roots of this reach; empty = nothing visible */
    public function findRootIds(AgentConnection $agentConnection, AgentConnectionAccess $agentConnectionAccess): array
    {
        return $agentConnectionAccess->getAllowedBoundaryValues($agentConnection, static::BOUNDARY_KIND_FOLDER) ?? [];
    }

    /** @throws AgentConnectionRefusedException */
    public function requireRootIds(AgentConnection $agentConnection, AgentConnectionAccess $agentConnectionAccess): array
    {
        $rootIds = $this->findRootIds($agentConnection, $agentConnectionAccess);
        if ($rootIds === []) {
            throw new AgentConnectionRefusedException(sprintf('No folder of "%s" is released here; a person who manages the connection adds one as a root.', $agentConnection->getLabel()));
        }
        return $rootIds;
    }

    public function isInsideRoots(DriveAgentConnectionAdapter $driveAdapter, AgentConnection $agentConnection, AgentConnectionAccess $agentConnectionAccess, AgentConnectionDriveItem $driveItem): bool
    {
        $rootIds = $this->findRootIds($agentConnection, $agentConnectionAccess);
        if ($rootIds === []) {
            return false;
        }
        $fingerprint = $agentConnectionAccess->fingerprint($agentConnection);
        $visitedIds = [];
        $currentItem = $driveItem;
        for ($depth = 0; $depth < static::MAXIMUM_ANCESTRY_DEPTH; $depth++) {
            $cacheKey = $fingerprint . ':' . $currentItem->itemId;
            if (isset($this->insideByKey[$cacheKey])) {
                return $this->remember($fingerprint, $visitedIds, $this->insideByKey[$cacheKey]);
            }
            if (in_array($currentItem->itemId, $rootIds, true)) {
                return $this->remember($fingerprint, [...$visitedIds, $currentItem->itemId], true);
            }
            $visitedIds[] = $currentItem->itemId;
            $parentId = $currentItem->parentIds[0] ?? null;
            if ($parentId === null || in_array($parentId, $visitedIds, true)) {
                return $this->remember($fingerprint, $visitedIds, false);
            }
            if (in_array($parentId, $rootIds, true)) {
                return $this->remember($fingerprint, $visitedIds, true);
            }
            try {
                $currentItem = $driveAdapter->getDriveItem($parentId);
            } catch (AgentConnectionRefusedException) {
                // a parent the token cannot read (the top of a drive, a folder of someone else): the walk ends outside
                return $this->remember($fingerprint, $visitedIds, false);
            }
        }
        return $this->remember($fingerprint, $visitedIds, false);
    }

    /** @throws AgentConnectionRefusedException */
    public function requireItemInsideRoots(DriveAgentConnectionAdapter $driveAdapter, AgentConnection $agentConnection, AgentConnectionAccess $agentConnectionAccess, string $itemId): AgentConnectionDriveItem
    {
        $this->requireRootIds($agentConnection, $agentConnectionAccess);
        $driveItem = $driveAdapter->getDriveItem($itemId);
        if (!$this->isInsideRoots($driveAdapter, $agentConnection, $agentConnectionAccess, $driveItem)) {
            throw new AgentConnectionRefusedException(sprintf('The item %s lies outside the folders released for "%s".', $itemId, $agentConnection->getLabel()));
        }
        return $driveItem;
    }

    /** The children of a folder inside the roots (the folder itself must be inside) */
    public function listFolderInsideRoots(DriveAgentConnectionAdapter $driveAdapter, AgentConnection $agentConnection, AgentConnectionAccess $agentConnectionAccess, string $folderId, ?string $pageToken, int $pageSize): AgentConnectionDriveItems
    {
        $this->requireItemInsideRoots($driveAdapter, $agentConnection, $agentConnectionAccess, $folderId);
        return $driveAdapter->listFolderChildren($folderId, $pageToken, $pageSize);
    }

    /** A search over the corpus, hits kept only inside the roots, paging on until `$maximumResults` or the last page */
    public function searchInsideRoots(DriveAgentConnectionAdapter $driveAdapter, AgentConnection $agentConnection, AgentConnectionAccess $agentConnectionAccess, string $query, int $maximumResults): AgentConnectionDriveItems
    {
        $this->requireRootIds($agentConnection, $agentConnectionAccess);
        $insideItems = AgentConnectionDriveItems::newInstance();
        $pageToken = null;
        for ($pageNumber = 0; $pageNumber < static::MAXIMUM_SEARCH_PAGES; $pageNumber++) {
            $pageItems = $driveAdapter->searchDriveItems($query, $pageToken, 100);
            foreach ($pageItems->getElements() as $driveItem) {
                if ($this->isInsideRoots($driveAdapter, $agentConnection, $agentConnectionAccess, $driveItem)) {
                    $insideItems->add($driveItem);
                    if ($insideItems->count() >= $maximumResults) {
                        return $insideItems;
                    }
                }
            }
            $pageToken = $pageItems->nextPageToken;
            if ($pageToken === null) {
                break;
            }
        }
        return $insideItems;
    }

    /** The change feed narrowed to the roots: a changed item outside them counts as removed (moved out) */
    public function filterChangesToRoots(DriveAgentConnectionAdapter $driveAdapter, AgentConnection $agentConnection, AgentConnectionAccess $agentConnectionAccess, AgentConnectionDriveChanges $driveChanges): AgentConnectionDriveChanges
    {
        $filteredChanges = AgentConnectionDriveChanges::newInstance();
        $filteredChanges->removedItemIds = $driveChanges->removedItemIds;
        $filteredChanges->nextPageToken = $driveChanges->nextPageToken;
        $filteredChanges->newStartPageToken = $driveChanges->newStartPageToken;
        foreach ($driveChanges->changedItems->getElements() as $driveItem) {
            if ($this->isInsideRoots($driveAdapter, $agentConnection, $agentConnectionAccess, $driveItem)) {
                $filteredChanges->changedItems->add($driveItem);
                continue;
            }
            $filteredChanges->removedItemIds[] = $driveItem->itemId;
        }
        return $filteredChanges;
    }

    /** Forgets every walk (roots, releases or the tree changed) */
    public function forgetWalks(): void
    {
        $this->insideByKey = [];
    }

    /** @param string[] $itemIds */
    protected function remember(string $fingerprint, array $itemIds, bool $isInside): bool
    {
        if (count($this->insideByKey) > 5000) {
            $this->insideByKey = [];
        }
        foreach ($itemIds as $itemId) {
            $this->insideByKey[$fingerprint . ':' . $itemId] = $isInside;
        }
        return $isInside;
    }
}
