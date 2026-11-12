<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The GROUPS of a loaded capability set (formerly the catalog closure):
 * per loaded skill body, the loaded tools that belong to it and the loaded sub-skills under it — the catalog's edges
 * (the skill's tool names and `subSkillNames`) UNION the parent links recorded at load time. Catalog-derived on purpose:
 * the recorded parents depend on the load order (a root loaded after its sub-skill is never linked, a lazy `load_tool`
 * used to record one parent), so the group is always the catalog's truth, joined with what was recorded. A tool may
 * belong to several groups (a shared tool). Built from RESOLVED skills; resolves nothing itself.
 *
 * @phpstan-consistent-constructor
 */
class LoadedSkillGroups extends ValueObject
{
    /** @var LoadedSkillGroup[] skill name → its group, in the order of the loaded skills */
    protected array $groupsBySkillName = [];

    /** The groups of `$loadedCapabilities` with the catalog edges of the resolved loaded skills (an unknown skill has none). */
    public static function buildFor(LoadedCapabilities $loadedCapabilities, AISkills $loadedSkills): self
    {
        $catalogEdgesBySkillName = [];
        foreach ($loadedSkills->getElements() as $loadedSkill) {
            $catalogEdgesBySkillName[$loadedSkill->name] = LoadedSkillCatalogEdges::forSkill($loadedSkill);
        }
        return self::fromCatalogEdges($loadedCapabilities, $catalogEdgesBySkillName);
    }

    /**
     * The groups from given catalog edges (skill name → tools / sub-skills) — the pure core of {@see buildFor()}, also
     * what a container-less test builds from a map.
     *
     * @param LoadedCapabilities $loadedCapabilities
     * @param LoadedSkillCatalogEdges[] $catalogEdgesBySkillName skill name → its catalog edges
     * @return static
     */
    public static function fromCatalogEdges(LoadedCapabilities $loadedCapabilities, array $catalogEdgesBySkillName): static
    {
        $loadedSkillNames = $loadedCapabilities->getLoadedSkillNames();
        $loadedToolNames = $loadedCapabilities->getLoadedToolNames();
        $loadedSkillGroups = new static();
        foreach ($loadedSkillNames as $skillName) {
            $catalogEdges = $catalogEdgesBySkillName[$skillName] ?? LoadedSkillCatalogEdges::forToolAndSubSkillNames([], []);
            $loadedSkillGroups->groupsBySkillName[$skillName] = LoadedSkillGroup::create(
                (string)$skillName,
                array_values(array_intersect($catalogEdges->toolNames, $loadedToolNames)),
                array_values(array_intersect($catalogEdges->subSkillNames, $loadedSkillNames))
            );
        }
        // union with the RECORDED parent links (a tool a package no longer lists, a legacy row)
        foreach ($loadedCapabilities->getElements() as $capability) {
            foreach ($capability->parentSkillNames as $parentSkillName) {
                $parentGroup = $loadedSkillGroups->groupsBySkillName[$parentSkillName] ?? null;
                if ($parentGroup === null) {
                    continue;
                }
                if ($capability->type === LoadedCapability::TYPE_TOOL) {
                    if (!$parentGroup->hasToolName($capability->name)) {
                        $parentGroup->toolNames[] = $capability->name;
                    }
                } elseif (!$parentGroup->hasSubSkillName($capability->name)) {
                    $parentGroup->subSkillNames[] = $capability->name;
                }
            }
        }
        return $loadedSkillGroups;
    }

    /** @return LoadedSkillGroup[] skill name → its group, in the order of the loaded skills */
    public function getGroupsBySkillName(): array
    {
        return $this->groupsBySkillName;
    }

    /** @return string[] the loaded tools of the skill's group; none for a skill without a group */
    public function getToolNamesOfSkill(string $skillName): array
    {
        $loadedSkillGroup = $this->groupsBySkillName[$skillName] ?? null;
        return $loadedSkillGroup !== null ? $loadedSkillGroup->toolNames : [];
    }

    /** @return string[] the loaded sub-skills under the skill; none for a skill without a group */
    public function getSubSkillNamesOfSkill(string $skillName): array
    {
        $loadedSkillGroup = $this->groupsBySkillName[$skillName] ?? null;
        return $loadedSkillGroup !== null ? $loadedSkillGroup->subSkillNames : [];
    }

    /** @return string[] the skills whose group the loaded tool belongs to */
    public function getParentSkillNamesOfTool(string $toolName): array
    {
        $parentSkillNames = [];
        foreach ($this->groupsBySkillName as $skillName => $loadedSkillGroup) {
            if ($loadedSkillGroup->hasToolName($toolName)) {
                $parentSkillNames[] = (string)$skillName;
            }
        }
        return $parentSkillNames;
    }
}
