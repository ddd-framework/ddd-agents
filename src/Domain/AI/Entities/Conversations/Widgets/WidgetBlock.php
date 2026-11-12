<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\Base\Entities\ValueObject;
use stdClass;

/**
 * One widget block in a message's text — the PARSED-BLOCK ENVELOPE: where it stands
 * (byte span, occurrence order), the syntax it was written in, whether its fence is closed, whether it parses, and —
 * when its type resolves — the typed widget ({@see buildWidget()}). A block that is no widget (a container, an unknown
 * type, a malformed payload) still exists and counts for the ordinals. Readers that ask "which widget" ask the widget;
 * readers that ask about the text ask the envelope. Built only by {@see WidgetBlocks::fromMarkdown()} through one parser
 * per syntax; reads nothing. A widget instance is its host message, its canonical type and its position among the blocks
 * of that type in the message ({@see $ordinalAmongBlocksOfType}).
 */
class WidgetBlock extends ValueObject
{
    /** A fenced block (``` or ~~~, any length ≥ 3) whose info string starts with `widget`. */
    public const string SYNTAX_FENCE = 'FENCE';

    /** The bare `widget {…}` paragraph, or the one-line code span ```` ```widget {…}``` ```` the frontend reads as one. */
    public const string SYNTAX_BARE = 'BARE';

    /** The stored leaf form `::Name{payload='…' <key field>=…}`. */
    public const string SYNTAX_LEAF = 'LEAF';

    /** A `:::Name … :::` container — recognised, never parsed. */
    public const string SYNTAX_CONTAINER = 'CONTAINER';

    /**
     * A field a few stored `ComposedView` fences carry (eval conversations that measured a second written form, since
     * removed). Nothing writes it; the readers that count or show a block's fields
     * drop it ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading::measureSourceBytes()},
     * {@see WidgetBlocks::buildModelFacingText()}), every other reader ignores it as an unknown field.
     */
    public const string LEGACY_WRITTEN_SYNTAX_FIELD = 'writtenSyntax';

    /** The block holds a JSON object with a string `type`. */
    public const string OUTCOME_VALID = 'VALID';

    /** An unreadable leaf, invalid JSON or no string `type`. */
    public const string OUTCOME_MALFORMED = 'MALFORMED';

    /** A container, which no reader supports. */
    public const string OUTCOME_UNSUPPORTED_SYNTAX = 'UNSUPPORTED_SYNTAX';

    /** @var int Zero-based occurrence among the message's blocks, message order. */
    public int $ordinal = 0;

    /**
     * @var int Zero-based occurrence among the message's VALID blocks of the same type, message order — the type read
     * without the legacy `…Directive` suffix ({@see getWidgetTypeWithoutLegacySuffix()}), so `X` and `XDirective` count
     * as one type. 0 for a block that is not VALID.
     */
    public int $ordinalAmongBlocksOfType = 0;

    /** @var int Byte offset of the block's first line in the original UTF-8 text. */
    public int $start = 0;

    /**
     * @var int Number of source bytes the block spans (its lines, line endings included) — a fence the frontend repairs
     * starts at a glued opener's backticks or ends right after its JSON object, mid-line.
     */
    public int $length = 0;

    /** @var string One of the SYNTAX_* constants. */
    public string $syntax = '';

    /**
     * @var bool Whether the fence renders closed: it found its closer line, or (a ``` widget fence) its JSON object
     * balances and the frontend splices the closer after it. An unclosed fence runs to the end of the text — the model
     * was cut off mid-payload; whether that counts is the reader's policy (the report email falls back). Always true
     * for the other syntaxes.
     */
    public bool $isClosed = true;

    /** @var string The block's JSON as written (a leaf's attributes re-encoded); empty for a container or an unreadable leaf. */
    public string $widgetJson = '';

    /** @var string One of the OUTCOME_* constants. */
    public string $outcome = '';

    /** @var stdClass|null The decoded block (depth 32) when VALID, decoded once by the parser. */
    protected ?stdClass $fields = null;

