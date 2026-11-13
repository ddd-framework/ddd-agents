<?php
// placeholder {{NAMESPACE}} sample: App\Domain\Notes\Entities\AI\Conversations\Widgets
// placeholder {{WIDGET_TYPE}} sample: NoteConfirm

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetAnswer;

/**
 * What the owner answered on one widget of the type, read by the widget's own class. Keep a suffix other than
 * `ConversationWidget`: the widget registry autoloads every such file in a widget folder (a non-widget class is skipped,
 * a class that does not load is a build error).
 */
class {{WIDGET_TYPE}}Answer extends ConversationWidgetAnswer
{
    /** @var string The id the owner confirmed. */
    public string $confirmedId = '';
}
