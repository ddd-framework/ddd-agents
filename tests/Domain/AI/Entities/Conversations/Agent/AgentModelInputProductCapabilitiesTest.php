<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The PRODUCT_CAPABILITIES system segment — the product-awareness block sits in the cacheable prefix, right
 * after the memory directives and before the loaded skills, and is omitted when empty. Plus a smoke check that the
 * agent-relevant flag scan runs over the real package catalog without error.
 */
class AgentModelInputProductCapabilitiesTest extends UnitTestCase
{
    /**
     * @param AgentContextSegment[] $segments
     * @return AgentContextSegmentType[]
     */
    protected function segmentTypes(array $segments): array
    {
        return array_map(static fn(AgentContextSegment $segment): AgentContextSegmentType => $segment->type, $segments);
    }

    public function testSegmentSitsAfterMemoryDirectives(): void
    {
        $agentContext = new AgentModelInput();
        $agentContext->systemPrompt = 'BASE';
        $agentContext->memoryDirectives = 'MEM';
        $agentContext->productCapabilities = 'CAPS-BLOCK';

        $segments = $agentContext->buildSystemSegments();
        $types = $this->segmentTypes($segments);

        self::assertContains(AgentContextSegmentType::PRODUCT_CAPABILITIES, $types);
        $memoryDirectivesIndex = array_search(AgentContextSegmentType::MEMORY_DIRECTIVES, $types, true);
        $productCapabilitiesIndex = array_search(AgentContextSegmentType::PRODUCT_CAPABILITIES, $types, true);
        self::assertIsInt($memoryDirectivesIndex);
        self::assertGreaterThan($memoryDirectivesIndex, $productCapabilitiesIndex, 'product capabilities follows the memory directives');
        self::assertStringContainsString('CAPS-BLOCK', $segments[$productCapabilitiesIndex]->text);
    }

    public function testSegmentOmittedWhenEmpty(): void
    {
        $agentContext = new AgentModelInput();
        $agentContext->systemPrompt = 'BASE';
        self::assertNotContains(AgentContextSegmentType::PRODUCT_CAPABILITIES, $this->segmentTypes($agentContext->buildSystemSegments()));
    }

    public function testAgentRelevantFlagScanRuns(): void
    {
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = AISkill::getService();
        // The scan reflects every skill's packages; it must run without error and return a de-duplicated flag list.
        $agentRelevantFeatureFlagNames = $aiSkillsService->getAgentRelevantFeatureFlagNames();
        self::assertSame(array_values(array_unique($agentRelevantFeatureFlagNames)), $agentRelevantFeatureFlagNames);
    }
}
