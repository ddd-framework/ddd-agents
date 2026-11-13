<?php
// placeholder {{NAMESPACE}} sample: App\Domain\Demo\Entities\AI\Conversations\Purposes\DoneCriteria
// placeholder {{CLASS_NAME}} sample: DemoNotesFirstNoteKeptDoneCriterion
// placeholder {{CRITERION_KEY}} sample: notes.firstNoteKept
// placeholder {{PROVING_TOOL}} sample: addDemoNote
// placeholder {{DONE_NOTE}} sample: the first note of the owner is kept

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;

/**
 * Decides the seed key {@see KEY} from the record: done once a call of the proving tool completed. Reads its inputs
 * only — no service call, no clock. Register the class in the purpose's DONE_CRITERION_CLASSES. The tool name is read
 * from the call (`toolCall->name`): the message's `toolName` is a virtual database column, empty during the run that
 * made the call.
 */
class {{CLASS_NAME}} extends TaskPlanDoneCriterion
{
    public const string KEY = '{{CRITERION_KEY}}';

    /** Only a completed call of this tool can change the verdict (the post-write re-sweep reads it). */
    public const array CHANGED_BY_TOOLS = ['{{PROVING_TOOL}}'];

    public function resolve(TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict
    {
        $conversationMessages = $taskPlanDoneCriterionInputs->conversationMessages;
        if ($conversationMessages === null) {
            return null;
        }
        foreach ($conversationMessages->getElements() as $conversationMessage) {
            if (($conversationMessage->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL
                && isset($conversationMessage->toolCall)
                && ($conversationMessage->toolCall->name ?? null) === '{{PROVING_TOOL}}'
                && ($conversationMessage->status ?? null) === AIConversationMessage::STATUS_COMPLETED
            ) {
                return $this->done('{{DONE_NOTE}}');
            }
        }
        return null;
    }
}
