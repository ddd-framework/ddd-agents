<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;
use JsonException;
use stdClass;

/**
 * The stored leaf form `::Name{payload='…' <key field>=…}` (what `normalize-server-message-to-markdown.ts` produces from
 * server `component` parts; stored rows of the legacy era carry `::NameDirective{…}`). The attributes are read without
 * evaluating them or inventing inputs and re-encoded as the block's JSON: the payload's fields, `type` = the name,
 * the self-hydration key from the attribute the application names ({@see WidgetBlockFieldNames}; a string, as the
 * attribute form delivers it). Unreadable attributes give a
 * `MALFORMED` block.
 */
class WidgetLeafSyntaxParser implements WidgetBlockSyntaxParser
{
    public function readBlockAtLine(WidgetBlockSourceText $widgetBlockSourceText, int $lineIndex): ?WidgetBlockSyntaxMatch
    {
        $line = $widgetBlockSourceText->getLine($lineIndex);
        if (!preg_match('/^[ \t]*(:{2,3})([A-Za-z][\w]*)([^\r\n]*)/', $line, $directive) || $directive[1] !== '::') {
            return null;
        }
        $widgetJson = static::decodeLeafAttributes($directive[2], trim($directive[3]));
        return WidgetBlockSyntaxMatch::forBlock($lineIndex, $widgetBlockSourceText->getLineStart($lineIndex), strlen($line), WidgetBlock::SYNTAX_LEAF, $widgetJson ?? '',
            $widgetJson === null ? WidgetBlock::OUTCOME_MALFORMED : null);
    }

    public function getMessageTextMarkers(): array
    {
        return ['::'];
    }

    /** The leaf's attributes as the block's JSON; null when they are not the readable `{payload=… <key field>=…}` form. */
    public static function decodeLeafAttributes(string $widgetName, string $attributes): ?string
    {
        if ($attributes === '') {
            return json_encode(['type' => $widgetName], JSON_THROW_ON_ERROR);
        }
        if (!str_starts_with($attributes, '{') || !str_ends_with($attributes, '}')) {
            return null;
        }
        $remaining = trim(substr($attributes, 1, -1));
        $widgetSelfHydrationKeyFieldName = WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName();
        $decodedAttributes = [];
        while ($remaining !== '') {
            if (!preg_match('/^([A-Za-z][\w]*)\s*=\s*(?:"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'|([^\s}]+))\s*/s', $remaining, $attribute, PREG_UNMATCHED_AS_NULL)) {
                return null;
            }
            if (!in_array($attribute[1], ['payload', $widgetSelfHydrationKeyFieldName], true) || isset($decodedAttributes[$attribute[1]])) {
                return null;
            }
            $decodedAttributes[$attribute[1]] = html_entity_decode($attribute[2] ?? $attribute[3] ?? $attribute[4], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $remaining = substr($remaining, strlen($attribute[0]));
        }
        try {
            $fields = json_decode($decodedAttributes['payload'] ?? '{}', false, 32, JSON_THROW_ON_ERROR);
            if (!$fields instanceof stdClass) {
                return null;
            }
            $fields->type = $widgetName;
            if (isset($decodedAttributes[$widgetSelfHydrationKeyFieldName])) {
                $fields->{$widgetSelfHydrationKeyFieldName} = $decodedAttributes[$widgetSelfHydrationKeyFieldName];
            }
            return json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return null;
        }
    }
}
