<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetAnswer;

/**
 * What the owner submitted in one `Form` node of a `ComposedView` tree, read by
 * {@see ComposedViewConversationWidget::readAnswer()} from the form's widget-click signal:
 *
 *     ComposedView form "<form name>" submitted:
 *     - <field name>: <value>
 *
 * The form `name` is the identifying field — one tree may carry several forms. Reads nothing.
 */
class ComposedViewFormSubmission extends ConversationWidgetAnswer
{
    /** @var string The `name` of the submitted `Form` node. */
    public string $formName = '';

    /** @var array<string, string> Field name (a state name of the tree) → the submitted value, its `\n` (line break) and `\\` (backslash) escapes undone (`"true"` / `"false"` for a checkbox, `''` for an empty field). */
    public array $fieldValues = [];
}
