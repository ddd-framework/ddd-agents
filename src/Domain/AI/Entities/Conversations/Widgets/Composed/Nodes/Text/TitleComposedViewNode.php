<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A heading inside a `ComposedView` tree: the widget title or the title of one section of it. One title
 * size per level: `widget` is the widget's title (`textIntro` bold), `section` the title of a
 * surface or block inside it (`text` bold). The text is content the model writes, never translated.
 */
#[ComposedViewNodeProp(name: 'text', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], isRequired: true, description: 'the heading, in the owner\'s language')]
#[ComposedViewNodeProp(name: 'level', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ['widget', 'section'], description: 'widget = the title of the whole view (default), section = the title of one part of it')]
class TitleComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Title';

    public const array CATEGORIES = [ComposedViewNodeCategory::BLOCK];

    public const string SUMMARY = 'a heading: the title of the view or of one section of it';
}
