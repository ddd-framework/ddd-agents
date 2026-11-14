import { getWidgetSelfHydrationKeyFieldName } from "../../provider/environment";
import { describe, expect, it } from "vitest";
import type { ComposedNodeSchema } from "./composed-node-schema";
import { composedNodeSchema } from "./composed-node-schema";
import {
  composedViewTreeToWire,
  isComposedViewWithoutContent,
  measureComposedViewSourceBytes,
  parseComposedViewTree,
  readComposedViewTree,
} from "./composed-view-tree";
import parityFixture from "./composed-view-parity.json";
import { unified } from "unified";
import remarkParse from "remark-parse";
import remarkDirective from "remark-directive";
import { visit } from "unist-util-visit";
import remarkWidgetFencePlugin from "../remarkWidgetFencePlugin";
import { parseWidgetJsonObject } from "../widget-fence-json";

/**
 * The frontend half of the shared parity fixture (the backend half is
 * `ComposedViewTreeParityTest.php`, which writes the expected parts): the frontend reader keeps the same tree and
 * reports the same finding codes as `ComposedViewTreeReading.php` for every payload, under the fixture's stub schema.
 */
interface ParityCase {
  name: string;
  payload: Record<string, unknown>;
  expectedFindingCodes: string[];
  expectedKeptTree: unknown;
  /** The widgets the reader lifted out of the tree — path, canonical name, fields. */
  expectedLiftedWidgets?: unknown[];
}

const stubSchema = parityFixture.stubSchema as unknown as ComposedNodeSchema;
const cases = parityFixture.cases as unknown as ParityCase[];

/** PHP's json round trip turns an empty object into an empty list; compare the frontend's output the same way. */
const asPhpJson = (value: unknown): unknown =>
  Array.isArray(value)
    ? value.map(asPhpJson)
    : typeof value === "object" && value !== null
      ? Object.keys(value).length === 0
        ? []
        : Object.fromEntries(Object.entries(value).map(([key, nested]) => [key, asPhpJson(nested)]))
      : value;

describe("composed-view-tree — parity with the backend reader", () => {
  it("has the cases the backend recorded", () => {
    expect(cases.length).toBeGreaterThanOrEqual(10);
    expect(cases.every((parityCase) => Array.isArray(parityCase.expectedFindingCodes))).toBe(true);
  });

  for (const parityCase of cases) {
    it(`reads "${parityCase.name}" as the backend does`, () => {
      const tree = readComposedViewTree(parityCase.payload, stubSchema);
      expect(tree.findings.map((finding) => finding.code)).toEqual(parityCase.expectedFindingCodes);
      expect(asPhpJson(composedViewTreeToWire(tree))).toEqual(parityCase.expectedKeptTree);
      expect(asPhpJson(tree.liftedWidgets)).toEqual(parityCase.expectedLiftedWidgets ?? []);
    });
  }
});

