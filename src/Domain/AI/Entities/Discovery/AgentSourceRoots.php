<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Discovery;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The source roots of the agent core's folder conventions, in order: the application first, then every installed DDD
 * module in module discovery order — each directory once.
 *
 * @property AgentSourceRoot[] $elements
 * @method AgentSourceRoot[] getElements()
 * @method AgentSourceRoot|null first()
 */
class AgentSourceRoots extends ObjectSet
{
    /** Adds `$agentSourceRoot` unless null or its directory is already a root. */
    public function addSourceRootOnce(?AgentSourceRoot $agentSourceRoot): void
    {
        if ($agentSourceRoot === null || $this->getByUniqueKey($agentSourceRoot->uniqueKey()) !== null) {
            return;
        }
        $this->add($agentSourceRoot);
    }

    /** The root that contains `$filePath` (the deepest one when roots nest); null when none does. */
    public function findSourceRootContainingFilePath(string $filePath): ?AgentSourceRoot
    {
        $containingSourceRoot = null;
        foreach ($this->getElements() as $agentSourceRoot) {
            if ($agentSourceRoot->containsFilePath($filePath)
                && ($containingSourceRoot === null || strlen($agentSourceRoot->directory) > strlen($containingSourceRoot->directory))) {
                $containingSourceRoot = $agentSourceRoot;
            }
        }
        return $containingSourceRoot;
    }

    /** The class name of a PHP file below one of the roots, by PSR-4; null outside them. */
    public function findClassNameForFilePath(string $classFilePath): ?string
    {
        return $this->findSourceRootContainingFilePath($classFilePath)?->findClassNameForFilePath($classFilePath);
    }

    /**
     * Every `Domain/<Domain>/<relativeFolder>` that exists below any root, sorted.
     *
     * @return string[]
     */
    public function findDomainFolders(string $relativeFolder): array
    {
        $domainFolders = [];
        foreach ($this->getElements() as $agentSourceRoot) {
            array_push($domainFolders, ...(glob($agentSourceRoot->directory . '/Domain/*/' . $relativeFolder, GLOB_ONLYDIR) ?: []));
        }
        $domainFolders = array_values(array_unique($domainFolders));
        sort($domainFolders);
        return $domainFolders;
    }
}
