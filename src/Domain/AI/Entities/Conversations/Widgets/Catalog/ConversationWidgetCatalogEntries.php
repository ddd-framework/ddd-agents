<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Catalog;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Widget catalog entries — the whole catalog sorted by type, or the matches of one query in rank order.
 *
 * @property ConversationWidgetCatalogEntry[] $elements
 * @method ConversationWidgetCatalogEntry[] getElements()
 * @method ConversationWidgetCatalogEntry|null first()
 */
class ConversationWidgetCatalogEntries extends ObjectSet
{
    /** The entry of `$widgetType`; null when the set has none. */
    public function findByWidgetType(string $widgetType): ?ConversationWidgetCatalogEntry
    {
        foreach ($this->getElements() as $conversationWidgetCatalogEntry) {
            if ($conversationWidgetCatalogEntry->widgetType === $widgetType) {
                return $conversationWidgetCatalogEntry;
            }
        }
        return null;
    }

    /** @return string[] the wire types, in set order */
    public function getWidgetTypes(): array
    {
        $widgetTypes = [];
        foreach ($this->getElements() as $conversationWidgetCatalogEntry) {
            $widgetTypes[] = $conversationWidgetCatalogEntry->widgetType;
        }
        return $widgetTypes;
    }
}
