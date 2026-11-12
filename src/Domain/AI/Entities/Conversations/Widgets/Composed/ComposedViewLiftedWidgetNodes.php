<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Embedding\WidgetComposedViewNode;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The `Widget` nodes lifted out of one `ComposedView` tree, one per wire path, in reading order.
 *
 * @property ComposedViewLiftedWidgetNode[] $elements
 * @method ComposedViewLiftedWidgetNode[] getElements()
 * @method ComposedViewLiftedWidgetNode|null first()
 */
class ComposedViewLiftedWidgetNodes extends ObjectSet
{
    /** Adds the node lifted from a wire path; a path the set already holds keeps its node. */
    public function addWidgetNodeAtPath(string $path, WidgetComposedViewNode $widgetComposedViewNode): void
    {
        $composedViewLiftedWidgetNode = ComposedViewLiftedWidgetNode::atPath($path, $widgetComposedViewNode);
        $this->add($composedViewLiftedWidgetNode);
    }

    /** @return string[] The wire paths the lifted nodes stood at, in reading order. */
    public function getPaths(): array
    {
        $paths = [];
        foreach ($this->getElements() as $composedViewLiftedWidgetNode) {
            $paths[] = $composedViewLiftedWidgetNode->path;
        }
        return $paths;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
