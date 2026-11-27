import type { ConversationWidgetEntry } from "../../renderer/conversationWidgetRegistry";
import { MailDraftPreviewConversationWidget } from "./MailDraftPreviewConversationWidget";
import { MAIL_DRAFT_PREVIEW_WIDGET_TYPE } from "./mail-draft-preview-payload";

/** The preview of a mail send step — ungated: the mail tool writes it only for the connection's owner. */
export const conversationWidgetRegistration: ConversationWidgetEntry = {
  name: MAIL_DRAFT_PREVIEW_WIDGET_TYPE,
  component: MailDraftPreviewConversationWidget,
};