    /** The decoded block when it is VALID, else null — a deep copy per call, so a reader may change it freely. */
    public function getFields(): ?stdClass
    {
        if ($this->fields === null) {
            return null;
        }
        /** @var stdClass $fields */
        $fields = unserialize(serialize($this->fields));
        return $fields;
    }

    /**
     * The block's JSON decoded to an associative array (nested objects as arrays), whatever the outcome — null when the
     * JSON is not an object or array. The one decode readers use instead of their own `json_decode($widgetJson, true)`.
     *
     * @return array<mixed>|null
     */
    public function getFieldsAsArray(): ?array
    {
        $decodedFields = json_decode($this->widgetJson, true);
        return is_array($decodedFields) ? $decodedFields : null;
    }

    /**
     * The class the registry resolves the written `type` to ({@see ConversationWidgetClasses::getWidgetClassForWidgetType()});
     * null for a block that is not VALID or names no known type.
     *
     * @return class-string<ConversationWidget>|null
     */
    public function getWidgetClass(): ?string
    {
        $writtenWidgetType = $this->getWrittenWidgetType();
        return $writtenWidgetType !== null ? ConversationWidgetClasses::getWidgetClassForWidgetType($writtenWidgetType) : null;
    }

    /** The typed widget the block stands for, restored through its class ({@see ConversationWidget::fromWidgetBlock()}); null without a class. */
    public function buildWidget(): ?ConversationWidget
    {
        $widgetClass = $this->getWidgetClass();
        if ($widgetClass === null) {
            return null;
        }
        /** @var ConversationWidget $conversationWidget a static factory called through the class-string */
        $conversationWidget = $widgetClass::fromWidgetBlock($this);
        return $conversationWidget;
    }

    /**
     * Whether the block's payload carries every field of `$requiredFilledFields` FILLED (the promise-aware
     * handover gate): a leaf is filled when it is a non-empty scalar whose trimmed string form is not a
     * whole-value `<placeholder>` nor the skeleton's placeholder without its brackets; an array skeleton value requires
     * the payload value to be an array with each sub-key filled recursively; a scalar skeleton is filled by a non-empty
     * array. False for a payload that does not decode to an array. Empty `$requiredFilledFields` = true.
     *
     * @param array<string, mixed> $requiredFilledFields
     */
    public function carriesFilledFields(array $requiredFilledFields): bool
    {
        $widgetPayload = $this->getFieldsAsArray();
        return $widgetPayload !== null && static::payloadCarriesFilledFields($widgetPayload, $requiredFilledFields);
    }

    /**
     * @param array<mixed> $widgetPayload
     * @param array<string, mixed> $requiredFilledFields
     */
    protected static function payloadCarriesFilledFields(array $widgetPayload, array $requiredFilledFields): bool
    {
        foreach ($requiredFilledFields as $requiredFieldName => $requiredFieldSkeleton) {
            if (!array_key_exists($requiredFieldName, $widgetPayload)) {
                return false;
            }
            $payloadFieldValue = $widgetPayload[$requiredFieldName];
            if (is_array($requiredFieldSkeleton)) {
                if (!is_array($payloadFieldValue)
                    || !static::payloadCarriesFilledFields($payloadFieldValue, $requiredFieldSkeleton)
                ) {
                    return false;
                }
                continue;
            }
            if (is_array($payloadFieldValue)) {
                // skeleton expects a scalar — a non-empty array counts as filled (e.g. completedItems list)
                if ($payloadFieldValue === []) {
                    return false;
                }
                continue;
            }
            $payloadFieldValueTrimmed = trim((string)$payloadFieldValue);
            if ($payloadFieldValueTrimmed === '' || preg_match('/^<[^>]*>$/', $payloadFieldValueTrimmed) === 1) {
                return false;
            }
            // Skeleton-derived (never a free-text matcher): a model that "cleans" the dictated placeholder by
            // stripping its angle brackets ("YYYY-MM-DD" for "<YYYY-MM-DD>") has still not filled the field.
            $skeletonPlaceholderWithoutBrackets = trim(trim((string)$requiredFieldSkeleton), '<>');
            if ($skeletonPlaceholderWithoutBrackets !== ''
                && strcasecmp($payloadFieldValueTrimmed, $skeletonPlaceholderWithoutBrackets) === 0
            ) {
                return false;
            }
        }
        return true;
    }

