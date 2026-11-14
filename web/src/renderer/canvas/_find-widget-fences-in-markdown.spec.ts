import { describe, expect, test } from "vitest";
import { composedNodeSchema, ComposedSizeClass } from "../widget-tree/composed-node-schema";
import { findWidgetFenceByPayloadHash, findWidgetFencesInMarkdown } from "./find-widget-fences-in-markdown";
import { canvasInstanceIdForMessagePayload, payloadHashOfInstanceId, shortStringHash } from "./canvas-state";
import { normalizeServerMessageToMarkdown } from "../../chat/normalize-server-message-to-markdown";
import { getWidgetSelfHydrationKeyFieldName } from "../../provider/environment";
import { registerConversationWidgets } from "../conversationWidgetRegistry";

/** Stands for an app widget whose stored node carries its own attributes instead of a `payload`. */
registerConversationWidgets([{ name: "ExampleEditOnMount", component: () => null }]);

const payload = JSON.stringify({ query: "local SEO", display: "canvas", groups: [] });

describe("findWidgetFencesInMarkdown — a canvas body re-reads its payload from the anchor message", () => {
  test("reads a ```widget fence: the type becomes the name, the other fields the payload", () => {
    const fences = findWidgetFencesInMarkdown(
      `Here you go.\n\n\`\`\`widget\n${JSON.stringify({ type: "ConversationSearchResults", ...JSON.parse(payload) })}\n\`\`\``,
    );
    expect(fences).toHaveLength(1);
    expect(fences[0].name).toBe("ConversationSearchResults");
    expect(JSON.parse(fences[0].payload)).toEqual(JSON.parse(payload));
    expect(fences[0].payloadHash).toBe(shortStringHash(fences[0].payload));
  });

  test("reads the stored `::Name{payload='…'}` form, canonical and legacy, and lifts the agent space key", () => {
    const fences = findWidgetFencesInMarkdown(
      `::ConversationSearchResultsDirective{payload='{"title":"Chats"}' ${getWidgetSelfHydrationKeyFieldName()}="7"}\n\n::WebSearchResults{payload='{"title":"Pages"}'}`,
    );
    expect(fences.map((fence) => fence.name)).toEqual(["ConversationSearchResults", "WebSearchResults"]);
    expect(fences[0].agentSpaceKey).toBe("7");
    expect(fences[0].payload).toBe('{"title":"Chats"}');
  });

  test("drops unknown widgets and malformed fences, like the renderer", () => {
    expect(findWidgetFencesInMarkdown('```widget\n{ "type": "NotAWidget" }\n```')).toEqual([]);
    expect(findWidgetFencesInMarkdown("```widget\n{ not json\n```")).toEqual([]);
    expect(findWidgetFencesInMarkdown("")).toEqual([]);
  });

  test("finds the fence a canvas instance id points at by name + payload hash; identical widgets share one", () => {
    const markdown = `::ConversationSearchResults{payload='{"title":"A"}'}\n\n::ConversationSearchResults{payload='{"title":"B"}'}\n\n::ConversationSearchResults{payload='{"title":"A"}'}`;
    const found = findWidgetFenceByPayloadHash(markdown, "ConversationSearchResults", shortStringHash('{"title":"B"}'));
    expect(found?.payload).toBe('{"title":"B"}');
    expect(
      findWidgetFencesInMarkdown(markdown).filter((f) => f.payloadHash === shortStringHash('{"title":"A"}')),
    ).toHaveLength(2);
    expect(findWidgetFenceByPayloadHash(markdown, "WebSearchResults", shortStringHash('{"title":"A"}'))).toBeUndefined();
  });
});

