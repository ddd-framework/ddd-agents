<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNames;

/**
 * The names of an application whose stored rows and client call the widget's key `shopId` and mark a click on
 * the widget of another space with ` (for shop N)` — the names the recorded tables of this test suite carry.
 */
class WidgetBlockFieldNamesOfAnApplicationWithItsOwnNames extends WidgetBlockFieldNames
{
    public const string WIDGET_SELF_HYDRATION_KEY = 'shopId';

    public const ?string OWNER_TEXT_SUFFIX_PATTERN_OF_ANOTHER_SPACE = '/ \(for shop (\d+)\)$/';
}
