<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Embedding\WidgetComposedViewNode;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * A `Widget` node that was lifted out of a `ComposedView` tree: the wire path it stood at and the node itself. It is
 * shown as its own widget block after the view.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewLiftedWidgetNode extends ValueObject
{
    /** @var string The wire path the node stood at, e.g. `view.children[0]`. */
    public string $path;

    /** @var WidgetComposedViewNode The lifted node. */
    public WidgetComposedViewNode $widgetComposedViewNode;

    public static function atPath(string $path, WidgetComposedViewNode $widgetComposedViewNode): static
    {
        $composedViewLiftedWidgetNode = new static();
        $composedViewLiftedWidgetNode->path = $path;
        $composedViewLiftedWidgetNode->widgetComposedViewNode = $widgetComposedViewNode;
        return $composedViewLiftedWidgetNode;
    }

    public function uniqueKey(): string
    {
        return self::class . '_' . $this->path;
    }
}