/** The forms and edge cases a body must read exactly as its inline widget rendered them. */
describe("findWidgetFencesInMarkdown — B0.5 cases", () => {
  test("reads the bare `widget { … }` line (the model dropping the backticks) like the renderer does", () => {
    const fences = findWidgetFencesInMarkdown(`Here.\n\nwidget { "type": "ConversationSearchResults", "title": "Recent" }`);
    expect(fences).toHaveLength(1);
    expect(fences[0].name).toBe("ConversationSearchResults");
    expect(JSON.parse(fences[0].payload)).toEqual({ title: "Recent" });
  });

  test("an apostrophe in the payload travels as \\u0027 (the normaliser's form); the widget and the body hash the SAME string", () => {
    const original = JSON.stringify({ title: "Bob's Bakery" });
    const encoded = original.replace(/'/g, "\\u0027");
    const fences = findWidgetFencesInMarkdown(`::ConversationSearchResults{payload='${encoded}'}`);
    expect(fences[0].payload).toBe(encoded);
    expect(JSON.parse(fences[0].payload)).toEqual({ title: "Bob's Bakery" });
    const instanceId = canvasInstanceIdForMessagePayload("conversationSearchResults", 10, encoded);
    expect(payloadHashOfInstanceId(instanceId)).toBe(fences[0].payloadHash);
  });

  test("keeps every string attribute (an edit-on-mount form carries target + mode, not payload)", () => {
    const target = JSON.stringify({ kind: "item", id: 9001, status: "PUBLISHED" });
    const fences = findWidgetFencesInMarkdown(`::ExampleEditOnMount{target='${target}' mode="edit"}`);
    expect(fences[0].name).toBe("ExampleEditOnMount");
    expect(fences[0].attributes.target).toBe(target);
    expect(fences[0].attributes.mode).toBe("edit");
    expect(fences[0].payload).toBe("{}");
  });

  test("two widgets of different kinds in one message: both, in document order, each found by its own name + hash", () => {
    const markdown = `::ConversationSearchResults{payload='{"title":"R"}'}\n\nSome prose.\n\n::WebSearchResults{payload='{"title":"P"}'}`;
    const fences = findWidgetFencesInMarkdown(markdown);
    expect(fences.map((fence) => fence.name)).toEqual(["ConversationSearchResults", "WebSearchResults"]);
    expect(fences[0].payloadHash).not.toBe(fences[1].payloadHash);
    expect(findWidgetFenceByPayloadHash(markdown, "WebSearchResults", shortStringHash('{"title":"P"}'))?.name).toBe(
      "WebSearchResults",
    );
    expect(findWidgetFenceByPayloadHash(markdown, "ConversationSearchResults", shortStringHash('{"title":"P"}'))).toBeUndefined();
  });

  test("end to end with the server normaliser: a component part becomes a fence the finder reads back", () => {
    const serverMessage = JSON.stringify({
      content: [
        { type: "text", text: "Here you go." },
        { type: "component", name: "ConversationSearchResults", props: { payload: { query: "it's", groups: [] } } },
      ],
    });
    const markdown = normalizeServerMessageToMarkdown(serverMessage);
    const fences = findWidgetFencesInMarkdown(markdown);
    expect(fences).toHaveLength(1);
    expect(fences[0].name).toBe("ConversationSearchResults");
    expect(JSON.parse(fences[0].payload)).toEqual({ query: "it's", groups: [] });
  });

  test("a widget the registry does not know, in any form, is dropped — never a fence, never a canvas anchor", () => {
    expect(findWidgetFencesInMarkdown("::NotAWidget{payload='{}'}")).toEqual([]);
    expect(findWidgetFencesInMarkdown(`widget { "type": "NotAWidget" }`)).toEqual([]);
  });

  test("lists no embedded widget that is not embeddable — the reader drops it, at the root too", () => {
    const composedViewFence = JSON.stringify({
      type: "ComposedView",
      view: { type: "Widget", widget: "QuickReplies", fields: { [getWidgetSelfHydrationKeyFieldName()]: 42 } },
    });
    const fences = findWidgetFencesInMarkdown(`\`\`\`widget\n${composedViewFence}\n\`\`\``);
    expect(fences.map((fence) => fence.name)).toEqual(["ComposedView"]);
  });

  test("descends into a ComposedView tree: an embedded Widget node reads as the fence of its widget", () => {
    // CustomVisualization embeds — the package's committed schema names it.
    const embeddableWidgets = composedNodeSchema.embeddableWidgets as Record<string, { sizeClass: ComposedSizeClass }>;
    expect(embeddableWidgets.CustomVisualization).toEqual({ sizeClass: "full" });
    const composedViewFence = JSON.stringify({
      type: "ComposedView",
      view: { type: "Widget", widget: "CustomVisualizationDirective", fields: { [getWidgetSelfHydrationKeyFieldName()]: 42, filters: "x" } },
    });
    const fences = findWidgetFencesInMarkdown(`Here.\n\n\`\`\`widget\n${composedViewFence}\n\`\`\``);
    expect(fences.map((fence) => fence.name)).toEqual(["ComposedView", "CustomVisualization"]);
    expect(JSON.parse(fences[1].payload)).toEqual({ [getWidgetSelfHydrationKeyFieldName()]: 42, filters: "x" });
    expect(fences[1].agentSpaceKey).toBe("42");
    expect(
      findWidgetFenceByPayloadHash(
        `\`\`\`widget\n${composedViewFence}\n\`\`\``,
        "CustomVisualization",
        fences[1].payloadHash,
      ),
    ).toBeDefined();
  });
});
