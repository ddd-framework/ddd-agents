import type { ConversationWidgetEntry } from "../../renderer/conversationWidgetRegistry";
import { WritingDraftConversationWidget } from "./WritingDraftConversationWidget";

/**
 * The editable text draft — deliberately UNGATED: it carries only the text the model wrote, reads no
 * business data and needs no capability; every action stays in the browser (copy, the owner's own mail program).
 */
export const conversationWidgetRegistration: ConversationWidgetEntry = {
  name: "WritingDraft",
  component: WritingDraftConversationWidget,
};
