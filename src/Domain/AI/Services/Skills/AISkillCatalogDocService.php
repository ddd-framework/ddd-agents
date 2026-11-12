<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Skills;

use DDD\Domain\AI\Entities\Conversations\Agent\SubSkillToolDiscovery;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillCatalog;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillCatalogEntry;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillCatalogPackage;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillCatalogSharedBlock;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillCatalogSharedBlocks;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillCatalogTool;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillCatalogToolComponents;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillCatalogTotals;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillNamespaceGroupDefinitions;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillToolDocumentation;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsSyntax;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use DDD\Presentation\Base\Dtos\SharedRequestParametersSyntax;
use Throwable;

/**
 * The shared cartography of the agent's skill surface — the per-turn context cost the model ingests when a skill is
 * loaded, decomposed Skill → Package → Tool, plus each skill's instruction (`.md`) block, the full wire schema of any
 * single tool, and the two once-per-turn shared system blocks. Token sizes are measured from the EXACT wire schema the
 * loop sends ({@see ToolDefinitions::forPackages()}->toOpenAiToolSchemas() — the same call as
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService} buildToolSchemas), so the numbers are the real
 * per-turn cost, NOT the /documentation shape.
 *
 * Every method returns a fully-built TYPED value object ({@see AISkillCatalog} / {@see AISkillToolDocumentation} /
 * {@see AISkillCatalogSharedBlocks}) — so the consumer (the host's MCP skills
 * admin endpoints AND its skill token map console command AND any future agent CLI tool) gets typed data with no
 * array assembly. Read-only (reflection + `.md` config, no DB) — no auth context needed. Token counts come from the
 * real GPT-3 BPE tokenizer (`gioni06/gpt3-tokenizer`, via the cached {@see AIPromptsService::getTokenCountForString()}):
 * exact for GPT-family BPE, indicative for other model families whose tokenizers differ.
 */
class AISkillCatalogDocService extends Service
{
    /** @var string Config path of the skill namespace groups (`config/app/AI/skillNamespaceGroups.php`) */
    public const string CONFIG_SKILL_NAMESPACE_GROUPS = 'AI.skillNamespaceGroups';

