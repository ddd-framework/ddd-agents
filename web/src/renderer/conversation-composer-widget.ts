/**
 * A widget instance living in the chat input BEFORE the message is sent — the composer-side twin of a rendered
 * widget: same wire name, same fields, structured (never raw text) until send assembles the fence
 * (`buildMessageTextWithComposerWidgets`, `composer-widget-fence.ts`).
 */
export interface ConversationComposerWidget {
  /** Client-only identity of this composer instance (`crypto.randomUUID()`); never sent. */
  composerWidgetId: string;
  /** The canonical wire name — becomes the fence `type`. */
  widgetName: string;
  /** The fence's non-`type` fields, structured. After the round-trip this is what the widget receives as `payload`. */
  fields: Record<string, unknown>;
}
