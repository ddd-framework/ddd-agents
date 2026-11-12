<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * ONE concrete tool-call response occurrence for the tool-response drill-down (the per-tool view of an application's
 * debug console command): which message/conversation produced a response of which size — the pointer to the REAL
 * payload one inspects to decide the optimisation (`toLlmMarkdown`, Hide, TOON, query-level scoping). Element of
 * {@see AgentToolResponseSamples}, largest result first.
 */
class AgentToolResponseSample extends ValueObject
{
    /** @var int The tool-call message's id (the application's debug command dumps its arguments and result by it). */
    public int $messageId;

    /** @var int The conversation the call happened in (the application's inspect command shows it). */
    public int $conversationId;

    /** @var DateTime|null When the call happened. */
    public ?DateTime $created = null;

    /** @var int The tool result's character length. */
    public int $resultChars;

    /** @var int The tool arguments' character length. */
    public int $argumentsChars;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->messageId ?? 0);
    }
}
