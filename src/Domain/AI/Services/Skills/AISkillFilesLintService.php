<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Skills;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * The skill-file lint: what breaks a skill or purpose file at runtime without any warning, checked over
 * every file of the given roots — a module's files included when an application overrides them (the module's file
 * still ships and loads in another application). Every problem is one line naming the file and the cause; an empty
 * list = clean. Run by the package's test over its own root and by an application's test over every registered root
 * ({@see AISkillSourcesService}).
 *
 * Skill files: the frontmatter parses as YAML (a double-quoted value with an unescaped inner double quote breaks every
 * run that loads the skill), names a description, carries only the keys the loader reads
 * ({@see AISkillsService::SKILL_FRONTMATTER_KEYS} — a misspelt gate is silently no gate), its lists are lists of
 * strings and its sub-skills resolve. Purpose files: the frontmatter parses and names a valid purpose class. The
 * binding (`AI.skillPackages`): every package id resolves, every bound class is an MCP package, every catalog skill has
 * a file, and no tool name is provided by two different packages. Names: no two files collide case-insensitively.
 */
class AISkillFilesLintService extends Service
{
    /** @var string[] The skill frontmatter keys whose value must be a list of strings. */
    public const array SKILL_FRONTMATTER_LIST_KEYS = [
        'requiredFeatureFlags', 'anyOfRequiredFeatureFlags', 'requiredAccountRights', 'subSkillNames',
        'onlyUnderPurposeNames', 'notUnderPurposeNames', 'preloadOnWidgetTypes', 'providesWidgetTypes', 'onlyOnInitiatorTypes',
    ];

    /**
     * Every problem of the skill files below the given skill folders.
     *
     * @param string[] $skillRootDirectories
     * @return string[]
     */
    public function findSkillFileProblems(array $skillRootDirectories): array
    {
        /** @var AISkillSourcesService $aISkillSourcesService */
        $aISkillSourcesService = DDDService::instance()->getService(AISkillSourcesService::class);
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $problems = [];
        $skillNamesByRoot = [];
        foreach ($skillRootDirectories as $skillRootDirectory) {
            $skillNamesByRoot[$skillRootDirectory] = $aISkillSourcesService->findDottedNamesInFolder($skillRootDirectory);
            foreach ($skillNamesByRoot[$skillRootDirectory] as $skillName) {
                $skillFile = $skillRootDirectory . '/' . str_replace('.', '/', $skillName) . '.md';
                $frontmatter = $this->parseFrontmatterOfFile($skillFile, $aISkillsService, $problems);
                if ($frontmatter === null) {
                    continue;
                }
                if (trim((string)($frontmatter['description'] ?? '')) === '') {
                    $problems[] = "$skillFile: no description (the only routing signal of a skill)";
                }
                foreach (array_diff(array_keys($frontmatter), AISkillsService::SKILL_FRONTMATTER_KEYS) as $unknownKey) {
                    $problems[] = "$skillFile: unknown frontmatter key '$unknownKey' (the loader ignores it)";
                }
                foreach (self::SKILL_FRONTMATTER_LIST_KEYS as $listKey) {
                    if (array_key_exists($listKey, $frontmatter) && !$this->isListOfStrings($frontmatter[$listKey])) {
                        $problems[] = "$skillFile: '$listKey' is not a list of strings";
                    }
                }
                foreach ((array)($frontmatter['subSkillNames'] ?? []) as $subSkillName) {
                    if (is_string($subSkillName) && $aISkillsService->getAISkillByName($subSkillName) === null) {
                        $problems[] = "$skillFile: sub-skill '$subSkillName' resolves to no skill file";
                    }
                }
            }
        }
        foreach ($aISkillSourcesService->findCollidingNames($skillNamesByRoot) as $lowercaseSkillName => $holders) {
            $problems[] = "skill name '$lowercaseSkillName' collides (case-insensitive): " . implode(', ', $holders);
        }
        return $problems;
    }

