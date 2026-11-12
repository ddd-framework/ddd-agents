<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * A quotation set off from the text around it (ChatGPT's `Blockquote`): the quoted words — `text`,
 * or text nodes in the children — then who said it (`attribution`) and where it was published (`source`, shown as a
 * link). A review sentence, a statement from a press article.
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: [ComposedViewNodeCategory::INLINE, ComposedViewNodeCategory::BLOCK], description: 'the quoted content, when it is more than one plain text')]
#[ComposedViewNodeProp(name: 'text', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the quoted words, verbatim')]
#[ComposedViewNodeProp(name: 'attribution', types: [ComposedViewNodeProp::TYPE_STRING], description: 'who said or wrote it')]
#[ComposedViewNodeProp(name: 'source', types: [ComposedViewNodeProp::TYPE_OBJECT], description: 'where it was published ({"name","url"}), shown as a link after the attribution')]
class QuoteComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Quote';

    public const array CATEGORIES = [ComposedViewNodeCategory::BLOCK];

    public const string SUMMARY = 'a quotation: the quoted words, who said them and where they were published';
}