    /** The `type` as written (a `…Directive` suffix kept), null unless VALID. */
    public function getWrittenWidgetType(): ?string
    {
        $type = $this->fields->type ?? null;
        return is_string($type) ? $type : null;
    }

    /**
     * The `type` as written with a trailing legacy `…Directive` stripped once — the frontend's name rule
     * (`widget-name-resolver.ts`) without the check against the known names; null unless VALID.
     */
    public function getWidgetTypeWithoutLegacySuffix(): ?string
    {
        $writtenWidgetType = $this->getWrittenWidgetType();
        if ($writtenWidgetType === null || !str_ends_with($writtenWidgetType, ConversationWidgetClasses::LEGACY_WIDGET_TYPE_SUFFIX)) {
            return $writtenWidgetType;
        }
        return substr($writtenWidgetType, 0, -strlen(ConversationWidgetClasses::LEGACY_WIDGET_TYPE_SUFFIX));
    }

    /**
     * The canonical type — the `WIDGET_TYPE` of the class the registry resolves the written `type` to
     * ({@see ConversationWidgetClasses::getWidgetClassForWidgetType()}); null for a block that is not VALID or names no
     * known type (the frontend renders nothing for it).
     */
    public function getCanonicalWidgetType(): ?string
    {
        $writtenWidgetType = $this->getWrittenWidgetType();
        if ($writtenWidgetType === null) {
            return null;
        }
        $widgetClass = ConversationWidgetClasses::getWidgetClassForWidgetType($writtenWidgetType);
        return $widgetClass !== null ? $widgetClass::WIDGET_TYPE : null;
    }

    /**
     * Whether the block is a VALID block of `$widgetType`, written plain or with the legacy `…Directive` suffix — the
     * frontend's name rule while no canonical name ends in `Directive` (asserted by the frontend registry spec).
     */
    public function isOfType(string $widgetType): bool
    {
        if ($widgetType === '') {
            return false;
        }
        $writtenWidgetType = $this->getWrittenWidgetType();
        return $writtenWidgetType === $widgetType
            || (!str_ends_with($widgetType, 'Directive') && $writtenWidgetType === $widgetType . 'Directive');
    }

    /**
     * Who wrote the block, read from its `emittedBy` field: a block without the field — every model
     * block and every stored block from before the field — reads as the MODEL, as does a block that is not VALID.
     */
    public function getOrigin(): ConversationWidgetOrigin
    {
        return ConversationWidgetOrigin::fromEmittedByValue($this->fields->{ConversationWidgetOrigin::EMITTED_BY_FIELD} ?? null);
    }

    /**
     * The block's JSON as written without the `emittedBy` field the one writer appended
     * ({@see ConversationWidget::buildWidgetBlockEmittedBy()}): the bytes before `,"emittedBy":"…"}` plus the closing
     * `}` — exactly {@see ConversationWidget::buildWidgetBlock()}'s JSON for a block that writer stored. Null when the
     * block carries no such field (a model block, a block from before the field, a field that is not the last one or
     * names no tool or platform), so the block stays as written. Reads nothing.
     */
    public function buildWidgetJsonWithoutEmittedBy(): ?string
    {
        $emittedByValue = $this->getOrigin()->toEmittedByValue();
        if ($emittedByValue === null) {
            return null;
        }
        $widgetJson = rtrim($this->widgetJson, "\r\n");
        // the writer's encoding (AIConversationMessage::buildWidgetFenceBlock()): unescaped slashes and unicode
        $emittedByFieldWithObjectEnd = ',' . json_encode(ConversationWidgetOrigin::EMITTED_BY_FIELD) . ':'
            . json_encode($emittedByValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '}';
        if (!str_ends_with($widgetJson, $emittedByFieldWithObjectEnd)) {
            return null;
        }
        return substr($widgetJson, 0, -strlen($emittedByFieldWithObjectEnd)) . '}';
    }

    /** The parser's single writer of the decoded fields. */
    public function setFields(?stdClass $fields): void
    {
        $this->fields = $fields;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->ordinal);
    }
}
