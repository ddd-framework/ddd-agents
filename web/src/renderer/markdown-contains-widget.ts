import { conversationWidgetRegistry, getConversationWidgetRegistryRevision } from "./conversationWidgetRegistry";
import { parseWidgetJsonObject } from "./widget-fence-json";
import { WIDGET_FENCE_LANG } from "./widget-fence-syntax";
import { resolveCanonicalWidgetName } from "./widget-name-resolver";

/**
 * Does a message's markdown carry a widget? The conversation asks this to reset the drawer when a fresh widget message
 * arrives, and to decide whether a reloaded last message should re-open its canvas (see ConversationMessageWidgets).
 *
 * Lives in its own file rather than in `conversationWidgetRegistry.ts` so the registry stays import-free: a registry →
 * resolver import back would be a cycle whose evaluation order decides whether the app boots. The registry is read
 * lazily for the same reason (a widget can import AgentChat, which imports this file).
 */

let widgetNames: ReadonlySet<string> | undefined;
let widgetNamesRevision = -1;

// Every registered widget name, canonical. Read lazily — see the note above and in widget-name-resolver.ts.
const getWidgetNames = (): ReadonlySet<string> => {
  if (widgetNames === undefined || widgetNamesRevision !== getConversationWidgetRegistryRevision()) {
    widgetNames = new Set(conversationWidgetRegistry.widgets.map((widget) => widget.name));
    widgetNamesRevision = getConversationWidgetRegistryRevision();
  }
  return widgetNames;
};

// Matches the widget name at the start of a line in the stored `::Name` form (and the `:::Name`
// form old messages may carry); a single-colon inline node doesn't match (min two colons).
const WIDGET_NODE_RE = /^[ \t]*:{2,3}([A-Za-z][\w]*)/gm;

// Fenced code blocks must not count as widgets — remark-directive never parses
// its nodes inside them. The open-fence variant drops a still-unterminated
// fence so streamed partial markdown doesn't match either.
const CLOSED_CODE_FENCE_RE = /```[\s\S]*?```/g;
const OPEN_CODE_FENCE_RE = /```[\s\S]*$/;

// A closed ```widget fence — the agent's widget syntax (see remarkWidgetFencePlugin). Only a CLOSED fence counts:
// a still-streaming open fence has no terminating ``` yet and must not match prematurely. Built from the shared
// lang constant so "``` widget" (a space before the lang — remark accepts it) resolves the same as everywhere else.
const CLOSED_WIDGET_FENCE_RE = new RegExp("```[ \\t]*" + WIDGET_FENCE_LANG + "\\s*([\\s\\S]*?)```", "g");

/**
 * True when the markdown contains at least one widget — either a `::Name` / `:::Name` node OR a closed ```widget
 * fence whose `type` resolves to a registered widget. BOTH forms go through `resolveCanonicalWidgetName`, so a stored
 * message written as `::ExampleWidgetDirective{…}` or `{"type":"ExampleWidgetDirective"}` counts exactly like the
 * canonical `::ExampleWidget` — the drawer resets for old conversations too.
 */
export const markdownContainsWidget = (markdown: string): boolean => widgetNamesInMarkdown(markdown).length > 0;

/**
 * The CANONICAL names of every registered widget the markdown carries, in order of appearance, one entry per
 * occurrence (both syntaxes, legacy `…Directive` spellings resolved). The message list reads it to tell a superseded
 * widget from the live one: a later message carrying a widget of the SAME name retires the earlier one's controls.
 */
export const widgetNamesInMarkdown = (markdown: string): string[] => {
  if (!markdown) {
    return [];
  }
  const names: string[] = [];
  for (const match of markdown.matchAll(CLOSED_WIDGET_FENCE_RE)) {
    // malformed fence body → not a renderable widget; a surplus trailing `}` / `]` is read as the backend reads it
    const parsed = parseWidgetJsonObject(match[1]);
    const type = parsed?.type;
    const canonicalName = typeof type === "string" ? resolveCanonicalWidgetName(type, getWidgetNames()) : null;
    if (canonicalName) {
      names.push(canonicalName);
    }
  }
  const withoutCode = markdown.replace(CLOSED_CODE_FENCE_RE, "").replace(OPEN_CODE_FENCE_RE, "");
  for (const match of withoutCode.matchAll(WIDGET_NODE_RE)) {
    const canonicalName = resolveCanonicalWidgetName(match[1], getWidgetNames());
    if (canonicalName) {
      names.push(canonicalName);
    }
  }
  return names;
};
