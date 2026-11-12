<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\Runs;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Per done-criterion key the state across the runs of ONE purpose in a window
 * (`reviewPurposeCriteria`): the review the analyst asks for as "which criterion stays open most often". Built run by
 * run from the stored task plans ({@see addTaskPlanOfRun()}, pure); ordered by open count when rendered.
 *
 * @property AIConversationPurposeCriterionReview[] $elements
 * @method AIConversationPurposeCriterionReview[] getElements()
 */
class AIConversationPurposeCriteriaReview extends ObjectSet implements LlmMarkdownRenderable
{
    public string $purposeName = '';

    /** The scope line of the filter. */
    public string $scope = '';

    /** Runs of the purpose in the window (all of them, before the review cap). */
    public int $totalRunCount = 0;

    /** Runs whose plans were read. */
    public int $reviewedRunCount = 0;

    /** Reviewed runs without a stored task plan. */
    public int $runsWithoutPlan = 0;

    /** True when more runs matched than {@see AIConversationPurposedRunsReadService::MAX_REVIEWED_RUNS} — the newest were read. */
    public bool $reviewCapReached = false;

    /** @var string[] The keys the purpose registers a criterion class for (the gates). */
    public array $registeredCriterionKeys = [];

    /** Counts every plan item of one run that carries a done-criterion key. */
    public function addTaskPlanOfRun(int $runId, ?TaskPlan $taskPlan, TaskPlanDoneCriteria $taskPlanDoneCriteria): void
    {
        $this->reviewedRunCount++;
        if ($taskPlan?->items === null || $taskPlan->items->count() === 0) {
            $this->runsWithoutPlan++;
            return;
        }
        foreach ($taskPlan->items->buildItemAddresses()->getElements() as $itemAddress) {
            $storedCriterionKey = $itemAddress->item->doneCriterion;
            if ($storedCriterionKey === null || $storedCriterionKey === '') {
                continue;
            }
            // a key stored before a rename counts under the key that replaced it (the criterion's LEGACY_KEYS)
            $registeredCriterion = $taskPlanDoneCriteria->forKey($storedCriterionKey);
            $doneCriterionKey = $registeredCriterion?->getKey() ?? $storedCriterionKey;
            $criterionReview = $this->getByUniqueKey(AIConversationPurposeCriterionReview::uniqueKeyOfDoneCriterionKey($doneCriterionKey));
            if (!$criterionReview instanceof AIConversationPurposeCriterionReview) {
                $criterionReview = AIConversationPurposeCriterionReview::forKey(
                    $doneCriterionKey,
                    $registeredCriterion !== null,
                    $itemAddress->item->text
                );
                $this->add($criterionReview);
            }
            $criterionReview->countItemOfRun($runId, $itemAddress->item->status, $itemAddress->hasBlockedAncestor);
        }
    }

    /** @return AIConversationPurposeCriterionReview[] the keys, most often open first */
    public function getReviewsByOpenCountDescending(): array
    {
        $criterionReviews = $this->getElements();
        usort(
            $criterionReviews,
            static fn (AIConversationPurposeCriterionReview $left, AIConversationPurposeCriterionReview $right): int => [$right->open, $right->blocked] <=> [$left->open, $left->blocked]
        );
        return $criterionReviews;
    }

    public function toLlmMarkdown(): string
    {
        $lines = [
            "# Done criteria of {$this->purposeName} — {$this->scope}",
            '',
            "{$this->reviewedRunCount} of {$this->totalRunCount} runs read" . ($this->reviewCapReached ? ' (review cap reached: the newest runs were read)' : '')
            . ", {$this->runsWithoutPlan} without a plan. A key marked \"no gate\" is a legacy key: the purpose registers no criterion for it, so the agent's tick alone closes it.",
            'Registered gates: ' . ($this->registeredCriterionKeys !== [] ? implode(', ', $this->registeredCriterionKeys) : 'none') . '.',
            '',
        ];
        if ($this->count() === 0) {
            $lines[] = 'No plan item with a done-criterion key in these runs.';
            return implode("\n", $lines);
        }
        $lines[] = '| key | gate | item | runs | done | open | blocked | n/a | open in runs | blocked in runs |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|---|';
        foreach ($this->getReviewsByOpenCountDescending() as $criterionReview) {
            $lines[] = $criterionReview->toLlmMarkdownRow();
        }
        return implode("\n", $lines);
    }
}
