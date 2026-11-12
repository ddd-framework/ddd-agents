<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

/**
 * THE writer of a widget block: every dictation to the model, every model-facing example and
 * every tool- or platform-written row is this ```widget fence with the FLAT JSON fields next to `type` — the frontend's
 * fence plugin strips `type` and hands every other key to the widget as its payload, so a nested `payload` wrapper would
 * arrive as `payload.payload`. The widget's self-hydration key follows `type` when known, under the field name the
 * application chose ({@see WidgetBlockFieldNames}); the extra fields come after and lose to both on a
 * key collision. This fence is the one written and stored form (decided by a measurement); the
 * older stored forms are read by their parsers ({@see WidgetBlockSyntaxParser}), never written. Reads nothing.
 */
class WidgetBlockSerializer
{
    /**
     * @param array<string, mixed> $extraPayloadFields Further flat payload fields, merged after the type and the key.
     */
    public static function buildFenceBlock(string $widgetType, ?int $widgetSelfHydrationKey = null, array $extraPayloadFields = []): string
    {
        $fencePayloadFields = ['type' => $widgetType];
        if ($widgetSelfHydrationKey !== null) {
            $fencePayloadFields[WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName()] = $widgetSelfHydrationKey;
        }
        $fencePayloadFields += $extraPayloadFields;
        return "```widget\n"
            . json_encode($fencePayloadFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n```";
    }
}
