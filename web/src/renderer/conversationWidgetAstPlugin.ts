import { visit } from "unist-util-visit";
import { conversationWidgetRegistry, type ConversationWidgetEntry } from "./conversationWidgetRegistry";
import { resolveCanonicalWidgetName } from "./widget-name-resolver";
import { exceedsJsonContainerDepth } from "./widget-tree/bounded-json";

/** Whether a `::Name{payload='…'}` payload nests deeper than the backend decodes — MALFORMED there, dropped here. */
const isPayloadTooDeep = (payload: unknown): boolean => {
  if (typeof payload !== "string") return false;
  try {
    return exceedsJsonContainerDepth(JSON.parse(payload));
  } catch {
    return false;
  }
};

/**
 * Maps the `::Name` nodes a message carries onto the registered components by rewriting `data.hName` to the CANONICAL
 * registry name — which is what `ConversationMarkdown` keys its component map by.
 *
 * The name on the node is whatever the message contains, so a stored conversation delivers the legacy
 * `::ExampleWidgetDirective{payload='…'}` form (and stored server `component` parts carry `"name":"<Canonical>Directive"`,
 * which `normalize-server-message-to-markdown` turns into exactly that). Resolution therefore goes
 * through `resolveCanonicalWidgetName` — the one legacy rule — never a direct set lookup, or every old message would
 * degrade to the `:name` plain-text fallback.
 */
export default function conversationWidgetAstPlugin() {
  const canonicalNames = new Set(conversationWidgetRegistry.widgets.map((w: ConversationWidgetEntry) => w.name));

  return (tree: any) => {
    // `"leafDirective"` is remark-directive's node type — its contract, not our vocabulary. It is the only form a
    // widget ever travels in: `remarkWidgetFencePlugin` produces it from a ```widget fence, and the stored `::Name`
    // syntax parses into it.
    visit(tree, ["leafDirective"], (node, index, parent) => {
      const canonicalName = resolveCanonicalWidgetName(node.name, canonicalNames);

      if (
        canonicalName &&
        index !== undefined &&
        index !== null &&
        parent &&
        isPayloadTooDeep(node.attributes?.payload)
      ) {
        parent.children[index] = { type: "text", value: "" };
        return;
      }

      if (canonicalName) {
        // Map properties to attributes for react-markdown
        node.data = {
          hName: canonicalName,
          hProperties: node.attributes || {},
          ...node.data,
        };
        return node;
      }

      // `index === 0` is falsy — a plain `!index` check would skip the text
      // fallback for a node that is its parent's first child.
      if (index === undefined || index === null || !parent) {
        return node;
      }

      // An unknown name becomes plain text. Keep the node's children so a `::Unknown[label]` form degrades to its
      // label instead of silently swallowing it.
      const asText = { type: "text", value: `:${node.name}` };
      parent.children.splice(index, 1, asText, ...(node.children ?? []));
      const prev = parent.children[index - 1];
      if (prev?.type === "text") {
        prev.value += asText.value;
        parent.children.splice(index, 1);
      }
    });
  };
}
