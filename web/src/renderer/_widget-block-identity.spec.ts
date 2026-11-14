import { describe, expect, it } from "vitest";
import { unified } from "unified";
import remarkParse from "remark-parse";
import remarkDirective from "remark-directive";
import { visit } from "unist-util-visit";
import remarkWidgetFencePlugin from "./remarkWidgetFencePlugin";
import conversationWidgetAstPlugin from "./conversationWidgetAstPlugin";
import { parseComposedViewTree } from "./widget-tree/composed-view-tree";

/**
 * A widget block keeps its place in the message through the fence plugin (the render
 * report tells two blocks of one type apart by it), and a payload nested deeper than the backend decodes is dropped by
 * both plugins and never throws in the reader.
 */
const renderedWidgetNodes = (markdown: string) => {
  const processor = unified()
    .use(remarkParse)
    .use(remarkDirective)
    .use(remarkWidgetFencePlugin)
    .use(conversationWidgetAstPlugin);
  const tree = processor.runSync(processor.parse(markdown));
  const nodes: Array<{ name: string; offset: number | undefined }> = [];
  visit(tree, "leafDirective", (node: any) => {
    if (typeof node.data?.hName === "string")
      nodes.push({ name: node.data.hName, offset: node.position?.start?.offset });
  });
  return nodes;
};

describe("widget blocks — identity and depth", () => {
  it("two blocks of one type keep two different offsets through the fence plugin", () => {
    const fence = (question: string) =>
      "```widget\n" + JSON.stringify({ type: "QuickReplies", question, options: ["Yes", "No"] }) + "\n```";
    const nodes = renderedWidgetNodes(`First:\n\n${fence("One?")}\n\nSecond:\n\n${fence("Two?")}`);
    expect(nodes.map((node) => node.name)).toEqual(["QuickReplies", "QuickReplies"]);
    expect(nodes[0].offset).toBeTypeOf("number");
    expect(nodes[1].offset).toBeGreaterThan(nodes[0].offset ?? Number.POSITIVE_INFINITY);
  });

  it("a ::Name payload nested deeper than the backend decodes is dropped, and the reader never throws on it", () => {
    // Written as text: `JSON.stringify` itself cannot serialise this deep a value.
    const deepPayload = `{"view":${"[".repeat(10000)}1${"]".repeat(10000)}}`;
    expect(renderedWidgetNodes(`::ComposedView{payload='${deepPayload}'}`)).toEqual([]);
    expect(() => parseComposedViewTree(deepPayload)).not.toThrow();
    expect(parseComposedViewTree(deepPayload).view).toBeNull();
  });
});
