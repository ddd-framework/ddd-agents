<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentPausedTurnRecord;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;

/**
 * The arguments of a read as the record of a paused turn keeps them: the argument the conversation's space fills
 * (the space service names it) and the presentation keys are no part of them, so a read with the filled argument is
 * the same read as one without.
 */
class AgentPausedTurnRecordReadArgumentsTest extends UnitTestCase
{
    public function testTheArgumentTheSpaceFillsAndThePresentationKeysAreLeftOut(): void
    {
        self::assertSame(
            '{"query":"opening hours"}',
            $this->normaliseArguments(['agentSpaceId' => 7, 'query' => 'opening hours', 'noCache' => true, 'outputFormat' => 'llm'])
        );
        self::assertSame('', $this->normaliseArguments(['agentSpaceId' => 7]));
    }

    public function testAReadWithTheFilledArgumentIsTheSameReadAsOneWithout(): void
    {
        self::assertSame(
            $this->normaliseArguments(['query' => 'opening hours']),
            $this->normaliseArguments(['query' => 'opening hours', 'agentSpaceId' => 7])
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function normaliseArguments(array $arguments): string
    {
        return (string)(new ReflectionMethod(AgentPausedTurnRecord::class, 'normalisedArguments'))->invoke(null, $arguments);
    }
}
