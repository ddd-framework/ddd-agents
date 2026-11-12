<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTone;

/**
 * The closed value lists the data nodes share: how a number is shown (`format`), which judgement
 * colour a value or icon takes (`tone`) and how a table column aligns (`align`). One list per concept, so `KpiTile`,
 * `Chart`, `Table` and `TableCell` accept the same words and the frontend formats them the same way
 * (`components/data/data-value-format.ts`). Not a node class — the node registry reads only `*ComposedViewNode.php`.
 */
class ComposedViewDataValueFormat
{
    /** a plain number in the owner's locale (`1.207`) */
    public const string FORMAT_NUMBER = 'number';
    /** a number with a percent sign; the value is the percentage itself (`36` → `36 %`) */
    public const string FORMAT_PERCENT = 'percent';
    /** an amount in the `currency` of the node (ISO code, default EUR) */
    public const string FORMAT_CURRENCY = 'currency';
    /** a short number (`39.5M`, `5.1K`) */
    public const string FORMAT_COMPACT = 'compact';
    /** a calendar date (`2026-10-25` → the owner's date form) */
    public const string FORMAT_DATE = 'date';
    /** the value as written */
    public const string FORMAT_TEXT = 'text';

    /** @var string[] */
    public const array FORMATS = [self::FORMAT_NUMBER, self::FORMAT_PERCENT, self::FORMAT_CURRENCY, self::FORMAT_COMPACT, self::FORMAT_DATE, self::FORMAT_TEXT];

    /** @var string[] the judgement a value or icon carries — the kit's one tone list ({@see ComposedViewTone}), the older words included */
    public const array TONES = ComposedViewTone::ACCEPTED_WORDS;

    /** @var string[] how a column or cell aligns; numbers read best at `end` */
    public const array ALIGNS = ['start', 'center', 'end'];
}
