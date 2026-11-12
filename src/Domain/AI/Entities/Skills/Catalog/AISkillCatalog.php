<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The whole MCP skill-catalog token map: catalog-wide totals + the Skill → Package → Tool tree (heaviest first).
 * The single typed object returned by {@see \DDD\Domain\AI\Services\Skills\AISkillCatalogDocService::buildSkillCatalog()}
 * — consumed identically by the admin endpoint and a console command of the application. Token counts are GPT-3 BPE
 * tokens (exact for GPT-family models, indicative for others).
 *
 * `skills` holds EVERY skill; each entry says whether it is purpose-bound ({@see AISkillCatalogEntry::$isPurposeBound}).
 * The purpose-bound ones are grouped per purpose in `purposeGroups` — a fresh conversation (purpose null) is never
 * offered them ({@see \DDD\Domain\AI\Entities\Skills\AISkill::isOfferedUnderPurposeName()}). The GENERAL skills are grouped
 * by namespace in `namespaceGroups` (Common, Agent, the host's product domains — {@see groupGeneralSkillsByNamespace()}).
 *
 * @phpstan-consistent-constructor
 */
class AISkillCatalog extends ValueObject
{
    /** @var AISkillCatalogTotals Catalog-wide totals. */
    public AISkillCatalogTotals $totals;

    /** @var AISkillCatalogEntry[] The skills (general and purpose-bound), biggest total first. */
    public array $skills = [];

    /** @var AISkillCatalogPurposeGroup[] One group per purpose that purpose-bound skills are offered under, by purpose name. */
    public array $purposeGroups = [];

    /**
     * @var AISkillCatalogNamespaceGroup[] The general skills grouped by namespace, config order; an "Ungrouped" group
     * last, only when a general skill matches no configured namespace.
     */
    public array $namespaceGroups = [];

    /**
     * @param AISkillCatalogEntry[] $skills
     * @param AISkillCatalogPurposeGroup[] $purposeGroups
     */
    public static function make(AISkillCatalogTotals $totals, array $skills, array $purposeGroups = []): static
    {
        $catalog = new static();
        $catalog->totals = $totals;
        $catalog->skills = $skills;
        $catalog->purposeGroups = $purposeGroups;
        return $catalog;
    }

    /**
     * The purpose names the skills declare a binding for (own `onlyUnderPurposeNames`, sorted) — the purposes whose
     * definitions {@see separatePurposeBoundSkills()} needs. A sub-skill bound through its roots adds no new name.
     *
     * @return string[]
     */
    public function getDeclaredPurposeNames(): array
    {
        $purposeNames = [];
        foreach ($this->skills as $entry) {
            $purposeNames = array_merge($purposeNames, $entry->onlyUnderPurposeNames);
        }
        $purposeNames = array_values(array_unique($purposeNames));
        sort($purposeNames);
        return $purposeNames;
    }

    /**
     * Separates the purpose-bound skills from the general ones: resolves every entry's binding, builds one group per
     * purpose and fills the split totals. Admin view only — the agent's offer is decided by
     * {@see \DDD\Domain\AI\Entities\Skills\AISkill::isOfferedUnderPurposeName()}, which this mirrors for purpose null.
     *
     * @param array<string, AIConversationPurpose|null> $purposesByName the purpose definitions by name (null = no file)
     */
    public function separatePurposeBoundSkills(array $purposesByName): void
    {
        $this->resolvePurposeBindings();
        $this->purposeGroups = $this->buildPurposeGroups($purposesByName);
        $this->totals->applyPurposeSplit($this->skills, count($this->purposeGroups));
    }

    /**
     * Groups the GENERAL skills by namespace ({@see AISkillNamespaceGroupDefinitions}): a root skill (or a sub-skill no
     * general root lists) by its longest matching name prefix, a sub-skill with the groups of its general roots (listed
     * under each when they differ). A general skill no prefix matches lands in the explicit "Ungrouped" group.
     * Purpose-bound skills stay out — call {@see separatePurposeBoundSkills()} first. Admin view only.
     */
    public function groupGeneralSkillsByNamespace(AISkillNamespaceGroupDefinitions $aISkillNamespaceGroupDefinitions): void
    {
        /** @var array<string, string[]> $generalRootGroupNamesBySubSkillName */
        $generalRootGroupNamesBySubSkillName = [];
        /** @var AISkillCatalogEntry[][] $entriesByGroupName */
        $entriesByGroupName = [];
        $ungroupedEntries = [];
        foreach ($this->skills as $entry) {
            if ($entry->isPurposeBound || $entry->isSubSkill) {
                continue;
            }
            $groupName = $aISkillNamespaceGroupDefinitions->findDefinitionForSkillName($entry->name)->name ?? null;
            foreach ($entry->subSkillNames as $subSkillName) {
                $generalRootGroupNamesBySubSkillName[$subSkillName][] = $groupName ?? AISkillCatalogNamespaceGroup::UNGROUPED_NAME;
            }
        }
        foreach ($this->skills as $entry) {
            if ($entry->isPurposeBound) {
                continue;
            }
            $groupNames = $entry->isSubSkill ? array_values(array_unique($generalRootGroupNamesBySubSkillName[$entry->name] ?? [])) : [];
            if ($groupNames === []) {
                $ownGroupName = $aISkillNamespaceGroupDefinitions->findDefinitionForSkillName($entry->name)->name ?? null;
                $groupNames = [$ownGroupName ?? AISkillCatalogNamespaceGroup::UNGROUPED_NAME];
            }
            foreach ($groupNames as $groupName) {
                if ($groupName === AISkillCatalogNamespaceGroup::UNGROUPED_NAME) {
                    $ungroupedEntries[] = $entry;
                    continue;
                }
                $entriesByGroupName[$groupName][] = $entry;
            }
        }

        $this->namespaceGroups = [];
        foreach ($aISkillNamespaceGroupDefinitions->definitions as $definition) {
            $this->namespaceGroups[] = AISkillCatalogNamespaceGroup::make($definition, $entriesByGroupName[$definition->name] ?? []);
        }
        if ($ungroupedEntries !== []) {
            $this->namespaceGroups[] = AISkillCatalogNamespaceGroup::makeUngrouped($ungroupedEntries);
        }
    }

    /**
     * A root (or orphan) skill is bound by its own `onlyUnderPurposeNames`. A sub-skill without an own binding belongs
     * to its roots: purpose-bound when EVERY root that lists it is purpose-bound (offered under the union of their
     * purposes); one general root keeps it reachable from an open conversation, so it stays general. A sub-skill no root
     * lists stays general.
     */
    protected function resolvePurposeBindings(): void
    {
        /** @var AISkillCatalogEntry[][] $rootEntriesBySubSkillName */
        $rootEntriesBySubSkillName = [];
        foreach ($this->skills as $entry) {
            $entry->isPurposeBound = $entry->onlyUnderPurposeNames !== [];
            $entry->offeredUnderPurposeNames = $entry->onlyUnderPurposeNames;
            if ($entry->isSubSkill) {
                continue;
            }
            foreach ($entry->subSkillNames as $subSkillName) {
                $rootEntriesBySubSkillName[$subSkillName][] = $entry;
            }
        }
        foreach ($this->skills as $entry) {
            if (!$entry->isSubSkill || $entry->isPurposeBound) {
                continue;
            }
            $rootEntries = $rootEntriesBySubSkillName[$entry->name] ?? [];
            if ($rootEntries === [] || !$this->areAllPurposeBound($rootEntries)) {
                continue;
            }
            $purposeNames = [];
            foreach ($rootEntries as $rootEntry) {
                $purposeNames = array_merge($purposeNames, $rootEntry->offeredUnderPurposeNames);
            }
            $entry->isPurposeBound = true;
            $entry->offeredUnderPurposeNames = array_values(array_unique($purposeNames));
        }
    }

    /** @param AISkillCatalogEntry[] $entries */
    protected function areAllPurposeBound(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (!$entry->isPurposeBound) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, AIConversationPurpose|null> $purposesByName
     * @return AISkillCatalogPurposeGroup[] by purpose name
     */
    protected function buildPurposeGroups(array $purposesByName): array
    {
        /** @var AISkillCatalogEntry[][] $entriesByPurposeName */
        $entriesByPurposeName = [];
        foreach ($this->skills as $entry) {
            if (!$entry->isPurposeBound) {
                continue;
            }
            foreach ($entry->offeredUnderPurposeNames as $purposeName) {
                $entriesByPurposeName[$purposeName][] = $entry;
            }
        }
        ksort($entriesByPurposeName);

        $purposeGroups = [];
        foreach ($entriesByPurposeName as $purposeName => $entries) {
            $purposeGroups[] = AISkillCatalogPurposeGroup::make((string)$purposeName, $purposesByName[$purposeName] ?? null, $entries);
        }
        return $purposeGroups;
    }
}
