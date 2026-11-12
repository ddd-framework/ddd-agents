<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\Discovery\ModuleB\Domain\Demo\Entities\AI\Conversations\SignalSources;

use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSourceDefaultAnswers;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSpeaker;

/** Fixture (a further module root): one signal source value only this module writes. */
enum DemoConversationSignalSource: string implements ConversationSignalSource
{
    use ConversationSignalSourceDefaultAnswers;

    case DEMO_FIXTURE_SIGNAL = 'DEMO_FIXTURE_SIGNAL';

    public function getSpeaker(): ConversationSignalSpeaker
    {
        return ConversationSignalSpeaker::PLATFORM;
    }
}
