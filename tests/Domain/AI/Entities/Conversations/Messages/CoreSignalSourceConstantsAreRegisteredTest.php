<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSpeaker;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every core `SIGNAL_SOURCE_*` constant of the message names a case of the core signal-source enum: a value written
 * without a case answers every signal-source question with "unknown", and an application's membership test sees it on
 * one side only.
 */
class CoreSignalSourceConstantsAreRegisteredTest extends TestCase
{
    public function testEveryCoreSignalSourceConstantIsACaseOfTheCoreEnum(): void
    {
        $constantsWithoutCase = [];
        foreach ((new ReflectionClass(AIConversationMessage::class))->getReflectionConstants() as $reflectionConstant) {
            if (!str_starts_with($reflectionConstant->getName(), 'SIGNAL_SOURCE_')) {
                continue;
            }
            $storedValue = $reflectionConstant->getValue();
            if (!is_string($storedValue) || AIConversationSignalSource::tryFrom($storedValue) === null) {
                $constantsWithoutCase[] = $reflectionConstant->getName();
            }
        }
        $this->assertSame([], $constantsWithoutCase);
    }

    public function testAConnectionApprovalIsAPlatformStatementThatStartsNoTurn(): void
    {
        $connectionApproval = AIConversationSignalSource::CONNECTION_APPROVAL;
        $this->assertSame(ConversationSignalSpeaker::PLATFORM, $connectionApproval->getSpeaker());
        $this->assertFalse($connectionApproval->startsATurn());
        $this->assertFalse($connectionApproval->isPostedByTheClient());
        $this->assertFalse($connectionApproval->carriesAnOwnerStatement());
        $this->assertTrue($connectionApproval->owesWork());
    }
}
