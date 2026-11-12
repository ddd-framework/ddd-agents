<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * An OWNER-GATE refusal: a write a tool refuses because the owner has not yet said what it needs (the
 * confirmation of a found record, the draft's nod). The agent loop recognises the prefix in the failed result and
 * forces the next step to be TEXT — the only correct move after such a refusal is to present / ask and end the turn;
 * without the forced text step the model retries the same write many times.
 */
class OwnerGateRefusal
{
    public const string PREFIX = 'REFUSED — ';

    public static function because(string $reason): BadRequestException
    {
        return new BadRequestException(self::PREFIX . $reason);
    }

    public static function isRefusalResult(?string $resultContent): bool
    {
        return $resultContent !== null && str_contains($resultContent, self::PREFIX);
    }
}
