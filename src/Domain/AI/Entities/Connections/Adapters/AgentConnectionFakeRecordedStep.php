<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One outward step the FAKE adapter received (tests and evals read them instead of a provider).
 */
class AgentConnectionFakeRecordedStep extends ValueObject
{
    /** @var string The adapter method, e.g. sendMail, createCalendarEvent, postChatMessage */
    public string $operation = '';

    /** @var string The JSON of the value object the step carried */
    public string $payloadJson = '';

    /** @param mixed $payload a serialized value object or a list of fields */
    public static function of(string $operation, mixed $payload): static
    {
        $recordedStep = new static();
        $recordedStep->operation = $operation;
        $recordedStep->payloadJson = (string)json_encode($payload instanceof ValueObject ? $payload->toObject(false) : $payload);
        return $recordedStep;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        $payload = json_decode($this->payloadJson, true);
        return is_array($payload) ? $payload : [];
    }
}
