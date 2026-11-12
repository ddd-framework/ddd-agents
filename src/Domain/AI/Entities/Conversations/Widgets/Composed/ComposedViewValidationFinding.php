<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One thing the reader of a `ComposedView` block dropped or flagged: where in the tree, what code,
 * and a sentence the model can act on. Data validity only — the rest of the tree renders (no gate). The frontend reports the same codes in its render report.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewValidationFinding extends ValueObject
{
    /** A node of an unknown type, in a slot that does not accept it, over a budget, or with three backticks in a string — dropped. */
    public const string CODE_INVALID_NODE = 'INVALID_NODE';

    /** A prop the node type does not declare or a literal of the wrong type — the prop is dropped. */
    public const string CODE_INVALID_PROP = 'INVALID_PROP';

    /** A binding to an undeclared state / computed name, an `$item` outside an item scope, or a malformed binding — the prop is dropped. */
    public const string CODE_UNKNOWN_BINDING = 'UNKNOWN_BINDING';

    /** A computed expression that is not a string, too long or misnamed — dropped. */
    public const string CODE_INVALID_EXPRESSION = 'INVALID_EXPRESSION';

    /** A state name or initial value that is not allowed — dropped. */
    public const string CODE_INVALID_STATE = 'INVALID_STATE';

    /** An action of an unknown type or without its target — dropped. */
    public const string CODE_INVALID_ACTION = 'INVALID_ACTION';

    /** A count or size over {@see ComposedViewBudgets} — the part over it is dropped. */
    public const string CODE_BUDGET_EXCEEDED = 'BUDGET_EXCEEDED';

    /** An inline `{"value": …}` with neither `source` nor `derivedFrom` — kept, rendered without its source line. */
    public const string CODE_INLINE_VALUE_WITHOUT_SOURCE = 'INLINE_VALUE_WITHOUT_SOURCE';

    /**
     * The initial value of the state a Tabs, Select or RadioGroup sets is none of its choices — kept; the control starts
     * with no choice picked.
     */
    public const string CODE_INVALID_INITIAL_CHOICE = 'INVALID_INITIAL_CHOICE';

    /** A state name nothing reads besides the control that sets it — kept; the control changes nothing on screen. */
    public const string CODE_UNREAD_STATE = 'UNREAD_STATE';

    /** An `$item` field that no row of the resolved `$from` rows has — kept; it renders empty. */
    public const string CODE_UNKNOWN_ITEM_KEY = 'UNKNOWN_ITEM_KEY';

    /**
     * A slot holds fewer nodes than its declared minimum (an empty Repeat, Pressable or Carousel) — the node is kept and
     * renders what it has; a child dropped from the slot carries its own `INVALID_NODE`.
     */
    public const string CODE_INVALID_SLOT_NODE_COUNT = 'INVALID_SLOT_NODE_COUNT';

    /**
     * A `Widget` node whose widget does not embed (or needs a larger slot) — LIFTED out of the tree, never dropped:
     * the widget is shown as its own block after the view (the row's text is rewritten before it is
     * stored, {@see \DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks::buildMarkdownWithLiftedWidgetBlocks()}),
     * and a layout node left empty by the lift goes with it.
     */
    public const string CODE_WIDGET_LIFTED = 'WIDGET_LIFTED';

    /**
     * @var string[] The codes of findings that drop nothing: the block renders and its render report is `mounted` with
     * the code as a note the model reads.
     */
    public const array NOTE_CODES = [self::CODE_INLINE_VALUE_WITHOUT_SOURCE, self::CODE_INVALID_INITIAL_CHOICE, self::CODE_UNREAD_STATE, self::CODE_UNKNOWN_ITEM_KEY, self::CODE_INVALID_SLOT_NODE_COUNT, self::CODE_WIDGET_LIFTED];

    /** @var string One of the CODE_* constants. */
    public string $code = '';

    /** @var string Where in the block (`view.children[2].slots.header[0].data`). */
    public string $path = '';

    /** @var string What was wrong and what happened to it. */
    public string $message = '';

    public static function create(string $code, string $path, string $message): static
    {
        $composedViewValidationFinding = new static();
        $composedViewValidationFinding->code = $code;
        $composedViewValidationFinding->path = $path;
        $composedViewValidationFinding->message = $message;
        return $composedViewValidationFinding;
    }

    /** One line: `INVALID_NODE at view.children[2]: …`. */
    public function toLine(): string
    {
        return $this->code . ' at ' . $this->path . ': ' . $this->message;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->code . '@' . $this->path . '@' . md5($this->message));
    }
}
