<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Embedding;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;

/**
 * An existing domain widget inside a `ComposedView` tree (the first data path): `{"type":"Widget",
 * "widget":"OpenOrders","fields":{"agentSpaceId":42}}` for an application's widget of its open orders. Allowed only for
 * a widget whose class declares {@see ConversationWidget::EMBEDDABLE} and only in a slot whose size class fits the
 * widget's {@see ConversationWidget::EMBEDDED_SIZE_CLASS}. The frontend renders the registered component with the
 * slot's context; an embedded widget is a shown widget of its own (awaiting input, answer readers per instance — the
 * flattening of the shown-widget view is a separate change).
 */
#[ComposedViewNodeProp(name: 'widget', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, isBindable: false, description: 'the wire name of an embeddable domain widget')]
#[ComposedViewNodeProp(name: 'fields', types: [ComposedViewNodeProp::TYPE_OBJECT], isBindable: false, description: "the widget's own payload fields, as its fence would carry them next to `type`")]
class WidgetComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Widget';

    public const array CATEGORIES = [ComposedViewNodeCategory::HEAVY];

    public const string SUMMARY = 'an existing domain widget embedded in the tree (only widgets declared embeddable)';

    /**
     * Whether the node is LIFTED out of the tree instead of dropped when its slot refuses it: it
     * names a known widget — not embeddable, or embeddable but larger than the slot. An unknown name is dropped.
     */
    public function isLiftedWhenRefused(): bool
    {
        return $this->getEmbeddedWidgetClass() !== null;
    }

    /** The canonical wire type of the named widget (a legacy `…Directive` name resolved), null for an unknown one. */
    public function getEmbeddedWidgetType(): ?string
    {
        /** @var class-string<ConversationWidget>|null $embeddedWidgetClass */
        $embeddedWidgetClass = $this->getEmbeddedWidgetClass();
        return $embeddedWidgetClass !== null ? $embeddedWidgetClass::WIDGET_TYPE : null;
    }

    /**
     * The widget's own fields, as its own block carries them next to `type` (the node's `fields` prop).
     *
     * @return array<string, mixed>
     */
    public function getEmbeddedWidgetFields(): array
    {
        $embeddedWidgetFields = $this->props['fields'] ?? [];
        return is_array($embeddedWidgetFields) ? $embeddedWidgetFields : [];
    }

    /**
     * The note of a lifted node: why it left the tree and where it is shown — `$composedViewNodeSlot` is the slot that
     * refused it, null at the root.
     */
    public function buildLiftedNoteMessage(?ComposedViewNodeSlot $composedViewNodeSlot): string
    {
        $embeddedWidgetType = (string)$this->getEmbeddedWidgetType();
        /** @var class-string<ConversationWidget>|null $embeddedWidgetClass */
        $embeddedWidgetClass = $this->getEmbeddedWidgetClass();
        $reason = $embeddedWidgetClass !== null && $embeddedWidgetClass::EMBEDDABLE && $composedViewNodeSlot !== null
            ? sprintf('"%s" needs a %s slot, the slot "%s" is %s', $embeddedWidgetType, $embeddedWidgetClass::EMBEDDED_SIZE_CLASS, $composedViewNodeSlot->name, $composedViewNodeSlot->sizeClass)
            : sprintf('"%s" does not embed', $embeddedWidgetType);
        return $reason . ' — it is shown as its own widget block after the view';
    }

    /** The embedded widget's class, null for an unknown wire name. */
    public function getEmbeddedWidgetClass(): ?string
    {
        $embeddedWidgetType = $this->props['widget'] ?? null;
        return is_string($embeddedWidgetType) ? ConversationWidgetClasses::getWidgetClassForWidgetType($embeddedWidgetType) : null;
    }

    /** Whether the named widget is a known class that declares itself embeddable. */
    public function isEmbeddedWidgetEmbeddable(): bool
    {
        /** @var class-string<ConversationWidget>|null $embeddedWidgetClass */
        $embeddedWidgetClass = $this->getEmbeddedWidgetClass();
        return $embeddedWidgetClass !== null && $embeddedWidgetClass::EMBEDDABLE;
    }

    /** An embedded widget is HEAVY: only a slot that accepts heavy nodes (or names `Widget`) and fits its size class takes it. */
    public function isAcceptedBySlot(ComposedViewNodeSlot $composedViewNodeSlot): bool
    {
        /** @var class-string<ConversationWidget>|null $embeddedWidgetClass */
        $embeddedWidgetClass = $this->getEmbeddedWidgetClass();
        return $this->isEmbeddedWidgetEmbeddable()
            && $composedViewNodeSlot->fitsSizeClass($embeddedWidgetClass::EMBEDDED_SIZE_CLASS)
            && (in_array(self::NODE_TYPE, $composedViewNodeSlot->acceptsNodeTypes, true)
                || in_array(ComposedViewNodeCategory::HEAVY, $composedViewNodeSlot->acceptsCategories, true));
    }

    /** At the root the embeddable rule holds too — a view of one non-embeddable widget is no way around it. */
    public function isAcceptedAsRoot(): bool
    {
        return $this->isEmbeddedWidgetEmbeddable();
    }

    /**
     * The wire names of the embeddable widgets, in registry order (the order of the schema's `embeddableWidgets`).
     *
     * @return string[]
     */
    public static function findEmbeddableWidgetTypes(): array
    {
        $embeddableWidgetTypes = [];
        foreach (ConversationWidgetClasses::getWidgetClassesByWidgetType() as $widgetType => $widgetClass) {
            if ($widgetClass::EMBEDDABLE) {
                $embeddableWidgetTypes[] = (string)$widgetType;
            }
        }
        return $embeddableWidgetTypes;
    }

    /**
     * The refusal names the widget and the way out: a widget that does not embed is shown as its own block next to the
     * `ComposedView` (the generic slot text "it accepts …, heavy" reads as if the `Widget` node fitted, and the model
     * wrote the same tree again); an embeddable widget too large for the slot names the size class it needs.
     */
    public function buildSlotRefusalMessage(ComposedViewNodeSlot $composedViewNodeSlot): string
    {
        if (!$this->isEmbeddedWidgetEmbeddable()) {
            return $this->buildNotEmbeddableMessage();
        }
        /** @var class-string<ConversationWidget> $embeddedWidgetClass */
        $embeddedWidgetClass = $this->getEmbeddedWidgetClass();
        if (!$composedViewNodeSlot->fitsSizeClass($embeddedWidgetClass::EMBEDDED_SIZE_CLASS)) {
            return sprintf(
                'the widget "%s" needs a %s slot — the slot "%s" is %s',
                (string)$this->props['widget'],
                $embeddedWidgetClass::EMBEDDED_SIZE_CLASS,
                $composedViewNodeSlot->name,
                $composedViewNodeSlot->sizeClass,
            );
        }
        return parent::buildSlotRefusalMessage($composedViewNodeSlot);
    }

    public function buildRootRefusalMessage(): string
    {
        return $this->buildNotEmbeddableMessage();
    }

    /** The text for a widget that does not embed (unknown, or its class does not declare {@see ConversationWidget::EMBEDDABLE}). */
    public function buildNotEmbeddableMessage(): string
    {
        $embeddableWidgetTypes = static::findEmbeddableWidgetTypes();
        return sprintf(
            '"%s" is no embeddable widget — show it as its own widget block next to the ComposedView, never as a Widget node; %s',
            is_string($this->props['widget'] ?? null) ? $this->props['widget'] : '',
            $embeddableWidgetTypes === [] ? 'no widget embeds' : 'only ' . implode(', ', $embeddableWidgetTypes) . ' embed',
        );
    }
}
