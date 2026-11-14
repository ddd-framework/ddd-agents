import { visit } from "unist-util-visit";
import { BARE_WIDGET_PARAGRAPH, WIDGET_FENCE_LANG } from "./widget-fence-syntax";
import { resolveWidgetName } from "./widget-name-resolver";
import { parseWidgetJsonObject } from "./widget-fence-json";
import { buildWidgetAgentSpaceKeyProps, readWidgetAgentSpaceKeyAttribute } from "./_agent-space/widget-agent-space-key-attribute";

/**
 * Render a widget the agent emitted in either form:
 *
 *   1. a ```widget fenced code block (the base-prompt convention):
 *        ```widget
 *        { "type": "<WidgetName>", ...fields }
 *        ```
 *   2. a bare `widget { "type": "<WidgetName>", ...fields }` line (no fence) — the same intent when the model
 *      drops the backticks, which happens in practice. Markdown parses this as a plain `paragraph`, so it never
 *      reached the fence path and leaked as raw JSON to the customer (the block shown verbatim).
 *
 * Both are rewritten into the SAME `leafDirective` node that `::WidgetName{payload='…'}` produces, so the existing
 * pipeline (conversationWidgetAstPlugin → ConversationWidgetHost → feature-gate → hydration) renders it unchanged —
 * the non-`type` fields become the node's `payload` (a JSON string), exactly what the widgets parse.
 *
 * MUST run BEFORE conversationWidgetAstPlugin. The `type` is resolved through the ONE legacy rule
 * (`resolveWidgetName`): the canonical `ExampleWidget` matches exactly, the legacy `ExampleWidgetDirective` resolves
 * onto it. A block whose JSON decodes only without surplus trailing `}` / `]` renders like the backend reads it
 * (`widget-fence-json.ts`). A malformed JSON block or an unknown widget name is dropped (renders nothing) — the kill switch; never leak
 * raw JSON or a `:name` fallback to the user.
 */
export default function remarkWidgetFencePlugin() {
  // `resolveWidgetName` (widget-name-resolver) is the ONE source of truth for "is this a real widget", shared with
  // the unrendered-widget detector so a dropped block here and a reported block there always agree.
  return (tree: any) => {
    // In-place replacement only (no splice) so the visitor's indices stay valid: rewrite the node into a
    // `leafDirective`, or drop it to an empty text node (malformed JSON / unknown widget → render nothing).
    const rewrite = (parent: any, index: number, widgetJson: string) => {
      const drop = () => {
        parent.children[index] = { type: "text", value: "" };
      };

      // A JSON object as written or without the model's surplus trailing `}` / `]` (the backend reads it the same way);
      // deeper than the backend decodes (`json_decode(…, 32)`), not an object, or malformed → dropped.
      const parsed = parseWidgetJsonObject(widgetJson);
      if (parsed === undefined) {
        drop();
        return;
      }

      const { type, ...fields } = parsed as Record<string, unknown>;
      const widgetName = typeof type === "string" ? resolveWidgetName(type) : undefined;
      if (!widgetName) {
        drop(); // unknown / unsupported widget → render nothing
        return;
      }

      const agentSpaceKeyAttribute = readWidgetAgentSpaceKeyAttribute(fields);
      parent.children[index] = {
        // `"leafDirective"` is remark-directive's node type — its contract, not our vocabulary.
        type: "leafDirective",
        name: widgetName,
        // The fence's place in the message travels on: it is the block's identity in the render report (two blocks
        // of one type in one message are told apart by it) — without it every block sat at offset 0.
        ...(parent.children[index]?.position ? { position: parent.children[index].position } : {}),
        attributes: {
          payload: JSON.stringify(fields),
          // The agent space key is the one fence field the FRAMEWORK consumes rather than the widget: the block field
          // the app declares (`AgentsEnvironment.widgetSelfHydrationKeyFieldName`) is documented on every widget and
          // resolved centrally by `useResolvedConversationWidgetAgentSpaceKey`. Without lifting it here it stayed
          // buried in the payload string and never arrived as a prop, so a fence targeting another agent space
          // silently rendered the ACTIVE agent space's data — every widget except the ones that happened to parse
          // it back out themselves. Carried as a string under the declared field name, matching what the
          // `::Name{<field>=123}` attribute form delivers; the resolver normalizes both. It stays in the payload too,
          // so widgets that parse it keep working.
          ...buildWidgetAgentSpaceKeyProps(agentSpaceKeyAttribute === undefined ? undefined : String(agentSpaceKeyAttribute)),
        },
        children: [],
        data: {},
      };
    };

    // Concatenate a node's text (paragraph children are text nodes; the bare JSON carries no markdown syntax).
    const textOf = (node: any): string =>
      typeof node.value === "string"
        ? node.value
        : Array.isArray(node.children)
          ? node.children.map(textOf).join("")
          : "";

    visit(tree, (node: any, index, parent) => {
      if (!parent || index === null || index === undefined) {
        return;
      }
      if (node.type === "code") {
        if (node.lang !== WIDGET_FENCE_LANG) {
          return;
        }
        rewrite(parent, index, node.value);
        return;
      }
      if (node.type === "paragraph") {
        const match = BARE_WIDGET_PARAGRAPH.exec(textOf(node));
        if (match) {
          rewrite(parent, index, match[1]);
        }
      }
    });
  };
}
