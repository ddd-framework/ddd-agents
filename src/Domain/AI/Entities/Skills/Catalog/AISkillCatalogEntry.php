<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One skill in the catalog tree: its dotted name, total per-turn cost (instruction + tool-defs) with the split, tool
 * count, and its packages (heaviest first).
 *
 * @phpstan-consistent-constructor
 */
class AISkillCatalogEntry extends ValueObject
{
    /** @var string The skill's dotted name (e.g. `Reputation.Overview`). */
    public string $name = '';

    /** @var int instructionTokens + toolDefTokens. */
    public int $total = 0;

    /** @var int Tokens of the skill's `.md` instruction block. */
    public int $instructionTokens = 0;

    /** @var int Σ of the skill's tool wire-schema tokens. */
    public int $toolDefTokens = 0;

    /** @var int Number of tools the skill exposes. */
    public int $toolCount = 0;

    /** @var AISkillCatalogPackage[] The skill's packages, heaviest first. */
    public array $packages = [];

    /** @var bool True = a sub-skill (not in the general catalog; offered only while one of its roots is loaded). */
    public bool $isSubSkill = false;

    /**
     * @var string[] For a ROOT skill: the sub-skill names it unlocks (frontmatter `subSkillNames`). The sub-skills'
     * own tokens are deliberately NOT part of this entry's `total` — each sub-skill carries its own entry.
     */
    public array $subSkillNames = [];

    /**
     * @var int Tokens of this skill's line in the system-prompt catalog (`- name — description · unlocks …`,
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool::renderCatalogLine()}) — what every
     * conversation that is OFFERED this skill pays per call before loading anything.
     */
    public int $catalogLineTokens = 0;

    /**
     * @var string[] The skill's own purpose binding (frontmatter `onlyUnderPurposeNames`): offered ONLY in a
     * conversation with one of these purposes, never in an open chat. Empty = no own binding.
     */
    public array $onlyUnderPurposeNames = [];

    /** @var string[] The purposes this skill is never offered under (frontmatter `notUnderPurposeNames`); it stays a general skill. */
    public array $notUnderPurposeNames = [];

    /**
     * @var bool True = never offered in an open conversation (purpose null): bound by its own `onlyUnderPurposeNames`
     * or — a sub-skill — because every root that lists it is purpose-bound. Set by
     * {@see AISkillCatalog::separatePurposeBoundSkills()}.
     */
    public bool $isPurposeBound = false;

    /**
     * @var string[] The purposes this skill is offered under when purpose-bound: its own binding, or the union of its
     * roots' purposes for a sub-skill without one. Empty for a general skill.
     */
    public array $offeredUnderPurposeNames = [];

    /**
     * @param AISkillCatalogPackage[] $packages
     * @param string[] $subSkillNames
     * @param string[] $onlyUnderPurposeNames
     * @param string[] $notUnderPurposeNames
     */
    public static function make(
        string $name,
        int $total,
        int $instructionTokens,
        int $toolDefTokens,
        int $toolCount,
        array $packages,
        bool $isSubSkill = false,
        array $subSkillNames = [],
        int $catalogLineTokens = 0,
        array $onlyUnderPurposeNames = [],
        array $notUnderPurposeNames = [],
    ): static {
        $entry = new static();
        $entry->name = $name;
        $entry->total = $total;
        $entry->instructionTokens = $instructionTokens;
        $entry->toolDefTokens = $toolDefTokens;
        $entry->toolCount = $toolCount;
        $entry->packages = $packages;
        $entry->isSubSkill = $isSubSkill;
        $entry->subSkillNames = $subSkillNames;
        $entry->catalogLineTokens = $catalogLineTokens;
        $entry->onlyUnderPurposeNames = $onlyUnderPurposeNames;
        $entry->notUnderPurposeNames = $notUnderPurposeNames;
        return $entry;
    }

    /**
     * The names of the tools this skill exposes (error rows of a package whose reflection failed are skipped).
     *
     * @return string[]
     */
    public function getToolNames(): array
    {
        $toolNames = [];
        foreach ($this->packages as $package) {
            foreach ($package->tools as $tool) {
                if ($tool->components !== null) {
                    $toolNames[] = $tool->name;
                }
            }
        }
        return $toolNames;
    }

    /**
     * The wire-schema tokens of each tool this skill exposes, by tool name (error rows skipped) — the input of a
     * de-duplicated tool-def footprint over several skills.
     *
     * @return array<string, int>
     */
    public function getToolTokensByName(): array
    {
        $toolTokensByName = [];
        foreach ($this->packages as $package) {
            foreach ($package->tools as $tool) {
                if ($tool->components !== null) {
                    $toolTokensByName[$tool->name] = $tool->tokens;
                }
            }
        }
        return $toolTokensByName;
    }
}
