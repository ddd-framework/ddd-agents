<?php
// placeholder {{NAMESPACE}} sample: App\Domain\Demo\Entities\AI\Conversations\Purposes
// placeholder {{CLASS_NAME}} sample: DemoNotesReviewPurpose
// placeholder {{CRITERION_FQCN}} sample: App\Domain\Demo\Entities\AI\Conversations\Purposes\DoneCriteria\DemoNotesFirstNoteKeptDoneCriterion
// placeholder {{CRITERION_CLASS}} sample: DemoNotesFirstNoteKeptDoneCriterion
// placeholder {{READ_TOOL}} sample: getDemoNotes

declare(strict_types=1);

namespace {{NAMESPACE}};

use {{CRITERION_FQCN}};
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/**
 * The class a purpose file names in its `purposeClass` line. The file carries the data (directive, preloads, seeded
 * plan); this class carries the logic the purpose needs. Every hook of the base answers "nothing": override only what
 * this purpose needs, and keep every override fail-soft.
 */
class {{CLASS_NAME}} extends AIConversationPurpose
{
    /** One class per `doneCriterion` key of the seeded plan; a key without a class is no gate. */
    public const array DONE_CRITERION_CLASSES = [
        {{CRITERION_CLASS}}::class,
    ];

    /** Tools known to change no criterion: no re-read of open criterion leaves after them. */
    public const array TOOLS_CHANGING_NO_DONE_CRITERION = [
        '{{READ_TOOL}}',
    ];

    /** Close the conversation once every item of the seeded plan is terminal (no further owner input). */
    public function shouldCloseConversationAfterSettledTurn(AIConversation $conversation): bool
    {
        $taskPlanItems = $conversation->getTaskPlan()?->items;
        return $taskPlanItems !== null && $taskPlanItems->count() > 0 && !$taskPlanItems->hasOpenItems();
    }
}
