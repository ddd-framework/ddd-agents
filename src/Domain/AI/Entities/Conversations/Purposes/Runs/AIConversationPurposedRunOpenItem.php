<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\Runs;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One still OPEN leaf of a purposed run's task plan, as a population read lists it: its key, its text cut to
 * {@see MAX_TEXT_CHARS}, its done-criterion key and whether that key is a gate of the run's purpose (a key the purpose
 * registers no class for is NO gate — a legacy key).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationPurposedRunOpenItem extends ValueObject
{
    /** The longest item text a population row carries. */
    public const int MAX_TEXT_CHARS = 60;

    /** The plan item's stable key (e.g. `2.3`). */
    public ?string $key = null;

    /** The item's text, cut to {@see MAX_TEXT_CHARS}. */
    public string $text = '';

    /** The machine done-criterion key of the leaf, or null when the agent's tick alone decides it. */
    public ?string $doneCriterion = null;

    /** True when the purpose registers a criterion class for {@see $doneCriterion} (a gate); false = legacy key, no gate; null without a key. */
    public ?bool $doneCriterionGates = null;

    public static function fromItem(?string $key, string $itemText, ?string $doneCriterion, ?bool $doneCriterionGates): static
    {
        $openItem = new static();
        $openItem->key = $key;
        $itemText = trim(preg_replace('/\s+/', ' ', $itemText) ?? $itemText);
        $openItem->text = mb_strlen($itemText) > self::MAX_TEXT_CHARS ? mb_substr($itemText, 0, self::MAX_TEXT_CHARS - 1) . '…' : $itemText;
        $openItem->doneCriterion = $doneCriterion;
        $openItem->doneCriterionGates = $doneCriterion !== null ? $doneCriterionGates : null;
        return $openItem;
    }

    /** `2.3 Workshop address … [workshop.address]` — the criterion marked `no gate` when it is a legacy key. */
    public function toLlmMarkdownLine(): string
    {
        $criterionLabel = '';
        if ($this->doneCriterion !== null) {
            $criterionLabel = ' [' . $this->doneCriterion . ($this->doneCriterionGates ? '' : ', no gate') . ']';
        }
        return trim(($this->key ?? '?') . ' ' . $this->text) . $criterionLabel;
    }
}
