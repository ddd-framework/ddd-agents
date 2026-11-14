/**
 * Common props injected into every widget component by ConversationMarkdown.
 *
 * Markdown attributes (the `key='value'` pairs inside `::Name{...}`) are spread as props by react-markdown.
 * ConversationMarkdown additionally injects two flags derived from the host message, answering two
 * separate questions:
 *   - `interactive`   → the widget's controls may be used (buttons, forms, hydration refresh). True for a message
 *                       delivered live in this session AND for the newest assistant reply after a reload, so a
 *                       customer who reloads mid-setup still sees the question's buttons.
 *   - `justDelivered` → the message was delivered live in this session, right now. NEVER true after a reload or on
 *                       history replay. The ONLY gate for one-shot side effects: opening a canvas, creating a draft,
 *                       generating a campaign, charging budget.
 *
 * `fresh` is kept as a deprecated alias of `interactive` so existing widgets keep working; a widget that opens a
 * canvas or mutates anything on mount must read `justDelivered`.
 */
export interface BaseConversationWidgetProps {
  /** @deprecated alias of `interactive` — read `interactive` (controls usable) or `justDelivered` (one-shot side effects). */
  fresh?: boolean;
  /** The widget's controls may be used; see the interface docblock. */
  interactive?: boolean;
  /** The host message was delivered live right now; the only gate for one-shot side effects. */
  justDelivered?: boolean;
  /** Id of the host message (undefined while it streams / for a composer echo). Identifies a canvas opened from it. */
  messageId?: string | number;
  /**
   * The widget was mounted by a PAGE hand-over (`openAgentWithWidget` / `?agentWidget=`), not by a message. A canvas
   * it opens has origin `openedByPage` and a page anchor (the agent space), and never runs creation side effects.
   */
  openedByPage?: boolean;
}

/*
 * The key of a block's agent space is NOT a member of this interface: it arrives as a prop under the block field the
 * app declares (`AgentsEnvironment.widgetSelfHydrationKeyFieldName`, default `agentSpaceId`). A widget of the package
 * reads it with `readWidgetAgentSpaceKeyAttribute(props)` and resolves it against the active agent space with
 * `useResolvedConversationWidgetAgentSpaceKey`; an app that wants the field typed on its own widgets adds it to
 * `BaseConversationWidgetProps` by module augmentation. The value is a string from a markdown attribute and a number
 * from a block field, so the reader takes both.
 */

/**
 * Standard widget shape: a JSON-string payload + the framework-injected `fresh`.
 * Use this for any widget whose markdown carries a single `payload='...'` attribute.
 */
export interface PayloadConversationWidgetProps extends BaseConversationWidgetProps {
  payload: string;
}

/**
 * Props injected into a widget's `composerComponent` — the same widget rendered INSIDE the chat input, before send,
 * editable instead of read-only. See `ConversationComposerWidget` and `ConversationWidgetEntry.composerComponent`
 * (`conversationWidgetRegistry.ts`).
 */
export interface ComposerConversationWidgetProps {
  /** Client-only identity of this composer instance; stable across `replaceFields` calls. */
  composerWidgetId: string;
  /** The widget's own fields (never includes `type`). */
  fields: Record<string, unknown>;
  /** Replace the fields (e.g. drop one element out of several). */
  replaceFields: (nextFields: Record<string, unknown>) => void;
  /** Remove the whole widget from the composer. */
  remove: () => void;
  testId?: string;
}
