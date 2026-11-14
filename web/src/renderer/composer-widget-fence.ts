import { ConversationComposerWidget } from "./conversation-composer-widget";
import { WIDGET_FENCE_LANG } from "./widget-fence-syntax";
import { resolveWidgetName } from "./widget-name-resolver";
import { findBalancedJsonObjectEnd } from "./balanced-json-object";
import { conversationWidgetRegistry } from "./conversationWidgetRegistry";

/**
 * The FE twin of the backend's `AIConversationMessage::buildWidgetFenceBlock` — assembles / disassembles the
 * ```widget fence(s) a composer widget becomes inside the message TEXT (the record; no DB column, no DTO change). `buildComposerWidgetFenceBlock` / `buildMessageTextWithComposerWidgets` run on SEND;
 * `splitComposerWidgetFencesFromMessageText` runs on the USER BUBBLE to pull the fence back out as a
 * read-only widget above the prose.
 */

/** One ```widget fence block for a composer widget — `type` first, then its own fields, one line of JSON. */
export const buildComposerWidgetFenceBlock = (widget: ConversationComposerWidget): string =>
  "```" + WIDGET_FENCE_LANG + "\n" + JSON.stringify({ type: widget.widgetName, ...widget.fields }) + "\n```";

/**
 * The message text a send assembles: the typed text first, then one fence block per composer widget in composer
 * order, a blank line between blocks. Empty text + at least one widget is allowed (a fence-only send) — the result
 * is non-empty as long as EITHER side has content; both empty yields `""`, the send guard's cue to no-op.
 */
export const buildMessageTextWithComposerWidgets = (text: string, widgets: ConversationComposerWidget[]): string =>
  [text.trim(), ...widgets.map(buildComposerWidgetFenceBlock)].filter(Boolean).join("\n\n");

/** The canonical names of every registered widget that declares a `composerComponent`. */
const composableWidgetNames = (): ReadonlySet<string> =>
  new Set(
    conversationWidgetRegistry.widgets
      .filter((entry) => entry.composerComponent !== undefined)
      .map((entry) => entry.name),
  );

export interface SplitComposerWidgetFencesResult {
  /** The message text with every extracted fence removed, trimmed. */
  text: string;
  /** The extracted composer widgets, in the order they appeared. */
  widgets: ConversationComposerWidget[];
}

/**
 * The inverse of {@link buildMessageTextWithComposerWidgets}. Extracts every ```widget fence in `text` whose `type`
 * resolves (canonical or legacy `Directive` form, see `widget-name-resolver.ts`) to a registered widget that
 * declares a `composerComponent` — the only fences a user bubble renders as a read-only widget instead of leaving as
 * text. Every other fence — prose, and fences for widgets WITHOUT a `composerComponent` — is left in `text`
 * verbatim. Never throws: an unterminated fence, malformed JSON, or an unresolved/non-composable `type` just leaves
 * that fence untouched.
 */
export const splitComposerWidgetFencesFromMessageText = (text: string): SplitComposerWidgetFencesResult => {
  const composableNames = composableWidgetNames();
  const widgets: ConversationComposerWidget[] = [];
  const fenceOpen = "```" + WIDGET_FENCE_LANG;

  let remaining = "";
  let cursor = 0;
  while (cursor < text.length) {
    const openIndex = text.indexOf(fenceOpen, cursor);
    if (openIndex === -1) {
      remaining += text.slice(cursor);
      break;
    }

    // The fence's info-string line must end right after the lang word (a newline follows) — otherwise this is some
    // other fence whose lang merely starts with "widget" (e.g. "widgetx"); leave it untouched and keep scanning.
    const afterOpen = openIndex + fenceOpen.length;
    const jsonStart = afterOpen + 1;
    const objectEnd = text[afterOpen] === "\n" ? findBalancedJsonObjectEnd(text, jsonStart) : null;
    if (objectEnd === null) {
      remaining += text.slice(cursor, afterOpen);
      cursor = afterOpen;
      continue;
    }

    // The fence must close with ``` right after the JSON object (optional whitespace/newline in between).
    const afterJson = objectEnd + 1;
    const closeMatch = /^\s*```/.exec(text.slice(afterJson));
    if (!closeMatch) {
      remaining += text.slice(cursor, afterOpen);
      cursor = afterOpen;
      continue;
    }
    const fenceEnd = afterJson + closeMatch[0].length;

    let parsed: unknown;
    try {
      parsed = JSON.parse(text.slice(jsonStart, objectEnd + 1));
    } catch {
      parsed = undefined;
    }
    const { type, ...fields } = (typeof parsed === "object" && parsed !== null ? parsed : {}) as Record<
      string,
      unknown
    >;
    const widgetName = typeof type === "string" ? resolveWidgetName(type) : undefined;

    if (!widgetName || !composableNames.has(widgetName)) {
      // Unknown / non-composable widget fence — leave it in the text verbatim (it still renders normally if this
      // text ever runs through the assistant-side remark pipeline; here it just stays as text).
      remaining += text.slice(cursor, fenceEnd);
      cursor = fenceEnd;
      continue;
    }

    remaining += text.slice(cursor, openIndex);
    widgets.push({ composerWidgetId: `${widgetName}:${widgets.length}`, widgetName, fields });
    cursor = fenceEnd;
  }

  return { text: remaining.trim(), widgets };
};
