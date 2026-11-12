<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What {@see AskOwnerTool::checkArguments()} found in an `askOwner` call's arguments (formerly
 * an array shape): the corrective feedback when the call is malformed, else the normalized question, and the reason
 * a catalogue key was dropped (the question is then recorded as a free one).
 */
class AskOwnerArgumentsCheck extends ValueObject
{
    /** @var string[] Why the call is malformed; empty when the question stands. */
    public array $feedback = [];

    /** Why a catalogue key was dropped (the question was recorded as a free one); null when none was. */
    public ?string $keyDropped = null;

    /** The normalized question; null when the call is malformed. */
    public ?AskOwnerQuestion $question = null;
}
