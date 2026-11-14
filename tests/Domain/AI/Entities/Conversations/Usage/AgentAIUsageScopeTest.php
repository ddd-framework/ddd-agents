<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Usage;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationControllerHooksTrait;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Symfony\Commands\Agents\RecordingUsageEnvelopesServiceDouble;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * The usage scopes of the agent core: every scope is a dotted upper-case identifier with a description, no value is
 * declared twice, an `ENVELOPE_` constant carries an `ENVELOPE.` scope the envelope service opens, and the search and
 * evaluation scopes carry their values.
 */
class AgentAIUsageScopeTest extends UnitTestCase
{
    /** @var string The shape of a scope: dotted segments of upper-case letters, digits and underscores */
    public const string SCOPE_PATTERN = '~^[A-Z][A-Z0-9_]*(\.[A-Z][A-Z0-9_]*)+$~';

    public function testEveryScopeHasTheScopeShapeAndADescription(): void
    {
        foreach ((new ReflectionClass(AgentAIUsageScope::class))->getReflectionConstants() as $reflectionClassConstant) {
            self::assertMatchesRegularExpression(self::SCOPE_PATTERN, (string)$reflectionClassConstant->getValue(), $reflectionClassConstant->getName());
            self::assertNotFalse($reflectionClassConstant->getDocComment(), $reflectionClassConstant->getName() . ' has a description');
        }
    }

    public function testNoScopeValueIsDeclaredTwice(): void
    {
        $scopeValues = array_values((new ReflectionClass(AgentAIUsageScope::class))->getConstants());

        self::assertSame(count($scopeValues), count(array_unique($scopeValues)));
    }

    public function testEveryEnvelopeConstantCarriesAnEnvelopeScopeTheEnvelopeServiceOpens(): void
    {
        $usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
        foreach ((new ReflectionClass(AgentAIUsageScope::class))->getReflectionConstants() as $reflectionClassConstant) {
            $isEnvelopeConstant = str_starts_with($reflectionClassConstant->getName(), 'ENVELOPE_');
            self::assertSame($isEnvelopeConstant, str_starts_with((string)$reflectionClassConstant->getValue(), 'ENVELOPE.'), $reflectionClassConstant->getName());
            if ($isEnvelopeConstant) {
                $this->assertTheEnvelopeServiceOpensAndClosesTheScope($usageEnvelopesServiceDouble, $reflectionClassConstant);
            }
        }
    }

    public function testTheSearchAndEvaluationScopesCarryTheirValues(): void
    {
        self::assertSame('ENVELOPE.AI.CONVERSATION.SEARCH', AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_SEARCH);
        self::assertSame('ENVELOPE.AI.AGENT.EVALUATION', AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION);
        self::assertSame('AI.AGENT.SIMULATE.OWNER', AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER);
        self::assertSame('AI.AGENT.JUDGE.EVALUATION', AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION);
    }

    public function testTheChatSearchRoutesBookUnderTheSearchEnvelope(): void
    {
        self::assertSame(
            AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_SEARCH,
            (new ReflectionClass(AgentConversationControllerHooksTrait::class))->getConstant('AGENT_CONVERSATION_SEARCH_ENVELOPE_SCOPE')
        );
    }

    protected function assertTheEnvelopeServiceOpensAndClosesTheScope(
        RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble,
        ReflectionClassConstant $reflectionClassConstant
    ): void {
        $envelopeContext = $usageEnvelopesServiceDouble->openEnvelope(1, (string)$reflectionClassConstant->getValue());
        self::assertTrue($usageEnvelopesServiceDouble->hasActiveEnvelope(), $reflectionClassConstant->getName());
        $usageEnvelopesServiceDouble->closeEnvelope($envelopeContext);
        self::assertFalse($usageEnvelopesServiceDouble->hasActiveEnvelope(), $reflectionClassConstant->getName());
    }
}
