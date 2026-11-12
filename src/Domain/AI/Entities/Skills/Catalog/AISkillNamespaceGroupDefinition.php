<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One namespace group of the agent's skills as configured in `config/app/AI/skillNamespaceGroups.php`: its name, kind, descriptions and the skill-name prefixes that map a skill into it. Admin view
 * only — nothing about the agent's offer or loading reads it.
 *
 * @phpstan-consistent-constructor
 */
class AISkillNamespaceGroupDefinition extends ValueObject
{
    /** @var string A product domain of the host (e.g. Workshop, Intake). */
    public const string KIND_DOMAIN = 'domain';

    /** @var string The host's product functions above the domains (e.g. account, files, settings). */
    public const string KIND_COMMON = 'common';

    /** @var string The agent itself: its capabilities and its own runs. */
    public const string KIND_AGENT = 'agent';

    /** @var string[] Every configurable kind. */
    public const array KINDS = [self::KIND_DOMAIN, self::KIND_COMMON, self::KIND_AGENT];

    /** @var string The group name (`Common`, `Agent`, `Setup`, …). */
    public string $name = '';

    /** @var string `domain`, `common` or `agent`. */
    #[Choice(choices: self::KINDS)]
    public string $kind = '';

    /** @var string One line: what the group is. */
    public string $shortDescription = '';

    /** @var string A few sentences: what belongs in the group and what does not. */
    public string $description = '';

    /** @var string[] Skill-name prefixes that map a skill into this group (longest prefix over all groups wins). */
    public array $skillNamePrefixes = [];

    /**
     * One definition from a config row; a row without a name, with an unknown kind or without prefixes is a
     * configuration error.
     *
     * @param array<string, mixed> $configRow
     */
    public static function fromConfigRow(array $configRow): static
    {
        $definition = new static();
        $definition->name = (string)($configRow['name'] ?? '');
        $definition->kind = (string)($configRow['kind'] ?? '');
        $definition->shortDescription = (string)($configRow['shortDescription'] ?? '');
        $definition->description = (string)($configRow['description'] ?? '');
        $definition->skillNamePrefixes = array_values(array_map('strval', (array)($configRow['skillNamePrefixes'] ?? [])));
        if ($definition->name === '' || !in_array($definition->kind, self::KINDS, true) || $definition->skillNamePrefixes === []) {
            throw new \LogicException(
                'Invalid skill namespace group "' . $definition->name . '" in config AI.skillNamespaceGroups: a name, a kind ('
                . implode('|', self::KINDS) . ') and at least one skill-name prefix are required.'
            );
        }
        return $definition;
    }

    /** The length of this group's longest prefix that starts the skill name; 0 = no prefix matches. */
    public function getLongestMatchingPrefixLength(string $skillName): int
    {
        $longest = 0;
        foreach ($this->skillNamePrefixes as $skillNamePrefix) {
            if (str_starts_with($skillName, $skillNamePrefix) && strlen($skillNamePrefix) > $longest) {
                $longest = strlen($skillNamePrefix);
            }
        }
        return $longest;
    }
}
