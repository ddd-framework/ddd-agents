<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

/**
 * One namespace group of the GENERAL skills (Common, Agent, or a product domain of the host — config
 * `config/app/AI/skillNamespaceGroups.php`) with its own figures ({@see AISkillCatalogSkillGroup}). A root skill
 * belongs to the group of its longest matching name prefix, a sub-skill to its general roots' group. The explicit
 * "Ungrouped" group ({@see $isUngrouped}) collects the general skills no prefix matches — the signal for a missing
 * namespace. Purpose-bound skills are never in a namespace group; they are listed per purpose.
 *
 * @phpstan-consistent-constructor
 */
class AISkillCatalogNamespaceGroup extends AISkillCatalogSkillGroup
{
    /** @var string The name of the group of general skills no configured prefix matches. */
    public const string UNGROUPED_NAME = 'Ungrouped';

    /** @var string The kind of the Ungrouped group. */
    public const string UNGROUPED_KIND = 'ungrouped';

    /** @var string The group name (`Common`, `Agent`, `Setup`, …, or `Ungrouped`). */
    public string $name = '';

    /** @var string `domain`, `common`, `agent`, or `ungrouped`. */
    public string $kind = '';

    /** @var string One line: what the group is. */
    public string $shortDescription = '';

    /** @var string A few sentences: what belongs in the group and what does not. */
    public string $description = '';

    /** @var bool True = the group of general skills no configured namespace prefix matches. */
    public bool $isUngrouped = false;

    /**
     * The group of one configured namespace over its general entries.
     *
     * @param AISkillCatalogEntry[] $generalEntries
     */
    public static function make(AISkillNamespaceGroupDefinition $aISkillNamespaceGroupDefinition, array $generalEntries): static
    {
        $group = new static();
        $group->name = $aISkillNamespaceGroupDefinition->name;
        $group->kind = $aISkillNamespaceGroupDefinition->kind;
        $group->shortDescription = $aISkillNamespaceGroupDefinition->shortDescription;
        $group->description = $aISkillNamespaceGroupDefinition->description;
        $group->applyFiguresOf($generalEntries);
        return $group;
    }

    /**
     * The group of the general skills no configured prefix matches.
     *
     * @param AISkillCatalogEntry[] $generalEntries
     */
    public static function makeUngrouped(array $generalEntries): static
    {
        $group = new static();
        $group->name = self::UNGROUPED_NAME;
        $group->kind = self::UNGROUPED_KIND;
        $group->isUngrouped = true;
        $group->shortDescription = 'General skills whose name matches no configured namespace group.';
        $group->description = 'Every general skill should belong to one of the configured namespace groups. A skill here '
            . 'has a namespace that config/app/AI/skillNamespaceGroups.php does not know yet: add its prefix to the '
            . 'right group, or rename the skill.';
        $group->applyFiguresOf($generalEntries);
        return $group;
    }
}
