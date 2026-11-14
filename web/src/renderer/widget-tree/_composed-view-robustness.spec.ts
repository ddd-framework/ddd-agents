import { getWidgetSelfHydrationKeyFieldName } from "../../provider/environment";
import { describe, expect, it } from "vitest";
import type { ComposedNodeSchema } from "./composed-node-schema";
import { composedNodeSchema } from "./composed-node-schema";
import { parseComposedViewTree, readComposedViewTree } from "./composed-view-tree";
import { exceedsJsonContainerDepth, stringifyWithinLimit, structuralSizeOf } from "./bounded-json";
import { computeComposedViewValues } from "./expression/evaluate-expression";
import { findWidgetFencesInMarkdown } from "../canvas/find-widget-fences-in-markdown";
import parityFixture from "./composed-view-parity.json";

/**
 * A model-written payload can never make the reader, the evaluator or the
 * fence finder throw or run away — deep nesting, prototype names, embedded widgets outside the embeddable rule,
 * unbounded texts and structures shared many times over.
 */

const stubSchema = parityFixture.stubSchema as unknown as ComposedNodeSchema;
const schemaWithEmbeddableWidget = {
  ...stubSchema,
  embeddableWidgets: { StubRegular: { sizeClass: "regular" } },
} as ComposedNodeSchema;

const deeplyNested = (depth: number): unknown => {
  let nested: unknown = 1;
  for (let level = 0; level < depth; level++) nested = [nested];
  return nested;
};

describe("composed view — robustness", () => {
  it("a payload nested deeper than the backend decodes gives no view and never throws", () => {
    const payload = JSON.stringify({ data: { rows: deeplyNested(5000) }, view: { type: "StubText", text: "x" } });
    const tree = parseComposedViewTree(payload, stubSchema);
    expect(tree.view).toBeNull();
    expect(tree.findings.map((finding) => finding.code)).toEqual(["BUDGET_EXCEEDED"]);
    expect(() => readComposedViewTree({ title: "t", data: { rows: deeplyNested(5000) } }, stubSchema)).not.toThrow();
    expect(exceedsJsonContainerDepth(deeplyNested(30))).toBe(false);
    expect(exceedsJsonContainerDepth({ a: deeplyNested(31) })).toBe(true);
  });

  it("the fence finder drops a deeply nested block and never throws", () => {
    const fence = "```widget\n" + JSON.stringify({ type: "ComposedView", view: deeplyNested(5000) }) + "\n```";
    expect(() => findWidgetFencesInMarkdown(`Here:\n\n${fence}`)).not.toThrow();
    expect(findWidgetFencesInMarkdown(`Here:\n\n${fence}`)).toEqual([]);
  });

  it("refuses __proto__ as a name and never touches a prototype", () => {
    const widgetFields = JSON.parse('{"state":{"__proto__":{"polluted":true},"ok":1},"data":{"__proto__":[1]}}');
    const tree = readComposedViewTree(widgetFields, stubSchema);
    expect(Object.getPrototypeOf(tree.state)).toBe(Object.prototype);
    expect(Object.keys(tree.state)).toEqual(["ok"]);
    expect(tree.findings.map((finding) => finding.code)).toEqual(["INVALID_STATE", "INVALID_PROP"]);
    expect(({} as Record<string, unknown>).polluted).toBeUndefined();
  });

  it("an embedded widget is heavy: a heavy slot and the root take an embeddable one, a data slot does not", () => {
    const embedded = { type: "Widget", widget: "StubRegularDirective", fields: { [getWidgetSelfHydrationKeyFieldName()]: 4 } };
    const inStack = readComposedViewTree(
      { view: { type: "StubStack", children: [embedded] } },
      schemaWithEmbeddableWidget,
    );
    expect(inStack.findings).toEqual([]);
    const inRepeat = readComposedViewTree(
      { view: { type: "StubRepeat", source: [1], children: [embedded] } },
      schemaWithEmbeddableWidget,
    );
    expect(inRepeat.findings.map((finding) => finding.code)).toEqual(["INVALID_NODE"]);
    expect(readComposedViewTree({ view: embedded }, schemaWithEmbeddableWidget).view?.type).toBe("Widget");
    expect(readComposedViewTree({ view: embedded }, { ...stubSchema, embeddableWidgets: {} }).view).toBeNull();
  });

  it("a text result cannot grow past maxTextLength", () => {
    const piece = "x".repeat(300);
    const computed = {
      piece: `"${piece}"`,
      long: Array.from({ length: 14 }, () => "piece").join(" + "),
      short: "piece + piece",
      joined: 'join(map(rows, r => "xxxxxxxxxx"), "")',
    };
    const result = computeComposedViewValues(
      computed,
      {},
      { rows: Array.from({ length: 1000 }, () => 1) },
      composedNodeSchema.budgets,
    );
    expect(result.errors.long?.errorCode).toBe("BUDGET_EXCEEDED");
    expect(result.errors.joined?.errorCode).toBe("BUDGET_EXCEEDED");
    expect(result.values.short).toBe(piece + piece);
  });

  it("a structure shared many times over is charged by its size as a tree", () => {
    const computed: Record<string, string> = { o0: "{v: 1}" };
    for (let step = 1; step <= 40; step++) computed[`o${step}`] = `{l: o${step - 1}, r: o${step - 1}}`;
    const result = computeComposedViewValues(computed, {}, {}, composedNodeSchema.budgets);
    expect(result.errors.o40?.errorCode).toBe("BUDGET_EXCEEDED");
    let shared: unknown = { v: 1 };
    for (let step = 0; step < 40; step++) shared = { l: shared, r: shared };
    expect(structuralSizeOf(shared)).toBeGreaterThan(1e12);
    expect(stringifyWithinLimit(shared, 65536)).toBeUndefined();
    expect(stringifyWithinLimit({ a: [1, 2] }, 65536)).toBe(JSON.stringify({ a: [1, 2] }, null, 2));
  });
});