describe("composed-view-tree — a refused widget is lifted, never dropped (the backend's texts)", () => {
  const embeddableWidgetNames = Object.keys(stubSchema.embeddableWidgets ?? {}).join(", ");

  it("lifts a widget that does not embed out of its slot and prunes the stack it emptied", () => {
    const slotTree = readComposedViewTree(
      {
        title: "Dashboard",
        view: {
          type: "StubStack",
          children: [{ type: "Widget", widget: "StubStandalone", fields: { [getWidgetSelfHydrationKeyFieldName()]: 1 } }],
        },
      },
      stubSchema,
    );
    expect(slotTree.findings.map((finding) => finding.message)).toEqual([
      '"StubStandalone" does not embed — it is shown as its own widget block after the view',
    ]);
    expect(slotTree.view).toBeNull();
    expect(slotTree.liftedWidgets).toEqual([
      { path: "view.children[0]", widget: "StubStandalone", fields: { [getWidgetSelfHydrationKeyFieldName()]: 1 } },
    ]);
    const rootTree = readComposedViewTree({ view: { type: "Widget", widget: "StubStandalone" } }, stubSchema);
    expect(rootTree.view).toBeNull();
    expect(rootTree.liftedWidgets.map((liftedWidget) => liftedWidget.path)).toEqual(["view"]);
  });

  it("refuses a widget inside a repeat with its own block as the way out (it would be lifted once per row)", () => {
    const tree = readComposedViewTree(
      { view: { type: "StubRepeat", source: [1, 2], children: [{ type: "Widget", widget: "StubStandalone" }] } },
      stubSchema,
    );
    expect(tree.findings.map((finding) => finding.message)).toEqual([
      `"StubStandalone" is no embeddable widget — show it as its own widget block next to the ComposedView, never as a Widget node; only ${embeddableWidgetNames} embed`,
    ]);
    expect(tree.liftedWidgets).toEqual([]);
  });

  it("lifts an embeddable widget too large for its slot, naming the size class it needs", () => {
    const tree = readComposedViewTree(
      {
        view: {
          type: "StubStack",
          children: [
            { type: "StubText", text: "kept" },
            { type: "Widget", widget: "CustomVisualization", fields: {} },
          ],
        },
      },
      stubSchema,
    );
    expect(tree.findings.map((finding) => finding.message)).toEqual([
      '"CustomVisualization" needs a full slot, the slot "children" is regular — it is shown as its own widget block after the view',
    ]);
    expect(tree.view?.nodesBySlotName.children).toHaveLength(1);
  });

  it("a view of layout nodes only has no content", () => {
    const emptyGridTree = readComposedViewTree({ view: { type: "Grid", columns: 2, children: [] } });
    expect(emptyGridTree.view && isComposedViewWithoutContent(emptyGridTree.view)).toBe(true);
    const textTree = readComposedViewTree({ view: { type: "Stack", children: [{ type: "Text", text: "x" }] } });
    expect(textTree.view && isComposedViewWithoutContent(textTree.view)).toBe(false);
  });
});

describe("composed-view-tree — the source budget counts the backend's bytes", () => {
  const sourceBytesCases = parityFixture.sourceBytesCases as Array<{
    name: string;
    payload: Record<string, unknown>;
    expectedBytes: number;
  }>;
  for (const sourceBytesCase of sourceBytesCases) {
    it(`measures "${sourceBytesCase.name}" as the backend does`, () => {
      expect(measureComposedViewSourceBytes(sourceBytesCase.payload)).toBe(sourceBytesCase.expectedBytes);
    });
  }
});

describe("parseComposedViewTree — the payload string", () => {
  it("gives an empty tree for a payload that is not a JSON object", () => {
    expect(parseComposedViewTree("not json").view).toBeNull();
    expect(parseComposedViewTree("[1,2]").findings).toEqual([]);
  });

  it("refuses a payload over the source budget with one finding", () => {
    const tree = parseComposedViewTree(
      JSON.stringify({ title: "x".repeat(composedNodeSchema.budgets.maxSourceBytes) }),
    );
    expect(tree.findings.map((finding) => finding.code)).toEqual(["BUDGET_EXCEEDED"]);
  });
});

describe("widget-fence-json — a block JSON with surplus trailing closers, as WidgetBlocks reads it", () => {
  const surplusCloserCases = parityFixture.surplusCloserCases as Array<{
    name: string;
    widgetJson: string;
    expectedOutcome: string;
    expectedFields: Record<string, unknown> | null;
  }>;
  const renderedWidgetNames = (markdown: string): string[] => {
    const processor = unified().use(remarkParse).use(remarkDirective).use(remarkWidgetFencePlugin);
    const tree = processor.runSync(processor.parse(markdown));
    const names: string[] = [];
    visit(tree, "leafDirective", (node: { name?: string }) => {
      if (typeof node.name === "string") names.push(node.name);
    });
    return names;
  };
  for (const surplusCloserCase of surplusCloserCases) {
    it(`reads "${surplusCloserCase.name}" as the backend does`, () => {
      const widgetObject = parseWidgetJsonObject(surplusCloserCase.widgetJson);
      const outcome = widgetObject !== undefined && typeof widgetObject.type === "string" ? "VALID" : "MALFORMED";
      expect(outcome).toBe(surplusCloserCase.expectedOutcome);
      expect(outcome === "VALID" ? asPhpJson(widgetObject) : null).toEqual(surplusCloserCase.expectedFields);
      // the render plugin renders exactly the blocks the backend reads as VALID (every case type is a registered widget)
      const fenceMarkdown = "```widget\n" + surplusCloserCase.widgetJson + "\n```";
      expect(renderedWidgetNames(fenceMarkdown).length).toBe(outcome === "VALID" ? 1 : 0);
    });
  }
});
