<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

/**
 * A tool result a `ComposedView` may bind with `$from`: the executor stores its structured shape
 * NEXT TO the markdown the model reads ({@see ComposedViewBindableResultRecord}), so `{"$from":{"toolCall":"call_7",
 * "path":"$.bookings[*]"}}` addresses the result DTO, not its text. A result that lists entities
 * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\ListsEntityReferenceRows}) is bindable
 * by that alone; any other result DTO opts in with this interface. A result returned in its structured shape (no
 * markdown) is bindable without it — its `structuredContent` already is the stored DTO. No method: a marker the
 * executor asks.
 */
interface ComposedViewBindableResult
{
}