    /**
     * The whole catalog as a typed tree: totals + Skill → Package → Tool (heaviest first at every level).
     */
    public function buildSkillCatalog(): AISkillCatalog
    {
        ini_set('memory_limit', '1024M'); // container + reflection over every package

        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = AISkill::getService();

        $skills = [];
        $sumOfSkillTotals = 0;
        $instructionTokensTotal = 0;
        $packageCount = 0;
        $toolCount = 0;
        $uniquePackages = [];
        $uniqueToolDefTokens = [];   // toolName => tokens (dedup across skills/packages)
        $rootSkillCount = 0;
        $subSkillCount = 0;

        foreach (AISkill::getAllSkillNames() as $skillName) {
            $skill = $aiSkillsService->getAISkillByName($skillName);
            $instructionTokens = $skill !== null
                ? $this->countTokens($skill->getInstructionBlock(
                    null,
                    null,
                    SubSkillToolDiscovery::listsSubSkillsUnderLoadedParent() ? $aiSkillsService->getCatalog() : null
                ))
                : 0;
            $instructionTokensTotal += $instructionTokens;

            $packages = [];
            $skillToolDefTokens = 0;
            $skillToolCount = 0;
            foreach (AISkill::getPackagesForSkill($skillName) as $packageClass) {
                $uniquePackages[$packageClass] = true;
                $packageCount++;
                $tools = [];
                $packageTotal = 0;
                try {
                    foreach (ToolDefinitions::forPackages([$packageClass])->getElements() as $toolDefinition) {
                        $openAiToolSchema = $toolDefinition->toOpenAiToolSchema();
                        $tokens = $this->countTokens((string)json_encode($openAiToolSchema));
                        $tools[] = AISkillCatalogTool::make($toolDefinition->name, $tokens, $this->decomposeOpenAiToolSchema($openAiToolSchema));
                        $packageTotal += $tokens;
                        $skillToolCount++;
                        $toolCount++;
                        $uniqueToolDefTokens[$toolDefinition->name] = $tokens;
                    }
                } catch (Throwable $e) {
                    $tools[] = AISkillCatalogTool::make('<error: ' . $e->getMessage() . '>', 0, null);
                }
                usort($tools, static fn (AISkillCatalogTool $a, AISkillCatalogTool $b): int => $b->tokens <=> $a->tokens);
                $packages[] = AISkillCatalogPackage::make($this->shortName($packageClass), $packageTotal, count($tools), $tools);
                $skillToolDefTokens += $packageTotal;
            }
            usort($packages, static fn (AISkillCatalogPackage $a, AISkillCatalogPackage $b): int => $b->total <=> $a->total);

            $skillTotal = $instructionTokens + $skillToolDefTokens;
            $sumOfSkillTotals += $skillTotal;
            // the skill's line in the system-prompt catalog, counted with the SAME renderer the wire uses
            $catalogLineTokens = $skill !== null
                ? $this->countTokens(LoadSkillsTool::renderCatalogLine(
                    $skill,
                    $skill->isSubSkill && SubSkillToolDiscovery::listsToolsInCatalog() ? $skill->getToolSummaries() : [],
                    !SubSkillToolDiscovery::listsSubSkillsUnderLoadedParent()
                ))
                : 0;
            if ($skill->isSubSkill ?? false) {
                $subSkillCount++;
            } else {
                $rootSkillCount++;
            }
            // Sub-skill tree info: totals stay STRICTLY per-skill — a root's entry carries only its OWN
            // instruction + directly-anchored tools; its sub-skills are separate entries (grouped in the UI).
            $skills[] = AISkillCatalogEntry::make(
                $skillName,
                $skillTotal,
                $instructionTokens,
                $skillToolDefTokens,
                $skillToolCount,
                $packages,
                $skill->isSubSkill ?? false,
                $skill->subSkillNames ?? [],
                $catalogLineTokens,
                $skill->onlyUnderPurposeNames ?? [],
                $skill->notUnderPurposeNames ?? [],
            );
        }
        usort($skills, static fn (AISkillCatalogEntry $a, AISkillCatalogEntry $b): int => $b->total <=> $a->total);

        $totals = AISkillCatalogTotals::make(
            count($skills),
            $packageCount,                  // counting a shared package once per skill
            count($uniquePackages),
            $toolCount,                     // counting a shared tool once per skill
            count($uniqueToolDefTokens),
            $sumOfSkillTotals,              // upper bound: every skill loaded at once
            $instructionTokensTotal,
            array_sum($uniqueToolDefTokens), // dedup tool-definition footprint
            $rootSkillCount,
            $subSkillCount,
        );

        // Purpose-bound skills are never offered in an open conversation: split them out of the general catalog
        // (the general catalog-block figure, the purpose groups) — the admin view of what each run actually gets.
        $aiSkillCatalog = AISkillCatalog::make($totals, $skills);
        $aiSkillCatalog->separatePurposeBoundSkills($this->findPurposesByName($aiSkillCatalog->getDeclaredPurposeNames()));
        // The general skills grouped by namespace (Common, Agent, the host's product domains) — admin view only.
        $aiSkillCatalog->groupGeneralSkillsByNamespace($this->findSkillNamespaceGroupDefinitions());
        return $aiSkillCatalog;
    }

    /**
     * The configured skill namespace groups (`config/app/AI/skillNamespaceGroups.php`) in display order; a missing
     * config yields no group, so every general skill shows as Ungrouped.
     */
    public function findSkillNamespaceGroupDefinitions(): AISkillNamespaceGroupDefinitions
    {
        $configRows = Config::get(self::CONFIG_SKILL_NAMESPACE_GROUPS);
        return AISkillNamespaceGroupDefinitions::fromConfigRows(is_array($configRows) ? $configRows : []);
    }

    /**
     * The purpose definitions (`config/app/AI/Purposes/**.md`, loaded by {@see AIConversationPurposesService}) for
     * the given names; a name without a loadable definition maps to null.
     *
     * @param string[] $purposeNames
     * @return array<string, AIConversationPurpose|null>
     */
    public function findPurposesByName(array $purposeNames): array
    {
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $purposesByName = [];
        foreach ($purposeNames as $purposeName) {
            try {
                $purposesByName[$purposeName] = $aIConversationPurposesService->getPurposeByName($purposeName);
            } catch (Throwable) {
                // an invalid purpose file is shown as an undefined purpose, never breaks the admin catalog
                $purposesByName[$purposeName] = null;
            }
        }
        return $purposesByName;
    }

