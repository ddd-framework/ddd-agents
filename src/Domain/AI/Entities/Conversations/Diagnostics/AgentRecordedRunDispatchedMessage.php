<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE Messenger message a recorded or replayed run dispatched: its class, its serialized body and what running it in
 * the harness did. The harness that runs the recorded-run comparison builds it; {@see AgentRecordedRunComparison::buildDispatchSnapshot()}
 * turns a list of them into the comparable dispatch section.
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunDispatchedMessage extends ValueObject
{
    /** @var string The fully qualified class of the dispatched message */
    public string $messageClass = '';

    /** @var string The message body as dispatched (JSON in every normal case) */
    public string $body = '';

    /** @var string|null What running the message in the harness did; null when nothing was recorded */
    public ?string $outcome = null;

    /**
     * @param string $messageClass
     * @param string $body
     * @param string|null $outcome
     * @return static
     */
    public static function forMessageClassAndBody(string $messageClass, string $body, ?string $outcome = null): static
    {
        $agentRecordedRunDispatchedMessage = new static();
        $agentRecordedRunDispatchedMessage->messageClass = $messageClass;
        $agentRecordedRunDispatchedMessage->body = $body;
        $agentRecordedRunDispatchedMessage->outcome = $outcome;
        return $agentRecordedRunDispatchedMessage;
    }

    /** @return string The message class without its namespace — the name a difference shows */
    public function getShortClassName(): string
    {
        return substr((string)strrchr('\\' . $this->messageClass, '\\'), 1);
    }
}
