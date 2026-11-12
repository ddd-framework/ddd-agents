<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\AgentSpaces;

use DDD\Domain\Base\Entities\ValueObject;
use Exception;

/**
 * The time zone an agent works in for a space, as {@see AgentSpace::findTimezone()} answers it: an IANA identifier
 * and whether it is confirmed. Held in memory by the conversation as its zone snapshot; never persisted.
 *
 * @phpstan-consistent-constructor
 */
class AgentSpaceTimezone extends ValueObject
{
    /** @var string The IANA identifier the conversation thinks in (e.g. `Europe/Berlin`) */
    public string $identifier;

    /** @var bool True when the identifier is a default nobody confirmed (the space has no stored zone) */
    public bool $unverified = true;

    /**
     * @param string $identifier an IANA identifier
     * @param bool $unverified true = a default nobody confirmed
     * @return static|null null when `$identifier` is no known zone
     */
    public static function ofKnownIdentifier(string $identifier, bool $unverified): ?static
    {
        try {
            new \DateTimeZone($identifier);
        } catch (Exception) {
            return null;
        }
        $agentSpaceTimezone = new static();
        $agentSpaceTimezone->identifier = $identifier;
        $agentSpaceTimezone->unverified = $unverified;
        return $agentSpaceTimezone;
    }

    /**
     * The zone as a PHP time zone object. Pure.
     *
     * @return \DateTimeZone
     * @throws Exception when the identifier is not a known IANA zone
     */
    public function getTimezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->identifier);
    }
}
