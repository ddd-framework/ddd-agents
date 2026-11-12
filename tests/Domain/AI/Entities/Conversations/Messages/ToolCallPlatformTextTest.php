<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The corrective notice of a call refused as a no-progress repeat is the platform's own text and is never
 * screened for injections; every executed result is.
 */
class ToolCallPlatformTextTest extends UnitTestCase
{
    public function testARepeatRefusalHoldsOnlyPlatformText(): void
    {
        $toolCall = new ToolCall();
        $toolCall->wasRefusedAsDuplicate = true;
        self::assertTrue($toolCall->holdsOnlyPlatformText());
    }

    public function testAnExecutedCallHoldsThirdPartyText(): void
    {
        self::assertFalse((new ToolCall())->holdsOnlyPlatformText());
    }
}
