<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Catalog;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * How the model reaches each widget one `findWidgets` call found, in the call's rank order.
 *
 * @property FoundWidgetReach[] $elements
 * @method FoundWidgetReach[] getElements()
 * @method FoundWidgetReach|null first()
 */
class FoundWidgetReaches extends ObjectSet
{
    /** The reach of `$widgetType`; null when the call did not find it. */
    public function findByWidgetType(string $widgetType): ?FoundWidgetReach
    {
        foreach ($this->getElements() as $foundWidgetReach) {
            if ($foundWidgetReach->widgetType === $widgetType) {
                return $foundWidgetReach;
            }
        }
        return null;
    }
}