    /**
     * Every problem of the purpose files below the given purpose folders.
     *
     * @param string[] $purposeRootDirectories
     * @return string[]
     */
    public function findPurposeFileProblems(array $purposeRootDirectories): array
    {
        /** @var AISkillSourcesService $aISkillSourcesService */
        $aISkillSourcesService = DDDService::instance()->getService(AISkillSourcesService::class);
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $problems = [];
        $purposeNamesByRoot = [];
        foreach ($purposeRootDirectories as $purposeRootDirectory) {
            $purposeNamesByRoot[$purposeRootDirectory] = $aISkillSourcesService->findDottedNamesInFolder($purposeRootDirectory);
            foreach ($purposeNamesByRoot[$purposeRootDirectory] as $purposeName) {
                $purposeFile = $purposeRootDirectory . '/' . str_replace('.', '/', $purposeName) . '.md';
                $frontmatter = $this->parseFrontmatterOfFile($purposeFile, $aISkillsService, $problems);
                if ($frontmatter === null) {
                    continue;
                }
                $purposeClass = $frontmatter[AIConversationPurposesService::FRONTMATTER_KEY_PURPOSE_CLASS] ?? null;
                if (!is_string($purposeClass) || !class_exists(trim($purposeClass)) || !is_a(trim($purposeClass), AIConversationPurpose::class, true)) {
                    $problems[] = "$purposeFile: no valid purposeClass (the purpose would not load)";
                }
            }
        }
        foreach ($aISkillSourcesService->findCollidingNames($purposeNamesByRoot) as $lowercasePurposeName => $holders) {
            $problems[] = "purpose name '$lowercasePurposeName' collides (case-insensitive): " . implode(', ', $holders);
        }
        return $problems;
    }

    /**
     * Every problem of the effective skill → package binding (`AI.skillPackages` as the running application reads it).
     *
     * @return string[]
     */
    public function findBindingProblems(): array
    {
        try {
            $packageClassesBySkillName = AISkill::getPackageClassesBySkillName();
        } catch (Throwable $bindingError) {
            return ['skill binding: ' . $bindingError->getMessage()];
        }
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $problems = [];
        $packageClassesByToolName = [];
        foreach ($packageClassesBySkillName as $skillName => $packageClasses) {
            if ($aISkillsService->getAISkillByName((string)$skillName) === null) {
                $problems[] = "skill binding: '$skillName' has no skill file in any config root";
            }
            foreach ($packageClasses as $packageClass) {
                if (!class_exists($packageClass) || !is_subclass_of($packageClass, MCPPackage::class)) {
                    $problems[] = "skill binding: '$skillName' binds '$packageClass', which is no MCP package";
                    continue;
                }
                foreach ((new ReflectionClass($packageClass))->getMethods(ReflectionMethod::IS_PUBLIC) as $packageMethod) {
                    if ($packageMethod->getAttributes(MCPTool::class) !== []) {
                        $packageClassesByToolName[$packageMethod->getName()][$packageClass] = true;
                    }
                }
            }
        }
        foreach ($packageClassesByToolName as $toolName => $packageClassSet) {
            if (count($packageClassSet) > 1) {
                $problems[] = "tool name '$toolName' is provided by more than one package: " . implode(', ', array_keys($packageClassSet));
            }
        }
        return $problems;
    }

    /**
     * The frontmatter of a skill or purpose file after the persona substitution, or null when it does not parse (the
     * problem is appended).
     *
     * @param string[] $problems
     * @return array<string, mixed>|null
     */
    protected function parseFrontmatterOfFile(string $configFile, AISkillsService $aISkillsService, array &$problems): ?array
    {
        $rawText = (string)file_get_contents($configFile);
        if (!preg_match('/^\x{FEFF}?\s*---\s*\R/u', $rawText)) {
            $problems[] = "$configFile: no frontmatter block";
            return null;
        }
        try {
            $frontmatter = AISkillsService::splitFrontmatter($aISkillsService->substitutePersonaPlaceholders($rawText))->frontmatter;
        } catch (Throwable $yamlError) {
            $problems[] = "$configFile: the frontmatter is no valid YAML — " . $yamlError->getMessage();
            return null;
        }
        if ($frontmatter === []) {
            $problems[] = "$configFile: the frontmatter is empty or no map";
            return null;
        }
        return $frontmatter;
    }

    public function isListOfStrings(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $element) {
            if (!is_string($element)) {
                return false;
            }
        }
        return true;
    }
}
