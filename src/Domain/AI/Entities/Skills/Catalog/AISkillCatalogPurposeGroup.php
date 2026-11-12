<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/**
 * One conversation purpose in the catalog's purpose section: the PURPOSE-BOUND skills offered under it (roots + the
 * sub-skills that belong to them) and what the purpose definition itself preloads and allows
 * (`config/app/AI/Purposes/**.md` frontmatter `preloadSkillNames` / `allowedLoadSkillNames`). A skill bound to
 * several purposes is listed in each group, so every group is the complete bound share of that run. The group figures
 * (counts, tokens) come from {@see AISkillCatalogSkillGroup}.
 *
 * @phpstan-consistent-constructor
 */
class AISkillCatalogPurposeGroup extends AISkillCatalogSkillGroup
{
    /** @var string The dotted purpose name (e.g. `Agent.DayContext`). */
    public string $purposeName = '';

    /** @var string The purpose's one-line description (frontmatter `description`); empty when the purpose file is missing. */
    public string $description = '';

    /** @var bool False = a skill names this purpose, but no loadable purpose definition exists for it. */
    public bool $isDefined = false;

    /** @var string[] Skills the purpose preloads at creation (frontmatter `preloadSkillNames`). */
    public array $preloadSkillNames = [];

    /** @var string[] The purpose's load rail (frontmatter `allowedLoadSkillNames`); empty = the full entitled catalog. */
    public array $allowedLoadSkillNames = [];

    /**
     * The group of one purpose over the catalog entries bound to it.
     *
     * @param AISkillCatalogEntry[] $purposeBoundEntries the purpose-bound entries offered under this purpose
     */
    public static function make(string $purposeName, ?AIConversationPurpose $aIConversationPurpose, array $purposeBoundEntries): static
    {
        $group = new static();
        $group->purposeName = $purposeName;
        $group->isDefined = $aIConversationPurpose !== null;
        $group->description = $aIConversationPurpose->description ?? '';
        $group->preloadSkillNames = $aIConversationPurpose->preloadSkillNames ?? [];
        $group->allowedLoadSkillNames = $aIConversationPurpose->allowedLoadSkillNames ?? [];
        $group->applyFiguresOf($purposeBoundEntries);
        return $group;
    }
}
