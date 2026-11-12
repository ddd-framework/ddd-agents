<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Catalog-wide totals for the skill-token map (the `totals` strip).
 *
 * @phpstan-consistent-constructor
 */
class AISkillCatalogTotals extends ValueObject
{
    /** @var int Number of registered skills. */
    public int $skillCount = 0;

    /** @var int Skill→package links (a shared package counted once per skill). */
    public int $packageCount = 0;

    /** @var int Distinct MCP packages. */
    public int $uniquePackageCount = 0;

    /** @var int Skill→tool links (a shared tool counted once per skill). */
    public int $toolCount = 0;

    /** @var int Distinct tools across the catalog. */
    public int $uniqueToolCount = 0;

    /** @var int Upper bound: every skill — general AND purpose-bound — loaded at once (Σ of skill totals). */
    public int $sumOfSkillTotals = 0;

    /** @var int Σ of the skill `.md` instruction blocks. */
    public int $instructionTokens = 0;

    /** @var int De-duplicated tool-definition (wire-schema) footprint. */
    public int $uniqueToolDefTokens = 0;

    /** @var int Root-level skills (not a sub-skill), general AND purpose-bound; the general share is {@see $generalRootSkillCount}. */
    public int $rootSkillCount = 0;

    /** @var int Sub-skills (offered only while one of their roots is loaded), general AND purpose-bound. */
    public int $subSkillCount = 0;

    /**
     * @var int Σ of the GENERAL root skills' catalog lines — the system-prompt catalog cost of a fresh
     * conversation offered every general root. Purpose-bound roots are never in an open conversation's catalog; their
     * lines are {@see $purposeBoundCatalogTokens}.
     */
    public int $rootCatalogTokens = 0;

    /** @var int General root skills (no purpose binding) — what a fresh conversation can load. */
    public int $generalRootSkillCount = 0;

    /** @var int Sub-skills reachable through at least one general root. */
    public int $generalSubSkillCount = 0;

    /** @var int Distinct tools of the general skills. */
    public int $generalToolCount = 0;

    /** @var int Σ of the general skills' `.md` instruction blocks. */
    public int $generalInstructionTokens = 0;

    /** @var int Σ of the general skills' totals — every general skill loaded at once. */
    public int $generalSumOfSkillTotals = 0;

    /** @var int Purpose-bound root skills (offered only under their purposes, never in an open conversation). */
    public int $purposeBoundRootSkillCount = 0;

    /** @var int Purpose-bound sub-skills (own binding, or every root that lists them is purpose-bound). */
    public int $purposeBoundSubSkillCount = 0;

    /** @var int Distinct tools of the purpose-bound skills. */
    public int $purposeBoundToolCount = 0;

    /** @var int Σ of the purpose-bound roots' catalog lines (each counted once) — paid only inside their purposes. */
    public int $purposeBoundCatalogTokens = 0;

    /** @var int Σ of the purpose-bound skills' `.md` instruction blocks. */
    public int $purposeBoundInstructionTokens = 0;

    /** @var int Σ of the purpose-bound skills' totals (each counted once). */
    public int $purposeBoundSumOfSkillTotals = 0;

    /** @var int Distinct purposes at least one purpose-bound skill is offered under. */
    public int $purposeCount = 0;

    public static function make(
        int $skillCount,
        int $packageCount,
        int $uniquePackageCount,
        int $toolCount,
        int $uniqueToolCount,
        int $sumOfSkillTotals,
        int $instructionTokens,
        int $uniqueToolDefTokens,
        int $rootSkillCount = 0,
        int $subSkillCount = 0,
        int $rootCatalogTokens = 0,
    ): static {
        $totals = new static();
        $totals->skillCount = $skillCount;
        $totals->packageCount = $packageCount;
        $totals->uniquePackageCount = $uniquePackageCount;
        $totals->toolCount = $toolCount;
        $totals->uniqueToolCount = $uniqueToolCount;
        $totals->sumOfSkillTotals = $sumOfSkillTotals;
        $totals->instructionTokens = $instructionTokens;
        $totals->uniqueToolDefTokens = $uniqueToolDefTokens;
        $totals->rootSkillCount = $rootSkillCount;
        $totals->subSkillCount = $subSkillCount;
        $totals->rootCatalogTokens = $rootCatalogTokens;
        return $totals;
    }

    /**
     * Fills the general / purpose-bound split from entries whose binding is resolved
     * ({@see AISkillCatalog::separatePurposeBoundSkills()}). Overwrites {@see $rootCatalogTokens} with the GENERAL
     * roots' share.
     *
     * @param AISkillCatalogEntry[] $entries
     */
    public function applyPurposeSplit(array $entries, int $purposeCount): void
    {
        $this->generalRootSkillCount = 0;
        $this->generalSubSkillCount = 0;
        $this->generalInstructionTokens = 0;
        $this->generalSumOfSkillTotals = 0;
        $this->rootCatalogTokens = 0;
        $this->purposeBoundRootSkillCount = 0;
        $this->purposeBoundSubSkillCount = 0;
        $this->purposeBoundCatalogTokens = 0;
        $this->purposeBoundInstructionTokens = 0;
        $this->purposeBoundSumOfSkillTotals = 0;
        $generalToolNames = [];
        $purposeBoundToolNames = [];
        foreach ($entries as $entry) {
            if ($entry->isPurposeBound) {
                if ($entry->isSubSkill) {
                    $this->purposeBoundSubSkillCount++;
                } else {
                    $this->purposeBoundRootSkillCount++;
                    $this->purposeBoundCatalogTokens += $entry->catalogLineTokens;
                }
                $this->purposeBoundInstructionTokens += $entry->instructionTokens;
                $this->purposeBoundSumOfSkillTotals += $entry->total;
                $purposeBoundToolNames = array_merge($purposeBoundToolNames, $entry->getToolNames());
                continue;
            }
            if ($entry->isSubSkill) {
                $this->generalSubSkillCount++;
            } else {
                $this->generalRootSkillCount++;
                $this->rootCatalogTokens += $entry->catalogLineTokens;
            }
            $this->generalInstructionTokens += $entry->instructionTokens;
            $this->generalSumOfSkillTotals += $entry->total;
            $generalToolNames = array_merge($generalToolNames, $entry->getToolNames());
        }
        $this->generalToolCount = count(array_unique($generalToolNames));
        $this->purposeBoundToolCount = count(array_unique($purposeBoundToolNames));
        $this->purposeCount = $purposeCount;
    }
}