    /**
     * The full documentation of ONE tool: the exact wire schema the model receives + its token-component breakdown +
     * which skill/package it belongs to. Tool names are unique across the catalog, so the FIRST match wins. Returns
     * null when no tool with that name exists.
     */
    public function buildToolDocumentation(string $toolName): ?AISkillToolDocumentation
    {
        ini_set('memory_limit', '1024M');

        foreach (AISkill::getAllSkillNames() as $skillName) {
            foreach (AISkill::getPackagesForSkill($skillName) as $packageClass) {
                try {
                    foreach (ToolDefinitions::forPackages([$packageClass])->getElements() as $toolDefinition) {
                        if ($toolDefinition->name !== $toolName) {
                            continue;
                        }
                        $openAiToolSchema = $toolDefinition->toOpenAiToolSchema();
                        return AISkillToolDocumentation::make(
                            $toolDefinition->name,
                            $skillName,
                            $this->shortName($packageClass),
                            $this->countTokens((string)json_encode($openAiToolSchema)),
                            $this->decomposeOpenAiToolSchema($openAiToolSchema),
                            $openAiToolSchema,
                        );
                    }
                } catch (Throwable) {
                    // a package whose reflection fails carries no matching tool — keep scanning the rest
                    continue;
                }
            }
        }
        return null;
    }

    /**
     * The two once-per-turn system-context blocks that are emitted ONCE (not per tool) when any loaded skill exposes a
     * QueryOptions parameter / shared request parameter — the QueryOptions grammar and the shared request-parameters
     * doc. They were factored OUT of the per-tool schemas (each tool param now carries only a short pointer back here),
     * so their cost is shown separately. Mirrors
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput} buildSystemSegments().
     */
    public function buildSharedBlocks(): AISkillCatalogSharedBlocks
    {
        $queryOptionsSyntax = QueryOptionsSyntax::getSyntaxDocumentation();
        $sharedRequestParameters = SharedRequestParametersSyntax::getDocumentation();

        return AISkillCatalogSharedBlocks::make([
            AISkillCatalogSharedBlock::make(
                'queryOptionsSyntax',
                'QueryOptions syntax',
                $this->countTokens($queryOptionsSyntax),
                $queryOptionsSyntax,
            ),
            AISkillCatalogSharedBlock::make(
                'sharedRequestParameters',
                SharedRequestParametersSyntax::HEADING,
                $this->countTokens($sharedRequestParameters),
                $sharedRequestParameters,
            ),
        ]);
    }

    /**
     * Decompose one function's wire schema into where its tokens live: the description, each top-level input parameter,
     * and each shared `$defs` definition (the reused nested DTO schemas — usually the real bloat, referenced from the
     * params via `$ref`). `envelope` is the structural remainder (name, type, required, wrappers).
     *
     * @param array<string, mixed> $openAiToolSchema
     */
    protected function decomposeOpenAiToolSchema(array $openAiToolSchema): AISkillCatalogToolComponents
    {
        $total = $this->countTokens((string)json_encode($openAiToolSchema));
        $function = is_array($openAiToolSchema['function'] ?? null) ? $openAiToolSchema['function'] : [];
        $description = $this->countTokens((string)($function['description'] ?? ''));
        $parameters = is_array($function['parameters'] ?? null) ? $function['parameters'] : [];

        $propertyTokens = [];
        $properties = is_array($parameters['properties'] ?? null) ? $parameters['properties'] : [];
        foreach ($properties as $propertyName => $propertySchema) {
            $propertyTokens[(string)$propertyName] = $this->countTokens((string)json_encode($propertySchema));
        }
        arsort($propertyTokens);

        $defTokens = [];
        $defs = is_array($parameters['$defs'] ?? null) ? $parameters['$defs'] : (is_array($parameters['definitions'] ?? null) ? $parameters['definitions'] : []);
        foreach ($defs as $defName => $defSchema) {
            $defTokens[(string)$defName] = $this->countTokens((string)json_encode($defSchema));
        }
        arsort($defTokens);

        $envelope = max(0, $total - $description - array_sum($propertyTokens) - array_sum($defTokens));
        return AISkillCatalogToolComponents::make($total, $description, $propertyTokens, $defTokens, $envelope);
    }

    /**
     * Token count via the real GPT-3 BPE tokenizer (`gioni06/gpt3-tokenizer`, reused through the codebase's cached
     * accessor {@see AIPromptsService::getTokenCountForString()} — the tokenizer instance is statically cached, so the
     * vocab/merges are loaded once per process). Exact for GPT-family BPE; indicative (not exact) for other model
     * families whose tokenizers differ.
     */
    protected function countTokens(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        return AIPromptsService::getTokenCountForString($text);
    }

    protected function shortName(string $class): string
    {
        return substr((string)strrchr('\\' . $class, '\\'), 1);
    }
}
