import { describe, expect, it } from "vitest";
import {
  type ActivityElement,
  isDifferentTurn,
  mergeActivityElements,
  visibleActivityElements,
} from "./merge-activity-elements";

const element = (toolCallMessageId: number, state: ActivityElement["state"], line: string | null = null): ActivityElement => ({
  toolCallMessageId,
  state,
  kind: "tool",
  line,
});

describe("mergeActivityElements", () => {
  it("adds new rows in the endpoint's order", () => {
    const merged = mergeActivityElements([], [element(2, "queued", "Looking up A"), element(3, "queued")]);
    expect(merged.map((row) => row.toolCallMessageId)).toEqual([2, 3]);
    expect(merged[1].line).toBeNull();
  });

  it("lets the finished line replace the started one", () => {
    const first = mergeActivityElements([], [element(2, "queued", "Looking up A")]);
    const merged = mergeActivityElements(first, [element(2, "finished", "Found A")]);
    expect(merged[0].state).toBe("finished");
    expect(merged[0].line).toBe("Found A");
  });

  it("keeps a told line when the same state comes back without one", () => {
    const first = mergeActivityElements([], [element(2, "queued", "Looking up A")]);
    const merged = mergeActivityElements(first, [element(2, "queued", null)]);
    expect(merged[0].line).toBe("Looking up A");
  });

  it("drops the started line when the state advanced and the finished line is still owed", () => {
    const first = mergeActivityElements([], [element(2, "queued", "Looking up A")]);
    const merged = mergeActivityElements(first, [element(2, "finished", null)]);
    expect(merged[0].state).toBe("finished");
    expect(merged[0].line).toBeNull();
  });

  it("never moves a state backwards on a stale response", () => {
    const first = mergeActivityElements([], [element(2, "finished", "Found A")]);
    const merged = mergeActivityElements(first, [element(2, "queued", "Looking up A")]);
    expect(merged[0].state).toBe("finished");
    expect(merged[0].line).toBe("Found A");
  });

  it("keeps rows a later response no longer carries, before the carried ones", () => {
    const first = mergeActivityElements([], [element(2, "finished", "Found A")]);
    const merged = mergeActivityElements(first, [element(5, "queued", "Looking up B")]);
    expect(merged.map((row) => row.toolCallMessageId)).toEqual([2, 5]);
  });

  it("hides skipped elements from the customer", () => {
    const merged = mergeActivityElements([], [element(2, "skipped"), element(3, "finished", "Found A")]);
    expect(visibleActivityElements(merged).map((row) => row.toolCallMessageId)).toEqual([3]);
  });
});

describe("isDifferentTurn", () => {
  it("is false before any turn is pinned", () => {
    expect(isDifferentTurn(null, 42)).toBe(false);
  });

  it("is false while no turn has been answered yet", () => {
    expect(isDifferentTurn(42, null)).toBe(false);
  });

  it("is false while the response answers the pinned turn", () => {
    expect(isDifferentTurn(42, 42)).toBe(false);
  });

  it("is true when the response names a different turn than the one pinned locally", () => {
    // The bug this guards: a customer message sent while the previous turn's stack is still shown must not have the
    // new turn's rows merged into the old ones.
    expect(isDifferentTurn(42, 99)).toBe(true);
  });
});
