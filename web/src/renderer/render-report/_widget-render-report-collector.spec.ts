import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import {
  buildWidgetRenderReportSignal,
  createWidgetRenderReportCollector,
  parseWidgetRenderReportSignal,
  WidgetRenderBlockReport,
} from "./widget-render-report-collector";

/**
 * The render-report collector: a block that reports nothing counts as mounted, a message
 * settles once (pending blocks hold it until `maxWaitMs`), the signal names only the blocks that are not mounted, and
 * the inspector's parser reads back what the builder wrote.
 */

describe("widget render report collector", () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  test("settles a message once, its blocks in document order, a silent block mounted", () => {
    const onSettled = vi.fn();
    const collector = createWidgetRenderReportCollector({ onSettled, settleMs: 100, maxWaitMs: 1000 });
    collector.reporterForBlock(7, "ComposedView", 80).report({ status: "failed", code: "INVALID_NODE", detail: "x" });
    collector.registerBlock(7, "QuickReplies", 10);
    collector.registerBlock(7, "ComposedView", 80);
    vi.advanceTimersByTime(150);
    expect(onSettled).toHaveBeenCalledTimes(1);
    const [messageId, blocks] = onSettled.mock.calls[0] as [string, WidgetRenderBlockReport[]];
    expect(messageId).toBe("7");
    expect(blocks.map((block) => [block.widgetName, block.entry.status])).toEqual([
      ["QuickReplies", "mounted"],
      ["ComposedView", "failed"],
    ]);
    collector.reporterForBlock(7, "ComposedView", 80).report({ status: "mounted" });
    vi.advanceTimersByTime(1000);
    expect(onSettled).toHaveBeenCalledTimes(1);
  });

  test("a pending block holds the message until it resolves or the wait runs out", () => {
    const onSettled = vi.fn();
    const collector = createWidgetRenderReportCollector({ onSettled, settleMs: 100, maxWaitMs: 1000 });
    collector.reporterForBlock(8, "ComposedView", 0).report({ status: "pending" });
    vi.advanceTimersByTime(500);
    expect(onSettled).not.toHaveBeenCalled();
    vi.advanceTimersByTime(700);
    expect(onSettled).toHaveBeenCalledTimes(1);
    expect((onSettled.mock.calls[0][1] as WidgetRenderBlockReport[])[0].entry.status).toBe("pending");
    collector.dispose();
  });

  test("the signal names only the blocks that are not mounted and parses back", () => {
    const blocks: WidgetRenderBlockReport[] = [
      { widgetName: "ComposedView", sourceOffset: 0, entry: { status: "mounted" } },
      {
        widgetName: "ComposedView",
        sourceOffset: 50,
        entry: { status: "failed", code: "RENDER_FAILED", detail: "boom" },
      },
      { widgetName: "WebSearchResults", sourceOffset: 90, entry: { status: "pending" } },
    ];
    const signal = buildWidgetRenderReportSignal("12", blocks);
    expect(signal).toContain("- widget 2 (ComposedView #2): failed RENDER_FAILED — boom");
    expect(signal).toContain("- widget 3 (WebSearchResults #1): pending (did not finish loading)");
    expect(parseWidgetRenderReportSignal(signal ?? "")).toEqual({
      messageId: "12",
      blocks: [
        {
          position: 2,
          widgetName: "ComposedView",
          occurrence: 2,
          status: "failed",
          code: "RENDER_FAILED",
          detail: "boom",
        },
        { position: 3, widgetName: "WebSearchResults", occurrence: 1, status: "pending", code: null, detail: null },
      ],
    });
    expect(buildWidgetRenderReportSignal("12", [blocks[0]])).toBeNull();
    const manyBlocks: WidgetRenderBlockReport[] = Array.from({ length: 40 }, (unused, blockIndex) => ({
      widgetName: "ComposedView",
      sourceOffset: blockIndex,
      entry: { status: "failed", code: "INVALID_NODE", detail: "x".repeat(120) },
    }));
    const longSignal = buildWidgetRenderReportSignal("12", manyBlocks) ?? "";
    expect(longSignal.length).toBeLessThanOrEqual(2000);
    expect(longSignal).toMatch(/- … and \d+ more widget\(s\) that did not render as intended\n/);
    expect(longSignal.split("\n").every((line) => !line.startsWith("- widget") || line.endsWith("x"))).toBe(true);
    expect(parseWidgetRenderReportSignal("User selected B")).toBeNull();
  });

  test("a block that mounted with a note (figures without a source) is reported, with the fix-it sentence", () => {
    const sourceNote = {
      status: "mounted" as const,
      code: "INLINE_VALUE_WITHOUT_SOURCE" as const,
      detail: "rendered, but 1 part(s) show figures without a source — view: a Chart shows figures written inline",
    };
    const noteOnly = buildWidgetRenderReportSignal("14", [
      { widgetName: "ComposedView", sourceOffset: 0, entry: sourceNote },
    ]);
    expect(noteOnly).toContain("- widget 1 (ComposedView #1): mounted INLINE_VALUE_WITHOUT_SOURCE — rendered, but");
    expect(noteOnly).toContain("names its publisher and page in `source`");
    expect(noteOnly).not.toContain("The owner did not see these widgets");
    expect(parseWidgetRenderReportSignal(noteOnly ?? "")?.blocks[0]).toMatchObject({
      status: "mounted",
      code: "INLINE_VALUE_WITHOUT_SOURCE",
    });

    const noteAndFailure = buildWidgetRenderReportSignal("14", [
      { widgetName: "ComposedView", sourceOffset: 0, entry: sourceNote },
      { widgetName: "ComposedView", sourceOffset: 40, entry: { status: "failed", code: "INVALID_NODE", detail: "x" } },
    ]);
    expect(noteAndFailure).toContain("The owner did not see these widgets as intended.");
    expect(noteAndFailure).toContain("Do not resend the widget for the note alone.");
  });
});
