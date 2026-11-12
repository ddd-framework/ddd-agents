<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The closed question an `askOwner` call records (formerly an array shape): the question text,
 * its 2–4 options (label + optional description), single or multiple choice, and — for a catalogue question the
 * platform knows — the key with one meaning per option ({@see AskOwnerQuestionCatalogue}).
 */
class AskOwnerQuestion extends ValueObject
{
    public string $question = '';

    /** @var AskOwnerQuestionOption[] The 2–4 answers, in the order they are shown */
    public array $options = [];

    public bool $multiSelect = false;

    public ?string $questionKey = null;

    /** @var string[]|null One catalogue meaning per option, same order; null for a free question. */
    public ?array $optionMeanings = null;

    /** The same question as a FREE one: no catalogue key, no meanings — nothing is recorded from the click. */
    public function withoutCatalogueKey(): self
    {
        $freeQuestion = clone $this;
        $freeQuestion->questionKey = null;
        $freeQuestion->optionMeanings = null;
        return $freeQuestion;
    }

    /** @return string[] The labels of the options, in their order */
    public function getOptionLabels(): array
    {
        return array_map(static fn (AskOwnerQuestionOption $option): string => $option->label, $this->options);
    }

    /** @return string[] The descriptions of the options, in their order; null where an option has none */
    public function getOptionDescriptions(): array
    {
        return array_map(static fn (AskOwnerQuestionOption $option): ?string => $option->description, $this->options);
    }
}
