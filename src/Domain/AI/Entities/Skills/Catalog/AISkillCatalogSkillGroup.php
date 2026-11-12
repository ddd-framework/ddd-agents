<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A group of catalog skills with its own figures — the same figures the catalog header shows, for this group only:
 * root / sub-skill / tool counts, catalog-line tokens, instruction tokens, unique tool-def tokens and the Σ if every
 * skill of the group were loaded at once. The base of the purpose groups ({@see AISkillCatalogPurposeGroup}) and the
 * namespace groups of the general skills ({@see AISkillCatalogNamespaceGroup}).
 */
class AISkillCatalogSkillGroup extends ValueObject
{
    /** @var string[] The skills of this group (roots and sub-skills), catalog order. */
    public array $skillNames = [];

    /** @var int Root skills of this group. */
    public int $rootSkillCount = 0;

    /** @var int Sub-skills of this group. */
    public int $subSkillCount = 0;

    /** @var int Distinct tools of the skills of this group. */
    public int $toolCount = 0;

    /** @var int Σ of the catalog lines of the group's roots — what the group adds to the system-prompt catalog. */
    public int $catalogTokens = 0;

    /** @var int Σ of the `.md` instruction blocks of the group's skills. */
    public int $instructionTokens = 0;

    /** @var int De-duplicated tool-definition (wire-schema) footprint of the group's tools — a shared tool counted once. */
    public int $uniqueToolDefTokens = 0;

    /** @var int Σ of the skill totals (instruction + tool-defs) of the group's skills — all loaded at once. */
    public int $sumOfSkillTotals = 0;

    /**
     * Sets the skill names and every figure from the group's entries (replaces earlier figures).
     *
     * @param AISkillCatalogEntry[] $entries the entries of this group, catalog order
     */
    public function applyFiguresOf(array $entries): void
    {
        $this->skillNames = [];
        $this->rootSkillCount = 0;
        $this->subSkillCount = 0;
        $this->catalogTokens = 0;
        $this->instructionTokens = 0;
        $this->sumOfSkillTotals = 0;
        /** @var array<string, int> $toolTokensByName */
        $toolTokensByName = [];
        foreach ($entries as $entry) {
            $this->skillNames[] = $entry->name;
            if ($entry->isSubSkill) {
                $this->subSkillCount++;
            } else {
                $this->rootSkillCount++;
                $this->catalogTokens += $entry->catalogLineTokens;
            }
            $this->instructionTokens += $entry->instructionTokens;
            $this->sumOfSkillTotals += $entry->total;
            $toolTokensByName = array_replace($toolTokensByName, $entry->getToolTokensByName());
        }
        $this->toolCount = count($toolTokensByName);
        $this->uniqueToolDefTokens = array_sum($toolTokensByName);
    }
}
