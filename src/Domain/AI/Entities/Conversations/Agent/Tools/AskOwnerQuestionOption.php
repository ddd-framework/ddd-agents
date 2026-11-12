<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One answer the owner can pick in an `askOwner` question ({@see AskOwnerQuestion::$options}): the label the click
 * posts, and an optional description shown under it.
 *
 * @phpstan-consistent-constructor
 */
class AskOwnerQuestionOption extends ValueObject
{
    /** @var string The answer as the owner reads it and as the click posts it */
    public string $label = '';

    /** @var string|null The line under the label; null when the option has none */
    public ?string $description = null;

    /**
     * @param string $label
     * @param string|null $description
     * @return static
     */
    public static function forLabelAndDescription(string $label, ?string $description): static
    {
        $askOwnerQuestionOption = new static();
        $askOwnerQuestionOption->label = $label;
        $askOwnerQuestionOption->description = $description;
        return $askOwnerQuestionOption;
    }
}
