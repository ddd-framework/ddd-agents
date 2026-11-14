import type { ConversationActivityElement } from "../../contract/conversation";

/**
 * What the activity read sends per element and the stack reads (`ConversationActivityElement`); every field may be
 * missing on an element a caller builds by hand (stories, specs).
 */
export type ActivityLineInput = Partial<ConversationActivityElement>;

/** One row of the stack: the element as the endpoint sent it last, keyed by its tool-call message id. */
export type ActivityElement = ActivityLineInput & { toolCallMessageId: number };

const STATE_RANK: Record<string, number> = { queued: 0, running: 1, finished: 2, failed: 2, skipped: 2 };

/**
 * Merge a response into the stack: a later state replaces an earlier one, a line never disappears once told (an
 * element answered without a line, because the narrator was late, keeps the sentence it already had), and an element
 * the response no longer carries stays (the turn snapshot is complete, so this only happens on a pinned turn that a
 * later turn pushed out of the window). Row order is the endpoint's: the order of the persisted rows.
 */
export const mergeActivityElements = (
  previous: ActivityElement[],
  incoming: ActivityLineInput[] | null | undefined,
): ActivityElement[] => {
  const byId = new Map<number, ActivityElement>(previous.map((element) => [element.toolCallMessageId, element]));
  const ordered: number[] = [];
  (incoming ?? []).forEach((element) => {
    const id = element.toolCallMessageId;
    if (id == null || id <= 0) return;
    ordered.push(id);
    const known = byId.get(id);
    if (!known) {
      byId.set(id, { ...element, toolCallMessageId: id });
      return;
    }
    const knownRank = STATE_RANK[known.state ?? "queued"] ?? 0;
    const incomingRank = STATE_RANK[element.state ?? "queued"] ?? 0;
    // A state never moves backwards (a stale response after a fresher one).
    if (incomingRank < knownRank) return;
    const stateAdvanced = incomingRank > knownRank || known.state !== element.state;
    byId.set(id, {
      ...known,
      ...element,
      toolCallMessageId: id,
      // The finished line replaces the started one; a missing line keeps what was told, unless the state advanced
      // (then the started sentence would misdescribe a finished step, so the row shows its state alone).
      line: element.line ?? (stateAdvanced ? null : (known.line ?? null)),
    });
  });
  // Rows the response carried, in its order, then rows it no longer carries, in their previous order.
  const carried = new Set(ordered);
  const kept = previous
    .filter((element) => !carried.has(element.toolCallMessageId))
    .map((element) => element.toolCallMessageId);
  return [...kept, ...ordered].map((id) => byId.get(id)!);
};

/** The rows the customer reads: skipped elements carry nothing. */
export const visibleActivityElements = (elements: ActivityElement[]): ActivityElement[] =>
  elements.filter((element) => element.state !== "skipped");

/**
 * Whether a response names a turn different from the one pinned locally — the stack must start clean instead of
 * merging the new turn's rows into the previous (already folded) one. False until a turn is pinned, and false while
 * the response has not named a turn yet (a request still in flight for the newly pinned, not-yet-answered turn).
 */
export const isDifferentTurn = (pinnedTurnMessageId: number | null, answeredTurnMessageId: number | null): boolean =>
  pinnedTurnMessageId !== null && answeredTurnMessageId !== null && answeredTurnMessageId !== pinnedTurnMessageId;
