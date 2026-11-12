<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceRow;

/**
 * The entity the OWNER asked about: the detail panel's "Ask about this" button puts the entity
 * into the chat input as a chip, and the frontend's composer writes it into the owner's own next message as this
 * block — one block per entity. A reader of the owner's message learns WHICH entity the question is about from
 * `entityType` + `entityId` ({@see buildEntityReferenceRow()}), never from the name: `label` is what the panel showed
 * and identifies nothing. The agent never writes it (`emitter: customer`). Reads nothing.
 */
class EntityMentionConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'EntityMention';

    public const string OWNER_CONTROL = 'none (written by the owner\'s composer)';

    /** @var string The entity type pattern — the `ENTITY_TYPE` form of an entity reference resolver. */
    public const string ENTITY_TYPE_PATTERN = '/^[a-zA-Z][a-zA-Z0-9]*$/';

    /** @var string The entity id pattern — a row id or an external id (letters, digits, `_`, `-`, `.`). */
    public const string ENTITY_ID_PATTERN = '/^[A-Za-z0-9_.-]{1,256}$/';

    /** @var int The longest label the composer writes. */
    public const int MAX_LABEL_LENGTH = 120;

    /** @var string The entity type (`competitor`, `review`, `keyword` — an `EntityReferenceResolver::ENTITY_TYPE`). */
    public string $entityType = '';

    /** @var string The entity's id as the backend resolved its reference (a row id or an external id). */
    public string $entityId = '';

    /** @var string|null The entity's name as the detail panel showed it (display only, at most 120 characters). */
    public ?string $label = null;

    /**
     * The widget restored from a stored block; an `entityId` written as a JSON integer (a hand-written or copied fence)
     * is read as its digits.
     */
    public static function fromWidgetBlock(WidgetBlock $widgetBlock): static
    {
        $entityMentionConversationWidget = parent::fromWidgetBlock($widgetBlock);
        if ($entityMentionConversationWidget->entityId === '' && $widgetBlock->outcome === WidgetBlock::OUTCOME_VALID) {
            $widgetFields = static::decodeWidgetFields($widgetBlock->widgetJson);
            if (is_int($widgetFields['entityId'] ?? null)) {
                $entityMentionConversationWidget->entityId = (string)$widgetFields['entityId'];
            }
        }
        return $entityMentionConversationWidget;
    }

    /** Whether the block names an entity: a type and an id of the reference forms. */
    public function namesAnEntity(): bool
    {
        return preg_match(self::ENTITY_TYPE_PATTERN, $this->entityType) === 1 && preg_match(self::ENTITY_ID_PATTERN, $this->entityId) === 1;
    }

    /**
     * The mentioned entity as a reference row — what the entity type's resolver checks the read rights of
     * (`EntityReferenceResolver::isReadableForWidgetSelfHydrationKey()`); null when the block names no entity.
     */
    public function buildEntityReferenceRow(): ?EntityReferenceRow
    {
        if (!$this->namesAnEntity()) {
            return null;
        }
        return EntityReferenceRow::create($this->entityType, $this->entityId, $this->widgetSelfHydrationKey);
    }

    /** The fields in block order; the label is not written when it is null. */
    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'entityType' => $this->entityType,
            'entityId' => $this->entityId,
            'label' => $this->label,
        ]);
    }
}
