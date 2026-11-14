import type { ConversationWidgetEntry } from "../../renderer/conversationWidgetRegistry";
import { EntityMentionConversationWidget } from "./EntityMentionConversationWidget";
import { EntityMentionComposerWidget } from "./EntityMentionComposerWidget";

/**
 * `EntityMention`: composed by the OWNER — the detail panel's "Ask about this" puts it into the chat
 * input (`composerComponent`), it travels in the owner's message as a fence and echoes read-only above its prose
 * (`component`). Ungated: it names an entity the owner could already open. Backspace removes the whole chip (one
 * entity per widget, so no `removeLastPartFromComposerWidgetFields`).
 */
export const conversationWidgetRegistration: ConversationWidgetEntry = {
  name: "EntityMention",
  component: EntityMentionConversationWidget,
  composerComponent: EntityMentionComposerWidget,
};
