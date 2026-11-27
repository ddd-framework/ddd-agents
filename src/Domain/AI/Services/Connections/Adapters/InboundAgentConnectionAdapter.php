<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionInbounds;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\UnauthorizedException;

/** Inbound of a provider: verify a delivery (signature, age) and parse it into typed events. */
interface InboundAgentConnectionAdapter
{
    /**
     * @param array<string, string> $headers lower-case header name => value
     * @param DateTime $receivedAt when the delivery arrived (the age check of the signature)
     * @throws UnauthorizedException a delivery that fails the verification
     */
    public function verifyAndParseDelivery(array $headers, string $rawBody, DateTime $receivedAt): AgentConnectionInbounds;
}
