<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The parallel tool calls a model emitted in ONE turn — turn-scoped and transient: it lives on
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse}, NOT folded onto a message. `appendTurn` emits one
 * message per call. Distinct from the persisted per-message {@see ToolCall} (which is one resolved call carried on
 * its own message); this is the turn's call list before emission.
 *
 * @property ToolCall[] $elements
 * @method ToolCall|null first()
 * @method ToolCall[] getElements()
 */
class ToolCalls extends ObjectSet
{
}
