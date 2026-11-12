<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The working-state slices the turn-start sites persist inside a collected stretch land in ONE
 * write at its end, with each key's final value; outside a stretch every persist writes at once; a dropped stretch
 * writes nothing.
 */
class AIConversationsServiceCollectedSliceWritesTest extends UnitTestCase
{
    public function testTheSlicesOfACollectedStretchLandInOneWriteWithTheirFinalValues(): void
    {
        $aIConversationsService = self::instantiateWithoutConstructor(AIConversationsServiceRecordingSliceWrites::class);
        $conversation = $this->conversationWithWorkingState();

        $aIConversationsService->collectAgentWorkingStateSliceWrites($conversation);
        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'dayContextText');
        $conversation->agentWorkingState->dayContextText = 'final';
        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'turnMinimumSteps', 'dayContextText');
        self::assertSame([], $aIConversationsService->writtenSlices, 'nothing is written inside the stretch');
        $aIConversationsService->writeCollectedAgentWorkingStateSlices($conversation);

        self::assertCount(1, $aIConversationsService->writtenSlices);
        self::assertSame(['dayContextText', 'turnMinimumSteps'], array_keys($aIConversationsService->writtenSlices[0]));
        self::assertSame('final', $aIConversationsService->writtenSlices[0]['dayContextText']);

        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'dayContextText');
        self::assertCount(2, $aIConversationsService->writtenSlices, 'after the stretch a persist writes at once');
    }

    public function testADroppedStretchWritesNothing(): void
    {
        $aIConversationsService = self::instantiateWithoutConstructor(AIConversationsServiceRecordingSliceWrites::class);
        $conversation = $this->conversationWithWorkingState();

        $aIConversationsService->collectAgentWorkingStateSliceWrites($conversation);
        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'dayContextText');
        $aIConversationsService->writeCollectedAgentWorkingStateSlices($conversation, false);

        self::assertSame([], $aIConversationsService->writtenSlices);
    }

    protected function conversationWithWorkingState(): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->id = 912950;
        $conversation->agentWorkingState = new AgentWorkingState();
        $conversation->agentWorkingState->dayContextText = 'first';
        return $conversation;
    }
}
