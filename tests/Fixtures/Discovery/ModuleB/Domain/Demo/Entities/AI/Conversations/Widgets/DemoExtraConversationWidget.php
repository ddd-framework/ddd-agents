<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\Discovery\ModuleB\Domain\Demo\Entities\AI\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;

/** Fixture (a further module root): a widget type only this module declares. */
class DemoExtraConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'DemoExtra';

    protected function getWidgetFields(): array
    {
        return [];
    }
}
