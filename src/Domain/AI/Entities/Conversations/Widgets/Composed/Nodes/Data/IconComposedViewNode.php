<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTone;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * One icon of the design system (e.g. the check and the cross of a majority column),
 * coloured by its `tone`. `name` is a closed list (the Vanguard icon set's names); an unknown name is dropped by the
 * reader. `label` is the text a screen reader says — needed whenever the icon carries meaning ("Majority").
 */
#[ComposedViewNodeProp(name: 'name', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, allowedValues: IconComposedViewNode::ICON_NAMES, description: 'the icon (check, close, warning, info, star, arrowUp, …)')]
#[ComposedViewNodeProp(name: 'tone', types: [ComposedViewNodeProp::TYPE_STRING], allowedValues: ComposedViewDataValueFormat::TONES, description: 'its colour: ' . ComposedViewTone::DESCRIPTION)]
#[ComposedViewNodeProp(name: 'size', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ['small', 'medium', 'large'], description: 'default small')]
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], description: 'what the icon means, for screen readers and the tooltip')]
class IconComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Icon';

    public const array CATEGORIES = [ComposedViewNodeCategory::INLINE];

    public const string SUMMARY = 'one design-system icon (check, close, warning, star, arrows, …) in a tone';

    /** @var string[] the icon names a tree may use — Vanguard `IconNames` keys, the frontend maps them one to one */
    public const array ICON_NAMES = [
        'check', 'close', 'warning', 'info', 'help', 'question', 'exclamation', 'add', 'remove', 'dash',
        'star', 'starActive', 'like', 'prize', 'arrowUp', 'arrowDown', 'arrowLeft', 'arrowRight',
        'caretUp', 'caretDown', 'calendar', 'clock', 'location', 'pinFilled', 'directions', 'maps', 'phone', 'mail',
        'link', 'newTab', 'lock', 'lockOpen', 'user', 'business', 'search', 'lineChart', 'barChart', 'budget',
        'google', 'image', 'photo', 'play', 'pause', 'document', 'edit', 'trash', 'download', 'upload', 'refresh',
        'settings', 'notification', 'messageGood', 'messageBad', 'messageNeutral', 'message', 'shield', 'tag',
        'ai', 'visibility', 'desktop', 'mobile', 'tablet', 'event', 'post', 'news', 'home',
    ];
}
