<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The configured namespace groups (`config/app/AI/skillNamespaceGroups.php`) in display order, and the rule that
 * maps a skill name to one of them: the LONGEST matching prefix over all groups wins.
 *
 * @phpstan-consistent-constructor
 */
class AISkillNamespaceGroupDefinitions extends ValueObject
{
    /** @var AISkillNamespaceGroupDefinition[] The groups, display order. */
    public array $definitions = [];

    /**
     * The definitions from the config rows (`Config::get('AI.skillNamespaceGroups')`); a duplicate group name is a
     * configuration error.
     *
     * @param array<int, array<string, mixed>> $configRows
     */
    public static function fromConfigRows(array $configRows): static
    {
        $definitions = new static();
        $seenNames = [];
        foreach ($configRows as $configRow) {
            $definition = AISkillNamespaceGroupDefinition::fromConfigRow((array)$configRow);
            if (isset($seenNames[$definition->name])) {
                throw new \LogicException('Duplicate skill namespace group "' . $definition->name . '" in config AI.skillNamespaceGroups.');
            }
            $seenNames[$definition->name] = true;
            $definitions->definitions[] = $definition;
        }
        return $definitions;
    }

    /** The group a skill name belongs to by its longest matching prefix; null = no group matches (an Ungrouped skill). */
    public function findDefinitionForSkillName(string $skillName): ?AISkillNamespaceGroupDefinition
    {
        $bestDefinition = null;
        $bestLength = 0;
        foreach ($this->definitions as $definition) {
            $length = $definition->getLongestMatchingPrefixLength($skillName);
            if ($length > $bestLength) {
                $bestLength = $length;
                $bestDefinition = $definition;
            }
        }
        return $bestDefinition;
    }
}
