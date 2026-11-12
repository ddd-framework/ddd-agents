<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One widget a tool wrote into the chat during a turn, as the platform's record of that turn lists it
 * ({@see AgentPausedTurnRecord::$widgets}).
 *
 * @phpstan-consistent-constructor
 */
class AgentPausedTurnRecordedWidget extends ValueObject
{
    /** @var string The widget's type as the tool wrote it */
    public string $widgetType = '';

    /** @var int The index of the tool-written message in the conversation */
    public int $messageIndex = 0;

    /**
     * @param string $widgetType
     * @param int $messageIndex
     * @return static
     */
    public static function forWidgetOfMessage(string $widgetType, int $messageIndex): static
    {
        $recordedWidget = new static();
        $recordedWidget->widgetType = $widgetType;
        $recordedWidget->messageIndex = $messageIndex;
        return $recordedWidget;
    }
}
