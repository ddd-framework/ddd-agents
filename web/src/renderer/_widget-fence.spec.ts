import { describe, expect, it } from "vitest";
import { unified } from "unified";
import remarkParse from "remark-parse";
import remarkDirective from "remark-directive";
import { visit } from "unist-util-visit";
import remarkWidgetFencePlugin from "./remarkWidgetFencePlugin";
import { detectUnrenderedWidgets } from "./detect-unrendered-widgets";
import { getWidgetSelfHydrationKeyFieldName } from "../provider/environment";

/** Parse markdown → run remark-directive + the widget-fence plugin → return the tree so a test can inspect the nodes. */
const transform = (markdown: string): any => {
  const processor = unified().use(remarkParse).use(remarkDirective).use(remarkWidgetFencePlugin);
  return processor.runSync(processor.parse(markdown));
};

const firstWidgetNode = (markdown: string): any => {
  let found: any;
  visit(transform(markdown), "leafDirective", (node) => {
    found ??= node;
  });
  return found;
};

describe("remarkWidgetFencePlugin — bare `widget { … }` line (fence dropped by the model)", () => {
  it("renders a bare widget line as the widget node it would be from a fence", () => {
    const node = firstWidgetNode(`widget { "type": "QuickReplies" }`);
    expect(node?.name).toBe("QuickReplies");
    expect(JSON.parse(node.attributes.payload)).toEqual({});
  });

  it("keeps the non-type fields as the widget payload", () => {
    const node = firstWidgetNode(`widget { "type": "MultiChoice", "title": "Toate recenziile", "filters": "" }`);
    expect(node?.name).toBe("MultiChoice");
    expect(JSON.parse(node.attributes.payload)).toEqual({ title: "Toate recenziile", filters: "" });
  });

  it("still renders the canonical ```widget fenced form", () => {
    const node = firstWidgetNode('```widget\n{ "type": "QuickReplies" }\n```');
    expect(node?.name).toBe("QuickReplies");
  });

  it("drops a bare widget line with an unknown type (renders nothing, never leaks JSON)", () => {
    expect(firstWidgetNode(`widget { "type": "NotAWidget" }`)).toBeUndefined();
  });

  it("does not touch a prose paragraph that merely mentions the word widget", () => {
    expect(firstWidgetNode("The connect widget is shown below.")).toBeUndefined();
  });
});

/** A ```widget fence of the given type whose block field of the agent space key holds the given JSON value. */
const fenceWithAgentSpaceKey = (type: string, agentSpaceKeyJson?: string): string =>
  agentSpaceKeyJson === undefined
    ? `\`\`\`widget\n{ "type": "${type}" }\n\`\`\``
    : `\`\`\`widget\n{ "type": "${type}", "${getWidgetSelfHydrationKeyFieldName()}": ${agentSpaceKeyJson} }\n\`\`\``;

describe("remarkWidgetFencePlugin — the declared agent space key field reaches the widget as a prop", () => {
  // Every widget documents `{ "type": "…", "<agent space key field>": <key> }`, so the plugin must not pack the key into
  // the payload STRING only: `conversationWidgetAstPlugin` turns `attributes` into props, so a fence targeting another
  // agent space would otherwise silently render the ACTIVE one — the field would be documentation, not behaviour.
  it("lifts the key to an attribute so it arrives as a prop", () => {
    const node = firstWidgetNode(fenceWithAgentSpaceKey("Clock", "412553"));
    expect(node?.name).toBe("Clock");
    expect(node.attributes[getWidgetSelfHydrationKeyFieldName()]).toBe("412553");
  });

  it("keeps the key in the payload too, so widgets that parse it themselves are unaffected", () => {
    const node = firstWidgetNode(fenceWithAgentSpaceKey("CurrencyConverter", "412553"));
    expect(JSON.parse(node.attributes.payload)).toEqual({ [getWidgetSelfHydrationKeyFieldName()]: 412553 });
  });

  it("carries it as a string, matching what the markdown attribute form delivers", () => {
    const node = firstWidgetNode(fenceWithAgentSpaceKey("ConceptExplainer", '"412553"'));
    expect(node.attributes[getWidgetSelfHydrationKeyFieldName()]).toBe("412553");
  });

  it("adds no key attribute when the fence carries none", () => {
    const node = firstWidgetNode(fenceWithAgentSpaceKey("QuickReplies"));
    expect(node.attributes[getWidgetSelfHydrationKeyFieldName()]).toBeUndefined();
  });

  it("ignores a non-scalar key rather than stringifying an object into the prop", () => {
    const node = firstWidgetNode(fenceWithAgentSpaceKey("QuickReplies", '{ "id": 1 }'));
    expect(node.attributes[getWidgetSelfHydrationKeyFieldName()]).toBeUndefined();
  });
});
