<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One line of the conversation's state in a resume diagnosis ({@see AIConversationResumeDiagnosis::$stateRows}): the
 * name of a stored value and the value as it is printed.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationResumeDiagnosisStateRow extends ValueObject
{
    /** @var string The name of the stored value (`runState`, `runHeartbeatAt`, `resumeAttempts`, …) */
    public string $label = '';

    /** @var string The value as it is printed; `—` where nothing is stored */
    public string $value = '';

    /**
     * @param string $label
     * @param string $value
     * @return static
     */
    public static function forLabelAndValue(string $label, string $value): static
    {
        $stateRow = new static();
        $stateRow->label = $label;
        $stateRow->value = $value;
        return $stateRow;
    }
}
