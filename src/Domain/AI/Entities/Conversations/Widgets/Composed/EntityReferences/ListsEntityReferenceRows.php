<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindableResult;

/**
 * A tool result that lists entities the model may put into a `ComposedView`: it names its rows in result
 * order; the platform mints one reference per row ({@see EntityReferenceMinting::mintForToolCall()}) and puts it next
 * to the durable id in what the model reads. Implemented by a domain's result DTO — the AI core
 * imports none of them. Such a result is bindable ({@see ComposedViewBindableResult}): the executor stores its rows and its
 * structured shape with the result, the persist pass resolves references and `$from` bindings against them.
 */
interface ListsEntityReferenceRows extends ComposedViewBindableResult
{
    /** @return EntityReferenceRow[] the listed entities, in the order the result lists them */
    public function getEntityReferenceRows(): array;
}
