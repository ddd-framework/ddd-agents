<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Domain\AI\Services\Skills\AISkillsService;
use PHPUnit\Framework\TestCase;

/**
 * The requirement overlay (`AI.skillRequirements`) merged into a skill's frontmatter at load time, and the one persona
 * substitution step of skill and purpose files.
 */
class AISkillsServiceRequirementsAndPersonaTest extends TestCase
{
    public function testTheOverlayIsUnitedWithTheFrontmatterFrontmatterFirst(): void
    {
        $mergedFrontmatter = AISkillsService::mergeRequirementOverlay(
            ['description' => 'd', 'requiredFeatureFlags' => ['A', 'B']],
            ['requiredFeatureFlags' => ['B', 'C'], 'requiredAccountRights' => ['manageX'], 'unknownKey' => ['ignored']],
            ['requiredFeatureFlags', 'anyOfRequiredFeatureFlags', 'requiredAccountRights'],
            ['requiresAccountBillingAccess']
        );
        self::assertSame(['description' => 'd', 'requiredFeatureFlags' => ['A', 'B', 'C'], 'requiredAccountRights' => ['manageX']], $mergedFrontmatter);
    }

    public function testABoolGateIsTrueWhenEitherSideSaysSo(): void
    {
        $keys = [[], ['requiresAccountBillingAccess']];
        self::assertTrue(AISkillsService::mergeRequirementOverlay([], ['requiresAccountBillingAccess' => true], ...$keys)['requiresAccountBillingAccess']);
        self::assertTrue(AISkillsService::mergeRequirementOverlay(['requiresAccountBillingAccess' => true], ['requiresAccountBillingAccess' => false], ...$keys)['requiresAccountBillingAccess']);
        self::assertFalse(AISkillsService::mergeRequirementOverlay([], ['requiresAccountBillingAccess' => false], ...$keys)['requiresAccountBillingAccess']);
    }

    public function testASkillWithoutAnOverlayEntryKeepsItsFrontmatter(): void
    {
        $aISkillsService = new AISkillsServiceWithFixedConfig(['Assistant.Budget' => ['requiredFeatureFlags' => ['BUDGET']]], []);
        $frontmatter = ['description' => 'd'];
        self::assertSame($frontmatter, $aISkillsService->applySkillRequirementOverlay('Assistant.Mandates', $frontmatter));
        self::assertSame(['description' => 'd', 'requiredFeatureFlags' => ['BUDGET']], $aISkillsService->applySkillRequirementOverlay('Assistant.Budget', $frontmatter));
    }

    public function testThePersonaPlaceholdersAreReplacedAndTheDefaultsApplyWithoutAPersona(): void
    {
        $rawText = "---\ndescription: \"{%AGENT_NAME%} for {%PRODUCT_NAME%}\"\n---\n{%AGENT_NAME%} acts. {%OTHER%} stays.";
        self::assertSame(
            "---\ndescription: \"Ava for Shopline\"\n---\nAva acts. {%OTHER%} stays.",
            (new AISkillsServiceWithFixedConfig([], ['agentName' => 'Ava', 'productName' => 'Shopline']))->substitutePersonaPlaceholders($rawText)
        );
        self::assertSame(
            "---\ndescription: \"Assistant for this product\"\n---\nAssistant acts. {%OTHER%} stays.",
            (new AISkillsServiceWithFixedConfig([], []))->substitutePersonaPlaceholders($rawText)
        );
        self::assertSame('no placeholder', (new AISkillsServiceWithFixedConfig([], ['agentName' => 'Ava']))->substitutePersonaPlaceholders('no placeholder'));
    }

    public function testThePackagesSkillFilesNameNoAgentAfterTheSubstitution(): void
    {
        $skillsDirectory = dirname(__DIR__, 5) . '/config/app/AI/Skills';
        $aISkillsService = new AISkillsServiceWithFixedConfig([], ['agentName' => 'Ava', 'productName' => 'Shopline']);
        foreach (['Assistant/Mandates.md', 'Assistant/TaskRunReports.md'] as $skillFile) {
            $substitutedText = $aISkillsService->substitutePersonaPlaceholders((string)file_get_contents($skillsDirectory . '/' . $skillFile));
            self::assertStringNotContainsString('{%', $substitutedText, $skillFile);
            $frontmatter = AISkillsService::splitFrontmatter($substitutedText)->frontmatter;
            self::assertNotSame('', (string)($frontmatter['description'] ?? ''), $skillFile);
        }
    }
}
