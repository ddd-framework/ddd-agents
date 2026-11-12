<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One failed integrity check of a persisted conversation ({@see AIConversationIntegrityReport::$violations}): which
 * check failed, and on which rows.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationIntegrityViolation extends ValueObject
{
    /** @var string The failed check, one of the `AIConversationIntegrityReport::CHECK_*` constants */
    public string $check = '';

    /** @var string What was found, naming the message indexes it was found on */
    public string $detail = '';

    /**
     * @param string $check one of the `AIConversationIntegrityReport::CHECK_*` constants
     * @param string $detail
     * @return static
     */
    public static function forCheckAndDetail(string $check, string $detail): static
    {
        $integrityViolation = new static();
        $integrityViolation->check = $check;
        $integrityViolation->detail = $detail;
        return $integrityViolation;
    }
}
