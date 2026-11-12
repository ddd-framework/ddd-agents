<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\Discovery\ModuleC\Domain\Other\Entities\AI\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;

/** Fixture (a third module root): declares module B's widget type without extending it — an unrelated duplicate. */
class DemoExtraConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'DemoExtra';

    protected function getWidgetFields(): array
    {
        return [];
    }
}
