<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A result that says the tool rendered nothing is no evidence for a plan item — with the marker the module writes
 * and with the former wording a stored result still carries.
 */
class ToolCallNothingProposedMarkerTest extends UnitTestCase
{
    public function testAResultWithTheMarkerIsNoEvidence(): void
    {
        $toolCall = new ToolCall();
        $toolCall->result = 'No candidate found. ' . ToolCall::RESULT_MARKER_NOTHING_PROPOSED;
        self::assertTrue($toolCall->carriesProposalOrUnacceptedClaimMarker());
        self::assertTrue($toolCall->wasDowngradedToProposal());
    }

    public function testAStoredResultWithTheFormerWordingIsStillNoEvidence(): void
    {
        $toolCall = new ToolCall();
        $toolCall->result = 'No candidate found. ' . ToolCall::LEGACY_RESULT_MARKER_NOTHING_PROPOSED;
        self::assertTrue($toolCall->carriesProposalOrUnacceptedClaimMarker());
    }

    public function testAnOrdinaryResultIsEvidence(): void
    {
        $toolCall = new ToolCall();
        $toolCall->result = 'Saved.';
        self::assertFalse($toolCall->carriesProposalOrUnacceptedClaimMarker());
    }

    public function testTheModuleWritesTheWidgetWording(): void
    {
        self::assertStringContainsString('no widget rendered', ToolCall::RESULT_MARKER_NOTHING_PROPOSED);
        self::assertNotSame(ToolCall::RESULT_MARKER_NOTHING_PROPOSED, ToolCall::LEGACY_RESULT_MARKER_NOTHING_PROPOSED);
    }
}
