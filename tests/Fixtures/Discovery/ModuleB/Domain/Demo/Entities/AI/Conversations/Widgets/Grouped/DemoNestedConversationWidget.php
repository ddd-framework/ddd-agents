<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\Discovery\ModuleB\Domain\Demo\Entities\AI\Conversations\Widgets\Grouped;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;

/** Fixture (a further module root): a widget in a subfolder of the widget folder. */
class DemoNestedConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'DemoNested';

    protected function getWidgetFields(): array
    {
        return [];
    }
}
