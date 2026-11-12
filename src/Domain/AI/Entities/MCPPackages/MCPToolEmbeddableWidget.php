<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * The embeddable widgets that show a READ tool's data: a tool that dictates no block
 * of its own still tells the model, under its result, which widget of this data fits inside a `ComposedView` tree and
 * in which size — the `embeds: <size class>` line of a `findWidgets` hit
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Tools\FindWidgetsTool::EMBEDS_LINE}). Rendered only where the model
 * is taught the kit (a person on a screen), never for a widget the result already dictates
 * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::buildEmbeddableWidgetLinesForModel()}). Name widgets
 * whose class is `EMBEDDABLE`; a type the registry does not know or that does not embed renders nothing.
 *
 *   #[MCPTool]
 *   #[MCPToolEffect(MCPToolEffect::READ)]
 *   #[MCPToolEmbeddableWidget(ReviewsSnapshotConversationWidget::WIDGET_TYPE)]
 *   public function listReviews(...): ... {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolEmbeddableWidget
{
    /** @var string[] The wire types of the widgets, in the order they are named. */
    public array $widgetTypes;

    public function __construct(string ...$widgetTypes)
    {
        $this->widgetTypes = array_values($widgetTypes);
    }
}
