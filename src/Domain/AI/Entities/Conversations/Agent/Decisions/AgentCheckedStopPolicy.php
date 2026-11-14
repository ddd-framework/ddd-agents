<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The CHECKED-STOP policy of a final answer, pure: whether a
 * premature final answer is bounced back to the model (one rule), the text that bounces it, and whether the
 * turn-closing decision model is asked at all. The loop reads the plan and the turn's facts and asks this policy; nothing here reads a repository.
 */
class AgentCheckedStopPolicy extends ValueObject
{
    /**
     * Checked-stop bounce cap PER CHUNK: one rejection, then the stop is accepted — the anti-infinite-loop
     * escape valve. Per chunk, not per turn: a long run yields into chunks and each chunk may stop early once.
     */
    public const int MAX_BOUNCES_PER_CHUNK = 1;

    /**
     * The ONE checked-stop rule, pure: a final answer is rejected when ALL hold —
     * - a task plan exists and THIS turn touched it (an item written or resolved in the turn — a leftover plan of an
     *   earlier turn never rejects a conversational reply) or a work signal started the turn (a work order re-seeds its
     *   plan unstamped, and no owner opened the turn);
     * - the model still owes open items once the candidate text's own evidence is counted (`$candidateTextEvidence`:
     *   a widget or a delivered answer in this text closes its leaf at the turn end; blocked items satisfy the stop);
     * - the text hands nothing to the owner — no question, no widget awaiting the owner or ending the purpose;
     * - the chunk has not used its one rejection;
     * - the text is not the opener of a kickoff the present owner has not answered yet
     *   (`$answersKickoffBeforeTheOwnerSpoke`: nothing can be owed before the owner spoke).
     * A model that never creates a plan is never rejected.
     */
    public static function shouldBounceCheckedStop(
        ?TaskPlan $taskPlan,
        int $currentTurnInitiatorMessageIndex,
        int $bounceSignalsInCurrentChunk,
        bool $turnInitiatedByWorkSignal,
        bool $finalTextHandsTheTurnToTheOwner,
        ?TaskPlanEvidence $candidateTextEvidence = null,
        bool $answersKickoffBeforeTheOwnerSpoke = false
    ): bool {
        if ($taskPlan === null || !isset($taskPlan->items)
            || $finalTextHandsTheTurnToTheOwner
            || $answersKickoffBeforeTheOwnerSpoke
            || $bounceSignalsInCurrentChunk >= self::MAX_BOUNCES_PER_CHUNK
        ) {
            return false;
        }
        $planTouchedThisTurn = $taskPlan->wasUpdatedInTurn($currentTurnInitiatorMessageIndex)
            || $taskPlan->wasResolvedInTurn($currentTurnInitiatorMessageIndex);
        if (!$planTouchedThisTurn && !$turnInitiatedByWorkSignal) {
            return false;
        }
        return $candidateTextEvidence !== null
            ? $taskPlan->items->hasOwedOpenItemsAfterCandidateTextEvidence($candidateTextEvidence)
            : $taskPlan->items->hasOwedOpenItems();
    }

    /**
     * The ONE checked-stop bounce text: the open items by key and plan-line title, split the way the
     * plan line splits them — the items the model ticks (finish and tick `done` with a `statusNote`, or mark them
     * `blocked` / `not_applicable` with a concrete `statusNote`) and the items the record closes (do their work; never
     * tick them done) — or end on the one question an item needs. The next answer is accepted as it is
     * ({@see MAX_BOUNCES_PER_CHUNK}). A turn that reads the sub-agents' results is told to tick from them, never to re-run a
     * child's tool (the results arrive as notifications; a delegation stored before the sub-agent records existed
     * carries them in its `delegate` tool result).
     */
    public static function buildCheckedStopBounceText(TaskPlan $taskPlan, bool $isFoldTurn = false): string
    {
        $modelTickedItemLines = $taskPlan->items?->getOwedOpenItemTitleLines(false) ?? [];
        $recordClosedItemLines = $taskPlan->items?->getOwedOpenItemTitleLines(true) ?? [];
        $foldPrefix = $isFoldTurn
            ? 'Your sub-agents\' results are above (their notifications, or the `delegate` tool result of an earlier delegation) — tick the item(s) they completed via set_task_status '
                . 'with the verdict as the statusNote (do NOT re-run any tool a sub-agent already ran, do NOT call check_delegations), '
                . 'then CONTINUE with the items that are still open. '
            : '';
        $bounceText = $foldPrefix . 'Your task plan still has OPEN items. Your previous answer was discarded: it ended the turn while they are open and asked the owner nothing.';
        if ($modelTickedItemLines !== []) {
            $bounceText .= ' Items you tick: ' . implode('; ', $modelTickedItemLines) . '. For each, use set_task_status — do the work now and tick it: '
                . '{"updates":[{"task":"<number>","status":"done","statusNote":"<one line: what you produced/found>"}]} '
                . '— or, if it truly cannot be done, mark it: '
                . '{"updates":[{"task":"<number>","status":"blocked","statusNote":"<the concrete obstacle>","requiresUserAction":true|false,"retryable":true|false}]} '
                . '— or, if it does not apply to this ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . ', mark it: '
                . '{"updates":[{"task":"<number>","status":"not_applicable","statusNote":"<why it does not apply>"}]} '
                . '(requiresUserAction when only the owner can fix it, retryable when a temporary external failure).';
        }
        if ($recordClosedItemLines !== []) {
            $bounceText .= ' Items that close themselves from the record: ' . implode('; ', $recordClosedItemLines)
                . ' — do their work now (the call, the widget or the answer the item names); never tick them done.';
        }
        return $bounceText . ' An item only the owner\'s reply can resolve: resolve every other item first, then end with the ONE question that unblocks it. '
            . 'When the owner asked you to stop after a part and check back with them: report that part and end with that ONE question — the items after it stay open. '
    . 'Then give your final answer, reporting the result of every item — it is accepted as it is.';
    }

    /**
     * The pure GATE of the turn-closing decision: ask the decision model only when its verdict
     * could matter and would be safe — a plan exists with OPEN items owing tool/widget evidence and none blocked on
     * the owner, the per-chunk bounce cap is not reached, this is not a delegation fold turn, the turn RESOLVED no
     * item (the progress exemption of the deterministic guard applies here too: a resolved turn's answer is
     * legitimately the next item's question), no work tool ran this turn (a delivered turn is never an announce),
     * and there is a text to judge.
     */
    public static function shouldAskTurnClosingDecision(
        ?TaskPlan $taskPlan,
        int $currentTurnInitiatorMessageIndex,
        int $bounceSignalsInCurrentChunk,
        bool $isFoldTurn,
        bool $workToolRanThisTurn,
        string $answerText
    ): bool {
        if ($taskPlan === null || $isFoldTurn || $workToolRanThisTurn || trim($answerText) === ''
            || $bounceSignalsInCurrentChunk >= self::MAX_BOUNCES_PER_CHUNK
            || $taskPlan->wasResolvedInTurn($currentTurnInitiatorMessageIndex)
        ) {
            return false;
        }
        $taskPlanItems = isset($taskPlan->items) ? $taskPlan->items : null;
        return $taskPlanItems !== null
            && $taskPlanItems->hasOpenItemsRequiringDoneEvidence()
            && !$taskPlanItems->hasBlockedItemRequiringUserAction();
    }
}
